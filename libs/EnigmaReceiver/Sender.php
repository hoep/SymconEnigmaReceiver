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
                $sender[] = ['ref' => $ref, 'name' => $name, 'bouquet' => $bname, 'pos' => (int) ($s['pos'] ?? 0)];
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
        // 1. genaue Uebereinstimmung der entschaerften Form
        foreach ($liste as $s) {
            if (self::form($s['name']) === $g) {
                return $s;
            }
        }
        // 2. Sender, dessen Name mit dem Gesuchten beginnt ("ORF2" -> "ORF2 Europe")
        foreach ($liste as $s) {
            $f = self::form($s['name']);
            if ($f !== '' && str_starts_with($f, $g)) {
                return $s;
            }
        }
        return null;
    }

    /** Vergleichsform eines Sendernamens. */
    public static function form(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        // Aufloesungs- und Regionszusaetze tragen fuer die Zuordnung nichts bei.
        $n = preg_replace('/\b(uhd|hd\+|hd|sd|austria|deutschland|germany|at|de)\b/u', ' ', $n) ?? $n;
        $n = preg_replace('/[^a-z0-9]+/u', '', $n) ?? $n;
        return (string) $n;
    }
}
