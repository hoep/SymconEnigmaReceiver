# EnigmaReceiver

Symcon-Modul fuer Enigma2-Receiver (Vu+, Dreambox und verwandte) ueber die
OpenWebIf-Schnittstelle. Eine Instanz je Geraet.

## Stand

**Stufe 1: Grunddaten und Statistik.** Rein lesend.

Geplant: Stufe 2 EPG und Senderlisten, Stufe 3 Programmierung von Aufnahmen.
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

Alle Funktionen sind lesend.

## Variablen

`Erreichbar`, `Standby`, `Aufnahme`, `Sender`, `Sendung`, `TunerFrei`,
`TunerGesamt`, `Modell`, `Image`, `Laufzeit`, `Meldung`.

`PlatteFrei` und `PlatteBelegt` entstehen nur, wenn der Receiver wirklich eine
Platte meldet. Eine Vu+, die auf eine Netzfreigabe aufnimmt, meldet `hdd` als
leere Liste.

## Tests

    php tests/GeraetTest.php

Laeuft ohne Symcon-Kernel gegen echte Antworten einer Vu+ Ultimo 4K
(VTi 15.0.02, OWIF 1.2.8) unter `tests/daten/`.
