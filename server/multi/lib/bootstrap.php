<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function multi_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof OAuthFailure) {
        if ($e->status === 401 && $e->oauthCode === 'invalid_client') {
            header('WWW-Authenticate: Basic realm="Coach"');
        }
        multi_json(['error' => $e->oauthCode, 'error_description' => $e->getMessage()], $e->status);
    }
    // Keine Exception-Details: PDO-/curl-/Konfigurationsfehler können Geheimnisse enthalten.
    multi_json(['error' => 'server_error', 'error_description' => 'Server-Konfiguration oder Datenbank prüfen.'], 503);
});

require_once __DIR__ . '/auth.php';
$file = dirname(__DIR__) . '/config.php';
if (!is_file($file)) {
    multi_json(['error' => 'server_not_configured'], 503);
}
$config = require $file;
$url = is_array($config) ? parse_url($config['base_url'] ?? '') : false;
if (!$url || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['query']) || isset($url['fragment'])
    || isset($url['user']) || isset($url['pass']) || str_ends_with($config['base_url'], '/')
    || !preg_match('/^[a-fA-F0-9]{64}$/D', $config['encryption_key'] ?? '')
    || strlen($config['client_secret'] ?? '') < 32 || strlen($config['client_id'] ?? '') < 1
    || !is_array($config['allowed_athletes'] ?? null) || !is_array($config['redirect_uris'] ?? null)
    || !extension_loaded('openssl') || !extension_loaded('curl')) {
    throw new RuntimeException('Konfiguration unvollständig.');
}
if (PHP_SAPI !== 'cli' && ($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
    multi_json(['error' => 'https_required'], 400);
}
foreach ($config['allowed_athletes'] as $athlete) {
    athlete_identity($athlete);
}
foreach ($config['redirect_uris'] as $redirect) {
    $parts = is_string($redirect) ? parse_url($redirect) : false;
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
        || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Ungültige Callback-Konfiguration.');
    }
}
// Die ausgelieferte Konfiguration nutzt MySQL; lokale Tests verwenden temporäres SQLite.
$db = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$auth = new CoachAuth($db, $config);
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$ownOrigin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
if ($origin !== null && !in_array($origin, array_merge([$ownOrigin], $config['allowed_origins'] ?? ['https://chatgpt.com']), true)) {
    multi_json(['error' => 'invalid_origin'], 403);
}
if (random_int(1, 100) === 1) {
    $auth->cleanup();
}

function form_body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        multi_json(['error' => 'invalid_request'], 405);
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/x-www-form-urlencoded') {
        multi_json(['error' => 'invalid_request', 'error_description' => 'Form-URL-Encoding erforderlich.'], 415);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        multi_json(['error' => 'invalid_request'], 413);
    }
    foreach ($_POST as $v) {
        if (!is_string($v) || strlen($v) > 4096) {
            multi_json(['error' => 'invalid_request'], 400);
        }
    }
    return $_POST;
}

function incoming_authorization(): ?string
{
    return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
}
