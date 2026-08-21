<?php

declare(strict_types=1);

namespace Hoep\EnigmaReceiver;

/**
 * Senderlisten und Service-Referenzen.
 *
 * Reine Auswertung, kein Netzverkehr. `getallservices` liefert alle Bouquets mit
 * ihren Sendern in EINEM Aufruf (gemessen: 8 Bouquets, 56 KB, 69 ms) - deshalb
 * keine Schleife ueber die einzelnen Bouquets.
 *
 * Zur Service-Referenz: sie ist die vollstaendige Adresse eines Senders und
 * sieht so aus:
 *
 *     1:0:19:132F:3EF:1:C00000:0:0:0:
 *     | |  |   |    |   |  |
 *     | |  |   |    |   |  Namensraum
 *     | |  |   |    |   Transportstrom-Kennung
 *     | |  |   |    Original-Netzkennung
 *     | |  |   Service-Kennung
 *     | |  Diensttyp (1 = SD, 19 = HD, 2 = Radio)
 *     | Typ (0 = Sender, 7 = Bouquet)
 *     Version
 *
 * Zwei Referenzen koennen auf denselben Sender zeigen und sich trotzdem
 * unterscheiden - etwa wenn ein Bouquet einen Namen anhaengt ("...:Sendername")
 * oder eine Streaming-URL folgt. Fuer Vergleiche zaehlen deshalb nur die ersten
 * zehn Felder.
 */
final class Sender
{
    /**
     * Bouquets mit Sendern aus `getallservices`.
     *
     * @param array<mixed> $daten
     * @return array{bouquets:list<array{ref:string,name:string,anzahl:int}>,
     *               sender:list<array{ref:string,name:string,bouquet:string,pos:int}>}
     */
    public static function ausAlleDienste(array $daten): array
    {
        $bouquets = [];
        $sender   = [];
        $gesehen  = [];

        foreach ((is_array($daten['services'] ?? null) ? $daten['services'] : []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $bname = trim((string) ($b['servicename'] ?? ''));
            $subs  = is_array($b['subservices'] ?? null) ? $b['subservices'] : [];
            $bouquets[] = ['ref' => (string) ($b['servicereference'] ?? ''), 'name' => $bname, 'anzahl' => count($subs)];

            foreach ($subs as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $ref  = trim((string) ($s['servicereference'] ?? ''));
                $name = trim((string) ($s['servicename'] ?? ''));
                if ($ref === '' || $name === '') {
                    continue;
                }
                // Marker und Trennzeilen sind keine Sender: Typ 64 (Marker) hat keine
                // Programmnummer und wuerde sonst als Sender in der Liste stehen.
                if (self::istMarker($ref)) {
                    continue;
                }
                $k = self::schluessel($ref);
                if (isset($gesehen[$k])) {
                    // Derselbe Sender steht in mehreren Bouquets. Einmal reicht;
                    // gemerkt wird das ERSTE Bouquet, in dem er vorkommt.
                    continue;
                }
                $gesehen[$k] = true;
                // Der Rang des Bouquets zaehlt bei der Namenssuche: das erste ist
                // auf jeder Enigma2-Box die selbst zusammengestellte Favoritenliste.
                $sender[] = ['ref' => $ref, 'name' => $name, 'bouquet' => $bname,
                             'bidx' => count($bouquets) - 1, 'pos' => (int) ($s['pos'] ?? 0)];
            }
        }
        return ['bouquets' => $bouquets, 'sender' => $sender];
    }

    /** Bouquetliste aus `bouquets` (Paare [ref, name]). @return list<array{ref:string,name:string}> */
    public static function ausBouquets(array $daten): array
    {
        $out = [];
        foreach ((is_array($daten['bouquets'] ?? null) ? $daten['bouquets'] : []) as $b) {
            if (is_array($b) && isset($b[0])) {
                $out[] = ['ref' => (string) $b[0], 'name' => trim((string) ($b[1] ?? ''))];
            }
        }
        return $out;
    }

    /** Vergleichsform einer Referenz: die ersten zehn Felder, ohne Anhaengsel. */
    public static function schluessel(string $ref): string
    {
        $f = explode(':', trim($ref));
        return strtoupper(implode(':', array_slice($f, 0, 10)));
    }

    /** Ist die Referenz eine HD-Fassung? Feld 2 ist der Diensttyp, 19 = HD. */
    public static function istHd(string $ref): bool
    {
        $f = explode(':', trim($ref));
        return strtoupper(trim((string) ($f[2] ?? ''))) === '19';
    }

    public static function istMarker(string $ref): bool
    {
        $f = explode(':', trim($ref));
        // Feld 2 ist der Diensttyp; 64 (hex 0x40) kennzeichnet einen Marker.
        return (int) ($f[1] ?? 0) === 64 || strtolower((string) ($f[2] ?? '')) === '64';
    }

    /**
     * Sender ueber den Namen finden - so, wie ihn ein Mensch schreibt.
     *
     * Verglichen wird auf einer entschaerften Form: ohne Gross/Klein, ohne
     * Zusaetze wie "HD", "UHD", "Austria", ohne Sonderzeichen. Sonst findet
     * "ORF 1" den Sender "ORF1 HD" nicht, obwohl beide dasselbe meinen.
     *
     * @param list<array{ref:string,name:string,bouquet:string,pos:int}> $liste
     * @return array{ref:string,name:string,bouquet:string,pos:int}|null
     */
    public static function finde(array $liste, string $name): ?array
    {
        $g = self::form($name);
        if ($g === '') {
            return null;
        }
        // Bewertung statt zweier Durchlaeufe. Der Anlass ist eine echte
        // Senderliste: "ORF 2" trifft woertlich den Eintrag "ORF2" - einen
        // toten SD-Platzhalter ohne Programmdaten -, waehrend die brauchbare
        // Fassung "ORF2O HD" im Favoritenbouquet steht. Wer nur auf den Namen
        // schaut, waehlt die leere Kachel.
        //
        //   Namenstreffer   genau 100, Anfang 50
        //   Favoritenliste  + 60  (das erste Bouquet ist die eigene Auswahl)
        //   HD              + 10
        //   Gleichstand     der kuerzere Name gewinnt - er liegt naeher an der Frage
        //
        // Die Gewichte sind so gewaehlt, dass ein Favorit einen woertlichen
        // Treffer ausserhalb der Favoriten schlagen darf, ein blosser
        // Namensanfang ohne Favoritenstatus aber nie.
        $beste = null;
        $bestwert = -1;
        foreach ($liste as $s) {
            $f = self::form((string) $s['name']);
            if ($f === '') {
                continue;
            }
            if ($f === $g) {
                $wert = 100;
            } elseif (str_starts_with($f, $g)) {
                $wert = 50;
            } else {
                continue;
            }
            if ((int) ($s['bidx'] ?? 99) === 0) {
                $wert += 60;
            }
            if (self::istHd((string) $s['ref'])) {
                $wert += 10;
            }
            $wert = $wert * 1000 - min(999, mb_strlen((string) $s['name']));
            if ($wert > $bestwert) {
                $bestwert = $wert;
                $beste = $s;
            }
        }
        return $beste;
    }

    /**
     * Dateiname des Picons zu einer Service-Referenz.
     *
     * Enigma2 legt die Senderlogos als `<Referenz mit _ statt :>.png` unter
     * `/picon/` ab - aus `1:0:19:132F:3EF:1:C00000:0:0:0:` wird
     * `1_0_19_132F_3EF_1_C00000_0_0_0.png`. Nur die ersten zehn Felder zaehlen,
     * ein angehaengter Sendername im Bouquet gehoert nicht dazu.
     *
     * Diese Fassung von OpenWebIf hat KEINEN Endpunkt dafuer (`/api/getpicon`
     * antwortet mit 404) - der Pfad wird also gerechnet, nicht erfragt. Das ist
     * kein Nachteil: die Datei holt spaeter der Browser, nicht das Modul.
     *
     * @return string Dateiname ohne Endung, leer wenn die Referenz nicht taugt
     */
    public static function piconName(string $ref): string
    {
        $f = explode(':', trim($ref));
        if (count($f) < 10) {
            return '';
        }
        $n = strtoupper(implode('_', array_slice($f, 0, 10)));
        // Streaming-Referenzen haengen hinter das zehnte Feld ihre Adresse; die
        // ist oben schon abgeschnitten. Was danach noch Sonderzeichen enthaelt,
        // ergibt keinen Dateinamen.
        return preg_match('/^[0-9A-Z_]+$/', $n) === 1 ? $n : '';
    }

    /** Vergleichsform eines Sendernamens. */
    public static function form(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        // Aufloesungs- und Regionszusaetze tragen fuer die Zuordnung nichts bei.
        $n = preg_replace('/\b(uhd|hd\+|hd|sd|austria|deutschland|germany|at|de)\b/u', ' ', $n) ?? $n;
        $n = preg_replace('/[^a-z0-9]+/u', '', $n) ?? $n;
        // Angeklebtes HD: die Box schreibt "ORF 1HD", der Serienrecorder fragt
        // nach "ORF1 HD". Die Wortgrenze oben greift da nicht - nach einer Ziffer
        // ist "hd" kein eigenes Wort. Deshalb hier noch einmal am ENDE.
        $n = preg_replace('/(uhd|hd|sd)$/u', '', (string) $n) ?? $n;
        return (string) $n;
    }
}
