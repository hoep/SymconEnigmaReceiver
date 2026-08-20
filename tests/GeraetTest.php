<?php

declare(strict_types=1);

/**
 * Prueft die Auswertung gegen ECHTE Antworten einer Vu+ Ultimo 4K
 * (VTi 15.0.02, OWIF 1.2.8), abgelegt unter tests/daten/.
 *
 * Aufruf: php tests/GeraetTest.php
 */

require_once __DIR__ . '/../libs/EnigmaReceiver/autoload.php';

use Hoep\EnigmaReceiver\Geraet;
use Hoep\EnigmaReceiver\OpenWebIf;

$fehler = 0;
$pruefe = function (string $was, mixed $ist, mixed $soll) use (&$fehler): void {
    $ok = $ist === $soll;
    if (!$ok) {
        $fehler++;
    }
    printf("  [%s] %-46s ist=%s soll=%s\n", $ok ? 'ok' : 'FEHLER', $was,
        var_export($ist, true), var_export($soll, true));
};

$lade = fn(string $n): array => json_decode((string) file_get_contents(__DIR__ . '/daten/' . $n), true);

echo "Geraetedaten aus about (mit info-Huelle)\n";
$g = Geraet::ausGeraet($lade('about.json'));
$pruefe('modell', $g['modell'], 'Vu+ Ultimo 4K');
$pruefe('image', $g['image'], 'VTi 15.0.02 / OWIF 1.2.8');
$pruefe('laufzeit bleibt Text', $g['laufzeit'], '68d 5:05');
$pruefe('tuner gesamt', $g['tunerGesamt'], 18);
$pruefe('tuner frei', $g['tunerFrei'], 18);
$pruefe('keine Platte gemeldet', count($g['platten']), 0);
$pruefe('ip aus den Testdaten', $g['ip'], '192.0.2.10');

echo "\nDieselben Felder flach aus deviceinfo\n";
$f = Geraet::ausGeraet($lade('deviceinfo.json'));
$pruefe('modell identisch', $f['modell'], $g['modell']);
$pruefe('tuner identisch', $f['tunerGesamt'], $g['tunerGesamt']);

echo "\nZustand aus statusinfo\n";
$s = Geraet::ausStatus($lade('statusinfo.json'));
$pruefe('standby (Text "true")', $s['standby'], true);
$pruefe('aufnahme (Text "false")', $s['aufnahme'], false);
$pruefe('Sender "N/A" gilt als leer', $s['sender'], '');

echo "\nWahrheitswerte\n";
$pruefe('Text "false" ist falsch', Geraet::wahr('false'), false);
$pruefe('Text "true" ist wahr', Geraet::wahr('true'), true);
$pruefe('echtes false bleibt falsch', Geraet::wahr(false), false);
$pruefe('leerer Text ist falsch', Geraet::wahr(''), false);

echo "\nGroessenangaben\n";
$pruefe('931 GB', Geraet::gb('931 GB'), 931.0);
$pruefe('1.8 TB', Geraet::gb('1.8 TB'), 1843.2);
$pruefe('leer', Geraet::gb(''), 0.0);

echo "\nPositivliste\n";
$pruefe('statusinfo erlaubt', OpenWebIf::istErlaubt('statusinfo'), true);
$pruefe('recordnow NICHT erlaubt', OpenWebIf::istErlaubt('recordnow'), false);
$pruefe('timeradd NICHT erlaubt', OpenWebIf::istErlaubt('timeradd'), false);
$pruefe('zap NICHT erlaubt', OpenWebIf::istErlaubt('zap'), false);
$pruefe('epgbouquet NICHT erlaubt', OpenWebIf::istErlaubt('epgbouquet'), false);

echo "\nEin nicht gelisteter Endpunkt darf die Box nicht erreichen\n";
$w = new OpenWebIf('10.255.255.1');   // nicht erreichbar - falls doch gerufen, dauert es
$t0 = microtime(true);
$r = $w->hole('recordnow');
$ms = (int) round((microtime(true) - $t0) * 1000);
$pruefe('abgelehnt', $r['ok'], false);
$pruefe('ohne Netzverkehr (< 50 ms)', $ms < 50, true);

echo "\n" . ($fehler === 0 ? "alles bestanden\n" : "$fehler Abweichung(en)\n");
exit($fehler === 0 ? 0 : 1);
