<?php

declare(strict_types=1);

namespace Hoep\EnigmaReceiver;

/**
 * Wandelt die Antworten der Box in Werte, die Symcon fuehren kann.
 *
 * Reine Auswertung, kein Netzverkehr und kein Symcon - damit ohne Kernel
 * testbar. Alles hier ist gegen die gemessenen Antworten einer Vu+ Ultimo 4K
 * (VTi 15.0.02, OWIF 1.2.8) geschrieben, nicht gegen die Dokumentation. Wo
 * beides auseinanderging, gilt die Messung; die Abweichungen stehen als
 * Kommentar an der jeweiligen Stelle.
 *
 * Grundsatz fuer die Bildunterschiede: ein fehlendes Feld ist ein leerer Wert,
 * kein Fehler. Die Dreambox liefert nachweislich anderes als die Vu+, und ein
 * Modul, das darueber stolpert, ist auf einer gemischten Anlage nutzlos.
 */
final class Geraet
{
    /**
     * `about` verpackt alles in "info", `deviceinfo` liefert dieselben Felder
     * flach. Beide Formen zulassen, statt sich auf eine festzulegen.
     *
     * @param array<mixed> $daten
     * @return array<string,mixed>
     */
    public static function rumpf(array $daten): array
    {
        $i = $daten['info'] ?? null;
        return is_array($i) ? $i : $daten;
    }

    /**
     * Geraetedaten aus about/deviceinfo.
     *
     * @param array<mixed> $daten
     * @return array{modell:string,image:string,laufzeit:string,tunerFrei:int,tunerGesamt:int,
     *               platten:list<array{name:string,frei:float,gesamt:float,belegtProzent:float,pfad:string}>,
     *               ip:string,mac:string}
     */
    public static function ausGeraet(array $daten): array
    {
        $d = self::rumpf($daten);

        $modell = trim(((string) ($d['brand'] ?? '')) . ' ' . ((string) ($d['model'] ?? '')));

        // Zwei Angaben, die zusammen erst etwas aussagen: die Image-Fassung sagt
        // nichts ueber die OpenWebIf-Fassung, und genau die entscheidet, welche
        // Endpunkte es gibt.
        $image = trim((string) ($d['imagedistro'] ?? '') . ' ' . (string) ($d['imagever'] ?? ''));
        $owif  = trim((string) ($d['webifver'] ?? ''));
        if ($owif !== '') {
            $image = trim($image . ' / ' . $owif);
        }

        // GEMESSEN: 'uptime' ist Text ("68d 5:05"), keine Sekundenzahl. Als Zahl
        // gefuehrt waere der Wert entweder 0 oder falsch.
        $laufzeit = trim((string) ($d['uptime'] ?? ''));

        // FBC-Tuner melden sich vielfach (gemessen: 18 Eintraege auf einer Ultimo 4K).
        // 'rec' und 'live' sind Text und leer, solange der Tuner nichts tut.
        $tuner = is_array($d['tuners'] ?? null) ? $d['tuners'] : [];
        $frei = 0;
        foreach ($tuner as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (trim((string) ($t['rec'] ?? '')) === '' && trim((string) ($t['live'] ?? '')) === '') {
                $frei++;
            }
        }

        // GEMESSEN: 'hdd' ist auf dieser Box eine LEERE Liste - sie nimmt auf eine
        // Netzfreigabe auf. Deshalb liefert diese Methode Platten als Liste, und
        // der Aufrufer legt Variablen nur an, wenn wirklich eine da ist.
        $platten = [];
        foreach ((is_array($d['hdd'] ?? null) ? $d['hdd'] : []) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $frei_gb   = self::gb((string) ($h['free'] ?? ''));
            $gesamt_gb = self::gb((string) ($h['capacity'] ?? ($h['labelled_capacity'] ?? '')));
            $platten[] = [
                'name'          => trim((string) ($h['model'] ?? '')),
                'frei'          => $frei_gb,
                'gesamt'        => $gesamt_gb,
                'belegtProzent' => $gesamt_gb > 0 ? round(($gesamt_gb - $frei_gb) / $gesamt_gb * 100, 1) : 0.0,
                'pfad'          => trim((string) ($h['mount'] ?? '')),
            ];
        }

        $ifc = is_array($d['ifaces'] ?? null) ? $d['ifaces'] : [];
        $erste = is_array($ifc[0] ?? null) ? $ifc[0] : [];

        return [
            'modell'      => $modell,
            'image'       => $image,
            'laufzeit'    => $laufzeit,
            'tunerFrei'   => $frei,
            'tunerGesamt' => count($tuner),
            'platten'     => $platten,
            'ip'          => (string) ($erste['ip'] ?? ''),
            'mac'         => (string) ($erste['mac'] ?? ''),
        ];
    }

    /**
     * Laufender Zustand aus statusinfo.
     *
     * @param array<mixed> $daten
     * @return array{standby:bool,aufnahme:bool,sender:string,sendung:string,lautstaerke:int}
     */
    public static function ausStatus(array $daten): array
    {
        // GEMESSEN: 'inStandby' und 'isRecording' kommen als ZEICHENKETTE "true"/"false",
        // 'muted' dagegen als echter Wahrheitswert. Ein (bool)-Cast auf "false" ergaebe
        // true - genau der Fehler, der eine Box dauerhaft als "im Betrieb" zeigt.
        $sender  = trim((string) ($daten['currservice_name'] ?? ''));
        $sendung = trim((string) ($daten['currservice_description'] ?? ''));

        return [
            'standby'     => self::wahr($daten['inStandby'] ?? null),
            'aufnahme'    => self::wahr($daten['isRecording'] ?? null),
            // Im Standby meldet die Box "N/A" als Sendername. Das ist kein Sender.
            'sender'      => ($sender === 'N/A') ? '' : $sender,
            'sendung'     => ($sendung === 'N/A') ? '' : $sendung,
            'lautstaerke' => (int) ($daten['volume'] ?? 0),
        ];
    }

    /** "true"/"false" als Text ODER echter Wahrheitswert - beides kommt vor. */
    public static function wahr(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return strtolower(trim($v)) === 'true';
        }
        return (bool) $v;
    }

    /** "1.8 TB", "931 GB", "500000 MB" -> Gigabyte. Leerer Text -> 0. */
    public static function gb(string $t): float
    {
        $t = trim($t);
        if ($t === '' || !preg_match('/([\d.,]+)\s*([TGM]?)B?/i', $t, $m)) {
            return 0.0;
        }
        $z = (float) str_replace(',', '.', $m[1]);
        return match (strtoupper($m[2])) {
            'T'     => $z * 1024,
            'M'     => $z / 1024,
            default => $z,
        };
    }
}
