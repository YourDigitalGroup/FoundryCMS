// From the pdateam.com SEO review (1.14.156):
//  - The reCAPTCHA / hCaptcha secret keys were saved into data/site.json, which
//    every visitor can download. Now a typed secret goes to the server's secret
//    store (set_secret), and no site.json write carries one — not even a copy an
//    older version left in memory.
//  - reCAPTCHA v3: Google's script loads on the visitor's first interaction, not
//    with the page (it was a hand patch on that site).
//  - Breadcrumbs: a page's "Breadcrumb name" instead of its long SEO title.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

await p.evaluate(() => {
  window.__secrets = []; window.__failSecret = false;
  const base = window.apiCall;
  window.apiCall = async (action, body = {}) => {
    if (action === 'set_secret') {
      if (window.__failSecret) { const e = new Error('You do not have access to that setting'); e.status = 403; throw e; }
      window.__secrets.push(body); return { ok: true };
    }
    return base(action, body);
  };
  window.uiConfirm = async () => true;
  localStorage.setItem('cd_user', JSON.stringify({ username: 'admin@example.com', role: 'admin' }));
});
const lastSiteWrite = () => p.evaluate(() => {
  const w = window.__writes.filter(x => x.path === 'data/site.json').pop();
  return w ? JSON.parse(w.content) : null;
});

// 1. reCAPTCHA: the typed secret goes to the secret store, never into site.json
await p.evaluate(() => {
  _site = { name: 'E2E', recaptcha: { enabled: true, siteKey: 'old-key', secret: 'legacy-secret-in-memory', version: 'v2' } };
  window.__writes = []; window.__secrets = []; window.__toasts = [];
  renderPlugins();
  document.getElementById('s-rc-site').value = 'site-key-123';
  document.getElementById('s-rc-secret').value = 'new-secret-456';
  return saveRecaptcha();
});
let w = await lastSiteWrite();
let s = await p.evaluate(() => window.__secrets);
chk(s.length === 1 && s[0].name === 'recaptcha_secret' && s[0].value === 'new-secret-456', 'a typed reCAPTCHA secret is saved to the server\'s secret store (set_secret recaptcha_secret) — ' + JSON.stringify(s));
chk(w && w.recaptcha && w.recaptcha.siteKey === 'site-key-123' && w.recaptcha.hasSecret === true && !('secret' in w.recaptcha) && !JSON.stringify(w).includes('secret-'),
  'the public site.json gets the site key and hasSecret, and no secret — neither the new one nor the copy an older version left in memory — ' + JSON.stringify(w && w.recaptcha));

// 2. saving without typing one: nothing sent to the store, still no secret in site.json
await p.evaluate(() => { window.__writes = []; window.__secrets = []; renderPlugins(); document.getElementById('s-rc-site').value = 'site-key-123'; return saveRecaptcha(); });
w = await lastSiteWrite(); s = await p.evaluate(() => window.__secrets);
chk(s.length === 0 && w && w.recaptcha.hasSecret === true && !('secret' in w.recaptcha), 'saving reCAPTCHA again without a new secret leaves the stored one and keeps hasSecret');

// 3. hCaptcha the same way
await p.evaluate(() => {
  _site.hcaptcha = { enabled: false, siteKey: '' };
  window.__writes = []; window.__secrets = []; renderPlugins();
  document.getElementById('s-hc-site').value = 'hc-site-key';
  document.getElementById('s-hc-secret').value = '0xhc-secret';
  return saveHcaptcha();
});
w = await lastSiteWrite(); s = await p.evaluate(() => window.__secrets);
chk(s.length === 1 && s[0].name === 'hcaptcha_secret' && s[0].value === '0xhc-secret' && w && w.hcaptcha.enabled === true && w.hcaptcha.hasSecret === true && !('secret' in w.hcaptcha),
  'hCaptcha: the secret goes to the store (hcaptcha_secret), site.json keeps the site key — ' + JSON.stringify(w && w.hcaptcha));

// 4. any other save of the site settings never writes a secret either
await p.evaluate(() => { _site.recaptcha.secret = 'still-in-memory'; window.__writes = []; return saveSiteInfo(); });
w = await lastSiteWrite();
chk(w && !JSON.stringify(w).includes('still-in-memory') && w.recaptcha.siteKey === 'site-key-123', 'every other site.json save leaves the secret out as well');

// 5. the store refusing the secret: nothing is saved, and it says why
await p.evaluate(() => { window.__failSecret = true; window.__writes = []; window.__toasts = []; renderPlugins(); document.getElementById('s-rc-site').value = 'site-key-999'; document.getElementById('s-rc-secret').value = 'x'; return saveRecaptcha(); });
const r5 = await p.evaluate(() => ({ writes: window.__writes.filter(x => x.path === 'data/site.json').length, toasts: window.__toasts.map(t => t.m + ' [' + t.t + ']') }));
chk(r5.writes === 0 && r5.toasts.some(m => /Save failed: You do not have access/.test(m) && /\[err\]$/.test(m)), 'when the secret can\'t be stored, the settings aren\'t saved half-way and the reason is shown — ' + JSON.stringify(r5));
await p.evaluate(() => { window.__failSecret = false; });

// 6. reCAPTCHA v3: Google's script waits for the visitor's first interaction
const page = '<!DOCTYPE html><html><head><title>t</title></head><body><form class="foundry-form"><input name="Name"></form></body></html>';
const out = await p.evaluate((page) => { _site.recaptcha = { enabled: true, siteKey: 'v3-key', version: 'v3', hasSecret: true }; return rcInjectIntoHtml(page); }, page);
chk(!/<script[^>]*src="[^"]*recaptcha\/api\.js/.test(out) && /api\.js\?render=v3-key/.test(out) && (out.match(/data-fourge-rc/g) || []).length === 2,
  'v3: the page no longer loads Google\'s script up front; a small loader is in its place (plus the key setup)');
const run = await p.evaluate(async (html) => {
  const f = document.createElement('iframe'); f.srcdoc = html; document.body.appendChild(f);
  await new Promise(r => f.onload = r);
  const d = f.contentDocument, has = () => !!d.querySelector('script[src*="recaptcha/api.js?render=v3-key"]');
  const before = has();
  f.contentWindow.dispatchEvent(new f.contentWindow.KeyboardEvent('keydown', { key: 'a' }));
  const after = has();
  f.contentWindow.dispatchEvent(new f.contentWindow.Event('scroll'));
  const count = d.querySelectorAll('script[src*="recaptcha/api.js"]').length;
  const key = d.querySelector('.foundry-form').getAttribute('data-rc-v3');
  f.remove();
  return { before, after, count, key };
}, out);
chk(!run.before && run.after && run.count === 1 && run.key === 'v3-key', 'it loads on the first interaction (a key press here), once, and forms still get the v3 key — ' + JSON.stringify(run));
const again = await p.evaluate((html) => rcInjectIntoHtml(html), out);
chk((again.match(/data-fourge-rc/g) || []).length === 2, 'publishing again replaces the loader rather than adding a second one');
const own = await p.evaluate(() => rcInjectIntoHtml('<html><head><script src="https://www.google.com/recaptcha/api.js?render=v3-key"></script></head><body></body></html>'));
chk(!/pointerdown/.test(own) && (own.match(/recaptcha\/api\.js/g) || []).length === 1, 'a page whose form already loads Google\'s script gets no second loader');
const v2 = await p.evaluate((page) => { _site.recaptcha = { enabled: true, siteKey: 'v2-key', version: 'v2' }; return rcInjectIntoHtml(page); }, page);
chk(!/data-fourge-rc/.test(v2), 'v2 (checkbox) pages are unchanged: the form loads its own widget');

// 7. breadcrumbs: the page's Breadcrumb name, else the SEO title as before
const crumbs = await p.evaluate(() => {
  _site.website = 'https://pda.example';
  const pg = { path: 'programs.html', file: 'programs.html', title: 'Programs' };
  const name = (s) => { const g = JSON.stringify(seoBuildSchema(pg, s, 'https://pda.example/programs', [])); const m = /"BreadcrumbList".*?"position":2,"name":"([^"]*)"/.exec(g); return m && m[1]; };
  return { named: name({ title: 'Driver Training Programs in Brentwood, TN | Professional Driver Agency', breadcrumbName: 'Programs' }),
           blank: name({ title: 'Driver Training Programs in Brentwood, TN | Professional Driver Agency', breadcrumbName: '  ' }) };
});
chk(crumbs.named === 'Programs' && crumbs.blank === 'Driver Training Programs in Brentwood, TN | Professional Driver Agency', 'the breadcrumb uses the page\'s Breadcrumb name, and the SEO title when it has none — ' + JSON.stringify(crumbs));
const field = await p.evaluate(() => {
  _pages = { programs: { title: 'Programs', file: 'programs.html', path: 'programs.html' } };
  _seo = { programs: { title: 'Driver Training Programs | PDA', breadcrumbName: 'Programs' } };
  try { renderSEO('programs'); } catch (e) { return 'render failed: ' + e.message; }
  const el = document.getElementById('seo-crumb');
  if (!el) return 'no field';
  const shown = el.value;
  el.value = 'Our Programs'; el.dispatchEvent(new Event('input', { bubbles: true }));
  return { shown, saved: _seo.programs.breadcrumbName };
});
chk(field && field.shown === 'Programs' && field.saved === 'Our Programs', 'Page SEO has a Breadcrumb name field: it shows the saved name and an edit is kept for Save SEO — ' + JSON.stringify(field));

await done('captcha settings');
