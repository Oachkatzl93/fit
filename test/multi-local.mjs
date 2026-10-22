import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, cp, readFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { createServer } from 'node:net';
import { createHash } from 'node:crypto';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = process.argv[2] ? resolve(process.argv[2]) : 'php';
const flags = process.argv.slice(3);
const run = args => {
  const r = spawnSync(php, [...flags, ...args], { encoding: 'utf8' });
  assert(!r.error && r.status === 0, r.error?.message || r.stdout + r.stderr);
  process.stdout.write(r.stdout);
};
run([join(root, 'test/oauth.php')]);
const temporary = await mkdtemp(join(tmpdir(), 'coach-multi-test-'));
let server;
try {
  const docroot = join(temporary, 'server');
  await cp(join(root, 'server'), docroot, { recursive: true, filter: source => !/[\\/]config\.php$/.test(source) });
  const dbpath = join(temporary, 'test.db');
  run([join(root, 'test/oauth.php'), '--init', dbpath]);
  const clientSecret = 'c'.repeat(64);
  const configText = `<?php return [
    'base_url'=>'https://coach.invalid/fit/multi',
    'db_dsn'=>'sqlite:' . ${JSON.stringify(dbpath.replaceAll('\\', '/'))},
    'db_user'=>'','db_password'=>'','encryption_key'=>'${'ab'.repeat(32)}',
    'client_id'=>'coach-chatgpt','client_secret'=>'${clientSecret}',
    'redirect_uris'=>['https://chatgpt.example/callback'],
    'allowed_athletes'=>['i101','i202'], 'allowed_origins'=>['https://chatgpt.com']
  ];`;
  await writeFile(join(docroot, 'multi/config.php'), configText);
  // Nur die temporäre Kopie erhält eine Fixture. Produktionscode hat keinen Test-Schalter.
  const clientPath = join(docroot, 'lib/intervals.php');
  let source = await readFile(clientPath, 'utf8');
  const marker = 'public function get(string $path, array $query = []): array\n    {';
  source = source.replaceAll('\r\n', '\n');
  assert(source.includes(marker));
  source = source.replace(marker, marker + `
      if ($this->apiKey === 'fixture-user-a-key') { $owner = 'i101'; }
      elseif ($this->apiKey === 'fixture-user-b-key') { $owner = 'i202'; }
      else { throw new RuntimeException('Fixture: invalid key'); }
      if ($path === '/athlete/0' || $path === '/athlete/' . $owner) {
        return ['id'=>$owner, 'timezone'=>'Europe/Vienna', 'sportSettings'=>[], 'icu_api_key'=>'PRIVATE-KEY'];
      }
      throw new RuntimeException('Fixture: unexpected path');
  `);
  await writeFile(clientPath, source);
  const router = join(temporary, 'router.php');
  await writeFile(router, `<?php $_SERVER['HTTPS']='on'; return false;`);
  const socket = createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  server = spawn(php, [...flags, '-S', `127.0.0.1:${port}`, '-t', docroot, router], { stdio: 'ignore' });
  let startupError;
  server.on('error', e => { startupError = e; });
  const origin = `http://127.0.0.1:${port}/multi/`;
  for (let i = 0; i < 60; i++) {
    if (startupError) throw startupError;
    try { await fetch(origin + 'metadata.php'); break; } catch {
      if (i === 59) throw new Error('PHP-Testserver startet nicht');
      await delay(100);
    }
  }
  const meta = await (await fetch(origin + 'metadata.php')).json();
  assert.equal(meta.issuer, 'https://coach.invalid/fit/multi');
  assert.deepEqual(meta.code_challenge_methods_supported, ['S256']);
  const missing = await fetch(origin + 'mcp.php', { method: 'POST' });
  assert.equal(missing.status, 401);
  assert.match(missing.headers.get('www-authenticate'), /resource_metadata=/);
  const verifier = 'v'.repeat(43);
  const authParams = new URLSearchParams({
    response_type: 'code', client_id: 'coach-chatgpt', redirect_uri: 'https://chatgpt.example/callback',
    scope: 'coach:read offline_access', state: 'test-session-state',
    code_challenge_method: 'S256', code_challenge: createHash('sha256').update(verifier).digest('base64url'),
    resource: 'https://coach.invalid/fit/multi/mcp.php',
  });
  const form = async (path, values, cookie) => fetch(origin + path, {
    method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...(cookie ? { Cookie: cookie } : {}) },
    body: new URLSearchParams(values),
  });
  async function connect(key) {
    const login = await fetch(origin + 'authorize.php?' + authParams);
    assert.equal(login.status, 200);
    const cookie = login.headers.get('set-cookie').split(';')[0];
    const html = await login.text();
    const flow = html.match(/name="flow" value="([^"]+)"/)[1];
    const csrf = html.match(/name="csrf" value="([^"]+)"/)[1];
    assert(!html.includes(key));
    const forged = await form('authorize.php', { flow, csrf: 'wrong', api_key: key, consent: 'yes', action: 'connect' }, cookie);
    assert.equal(forged.status, 400);
    const success = await form('authorize.php', { flow, csrf, api_key: key, consent: 'yes', action: 'connect' }, cookie);
    assert.equal(success.status, 303);
    const destination = new URL(success.headers.get('location'));
    assert.equal(destination.origin, 'https://chatgpt.example');
    assert.equal(destination.searchParams.get('state'), 'test-session-state');
    assert.equal(destination.searchParams.get('iss'), meta.issuer);
    const response = await form('token.php', {
      grant_type: 'authorization_code', code: destination.searchParams.get('code'), code_verifier: verifier,
      client_id: 'coach-chatgpt', client_secret: clientSecret,
      redirect_uri: 'https://chatgpt.example/callback', resource: authParams.get('resource'),
    });
    assert.equal(response.status, 200);
    const tokens = await response.json();
    assert(tokens.access_token && tokens.refresh_token);
    return { tokens, cookie };
  }
  const a = await connect('fixture-user-a-key');
  const b = await connect('fixture-user-b-key');
  async function rpc(tokens, method, params = {}) {
    return fetch(origin + 'mcp.php', { method: 'POST', headers: {
      'Content-Type': 'application/json', Authorization: `Bearer ${tokens.access_token}`,
    }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }) });
  }
  for (const [connection, id] of [[a, 'i101'], [b, 'i202']]) {
    const tools = await (await rpc(connection.tokens, 'tools/list')).json();
    assert.equal(tools.result.tools.length, 5);
    assert.equal(tools.result.tools[0].securitySchemes[0].type, 'oauth2');
    const result = await (await rpc(connection.tokens, 'tools/call', { name: 'get_athlete_profile', arguments: {} })).json();
    const text = result.result.content[0].text;
    assert.equal(JSON.parse(text).id, id);
    assert(!text.includes('PRIVATE-KEY'));
  }
  const refresh = await form('token.php', {
    grant_type: 'refresh_token', refresh_token: a.tokens.refresh_token,
    client_id: 'coach-chatgpt', client_secret: clientSecret, resource: authParams.get('resource'),
  });
  assert.equal(refresh.status, 200);
  const rotated = await refresh.json();
  const revoked = await form('revoke.php', { token: rotated.refresh_token, client_id: 'coach-chatgpt', client_secret: clientSecret });
  assert.equal(revoked.status, 200);
  assert.equal((await rpc(rotated, 'tools/list')).status, 401);
  assert.equal((await rpc(b.tokens, 'tools/list')).status, 200);
  const removal = await fetch(origin + 'disconnect.php', { headers: { Cookie: b.cookie } });
  const removalHtml = await removal.text();
  const csrf = removalHtml.match(/name="csrf" value="([^"]+)"/)[1];
  const removed = await form('disconnect.php', { csrf, api_key: 'fixture-user-b-key', confirm: 'yes' }, b.cookie);
  assert.equal(removed.status, 200);
  assert((await removed.text()).includes('wurden entfernt'));
  assert.equal((await rpc(b.tokens, 'tools/list')).status, 401);
  const deniedOrigin = await fetch(origin + 'metadata.php', { headers: { Origin: 'https://evil.invalid' } });
  assert.equal(deniedOrigin.status, 403);
  console.log('OK HTTP-Anmeldung mit zwei Konten, CSRF, Callback, PKCE, MCP-Aufrufe, Refresh, Widerruf und Löschen');
} finally {
  if (server && server.exitCode === null) {
    const exited = new Promise(resolve => server.once('exit', resolve));
    server.kill();
    await exited;
  }
  await rm(temporary, { recursive: true, force: true });
}
