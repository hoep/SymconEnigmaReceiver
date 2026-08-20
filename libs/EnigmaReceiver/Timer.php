<?php

declare(strict_types=1);

namespace Hoep\EnigmaReceiver;

/**
 * Programmierte Aufnahmen am Receiver.
 *
 * Reine Auswertung und Auftragsbau, kein Netzverkehr.
 *
 * Zwei Eigenheiten von OpenWebIf bestimmen alles Weitere:
 *
 * 1. **Ein Timer hat keine Kennung.** Seine Identitaet ist das Tripel
 *    Serviceref + Beginn + Ende. Loeschen und Umschalten brauchen genau diese
 *    drei, Aendern zusaetzlich die ALTEN Werte (channelOld/beginOld/endOld).
 *    Ueber den Namen zu gehen ist keine Alternative: zwei Folgen derselben
 *    Serie heissen gleich.
 *
 * 2. **Der Erfolg steht nicht im HTTP-Code.** Ein abgelehnter Timer kommt mit
 *    HTTP 200 und `"result": false` zurueck. Wer nur den Code prueft, haelt eine
 *    Ablehnung fuer eine Programmierung - genau dieser Fehler steckt im
 *    Duplikatschutz des Altsystems.
 */
final class Timer
{
    /** afterevent: was der Receiver NACH der Aufnahme tut. */
    public const NACHHER_NICHTS       = 0;
    public const NACHHER_STANDBY      = 1;
    public const NACHHER_TIEFSCHLAF   = 2;
    public const NACHHER_AUTOMATISCH  = 3;

    /** state eines Timers. */
    public const ZUSTAND = [0 => 'wartet', 1 => 'vorbereitet', 2 => 'laeuft', 3 => 'beendet'];

    /** repeated: Bitmaske Montag (Bit 0) bis Sonntag (Bit 6). */
    public const TAEGLICH   = 0x7F;
    public const WERKTAGS   = 0x1F;
    public const WOCHENENDE = 0x60;

    /**
     * Timerliste aus `timerlist`.
     *
     * @param array<mixed> $daten
     * @return list<array{ref:string,sender:string,name:string,beschreibung:string,start:int,ende:int,
     *                    dauer:int,zustand:int,zustandText:string,aus:bool,eit:int,verzeichnis:string,
     *                    wiederholung:int,datei:string,schluessel:string}>
     */
    public static function ausListe(array $daten): array
    {
        $out = [];
        foreach ((is_array($daten['timers'] ?? null) ? $daten['timers'] : []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $ref   = trim((string) ($t['serviceref'] ?? ''));
            $start = (int) ($t['begin'] ?? 0);
            $ende  = (int) ($t['end'] ?? 0);
            if ($ref === '' || $start <= 0) {
                continue;
            }
            $z = (int) ($t['state'] ?? 0);
            $out[] = [
                'ref'          => $ref,
                'sender'       => trim((string) ($t['servicename'] ?? '')),
                'name'         => trim((string) ($t['name'] ?? '')),
                'beschreibung' => trim((string) ($t['description'] ?? '')),
                'start'        => $start,
                'ende'         => $ende,
                'dauer'        => (int) ($t['duration'] ?? max(0, $ende - $start)),
                'zustand'      => $z,
                'zustandText'  => self::ZUSTAND[$z] ?? ('unbekannt (' . $z . ')'),
                // 'disabled' kommt je nach Image als Zahl oder Wahrheitswert.
                'aus'          => Geraet::wahr($t['disabled'] ?? false),
                'eit'          => (int) ($t['eit'] ?? 0),
                'verzeichnis'  => trim((string) ($t['dirname'] ?? '')),
                'wiederholung' => (int) ($t['repeated'] ?? 0),
                'datei'        => trim((string) ($t['filename'] ?? '')),
                'schluessel'   => self::schluessel($ref, $start, $ende),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $out;
    }

    /** Die Identitaet eines Timers: Serviceref + Beginn + Ende. */
    public static function schluessel(string $ref, int $start, int $ende): string
    {
        return Sender::schluessel($ref) . '|' . $start . '|' . $ende;
    }

    /**
     * Auftrag fuer `timeradd` bauen - mit Vor- und Nachlauf.
     *
     * Der Vor-/Nachlauf wird HIER gerechnet, nicht vom Receiver. Und zwar so:
     *
     *     Beginn = Sendungsbeginn - Vorlauf
     *     Ende   = SENDUNGSENDE   + Nachlauf
     *
     * Das Ende wird auf dem urspruenglichen Sendungsende aufgebaut, nicht auf
     * dem bereits verschobenen Beginn. Im Altsystem (19103, 30398) steht:
     *
     *     StartTime = Sendungsbeginn - Vorlauf
     *     EndTime   = StartTime + Dauer + Nachlauf
     *
     * Damit liegt das Ende nur um (Nachlauf - Vorlauf) nach der Sendung. Solange
     * beide zwei Minuten betragen, faellt es nicht auf; sobald sie sich
     * unterscheiden, wird jede Aufnahme zu kurz.
     *
     * @param array{ref:string,start:int,ende:int,titel:string,kurz?:string,eventId?:int} $sendung
     * @return array<string,string|int>
     */
    public static function bauAuftrag(array $sendung, int $vorlaufMin, int $nachlaufMin,
                                      string $verzeichnis = '', int $nachher = self::NACHHER_AUTOMATISCH): array
    {
        $start = (int) $sendung['start'] - max(0, $vorlaufMin) * 60;
        $ende  = (int) $sendung['ende'] + max(0, $nachlaufMin) * 60;

        $args = [
            'sRef'        => (string) $sendung['ref'],
            'begin'       => $start,
            'end'         => $ende,
            'name'        => (string) $sendung['titel'],
            'description' => (string) ($sendung['kurz'] ?? ''),
            'disabled'    => 0,
            'justplay'    => 0,          // 0 = aufnehmen, 1 = nur umschalten
            'afterevent'  => $nachher,
            'repeated'    => 0,          // Einzeltermin
        ];
        if ($verzeichnis !== '') {
            $args['dirname'] = $verzeichnis;
        }
        // Die Event-Kennung hilft dem Receiver, die Sendung wiederzufinden, wenn
        // sie sich verschiebt. Sie ist aber nicht ueberall stabil - deshalb nur
        // mitgeben, wenn sie wirklich da ist, statt eine 0 zu schicken.
        if ((int) ($sendung['eventId'] ?? 0) > 0) {
            $args['eit'] = (int) $sendung['eventId'];
        }
        return $args;
    }

    /**
     * Antwort eines Schreibaufrufs auswerten.
     *
     * @param array<mixed> $daten
     * @return array{ok:bool,meldung:string,konflikte:list<string>}
     */
    public static function ergebnis(array $daten): array
    {
        $ok  = Geraet::wahr($daten['result'] ?? false);
        $txt = trim((string) ($daten['message'] ?? ''));

        $konf = [];
        foreach ((is_array($daten['conflicts'] ?? null) ? $daten['conflicts'] : []) as $c) {
            $konf[] = is_array($c) ? trim((string) ($c['name'] ?? json_encode($c))) : (string) $c;
        }
        // Manche Fassungen melden die Ueberschneidung nur im Text.
        if ($konf === [] && $txt !== '' && stripos($txt, 'conflict') !== false) {
            $konf[] = $txt;
        }
        return ['ok' => $ok && $konf === [], 'meldung' => $txt !== '' ? $txt : ($ok ? 'angelegt' : 'abgelehnt'),
                'konflikte' => $konf];
    }

    /**
     * Steht diese Sendung schon als Timer am Receiver?
     *
     * Verglichen wird ueber Sender und Zeitfenster, nicht ueber den Namen: der
     * Receiver kuerzt Namen, und der Vor-/Nachlauf verschiebt die Zeiten.
     *
     * @param list<array<string,mixed>> $timer
     * @return array<string,mixed>|null
     */
    public static function schonProgrammiert(array $timer, string $ref, int $start, int $ende,
                                             int $toleranzSek = 900): ?array
    {
        $k = Sender::schluessel($ref);
        foreach ($timer as $t) {
            if (Sender::schluessel((string) $t['ref']) !== $k) {
                continue;
            }
            // Ueberlappung im Zeitfenster genuegt: ein Timer, der die Sendung
            // abdeckt, macht einen zweiten ueberfluessig.
            if ((int) $t['start'] - $toleranzSek <= $start && (int) $t['ende'] + $toleranzSek >= $ende) {
                return $t;
            }
        }
        return null;
    }
}
