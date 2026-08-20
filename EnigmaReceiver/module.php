<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EnigmaReceiver/autoload.php';

use Hoep\EnigmaReceiver\Geraet;
use Hoep\EnigmaReceiver\OpenWebIf;
use Hoep\EnigmaReceiver\Programm;
use Hoep\EnigmaReceiver\Sender;
use Hoep\EnigmaReceiver\Timer;

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
        $this->RegisterPropertyInteger('IntervallTimer', 15);  // Timerliste, Minuten
        $this->RegisterPropertyInteger('IntervallSender', 1440); // Senderliste, Minuten (1 Tag)

        // --- Stufe 3: alles, was den Receiver veraendert ---
        // Vorgabe AUS. Lesen braucht kein Gate; jeder Schreibaufruf schon.
        $this->RegisterPropertyBoolean('Scharf', false);
        $this->RegisterPropertyInteger('Vorlauf', 2);          // Minuten vor der Sendung
        $this->RegisterPropertyInteger('Nachlauf', 2);         // Minuten nach der Sendung
        $this->RegisterPropertyString('Verzeichnis', '');      // dirname am Receiver, leer = Vorgabe der Box
        $this->RegisterPropertyInteger('Nachher', 3);          // afterevent: 3 = automatisch

        // Ruhe bis (Unix), letzter Fehlertext, erkannte OpenWebIf-Fassung.
        $this->RegisterAttributeInteger('RuheBis', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('Fassung', '');
        $this->RegisterAttributeString('SenderCache', '');
        $this->RegisterAttributeInteger('SenderStand', 0);

        $this->RegisterTimer('ER_Status', 0, 'ER_Aktualisieren($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ER_Geraet', 0, 'ER_GeraetLesen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ER_Timer', 0, 'ER_TimerLesen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ER_Sender', 0, 'ER_SenderLesen($_IPS[\'TARGET\']);');
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
        $this->RegisterVariableInteger('TimerAnzahl', 'Programmierte Aufnahmen', '', $p += 10);
        $this->RegisterVariableString('TimerListe', 'Aufnahmen', '~TextBox', $p += 10);
        $this->RegisterVariableInteger('SenderAnzahl', 'Sender', '', $p += 10);
        $this->RegisterVariableString('Meldung', 'Meldung', '~TextBox', $p += 10);

        // Plattenvariablen NICHT hier: die gemessene Box meldet 'hdd' als leere
        // Liste, weil sie auf eine Netzfreigabe aufnimmt. Sie entstehen erst,
        // wenn ein Receiver wirklich eine Platte meldet.

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->setzeTimer(0, 0, 0, 0);
            $this->SetStatus(201);   // Adresse fehlt - das kann nur der Anwender richten
            return;
        }
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->setzeTimer(0, 0, 0, 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
        $this->setzeTimer(
            $this->ReadPropertyInteger('IntervallStatus'),
            $this->ReadPropertyInteger('IntervallGeraet'),
            $this->ReadPropertyInteger('IntervallTimer'),
            $this->ReadPropertyInteger('IntervallSender')
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


    // ---- Stufe 2: Sender und Programm -------------------------------------

    /** Senderliste vom Receiver holen und ablegen. Ruft der Sender-Timer. */
    public function SenderLesen(): bool
    {
        // getallservices liefert ALLE Bouquets mit ihren Sendern in einem Aufruf
        // (gemessen: 8 Bouquets, 56 KB, 69 ms). Eine Schleife ueber die Bouquets
        // waere dieselbe Datenmenge in acht Anfragen.
        $a = $this->frage('getallservices');
        if (!$a['ok']) {
            return false;
        }
        $l = Sender::ausAlleDienste($a['daten']);
        $this->WriteAttributeString('SenderCache', $this->json($l));
        $this->WriteAttributeInteger('SenderStand', time());
        $this->SetValue('SenderAnzahl', count($l['sender']));
        return true;
    }

    /**
     * Senderliste als JSON. Holt nur dann neu, wenn die Ablage zu alt ist -
     * Senderlisten aendern sich selten, und jede Abfrage kostet die Box Zeit.
     */
    public function Sender(): string
    {
        $l = $this->senderAusAblage();
        if ($l === null) {
            return $this->json(['ok' => false, 'fehler' => 'Senderliste nicht lesbar']);
        }
        return $this->json(['ok' => true, 'stand' => date('d.m. H:i', $this->ReadAttributeInteger('SenderStand'))]
            + $l);
    }

    /** Sender ueber den Namen finden. Liefert die Service-Referenz. */
    public function FindeSender(string $Name): string
    {
        $l = $this->senderAusAblage();
        if ($l === null) {
            return $this->json(['ok' => false, 'fehler' => 'Senderliste nicht lesbar']);
        }
        $s = Sender::finde($l['sender'], $Name);
        return $s === null
            ? $this->json(['ok' => false, 'fehler' => 'kein Sender zu "' . $Name . '"'])
            : $this->json(['ok' => true] + $s);
    }

    /**
     * Programm eines Senders in einem Zeitfenster.
     *
     * @param string $SRef    Service-Referenz des Senders (NICHT eines Bouquets)
     * @param int    $Minuten Fensterlaenge in MINUTEN, hoechstens 1440. 0 = Vorgabe (240).
     * @param int    $Start   Beginn als Unix-Zeit, 0 = jetzt
     */
    public function Programm(string $SRef, int $Minuten = 0, int $Start = 0): string
    {
        $f = Programm::fenster($Minuten);
        if (!$f['ok']) {
            // Kein Netzverkehr. Ein Zeitstempel als Fensterlaenge ist ein
            // Programmfehler des Aufrufers und legt die Box lahm.
            return $this->json(['ok' => false, 'fehler' => $f['fehler']]);
        }
        $args = ['sRef' => $SRef, 'endTime' => $f['minuten']];
        $args['time'] = $Start > 0 ? $Start : time();

        $a = $this->frage('epgservice', $args);
        if (!$a['ok']) {
            return $this->json(['ok' => false, 'fehler' => $a['fehler']]);
        }
        $sendungen = Programm::ausEpg($a['daten']);
        return $this->json(['ok' => true, 'minuten' => $f['minuten'], 'anzahl' => count($sendungen),
                            'ms' => $a['ms'], 'sendungen' => $sendungen]);
    }

    /**
     * Die Sendung, die zu einer erwarteten Startzeit passt - mit exakter
     * Anfangs- und Endzeit und der Event-Kennung.
     *
     * Genau das braucht der Serienrecorder: er weiss aus XMLTV, wann etwas
     * laufen soll, und holt sich hier die Wahrheit der Box dazu.
     */
    public function SucheSendung(string $SRef, int $Start, int $ToleranzMinuten = 15): string
    {
        if ($Start <= 0) {
            return $this->json(['ok' => false, 'fehler' => 'kein Startzeitpunkt']);
        }
        // Fenster um die erwartete Zeit herum, nicht ab jetzt.
        $tol = max(1, min(240, $ToleranzMinuten));
        $von = $Start - $tol * 60;
        $p = json_decode($this->Programm($SRef, min(Programm::MAX_MINUTEN, $tol * 4), $von), true);
        if (!is_array($p) || empty($p['ok'])) {
            return $this->json(['ok' => false, 'fehler' => (string) ($p['fehler'] ?? 'Abfrage fehlgeschlagen')]);
        }
        $treffer = Programm::passend($p['sendungen'], $Start, $tol * 60);
        return $treffer === null
            ? $this->json(['ok' => false, 'fehler' => 'keine Sendung im Fenster', 'geprueft' => $p['anzahl']])
            : $this->json(['ok' => true, 'sendung' => $treffer]);
    }

    // ---- Stufe 3: programmierte Aufnahmen ---------------------------------

    /** Timerliste vom Receiver holen. Ruft der Timer-Timer. Rein lesend. */
    public function TimerLesen(): bool
    {
        $a = $this->frage('timerlist');
        if (!$a['ok']) {
            return false;
        }
        $t = Timer::ausListe($a['daten']);
        $this->SetValue('TimerAnzahl', count($t));

        $zeilen = [['Sender', 'Beginn', 'Ende', 'Titel', 'Folge', 'Zustand']];
        foreach ($t as $x) {
            $zeilen[] = [$x['sender'], date('d.m. H:i', $x['start']), date('H:i', $x['ende']),
                         $x['name'], $x['beschreibung'], $x['aus'] ? 'aus' : $x['zustandText']];
        }
        $this->SetValue('TimerListe', $this->json($zeilen));
        return true;
    }

    /** Programmierte Aufnahmen als JSON. */
    public function Timer(): string
    {
        $a = $this->frage('timerlist');
        if (!$a['ok']) {
            return $this->json(['ok' => false, 'fehler' => $a['fehler']]);
        }
        $t = Timer::ausListe($a['daten']);
        return $this->json(['ok' => true, 'anzahl' => count($t), 'ms' => $a['ms'], 'timer' => $t]);
    }

    /**
     * Eine Aufnahme PLANEN - und nichts tun.
     *
     * Liefert einen Vorschlag, den man ansehen kann: was genau wuerde an den
     * Receiver gehen, mit welchen Zeiten, und steht die Sendung dort schon.
     * Erst `FuehreAus` schickt ihn ab. Dasselbe zweistufige Muster wie beim
     * Aufraeumen doppelter Aufnahmen: erst die Liste, dann die Handlung.
     *
     * @param string $Auftrag JSON: {"sRef":"...","start":<unix>,"ende":<unix>,
     *                        "titel":"...","kurz":"...","eventId":0}
     *                        oder {"sender":"ORF1","start":<unix>,...} - dann wird
     *                        der Sender ueber die Senderliste aufgeloest.
     */
    public function PlaneAufnahme(string $Auftrag): string
    {
        $a = json_decode($Auftrag, true);
        if (!is_array($a)) {
            return $this->json(['ok' => false, 'fehler' => 'ungueltiges JSON']);
        }

        $ref = trim((string) ($a['sRef'] ?? ''));
        if ($ref === '' && trim((string) ($a['sender'] ?? '')) !== '') {
            $f = json_decode($this->FindeSender((string) $a['sender']), true);
            if (empty($f['ok'])) {
                return $this->json(['ok' => false, 'fehler' => (string) ($f['fehler'] ?? 'Sender unbekannt')]);
            }
            $ref = (string) $f['ref'];
        }
        $start = (int) ($a['start'] ?? 0);
        $ende  = (int) ($a['ende'] ?? 0);
        $titel = trim((string) ($a['titel'] ?? ''));
        if ($ref === '' || $start <= 0 || $ende <= $start || $titel === '') {
            return $this->json(['ok' => false, 'fehler' => 'unvollstaendig: sRef/sender, start, ende, titel noetig']);
        }

        $args = Timer::bauAuftrag(
            ['ref' => $ref, 'start' => $start, 'ende' => $ende, 'titel' => $titel,
             'kurz' => (string) ($a['kurz'] ?? ''), 'eventId' => (int) ($a['eventId'] ?? 0)],
            $this->ReadPropertyInteger('Vorlauf'),
            $this->ReadPropertyInteger('Nachlauf'),
            trim((string) ($a['verzeichnis'] ?? $this->ReadPropertyString('Verzeichnis'))),
            $this->ReadPropertyInteger('Nachher')
        );

        // Steht sie schon am Receiver? Das zu wissen, bevor man schreibt, erspart
        // eine Ablehnung und eine doppelte Aufnahme.
        $vorhanden = null;
        $tl = json_decode($this->Timer(), true);
        if (!empty($tl['ok'])) {
            $vorhanden = Timer::schonProgrammiert($tl['timer'], $ref, (int) $args['begin'], (int) $args['end']);
        }

        return $this->json([
            'ok'          => true,
            'vorschlag'   => $args,
            'lesbar'      => sprintf('%s · %s bis %s · %s', $titel,
                                date('d.m. H:i', (int) $args['begin']), date('H:i', (int) $args['end']),
                                $ref),
            'vorlauf'     => $this->ReadPropertyInteger('Vorlauf'),
            'nachlauf'    => $this->ReadPropertyInteger('Nachlauf'),
            'schonDa'     => $vorhanden !== null,
            'schonDaTimer' => $vorhanden,
            'scharf'      => $this->ReadPropertyBoolean('Scharf'),
            'hinweis'     => $this->ReadPropertyBoolean('Scharf')
                                ? 'Gate ist offen - FuehreAus wuerde diesen Timer setzen.'
                                : 'Gate ist zu - FuehreAus wuerde nichts an den Receiver schicken.',
        ]);
    }

    /**
     * Einen Vorschlag ausfuehren. NUR bei offenem Gate.
     *
     * @param string $Vorschlag das Feld "vorschlag" aus PlaneAufnahme, als JSON
     */
    public function FuehreAus(string $Vorschlag): string
    {
        $args = json_decode($Vorschlag, true);
        if (!is_array($args) || trim((string) ($args['sRef'] ?? '')) === '' || (int) ($args['begin'] ?? 0) <= 0) {
            return $this->json(['ok' => false, 'fehler' => 'kein brauchbarer Vorschlag']);
        }
        return $this->schreibeTimer('timeradd', $args,
            'Aufnahme ' . (string) ($args['name'] ?? '') . ' ' . date('d.m. H:i', (int) $args['begin']));
    }

    /** Einen Timer loeschen. Identitaet ist Serviceref + Beginn + Ende. NUR bei offenem Gate. */
    public function LoescheTimer(string $SRef, int $Begin, int $Ende): string
    {
        if (trim($SRef) === '' || $Begin <= 0 || $Ende <= 0) {
            return $this->json(['ok' => false, 'fehler' => 'sRef, Begin und Ende noetig - ein Timer hat keine Kennung']);
        }
        return $this->schreibeTimer('timerdelete', ['sRef' => $SRef, 'begin' => $Begin, 'end' => $Ende],
            'Timer geloescht ' . date('d.m. H:i', $Begin));
    }

    /** Einen Timer ein- oder ausschalten. NUR bei offenem Gate. */
    public function SchalteTimer(string $SRef, int $Begin, int $Ende): string
    {
        if (trim($SRef) === '' || $Begin <= 0 || $Ende <= 0) {
            return $this->json(['ok' => false, 'fehler' => 'sRef, Begin und Ende noetig']);
        }
        return $this->schreibeTimer('timertogglestatus', ['sRef' => $SRef, 'begin' => $Begin, 'end' => $Ende],
            'Timer umgeschaltet ' . date('d.m. H:i', $Begin));
    }

    /** Scharf-Gate setzen. Bewusst als eigene Funktion, damit es im Log auftaucht. */
    public function SetzeScharf(bool $Scharf): bool
    {
        IPS_SetProperty($this->InstanceID, 'Scharf', $Scharf);
        IPS_ApplyChanges($this->InstanceID);
        $this->melde($Scharf ? 'SCHARF - Schreibaufrufe erlaubt' : 'Gate geschlossen - nur noch lesend');
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


    /**
     * Senderliste aus der Ablage, bei Bedarf neu geholt.
     *
     * @return array{bouquets:list<array<string,mixed>>,sender:list<array<string,mixed>>}|null
     */
    private function senderAusAblage(): ?array
    {
        $alter = time() - $this->ReadAttributeInteger('SenderStand');
        $max   = max(60, $this->ReadPropertyInteger('IntervallSender')) * 60;
        $roh   = $this->ReadAttributeString('SenderCache');

        if ($roh === '' || $alter > $max) {
            $this->SenderLesen();
            $roh = $this->ReadAttributeString('SenderCache');
        }
        $l = json_decode($roh, true);
        return is_array($l) && isset($l['sender']) ? $l : null;
    }

    /**
     * Der EINZIGE Weg, auf dem dieses Modul etwas am Receiver veraendert.
     *
     * Drei Bedingungen, jede fuer sich ausreichend zum Abbruch: der Endpunkt
     * muss auf der Schreibliste stehen, das Gate muss offen sein, und die
     * Antwort muss `result: true` ohne Konflikte melden. Der HTTP-Code sagt
     * dazu nichts - ein abgelehnter Timer kommt mit HTTP 200.
     *
     * @param array<string,string|int> $args
     */
    private function schreibeTimer(string $endpunkt, array $args, string $was): string
    {
        $scharf = $this->ReadPropertyBoolean('Scharf');
        if (!$scharf) {
            // Kein Netzverkehr. Der Receiver erfaehrt nichts davon.
            $this->melde('abgelehnt (Gate zu): ' . $was);
            return $this->json(['ok' => false, 'scharf' => false,
                'fehler' => 'Das Gate ist zu. Es wurde nichts an den Receiver geschickt.',
                'waere' => $args]);
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return $this->json(['ok' => false, 'fehler' => 'keine Adresse']);
        }
        $ruhe = $this->ReadAttributeInteger('RuheBis');
        if ($ruhe > time()) {
            // Waehrend der Ruhezeit wird auch nicht geschrieben: die Box hatte
            // gerade keine Luft, und ein Schreibaufruf ist teurer als eine Abfrage.
            return $this->json(['ok' => false, 'fehler' => 'Ruhezeit bis ' . date('H:i:s', $ruhe)]);
        }

        $sem = 'ER_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 5000)) {
            return $this->json(['ok' => false, 'fehler' => 'andere Abfrage laeuft noch']);
        }
        try {
            $w = new OpenWebIf($host, $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyString('Benutzer'), $this->ReadPropertyString('Passwort'),
                max(5, $this->ReadPropertyInteger('Timeout')));
            $a = $w->schreibe($endpunkt, $args, true);
        } finally {
            IPS_SemaphoreLeave($sem);
        }

        if (!$a['ok']) {
            $this->melde('FEHLER: ' . $was . ' - ' . $a['fehler']);
            return $this->json(['ok' => false, 'fehler' => $a['fehler'], 'code' => $a['code']]);
        }

        $e = Timer::ergebnis($a['daten']);
        $this->melde(($e['ok'] ? 'ausgefuehrt: ' : 'abgelehnt: ') . $was
            . ($e['meldung'] !== '' ? ' - ' . $e['meldung'] : ''));

        // Die Liste stimmt jetzt nicht mehr.
        $this->TimerLesen();

        return $this->json(['ok' => $e['ok'], 'meldung' => $e['meldung'], 'konflikte' => $e['konflikte'],
                            'ms' => $a['ms'], 'gesendet' => $args]);
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

    private function setzeTimer(int $statusMin, int $geraetMin, int $timerMin, int $senderMin): void
    {
        $this->SetTimerInterval('ER_Status', max(0, $statusMin) * 60000);
        $this->SetTimerInterval('ER_Geraet', max(0, $geraetMin) * 60000);
        $this->SetTimerInterval('ER_Timer', max(0, $timerMin) * 60000);
        $this->SetTimerInterval('ER_Sender', max(0, $senderMin) * 60000);
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
        $ruhe   = $this->ReadAttributeInteger('RuheBis');
        $scharf = $this->ReadPropertyBoolean('Scharf');

        $hinweis = $ruhe > time()
            ? 'Ruhezeit bis ' . date('H:i:s', $ruhe) . ' - die Box hatte keine Luft. Grund: '
              . $this->ReadAttributeString('LetzterFehler')
            : ($scharf
                ? 'SCHARF: Dieses Modul darf Aufnahmen am Receiver anlegen, aendern und loeschen.'
                : 'Nicht scharf: alle Abfragen laufen, aber kein Schreibaufruf verlaesst das Modul.');

        return $this->json([
            'elements' => [
                ['type' => 'Label', 'caption' => $hinweis],
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Adresse (IP oder Name)'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535],
                ['type' => 'ValidationTextBox', 'name' => 'Benutzer', 'caption' => 'Benutzer (leer, wenn die Box keine Anmeldung verlangt)'],
                ['type' => 'PasswordTextBox', 'name' => 'Passwort', 'caption' => 'Passwort'],
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Aktiv'],
                ['type' => 'ExpansionPanel', 'caption' => 'Aufnahmen programmieren (Stufe 3)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Solange ein anderes System auf denselben Receiver programmiert, muss dies AUS bleiben. Zwei Absender auf einer Box erzeugen doppelte Aufnahmen.'],
                    ['type' => 'CheckBox', 'name' => 'Scharf', 'caption' => 'Scharf - Schreibaufrufe an den Receiver erlauben'],
                    ['type' => 'NumberSpinner', 'name' => 'Vorlauf', 'caption' => 'Vorlauf (Minuten vor der Sendung)', 'minimum' => 0, 'maximum' => 60],
                    ['type' => 'NumberSpinner', 'name' => 'Nachlauf', 'caption' => 'Nachlauf (Minuten nach der Sendung)', 'minimum' => 0, 'maximum' => 120],
                    ['type' => 'ValidationTextBox', 'name' => 'Verzeichnis', 'caption' => 'Aufnahmeverzeichnis am Receiver (leer = Vorgabe der Box)'],
                    ['type' => 'Select', 'name' => 'Nachher', 'caption' => 'Nach der Aufnahme', 'options' => [
                        ['caption' => 'nichts tun', 'value' => 0],
                        ['caption' => 'Standby', 'value' => 1],
                        ['caption' => 'Tiefschlaf', 'value' => 2],
                        ['caption' => 'automatisch', 'value' => 3],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Abfragetakt', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'IntervallStatus', 'caption' => 'Zustand alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                    ['type' => 'NumberSpinner', 'name' => 'IntervallGeraet', 'caption' => 'Geraetedaten alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                    ['type' => 'NumberSpinner', 'name' => 'IntervallTimer', 'caption' => 'Programmierte Aufnahmen alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                    ['type' => 'NumberSpinner', 'name' => 'IntervallSender', 'caption' => 'Senderliste alle (Minuten, 0 = aus)', 'minimum' => 0, 'maximum' => 10080],
                    ['type' => 'NumberSpinner', 'name' => 'Timeout', 'caption' => 'Zeitgrenze je Abfrage (Sekunden)', 'minimum' => 2, 'maximum' => 30],
                    ['type' => 'NumberSpinner', 'name' => 'RuheMinuten', 'caption' => 'Ruhezeit nach einer Zeitueberschreitung (Minuten)', 'minimum' => 1, 'maximum' => 120],
                    ['type' => 'Label', 'caption' => 'Nach einer Zeitueberschreitung wird nicht nachgefasst. OpenWebIf arbeitet Anfragen einzeln ab; ein zweiter Versuch stellt sich nur in dieselbe Warteschlange.'],
                ]],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt abfragen', 'onClick' => 'ER_Aktualisieren($id); ER_GeraetLesen($id);'],
                ['type' => 'Button', 'caption' => 'Zustand anzeigen', 'onClick' => 'echo ER_Status($id);'],
                ['type' => 'Button', 'caption' => 'Geraetedaten anzeigen', 'onClick' => 'echo ER_Geraet($id);'],
                ['type' => 'Button', 'caption' => 'Senderliste holen', 'onClick' => 'ER_SenderLesen($id); echo ER_Sender($id);'],
                ['type' => 'Button', 'caption' => 'Programmierte Aufnahmen', 'onClick' => 'echo ER_Timer($id);'],
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
