<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    multi_json(['error' => 'method_not_allowed'], 405);
}
$scopes = ['coach:read', 'offline_access'];
if (($config['enable_calendar_writes'] ?? false) === true) {
    $scopes[] = 'coach:calendar:write';
}
if (($_GET['kind'] ?? '') === 'resource') {
    multi_json([
        'resource' => $config['base_url'] . '/mcp.php',
        'resource_name' => 'intervals.icu Coach',
        'authorization_servers' => [$config['base_url']],
        'scopes_supported' => $scopes,
        'bearer_methods_supported' => ['header'],
    ]);
}
multi_json([
    'issuer' => $config['base_url'],
    'authorization_endpoint' => $config['base_url'] . '/authorize.php',
    'token_endpoint' => $config['base_url'] . '/token.php',
    'revocation_endpoint' => $config['base_url'] . '/revoke.php',
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
    'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => $scopes,
    'authorization_response_iss_parameter_supported' => true,
]);
