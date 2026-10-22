<?php
declare(strict_types=1);
// Ausschließlich über CLI verwenden; lib/ bleibt im Web gesperrt.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
if (($argv[1] ?? '') === 'disconnect' && isset($argv[2])) {
    $auth->disconnect($argv[2]);
    echo "Verbindung, API-Key und Coach-Zugänge gelöscht. Freigabeliste bei Bedarf ebenfalls anpassen.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $auth->cleanup();
    echo "Abgelaufene Einträge begrenzt bereinigt.\n";
} else {
    fwrite(STDERR, "Aufruf: php lib/admin.php disconnect i12345 | cleanup\n");
    exit(1);
}
