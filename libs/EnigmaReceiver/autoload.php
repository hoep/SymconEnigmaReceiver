<?php

declare(strict_types=1);

/**
 * Feste Ladeliste in Abhaengigkeitsreihenfolge.
 *
 * Das Verzeichnis libs/ von IP-Symcon kennt kein PSR-4-Autoloading, und ein
 * registrierter Autoloader ueberlebt den Modulwechsel nicht zuverlaessig.
 *
 * KRITISCH: eine Datei, die hier fehlt, existiert fuer Symcon nicht - und
 * sobald eine geladene Klasse sie braucht, reisst der Ladefehler die GESAMTE
 * Library mit, ohne dass im Log ein fataler Fehler auftaucht. Wer eine Klasse
 * hinzufuegt, traegt sie hier ein.
 */

require_once __DIR__ . '/OpenWebIf.php';
require_once __DIR__ . '/Geraet.php';
require_once __DIR__ . '/Sender.php';
require_once __DIR__ . '/Programm.php';
require_once __DIR__ . '/Timer.php';
