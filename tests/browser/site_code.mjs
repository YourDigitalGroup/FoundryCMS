// 1.14.161 — the site-wide code stays on every page:
//  - siteJsonText() writes a settings group as an object even when it arrived as a list ([]), which an older server
//    made of every empty group: whatever was saved into it (Analytics, chatbot, reCAPTCHA, brand colors) was dropped;
//  - placementsEnsure() puts back the Analytics, Custom Code and chatbot blocks, reCAPTCHA v3 and ADAptify a page has
//    lost, where Apply puts them, and only ever adds;
//  - an Admin's sign-in sweeps the whole site (placementsHealSweep, one page_markers request), a page save and a
//    revision restore do the same for their page.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const SITE = {
  name: 'E2E', website: 'http://127.0.0.1:8931', nav: [],
  analytics: {
    ga4: 'G-E2ETEST1', gtm: 'GTM-E2E1234', ads: '',
    custom: { funcHead: '', funcBody: '', anHead: '<script async src="https://example.com/an.js"></script>', anBody: '', mktHead: '', mktBody: '<script>window.__mkt=1</script>' },
    chatbot: { mode: 'embed', embed: '<script src="https://widgets.leadconnectorhq.com/loader.js" data-widget-id="e2e"></script>', testing: false }
  },
  recaptcha: { enabled: true, siteKey: '6Le2eKEY', version: 'v3', hasSecret: true, threshold: 0.5 }
};
const PAGE = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Home</title>
</head>
<body>
<main><h1>Welcome</h1><p>Text</p></main>
<footer><p>Footer</p></footer>
</body>
</html>`;

// ── siteJsonText: a settings group that arrived as a list is written as the object it is ──
let r = await p.evaluate(() => {
  _site = JSON.parse('{"name":"E2E","nav":[],"analytics":[],"recaptcha":[],"brand":[],"hiddenPages":[],"social":{"facebook":"x"}}');
  const a = gaCfg(); a.ga4 = 'G-ARRAY01'; a.custom = { anHead: '<!-- a -->' }; a.chatbot = { mode: 'embed', embed: '<div></div>', testing: false };
  _site.recaptcha.siteKey = 'k1'; _site.recaptcha.enabled = true; _site.recaptcha.secret = 'never';
  const out = JSON.parse(siteJsonText());
  return out;
});
chk(r.analytics && !Array.isArray(r.analytics) && r.analytics.ga4 === 'G-ARRAY01' && r.analytics.custom.anHead === '<!-- a -->' && r.analytics.chatbot.mode === 'embed',
  'Analytics settings saved into a group that arrived as [] are written (they used to vanish: JSON.stringify writes no named fields of a list) — ' + JSON.stringify(r.analytics));
chk(r.recaptcha && !Array.isArray(r.recaptcha) && r.recaptcha.siteKey === 'k1' && r.recaptcha.enabled === true && !('secret' in r.recaptcha),
  '…so are the reCAPTCHA settings, still without the secret — ' + JSON.stringify(r.recaptcha));
chk(r.brand && !Array.isArray(r.brand) && Object.keys(r.brand).length === 0 && Array.isArray(r.nav) && Array.isArray(r.hiddenPages) && r.social.facebook === 'x',
  'an empty group written as [] becomes {} again; real lists (nav, hiddenPages) stay lists and other groups are untouched');

// ── placementsWanted: what the settings place, never creating a group ──
r = await p.evaluate((SITE) => {
  _site = { name: 'Bare' };
  const none = placementsWanted();
  const created = Object.keys(_site);
  const w = placementsWanted(JSON.parse(JSON.stringify(SITE)));
  return { none, created, keys: Object.keys(w).sort(), rc: w.rc, stillBare: Object.keys(_site) };
}, SITE);
chk(Object.keys(r.none).length === 0 && r.created.join() === 'name', 'a site with no Analytics or reCAPTCHA settings wants nothing, and asking creates no settings group — ' + JSON.stringify(r.created));
chk(r.keys.join() === 'cb,ccBody,ccHead,ga,gaBody,rc' && r.rc === '6Le2eKEY' && r.stillBare.join() === 'name',
  'with GA4 + GTM, Custom Code, a chatbot and reCAPTCHA v3: all six placements, read from the settings given without touching the loaded ones — ' + r.keys.join());

// ── placementsEnsure: a page that lost everything gets it back where Apply puts it ──
r = await p.evaluate(({ SITE, PAGE }) => {
  _site = JSON.parse(JSON.stringify(SITE));
  const want = Object.assign(placementsWanted(), { ada: true });
  const res = placementsEnsure(PAGE, want);
  const h = res.html;
  const pos = s => h.indexOf(s);
  const appliedGa = gaApplySnippets(PAGE), appliedRc = rcInjectIntoHtml(PAGE);   // what Save & Apply write
  const rcText = html => [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('script[data-fourge-rc]')].map(s => s.textContent);
  const blockText = (html, s, e) => { const i = html.indexOf(s), j = html.indexOf(e, i); return i < 0 || j < 0 ? null : html.slice(i, j + e.length); };
  const same = [[GA_HEAD_START, GA_HEAD_END], [CC_HEAD_START, CC_HEAD_END], [CB_HEAD_START, CB_HEAD_END], [GA_BODY_START, GA_BODY_END], [CC_BODY_START, CC_BODY_END]]
    .every(([s, e]) => blockText(h, s, e) !== null && blockText(h, s, e) === blockText(appliedGa, s, e));
  const again = placementsEnsure(h, want);
  return {
    added: res.added.slice().sort(), same, rcSame: JSON.stringify(rcText(h)) === JSON.stringify(rcText(appliedRc)) && rcText(h).length === 2,
    headOrder: pos('<head>') < pos(GA_HEAD_START) && pos(GA_HEAD_END) < pos(CC_HEAD_START) && pos(CC_HEAD_END) < pos(CB_HEAD_START) && pos(CB_HEAD_END) < pos('<meta charset'),
    gaBody: /<body>\n<!-- foundry-analytics-body:start -->/.test(h),
    bodyEnd: pos('</footer>') < pos(CC_BODY_START) && pos(CC_BODY_END) < pos('/adaptify.js') && pos('/adaptify.js') < pos('</body>'),
    rcHead: pos('data-fourge-rc') > pos('<title>') && h.lastIndexOf('data-fourge-rc') < pos('</head>'),
    again: again.html === h && again.added.length === 0,
    content: h.includes('<main><h1>Welcome</h1><p>Text</p></main>'),
  };
}, { SITE, PAGE });
chk(r.added.join() === 'ada,cb,ccBody,ccHead,ga,gaBody,rc', 'a page that lost all of it gets every placement back — ' + r.added.join());
chk(r.same && r.rcSame, '…each block exactly as Save & Apply writes it (Analytics, GTM noscript, Custom Code head and body, chatbot, the two reCAPTCHA tags)');
chk(r.headOrder && r.gaBody && r.bodyEnd && r.rcHead && r.content, '…where Apply puts it: Analytics, Custom Code, chatbot right after <head>; the GTM noscript right after <body>; body code and ADAptify before </body>; reCAPTCHA at the end of <head>; the content untouched');
chk(r.again, 'a page that has it all is returned exactly as it was (nothing is added twice)');

r = await p.evaluate(({ SITE, PAGE }) => {
  _site = JSON.parse(JSON.stringify(SITE));
  const want = placementsWanted();
  const full = rcInjectIntoHtml(gaApplySnippets(PAGE));
  // only the chatbot block lost (the middle one in the head goes back between its neighbours)
  const s = full.indexOf(CC_HEAD_START), e = full.indexOf(CC_HEAD_END) + CC_HEAD_END.length;
  const noCc = full.slice(0, s) + full.slice(e + 1);
  const r1 = placementsEnsure(noCc, want);
  const ccOk = r1.added.join() === 'ccHead' && r1.html.indexOf(GA_HEAD_END) < r1.html.indexOf(CC_HEAD_START) && r1.html.indexOf(CC_HEAD_END) < r1.html.indexOf(CB_HEAD_START);
  // a block the settings no longer place is never taken off, and one already there is never rewritten
  _site.analytics.custom = {}; _site.analytics.chatbot = { mode: 'none', embed: '', testing: false };
  const handEdited = full.replace('an.js', 'an.js?v=hand');
  const r2 = placementsEnsure(handEdited, placementsWanted());
  // a page that already loads Google's reCAPTCHA script (a placed form) gets only the boot tag
  const withForm = PAGE.replace('</body>', '<script src="https://www.google.com/recaptcha/api.js?render=6Le2eKEY"></script></body>');
  const r3 = placementsEnsure(withForm, { rc: '6Le2eKEY' });
  // not a whole page: untouched
  const frag = '<p>just a fragment</p>';
  const r4 = placementsEnsure(frag, placementsWanted(JSON.parse(JSON.stringify(SITE))));
  return { ccOk, kept: r2.html === handEdited && r2.added.length === 0, rcOne: r3.added.join() === 'rc' && (r3.html.match(/data-fourge-rc/g) || []).length === 1, frag: r4.html === frag && !r4.added.length };
}, { SITE, PAGE });
chk(r.ccOk, 'only the Custom Code block lost: it goes back between the Analytics and chatbot blocks');
chk(r.kept, 'it only adds: a block the settings no longer place stays on the page, and one already there (even edited by hand) is left as it is');
chk(r.rcOne, 'a page that already loads Google\'s reCAPTCHA script (a placed form) gets only the boot tag, as Apply does');
chk(r.frag, 'text that is not a whole page (no <head> or <body>) is left alone');

// ── the sign-in sweep: one page_markers request, then only the pages that lost something ──
const setupSite = (SITE, PAGE, extra) => {
  _site = { name: 'E2E' };
  window.__files = {};
  window.__files['data/site.json'] = JSON.stringify(SITE);
  window.__files['data/adaptify.json'] = JSON.stringify({ enabled: true });
  _site = JSON.parse(JSON.stringify(SITE));
  const full = rcInjectIntoHtml(gaApplySnippets(PAGE));   // a page as Save & Apply left it
  _site = { name: 'E2E' };
  window.__files['full.html'] = full.replace('</body>', '<script src="/adaptify.js" defer=""></script></body>');
  window.__files['lost.html'] = PAGE;
  window.__files['half.html'] = window.__files['full.html'].replace(/<!-- foundry-chatbot:start -->[\s\S]*?<!-- foundry-chatbot:end -->\n?/, '').replace(/<script data-fourge-rc[^>]*>[\s\S]*?<\/script>/g, '');
  window.__files['data/rev_1.html'] = PAGE;
  Object.assign(window.__files, extra || {});
  window.__writes = []; window.__toasts = []; window.__reads = [];
  const api = window.apiCall;
  window.apiCall = async (action, body = {}) => {
    if (action === 'page_markers') {   // as the server answers it
      window.__markerCalls = (window.__markerCalls || 0) + 1;
      return { ok: true, pages: Object.keys(window.__files).filter(f => /\.html$/.test(f) && !f.startsWith('data/')).map(f => ({ path: f, has: body.markers.map((m, i) => window.__files[f].includes(m) ? i : -1).filter(i => i >= 0) })) };
    }
    if (action === 'read_file') window.__reads.push(body.path);
    return api(action, body);
  };
};
r = await p.evaluate(async ({ SITE, PAGE, setup }) => {
  eval('(' + setup + ')')(SITE, PAGE);
  const full = window.__files['full.html'];
  const res = await placementsHealSweep({ force: true });
  const lost = window.__files['lost.html'], half = window.__files['half.html'];
  const all = h => [GA_HEAD_START, GA_BODY_START, CC_HEAD_START, CC_BODY_START, CB_HEAD_START, 'data-fourge-rc', '/adaptify.js'].every(m => h.includes(m));
  return {
    res, writes: window.__writes.map(w => w.path).sort(), reads: window.__reads.filter(x => /\.html$/.test(x)).sort(),
    lostOk: all(lost), halfOk: all(half) && (half.match(/foundry-chatbot:start/g) || []).length === 1, fullSame: window.__files['full.html'] === full,
    toast: (window.__toasts.pop() || {}).m || '', calls: window.__markerCalls,
  };
}, { SITE, PAGE, setup: setupSite.toString() });
chk(r.res && r.res.fixed === 2 && r.writes.join() === 'half.html,lost.html' && r.lostOk && r.halfOk,
  'an Admin\'s sign-in puts the site-wide code back on the two pages that lost it (all of it on one, the chatbot and reCAPTCHA on the other) — ' + JSON.stringify(r.res && r.res.pages));
chk(r.fullSame && r.reads.join() === 'half.html,lost.html' && r.calls === 1, '…one page_markers request finds them; the page that has it all is not even read, let alone written — reads: ' + r.reads.join());
chk(/back on 2 pages that had lost them/.test(r.toast) && /the chatbot/.test(r.toast) && /reCAPTCHA/.test(r.toast), 'and says what it put back, and on how many pages — "' + r.toast + '"');
r = await p.evaluate(async () => { window.__writes = []; const res = await placementsHealSweep({ force: true, silent: true }); return { res, writes: window.__writes.length }; });
chk(r.res && r.res.fixed === 0 && r.writes === 0, 'run again: nothing left to put back, nothing written');

r = await p.evaluate(async ({ PAGE, setup }) => {
  eval('(' + setup + ')')({ name: 'No code', nav: [] }, PAGE, { 'data/adaptify.json': JSON.stringify({ enabled: false }) });
  window.__markerCalls = 0;
  const res = await placementsHealSweep({ force: true });
  return { res, writes: window.__writes.length, calls: window.__markerCalls };
}, { PAGE, setup: setupSite.toString() });
chk(r.res && r.res.fixed === 0 && r.writes === 0 && r.calls === 0, 'a site whose settings place nothing (and ADAptify off): no scan, nothing written');

r = await p.evaluate(async ({ SITE, PAGE, setup }) => {
  eval('(' + setup + ')')(SITE, PAGE);
  delete window.__files['full.html'];   // ADAptify on in its settings, but on no page: it was never installed
  window.__files['half.html'] = window.__files['half.html'].replace('<script src="/adaptify.js" defer=""></script>', '');
  const res = await placementsHealSweep({ force: true, silent: true });
  return { res, ada: Object.keys(window.__files).filter(f => /\.html$/.test(f) && !f.startsWith('data/') && window.__files[f].includes('/adaptify.js')) };
}, { SITE, PAGE, setup: setupSite.toString() });
chk(r.res && r.res.fixed === 2 && r.ada.length === 0, 'ADAptify switched on but installed on no page is not installed by the sweep (only put back where the site has it)');

r = await p.evaluate(async () => {
  try { sessionStorage.removeItem('fourge_place_ok'); } catch (e) {}
  window.__writes = []; window.__markerCalls = 0;
  const res = await placementsHealSweep();   // the harness signs in as no one in particular: not an Admin
  return { res, calls: window.__markerCalls };
});
chk(r.res === null && r.calls === 0, 'without an Admin signed in the sweep does not run');

// ── "Remove from All Pages" takes the code off by choice, settings kept: nothing puts it back until Save & Apply ──
r = await p.evaluate(async ({ SITE, PAGE }) => {
  _site = JSON.parse(JSON.stringify(SITE));
  const full = rcInjectIntoHtml(gaApplySnippets(PAGE));
  window.__files = { 'data/site.json': JSON.stringify(SITE), 'p1.html': full };
  _pages = { p1: { path: 'p1.html', file: 'p1.html', title: 'P1' } };
  window.__writes = []; window.__toasts = [];
  await gaRemoveAll();
  const stripped = window.__files['p1.html'];
  const site1 = JSON.parse(window.__files['data/site.json']);
  const st = document.getElementById('ga-inject-status');
  let note = '';
  try { if (st) st.textContent = ''; initAnalytics(); note = st ? st.textContent : ''; } catch (e) { note = 'ERR ' + e.message; }
  const want = Object.keys(placementsWanted()).join();
  const sweep = await placementsHealSweep({ force: true, silent: true });
  const afterSweep = window.__files['p1.html'];
  const saved = veFinalizePageHtml(stripped, null);
  await gaInjectAll();
  const back = window.__files['p1.html'];
  const site2 = JSON.parse(window.__files['data/site.json']);
  const blocks = h => [GA_HEAD_START, CC_HEAD_START, CB_HEAD_START].map(m => h.includes(m));
  return {
    stripped: blocks(stripped), kept: site1.analytics.ga4 === 'G-E2ETEST1' && site1.analytics.offPages === true, note, want,
    sweep: sweep && sweep.fixed === 0 && afterSweep === stripped, saved: blocks(saved), back: blocks(back), cleared: !('offPages' in site2.analytics),
  };
}, { SITE, PAGE });
chk(!r.stripped.some(Boolean) && r.kept && /Removed from all pages/.test(r.note),
  '"Remove from All Pages" takes the Analytics, Custom Code and chatbot code off every page, keeps the settings and remembers the choice (offPages), and Analytics says so — "' + r.note + '"');
chk(r.want === 'rc' && r.sweep && !r.saved.some(Boolean), '…so neither an Admin\'s sign-in nor a page save puts it back (reCAPTCHA, which has its own switch, still counts) — wanted: ' + r.want);
chk(r.back.every(Boolean) && r.cleared, '…until Save & Apply, which puts it back on every page and forgets the choice');

// ── a page save and a revision restore put back what that page lost ──
r = await p.evaluate(({ SITE, PAGE }) => {
  _site = JSON.parse(JSON.stringify(SITE));
  const out = veFinalizePageHtml(PAGE, null);
  _site = { name: 'E2E' };
  const bare = veFinalizePageHtml(PAGE, null);
  _site = {};
  const unloaded = veFinalizePageHtml(PAGE, null);
  const has = h => [GA_HEAD_START, CC_HEAD_START, CB_HEAD_START, 'data-fourge-rc'].map(m => h.includes(m));
  return { out: has(out), bare: has(bare), unloaded: has(unloaded) };
}, { SITE, PAGE });
chk(r.out.every(Boolean), 'a page saved through the editor (veFinalizePageHtml) gets back the Analytics, Custom Code, chatbot and reCAPTCHA code it had lost — ' + JSON.stringify(r.out));
chk(!r.bare.some(Boolean) && !r.unloaded.some(Boolean), '…and nothing when the settings place none, or have not loaded');

r = await p.evaluate(async ({ SITE, PAGE }) => {
  _site = JSON.parse(JSON.stringify(SITE));
  window.__files['data/rev_77.html'] = PAGE; window.__writes = [];
  window.uiConfirm = async () => true;
  await restoreRevision('77', 'home.html', 'home');
  const w = window.__writes.filter(x => x.path === 'home.html').pop();
  return w ? [GA_HEAD_START, CB_HEAD_START, 'data-fourge-rc', '<main><h1>Welcome</h1><p>Text</p></main>'].map(m => w.content.includes(m)) : null;
}, { SITE, PAGE });
chk(r && r.every(Boolean), 'restoring a revision from before the code was placed brings back the page\'s content and keeps its Analytics, chatbot and reCAPTCHA — ' + JSON.stringify(r));

// rcInjectIntoHtml (Save on reCAPTCHA) writes the same two tags as before
r = await p.evaluate(() => {
  _site = { recaptcha: { enabled: true, siteKey: 'K-1', version: 'v3' } };
  const d = new DOMParser().parseFromString(rcInjectIntoHtml('<!DOCTYPE html><html><head></head><body></body></html>'), 'text/html');
  const s = [...d.querySelectorAll('script[data-fourge-rc]')].map(x => x.textContent);
  _site = { recaptcha: { enabled: true, siteKey: 'K-1', version: 'v2' } };
  const v2 = rcInjectIntoHtml('<!DOCTYPE html><html><head></head><body></body></html>');
  return { ok: s.length === 2 && s[0] === rcBootJs('K-1') && s[1] === rcLoaderJs('K-1') && /window.__fourgeRcSiteKey="K-1"/.test(s[0]) && /render=K-1/.test(s[1]), v2: !/data-fourge-rc/.test(v2) };
});
chk(r.ok && r.v2, 'reCAPTCHA\'s own Save writes the same two tags (v3), and none for v2');

await done('site code');
