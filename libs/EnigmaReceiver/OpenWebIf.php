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

    /**
     * Einen Endpunkt abfragen.
     *
     * @param array<string,string|int> $args
     * @return array{ok:bool,daten:array<mixed>,fehler:string,code:int,ms:int}
     */
    public function hole(string $endpunkt, array $args = []): array
    {
        $t0 = microtime(true);
        $ms = fn(): int => (int) round((microtime(true) - $t0) * 1000);

        if (!self::istErlaubt($endpunkt)) {
            // Kein Netzverkehr. Ein nicht gelisteter Endpunkt ist ein Programmfehler,
            // kein Betriebsfall - er darf die Box nicht einmal erreichen.
            return ['ok' => false, 'daten' => [], 'code' => 0, 'ms' => 0,
                    'fehler' => 'Endpunkt "' . $endpunkt . '" steht nicht auf der Positivliste'];
        }
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
