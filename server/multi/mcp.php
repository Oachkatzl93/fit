<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/../lib/intervals.php';
require __DIR__ . '/../lib/tools.php';
require __DIR__ . '/../lib/rpc.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$header = incoming_authorization() ?? '';
try {
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/Di', $header, $m)) {
        throw new OAuthFailure('invalid_token', 'Bitte Coach verbinden.', 401);
    }
    $account = $auth->resolve($m[1]);
} catch (OAuthFailure $failure) {
    header('WWW-Authenticate: Bearer resource_metadata="' . $config['base_url'] . '/metadata.php?kind=resource", error="invalid_token", scope="coach:read"');
    multi_json(['error' => 'invalid_token', 'error_description' => 'Bitte Coach verbinden.'], 401);
}
$auth->limit('mcp:' . $account['athlete_id'], 120);
$client = new IntervalsClient($account['api_key'], $account['athlete_id'], $account['timezone']);
unset($account);
$versions = ['2025-11-25', '2025-06-18', '2025-03-26'];
if (isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']) && !in_array($_SERVER['HTTP_MCP_PROTOCOL_VERSION'], $versions, true)) {
    send_json(rpc_error(null, -32600, 'Nicht unterstützte MCP-Protokollversion.'), 400);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    send_json(rpc_error(null, -32600, 'Content-Type application/json erforderlich.'), 415);
}
$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) {
    send_json(rpc_error(null, -32600, 'Request zu groß.'), 413);
}
try {
    $request = json_decode($body, false, 128, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    send_json(rpc_error(null, -32700, 'Ungültiges JSON.'), 400);
}
// Auf der neuen OAuth-Schnittstelle nur einzelne MCP-Nachrichten.
if (is_array($request)) {
    send_json(rpc_error(null, -32600, 'Einzelnen JSON-RPC-Request senden.'), 400);
}
$response = dispatch($request, $versions, $client, true);
if ($response !== null) {
    send_json($response);
}
http_response_code(202);
