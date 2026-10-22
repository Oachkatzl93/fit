<?php
declare(strict_types=1);
require __DIR__ . '/../server/multi/lib/auth.php';
require __DIR__ . '/../server/lib/intervals.php';
require __DIR__ . '/../server/lib/tools.php';

function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function denied(callable $work, string $code): void
{
    try {
        $work();
    } catch (OAuthFailure $e) {
        verify($e->oauthCode === $code, 'Falscher OAuth-Fehler: ' . $e->oauthCode);
        return;
    }
    throw new RuntimeException('Erwarteter OAuth-Fehler fehlt: ' . $code);
}

$mysql = ($argv[1] ?? '') === '--mysql';
$database = ($argv[1] ?? '') === '--init' ? $argv[2] : ':memory:';
if ($mysql) {
    $dsn = getenv('COACH_TEST_MYSQL_DSN') ?: '';
    // Dieser Test legt Tabellen an; ausschließlich eine ausdrücklich benannte Testdatenbank verwenden.
    verify((bool) preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=coach_test_[A-Za-z0-9_]+;charset=utf8mb4$/D', $dsn), 'Lokale coach_test_-Datenbank erforderlich');
    $db = new PDO($dsn, getenv('COACH_TEST_MYSQL_USER'), getenv('COACH_TEST_MYSQL_PASSWORD'));
} else {
    $db = new PDO('sqlite:' . $database);
}
$schema = file_get_contents(__DIR__ . '/../server/multi/schema.sql');
if (!$mysql) {
    $schema = str_replace(' ENGINE=InnoDB', '', $schema);
}
$db->exec($schema);
if ($database !== ':memory:') {
    echo "OK Testdatenbank initialisiert\n";
    exit;
}
$config = [
    'base_url' => 'https://coach.invalid/fit/multi', 'encryption_key' => str_repeat('ab', 32),
    'client_id' => 'coach-chatgpt', 'client_secret' => str_repeat('c', 64),
    'redirect_uris' => ['https://chatgpt.example/callback'], 'allowed_athletes' => ['i101', 'i202'],
];
$auth = new CoachAuth($db, $config);
$verifier = str_repeat('v', 43);
$input = [
    'response_type' => 'code', 'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uris'][0],
    'state' => 'random-test-state', 'resource' => $config['base_url'] . '/mcp.php',
    'scope' => 'coach:read offline_access', 'code_challenge_method' => 'S256',
    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
];
$request = authorization_request($input, $config);
denied(fn() => authorization_request(array_replace($input, ['redirect_uri' => 'https://evil.invalid']), $config), 'invalid_request');
denied(fn() => authorization_request(array_replace($input, ['resource' => 'https://other.invalid/mcp']), $config), 'invalid_request');
denied(fn() => authorization_request(array_replace($input, ['code_challenge_method' => 'plain']), $config), 'invalid_request');
denied(fn() => authorization_request(array_replace($input, ['scope' => 'coach:write']), $config), 'invalid_scope');
authenticate_oauth_client(['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']], $config, null);
authenticate_oauth_client([], $config, 'Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']));
denied(fn() => authenticate_oauth_client(['client_id' => $config['client_id'], 'client_secret' => 'bad'], $config, null), 'invalid_client');
denied(fn() => authenticate_oauth_client(['client_secret' => $config['client_secret']], $config, 'Basic ' . base64_encode('coach-chatgpt:bad')), 'invalid_client');

$keyA = 'fixture-api-key-A';
$encrypted = $auth->encrypt($keyA);
verify($auth->decrypt($encrypted) === $keyA && !str_contains($encrypted, $keyA), 'Key-Verschlüsselung');
$tampered = base64_decode($encrypted);
$tampered[20] = chr(ord($tampered[20]) ^ 1);
try {
    $auth->decrypt(base64_encode($tampered));
    throw new LogicException('Manipulierte Verschlüsselung akzeptiert');
} catch (RuntimeException $e) {
    verify(!$e instanceof LogicException, 'Manipulierte Verschlüsselung akzeptiert');
}
denied(fn() => $auth->authorize($request, 'key-not-allowed', ['id' => 'i303']), 'access_denied');
verify((int) $db->query('SELECT COUNT(*) FROM coach_accounts')->fetchColumn() === 0, 'Nicht freigegebenes Konto gespeichert');
$codeA = $auth->authorize($request, $keyA, ['id' => '101', 'timezone' => 'America/New_York']);
$codeB = $auth->authorize($request, 'fixture-api-key-B', ['id' => 'i202', 'timezone' => 'Europe/Vienna']);
$exchange = fn(string $code): array => ['code' => $code, 'redirect_uri' => $input['redirect_uri'], 'code_verifier' => $verifier, 'resource' => $input['resource']];
denied(fn() => $auth->exchange(array_replace($exchange($codeA), ['code_verifier' => str_repeat('x', 43)])), 'invalid_grant');
denied(fn() => $auth->exchange(array_replace($exchange($codeA), ['resource' => 'https://evil.invalid'])), 'invalid_grant');
$tokensA = $auth->exchange($exchange($codeA));
$tokensB = $auth->exchange($exchange($codeB));
denied(fn() => $auth->exchange($exchange($codeA)), 'invalid_grant');
$accountA = $auth->resolve($tokensA['access_token']);
$accountB = $auth->resolve($tokensB['access_token']);
verify($accountA['athlete_id'] === 'i101' && $accountA['api_key'] === $keyA && $accountA['timezone'] === 'America/New_York', 'Konto A falsch');
verify($accountB['athlete_id'] === 'i202' && $accountB['api_key'] !== $keyA, 'Nutzertrennung B');
verify(!str_contains((string) json_encode($db->query('SELECT * FROM coach_accounts')->fetchAll()), $keyA), 'Klartext-Key gespeichert');
try {
    $auth->decrypt($db->query("SELECT api_key FROM coach_accounts WHERE id='i101'")->fetchColumn(), 'i202');
    throw new LogicException('Verschlüsselter Key auf fremdes Konto übertragbar');
} catch (RuntimeException $e) {
    verify(!$e instanceof LogicException, 'Verschlüsselter Key auf fremdes Konto übertragbar');
}
verify(!$db->query("SELECT id FROM coach_tokens WHERE id = '" . $tokensA['access_token'] . "'")->fetchColumn(), 'MCP-Token im Klartext gespeichert');

class IsolatedFixture extends IntervalsClient
{
    public array $paths = [];
    public function get(string $path, array $query = []): array
    {
        $this->paths[] = $path;
        if ($path === '/activity/iForeign') {
            return ['id' => 'iForeign', 'icu_athlete_id' => 'i202', 'description' => 'PRIVATE-B'];
        }
        if (str_ends_with($path, '/activities') || str_ends_with($path, '/wellness') || str_ends_with($path, '/events')) {
            return [];
        }
        return ['id' => $this->athleteId()];
    }
}
$a = new IsolatedFixture($accountA['api_key'], $accountA['athlete_id'], $accountA['timezone']);
$b = new IsolatedFixture($accountB['api_key'], $accountB['athlete_id'], $accountB['timezone']);
verify(call_tool('get_athlete_profile', [], $a)['id'] === 'i101', 'Profil A');
verify(call_tool('get_athlete_profile', [], $b)['id'] === 'i202', 'Profil B');
foreach (['list_activities', 'get_wellness', 'get_calendar'] as $name) {
    call_tool($name, [], $a);
    call_tool($name, [], $b);
}
verify($a->paths === ['/athlete/i101', '/athlete/i101/activities', '/athlete/i101/wellness', '/athlete/i101/events'], 'A fragt fremde Pfade ab');
verify($b->paths === ['/athlete/i202', '/athlete/i202/activities', '/athlete/i202/wellness', '/athlete/i202/events'], 'B fragt fremde Pfade ab');
try {
    call_tool('get_activity', ['activity_id' => 'iForeign'], $a);
    throw new LogicException('Fremde Aktivität akzeptiert');
} catch (RuntimeException $e) {
    verify(!$e instanceof LogicException, 'Fremde Aktivität akzeptiert');
}
verify(!in_array('/activity/iForeign/intervals', $a->paths, true), 'Fremde Intervalle abgerufen');
$deniedConfig = $config;
$deniedConfig['allowed_athletes'] = ['i202'];
denied(fn() => (new CoachAuth($db, $deniedConfig))->resolve($tokensA['access_token']), 'invalid_token');
$refreshForm = ['refresh_token' => $tokensA['refresh_token'], 'resource' => $input['resource']];
denied(fn() => $auth->refresh(array_replace($refreshForm, ['resource' => 'https://evil.invalid'])), 'invalid_grant');
$rotated = $auth->refresh($refreshForm);
verify($auth->resolve($rotated['access_token'])['athlete_id'] === 'i101', 'Refresh-Konto falsch');
denied(fn() => $auth->refresh($refreshForm), 'invalid_grant');
denied(fn() => $auth->resolve($rotated['access_token']), 'invalid_token');
verify($auth->resolve($tokensB['access_token'])['athlete_id'] === 'i202', 'Refresh von A sperrt B');
$auth->revoke($tokensB['refresh_token']);
denied(fn() => $auth->resolve($tokensB['access_token']), 'invalid_token');
$auth->revoke('unknown-token');

$expiringCode = $auth->authorize($request, 'fixture-api-key-B', ['id' => 'i202']);
$s = $db->prepare('UPDATE coach_grants SET expires_at = ? WHERE id = ?');
$s->execute([time() - 1, hash('sha256', $expiringCode)]);
denied(fn() => $auth->exchange($exchange($expiringCode)), 'invalid_grant');
$newCode = $auth->authorize($request, 'fixture-api-key-B', ['id' => 'i202']);
$expiringTokens = $auth->exchange($exchange($newCode));
$s = $db->prepare('UPDATE coach_tokens SET expires_at = ? WHERE id = ?');
$s->execute([time() - 1, hash('sha256', $expiringTokens['access_token'])]);
denied(fn() => $auth->resolve($expiringTokens['access_token']), 'invalid_token');
$s->execute([time() - 1, hash('sha256', $expiringTokens['refresh_token'])]);
denied(fn() => $auth->refresh(['refresh_token' => $expiringTokens['refresh_token'], 'resource' => $input['resource']]), 'invalid_grant');

$code = $auth->authorize($request, 'new-key-a', ['id' => 'i101']);
$fresh = $auth->exchange($exchange($code));
$auth->authorize($request, 'replacement-key-a', ['id' => 'i101']);
denied(fn() => $auth->resolve($fresh['access_token']), 'invalid_token');
$auth->disconnect('i101');
verify(!$db->query("SELECT id FROM coach_accounts WHERE id='i101'")->fetchColumn(), 'Key nach Disconnect noch vorhanden');
$auth->limit('test-limit', 1);
denied(fn() => $auth->limit('test-limit', 1), 'temporarily_unavailable');
$auth->cleanup();
echo "OK OAuth/PKCE, Verschlüsselung, Freigaben, Nutzertrennung, Tokenrotation, Replay, Widerruf, Keywechsel und Limits\n";
