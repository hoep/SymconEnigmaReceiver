# EnigmaReceiver

Symcon-Modul fuer Enigma2-Receiver (Vu+, Dreambox und verwandte) ueber die
OpenWebIf-Schnittstelle. Eine Instanz je Geraet.

## Stand

Alle drei Stufen gebaut:

1. **Grunddaten und Statistik** - Zustand, Modell, Image, Laufzeit, Tuner, Platten
2. **EPG und Senderlisten** - Bouquets, Sender, Programm je Sender im Zeitfenster
3. **Programmierung** - Aufnahmen anlegen, loeschen, umschalten, hinter dem Gate

Das Gate (`Scharf`) ist ab Werk **zu**. Bis es geoeffnet wird, verlaesst kein
Schreibaufruf das Modul - der Receiver erfaehrt nichts davon.

Der Plan liegt in `docs/PLAN.md`.

## Warum das Modul so vorsichtig ist

OpenWebIf laeuft im Hauptprozess von Enigma2. Eine schwere Anfrage blockiert
nicht nur die Weboberflaeche, sondern die ganze Box einschliesslich
Fernbedienung. Zwei Beobachtungen aus dem Betrieb bestimmen den Entwurf:

- Eine EPG-Abfrage ueber ein ganzes Bouquet hat eine Vu+ Ultimo 4K zweimal fuer
  rund zehn Minuten lahmgelegt. In OpenWebIf 1.2.x kennt `epgbouquet` keinen
  Zeitfenster-Parameter; die Abfrage kann nur alles liefern.
- `endTime` bei `epgservice` ist eine Dauer in **Minuten**, kein Zeitstempel.
  Ein Zeitstempel bedeutet fuer die Box eine Abfrage ueber rund 3400 Jahre.
  Die Bremse dagegen (`if endtime > 100000`) kam erst nach 1.2.x ins Projekt.

Daraus folgt: eine Anfrage zur Zeit, nach einer Zeitueberschreitung wird nicht
nachgefasst, und `OpenWebIf` kennt eine **Positivliste** erlaubter Endpunkte.
Eine Verbotsliste deckt eine fremde API nie vollstaendig ab - `/api/recordnow`
etwa startet sofort eine Aufnahme und sieht wie eine Abfrage aus.

## Befehlsreferenz

| Funktion | Wirkung |
|---|---|
| `ER_Aktualisieren($id)` | Zustand holen (Standby, Aufnahme, Sender, Sendung) |
| `ER_GeraetLesen($id)` | Geraetedaten holen (Modell, Image, Laufzeit, Tuner, Platten) |
| `ER_Status($id)` | Zustand als JSON |
| `ER_Geraet($id)` | Geraetedaten als JSON |
| `ER_Probe($id)` | Diagnose: welche Endpunkte antworten, wie schnell |
| `ER_Wecken($id)` | Ruhezeit nach einer Zeitueberschreitung vorzeitig beenden |

### Stufe 2 - Sender und Programm (lesend)

| Funktion | Wirkung |
|---|---|
| `ER_SenderLesen($id)` | Senderliste holen und ablegen |
| `ER_Sender($id)` | Bouquets und Sender als JSON (aus der Ablage) |
| `ER_FindeSender($id, $Name)` | Sendername zu Service-Referenz ("ORF 1" findet "ORF1 HD") |
| `ER_Programm($id, $SRef, $Minuten, $Start)` | Programm eines Senders im Zeitfenster |
| `ER_SucheSendung($id, $SRef, $Start, $ToleranzMinuten)` | die Sendung zu einer erwarteten Startzeit |
| `ER_Laeuft($id, $SRef, $MitNaechster)` | was jetzt laeuft und was folgt - fuer EINEN Sender |
| `ER_Uebersicht($id, $Sender, $MitNaechster, $MaxAlterSekunden)` | dasselbe fuer mehrere Sender (Fernsehseite) |
| `ER_Picon($id, $SRef)` | Adresse des Senderlogos auf der Box |

`$SRef` darf ueberall auch ein **Sendername** sein ("ORF 1"); bei mehreren gleich
geschriebenen Sendern gewinnt die HD-Fassung. `$Sender` bei `ER_Uebersicht` ist eine
Liste - Komma-getrennt, zeilenweise oder als JSON-Liste.

**Warum die Uebersicht so gebaut ist:** OpenWebIf beantwortet Anfragen im selben
Prozess, in dem Enigma2 auch das Fernsehbild macht. Deshalb `epgservicenow` /
`epgservicenext` (je 1091 und 239 Bytes, 11 und 9 ms) statt einer Bouquet-Abfrage,
hoechstens 20 Sender je Aufruf, ein Mindestabstand von 15 Sekunden zwischen zwei
echten Laeufen (dazwischen kommt die Antwort aus der Ablage) und Abbruch der
Schleife, sobald eine einzelne Abfrage ueber 1500 ms braucht.
Gemessen an einer Vu+ Ultimo 4K: 10 Sender, 20 Abfragen, **176 ms** - die
Antwortzeit der Box lag vorher wie nachher bei 9 ms.

Die Picon-Adresse wird **gerechnet, nicht erfragt** (`/api/getpicon` gibt es in
dieser Fassung nicht): aus `1:0:19:132F:3EF:1:C00000:0:0:0:` wird
`http://<box>/picon/1_0_19_132F_3EF_1_C00000_0_0_0.png`. Das Bild holt spaeter der
Browser, nicht das Modul.

`$Minuten` ist eine **Dauer in Minuten**, hoechstens 1440. Ein Zeitstempel wird
abgelehnt, ohne die Box anzufassen - siehe oben.

IP-Symcon verlangt bei Prefix-Funktionen **alle** Parameter; die Vorgabewerte in
der Signatur gelten nur fuer Aufrufe innerhalb des Moduls. Also
`ER_Programm($id, $sRef, 240, 0)` und nicht `ER_Programm($id, $sRef, 240)`.

### Stufe 3 - Aufnahmen programmieren

| Funktion | Wirkung | Gate |
|---|---|---|
| `ER_TimerLesen($id)` | Timerliste holen | lesend |
| `ER_Timer($id)` | programmierte Aufnahmen als JSON | lesend |
| `ER_PlaneAufnahme($id, $Auftrag)` | **Vorschlag** - schickt nichts an den Receiver | lesend |
| `ER_FuehreAus($id, $Vorschlag)` | den Vorschlag setzen | nur scharf |
| `ER_LoescheTimer($id, $SRef, $Begin, $Ende)` | Timer loeschen | nur scharf |
| `ER_SchalteTimer($id, $SRef, $Begin, $Ende)` | Timer ein/aus | nur scharf |
| `ER_SetzeScharf($id, $Scharf)` | Gate oeffnen oder schliessen | - |

Der Weg ist immer zweistufig: `PlaneAufnahme` liefert einen Vorschlag zum
Ansehen, `FuehreAus` schickt ihn ab.

Ein Timer hat **keine Kennung**. Seine Identitaet ist Service-Referenz + Beginn
+ Ende; Loeschen und Umschalten brauchen genau diese drei.

Der Erfolg steht **nicht im HTTP-Code**: ein abgelehnter Timer kommt mit
HTTP 200 und `"result": false`, bei Ueberschneidung zusaetzlich mit einer
`conflicts`-Liste. Das Modul wertet `result` und `conflicts` aus.

## Variablen

`Erreichbar`, `Standby`, `Aufnahme`, `Sender`, `Sendung`, `TunerFrei`,
`TunerGesamt`, `Modell`, `Image`, `Laufzeit`, `Meldung`.

`PlatteFrei` und `PlatteBelegt` entstehen nur, wenn der Receiver wirklich eine
Platte meldet. Eine Vu+, die auf eine Netzfreigabe aufnimmt, meldet `hdd` als
leere Liste.

## Tests

    php tests/GeraetTest.php
    php tests/ProgrammTest.php

Laufen ohne Symcon-Kernel gegen echte Antworten einer Vu+ Ultimo 4K
(VTi 15.0.02, OWIF 1.2.8) unter `tests/daten/`.
