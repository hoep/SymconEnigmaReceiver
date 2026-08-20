<?php

declare(strict_types=1);

namespace Hoep\EnigmaReceiver;

/**
 * Programmdaten (EPG) eines einzelnen Senders.
 *
 * Diese Klasse traegt die wichtigste Regel des ganzen Moduls, und sie ist teuer
 * gelernt: **`endTime` ist eine Dauer in MINUTEN, kein Zeitstempel.**
 *
 * Der Wert wandert in OpenWebIf ueber `getChannelEpg` unveraendert in
 * `lookupEvent(['IBDTSENC', (ref, 0, begin, endtime)])`, und der vierte Platz
 * dieses Tupels ist `int minutes` (`startTimeQuery(service, begin, minutes)`).
 * Ein Unix-Zeitstempel bedeutet damit eine Abfrage ueber rund 3400 Jahre. Eine
 * Vu+ Ultimo 4K war daraufhin zehn Minuten lang nicht ansprechbar - nicht nur
 * ihre Weboberflaeche, sondern auch die Fernbedienung.
 *
 * Neuere OpenWebIf-Fassungen bremsen das ab (`if endtime > 100000: endtime = -1`).
 * Die Fassung 1.2.x tut es NICHT. Deshalb deckelt dieses Modul selbst, und zwar
 * an zwei Stellen: hier und noch einmal in OpenWebIf, wo es sich nicht umgehen
 * laesst.
 *
 * Aus demselben Grund gibt es hier nichts fuer Bouquets: `epgbouquet` kennt in
 * 1.2.x ueberhaupt kein `endTime` und kann nur alles liefern.
 */
final class Programm
{
    /** Groesstes zulaessiges Fenster in Minuten (ein Tag). */
    public const MAX_MINUTEN = 1440;

    /** Uebliches Fenster, wenn der Aufrufer nichts sagt. */
    public const STD_MINUTEN = 240;

    /**
     * Fensterwunsch auf ein vertretbares Mass bringen.
     *
     * Absichtlich KEIN stilles Zurechtstutzen grosser Werte: wer einen
     * Zeitstempel schickt, hat einen Fehler im Programm, und der soll auffallen,
     * statt als 1440 durchzurutschen.
     *
     * @return array{ok:bool,minuten:int,fehler:string}
     */
    public static function fenster(int $wunsch): array
    {
        if ($wunsch <= 0) {
            return ['ok' => true, 'minuten' => self::STD_MINUTEN, 'fehler' => ''];
        }
        if ($wunsch > self::MAX_MINUTEN) {
            return ['ok' => false, 'minuten' => 0, 'fehler' => sprintf(
                'Fenster %d ist keine Minutenangabe (hoechstens %d). endTime ist eine DAUER in Minuten, '
                . 'kein Zeitstempel - ein Zeitstempel legt die Box lahm.', $wunsch, self::MAX_MINUTEN)];
        }
        return ['ok' => true, 'minuten' => $wunsch, 'fehler' => ''];
    }

    /**
     * Sendungen aus einer epgservice-Antwort.
     *
     * Die Feldnamen wechseln zwischen den Images; deshalb je Wert mehrere
     * Kandidaten statt einer festen Zuordnung. Was fehlt, bleibt leer.
     *
     * @param array<mixed> $daten
     * @return list<array{eventId:int,start:int,ende:int,dauer:int,titel:string,
     *                    kurz:string,lang:string,sender:string,ref:string}>
     */
    public static function ausEpg(array $daten): array
    {
        $out = [];
        foreach ((is_array($daten['events'] ?? null) ? $daten['events'] : []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $start = (int) self::ersteZahl($e, ['begin_timestamp', 'begin_timestamp_sec', 'begintimestamp']);
            $dauer = (int) self::ersteZahl($e, ['duration_sec', 'duration', 'durationsec']);
            $titel = trim((string) self::ersterText($e, ['title', 'e2eventtitle']));
            if ($start <= 0 || $titel === '') {
                continue;
            }
            $out[] = [
                'eventId' => (int) self::ersteZahl($e, ['id', 'eventid', 'e2eventid']),
                'start'   => $start,
                'ende'    => $start + $dauer,
                'dauer'   => $dauer,
                'titel'   => $titel,
                // Der Untertitel traegt bei Serien den Episodentitel - genau das
                // Feld, an dem der Serienrecorder eine Folge wiedererkennt.
                'kurz'    => trim((string) self::ersterText($e, ['shortdesc', 'e2eventdescription'])),
                'lang'    => trim((string) self::ersterText($e, ['longdesc', 'e2eventdescriptionextended'])),
                'sender'  => trim((string) self::ersterText($e, ['sname', 'e2eventservicename'])),
                'ref'     => trim((string) self::ersterText($e, ['sref', 'e2eventservicereference'])),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * Die Sendung, die zu einer erwarteten Startzeit passt.
     *
     * Gesucht wird die mit dem KLEINSTEN Abstand zur erwarteten Zeit, nicht die
     * erste im Fenster - bei Programmverschiebungen liegen sonst zwei Sendungen
     * in der Toleranz, und die falsche gewinnt.
     *
     * @param list<array<string,mixed>> $sendungen
     * @return array<string,mixed>|null
     */
    public static function passend(array $sendungen, int $start, int $toleranzSek = 900): ?array
    {
        $beste = null;
        $abstand = PHP_INT_MAX;
        foreach ($sendungen as $s) {
            $d = abs(((int) $s['start']) - $start);
            if ($d <= $toleranzSek && $d < $abstand) {
                $abstand = $d;
                $beste = $s;
            }
        }
        return $beste;
    }

    /** @param array<mixed> $e @param list<string> $namen */
    private static function ersteZahl(array $e, array $namen): int
    {
        foreach ($namen as $n) {
            if (isset($e[$n]) && is_numeric($e[$n])) {
                return (int) $e[$n];
            }
        }
        return 0;
    }

    /** @param array<mixed> $e @param list<string> $namen */
    private static function ersterText(array $e, array $namen): string
    {
        foreach ($namen as $n) {
            if (isset($e[$n]) && is_scalar($e[$n]) && (string) $e[$n] !== '') {
                return (string) $e[$n];
            }
        }
        return '';
    }
}
