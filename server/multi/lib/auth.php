<?php
declare(strict_types=1);

final class OAuthFailure extends RuntimeException
{
    public function __construct(public string $oauthCode, string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function opaque_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function athlete_identity(mixed $id): string
{
    $id = is_string($id) || is_int($id) ? (string) $id : '';
    if (!preg_match('/^i?([1-9][0-9]{0,18})$/D', $id, $m)) {
        throw new InvalidArgumentException('Ungültige Athlete-ID.');
    }
    return 'i' . $m[1];
}

function account_allowed(string $id, array $config): bool
{
    return in_array(athlete_identity($id), array_map('athlete_identity', $config['allowed_athletes']), true);
}

function requested_scope(string $scope): string
{
    $parts = array_values(array_unique(explode(' ', trim($scope))));
    if (!in_array('coach:read', $parts, true) || array_diff($parts, ['coach:read', 'offline_access']) !== []) {
        throw new OAuthFailure('invalid_scope', 'Erforderlicher Scope: coach:read; optional offline_access.');
    }
    sort($parts);
    return implode(' ', $parts);
}

function authorization_request(array $input, array $config): array
{
    foreach ($input as $value) {
        if (!is_string($value)) {
            throw new OAuthFailure('invalid_request', 'Ungültige Autorisierungsparameter.');
        }
    }
    $redirect = $input['redirect_uri'] ?? '';
    if (($input['client_id'] ?? '') !== $config['client_id'] || !in_array($redirect, $config['redirect_uris'], true)) {
        // Keine Weiterleitung an ungeprüfte Callback-Adressen.
        throw new OAuthFailure('invalid_request', 'Client oder Redirect-URI ist nicht freigegeben.');
    }
    if (($input['response_type'] ?? '') !== 'code'
        || ($input['code_challenge_method'] ?? '') !== 'S256'
        || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'] ?? '')
        || !isset($input['state']) || strlen($input['state']) < 1 || strlen($input['state']) > 2048
        || ($input['resource'] ?? '') !== $config['base_url'] . '/mcp.php') {
        throw new OAuthFailure('invalid_request', 'Authorization Code, S256, state und die korrekte MCP-Resource sind erforderlich.');
    }
    if (isset($input['code_challenge']) && array_key_exists('request_uri', $input)) {
        throw new OAuthFailure('invalid_request', 'Request-URI wird nicht unterstützt.');
    }
    return [
        'client_id' => $config['client_id'], 'redirect_uri' => $redirect,
        'resource' => $input['resource'], 'scope' => requested_scope($input['scope'] ?? 'coach:read'),
        'challenge' => $input['code_challenge'], 'state' => $input['state'],
    ];
}

function authenticate_oauth_client(array $form, array $config, ?string $authorization): void
{
    $id = $form['client_id'] ?? '';
    $secret = $form['client_secret'] ?? '';
    if ($authorization !== null) {
        if (!preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/Di', $authorization, $m)
            || ($decoded = base64_decode($m[1], true)) === false || !str_contains($decoded, ':')
            || isset($form['client_secret'])) {
            throw new OAuthFailure('invalid_client', 'Client-Anmeldung fehlgeschlagen.', 401);
        }
        [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));
        if (isset($form['client_id']) && $form['client_id'] !== $id) {
            throw new OAuthFailure('invalid_client', 'Client-Anmeldung fehlgeschlagen.', 401);
        }
    }
    if (!is_string($id) || !is_string($secret) || !hash_equals($config['client_id'], $id)
        || !hash_equals($config['client_secret'], $secret)) {
        throw new OAuthFailure('invalid_client', 'Client-Anmeldung fehlgeschlagen.', 401);
    }
}

final class CoachAuth
{
    public function __construct(private PDO $db, private array $config)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function query(string $sql, array $params = []): PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s;
    }

    private function locked(string $table, string $id): array|false
    {
        $suffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        return $this->query("SELECT * FROM $table WHERE id = ?" . $suffix, [$id])->fetch(PDO::FETCH_ASSOC);
    }

    private function atomic(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function encrypt(string $key, string $identity = ''): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($key, 'aes-256-gcm', hex2bin($this->config['encryption_key']), OPENSSL_RAW_DATA, $iv, $tag, 'coach-api-key-v1:' . $identity);
        if ($cipher === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encrypted, string $identity = ''): string
    {
        $bytes = base64_decode($encrypted, true);
        $key = $bytes !== false && strlen($bytes) > 28
            ? openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hex2bin($this->config['encryption_key']), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'coach-api-key-v1:' . $identity)
            : false;
        if ($key === false) {
            throw new RuntimeException('Gespeicherter Zugang kann nicht gelesen werden.');
        }
        return $key;
    }

    public function limit(string $bucket, int $maximum, int $seconds = 60): void
    {
        $now = time();
        $id = hash_hmac('sha256', $bucket . ':' . intdiv($now, $seconds), $this->config['encryption_key']);
        $this->atomic(function () use ($id, $now, $seconds, $maximum): void {
            $row = $this->locked('coach_limits', $id);
            if ($row && (int) $row['hits'] >= $maximum) {
                throw new OAuthFailure('temporarily_unavailable', 'Zu viele Versuche. Bitte später erneut versuchen.', 429);
            }
            if ($row) {
                $this->query('UPDATE coach_limits SET hits = hits + 1 WHERE id = ?', [$id]);
            } else {
                $this->query('INSERT INTO coach_limits (id, hits, expires_at) VALUES (?, 1, ?)', [$id, $now + $seconds * 2]);
            }
        });
    }

    public function authorize(array $request, string $apiKey, array $profile): string
    {
        $id = athlete_identity($profile['id'] ?? null);
        if (!account_allowed($id, $this->config)) {
            throw new OAuthFailure('access_denied', 'Dieses intervals.icu-Konto ist nicht freigeschaltet.', 403);
        }
        $timezone = $profile['timezone'] ?? 'Europe/Vienna';
        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'Europe/Vienna';
        }
        return $this->atomic(function () use ($id, $apiKey, $timezone, $request): string {
            $old = $this->locked('coach_accounts', $id);
            $generation = $old ? (int) $old['generation'] + 1 : 1;
            $values = [$this->encrypt($apiKey, $id), $timezone, $generation, time(), $id];
            if ($old) {
                $this->query('UPDATE coach_accounts SET api_key = ?, timezone = ?, generation = ?, updated_at = ? WHERE id = ?', $values);
                // Neue Anmeldung ersetzt für diesen Athleten sämtliche alten Verbindungen.
                $this->query('DELETE FROM coach_tokens WHERE athlete_id = ?', [$id]);
                $this->query('DELETE FROM coach_grants WHERE athlete_id = ?', [$id]);
            } else {
                $this->query('INSERT INTO coach_accounts (api_key, timezone, generation, updated_at, id) VALUES (?, ?, ?, ?, ?)', $values);
            }
            $code = opaque_token();
            $this->query('INSERT INTO coach_grants (id, athlete_id, generation, client_id, redirect_uri, resource, scope, challenge, expires_at, consumed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)', [
                hash('sha256', $code), $id, $generation, $request['client_id'], $request['redirect_uri'],
                $request['resource'], $request['scope'], $request['challenge'], time() + 120,
            ]);
            return $code;
        });
    }

    private function issue(array $grant, string $family, ?int $refreshExpiry = null): array
    {
        $access = opaque_token();
        $refresh = str_contains(' ' . $grant['scope'] . ' ', ' offline_access ') ? opaque_token() : null;
        foreach (['access' => $access, 'refresh' => $refresh] as $kind => $value) {
            if ($value === null) {
                continue;
            }
            $this->query('INSERT INTO coach_tokens (id, kind, family, athlete_id, generation, client_id, resource, scope, expires_at, consumed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)', [
                hash('sha256', $value), $kind, $family, $grant['athlete_id'], $grant['generation'],
                $grant['client_id'], $grant['resource'], $grant['scope'],
                $kind === 'access' ? time() + 900 : ($refreshExpiry ?? time() + 30 * 86400),
            ]);
        }
        $result = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 900, 'scope' => $grant['scope']];
        if ($refresh !== null) {
            $result['refresh_token'] = $refresh;
        }
        return $result;
    }

    private function validAccount(array $grant): array
    {
        $account = $this->query('SELECT * FROM coach_accounts WHERE id = ?', [$grant['athlete_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$account || !account_allowed($account['id'], $this->config)
            || (int) $account['generation'] !== (int) $grant['generation']) {
            throw new OAuthFailure('invalid_grant', 'Verbindung ist nicht mehr gültig.');
        }
        return $account;
    }

    public function exchange(array $form): array
    {
        return $this->atomic(function () use ($form): array {
            $row = $this->locked('coach_grants', hash('sha256', $form['code'] ?? ''));
            $verifier = $form['code_verifier'] ?? '';
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!$row || (int) $row['expires_at'] <= time() || (int) $row['consumed'] !== 0
                || $row['client_id'] !== $this->config['client_id']
                || $row['redirect_uri'] !== ($form['redirect_uri'] ?? '')
                || $row['resource'] !== ($form['resource'] ?? '')
                || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
                || !hash_equals($row['challenge'], $challenge)) {
                throw new OAuthFailure('invalid_grant', 'Code, PKCE-Verifier, Resource oder Callback ungültig.');
            }
            $this->validAccount($row);
            $this->query('UPDATE coach_grants SET consumed = 1 WHERE id = ?', [$row['id']]);
            return $this->issue($row, opaque_token());
        });
    }

    public function refresh(array $form): array
    {
        $result = $this->atomic(function () use ($form): array {
            $row = $this->locked('coach_tokens', hash('sha256', $form['refresh_token'] ?? ''));
            if (!$row || $row['kind'] !== 'refresh' || $row['client_id'] !== $this->config['client_id']
                || (int) $row['expires_at'] <= time() || $row['resource'] !== ($form['resource'] ?? '')) {
                throw new OAuthFailure('invalid_grant', 'Refresh-Verbindung ungültig oder abgelaufen.');
            }
            if ((int) $row['consumed'] !== 0) {
                $this->query('UPDATE coach_tokens SET consumed = 1 WHERE family = ?', [$row['family']]);
                // Commit trotz Fehler, damit eine Wiederverwendung die Familie sperrt.
                return ['reused' => true];
            }
            $this->validAccount($row);
            if (isset($form['scope']) && requested_scope($form['scope']) !== $row['scope']) {
                throw new OAuthFailure('invalid_scope', 'Scope-Änderung benötigt eine neue Anmeldung.');
            }
            $this->query('UPDATE coach_tokens SET consumed = 1 WHERE id = ?', [$row['id']]);
            return $this->issue($row, $row['family'], (int) $row['expires_at']);
        });
        if (isset($result['reused'])) {
            throw new OAuthFailure('invalid_grant', 'Refresh-Token wurde wiederverwendet. Bitte neu verbinden.');
        }
        return $result;
    }

    public function resolve(string $token): array
    {
        $row = $this->query('SELECT * FROM coach_tokens WHERE id = ?', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['kind'] !== 'access' || (int) $row['consumed'] !== 0 || (int) $row['expires_at'] <= time()
            || $row['resource'] !== $this->config['base_url'] . '/mcp.php' || $row['client_id'] !== $this->config['client_id']
            || !str_contains(' ' . $row['scope'] . ' ', ' coach:read ')) {
            throw new OAuthFailure('invalid_token', 'Bitte Coach erneut verbinden.', 401);
        }
        try {
            $account = $this->validAccount($row);
        } catch (OAuthFailure $e) {
            throw new OAuthFailure('invalid_token', 'Verbindung wurde entfernt oder gesperrt.', 401);
        }
        return ['athlete_id' => $account['id'], 'api_key' => $this->decrypt($account['api_key'], $account['id']), 'timezone' => $account['timezone']];
    }

    public function revoke(string $token): void
    {
        $this->atomic(function () use ($token): void {
            $row = $this->locked('coach_tokens', hash('sha256', $token));
            if ($row && $row['client_id'] === $this->config['client_id']) {
                $this->query('UPDATE coach_tokens SET consumed = 1 WHERE family = ?', [$row['family']]);
            }
        });
    }

    public function disconnect(string $id): void
    {
        $id = athlete_identity($id);
        $this->atomic(function () use ($id): void {
            $this->locked('coach_accounts', $id);
            $this->query('DELETE FROM coach_tokens WHERE athlete_id = ?', [$id]);
            $this->query('DELETE FROM coach_grants WHERE athlete_id = ?', [$id]);
            $this->query('DELETE FROM coach_accounts WHERE id = ?', [$id]);
        });
    }

    public function cleanup(): void
    {
        foreach (['coach_grants', 'coach_tokens', 'coach_limits'] as $table) {
            $rows = $this->query("SELECT id FROM $table WHERE expires_at < ? LIMIT 100", [time()])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                $this->query("DELETE FROM $table WHERE id = ? AND expires_at < ?", [$id, time()]);
            }
        }
    }
}
