<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/lib/rpc.php';

$config = __DIR__ . '/config.php';
if (!is_file($config)) {
    http_response_code(404);
    exit;
}
require $config;
$token = $_GET['token'] ?? '';
if (!defined('MCP_TOKEN') || !is_string(MCP_TOKEN) || strlen(MCP_TOKEN) < 32
    || !is_string($token) || !hash_equals(MCP_TOKEN, $token)) {
    http_response_code(404);
    exit;
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin !== null && !in_array($origin, defined('MCP_ALLOWED_ORIGINS') ? MCP_ALLOWED_ORIGINS : ['https://chatgpt.com'], true)) {
    http_response_code(403);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$versions = ['2025-11-25', '2025-06-18', '2025-03-26'];
if (isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']) && !in_array($_SERVER['HTTP_MCP_PROTOCOL_VERSION'], $versions, true)) {
    send_json(rpc_error(null, -32600, 'Nicht unterstützte MCP-Protokollversion.'), 400);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    send_json(rpc_error(null, -32600, 'Content-Type application/json erforderlich.'), 415);
}
if (!defined('INTERVALS_API_KEY') || !is_string(INTERVALS_API_KEY) || INTERVALS_API_KEY === ''
    || !defined('INTERVALS_ATHLETE_ID') || !is_string(INTERVALS_ATHLETE_ID)
    || !preg_match('/^i[0-9]+$/D', INTERVALS_ATHLETE_ID) || !extension_loaded('curl')) {
    send_json(rpc_error(null, -32603, 'Server-Konfiguration oder PHP-curl prüfen.'), 500);
}
if (!defined('COACH_TIMEZONE')) {
    define('COACH_TIMEZONE', 'Europe/Vienna');
}
require __DIR__ . '/lib/intervals.php';
require __DIR__ . '/lib/tools.php';

$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) {
    send_json(rpc_error(null, -32600, 'Request zu groß.'), 413);
}
try {
    $request = json_decode($body, false, 128, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    send_json(rpc_error(null, -32700, 'Ungültiges JSON.'), 400);
}
if (is_array($request)) {
    // Kompatibilitätserweiterung: aktuelle MCP-Clients senden einzelne Requests.
    if ($request === [] || count($request) > 20) {
        send_json(rpc_error(null, -32600, 'Batch muss 1 bis 20 Einträge enthalten.'), 400);
    }
    $responses = [];
    foreach ($request as $item) {
        $response = dispatch($item, $versions);
        if ($response !== null) {
            $responses[] = $response;
        }
    }
    if ($responses !== []) {
        send_json($responses);
    }
} else {
    $response = dispatch($request, $versions);
    if ($response !== null) {
        send_json($response);
    }
}
http_response_code(202);
