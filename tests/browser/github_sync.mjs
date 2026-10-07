// GitHub sync in the admin (1.14.141): the one-commit protocol (cumulative counts per run,
// the run id handed back), the older per-batch protocol still summed, a rejected token
// reported in one sentence, an api.php that does not know gh_sync_all reported as out of
// date with the install button (and the api banner raised), Test Connection through
// gh_test with the token expiry on the Settings line, the daily tick's expiry warning,
// and [skip ci] on every commit the browser itself makes.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();
await p.evaluate(() => {
  localStorage.setItem('cd_user', JSON.stringify({ username: 't', role: 'admin' }));
  window.__calls = [];
  window.__gh = { sync: [], priv: { ok: true, already: true }, test: null, ping: { ok: true, api_version: '1.14.141' } };
  const answer = h => { if (!h) throw new Error('no stub answer'); if (h.__throw) throw new Error(h.__throw); return h; };
  window.apiCall = async (action, body = {}) => {
    window.__calls.push({ action, body: JSON.parse(JSON.stringify(body)) });
    if (action === 'gh_sync_all') return answer(window.__gh.sync.shift());
    if (action === 'gh_set_private') return answer(window.__gh.priv);
    if (action === 'gh_test') return answer(window.__gh.test);
    if (action === 'ping') return answer(window.__gh.ping);
    return { ok: true };
  };
  document.getElementById('api-banner').classList.remove('show');
});
const run = (setup) => p.evaluate(async (setup) => {
  window.__toasts.length = 0; window.__calls.length = 0;
  Object.assign(window.__gh, setup);
  await ghSyncNow();
  return { status: document.getElementById('s-gh-sync-status').textContent, toasts: window.__toasts.slice(), syncBodies: window.__calls.filter(c => c.action === 'gh_sync_all').map(c => c.body), privCalls: window.__calls.filter(c => c.action === 'gh_set_private').length, banner: document.getElementById('api-banner').classList.contains('show'), bannerMsg: document.getElementById('api-msg').textContent, installBtn: !!document.getElementById('s-gh-install-api') };
}, setup);

// 1. the one-commit protocol: counts are cumulative per run (replaced, not summed); the run id goes back
let r = await run({ sync: [
  { ok: true, run: 'r1', total: 3, done: 2, next: 2, staged: 1, pushed: 0, upToDate: 1, failed: 0, skipped: 0, errors: [] },
  { ok: true, run: 'r1', total: 3, done: 3, next: null, staged: 0, pushed: 2, upToDate: 1, failed: 0, skipped: 0, errors: [], commit: 'abcdef1234567890', tokenExpires: '2099-01-01 00:00:00 UTC' },
], priv: { ok: true, already: true } });
chk(r.syncBodies.length === 2 && r.syncBodies[0].offset === 0 && !('run' in r.syncBodies[0]) && r.syncBodies[1].offset === 2 && r.syncBodies[1].run === 'r1', 'the second batch is asked for with offset 2 and the run id the server handed out — ' + JSON.stringify(r.syncBodies));
chk(/3 files checked — 2 pushed in one commit \(abcdef1\), 1 already current\. Repo: already private\./.test(r.status), 'status: "3 files checked — 2 pushed in one commit (abcdef1), 1 already current. Repo: already private." — got: ' + r.status);
chk(/Token expires /.test(r.status), 'status ends with the token expiry — ' + r.status);
chk(r.toasts.some(t => t.m === 'GitHub sync: 2 pushed in one commit, 1 already current' && t.t === 'ok'), 'toast: "GitHub sync: 2 pushed in one commit, 1 already current" — ' + JSON.stringify(r.toasts));
chk(r.privCalls === 1 && !r.banner, 'the repo is set private afterwards; no api banner');

// 2. the older per-batch protocol (an api.php before 1.14.141): counts are summed
r = await run({ sync: [
  { ok: true, total: 3, done: 2, next: 2, pushed: 1, upToDate: 1, failed: 0, skipped: 0, errors: [] },
  { ok: true, total: 3, done: 3, next: null, pushed: 1, upToDate: 0, failed: 0, skipped: 0, errors: [] },
] });
chk(/3 files checked — 2 pushed, 1 already current\. Repo: already private\./.test(r.status) && !/one commit/.test(r.status), 'per-batch answers (no run id) are summed: "2 pushed, 1 already current" — got: ' + r.status);

// 3. a rejected token: one sentence, on the first answer
const bad = 'GitHub sync did not start: GitHub rejected the saved token (401 Bad credentials) — it has expired or been revoked. Create a new token and save it under Settings → GitHub Integration.';
r = await run({ sync: [{ ok: false, reason: 'bad_token', error: bad }], priv: { ok: false, reason: 'bad_token', error: 'Could not confirm the repo is private: GitHub rejected the saved token (401 Bad credentials) — it has expired or been revoked. Create a new token and save it under Settings → GitHub Integration.' } });
chk(r.status.startsWith(bad), 'status line IS the explanation (no count of a thousand failures) — got: ' + r.status);
chk(r.toasts.some(t => t.m === bad && t.t === 'err'), 'the explanation is also toasted');
chk(r.syncBodies.length === 1, 'the client stops after the first answer');

// 4. an api.php that does not know gh_sync_all: out of date, with the install button; the api banner is raised with the versions
r = await run({ sync: [{ __throw: 'Unknown action: gh_sync_all' }], ping: { ok: true, api_version: '1.14.120' } });
chk(/admin\/api\.php on this server is out of date/.test(r.status) && /gh_sync_all/.test(r.status) && r.installBtn, 'status: api.php is out of date (names the action) with an "Install api.php now" button — got: ' + r.status);
chk(r.privCalls === 0, 'gh_set_private is not attempted on a stale api.php (it would fail the same way)');
chk(r.banner && /version 1\.14\.120/.test(r.bannerMsg) && /Unknown action/.test(r.bannerMsg), 'the api banner is up and says the server is version 1.14.120 — ' + r.bannerMsg);
chk(r.toasts.some(t => /out of date/.test(t.m) && t.t === 'err'), 'and a toast says so');

// 5. the server lost the run (another admin synced meanwhile): restarted once from the top
r = await run({ sync: [
  { ok: true, run: 'r2', total: 2, done: 1, next: 1, staged: 1, pushed: 0, upToDate: 0, failed: 0, skipped: 0, errors: [] },
  { ok: false, reason: 'restart', error: 'This sync run has expired — start it again from the beginning.' },
  { ok: true, run: 'r3', total: 2, done: 2, next: null, staged: 0, pushed: 2, upToDate: 0, failed: 0, skipped: 0, errors: [], commit: '0123456789' },
], ping: { ok: true, api_version: '1.14.141' } });
chk(r.syncBodies.length === 3 && r.syncBodies[2].offset === 0 && !('run' in r.syncBodies[2]) && /2 pushed in one commit \(0123456\)/.test(r.status), 'a "restart" answer starts the run again once, from offset 0 without a run id — ' + JSON.stringify(r.syncBodies) + ' / ' + r.status);

// 6. Test Connection → gh_test: who, private, can push, expiry (red inside two weeks)
const tenDays = new Date(Date.now() + 10 * 86400000 + 3600000).toISOString().slice(0, 19).replace('T', ' ') + ' UTC';
let t = await p.evaluate(async (exp) => {
  _site.github = { repo: 'org/site', branch: 'main' }; _secretStatus.github_pat = true;
  window.__toasts.length = 0;
  window.__gh.test = { ok: true, login: 'octo', repo: 'org/site', branch: 'main', private: true, canPush: true, expires: exp, scopes: '' };
  await testGH();
  const el = document.getElementById('s-repo-info');
  return { html: el.innerHTML, text: el.textContent, toasts: window.__toasts.slice() };
}, tenDays);
chk(/connected as <strong>octo<\/strong>/.test(t.html) && /· private ·/.test(t.text) && /token can push/.test(t.text), 'Settings line: "connected as octo · private · token can push" — ' + t.text);
chk(/expires in 10 days/.test(t.text) && /color:var\(--red\)[^>]*>The GitHub token expires in 10 days/.test(t.html), 'the expiry inside two weeks is shown in red — ' + t.text);
chk(t.toasts.some(x => /Connected as octo → org\/site/.test(x.m) && x.t === 'ok'), 'toast: Connected as octo → org/site');
t = await p.evaluate(async () => {
  window.__toasts.length = 0;
  window.__gh.test = { ok: false, reason: 'bad_token', error: 'Token check failed: GitHub rejected the saved token (401 Bad credentials) — it has expired or been revoked. Create a new token and save it under Settings → GitHub Integration.' };
  await testGH();
  return { text: document.getElementById('s-repo-info').textContent, toasts: window.__toasts.slice() };
});
chk(/Token check failed: GitHub rejected the saved token/.test(t.text) && t.toasts.some(x => x.t === 'err' && /rejected the saved token/.test(x.m)), 'a rejected token is written on the Settings line and toasted — ' + t.text);

// 7. the daily tick warns about a token that is about to expire (and runs the sync once a day)
const fiveDays = new Date(Date.now() + 5 * 86400000 + 3600000).toISOString().slice(0, 19).replace('T', ' ') + ' UTC';
let d = await p.evaluate(async (exp) => {
  localStorage.removeItem('fourge_gh_daily'); window.__toasts.length = 0;
  window.__gh.sync = [{ ok: true, run: 'r4', total: 1, done: 1, next: null, staged: 0, pushed: 0, upToDate: 1, failed: 0, skipped: 0, errors: [], commit: null, tokenExpires: exp }];
  const a = await ghDailySyncTick(); const b = await ghDailySyncTick();
  return { a, b, toasts: window.__toasts.slice() };
}, fiveDays);
chk(d.a.ran === true && d.b.ran === false && d.b.reason === 'done_today', 'the daily tick runs once a day');
chk(d.toasts.some(x => /expires in 5 days/.test(x.m) && x.t === 'err'), 'and warns when the token expires within two weeks — ' + JSON.stringify(d.toasts));

// 8. commits the browser makes itself carry [skip ci]
const w = await p.evaluate(async () => {
  _secrets.github_pat = 'x'; _site.github = { repo: 'org/site', branch: 'main' };
  const seen = [];
  const realFetch = window.fetch;
  window.fetch = async (url, init) => { seen.push({ url, body: init && init.body ? JSON.parse(init.body) : null, method: init && init.method }); return new Response('{"content":{"sha":"s"}}', { status: 201, headers: { 'Content-Type': 'application/json' } }); };
  try {
    await ghWrite('x.html', '<p>hi</p>', null, 'Fourge: add page "x"');
    await ghBin('a.png', new Uint8Array([1, 2, 3]).buffer, 'abc', 'Fourge: upload a.png');
  } finally { window.fetch = realFetch; }
  return { seen, skip: [ghSkipCi('Fourge: x'), ghSkipCi('Fourge: x [skip ci]'), ghSkipCi('')] };
});
chk(w.seen.length === 2 && w.seen[0].body.message === 'Fourge: add page "x" [skip ci]' && w.seen[1].body.message === 'Fourge: upload a.png [skip ci]', 'ghWrite/ghBin commit messages end with [skip ci] — ' + JSON.stringify(w.seen.map(s => s.body && s.body.message)));
chk(w.skip[0] === 'Fourge: x [skip ci]' && w.skip[1] === 'Fourge: x [skip ci]' && w.skip[2] === 'Fourge: update [skip ci]', 'ghSkipCi adds the marker once and gives an empty message a default');

await done('github sync');
