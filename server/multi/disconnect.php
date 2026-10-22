<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/../lib/intervals.php';
session_name('coach_multi_auth');
session_start([
    'cookie_secure' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax',
    'cookie_path' => (parse_url($config['base_url'], PHP_URL_PATH) ?: '/') . '/',
    'use_strict_mode' => true, 'use_only_cookies' => true,
]);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    multi_json(['error' => 'method_not_allowed'], 405);
}
$auth->limit('disconnect:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20);
if (!isset($_SESSION['disconnect']) || $_SESSION['disconnect']['expires'] <= time()) {
    $_SESSION['disconnect'] = ['csrf' => opaque_token(), 'expires' => time() + 600];
}
$message = '';
$removed = false;
if ($method === 'POST') {
    $form = form_body();
    if (!hash_equals($_SESSION['disconnect']['csrf'], $form['csrf'] ?? '')) {
        throw new OAuthFailure('invalid_request', 'Formular abgelaufen. Bitte neu laden.');
    }
    $key = trim($form['api_key'] ?? '');
    if (($form['confirm'] ?? '') !== 'yes' || strlen($key) < 8 || strlen($key) > 512 || preg_match('/[\x00-\x20\x7f]/', $key)) {
        $message = 'API-Key eingeben und Entfernen bestätigen.';
    } else {
        try {
            $profile = (new IntervalsClient($key, '0'))->get('/athlete/0');
            $auth->disconnect(athlete_identity($profile['id'] ?? null));
            $removed = true;
            unset($_SESSION['disconnect']);
            $message = 'Dein gespeicherter API-Key und alle zugehörigen Coach-Zugänge wurden entfernt.';
        } catch (RuntimeException | InvalidArgumentException $e) {
            $message = 'Konto konnte nicht bestätigt werden. Prüfe den API-Key oder kontaktiere den Betreiber.';
        }
    }
    unset($key, $form, $profile);
}
function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coach-Verbindung entfernen</title>
<style>body{font:17px/1.5 system-ui,sans-serif;margin:40px auto;padding:20px;max-width:560px}label{display:block;margin:20px 0}input[type=password]{width:100%;box-sizing:border-box;padding:12px;font:inherit}button{padding:12px;font:inherit}</style>
<main><h1>Coach-Verbindung entfernen</h1>
<p>Bestätige dein intervals.icu-Konto mit deinem aktuellen API-Key. Danach löschen wir den gespeicherten Key und sperren alle zugehörigen Coach-Zugänge. Deine Trainingsdaten auf intervals.icu bleiben erhalten.</p>
<?php if ($message !== ''): ?><p role="status"><?= escape_html($message) ?></p><?php endif; ?>
<?php if (!$removed): ?>
<form method="post" action="<?= escape_html($config['base_url'] . '/disconnect.php') ?>" autocomplete="off">
<input type="hidden" name="csrf" value="<?= escape_html($_SESSION['disconnect']['csrf']) ?>">
<label>intervals.icu-API-Key<input type="password" name="api_key" maxlength="512" autocomplete="off"></label>
<label><input type="checkbox" name="confirm" value="yes"> Meine gespeicherte Verbindung entfernen.</label>
<button>Verbindung entfernen</button>
</form>
<?php endif; ?>
<p>Alternativ kann der Betreiber die Verbindung entfernen. Ein in intervals.icu erneuerter API-Key macht den alten Key ungültig.</p>
</main></html>
