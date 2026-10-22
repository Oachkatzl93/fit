import assert from 'node:assert/strict';

try {
  const base = new URL(process.argv[2]);
  assert.equal(base.protocol, 'https:', 'HTTPS erforderlich');
  assert(!base.username && !base.password && !base.search && !base.hash, 'Basis-URL ohne Zugangsdaten, Query und Fragment angeben');
  base.pathname = base.pathname.replace(/\/$/, '');
  const endpoint = base.href + '/mcp.php';
  const get = (url, init) => fetch(url, { ...init, redirect: 'error', signal: AbortSignal.timeout(25_000) });
  const meta = await get(base.href + '/metadata.php');
  assert.equal(meta.status, 200, 'Metadaten nicht erreichbar; PHP-/Datenbank-Konfiguration prüfen');
  const auth = await meta.json();
  assert.equal(auth.issuer, base.href);
  assert(auth.code_challenge_methods_supported.includes('S256'));
  assert(auth.token_endpoint_auth_methods_supported.includes('client_secret_post'));
  const standard = new URL('/.well-known/oauth-authorization-server' + base.pathname, base);
  const discovery = await get(standard);
  assert.equal(discovery.status, 200, 'OAuth-Discovery: Rewrite-Regel im Domain-Hauptverzeichnis prüfen');
  assert.equal((await discovery.json()).issuer, base.href);
  const resource = await (await get(base.href + '/metadata.php?kind=resource')).json();
  assert.equal(resource.resource, endpoint);
  assert.deepEqual(resource.authorization_servers, [base.href]);
  console.log('OK OAuth- und Ressourcen-Metadaten, Discovery, PKCE');
  const missing = await get(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
  assert.equal(missing.status, 401);
  assert.match(missing.headers.get('www-authenticate') || '', /resource_metadata=/);
  assert.equal((await get(endpoint)).status, 405);
  const badToken = await get(endpoint, { method: 'POST', headers: { Authorization: 'Bearer ' + 'x'.repeat(43) } });
  assert.equal(badToken.status, 401, 'Tokenprüfung fehlgeschlagen; Datenbankschema prüfen');
  const badLogin = await get(base.href + '/authorize.php?client_id=invalid');
  assert.equal(badLogin.status, 400, 'Anmeldungsvalidierung fehlgeschlagen; Datenbankschema prüfen');
  const badOrigin = await get(base.href + '/metadata.php', { headers: { Origin: 'https://untrusted.invalid' } });
  assert.equal(badOrigin.status, 403);
  for (const path of ['config.php', 'lib/auth.php', 'lib/bootstrap.php', 'lib/admin.php']) {
    assert.equal((await get(base.href + '/' + path)).status, 403, `${path}: Dateisperre prüfen`);
  }
  console.log('OK Anmeldung erforderlich, falsche Zugänge, GET, Origin und Dateischutz');
  console.log('Als Nächstes echte ChatGPT-Anmeldung und Nutzertrennung mit zwei Konten testen.');
} catch (error) {
  console.error(error instanceof assert.AssertionError ? error.message : 'FEHLER: Aufruf, HTTPS, Hosting und JSON-Antworten prüfen.');
  process.exitCode = 1;
}
