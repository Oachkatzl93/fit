# Geplante Workouts bearbeiten und Wettkämpfe anlegen

Stand 31.10.2026. Implementiert und mit lokalen Testdaten geprüft; echter Webspace und App-Toolscan müssen nach dem Upload getestet werden. Die bestehende Verbindung wird durch ein Skill-Update allein nicht schreibfähig.

## Persönlicher Coach mit URL-Token

Diese Variante passt zur ursprünglichen Verbindung `https://<domain>/fit/mcp/<TOKEN>` bzw. `mcp.php?token=…` mit **No authentication**.

1. Diese aktuellen Dateien per FTP nach `/fit/lib/` hochladen:

   ```text
   server/lib/intervals.php
   server/lib/tools.php
   server/lib/rpc.php
   server/lib/calendar.php   (neu)
   ```

   Zusätzlich die aktuelle `server/mcp.php` nach `/fit/mcp.php` hochladen. Eigene Konfiguration, API-Key und Token erhalten.
2. In der bestehenden `/fit/config.php` folgende Konstante ergänzen bzw. auf `true` setzen:

   ```php
   define('ENABLE_CALENDAR_WRITES', true);
   ```

   Sie muss innerhalb des PHP-Blocks stehen und darf nur einmal definiert sein. Ohne die Konstante oder bei `false` sind Schreibaufrufe gesperrt.
3. In ChatGPT die bestehende MCP-App öffnen und **Refresh** wählen, damit die neue Tool-Liste übernommen wird. Den bestehenden Endpoint und die Verbindung beibehalten. Falls die Oberfläche abweicht, die App-Detailansicht prüfen. Eine neue Verbindung ist für den persönlichen URL-Token nicht grundsätzlich erforderlich.
4. Neuen Coach-Chat starten und nach den verfügbaren Tools fragen. Erwartet werden zehn Tools, darunter die fünf Kalenderfunktionen unten.

Wer die persönliche MCP-URL kennt, besitzt bei aktivierter Freigabe auch diesen Kalender-Schreibzugriff. Für andere Nutzer die OAuth-Version mit getrennten Konten verwenden.

## Mehrbenutzer-Version mit OAuth

Zusätzlich zu den gemeinsamen Bibliotheken diese Dateien aktualisieren:

```text
server/multi/lib/auth.php
server/multi/mcp.php
server/multi/metadata.php
server/multi/authorize.php
```

In `/fit/multi/config.php` ergänzen:

```php
'enable_calendar_writes' => true,
```

In der OAuth-App die Scopes auf `coach:read coach:calendar:write offline_access` erweitern, Tools neu einlesen und **neu verbinden**. Der Nutzer muss in der Anmeldemaske den Zugriff einschließlich Kalenderbearbeitung bestätigen. Bestehende read-only Access-/Refresh-Token erhalten durch das Serverupdate keine Schreibrechte. Kein Datenbank-Schemawechsel ist nötig. Bei `enable_calendar_writes=false` sind Schreibaufrufe auch mit einem früher erteilten Schreibscope gesperrt.

## Neue Funktionen

| Tool | Wirkung |
| --- | --- |
| `get_calendar_event` | Eigenen Eintrag und aktuelle `revision` lesen |
| `create_calendar_workout` | Einzelnes neues Workout anlegen |
| `create_calendar_race` | Wettkampf als RACE_A, RACE_B oder RACE_C anlegen |
| `update_calendar_workout` | Einzelnes Workout ändern oder verschieben |
| `delete_calendar_workout` | Einzelnes Workout löschen |

Zum Finden vorhandener IDs zuerst `get_calendar` verwenden. Event-IDs sind keine IDs abgeschlossener Aktivitäten. Bei Änderungen und Löschungen muss der aktuelle Eintrag frisch abgerufen werden; dessen `revision` wird als `expected_revision` übergeben. Der Server prüft den Stand vor dem Schreiben und verweigert erkannte Konflikte. Die intervals.icu-API bietet hier keine von uns verwendete atomare Transaktion über Lesen und Schreiben; eine gleichzeitige manuelle Änderung zwischen beiden Aufrufen bleibt möglich.

Die Schnittstelle bearbeitet/löscht nur WORKOUT-Ereignisse des verbundenen Athleten mit Datum ab heute in dessen Zeitzone. Das separate Tool `create_calendar_race` kann zusätzlich neue Wettkämpfe anlegen: `race` benötigt `name`, `type`, `start_date_local` und `priority` als `A`, `B` oder `C`. `description`, `distance` in Metern und erwartete `moving_time` in Sekunden sind optional. A bezeichnet den Hauptwettkampf, B einen wichtigen Vorbereitungswettkampf, C einen nachrangigen/Testwettkampf. Unbekannte Priorität oder Startzeit vorher klären.

Vorhandene Wettkämpfe, Notizen, vergangene Ereignisse und gesperrte Workouts werden nicht bearbeitet. Es gibt keine Massen-/Serienlöschung; beim Löschen wird `others=false` erzwungen. Beim Anlegen ist `upsertOnUid=false`, sodass nicht versehentlich ein bestehendes Ereignis anhand einer UID überschrieben wird.

Wer das vorherige Schreibupdate bereits hochgeladen hat, muss für die Wettkampf-Erweiterung `server/lib/calendar.php` ersetzen und die App erneut mit **Refresh** aktualisieren. Für die Mehrbenutzer-Version zusätzlich `server/multi/authorize.php` hochladen, damit die Zustimmungsmaske auch die Wettkampf-Anlage nennt. Freigabeschalter und OAuth-Schreibscope bleiben dieselben.

Erlaubte Workout-Felder: `name`, `type`, `start_date_local`, `description`, `moving_time` (Sekunden), `distance` (Meter), `icu_training_load`. Anlage benötigt mindestens Name, Sportart, lokalen Zeitstempel und Beschreibung. Zeitstempel haben das Format `YYYY-MM-DDTHH:MM:SS`, ohne Z oder Offset. Beim Verschieben einer Einheit wird eine vorhandene Endzeit mitverschoben. Update sendet nur die beauftragten Felder.

`description` kann native intervals.icu-Workout-Syntax enthalten; Freitext ist nicht automatisch ein strukturiertes Intervalltraining. Der Coach soll die gespeicherte Beschreibung/Struktur und Dauer prüfen. Nach erfolgreichem Schreiben wird der gespeicherte Eintrag erneut abgerufen; eine Löschung wird durch einen nachfolgenden 404-Abruf geprüft. Ein Timeout kann trotzdem nach einer erfolgreichen Mutation auftreten. In diesem Fall zuerst Kalender prüfen, nicht ungeprüft erneut anlegen.

## Testen

Lokale Tests schreiben ausschließlich in Fixtures:

```powershell
php test/calendar.php
node test/local.mjs C:/Pfad/php.exe
node test/multi-local.mjs C:/Pfad/php.exe
```

Für die letzten beiden Befehle bei portabler PHP-Laufzeit die bisher beschriebenen Erweiterungsparameter anhängen. Geprüft werden Anlage, Teiländerung, Verschiebung samt Endzeit, Revision-Konflikt, fremde Konten, gesperrte und vergangene Einträge, Eingabevalidierung, Leserechte und verifiziertes Löschen. Die OAuth-Tests prüfen zusätzlich den getrennten Schreibscope.

Der normale Smoke-Test bleibt ohne Schreibaktionen. Für eine echte Abnahme nach dem Upload ausdrücklich einen temporären Test-Workout an einem freien zukünftigen Tag anlegen lassen, ihn verändern/verschieben und diesen Testeintrag anschließend ausdrücklich löschen lassen. In intervals.icu gegenprüfen. Mit einer Mehrbenutzer-Leseverbindung müssen dieselben Schreibversuche scheitern. Keine bestehenden echten Trainings zum Testen löschen.

Quellen: [intervals.icu OpenAPI](https://intervals.icu/api/v1/docs), [OpenAI: Verbindung und Toolscan](https://developers.openai.com/plugins/deploy/connect-chatgpt).
