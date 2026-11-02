<?php
declare(strict_types=1);

// Als config.php speichern. Keine Zugangsdaten ins Repository übernehmen.
define('INTERVALS_API_KEY', 'DEIN_API_KEY');
define('INTERVALS_ATHLETE_ID', 'i12345');
// Erzeugen: node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
define('MCP_TOKEN', 'HIER_64_ZUFAELLIGE_HEX_ZEICHEN_EINTRAGEN');
define('COACH_TIMEZONE', 'Europe/Vienna');
// Erst nach Einrichtung gezielt aktivieren. Bestehende config.php entsprechend ergänzen.
define('ENABLE_CALENDAR_WRITES', false);
// Requests ohne Origin sind erlaubt (Server-zu-Server). Browser nur explizit erlauben.
define('MCP_ALLOWED_ORIGINS', ['https://chatgpt.com']);
