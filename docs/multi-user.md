# Mehrere Nutzer mit eigenen API-Keys

Die separate Mehrbenutzer-Version liegt unter `server/multi/`. Sie ist lokal mit zwei Testkonten geprüft, aber noch nicht auf World4You oder mit echten ChatGPT-Verbindungen abgenommen. Die bestehende Einzelbenutzer-Konfiguration bleibt erhalten.

## Nutzerablauf

1. Nutzer installieren den freigegebenen Coach und klicken auf **Verbinden**.
2. ChatGPT öffnet eine Anmeldemaske auf deinem HTTPS-Server.
3. Der Nutzer gibt seinen intervals.icu-API-Key ein und stimmt der Speicherung sowie den angeforderten Rechten zu. Kalenderänderungen benötigen eine gesondert angeforderte Schreibberechtigung.
4. PHP fragt mit diesem Key `/athlete/0` ab. Die zurückgegebene Athlete-ID muss in deiner Freigabeliste stehen.
5. Der Key wird mit AES-256-GCM verschlüsselt gespeichert und an die bestätigte Athlete-ID gebunden. ChatGPT erhält einen eigenen kurzlebigen Coach-Zugang, keinen intervals.icu-Key.
6. Alle Tools verwenden ausschließlich das bestätigte Konto. Datumsdefaults nutzen dessen Zeitzone.

```text
ChatGPT ── OAuth + PKCE ── PHP auf World4You ── persönlicher API-Key ── intervals.icu
```

Es ist **keine OAuth-App bei intervals.icu und keine Genehmigung dort erforderlich**. OAuth zwischen ChatGPT und deinem PHP-Server ordnet die gemeinsame App den jeweiligen Nutzern zu. Diese Version verwendet einen fest konfigurierten ChatGPT-OAuth-Client mit Client-ID und Secret; keine dynamische Client-Registrierung.

Ein intervals.icu-API-Key kann weitergehende Rechte besitzen. Unser Client liest Trainingsdaten und kann optional einzelne geplante Workouts verwalten. Schreibrechte erfordern Server-Freigabe und `coach:calendar:write` im jeweiligen OAuth-Zugang; alte Lesezugänge bleiben lesend. Siehe [Kalender-Schreibzugriff](calendar-write.md). Der Betreiber kann die gespeicherten Keys technisch entschlüsseln. Keys gehören ausschließlich in die HTTPS-Maske, nicht in Chat-Nachrichten.

## 1. Datenbank und PHP

Benötigt werden PHP ≥ 8.1 mit `curl`, `json`, `openssl`, `PDO` und `pdo_mysql`, PHP-Sessions sowie MySQL/MariaDB mit InnoDB. PHP muss HTTPS über `HTTPS=on` oder Port 443 erkennen; frei gesetzten Forwarded-Headern vertraut der Code nicht.

1. In World4You eine Datenbank samt Benutzer anlegen.
2. In phpMyAdmin diese Datenbank auswählen und `server/multi/schema.sql` importieren. Das Schema legt Tabellen mit Präfix `coach_` an. Bei bereits vorhandenen Tabellen nicht erneut blind importieren.
3. Für den Laufzeitbenutzer genügen SELECT, INSERT, UPDATE und DELETE auf diesen Tabellen. DDL-Rechte werden nach dem Import nicht benötigt.

## 2. Konfiguration

`server/multi/config.example.php` nach `server/multi/config.php` kopieren. Die echte Konfiguration ist in Git ignoriert und durch Apache gesperrt.

| Einstellung | Wert |
| --- | --- |
| `base_url` | `https://deine-domain.at/fit/multi` ohne Slash am Ende |
| `db_dsn` | MySQL-Host und Datenbank aus World4You |
| `db_user`, `db_password` | Datenbank-Zugang |
| `encryption_key` | 32 zufällige Bytes als 64 Hex-Zeichen |
| `client_id` | z. B. `coach-chatgpt` |
| `client_secret` | Eigener zufälliger Wert mit mindestens 32 Zeichen |
| `redirect_uris` | Exakte OAuth-Callback-Adresse(n) aus ChatGPT; keine Wildcards |
| `allowed_athletes` | Freigeschaltete IDs, z. B. `['i12345', 'i67890']` |
| `privacy_url` | Optional deine HTTPS-Seite mit Betreiber-/Datenschutzinformationen |

Den folgenden Befehl **zweimal separat** ausführen, einmal für `encryption_key` und einmal für `client_secret`:

```powershell
node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
```

Den Verschlüsselungsschlüssel getrennt von der Datenbank sichern. Ein Verlust oder Wechsel macht bestehende verschlüsselte Keys unlesbar; die Nutzer müssen dann neu verbinden. Beim Wechsel von Client-ID, Secret oder Basisadresse ebenfalls die ChatGPT-Verbindung und gespeicherte Zugänge berücksichtigen.

Nutzer finden ihre Athlete-ID in ihrer Profil-URL. Die Maske prüft diese serverseitig gegen die mit dem Key bestätigte Identität. `0` darf nicht in die Freigabeliste.

## 3. Upload

Den aktuellen Inhalt von `server/` nach `/fit/` hochladen. Bestehende **`/fit/config.php` nicht überschreiben**. Die neue Konfiguration gehört zusätzlich nach `/fit/multi/config.php`.

```text
/fit/
├── .htaccess
├── config.php                 bestehende persönliche Konfiguration
├── mcp.php                    aktualisiert
├── lib/
│   ├── .htaccess
│   ├── intervals.php          aktualisiert
│   ├── tools.php              aktualisiert
│   └── rpc.php                neu; auch für den bisherigen Endpoint erforderlich
└── multi/
    ├── .htaccess
    ├── config.php             neue Mehrbenutzer-Konfiguration
    ├── authorize.php          API-Key- und Zustimmungsmaske
    ├── disconnect.php         gespeicherten Key und Zugänge entfernen
    ├── metadata.php
    ├── mcp.php
    ├── token.php
    ├── revoke.php
    └── lib/
        ├── .htaccess
        ├── auth.php
        ├── bootstrap.php
        └── admin.php
```

`schema.sql` und `config.example.php` werden für den Betrieb nicht benötigt; falls hochgeladen, sperrt `.htaccess` sie. Alle `.htaccess`-Dateien mit übertragen.

## 4. OAuth-Discovery im Domain-Hauptverzeichnis

Bei einem Issuer mit Pfad sucht OAuth die Server-Metadaten im **Hauptverzeichnis der Domain**. Die folgenden Regeln in die dortige `.htaccess` aufnehmen, vor allgemeinen CMS-/WordPress-Weiterleitungen. Die bestehende Datei ergänzen, nicht ersetzen:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^\.well-known/oauth-authorization-server/fit/multi$ /fit/multi/metadata.php [END]
    RewriteRule ^\.well-known/oauth-protected-resource/fit/multi/mcp\.php$ /fit/multi/metadata.php?kind=resource [END]
</IfModule>
```

Bei anderem Upload-Pfad beide Seiten der Regeln anpassen. Die kanonische MCP-Adresse ist **`/fit/multi/mcp.php`**; sie muss exakt zum `resource`-Wert passen. Keinen persönlichen URL-Token anhängen. Der optionale Rewrite-Alias `/mcp` ist nicht die OAuth-Resource.

Diese Adressen müssen JSON liefern:

```text
https://deine-domain.at/.well-known/oauth-authorization-server/fit/multi
https://deine-domain.at/fit/multi/metadata.php?kind=resource
```

Der MCP-Endpoint liefert ohne Anmeldung auf POST 401 samt Ressourcen-Metadaten-Verweis. GET liefert 405.

## 5. Neue ChatGPT-App

Für die Abnahme eine **separate OAuth-App** anlegen. Den bestehenden persönlichen Coach zunächst nicht teilen: Sein Paket enthält noch deine persönliche URL und die alte App-Verknüpfung.

1. ChatGPT im Browser öffnen und den Entwicklermodus aktivieren, falls die App-Erstellung fehlt.
2. Neue App mit Server-URL `https://deine-domain.at/fit/multi/mcp.php` anlegen, Authentifizierung **OAuth** wählen.
3. Unter den erweiterten OAuth-Einstellungen den statischen/vorgegebenen Client verwenden: Client-ID und Secret aus `multi/config.php` eintragen. Nicht CIMD oder dynamische Registrierung wählen.
4. Die von ChatGPT angezeigte exakte Redirect-/Callback-Adresse in `redirect_uris` übernehmen und die Konfiguration hochladen. Falls die Adresse erst nach dem Anlegen in der App-Verwaltung erscheint, diesen Schritt dort abschließen, bevor verbunden wird. Keine Callback-Adresse erraten.
5. Falls explizite URLs benötigt werden:

   ```text
   Authorization URL: https://deine-domain.at/fit/multi/authorize.php
   Token URL:         https://deine-domain.at/fit/multi/token.php
   Scopes:            coach:read offline_access
   ```

6. Verbindung starten: Die API-Key-Maske erscheint auf deiner Domain. Mit einem freigegebenen eigenen Konto testen.
7. Prüfen, dass alle fünf Tools geladen werden und das eigene Profil korrekt ist.

Der Server erfordert `resource=https://deine-domain.at/fit/multi/mcp.php` im Autorisierungs- und Token-Ablauf. Die Metadaten veröffentlichen PKCE S256 und den Authorization Code Flow. Client-Anmeldung wird über `client_secret_basic` oder `client_secret_post` akzeptiert.

Die echte OAuth-Verbindung muss noch mit deinem ChatGPT-Konto geprüft werden. Wenn Felder oder Callback-Angaben abweichen, die Ansicht ohne Secrets zeigen.

## 6. Gemeinsames Plugin

Nach erfolgreichem Verbindungstest ein separates privates Plugin mit dem Coach-Skill und **ausschließlich der neuen registrierten OAuth-App** bündeln. Die technische App-ID aus der tatsächlichen neuen Verbindung übernehmen. Kein Paket mit deiner bisherigen persönlichen MCP-URL verteilen.

Persönliche Ziele und Wochenverfügbarkeit gehören in den jeweiligen Nutzerkontext, nicht fest in den gemeinsam verteilten Skill.

Die private Freigabe an andere Konten hängt vom ChatGPT-Tarif bzw. Workspace ab und ist separat zu prüfen. Ein Download-/Upload-Paket darf keine persönlichen Keys, URL-Tokens oder das OAuth-Client-Secret enthalten. Nutzer müssen ihre eigene Verbindung herstellen. Falls der Tarif keine passende private Freigabe bietet, können separate App-Verbindungen erforderlich sein; dafür muss die Client-/Callback-Konfiguration gezielt erweitert werden. Die aktuelle Version ist auf einen registrierten ChatGPT-Client ausgelegt.

## Widerruf und Löschen

- Nutzer können unter `/fit/multi/disconnect.php` ihr Konto mit dem aktuellen API-Key bestätigen und den gespeicherten Key samt allen Coach-Zugängen löschen.
- Entfernen einer Athlete-ID aus `allowed_athletes` sperrt auch bestehende Zugänge beim nächsten Aufruf. Die verschlüsselten Daten werden dadurch nicht automatisch gelöscht.
- Administratives Löschen ohne SSH: In phpMyAdmin in einer Transaktion die zugehörigen Datensätze aus `coach_tokens`, `coach_grants` und `coach_accounts` löschen. Bedingungen jeweils auf die tatsächliche Athlete-ID beschränken.
- Optional per CLI aus `multi/`: `php lib/admin.php disconnect i12345`.
- Eine neue Anmeldung ersetzt sämtliche bisherigen Coach-Verbindungen dieses Athleten. Gleichzeitige Verbindungen desselben Athleten aus mehreren ChatGPT-Konten sind in dieser Version nicht vorgesehen.
- Ein erneuerter intervals.icu-Key macht den alten Key ungültig. Danach neu verbinden oder den gespeicherten Zugang entfernen. Der Server ändert oder widerruft den Key bei intervals.icu selbst nicht.
- OAuth-Revoke in ChatGPT sperrt eine Token-Familie, löscht aber nicht automatisch den gespeicherten intervals.icu-Key. Zum vollständigen Entfernen die Löschmaske verwenden.

Access-Token gelten 15 Minuten. Mit `offline_access` werden Refresh-Token mit maximal 30 Tagen Laufzeit ausgegeben und bei Nutzung rotiert. Wiederverwendung eines benutzten Refresh-Tokens sperrt die ganze Familie. Ohne `offline_access` ist nach Ablauf eine erneute Anmeldung nötig.

## Betrieb und Tests

Keine Composer-Abhängigkeiten oder Hintergrundprozesse. Anmeldung und Nutzerverbindungen benötigen PHP-Sessions und Datenbank. Abgelaufene Codes, Tokens und Rate-Limit-Zähler werden bei einem kleinen Teil normaler Anfragen begrenzt bereinigt. Optional kann `php lib/admin.php cleanup` wiederholt ausgeführt werden.

Rate-Limits gelten pro Konto für MCP und pro Quell-IP für Anmelde-/Token-Endpunkte. IP-Adressen werden als HMAC-Zählerkennung gespeichert, nicht im Klartext. Viele Nutzer hinter einem Proxy können Limits gemeinsam erreichen.

Die Anwendung protokolliert keine Keys oder Token. Beim Hosting zusätzlich sicherstellen, dass POST-Bodies der Anmelde-/Token-Endpunkte nicht aufgezeichnet werden. Antworten sind `no-store`; Formulare verwenden CSRF-Schutz und sichere Session-Cookies. HTTPS muss korrekt erkannt werden; `HTTPS=on` darf für echte HTTP-Zugänge nicht künstlich gesetzt werden.

Lokale Tests:

```powershell
node test/local.mjs C:/Pfad/php.exe
node test/multi-local.mjs C:/Pfad/php.exe
```

Bei Bedarf `-d extension_dir=C:/Pfad/ext -d extension=curl -d extension=openssl -d extension=pdo_sqlite` anhängen. Die zweite Testsuite verwendet eine temporäre Kopie, fiktive Keys und SQLite und prüft HTTP-Login mit zwei Konten, CSRF, Callback, PKCE, Freigaben, Nutzertrennung, Verschlüsselung, Refresh-Replay, Widerruf und Löschung. Produktionsdateien und echte Keys werden nicht verwendet. SQLite wird auf dem Webspace nicht benötigt.

Auf dem Webspace:

Das SQL-Schema und die OAuth-/Speicher-Tests wurden zusätzlich gegen eine lokale MariaDB 11.4 mit InnoDB geprüft. Für eine Wiederholung auf einer eigenen lokalen Testinstanz kann `test/oauth.php --mysql` verwendet werden: `COACH_TEST_MYSQL_DSN` muss auf eine neue Datenbank `coach_test_…` auf `127.0.0.1` mit explizitem Port und `charset=utf8mb4` zeigen; Zugang über `COACH_TEST_MYSQL_USER` und `COACH_TEST_MYSQL_PASSWORD`. Der Test legt Tabellen an; keine Produktionsdatenbank verwenden.

```powershell
node test/multi-smoke.mjs https://deine-domain.at/fit/multi
```

Danach echte Anmeldung und alle fünf Tools mit zwei getrennten ChatGPT-/intervals.icu-Konten prüfen. Konto A darf auch bei bekannter Aktivitäts-ID von B keine fremden Details oder Intervalle erhalten. Sperren und Löschen von A dürfen B nicht beeinflussen. Der bisherige Einzelbenutzer-Smoke-Test bleibt zusätzlich verwendbar.

Quellen:

- [intervals.icu: API-Zugang](https://forum.intervals.icu/t/api-access-to-intervals-icu/609)
- [intervals.icu: ID 0 mit API-Key](https://forum.intervals.icu/t/api-access-to-intervals-icu/609?page=24)
- [OpenAI: OAuth für Plugin-MCP-Server](https://developers.openai.com/plugins/build/auth)
- [OpenAI: Plugin-Pakete und Verteilung](https://developers.openai.com/plugins/build/plugins)
