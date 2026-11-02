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

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function finish_authorization(array $request, array $values, array $config): never
{
    $values['state'] = $request['state'];
    $values['iss'] = $config['base_url'];
    $redirect = $request['redirect_uri'];
    $redirect .= (str_contains($redirect, '?') ? '&' : '?') . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    session_write_close();
    header('Location: ' . $redirect, true, 303);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    multi_json(['error' => 'method_not_allowed'], 405);
}
$auth->limit('authorize:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30);
foreach ($_SESSION['flows'] ?? [] as $key => $stored) {
    if ($stored['expires'] <= time()) {
        unset($_SESSION['flows'][$key]);
    }
}
$error = '';
if ($method === 'GET') {
    $request = authorization_request($_GET, $config);
    $flow = opaque_token();
    while (count($_SESSION['flows'] ?? []) >= 5) {
        array_shift($_SESSION['flows']);
    }
    $_SESSION['flows'][$flow] = ['request' => $request, 'csrf' => opaque_token(), 'expires' => time() + 600];
} else {
    $form = form_body();
    $flow = $form['flow'] ?? '';
    $stored = $_SESSION['flows'][$flow] ?? null;
    if (!$stored || !hash_equals($stored['csrf'], $form['csrf'] ?? '')) {
        throw new OAuthFailure('invalid_request', 'Anmeldung abgelaufen. Bitte in ChatGPT neu starten.');
    }
    $request = $stored['request'];
    if (($form['action'] ?? '') === 'cancel') {
        unset($_SESSION['flows'][$flow]);
        finish_authorization($request, ['error' => 'access_denied'], $config);
    }
    $apiKey = trim($form['api_key'] ?? '');
    if (($form['consent'] ?? '') !== 'yes' || strlen($apiKey) < 8 || strlen($apiKey) > 512
        || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
        $error = 'Bitte einen gültigen API-Key eingeben und dem Lesezugriff zustimmen.';
    } else {
        try {
            // /athlete/0 bindet die Identität an den Key, keine fremde Athlete-ID aus dem Formular.
            $profile = (new IntervalsClient($apiKey, '0'))->get('/athlete/0');
            $code = $auth->authorize($request, $apiKey, $profile);
            unset($_SESSION['flows'][$flow]);
            unset($apiKey, $form, $profile);
            finish_authorization($request, ['code' => $code], $config);
        } catch (OAuthFailure $failure) {
            $error = $failure->getMessage();
        } catch (RuntimeException | InvalidArgumentException $failure) {
            $error = 'API-Key konnte nicht bestätigt werden. Prüfe den Key oder versuche es später erneut.';
        }
    }
    unset($apiKey, $form, $profile);
}
header('Content-Type: text/html; charset=utf-8');
$writing = in_array('coach:calendar:write', explode(' ', $request['scope']), true);
?>
<!doctype html>
<html lang="de">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coach mit intervals.icu verbinden</title>
<style>
body{font:17px/1.55 system-ui,sans-serif;background:#f3f5f3;color:#17291f;margin:0;padding:24px}main{max-width:560px;margin:5vh auto;background:white;padding:32px;border-radius:18px}h1{font-size:1.7rem;line-height:1.2}label{display:block;margin:20px 0}input[type=password]{box-sizing:border-box;width:100%;padding:12px;margin-top:8px;font:inherit;border:1px solid #718477;border-radius:8px}button{font:inherit;padding:12px 18px;border:0;border-radius:8px;background:#155b39;color:white;cursor:pointer}button[value=cancel]{background:#e6ebe7;color:#17291f;margin-left:8px}.error{color:#9b1c20}small{color:#496150}a{color:#155b39}
</style>
<main>
<h1>Deinen Coach verbinden</h1>
<p>Gib deinen intervals.icu-API-Key ein. Der Coach darf damit deine Trainings, Wellnesswerte, Sporteinstellungen und deinen Kalender lesen.<?php if ($writing): ?> Zusätzlich darf er geplante Workouts und Wettkämpfe anlegen sowie geplante Workouts bearbeiten, verschieben und löschen.<?php endif; ?></p>
<p>Den Key findest du in intervals.icu unter <strong>Settings → Developer Settings</strong>.</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="<?= e($config['base_url'] . '/authorize.php') ?>" autocomplete="off">
<input type="hidden" name="flow" value="<?= e($flow) ?>">
<input type="hidden" name="csrf" value="<?= e($_SESSION['flows'][$flow]['csrf']) ?>">
<label>intervals.icu-API-Key<input type="password" name="api_key" maxlength="512" autocomplete="off" spellcheck="false"></label>
<label><input type="checkbox" name="consent" value="yes"> Ich erlaube die verschlüsselte Speicherung meines API-Keys und den beschriebenen Zugriff auf mein Konto.<?php if ($writing): ?> Das schließt die Anlage von Wettkämpfen sowie die Anlage, Bearbeitung und Löschung geplanter Workouts ein.<?php endif; ?></label>
<p><small>Die Freigabe gilt für deine Coach-Verbindung in ChatGPT. Dein API-Key wird nicht an ChatGPT weitergegeben. Abgeschlossene Aktivitäten, Wellnesswerte und Sporteinstellungen werden durch diese Schnittstelle nicht verändert. Eine neue Anmeldung ersetzt deine bisherige Coach-Verbindung.</small></p>
<button name="action" value="connect">Verbinden</button><button name="action" value="cancel">Abbrechen</button>
</form>
<p><small>Zum Entfernen der gespeicherten Verbindung kontaktiere den Betreiber. Deinen API-Key kannst du außerdem jederzeit in intervals.icu erneuern.</small></p>
<p><a href="<?= e($config['base_url'] . '/disconnect.php') ?>">Gespeicherte Verbindung selbst entfernen</a></p>
<?php if (($config['privacy_url'] ?? '') !== ''): ?><p><a href="<?= e($config['privacy_url']) ?>">Betreiber und Datenschutz</a></p><?php endif; ?>
</main>
</html>
