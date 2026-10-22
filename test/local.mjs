// Optionale lokale Tests mit einer vorhandenen PHP-Laufzeit, ohne echte Zugangsdaten.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, cp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { createServer } from 'node:net';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = process.argv[2] ? resolve(process.argv[2]) : 'php';
const runtime = process.argv.slice(3); // z. B. -d extension_dir=... -d extension=curl
const unit = spawnSync(php, [...runtime, join(root, 'test/unit.php')], { encoding: 'utf8' });
if (unit.error || unit.status !== 0) {
  console.error(unit.error?.message || unit.stdout + unit.stderr);
  process.exit(1);
}
process.stdout.write(unit.stdout);
const temporary = await mkdtemp(join(tmpdir(), 'coach-php-test-'));
let server;
try {
  await cp(join(root, 'server'), temporary, { recursive: true, filter: source => !source.endsWith(`${process.platform === 'win32' ? '\\' : '/'}config.php`) });
  await writeFile(join(temporary, 'config.php'), `<?php
define('INTERVALS_API_KEY', 'fixture-only');
define('INTERVALS_ATHLETE_ID', 'i12345');
define('MCP_TOKEN', '${'a'.repeat(64)}');
`);
  const socket = createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  server = spawn(php, [...runtime, '-S', `127.0.0.1:${port}`, '-t', temporary], { stdio: 'ignore' });
  let startupError;
  server.on('error', error => { startupError = error; });
  const url = `http://127.0.0.1:${port}/mcp.php?token=${'a'.repeat(64)}`;
  for (let attempt = 0; attempt < 60; attempt++) {
    if (startupError) throw startupError;
    try { await fetch(url); break; } catch {
      if (attempt === 59) throw new Error('PHP-Testserver startet nicht.');
      await delay(100);
    }
  }
  const send = (data, extraHeaders = {}) => fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', ...extraHeaders }, body: typeof data === 'string' ? data : JSON.stringify(data) });
  const message = (method, params = {}) => ({ jsonrpc: '2.0', id: 1, method, params });
  const init = await send(message('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'local', version: '1' } }));
  assert.equal(init.status, 200);
  assert.equal((await init.json()).result.protocolVersion, '2025-11-25');
  const listed = await (await send(message('tools/list'))).json();
  assert.equal(listed.result.tools.length, 5, 'curl-Erweiterung muss geladen sein');
  assert.equal((await (await send(message('ping'))).json()).id, 1);
  const notification = await send({ jsonrpc: '2.0', method: 'notifications/initialized' });
  assert.equal(notification.status, 202);
  assert.equal(await notification.text(), '');
  for (const [input, code] of [
    ['{', -32700], [[], -32600], [null, -32600], [{}, -32600],
    [message('unknown'), -32601], [message('tools/call', { name: 'unknown' }), -32602],
    [message('ping', null), -32602],
    [message('tools/call', { name: 'get_activity', arguments: { activity_id: '../x' } }), -32602],
    [message('tools/call', { name: 'get_wellness', arguments: { oldest: '2026-02-30' } }), -32602],
    [message('tools/call', { name: 'get_wellness', arguments: [] }), -32602],
  ]) assert.equal((await (await send(input)).json()).error.code, code);
  const batch = await (await send([message('ping'), { jsonrpc: '2.0', method: 'notifications/initialized' }])).json();
  assert.equal(batch.length, 1);
  assert.equal((await send([{ jsonrpc: '2.0', method: 'notifications/initialized' }])).status, 202);
  assert.equal((await fetch(url)).status, 405);
  assert.equal((await fetch(url.replace('a'.repeat(64), 'b'.repeat(64)))).status, 404);
  assert.equal((await send(message('ping'), { Origin: 'https://evil.invalid' })).status, 403);
  assert.equal((await send(message('ping'), { Origin: 'https://chatgpt.com' })).status, 200);
  assert.equal((await send(message('ping'), { 'MCP-Protocol-Version': 'unknown' })).status, 400);
  assert.equal((await send('{}', { 'Content-Type': 'text/plain' })).status, 415);
  console.log('OK HTTP-Transport, JSON-RPC, Batch, Benachrichtigungen, Token, Origin und Versionen');
  console.log('Apache-Dateischutz und echte API-Daten separat mit test/smoke.mjs prüfen.');
} finally {
  if (server && server.exitCode === null) {
    const closed = new Promise(resolve => server.once('exit', resolve));
    server.kill();
    await closed;
  }
  await rm(temporary, { recursive: true, force: true });
}
