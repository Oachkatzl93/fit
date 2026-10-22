<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$form = form_body();
$auth->limit('token:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120);
authenticate_oauth_client($form, $config, incoming_authorization());
$result = match ($form['grant_type'] ?? '') {
    'authorization_code' => $auth->exchange($form),
    'refresh_token' => $auth->refresh($form),
    default => throw new OAuthFailure('unsupported_grant_type', 'Nur authorization_code und refresh_token werden unterstützt.'),
};
multi_json($result);
