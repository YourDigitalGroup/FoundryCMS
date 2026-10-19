// One address for the site (1.14.144): the line under Site Foundry's Website URL says
// whether other domains are redirected there, and saving a changed Website URL asks the
// server (canonical_host) to check that address at once; an unchanged save only reads
// the cached verdict. The redirect itself is server-side (tests/api/htaccess_canonical.php
// and the dispatcher suite); this covers the admin's part.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();
await p.evaluate(() => {
  localStorage.setItem('cd_user', JSON.stringify({ username: 'ann@example.com', role: 'admin' }));
  window.__canon = []; window.__canonAnswer = { ok: true, on: true, host: 'www.example.com', url: 'https://www.example.com', reason: 'verified', detail: '', changed: false };
  const base = window.apiCall;
  window.apiCall = async (action, body = {}) => { if (action === 'canonical_host') { window.__canon.push(JSON.parse(JSON.stringify(body))); return window.__canonAnswer; } return base(action, body); };
});
const line = () => p.evaluate(() => { const el = document.getElementById('si-canonical'); return { text: el.textContent, color: el.style.color }; });

// 1. what the line says
await p.evaluate(() => siCanonicalRender({ on: true, url: 'https://www.example.com', reason: 'verified' }));
let l = await line();
chk(/redirected to https:\/\/www\.example\.com — that is what the address bar shows/.test(l.text) && l.color === 'var(--green)', 'verified: "Other domains … are redirected to https://www.example.com — that is what the address bar shows." in green — ' + l.text);
await p.evaluate(() => siCanonicalRender({ on: false, url: 'https://www.example.com', reason: 'elsewhere', detail: 'another installation answers there' }));
l = await line();
chk(/does not answer as this site \(another installation answers there\)/.test(l.text) && /save again/.test(l.text) && l.color === 'var(--red)', 'elsewhere: says the address does not answer as this site, with the detail, in red — ' + l.text);
await p.evaluate(() => siCanonicalRender({ on: false, url: 'https://www.example.com', reason: 'unreachable', detail: 'Connection refused' }));
l = await line();
chk(/could not be reached from the server \(Connection refused\)/.test(l.text) && /checked again at each sign-in/.test(l.text), 'unreachable: says it could not be reached and that it is re-checked at sign-in — ' + l.text);
await p.evaluate(() => siCanonicalRender({ on: false, url: '', reason: 'no_website' }));
l = await line();
chk(/set the Website URL above/.test(l.text) && l.color === 'var(--muted)', 'no Website URL: asks for one, in the muted color — ' + l.text);

// 2. saving: a changed Website URL is checked at once (force); an unchanged one reads the cache
const s = await p.evaluate(async () => {
  _siteInfoLoaded = true; _site = { website: 'https://old.example.com' };
  document.getElementById('si-website').value = 'https://www.example.com';
  window.__canon.length = 0;
  await saveSiteInfo(); await new Promise(r => setTimeout(r, 50));
  const first = window.__canon.slice();
  await saveSiteInfo(); await new Promise(r => setTimeout(r, 50));
  return { first, second: window.__canon.slice(first.length), line: document.getElementById('si-canonical').textContent, writes: window.__writes.map(w => w.path) };
});
chk(s.writes.includes('data/site.json'), 'Site Foundry still saves data/site.json');
chk(s.first.length === 1 && s.first[0].force === true, 'a changed Website URL → canonical_host with force:true — ' + JSON.stringify(s.first));
chk(s.second.length === 1 && s.second[0].force === false, 'saving again without a change → canonical_host without force (cached verdict) — ' + JSON.stringify(s.second));
chk(/redirected to https:\/\/www\.example\.com/.test(s.line), 'and the line shows the server’s answer after the save — ' + s.line);

// 3. an Editor never asks (the action is admin-level)
const e = await p.evaluate(async () => { localStorage.setItem('cd_user', JSON.stringify({ username: 'ed@example.com', role: 'editor' })); window.__canon.length = 0; const r = await siCanonicalRefresh(true); return { r, calls: window.__canon.length }; });
chk(e.r === null && e.calls === 0, 'an Editor session does not call canonical_host');

await done('canonical host');
