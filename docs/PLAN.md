# EnigmaReceiver - Plan fuer ein Symcon-Modul

Stand 20.08.2026. Grundlage: Quelltext von OpenWebIf und Enigma2, Messung an
<Receiver 1>, Bestandsaufnahme der 35 Altskripte, Hausmuster der bestehenden Module.

## 1. Ausgangslage

Im Haus stehen drei Enigma-Receiver, von denen zur Zeit einer laeuft:

| Adresse | Rolle | Gemessen |
|---|---|---|
| <Receiver 1> | Vu+ Ultimo 4K, VTi 15.0.02, OWIF 1.2.8 | erreichbar, keine Anmeldung, Port 80 |
| <Receiver 2> | Dreambox | offline |
| <Receiver 3> | zweiter TVServer | offline |

35 Skripte sprechen die Boxen an, davon laufen noch zwei nach Zeitplan: der
Serienrecorder 44702 alle zwei Stunden und die Senderabfrage 52582 stuendlich.

**Der Serienrecorder programmiert heute nicht.** Das Modul liest ausschliesslich
`/web/timerlist`, um zu erkennen, was schon eingeplant ist; das Setzen von Timern macht
allein das Altskript. Die Begruendung steht im Code (`libs/SeriesRecorder/Receiver.php:52`):
zwei Absender auf demselben Receiver waeren ein Rezept fuer doppelte Aufnahmen. Genau
deshalb ist die Programmierung hier kein Nachruesten, sondern gehoert von Anfang an in
das neue Modul.

## 2. Architektur

**Eine Instanz je Receiver**, ModuleType 3 (Device), ohne Elternanforderung - das
Hausmuster fuer Geraete, die selbst per HTTP sprechen (wie SeriesRecorder, WeatherSource,
PoolController). Kein Splitter: anders als bei HEOS teilt sich hier nichts eine
TCP-Sitzung, jeder Receiver hat seine eigene HTTP-Endstelle, und ein abgeschalteter
Receiver darf die anderen nicht mitreissen.

```
/var/lib/symcon/modules/SymconEnigmaReceiver/
  library.json                      neue Library-GUID
  GUIDS.md                          Register, immutabel
  VERSION
  EnigmaReceiver/module.json        Modul-GUID, prefix "ER", type 3
  EnigmaReceiver/module.php         class EnigmaReceiver extends IPSModule
  libs/EnigmaReceiver/
    autoload.php                    feste Ladeliste, kein PSR-4
    OpenWebIf.php                   HTTP-Zugriff, Timeouts, Sperre
    Geraet.php                      about/deviceinfo/statusinfo -> Kennzahlen
    Sender.php                      Bouquets, Senderliste, sRef-Behandlung
    Programm.php                    EPG je Sender, gedeckeltes Fenster
    Timer.php                       Timerliste lesen, Timer setzen/aendern/loeschen
    Vorschlag.php                   Vorschlagsliste vor jedem Schreibzugriff
  docs/PLAN.md
  tests/                            nackte PHP-Skripte, kein Framework
```

Eigenes Repo statt Aufnahme in HomeSuite: HomeSuite traegt 118 Instanzen, und ein
Library-Reload dort hat den Kernel mehrfach abgeschossen. Eine eigene Library laesst sich
per Kaltstart sauber einspielen, ohne die bestehenden Domaenen anzufassen.

HTTP ueber Streams (`file_get_contents` mit Stream-Context), nicht curl - damit die
Receiver-Logik ohne Kernel testbar bleibt; php-cli hat hier kein curl.

## 3. Raumzuordnung

Raeume werden nicht selbst modelliert. Es gibt HSSP `{5598F752-886D-475F-91CE-5813A3C581E5}`
(Haus / Bereich / Raum), und die Zuordnung ist die **Elternschaft im Objektbaum**:
`Hub::nearestRoom()` laeuft vom Geraet aufwaerts, bis es ein HSSP mit `Kind = Raum` findet.
Eine ER-Instanz unter "Wohnzimmer" ist damit im Wohnzimmer - ohne eigene Property.

Ein Punkt, der geprueft werden musste: `Hub::discoverEntities()` sammelt Entitaeten ueber
eine **feste GUID-Liste** der HomeSuite-Module. Ein fremdes Modul taucht im Raumbaum des
Hubs also nicht von selbst auf. Zwei Wege:

1. **Eine Zeile in `Hub::domainGuids()`** mit der ER-GUID und der Domaene "Medien".
   Sauber, dauerhaft, aber eine Aenderung an HomeSuite.
2. **Verknuepfung** der ER-Instanz in den Raum. Der Hub nimmt Links jeder Herkunft auf
   (`IPS_GetLinkList`) und bestimmt die Domaene heuristisch. Kein fremder Code, dafuer
   ein Objekt mehr je Receiver.

Vorschlag: Weg 1, zusammen mit dem naechsten HomeSuite-Neustart eingespielt. Bis dahin
funktioniert Weg 2 ohne jede Aenderung.

## 4. Schnittlinie zum Serienrecorder

| | Serienrecorder (SR) | EnigmaReceiver (ER) |
|---|---|---|
| Entscheidet | WAS aufgenommen wird | - |
| Kennt | Wunschliste, XMLTV, Bestand, Duplikate | Receiver, Sender, Timer |
| Spricht mit | Dateien, XMLTV, TMDB | ausschliesslich den Boxen |
| Programmiert | nein | ja, hinter dem Gate |

SR ruft ER ueber oeffentliche Prefix-Funktionen. Der heutige Zustand hilft dabei: SRs
Feld `ReceiverIP` ist **leer**, das Modul hat die Box noch nie angefasst. Die Umstellung
bricht also nichts - sie ersetzt eine ungenutzte IP durch einen Instanzverweis
(`SelectInstance`). Das Altskript 44702 bleibt bis zur Abnahme von Stufe 3 der einzige
Absender.

## 5. Was der Quelltext dem Plan vorschreibt

Diese Punkte sind keine Empfehlungen, sondern Folgen aus dem Code der Fassung, die auf
der Box laeuft. Beide Ausfaelle am 20.08. gehen darauf zurueck.

1. **`endTime` sind Minuten, kein Zeitstempel.** `getChannelEpg` reicht den Wert an
   `lookupEvent(['IBDTSENC', (ref, 0, begintime, endtime)])` weiter, und der vierte Platz
   ist `int minutes` (`startTimeQuery(service, begin, minutes = -1)`). Ein Zeitstempel
   bedeutet eine Abfrage ueber rund 3400 Jahre.
2. **In 1.2.x fehlt jede Absicherung.** Die Bremse `if endtime > 100000: endtime = -1`
   kam erst spaeter ins Projekt. Das Modul deckelt deshalb selbst: hoechstens 1440 Minuten.
3. **`epgbouquet` kennt in 1.2.x kein `endTime`** - der Handler liest nur `bRef` und
   `time`. Bouquet-EPG ist auf dieser Box grundsaetzlich unbegrenzt. Das Modul verwendet
   `epgbouquet` und `epgmulti` nicht.
4. **OpenWebIf laeuft im Hauptprozess von Enigma2.** Eine schwere Abfrage blockiert die
   Box einschliesslich Fernbedienung. Also: nie zwei Abfragen gleichzeitig gegen denselben
   Receiver, geschuetzt durch eine Semaphore je Instanz.
5. **Nach einem Timeout nicht nachfassen.** Der Receiver wird fuer 10 Minuten als
   beschaeftigt gefuehrt; jede Abfrage in dieser Zeit faellt sofort aus, ohne die Box
   anzufassen. Nachfassen reiht sich nur in dieselbe Warteschlange ein.
6. **Das grosse EPG kommt aus XMLTV, nicht von der Box.** Der Serienrecorder laedt es
   ohnehin. Die Box wird nur fuer das schmale Fenster um eine konkrete Sendung gefragt.

## 6. Datenmodell je Instanz

| Ident | Typ | Profil | Quelle | Takt |
|---|---|---|---|---|
| `Erreichbar` | bool | ~Alert.Reversed | HTTP-Antwort | 5 min |
| `Standby` | bool | ~Switch | statusinfo.inStandby | 5 min |
| `Aufnahme` | bool | ~Record | statusinfo.isRecording | 5 min |
| `Sender` | string | - | statusinfo.currservice_name | 5 min |
| `Sendung` | string | - | statusinfo.currservice_description | 5 min |
| `Modell` | string | - | about.model | 6 h |
| `Image` | string | - | about.imagever + webifver | 6 h |
| `Laufzeit` | int | ER.Uptime | deviceinfo.uptime | 1 h |
| `PlatteFrei` | float | ~Gigabyte | deviceinfo.hdd[].free | 1 h |
| `PlatteBelegt` | float | ~Percent | aus capacity/free | 1 h |
| `TunerFrei` | int | - | deviceinfo.tuners, rec-Flag | 5 min |
| `TimerAnzahl` | int | - | timerlist | 15 min |
| `TimerListe` | string | ~TextBox | Tabelle fuer den LiveViewBuilder | 15 min |
| `Meldung` | string | ~TextBox | letzte Handlung mit Zeitstempel | bei Bedarf |

Nicht persistiert: EPG-Antworten und die Senderliste. Sender kommen in eine
Zwischenablage unter `/var/lib/symcon/enigma/<instanz>/` mit 24 Stunden Haltbarkeit -
so macht es der Altbestand seit Jahren (`channels.json`), und es hat sich bewaehrt.

Temperatur und Systemlast liefert diese OpenWebIf-Fassung nicht. Keine Variablen dafuer.

## 7. Abfragetakt

| Timer | Takt | Aufrufe | Gemessen |
|---|---|---|---|
| Status | 5 min | statusinfo | 9 ms |
| Bestand | 15 min | timerlist | 18 ms |
| Geraet | 1 h | deviceinfo | 94 ms |
| Sender | 24 h | bouquets + getservices je Bouquet | 10 + 69 ms |

Alles unter 100 ms, mit Ausnahme von `movielist` (317 ms) und `epgservice` je Sender
(223 KB, 432 ms). Der Takt ist damit unkritisch - kritisch ist allein, was unter Punkt 5
steht.

Im Standby wird derselbe Takt gefahren, aber nur `statusinfo`; die Box antwortet dort
normal. Nach drei erfolglosen Versuchen faellt die Instanz auf einen Ruhetakt von
30 Minuten zurueck, bis sie wieder antwortet. Ein dauerhaft ausgeschalteter Receiver
(.229) darf weder das Log fluten noch die Instanz auf Fehler setzen; er ist einfach aus.

## 8. Ausbaustufen

### Stufe 1 - Grunddaten und Statistik

Umfang: Instanz je Receiver, Formular mit Host/Port/Anmeldung, Status- und Geraetetimer,
die Variablen aus Abschnitt 6 ausser TimerListe, Erreichbarkeitslogik mit Ruhetakt.

Oeffentlich: `ER_Aktualisieren($id)`, `ER_Status($id)` (JSON), `ER_Geraet($id)` (JSON).

Abnahme: drei Instanzen angelegt, .85 liefert alle Werte, .228 und .229 stehen auf
"nicht erreichbar" ohne Fehlerstatus und ohne Logeintraege; ein Neustart von Symcon
aendert daran nichts; die Werte stimmen mit der Weboberflaeche der Box ueberein.

### Stufe 2 - EPG und Sender

Umfang: Bouquets und Senderliste mit Zwischenablage, EPG je Sender mit gedeckeltem
Fenster, Aufloesung Sendername zu sRef, Picon-Pfad.

Oeffentlich:
- `ER_Sender($id)` - Senderliste als JSON, aus der Ablage
- `ER_Programm($id, string $SRef, int $Minuten)` - EPG-Fenster, Minuten hart auf 1440
  gedeckelt, Vorgabe 240
- `ER_SucheSendung($id, string $SRef, int $Start, int $Toleranz)` - die Sendung um eine
  Startzeit herum, mit exakter Anfangs- und Endzeit und der Event-ID

- `ER_Laeuft($id, string $SRef, bool $MitNaechster)` - laufende und folgende Sendung
  eines Senders, ueber `epgservicenow`/`epgservicenext`; diese Endpunkte kennen kein
  `endTime` und koennen die Box daher gar nicht ueberlasten
- `ER_Uebersicht($id, string $Sender, bool $MitNaechster, int $MaxAlterSekunden)` -
  dasselbe fuer eine Liste von Sendern, der Baustein fuer eine Fernsehseite
- `ER_Picon($id, string $SRef)` - Adresse des Senderlogos, gerechnet aus der Referenz

Abnahme: Senderliste stimmt mit dem Bouquet der Box ueberein; ein Fenster von 240 Minuten
liefert unter 20 KB in unter 100 ms; ein Aufruf mit `Minuten = 999999` wird vom Modul
abgelehnt, ohne die Box anzufassen; zwei gleichzeitige Aufrufe werden serialisiert.

**Erfuellt am 21.08.2026** an der Vu+ Ultimo 4K (#<ID>): Senderliste 348 Sender in
8 Bouquets; EPG 240 Minuten = 10 Sendungen, 3805 Bytes, 16 ms; `Minuten = 999999`
abgelehnt in 0 ms ohne Netzverkehr; `SucheSendung` trifft die Sendung mit dem
kleinsten Abstand samt Event-ID.

**Uebersicht - drei Bremsen.** Weil OpenWebIf im Hauptprozess von Enigma2 laeuft, ist
die Zahl der Abfragen der eigentliche Kostenfaktor, nicht ihre Groesse:

1. hoechstens 20 Sender je Aufruf (`MAX_UEBERSICHT`),
2. Mindestabstand 15 Sekunden zwischen zwei echten Laeufen; dazwischen antwortet die
   Ablage. Das gilt auch, wenn der Aufrufer `MaxAlterSekunden = 0` uebergibt - eine
   Seite mit kurzem Zeichentakt darf die Box nicht im Sekundentakt befragen,
3. Abbruch der Schleife, sobald eine einzelne Abfrage ueber 1500 ms braucht oder die
   Instanz in der Ruhezeit steht.

Gemessen (10 Sender, 20 Abfragen): 176 ms gesamt, 17 ms je Sender; die Antwortzeit
der Box auf `statusinfo` lag vorher wie nachher bei 9 ms. Picons: 10 von 10 vorhanden,
je rund 6,3 KB.

Bewusst NICHT verwendet: `epgnow`/`epgbouquet` mit einer Bouquet-Referenz. Eine
Anfrage statt zwanzig waere verlockend, aber genau diese Familie hat die Box zweimal
lahmgelegt; sie steht deshalb nicht auf der Positivliste.

### Stufe 3 - Programmierung

Umfang: Timerliste lesen, Timer anlegen, aendern, loeschen, ein- und ausschalten -
alles hinter dem Gate.

Ein Timer hat **keine ID**. Seine Identitaet ist das Tripel `sRef` + `begin` + `end`;
Aendern verlangt zusaetzlich `channelOld`, `beginOld`, `endOld`. Das Modul fuehrt dieses
Tripel als Schluessel und nimmt niemals den Namen.

Der Erfolg steht **nicht im HTTP-Code**, sondern im Feld `result` der Antwort; bei
Ueberschneidung kommt zusaetzlich eine `conflicts`-Liste ("Conflicting Timer(s)
detected!"). Wer nur auf HTTP 200 prueft, haelt einen abgelehnten Timer fuer gesetzt -
genau der Fehler, der im Altsystem beim Duplikatschutz steckt.

Parameter beim Anlegen: `sRef`, `begin`, `end`, `name` sind Pflicht; dazu `description`,
`disabled`, `justplay`, `afterevent`, `dirname`, `tags`, `repeated`, `eit`.
`afterevent`: 0 nichts, 1 Standby, 2 Deep Standby, 3 automatisch.
`repeated`: Bitmaske Montag (Bit 0) bis Sonntag (Bit 6); 0x7F taeglich, 0x1F Mo-Fr.
`state`: 0 wartend, 1 vorbereitet, 2 laufend, 3 beendet.

**Vor- und Nachlauf** werden vom Aufrufer gerechnet, nicht von der Box: `begin` minus
Vorlauf, `end` plus Nachlauf. Im Altbestand steckt hier ein Fehler, den das neue Modul
nicht erben darf: in 19103 und 30398 wird `EndTime` auf der bereits um den Vorlauf
zurueckgesetzten `StartTime` aufgebaut, das Ende liegt dadurch nur um
`Nachlauf - Vorlauf` nach dem Sendungsende. Unauffaellig, solange beide zwei Minuten
sind. In 44702 sind die beiden Variablen zusaetzlich vertauscht.

Oeffentlich:
- `ER_Timer($id)` - Timerliste als JSON
- `ER_PlaneAufnahme($id, string $Auftrag)` - JSON mit sRef, Start, Ende, Name; liefert
  einen **Vorschlag** und schreibt nichts
- `ER_FuehreAus($id, string $Vorschlag)` - fuehrt einen Vorschlag aus, nur bei `Armed`
- `ER_LoescheTimer($id, string $SRef, int $Begin, int $Ende)` - nur bei `Armed`

Abnahme: ein Vorschlag laesst sich erzeugen, ohne dass die Box ihn sieht; bei
ausgeschaltetem Gate wird jede Ausfuehrung abgelehnt und gemeldet; ein bewusst
ueberschneidender Timer wird als Konflikt erkannt und nicht als Erfolg gemeldet; ein
gesetzter Timer erscheint in der Timerliste der Box mit den erwarteten Zeiten
einschliesslich Vor- und Nachlauf.

## 9. Scharf-Gate

Wie im Serienrecorder: `Aktiv` (Vorgabe an) und `Armed` (Vorgabe **aus**). Lesen braucht
kein Gate. Jeder Aufruf, der die Box veraendert - `timeradd`, `timerchange`,
`timerdelete`, `timertogglestatus`, `zap`, `message`, `powerstate`, `recordnow` - laeuft
nur bei `Armed` und wird in der Meldungsvariable mit Zeitstempel festgehalten.

Der Weg ist immer zweistufig: erst eine Vorschlagsliste, die man ansehen kann, dann die
Ausfuehrung. Das ist dasselbe Muster, das sich bei den Duplikaten bewaehrt hat.

Umgekehrt gilt eine **Positivliste**: das Modul kennt genau die Endpunkte, die es
aufrufen darf, und keinen anderen. Eine Verbotsliste deckt eine fremde API nie
vollstaendig ab - `recordnow` stand am 20.08. nicht darauf und wurde deshalb aufgerufen.

## 10. Fehlerbehandlung

| Fall | Erkennung | Meldung | Was NICHT passiert |
|---|---|---|---|
| Receiver aus | keine Verbindung, sofort | Variable `Erreichbar` = false | kein Fehlerstatus, kein Log |
| Netz weg | Timeout | dieselbe | keine Wiederholung |
| Box beschaeftigt | Timeout bei sonst schneller Abfrage | Ruhetakt 10 min | kein Nachfassen |
| Anmeldung falsch | HTTP 401 | Instanzstatus 201 | keine Wiederholung mit denselben Daten |
| Tuner voll | `result:false` + `conflicts` | Konflikt im Vorschlag | Timer gilt als nicht gesetzt |
| Timer-Konflikt | ebenso | ebenso | ebenso |
| Unbekannter sRef | `result:false` | Meldung mit sRef | kein Timer |

Grundsatz: ein nicht erreichbarer Receiver ist ein Betriebszustand, kein Fehler. Nur was
der Anwender richten kann - falsche Anmeldedaten, falscher Host - setzt den Instanzstatus.

## 11. Bildunterschiede

Die Vu+ meldet OWIF 1.2.8 aus dem VTi-Zweig; eine Fassung 1.2.8 gibt es bei
E2OpenPlugins nicht, die Tags springen von 1.2.5 auf 1.4.0. Der Dreambox-Zweig (DreamOS)
weicht staerker ab. Das Modul geht deshalb so vor:

1. Beim ersten Kontakt `about`/`deviceinfo` lesen und die Fassung merken.
2. Jeden Endpunkt einmal probeweise aufrufen und das Ergebnis je Instanz vermerken -
   vorhanden, fehlt, fehlerhaft.
3. Fehlende Felder fuehren zu leeren Variablen, nicht zu Fehlern.
4. Kein Endpunkt wird aufgrund der Versionsnummer vorausgesetzt.

Was die Dreambox wirklich kann, ist offen: sie war bei jeder Messung aus.

## 12. Ablösung der Altskripte

Reihenfolge, jede Stufe erst nach Tagen Parallellauf:

1. Nach Stufe 1: die fuenf reinen Abfrageskripte (12103, 36965, 39334, 49291, 51523) und
   52582 "Sender abfragen" abschalten.
2. Nach Stufe 2: 40079 und die EPG-Teile von 19103/30398 abschalten - beide Skripte sind
   bereits ohne Zeitereignis.
3. Nach Stufe 3: 44702 "Schedule Recordings" abschalten. Das ist der letzte aktive
   Schreiber; erst danach darf ER scharf geschaltet werden. **Nie beide gleichzeitig.**
4. Die 13 Config-Skripte mit `/web/message` bleiben zunaechst unangetastet; sie blenden
   Meldungen auf dem Fernseher ein und haben mit dem Modul nichts zu tun.
5. Erst ganz am Ende die Altfassungen 14317, 19075, 15621, 21979, 30734 loeschen.

## 13. Risiken

- **Ein Absender zu viel.** Laufen 44702 und ER gleichzeitig scharf, entstehen doppelte
  Timer. Deshalb Punkt 3 der Reihenfolge, und das Gate bleibt bis dahin aus.
- **Library-Reload.** Aenderungen sammeln, ein kontrollierter Neustart, danach
  gegenpruefen: `function_exists('ER_Aktualisieren')` und die Zahl der Instanzen mit
  Status >= 104. Ein fehlender Autoload-Eintrag reisst alle Libraries mit.
- **Die Box ist empfindlicher als erwartet.** Zwei Ausfaelle am 20.08. durch EPG-Abfragen.
  Punkt 5 ist deshalb Teil des Entwurfs, nicht eine Betriebsempfehlung.
- **Dreambox unbekannt.** Solange sie aus ist, ist Abschnitt 11 eine Annahme.

## 14. Offene Fragen

1. **Raumzuordnung**: eine Zeile in HomeSuites `Hub::domainGuids()` (sauber, aber
   Aenderung an HomeSuite) oder Verknuepfung in den Raum (kein fremder Code)?
2. **Dreambox und zweiter TVServer**: sollen sie ueberhaupt Instanzen bekommen, oder
   sind sie dauerhaft ausser Betrieb?
3. **Vor- und Nachlauf**: die heutigen zwei Minuten uebernehmen, oder je Receiver
   einstellbar machen?
4. **Aufnahmeverzeichnis**: `dirname` je Serie wie heute, oder je Receiver fest?
5. **Darf ich das Plugin-Verzeichnis der Box einmal per SSH lesen**, um den VTi-Zweig
   gegen 1.2.5 zu vergleichen? Reines Dateilesen, ohne Enigma2 anzufassen.
