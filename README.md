# intervals.icu Coach auf World4You

MCP-Server in reinem PHP für einen persönlichen ChatGPT-Coach, mit standardmäßig lesendem Zugriff und optionaler Bearbeitung geplanter Workouts:
Garmin → intervals.icu → PHP auf World4You → ChatGPT-Plugin.
Keine Composer-Abhängigkeiten, keine Datenbank, keine Hintergrundprozesse.

Die folgende Anleitung beschreibt den persönlichen Coach mit einem fest konfigurierten Konto. Die separate [Mehrbenutzer-Version mit API-Key-Maske und MySQL](docs/multi-user.md) liegt unter `server/multi/`; sie muss zusätzlich eingerichtet und auf dem Webspace abgenommen werden.

## 1. Zugangsdaten und Webspace vorbereiten

1. In intervals.icu unter **Settings → Developer Settings** einen API-Key erstellen. Die Athlete-ID (z. B. `i12345`) steht in der Profil-URL. Die tatsächliche ID verwenden, nicht den API-Alias `0`: Der Server prüft bei Aktivitätsdetails die Zugehörigkeit.
2. Im World4You-Kundenbereich ein Let's-Encrypt-Zertifikat für die Domain aktivieren und HTTPS prüfen. PHP mindestens 8.1 mit `curl` und `json` wählen; bevorzugt eine aktuell unterstützte PHP-Version. Ausgehende HTTPS-Verbindungen zu `intervals.icu` müssen erlaubt sein.
3. `server/config.example.php` nach `server/config.php` kopieren und API-Key sowie Athlete-ID eintragen. Die Datei wird von Git ignoriert.
4. Einen Token erzeugen:

   ```powershell
   node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
   ```

   Ausgabe als `MCP_TOKEN` eintragen. Erlaubt sind URL-sichere Zeichen `A–Z`, `a–z`, `0–9`, `_`, `-`, mindestens 32 Zeichen. Der generierte Token hat 64 Hex-Zeichen. `COACH_TIMEZONE` ist standardmäßig `Europe/Vienna` und sollte zur Zeitzone des Athleten passen.
5. Mit FileZilla den **Inhalt von `server/`**, einschließlich beider `.htaccess`-Dateien und `lib/`, in den Webordner `/fit/` hochladen. Zugangsdaten per verschlüsseltem FTP übertragen, sofern verfügbar. Nur die Serverdateien hochladen; Repo, Tests und Skill gehören nicht in den Webordner.

Die MCP-URL lautet dann:

```text
https://<domain>/fit/mcp/<TOKEN>
```

Ohne `mod_rewrite` funktioniert `https://<domain>/fit/mcp.php?token=<TOKEN>`.
Apache muss `.htaccess` einschließlich `Require all denied` auswerten; der Fallback ersetzt diesen Dateischutz nicht. `lib/.htaccess` sperrt das Verzeichnis auch ohne Rewrite.

## 2. Webspace prüfen

Node 22 genügt, es ist kein `npm install` nötig:

```powershell
node test/smoke.mjs 'https://<domain>/fit/mcp/<TOKEN>'
```

Alternativ die URL über die Umgebungsvariable `MCP_URL` übergeben und `node test/smoke.mjs` starten, damit sie nicht als Kommandozeilenargument erscheint. Der Test gibt weder URL noch Trainingsdaten aus. Shell-Verlauf und die Verwaltung der Umgebungsvariable bleiben deine Verantwortung.

Der Test prüft Initialisierung, Benachrichtigung (202 ohne Body), Ping, die Tool-Liste samt Schreibannotationen, die fünf bisherigen Datenabrufe, Datumsfehler, falschen Token (404), GET (405), fremden Origin (403) sowie Dateischutz (403). Er führt keine Schreibaktionen aus. Für `get_activity` nimmt er eine Aktivität der letzten 14 Tage. Falls diese Liste leer ist:

```powershell
node test/smoke.mjs 'https://<domain>/fit/mcp/<TOKEN>' iDEINE_AKTIVITAETS_ID
```

Leere Wellness- oder Kalenderlisten sind gültig und werden gemeldet. Inhaltlich prüfen, ob tatsächlich Daten vorhanden sein sollten. Ein erfolgreicher Smoke-Test prüft die Verbindung und Struktur; die Werte zusätzlich mit intervals.icu vergleichen.

Optional: `npx @modelcontextprotocol/inspector` starten, im Inspector **Streamable HTTP** wählen und die MCP-URL angeben. Den dort verwendeten Browser-Origin bei Bedarf ausdrücklich in `MCP_ALLOWED_ORIGINS` aufnehmen; keine pauschale Freigabe verwenden.

## 3. ChatGPT verbinden

Geprüft anhand der offiziellen OpenAI-Dokumentation am **21.10.2026**. Oberfläche und Berechtigungen können je nach Konto oder Workspace abweichen. Der aktuelle [Plugin-Quickstart](https://developers.openai.com/plugins/quickstart) beschreibt:

1. [ChatGPT Plugins](https://chatgpt.com/plugins) öffnen, **Plus → Add custom MCP server** wählen.
2. Name „Coach“, die vollständige HTTPS-MCP-URL und **No authentication** eintragen. Der URL-Token übernimmt hier die Zugangskontrolle.
3. Den angezeigten Hinweis prüfen und mit **Create as a plugin** anlegen.
4. Plugin installieren, einen neuen Chat in **Work** öffnen und über `@` auswählen.

Fehlt im Plus-Menü die MCP-Option und werden nur „Create plugin“ und „Upload plugin“ angezeigt, in der Weboberfläche unter **Einstellungen → Sicherheit und Anmeldung** den **Entwicklermodus** prüfen und gegebenenfalls aktivieren. In der hier verwendeten Kontokonfiguration wurde dadurch das Hinzufügen einer App zum Coach verfügbar. Nach dem Hinzufügen die App ausdrücklich verbinden; die bloße Installation des Skills stellt noch keine Tools bereit.

Für die Coach-Anweisungen `coach/SKILL.md` um persönliche Ziele, Sportarten und Verfügbarkeit ergänzen. Der reine MCP-Verbindungsdialog übernimmt diesen Skill nicht automatisch. Laut [offizieller Anleitung zum Paketieren](https://developers.openai.com/plugins/build/plugins) lässt sich die registrierte Verbindung mit einem Skill bündeln:

1. Nach dem Verbinden die technische ID `plugin_asdk_app...` aus der Browser-URL kopieren.
2. In ChatGPT Work `@plugin-creator` aufrufen, `coach/SKILL.md` beilegen und beauftragen: „Erstelle ein privates Plugin Coach mit der registrierten MCP-Verbindung plugin_asdk_app… und diesem Skill. Bündle den Skill unter skills/coach/SKILL.md.“ Dabei keine MCP-URL oder API-Keys in veröffentlichbare Paketdateien übernehmen.
3. Das erzeugte private Plugin installieren und in einem neuen Chat testen. Die Verbindung muss auf deine registrierte App zeigen und der Skill enthalten sein. Dieses Repo liefert den Server und den Skill; Registrierung und das kontospezifische Plugin-Paket entstehen erst in diesem Schritt.

Die Umsetzung setzt kein bestimmtes Abschaltdatum von Custom GPTs voraus; das im Plan genannte Datum wurde hier nicht unabhängig bestätigt.

Testfrage: „Was habe ich die letzten sieben Tage trainiert und wie ist meine Form?“ Sichtbare Tool-Aufrufe und Werte mit intervals.icu vergleichen. Danach „Wochenreview“ oder einen Trainingsplan anfordern und prüfen, ob Wellness, Aktivitäten und Kalender abgerufen werden.

## Tools und Werte

Lese-Tools sind mit `readOnlyHint: true` markiert. Die vier Schreibtools sind ausdrücklich als schreibend gekennzeichnet und benötigen eine zusätzliche Freigabe. Sie verwalten einzelne geplante WORKOUT-Kalendereinträge und können zusätzlich Wettkämpfe mit A-/B-/C-Priorität anlegen; Aktivitäten, Wellness und Einstellungen werden nicht geändert. Einrichtung: [Kalender bearbeiten](docs/calendar-write.md).

| Tool | Inhalt | Standardzeitraum |
| --- | --- | --- |
| `get_athlete_profile` | Gewicht, Ruhepuls, sportartspezifische Schwellen und Zonen | – |
| `list_activities` | Gekürzte Trainingsdaten mit ID für Detailabruf | Heute und vorherige 13 Tage |
| `get_activity(activity_id)` | Gekürzte Aktivitätsdetails und Intervalle; nur eigener Athlet | – |
| `get_wellness` | CTL, ATL, absolute Form, HRV, Schlaf, Gewicht, Befinden | Heute und vorherige 13 Tage |
| `get_calendar` | Geplante Workouts, Wettkämpfe, Notizen | Heute bis heute +14 Tage |
| `get_calendar_event(event_id)` | Ein Kalenderereignis samt Revision zum Bearbeiten | – |
| `create_calendar_workout(workout)` | Ein geplantes Workout anlegen, wenn freigegeben | Ab heute |
| `create_calendar_race(race)` | Einen Wettkampf mit A-/B-/C-Priorität anlegen | Ab heute |
| `update_calendar_workout(event_id, expected_revision, changes)` | Ein Workout ändern oder verschieben | Ab heute |
| `delete_calendar_workout(event_id, expected_revision)` | Ein ausdrücklich beauftragtes Workout löschen | Ab heute |

Die drei Listen akzeptieren optionale `oldest`/`newest` im Format `YYYY-MM-DD`, maximal **90 Kalendertage einschließlich beider Grenzen**. Ausgelassene Grenzen behalten ihren jeweiligen Standardwert; für andere Zeiträume am besten beide angeben. Aktivitäten enthalten den gesamten letzten Tag.

Dauer und Schlaf: Sekunden; Distanz: Meter; Geschwindigkeit: m/s; Leistung: Watt; Herzfrequenz: bpm. `icu_intensity` kommt in Prozent, `intensity_factor` wird durch Division durch 100 berechnet. `average_pace_seconds_per_km` wird aus der Geschwindigkeit berechnet. `form_absolute = ctl - atl` kann von einer prozentualen Formanzeige in intervals.icu abweichen. Fehlende Werte bleiben fehlend.

## Protokoll und Betrieb

Stateless Streamable HTTP mit JSON-RPC 2.0 und `application/json`, ohne SSE oder Session-ID. Unterstützte Versionen: `2025-11-25`, `2025-06-18`, `2025-03-26`. Methoden: `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`. Unbekannte Methoden: `-32601`; ungültige Parameter: `-32602`. Fehler der intervals.icu-API werden als Tool-Ergebnis mit `isError: true` geliefert.

Benachrichtigungen erhalten 202 ohne Body. GET und andere HTTP-Methoden erhalten 405. Token-Prüfung erfolgt vor dem Methodencheck; ein falscher Token ergibt auch bei GET 404. Unterstützte Batch-Arrays (1–20 Einträge) sind eine Kompatibilitätserweiterung; aktuelle MCP-Clients senden einzelne Nachrichten. Maximaler Request-Body: 1 MiB. Der curl-Timeout ist 20 Sekunden je API-Aufruf; Aktivitätsdetails brauchen zwei Aufrufe.

Requests ohne Origin sind für Server-zu-Server-Aufrufe erlaubt. Wenn ein Origin vorhanden ist, muss er exakt auf der konfigurierten Liste stehen. HTTPS muss durch das Hosting bereitgestellt werden. Antworten werden mit `Cache-Control: no-store` versendet. Der Tokenvergleich erfolgt mit `hash_equals`.

**Wer die persönliche URL kennt, kann Daten lesen und bei aktiviertem Schreibzugriff geplante Workouts ändern.** Die URL nicht teilen oder in Git, Screenshots und öffentliche Plugins übernehmen. Hosting-Access-Logs können Pfad und Query inklusive Token enthalten. Bei Offenlegung `MCP_TOKEN` ändern und die Verbindung in ChatGPT aktualisieren. Der PHP-Server begrenzt den API-Key auf die hier implementierten Funktionen.

ChatGPT startet kein Gespräch von selbst. Reviews manuell anstoßen. Eine Scheduled Task kann gegebenenfalls erinnern; einen automatischen Zugriff der Task auf dieses Plugin nicht voraussetzen.

Bei HTTP 500 zuerst PHP-Version, curl und die Syntax von `config.php` prüfen. Falls Apache bereits vor PHP 500 liefert, das Hosting-Error-Log auf nicht erlaubte `.htaccess`-Direktiven prüfen. Dateisperren nicht entfernen; vor Verwendung müssen die Schutzprüfungen erfolgreich sein.

API-Referenz: [intervals.icu OpenAPI](https://intervals.icu/api/v1/docs), [API-Zugang](https://forum.intervals.icu/t/api-access-to-intervals-icu/609). Protokoll: [MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).

## Lokale Entwicklung (optional)

Mit einer vorhandenen PHP-Laufzeit lassen sich Handler mit Fixtures und der HTTP-Transport ohne Zugangsdaten prüfen:

```powershell
node test/local.mjs C:/Pfad/zu/php.exe
```

Die PHP-Laufzeit muss curl laden. Bei einer portablen Windows-Version gegebenenfalls `-d extension_dir=C:/Pfad/zu/ext -d extension=curl` anhängen. Der Test verwendet eine temporäre Kopie und eine fiktive Konfiguration, startet PHP ausschließlich auf `127.0.0.1` und beendet den Prozess anschließend. Er prüft keine Apache-Regeln und keine echte intervals.icu-Verbindung. Produktionsdateien und die echte `config.php` werden nicht verändert.
