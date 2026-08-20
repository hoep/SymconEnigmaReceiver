<?php

declare(strict_types=1);

/**
 * Stufe 2 und 3: Sender, Programm, Timer.
 *
 * Laeuft ohne Symcon-Kernel. Senderliste und Timerliste sind ECHTE Antworten
 * einer Vu+ Ultimo 4K unter tests/daten/.
 *
 * Aufruf: php tests/ProgrammTest.php
 */

require_once __DIR__ . '/../libs/EnigmaReceiver/autoload.php';

use Hoep\EnigmaReceiver\OpenWebIf;
use Hoep\EnigmaReceiver\Programm;
use Hoep\EnigmaReceiver\Sender;
use Hoep\EnigmaReceiver\Timer;

$fehler = 0;
$pruefe = function (string $was, mixed $ist, mixed $soll) use (&$fehler): void {
    $ok = $ist === $soll;
    if (!$ok) {
        $fehler++;
    }
    printf("  [%s] %-52s ist=%s soll=%s\n", $ok ? 'ok' : 'FEHLER', $was,
        var_export($ist, true), var_export($soll, true));
};
$lade = fn(string $n): array => json_decode((string) file_get_contents(__DIR__ . '/daten/' . $n), true);

echo "Senderliste aus echten Daten\n";
$l = Sender::ausAlleDienste($lade('getallservices.json'));
$pruefe('Bouquets gefunden', count($l['bouquets']) > 0, true);
$pruefe('Sender gefunden', count($l['sender']) > 0, true);
$refs = array_map(fn(array $s): string => Sender::schluessel($s['ref']), $l['sender']);
$pruefe('keine Dopplung ueber Bouquets hinweg', count($refs) === count(array_unique($refs)), true);

echo "\nSender ueber den Namen finden\n";
$orf = Sender::finde($l['sender'], 'ORF 1');
$pruefe('"ORF 1" findet einen Sender', $orf !== null, true);
$pruefe('  gefunden', $orf['name'] ?? '', 'ORF1 HD');
$pruefe('"Das Erste" findet HD-Fassung', Sender::finde($l['sender'], 'Das Erste')['name'] ?? '', 'Das Erste HD');
$pruefe('Unsinn findet nichts', Sender::finde($l['sender'], 'Gibt Es Nicht 4711'), null);
$pruefe('Vergleichsform ignoriert HD', Sender::form('ORF1 HD'), Sender::form('orf1'));

echo "\nReferenz-Vergleichsform\n";
$pruefe('Anhaengsel faellt weg',
    Sender::schluessel('1:0:19:132F:3EF:1:C00000:0:0:0:ORF1 HD'),
    Sender::schluessel('1:0:19:132F:3EF:1:C00000:0:0:0:'));

echo "\nZeitfenster - die Regel, die eine Box zweimal lahmgelegt hat\n";
$pruefe('240 Minuten sind in Ordnung', Programm::fenster(240)['minuten'], 240);
$pruefe('0 wird zur Vorgabe', Programm::fenster(0)['minuten'], 240);
$pruefe('1440 ist die Grenze', Programm::fenster(1440)['ok'], true);
$pruefe('1441 wird ABGELEHNT', Programm::fenster(1441)['ok'], false);
$pruefe('ein Zeitstempel wird ABGELEHNT', Programm::fenster(1787000000)['ok'], false);
$pruefe('  nicht stillschweigend gekappt', Programm::fenster(1787000000)['minuten'], 0);

echo "\nEPG auswerten\n";
$epg = ['events' => [
    ['id' => 4711, 'begin_timestamp' => 1787000000, 'duration_sec' => 3600,
     'title' => 'Tatort', 'shortdesc' => 'Der Fall', 'longdesc' => 'lang', 'sname' => 'ORF2'],
    ['id' => 4712, 'begin_timestamp' => 1787003600, 'duration_sec' => 1800, 'title' => 'ZIB'],
    ['id' => 0, 'begin_timestamp' => 0, 'title' => 'kaputt'],
]];
$s = Programm::ausEpg($epg);
$pruefe('zwei brauchbare Sendungen', count($s), 2);
$pruefe('Ende = Beginn + Dauer', $s[0]['ende'], 1787000000 + 3600);
$pruefe('Episodentitel aus shortdesc', $s[0]['kurz'], 'Der Fall');
$pruefe('nach Beginn sortiert', $s[0]['start'] < $s[1]['start'], true);

echo "\nPassende Sendung zu einer erwarteten Zeit\n";
$pruefe('naechstgelegene gewinnt', Programm::passend($s, 1787000300, 900)['eventId'], 4711);
$pruefe('ausserhalb der Toleranz nichts', Programm::passend($s, 1787900000, 900), null);

echo "\nVor- und Nachlauf - der Rechenfehler des Altsystems\n";
$auftrag = Timer::bauAuftrag(
    ['ref' => '1:0:19:1:2:3:4:0:0:0:', 'start' => 1787000000, 'ende' => 1787003600, 'titel' => 'Tatort'],
    5, 10);
$pruefe('Beginn = Sendung - 5 min', $auftrag['begin'], 1787000000 - 300);
$pruefe('Ende = SENDUNGSENDE + 10 min', $auftrag['end'], 1787003600 + 600);
$pruefe('  Dauer waechst um Vorlauf+Nachlauf', $auftrag['end'] - $auftrag['begin'], 3600 + 300 + 600);
$pruefe('keine Event-Kennung -> kein eit-Feld', isset($auftrag['eit']), false);
$pruefe('Einzeltermin', $auftrag['repeated'], 0);
$pruefe('aufnehmen, nicht umschalten', $auftrag['justplay'], 0);

echo "\nAntwort eines Schreibaufrufs\n";
$pruefe('result true -> Erfolg', Timer::ergebnis(['result' => true, 'message' => 'ok'])['ok'], true);
$pruefe('result false -> KEIN Erfolg', Timer::ergebnis(['result' => false, 'message' => 'nein'])['ok'], false);
$k = Timer::ergebnis(['result' => true, 'message' => 'Conflicting Timer(s) detected! ZIB']);
$pruefe('Konflikt trotz result true -> KEIN Erfolg', $k['ok'], false);
$pruefe('  Konflikt gemeldet', count($k['konflikte']), 1);

echo "\nTimerliste aus echten Daten\n";
$t = Timer::ausListe($lade('timerlist.json'));
$pruefe('Timer gelesen', count($t) > 0, true);
$pruefe('Schluessel ist ref|begin|end',
    $t[0]['schluessel'], Sender::schluessel($t[0]['ref']) . '|' . $t[0]['start'] . '|' . $t[0]['ende']);
$pruefe('Zustand uebersetzt', in_array($t[0]['zustandText'], Timer::ZUSTAND, true), true);

echo "\nSchon programmiert?\n";
$erste = $t[0];
$pruefe('deckungsgleiche Sendung erkannt',
    Timer::schonProgrammiert($t, $erste['ref'], $erste['start'] + 60, $erste['ende'] - 60) !== null, true);
$pruefe('anderer Sender nicht',
    Timer::schonProgrammiert($t, '1:0:19:AAAA:BBBB:1:C00000:0:0:0:', $erste['start'], $erste['ende']), null);

echo "\nSchreibpfad - die Absicherung\n";
$w = new OpenWebIf('10.255.255.1');
$pruefe('timeradd ist schreibend', OpenWebIf::istSchreibend('timeradd'), true);
$pruefe('recordnow ist NICHT einmal schreibend gelistet', OpenWebIf::istSchreibend('recordnow'), false);
$pruefe('zap ist NICHT gelistet', OpenWebIf::istSchreibend('zap'), false);

$t0 = microtime(true);
$r = $w->schreibe('timeradd', ['sRef' => 'x', 'begin' => 1, 'end' => 2, 'name' => 'y'], false);
$ms = (int) round((microtime(true) - $t0) * 1000);
$pruefe('Gate zu -> abgelehnt', $r['ok'], false);
$pruefe('Gate zu -> ohne Netzverkehr (< 50 ms)', $ms < 50, true);

$r2 = $w->hole('timeradd', []);
$pruefe('timeradd ueber hole() nicht erreichbar', $r2['ok'], false);

$t0 = microtime(true);
$r3 = $w->hole('epgservice', ['sRef' => 'x', 'endTime' => 1787000000]);
$ms3 = (int) round((microtime(true) - $t0) * 1000);
$pruefe('Zeitstempel als endTime abgefangen', $r3['ok'], false);
$pruefe('  ohne Netzverkehr (< 50 ms)', $ms3 < 50, true);

echo "\n" . ($fehler === 0 ? "alles bestanden\n" : "$fehler Abweichung(en)\n");
exit($fehler === 0 ? 0 : 1);
