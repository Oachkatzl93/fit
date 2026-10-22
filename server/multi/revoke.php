<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$form = form_body();
$auth->limit('revoke:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120);
authenticate_oauth_client($form, $config, incoming_authorization());
if (!isset($form['token'])) {
    throw new OAuthFailure('invalid_request', 'Token fehlt.');
}
$auth->revoke($form['token']);
http_response_code(200);
