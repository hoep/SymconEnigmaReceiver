<?php

declare(strict_types=1);

namespace Hoep\EnigmaReceiver;

/**
 * Zugriff auf die OpenWebIf-Schnittstelle eines Enigma2-Receivers.
 *
 * Diese Klasse ist die EINZIGE Stelle, die mit einer Box spricht, und sie kennt
 * eine Positivliste erlaubter Endpunkte. Das ist keine Formsache: OpenWebIf hat
 * schreibende Aufrufe, die auf den ersten Blick wie Abfragen aussehen -
 * `/api/recordnow` startet sofort eine Aufnahme. Eine Verbotsliste deckt eine
 * fremde API nie vollstaendig ab; was hier nicht steht, wird nicht gerufen.
 *
 * Kein curl, sondern Streams: php-cli hat hier kein curl, und die Klasse soll
 * ohne Symcon-Kernel testbar bleiben.
 *
 * Wichtig zum Verhalten der Gegenstelle: OpenWebIf laeuft im Hauptprozess von
 * Enigma2. Eine schwere Anfrage blockiert die ganze Box einschliesslich
 * Fernbedienung. Deshalb faellt hier nach einem Timeout NICHT sofort der
 * naechste Versuch an - das entscheidet der Aufrufer ueber die Ruhezeit.
 */
final class OpenWebIf
{
    /** Lesende Endpunkte. Alles andere ist nicht aufrufbar. */
    private const ERLAUBT = [
        'about', 'deviceinfo', 'statusinfo', 'currenttime', 'signal',
        'powerstate', 'getcurrent', 'bouquets', 'getservices', 'getallservices',
        'timerlist', 'epgservice', 'epgservicenow', 'epgservicenext',
        'servicelistplayable', 'tunersignal', 'settings', 'movielist',
    ];

    /**
     * SCHREIBENDE Endpunkte. Sie sind ueber `hole()` NICHT erreichbar, sondern
     * ausschliesslich ueber `schreibe()`, und das verlangt ein ausdrueckliches
     * offenes Gate. Zwei getrennte Listen statt einer mit Merkmal: so kann ein
     * Tippfehler im Aufrufer nicht aus einer Abfrage einen Schreibvorgang machen.
     *
     * Bewusst NICHT enthalten: recordnow (startet sofort eine Aufnahme), zap
     * (schaltet um), message (blendet Text auf dem Fernseher ein), powerstate
     * (schaltet ab), remotecontrol. Das Modul hat fuer nichts davon einen Anlass.
     */
    private const SCHREIBEND = [
        'timeradd', 'timeraddbyeventid', 'timerchange', 'timerdelete',
        'timertogglestatus', 'timercleanup',
    ];

    /**
     * Endpunkte, die verlaesslich klein und schnell sind. `movielist` und
     * `epgservice` gehoeren NICHT dazu (gemessen: 317 ms bzw. 223 KB / 432 ms).
     */
    private const LEICHT = [
        'statusinfo', 'currenttime', 'powerstate', 'signal', 'getcurrent',
        'bouquets', 'tunersignal',
    ];

    public function __construct(
        private string $host,
        private int $port = 80,
        private string $benutzer = '',
        private string $passwort = '',
        private int $timeout = 5,
    ) {
    }

    public function erreichbarkeitsPfad(): string
    {
        return 'statusinfo';
    }

    /** Ist der Endpunkt ueberhaupt erlaubt? Oeffentlich, damit Tests es pruefen koennen. */
    public static function istErlaubt(string $endpunkt): bool
    {
        return in_array($endpunkt, self::ERLAUBT, true);
    }

    public static function istLeicht(string $endpunkt): bool
    {
        return in_array($endpunkt, self::LEICHT, true);
    }

    public static function istSchreibend(string $endpunkt): bool
    {
        return in_array($endpunkt, self::SCHREIBEND, true);
    }

    /**
     * Einen schreibenden Endpunkt aufrufen.
     *
     * @param bool $gateOffen Das Scharf-Gate der Instanz. Ist es zu, findet kein
     *                        Netzverkehr statt - die Box erfaehrt nichts davon.
     * @param array<string,string|int> $args
     * @return array{ok:bool,daten:array<mixed>,fehler:string,code:int,ms:int}
     */
    public function schreibe(string $endpunkt, array $args, bool $gateOffen): array
    {
        if (!self::istSchreibend($endpunkt)) {
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0,
                    'fehler' => 'Endpunkt "' . $endpunkt . '" ist kein zugelassener Schreibaufruf'];
        }
        if (!$gateOffen) {
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0,
                    'fehler' => 'Gate ist zu - es wird nichts an den Receiver geschickt'];
        }
        return $this->ruf($endpunkt, $args);
    }

    /**
     * Einen Endpunkt abfragen.
     *
     * @param array<string,string|int> $args
     * @return array{ok:bool,daten:array<mixed>,fehler:string,code:int,ms:int}
     */
    public function hole(string $endpunkt, array $args = []): array
    {
        if (!self::istErlaubt($endpunkt)) {
            // Kein Netzverkehr. Ein nicht gelisteter Endpunkt ist ein Programmfehler,
            // kein Betriebsfall - er darf die Box nicht einmal erreichen. Schreibende
            // Aufrufe kommen hier ebenfalls nicht durch; dafuer gibt es schreibe().
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0,
                    'fehler' => self::istSchreibend($endpunkt)
                        ? 'Endpunkt "' . $endpunkt . '" ist schreibend und nur ueber schreibe() erreichbar'
                        : 'Endpunkt "' . $endpunkt . '" steht nicht auf der Positivliste'];
        }
        // Zweite Bremse gegen den Fehler, der eine Box zweimal lahmgelegt hat:
        // endTime ist eine DAUER IN MINUTEN. Ein Zeitstempel bedeutet fuer die
        // Box eine Abfrage ueber Jahrtausende, und OpenWebIf 1.2.x faengt das
        // nicht ab. Hier kommt so ein Wert nicht vorbei.
        if (isset($args['endTime']) && (int) $args['endTime'] > 1440) {
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0,
                    'fehler' => 'endTime=' . $args['endTime'] . ' ist keine Minutenangabe (hoechstens 1440)'];
        }
        if ($this->host === '') {
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0, 'fehler' => 'keine Adresse'];
        }

        return $this->ruf($endpunkt, $args);
    }

    /**
     * Der eigentliche Aufruf. Prueft NICHTS mehr - die Freigabe ist vorher
     * gefallen, in hole() oder in schreibe().
     *
     * @param array<string,string|int> $args
     * @return array{ok:bool,daten:array<mixed>,fehler:string,code:int,ms:int}
     */
    private function ruf(string $endpunkt, array $args): array
    {
        $t0 = microtime(true);
        $ms = fn(): int => (int) round((microtime(true) - $t0) * 1000);

        if ($this->host === '') {
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0, 'fehler' => 'keine Adresse'];
        }

        $url = 'http://' . $this->host . ':' . $this->port . '/api/' . $endpunkt;
        if ($args !== []) {
            $url .= '?' . http_build_query($args);
        }

        $kopf = ["Accept: application/json", "Connection: close"];
        if ($this->benutzer !== '') {
            $kopf[] = 'Authorization: Basic ' . base64_encode($this->benutzer . ':' . $this->passwort);
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $kopf),
            'timeout'       => $this->timeout,
            'ignore_errors' => true,     // 401/500 als Antwort lesen statt als false
            'follow_location' => 0,
        ]]);

        $roh = @file_get_contents($url, false, $ctx);
        $code = $this->statusCode($http_response_header ?? []);

        if ($roh === false) {
            return ['ok' => false, 'daten' => [], 'code' => $code, 'ms' => $ms(),
                    'fehler' => $code === 0 ? 'keine Antwort' : 'Abbruch (HTTP ' . $code . ')'];
        }
        if ($code === 401) {
            return ['ok' => false, 'daten' => [], 'code' => 401, 'ms' => $ms(),
                    'fehler' => 'Anmeldung abgelehnt'];
        }
        if ($code >= 400) {
            return ['ok' => false, 'daten' => [], 'code' => $code, 'ms' => $ms(),
                    'fehler' => 'HTTP ' . $code];
        }

        // OpenWebIf meldet Content-Type text/plain, liefert aber JSON. Nicht am
        // Kopf festmachen, sondern am Inhalt.
        $j = json_decode($roh, true);
        if (!is_array($j)) {
            return ['ok' => false, 'daten' => [], 'code' => $code, 'ms' => $ms(),
                    'fehler' => 'keine verwertbare Antwort (' . strlen($roh) . ' Bytes)'];
        }

        return ['ok' => true, 'daten' => $j, 'code' => $code, 'ms' => $ms(), 'fehler' => ''];
    }

    /** @param list<string> $kopfzeilen */
    private function statusCode(array $kopfzeilen): int
    {
        foreach ($kopfzeilen as $z) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $z, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
