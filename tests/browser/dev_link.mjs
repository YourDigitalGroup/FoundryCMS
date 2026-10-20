// Editing on the dev link while the Website URL is the live domain (1.14.146).
// The Website URL is what search engines read; the files the admin edits live
// where the admin itself is served from. Everything that LOADS or OPENS the
// site's own files must use the admin's origin — a page loaded from the live
// domain is cross-origin (the editor bridge cannot touch it) or not live yet —
// while canonical output keeps the configured address.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();
const ORIGIN = 'http://127.0.0.1:' + (process.env.TEST_PORT || '8931');
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><title>Dev link</title><link rel="stylesheet" href="/tests/fixtures/elcss/style.css"></head><body>
<section class="hero"><h1 id="hero-title">Hello</h1><p>para</p><a id="about" href="about">About</a><img id="pic" src="/tests/fixtures/elcss/missing.png" alt=""></section>
</body></html>`;
await p.route(ORIGIN + '/page.html*', r => r.fulfill({ status: 200, contentType: 'text/html', body: PAGE }));
await p.evaluate(({ PAGE }) => {
  window.__files['page.html'] = PAGE;
  window.__pagesList = [{ file: 'page.html', path: 'page.html', title: 'Dev link', size: PAGE.length, modified: '', is_cms: false, snippet: '', has_post_list: false }];
  _site = { website: 'https://www.client.com' };   // the live domain — NOT where this admin runs
  goPanel('p-pages');
}, { PAGE });
await p.waitForFunction(() => { const f = document.getElementById('visual-iframe'); const d = f && f.contentDocument; return !!(_curPage && d && d.getElementById('hero-title')); }, null, { timeout: 15000 });
await p.waitForTimeout(500);
const r = await p.evaluate(() => {
  const f = document.getElementById('visual-iframe'); const d = f.contentDocument;
  const base = d && d.querySelector('base');
  return {
    src: f.src, sameOrigin: !!(d && d.getElementById('hero-title')), baseHref: base ? base.href : '',
    norm: normSiteBase(), auto: autoSiteBase(), cssBase: siteCssBase().origin, elcss: elcssSiteOrigin(),
    canonical: fourgeCanonicalBase(), pageUrl: fourgePageUrl('page.html'), domain: siteDomain(),
    privacy: privacyPolicyHTML(), origin: location.origin,
    aboutHref: d.getElementById('about').href, picSrc: d.getElementById('pic').src,
  };
});
chk(r.src.startsWith(r.origin + '/page.html'), 'the visual editor loads the page from the admin’s own origin, not from the Website URL — ' + r.src);
chk(r.sameOrigin, 'so the editor can reach the page’s document (same-origin)');
chk(r.aboutHref.startsWith(r.origin + '/') && r.picSrc.startsWith(r.origin + '/'), 'links and images inside the editor resolve against the admin’s origin — ' + r.aboutHref + ' / ' + r.picSrc);
chk(!r.baseHref || r.baseHref.startsWith(r.origin + '/'), 'no editor <base> points at the live domain — ' + (r.baseHref || '(none)'));
chk(r.norm === r.origin && r.auto === r.origin, 'normSiteBase() is the admin’s origin even with a Website URL set — ' + r.norm);
chk(r.cssBase === r.origin && r.elcss === r.origin, 'Site CSS and Element CSS look for stylesheets on the admin’s origin — ' + r.cssBase);
chk(r.canonical === 'https://www.client.com' && r.pageUrl === 'https://www.client.com/page', 'search-facing addresses keep the Website URL: canonical base and page URL — ' + r.pageUrl);
chk(r.domain === 'client.com' && r.privacy.includes('https://www.client.com') && !r.privacy.includes('127.0.0.1'), 'content written into a page (privacy policy) names the live domain, never the dev link — domain ' + r.domain);
// and with NO Website URL at all, the editor still works from its own origin, and nothing search-facing is invented
const n = await p.evaluate(() => { _site = {}; return { norm: normSiteBase(), canonical: fourgeCanonicalBase(), pageUrl: fourgePageUrl('page.html') }; });
chk(n.norm === r.origin && n.canonical === '' && n.pageUrl === '', 'without a Website URL: loading still uses the admin’s origin, canonical output stays empty rather than guessed');
await done('dev link');
