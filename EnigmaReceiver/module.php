<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EnigmaReceiver/autoload.php';

use Hoep\EnigmaReceiver\Geraet;
use Hoep\EnigmaReceiver\OpenWebIf;

/**
 * Ein Enigma2-Receiver als Symcon-Instanz.
 *
 * Eine Instanz je Geraet. Kein Splitter: anders als bei einer geteilten
 * TCP-Sitzung hat jeder Receiver seine eigene HTTP-Endstelle, und ein
 * abgeschalteter Receiver darf die anderen nicht mitreissen.
 *
 * Stufe 1 dieses Moduls ist REIN LESEND. Es gibt keinen Aufruf, der die Box
 * umschaltet, eine Aufnahme programmiert oder eine Meldung einblendet - die
 * Positivliste in OpenWebIf laesst das gar nicht zu.
 *
 * Zwei Dinge bestimmen das Verhalten, beide teuer gelernt:
 *
 * 1. OpenWebIf laeuft im Hauptprozess von Enigma2. Eine schwere Anfrage
 *    blockiert die ganze Box einschliesslich Fernbedienung. Deshalb geht immer
 *    nur EINE Anfrage gleichzeitig an dieselbe Instanz (Semaphore).
 * 2. Nach einem Timeout wird NICHT nachgefasst. Der Receiver gilt dann fuer
 *    einige Minuten als beschaeftigt, und jede Abfrage faellt sofort aus, ohne
 *    ihn anzufassen. Nachfassen reiht sich nur in dieselbe Warteschlange ein.
 */
class EnigmaReceiver extends IPSModule
{
    private const PROFIL_ERREICHBAR = 'ER.Erreichbar';
    private const PROFIL_STANDBY    = 'ER.Standby';
    private const PROFIL_AUFNAHME   = 'ER.Aufnahme';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyString('Benutzer', '');
        $this->RegisterPropertyString('Passwort', '');
        $this->RegisterPropertyInteger('Timeout', 5);          // Sekunden
        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyInteger('IntervallStatus', 5);  // Minuten, 0 = aus
        $this->RegisterPropertyInteger('IntervallGeraet', 60); // Minuten, 0 = aus
        $this->RegisterPropertyInteger('RuheMinuten', 10);     // Ruhezeit nach einem Timeout

        // Ruhe bis (Unix), letzter Fehlertext, erkannte OpenWebIf-Fassung.
        $this->RegisterAttributeInteger('RuheBis', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('Fassung', '');

        $this->RegisterTimer('ER_Status', 0, 'ER_Aktualisieren($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ER_Geraet', 0, 'ER_GeraetLesen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->legeProfileAn();

        $p = 0;
        $this->RegisterVariableBoolean('Erreichbar', 'Erreichbar', self::PROFIL_ERREICHBAR, $p += 10);
        $this->RegisterVariableBoolean('Standby', 'Standby', self::PROFIL_STANDBY, $p += 10);
        $this->RegisterVariableBoolean('Aufnahme', 'Aufnahme', self::PROFIL_AUFNAHME, $p += 10);
        $this->RegisterVariableString('Sender', 'Sender', '', $p += 10);
        $this->RegisterVariableString('Sendung', 'Sendung', '', $p += 10);
        $this->RegisterVariableInteger('TunerFrei', 'Tuner frei', '', $p += 10);
        $this->RegisterVariableInteger('TunerGesamt', 'Tuner gesamt', '', $p += 10);
        $this->RegisterVariableString('Modell', 'Modell', '', $p += 10);
        $this->RegisterVariableString('Image', 'Image', '', $p += 10);
        $this->RegisterVariableString('Laufzeit', 'Laufzeit', '', $p += 10);
        $this->RegisterVariableString('Meldung', 'Meldung', '~TextBox', $p += 10);

        // Plattenvariablen NICHT hier: die gemessene Box meldet 'hdd' als leere
        // Liste, weil sie auf eine Netzfreigabe aufnimmt. Sie entstehen erst,
        // wenn ein Receiver wirklich eine Platte meldet.

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->setzeTimer(0, 0);
            $this->SetStatus(201);   // Adresse fehlt - das kann nur der Anwender richten
            return;
        }
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->setzeTimer(0, 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
        $this->setzeTimer(
            $this->ReadPropertyInteger('IntervallStatus'),
            $this->ReadPropertyInteger('IntervallGeraet')
        );
    }

    // ==================================================================
    // Oeffentliche Funktionen

    /** Laufenden Zustand holen (statusinfo). Ruft der Status-Timer. */
    public function Aktualisieren(): bool
    {
        $a = $this->frage('statusinfo');
        if (!$a['ok']) {
            return false;
        }
        $s = Geraet::ausStatus($a['daten']);
        $this->SetValue('Standby', $s['standby']);
        $this->SetValue('Aufnahme', $s['aufnahme']);
        $this->SetValue('Sender', $s['sender']);
        $this->SetValue('Sendung', $s['sendung']);
        return true;
    }

    /** Geraetedaten holen (deviceinfo). Ruft der Geraete-Timer. */
    public function GeraetLesen(): bool
    {
        $a = $this->frage('deviceinfo');
        if (!$a['ok']) {
            return false;
        }
        $g = Geraet::ausGeraet($a['daten']);
        $this->SetValue('Modell', $g['modell']);
        $this->SetValue('Image', $g['image']);
        $this->SetValue('Laufzeit', $g['laufzeit']);
        $this->SetValue('TunerFrei', $g['tunerFrei']);
        $this->SetValue('TunerGesamt', $g['tunerGesamt']);
        $this->WriteAttributeString('Fassung', $g['image']);

        // Platten nur anlegen, wenn der Receiver wirklich welche meldet.
        foreach ($g['platten'] as $i => $pl) {
            $identFrei  = 'PlatteFrei' . ($i > 0 ? (string) $i : '');
            $identVoll  = 'PlatteBelegt' . ($i > 0 ? (string) $i : '');
            $bez = $pl['name'] !== '' ? $pl['name'] : ('Platte ' . ($i + 1));
            $this->RegisterVariableFloat($identFrei, $bez . ' frei (GB)', '', 200 + $i * 2);
            $this->RegisterVariableFloat($identVoll, $bez . ' belegt (%)', '', 201 + $i * 2);
            $this->SetValue($identFrei, $pl['frei']);
            $this->SetValue($identVoll, $pl['belegtProzent']);
        }
        return true;
    }

    /** Zustand als JSON - fuer Formular, LiveViewBuilder und andere Module. */
    public function Status(): string
    {
        $a = $this->frage('statusinfo');
        if (!$a['ok']) {
            return $this->json(['ok' => false, 'fehler' => $a['fehler']]);
        }
        return $this->json(['ok' => true] + Geraet::ausStatus($a['daten']) + ['ms' => $a['ms']]);
    }

    /** Geraetedaten als JSON. */
    public function Geraet(): string
    {
        $a = $this->frage('deviceinfo');
        if (!$a['ok']) {
            return $this->json(['ok' => false, 'fehler' => $a['fehler']]);
        }
        return $this->json(['ok' => true] + Geraet::ausGeraet($a['daten']) + ['ms' => $a['ms']]);
    }

    /**
     * Diagnose: was kann DIESE Box wirklich?
     *
     * Ruft die leichten Endpunkte der Positivliste der Reihe nach - einzeln,
     * mit Pause dazwischen - und meldet je Endpunkt Antwortzeit und Groesse.
     * Nichts davon veraendert etwas an der Box.
     */
    public function Probe(): string
    {
        $erg = [];
        foreach (['statusinfo', 'currenttime', 'powerstate', 'getcurrent', 'bouquets', 'signal'] as $e) {
            $a = $this->frage($e, [], false);
            $erg[$e] = ['ok' => $a['ok'], 'ms' => $a['ms'],
                        'felder' => $a['ok'] ? array_slice(array_keys($a['daten']), 0, 8) : [],
                        'fehler' => $a['fehler']];
            usleep(200000);   // 200 ms Abstand: die Box arbeitet Anfragen einzeln ab
        }
        $a = $this->frage('deviceinfo', [], false);
        $erg['deviceinfo'] = ['ok' => $a['ok'], 'ms' => $a['ms'], 'fehler' => $a['fehler']];
        return $this->json(['ok' => true, 'host' => $this->ReadPropertyString('Host'), 'endpunkte' => $erg]);
    }

    /** Ruhezeit vorzeitig beenden (nach einem Timeout gesetzt). */
    public function Wecken(): bool
    {
        $this->WriteAttributeInteger('RuheBis', 0);
        $this->melde('Ruhezeit aufgehoben');
        return true;
    }

    // ==================================================================

    /**
     * Eine Abfrage an die Box - serialisiert, mit Ruhezeit nach Timeout.
     *
     * @param array<string,string|int> $args
     * @return array{ok:bool,daten:array<mixed>,fehler:string,code:int,ms:int}
     */
    private function frage(string $endpunkt, array $args = [], bool $timerBetrieb = true): array
    {
        $leer = fn(string $f): array => ['ok' => false, 'daten' => [], 'fehler' => $f, 'code' => 0, 'ms' => 0];

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return $leer('keine Adresse');
        }

        // Ruhezeit: die Box hatte gerade keine Luft. Nicht anfassen.
        $ruhe = $this->ReadAttributeInteger('RuheBis');
        if ($ruhe > time()) {
            return $leer('Ruhezeit bis ' . date('H:i:s', $ruhe) . ' (' . $this->ReadAttributeString('LetzterFehler') . ')');
        }

        // Immer nur EINE Anfrage gleichzeitig gegen dieselbe Box.
        $sem = 'ER_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 2000)) {
            return $leer('andere Abfrage laeuft noch');
        }

        try {
            $w = new OpenWebIf($host, $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyString('Benutzer'), $this->ReadPropertyString('Passwort'),
                max(2, $this->ReadPropertyInteger('Timeout')));
            $a = $w->hole($endpunkt, $args);
        } finally {
            IPS_SemaphoreLeave($sem);
        }

        if ($a['ok']) {
            $this->SetValue('Erreichbar', true);
            $this->WriteAttributeString('LetzterFehler', '');
            if ($this->GetStatus() !== 102 && $this->ReadPropertyBoolean('Aktiv')) {
                $this->SetStatus(102);
            }
            return $a;
        }

        $this->SetValue('Erreichbar', false);
        $this->WriteAttributeString('LetzterFehler', $a['fehler']);

        if ($a['code'] === 401) {
            // Das kann nur der Anwender richten - also sichtbar machen.
            $this->SetStatus(202);
            $this->melde('Anmeldung abgelehnt');
            return $a;
        }

        // Ein nicht erreichbarer Receiver ist ein Betriebszustand, kein Fehler:
        // kein Instanzstatus, kein Logeintrag. Er ist einfach aus.
        // Hat er aber ANGEFANGEN zu antworten und dann nicht mehr, war die
        // Anfrage zu schwer - dann Ruhezeit.
        if ($timerBetrieb && $a['ms'] >= ($this->ReadPropertyInteger('Timeout') * 1000)) {
            $min = max(1, $this->ReadPropertyInteger('RuheMinuten'));
            $this->WriteAttributeInteger('RuheBis', time() + $min * 60);
            $this->melde('keine Antwort nach ' . $a['ms'] . ' ms - Ruhe bis ' . date('H:i', time() + $min * 60));
        }
        return $a;
    }

    private function melde(string $text): void
    {
        $this->SetValue('Meldung', date('d.m. H:i') . ' · ' . $text);
    }

    /** @param array<string,mixed> $d */
    private function json(array $d): string
    {
        return (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function setzeTimer(int $statusMin, int $geraetMin): void
    {
        $this->SetTimerInterval('ER_Status', max(0, $statusMin) * 60000);
        $this->SetTimerInterval('ER_Geraet', max(0, $geraetMin) * 60000);
    }

    private function legeProfileAn(): void
    {
        $bool = function (string $name, string $aus, string $an, int $farbeAus, int $farbeAn): void {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_BOOLEAN);
            }
            IPS_SetVariableProfileAssociation($name, 0, $aus, '', $farbeAus);
            IPS_SetVariableProfileAssociation($name, 1, $an, '', $farbeAn);
        };
        $bool(self::PROFIL_ERREICHBAR, 'nicht erreichbar', 'erreichbar', 0x808080, 0x00A000);
        $bool(self::PROFIL_STANDBY, 'in Betrieb', 'Standby', 0x00A000, 0x808080);
        $bool(self::PROFIL_AUFNAHME, 'keine Aufnahme', 'nimmt auf', 0x808080, 0xC00000);
    }

    public function GetConfigurationForm(): string
    {
        $ruhe = $this->ReadAttributeInteger('RuheBis');
        $hinweis = $ruhe > time()
            ? 'Ruhezeit bis ' . date('H:i:s', $ruhe) . ' - die Box hatte keine Luft. Grund: '
              . $this->ReadAttributeString('LetzterFehler')
            : 'Stufe 1: rein lesend. Dieses Modul kann die Box weder umschalten noch programmieren.';

        return $this->json([
            'elements' => [
                ['type' => 'Label', 'caption' => $hinweis],
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Adresse (IP oder Name)'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535],
                ['type' => 'ValidationTextBox', 'name' => 'Benutzer', 'caption' => 'Benutzer (leer, wenn die Box keine Anmeldung verlangt)'],
                ['type' => 'PasswordTextBox', 'name' => 'Passwort', 'caption' => 'Passwort'],
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Aktiv'],
                ['type' => 'ExpansionPanel', 'caption' => 'Abfragetakt', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'IntervallStatus', 'caption' => 'Zustand alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                    ['type' => 'NumberSpinner', 'name' => 'IntervallGeraet', 'caption' => 'Geraetedaten alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                    ['type' => 'NumberSpinner', 'name' => 'Timeout', 'caption' => 'Zeitgrenze je Abfrage (Sekunden)', 'minimum' => 2, 'maximum' => 30],
                    ['type' => 'NumberSpinner', 'name' => 'RuheMinuten', 'caption' => 'Ruhezeit nach einer Zeitueberschreitung (Minuten)', 'minimum' => 1, 'maximum' => 120],
                    ['type' => 'Label', 'caption' => 'Nach einer Zeitueberschreitung wird nicht nachgefasst. OpenWebIf arbeitet Anfragen einzeln ab; ein zweiter Versuch stellt sich nur in dieselbe Warteschlange.'],
                ]],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt abfragen', 'onClick' => 'ER_Aktualisieren($id); ER_GeraetLesen($id);'],
                ['type' => 'Button', 'caption' => 'Zustand anzeigen', 'onClick' => 'echo ER_Status($id);'],
                ['type' => 'Button', 'caption' => 'Geraetedaten anzeigen', 'onClick' => 'echo ER_Geraet($id);'],
                ['type' => 'Button', 'caption' => 'Endpunkte pruefen (Diagnose)', 'onClick' => 'echo ER_Probe($id);'],
                ['type' => 'Button', 'caption' => 'Ruhezeit aufheben', 'onClick' => 'ER_Wecken($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'nicht aktiv'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'keine Adresse eingetragen'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Anmeldung abgelehnt'],
            ],
        ]);
    }
}
