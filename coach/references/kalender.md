# Geplante Workouts verwalten

Diese Regeln gelten für die Kalender-Schreibtools. Wissenschaftliche Planung und Erstaufnahme bleiben in den anderen Skill-Dateien geregelt.

## Verfügbare Funktionen

| Tool | Zweck |
| --- | --- |
| `get_calendar` | Datum, Namen und IDs vorhandener Ereignisse finden |
| `get_calendar_event(event_id)` | Vollständigerer Eintrag mit aktuellem `revision`-Wert |
| `create_calendar_workout(workout)` | Genau ein neues geplantes Workout anlegen |
| `create_calendar_race(race)` | Genau einen neuen Wettkampf mit Priorität A/B/C anlegen |
| `update_calendar_workout(event_id, expected_revision, changes)` | Bestehendes Workout gezielt ändern oder verschieben |
| `delete_calendar_workout(event_id, expected_revision)` | Ein bestimmtes geplantes Workout ausdrücklich löschen |

Event-IDs sind keine Aktivitäts-IDs. Kein Tool nimmt eine beliebige Athlete-ID entgegen: Die Verbindung bestimmt das Konto. Der Server lässt Bearbeitung/Löschung nur an WORKOUT-Ereignissen des eigenen Kontos ab heute zu. Neue Wettkämpfe werden mit dem separaten Anlagetool als RACE_A/RACE_B/RACE_C gespeichert. Keine bestehenden Wettkämpfe oder Notizen in WORKOUT umwandeln, um die Einschränkung zu umgehen.

## Auftrag und Zuordnung

Ein Vorschlag oder Wochenreview wird nicht automatisch gespeichert. Eine klare Aufforderung zum Eintragen, Ändern oder Verschieben autorisiert die konkret benannten Schritte. Nicht erneut nach Zustimmung zu jedem bereits eindeutig beauftragten Schritt fragen. Nur bei fehlender/mehrdeutiger Zuordnung, unerwarteten Konflikten oder unklarem Umfang nachfragen. Ein Auftrag zum Anpassen einer Woche bedeutet nicht, sämtliche bestehenden Kalendereinträge zu löschen.

Vor jeder Änderung im aktuellen Projekt das Athletenkonto prüfen. Bei mehreren Verbindungen das beabsichtigte Konto eindeutig identifizieren. Eine natürlichsprachliche Projektanweisung ist keine serverseitige Kontosperre; keine garantierte Projektisolation behaupten.

## Bestehende Einheit ändern

1. Kalender für den betroffenen Zeitraum lesen und die Einheit eindeutig anhand Datum, Sportart und Namen identifizieren.
2. `get_calendar_event` mit ihrer ID abrufen. Zuordnung, Datum, Kategorie und Sperren prüfen.
3. Nur die gewünschten Felder in `changes` übergeben; `expected_revision` exakt aus der Antwort übernehmen. Nicht ungefragt Intervalle, Notizen, Titel oder Belastung ersetzen.
4. Für eine Terminverschiebung `start_date_local` in der Athleten-Zeitzone setzen. Das Servertool verschiebt eine vorhandene Endzeit mit. Bei mehreren Einheiten Kollisionen und Zeitbudget prüfen.
5. Zurückgelieferten gespeicherten Eintrag mit dem Auftrag abgleichen. Bei Konflikt den Eintrag neu lesen; keine alte Revision wiederverwenden oder fremde Änderungen überschreiben.

Der Revisionsvergleich erfolgt vor dem API-Schreibaufruf. Er ersetzt keine atomare Transaktion über die fremde API; bei parallelen manuellen Änderungen muss der Nutzer den finalen Stand kontrollieren können.

## Neue Einheit anlegen

Das `workout`-Objekt benötigt `name`, `type`, `start_date_local` und `description`. Zusätzlich sind `moving_time` in Sekunden, `distance` in Metern und `icu_training_load` erlaubt. Datumsformat: `YYYY-MM-DDTHH:MM:SS`, ohne Z oder Offset. „Morgen“ relativ zur Athleten-Zeitzone eindeutig in ein Datum übersetzen.

Für strukturierte Workouts die native intervals.icu-Beschreibung verwenden, soweit deren Syntax bekannt oder anhand offizieller Dokumentation überprüft ist. Nicht behaupten, dass unstrukturierter Freitext bereits ein gerätesynchronisierbares Intervallprogramm ist. Keine erfundenen Watt-/Pace-Ziele bei fehlender Datengrundlage; Trainingszonen konsistent angeben. Die zurückgelieferte `workout_doc` und Dauer auf Plausibilität prüfen, wenn vorhanden.

Vor dem Eintragen einer Woche prüfen, ob die Einheiten bereits existieren. POST-Anlagen sind nicht automatisch wiederholbar: Bei unklarer Antwort zuerst den Kalender nach der geplanten Einheit durchsuchen. Doppelte Einträge nicht vorsorglich erzeugen.

## Löschen und Ergebnisse

Zum Anlegen eines Wettkampfs `race` mit `name`, `type`, `start_date_local` und `priority` (A/B/C) übergeben. `description`, `distance` in Metern, erwartete `moving_time` in Sekunden und `icu_training_load` sind optional; keine unbekannten Werte erfinden. Namen, Datum und Priorität vorab mit dem Kalender/Profil abgleichen. Die Kategorie bildet der Server aus der Priorität. Eine Neuanlage ist kein Auftrag zur Bearbeitung oder Löschung vorhandener Rennen. Es gelten dieselben Schreibrechte und Regeln bei unklarem Ergebnis wie für Workouts.

Nur eindeutig benannte und ausdrücklich zum Löschen beauftragte Workouts entfernen. Das Tool löscht keine Serien; nicht mit wiederholten Einzelaufrufen ungefragt einen ganzen Plan leeren. Fehlgeschlagene Änderungen nicht durch Löschen und Neuanlage umgehen.

Nach Ausführung knapp berichten: Athlet, Datum, Aktion, Einheit, Event-ID und ob das Ergebnis verifiziert wurde. Bei einer Woche Teilfortschritt je Einheit festhalten. Fehlgeschlagene oder unklare Aufrufe nicht als Erfolg darstellen und erfolgreich gespeicherte Einheiten nicht erneut anlegen.

Bei fehlendem Schreibtool oder Berechtigungsfehler keine Umgehung über Webaufrufe mit einem Key versuchen. Ein Lesezugang genügt nicht zum Schreiben; der Betreiber muss die Schnittstelle aktivieren und bei OAuth muss der Nutzer `coach:calendar:write` freigeben.
