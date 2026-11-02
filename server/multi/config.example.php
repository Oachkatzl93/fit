<?php
declare(strict_types=1);

return [
    // Exakt die öffentliche HTTPS-Adresse, ohne Slash am Ende.
    'base_url' => 'https://DEINE-DOMAIN/fit/multi',
    'db_dsn' => 'mysql:host=localhost;dbname=DEINE_DATENBANK;charset=utf8mb4',
    'db_user' => 'DEIN_DB_BENUTZER',
    'db_password' => 'DEIN_DB_PASSWORT',
    // Jeweils getrennt erzeugen: node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
    'encryption_key' => 'HIER_64_HEX_ZEICHEN',
    'client_id' => 'coach-chatgpt',
    'client_secret' => 'HIER_EIN_WEITERES_ZUFAELLIGES_SECRET',
    // Die exakte Redirect-/Callback-URL aus der neuen ChatGPT-OAuth-App übernehmen.
    // Keine Wildcards. Erst nach Eintrag funktioniert die Anmeldung.
    'redirect_uris' => [],
    // Nur diese Konten erhalten Zugang. Tatsächliche IDs eintragen, niemals 0.
    'allowed_athletes' => ['i12345', 'i67890'],
    // Zusätzlich pro Verbindung coach:calendar:write anfordern und erneut zustimmen.
    'enable_calendar_writes' => false,
    'allowed_origins' => ['https://chatgpt.com'],
    // Optional: sichere HTTPS-Adresse mit deinen Betreiber-/Datenschutzinformationen.
    'privacy_url' => '',
];
