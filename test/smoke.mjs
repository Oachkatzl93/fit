import assert from 'node:assert/strict';

const input = process.argv[2] || process.env.MCP_URL;
if (!input) {
  console.error('Aufruf: node test/smoke.mjs <HTTPS-MCP-URL> [activity_id]\nAlternativ MCP_URL als Umgebungsvariable setzen.');
  process.exit(1);
}

let endpoint;
try {
  endpoint = new URL(input);
  assert.equal(endpoint.protocol, 'https:', 'HTTPS erforderlich');
  assert(!endpoint.username && !endpoint.password && !endpoint.hash, 'Ungültige URL');
} catch {
  console.error('Eine gültige HTTPS-MCP-URL angeben.');
  process.exit(1);
}

let sequence = 0;
let version;
const headers = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json, text/event-stream',
  ...(version ? { 'MCP-Protocol-Version': version } : {}),
});
async function request(url, options = {}) {
  try {
    return await fetch(url, { ...options, redirect: 'error', signal: AbortSignal.timeout(55_000) });
  } catch {
    throw new Error('Netzwerkfehler oder Zeitlimit; HTTPS, URL und Hosting prüfen.');
  }
}
async function rpc(method, params = {}, expectedError) {
  const id = ++sequence;
  const response = await request(endpoint, { method: 'POST', headers: headers(), body: JSON.stringify({ jsonrpc: '2.0', id, method, params }) });
  assert.equal(response.status, 200, `${method}: HTTP ${response.status}`);
  assert.match(response.headers.get('content-type') || '', /application\/json/);
  let data;
  try { data = await response.json(); } catch { throw new Error(`${method}: ungültiges JSON`); }
  assert.equal(data.jsonrpc, '2.0');
  assert.equal(data.id, id);
  if (expectedError !== undefined) {
    assert.equal(data.error?.code, expectedError, `${method}: erwarteter RPC-Fehler fehlt`);
    return;
  }
  assert(!data.error, `${method}: RPC-Fehler ${data.error?.code}`);
  return data.result;
}
async function tool(name, args = {}) {
  const result = await rpc('tools/call', { name, arguments: args });
  assert.equal(result.isError, false, `${name}: intervals.icu-Fehler; API-Key, Athlete-ID und Rechte prüfen`);
  assert.equal(result.content?.[0]?.type, 'text');
  let data;
  try { data = JSON.parse(result.content[0].text); } catch { throw new Error(`${name}: Ergebnis ist kein JSON`); }
  console.log(`OK ${name}`);
  return data;
}

try {
  const init = await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'coach-smoke', version: '1.0.0' } });
  assert(['2025-11-25', '2025-06-18', '2025-03-26'].includes(init.protocolVersion));
  assert(init.capabilities.tools);
  version = init.protocolVersion;
  console.log('OK initialize');
  const notification = await request(endpoint, { method: 'POST', headers: headers(), body: JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) });
  assert.equal(notification.status, 202);
  assert.equal(await notification.text(), '');
  await rpc('ping');
  const { tools } = await rpc('tools/list');
  const names = ['get_athlete_profile', 'list_activities', 'get_activity', 'get_wellness', 'get_calendar'];
  assert.deepEqual(tools.map(t => t.name).sort(), [...names].sort());
  for (const t of tools) assert.equal(t.annotations.readOnlyHint, true);
  console.log('OK tools/list, ping, initialized');
  const profile = await tool('get_athlete_profile');
  assert(profile.id, 'Profil ohne Athlete-ID');
  assert(!Object.hasOwn(profile, 'icu_api_key'), 'Profil darf keinen API-Key enthalten');
  const activities = await tool('list_activities');
  assert(Array.isArray(activities.activities));
  const wellness = await tool('get_wellness');
  assert(Array.isArray(wellness.wellness));
  const calendar = await tool('get_calendar');
  assert(Array.isArray(calendar.events));
  if (!wellness.wellness.length) console.log('HINWEIS: Keine Wellnessdaten im Standardzeitraum.');
  if (!calendar.events.length) console.log('HINWEIS: Keine Kalendereinträge im Standardzeitraum.');
  const activityId = process.argv[3] || activities.activities.find(a => a.id)?.id;
  assert(activityId, 'Keine Aktivität in den letzten 14 Tagen. Für den vollständigen Test eine eigene activity_id als zweites Argument angeben.');
  const activity = await tool('get_activity', { activity_id: String(activityId) });
  assert.equal(String(activity.activity.id), String(activityId));
  assert(activity.intervals && typeof activity.intervals === 'object');
  await rpc('unbekannt', {}, -32601);
  await rpc('tools/call', { name: 'get_wellness', arguments: { oldest: '2026-02-30' } }, -32602);
  await rpc('tools/call', { name: 'get_wellness', arguments: { oldest: '2026-01-01', newest: '2026-04-01' } }, -32602);
  const get = await request(endpoint);
  assert.equal(get.status, 405, 'GET muss 405 liefern');
  const wrong = new URL(endpoint);
  if (wrong.searchParams.has('token')) wrong.searchParams.set('token', '0'.repeat(64));
  else wrong.pathname = wrong.pathname.replace(/[^/]+$/, '0'.repeat(64));
  assert.notEqual(wrong.href, endpoint.href, 'Falschtoken-Test benötigt anderen Token');
  assert.equal((await request(wrong, { method: 'POST', headers: headers(), body: '{}' })).status, 404);
  const blockedOrigin = await request(endpoint, { method: 'POST', headers: { ...headers(), Origin: 'https://untrusted.invalid' }, body: '{}' });
  assert.equal(blockedOrigin.status, 403);
  const base = new URL(endpoint);
  if (/\/mcp\/[^/]+$/.test(base.pathname)) base.pathname = base.pathname.replace(/mcp\/[^/]+$/, '');
  else base.pathname = base.pathname.replace(/mcp\.php$/, '');
  base.search = '';
  for (const path of ['config.php', 'config.example.php', 'lib/intervals.php', 'lib/tools.php']) {
    assert.equal((await request(new URL(path, base))).status, 403, `${path} muss 403 liefern; .htaccess prüfen`);
  }
  console.log('OK Validierung, HTTP-Methoden, Token, Origin und Dateischutz\nAlle Prüfungen bestanden. Trainingsdaten und URL werden nicht ausgegeben.');
} catch (error) {
  // Keine fetch-Fehler samt geheimer URL und keine Tool-Nutzdaten protokollieren.
  console.error(`FEHLER: ${error.message}`);
  process.exitCode = 1;
}
