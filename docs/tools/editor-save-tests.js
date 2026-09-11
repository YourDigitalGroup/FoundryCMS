#!/usr/bin/env node
/**
 * Fourge CMS — editor save-path regression tests (engine 1.14.65 fix).
 *
 * Loads admin/index.html in headless Chromium (offline: all network blocked) and
 * verifies that the visual editor never writes third-party RUNTIME DOM into a
 * saved page, that Custom Code / chat-loader scripts are neutralized inside the
 * editor and restored byte-for-byte, and that the Plugins panel no longer shows
 * the inert "code injection" toggles.
 *
 * Usage (from the repo root):
 *   node docs/tools/editor-save-tests.js                # tests ./admin/index.html
 *   node docs/tools/editor-save-tests.js path/to/admin/index.html
 *
 * Needs Playwright with Chromium:
 *   npm i -D playwright && npx playwright install chromium
 *   (or a global install: NODE_PATH=$(npm root -g) node docs/tools/editor-save-tests.js)
 *   CHROMIUM_PATH=/path/to/chromium overrides the browser binary.
 *
 * Exit 0 = all pass, 1 = a check failed, 2 = harness error.
 */
const path = require('path'), fs = require('fs');
let chromium; try { ({ chromium } = require('playwright')); } catch (e) { console.error('playwright not found — npm i -D playwright && npx playwright install chromium'); process.exit(2); }

const adminPath = path.resolve(process.argv[2] || 'admin/index.html');
if (!fs.existsSync(adminPath)) { console.error('admin/index.html not found at ' + adminPath); process.exit(2); }
const ADMIN_URL = 'file://' + adminPath;

// A page the way an OLD engine saved it: the GoHighLevel loader's runtime output
// (module + nomodule bundle scripts, phone lib, Stencil style, <chat-widget> in a
// positioning div), an injected origin-trial meta, an Osano cookie banner, plus
// everything legitimate that must survive (Custom Code block with the install
// snippet, reCAPTCHA data-fourge-rc tags, adaptify.js, forms runtime, content).
const FIXTURE = `<!DOCTYPE html>
<html lang="en"><head>
<!-- foundry-custom-head:start -->
<!-- Functional -->
<script defer src='https://consent.example-vendor.com/cscripts/abc123.js'></script>
<!-- Marketing -->
<script src="https://widgets.leadconnectorhq.com/loader.js" data-resources-url="https://widgets.leadconnectorhq.com/chat-widget/loader.js" data-widget-id="widget123"></script>
<!-- foundry-custom-head:end --><meta http-equiv="origin-trial" content="AAAA"><meta charset="utf-8"><style data-styles="">slot-fb{display:contents}chat-widget{visibility:hidden}</style>
<title>Fixture</title>
<link rel="stylesheet" href="css/site.css?v=7">
<link type="text/css" rel="stylesheet" href="https://fe.sitedataprocessing.com/consent/cookieconsent.min.css">
<script type="module" src="https://widgets.leadconnectorhq.com/chat-widget/chat-widget.esm.js?v=1788357475650"></script>
<script nomodule="" src="https://widgets.leadconnectorhq.com/chat-widget/chat-widget.js" data-resources-url="https://widgets.leadconnectorhq.com/chat-widget/chat-widget.js" data-cookie-banner="undefined" data-stencil-namespace="https://widgets.leadconnectorhq.comchat-widget"></script>
<script src="https://stcdn.leadconnectorhq.com/libphonenumber/1.12.41/libphonenumber-js.min.js" async=""></script>
<script data-fourge-rc="">window.__fourgeRcSiteKey="6LeXXXX";window.__fourgeRcV3=true;</script><script data-fourge-rc="" async="" defer="" src="https://www.google.com/recaptcha/api.js?render=6LeXXXX"></script>
<script src="js/app.js"></script>
</head><body class="page-home"><div style="position: fixed; z-index: 9999;"><chat-widget data-loader-instance-id="loader-1788357475650-6gewdnwch" source="custom-code" class="hydrated" data-active="false"><div id="lc-captcha"></div></chat-widget></div>
<div class="cc-revoke cc-bottom cc-left cc-animate" style="">Cookie Policy</div><div role="dialog" aria-label="cookieconsent" class="cc-window cc-floating cc-bottom cc-invisible" style="display: none;"><span class="cc-message">This website uses cookies.</span></div>
<header class="site-header"><nav><a href="/">Home</a></nav></header>
<main>
<section id="hero" class="hero"><h1>Fixture page</h1><p>Body copy that must survive.</p></section>
<section id="cards"><my-card>authored custom element</my-card></section>
<section id="contact"><form class="foundry-form" data-form-id="f1"><input name="email"><button type="submit">Send</button></form></section>
</main>
<footer class="site-footer"><p>Site by Fixture</p></footer>
<script src="/adaptify.js" defer=""></script><script>function ffApiBase(){return "/admin/api.php";}</script>
</body></html>`;

let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const count = (s, re) => (s.match(re) || []).length;

(async () => {
  const launch = {}; if (process.env.CHROMIUM_PATH) launch.executablePath = process.env.CHROMIUM_PATH;
  const browser = await chromium.launch(launch);
  const page = await browser.newPage();
  const errors = []; page.on('pageerror', e => errors.push(String(e && e.message || e)));
  await page.route('**/*', r => r.request().url().startsWith('file://') ? r.continue() : r.abort());
  await page.goto(ADMIN_URL, { waitUntil: 'load' }).catch(e => console.log('goto:', e.message));
  await page.waitForTimeout(1200);
  ok(!errors.some(e => /SyntaxError/i.test(e)), 'admin loads with no SyntaxError (' + errors.length + ' runtime errors, offline noise expected)');

  const defs = await page.evaluate(() => ({ strip: typeof veStripRuntimeDom, neut: typeof veNeutralizeThirdParty, ser: typeof veSerializeClean, managed: typeof veIsCmsManaged, sels: Array.isArray(VE_RUNTIME_DOM_SELECTORS) ? VE_RUNTIME_DOM_SELECTORS.length : -1, cc: typeof CC_HEAD_START }));
  ok(defs.strip === 'function' && defs.neut === 'function' && defs.ser === 'function' && defs.managed === 'function' && defs.cc === 'string' && defs.sels > 20, 'helpers defined: ' + JSON.stringify(defs));

  const plug = await page.evaluate(() => { _inject = []; renderPlugins(); const b = document.getElementById('plugins-body'); const h = b ? b.innerHTML : ''; return { hasToggle: /togglePlugin\(/.test(h), hasLink: /Open Custom Code/.test(h), moved: /moved to Analytics/.test(h) }; });
  ok(!plug.hasToggle && plug.hasLink && plug.moved, 'Plugins panel: inert toggles gone, presets link to Custom Code');

  // A — strip a contaminated page (pass 1, selector list) --------------------
  const A = await page.evaluate((html) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const removed = veStripRuntimeDom(doc);
    const out = '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
    return { removed, out, forms: doc.querySelectorAll('form').length, sections: doc.querySelectorAll('section').length, rc: doc.querySelectorAll('script[data-fourge-rc]').length };
  }, FIXTURE);
  ok(count(A.out, /<chat-widget/g) === 0 && count(A.out, /chat-widget\.esm\.js|chat-widget\/chat-widget\.js|libphonenumber/g) === 0 && count(A.out, /<style data-styles/g) === 0 && count(A.out, /origin-trial/g) === 0 && count(A.out, /z-index: 9999;/g) === 0, 'A1 baked-in GoHighLevel widget, bundle scripts, Stencil style, wrapper div and origin-trial meta removed (' + A.removed + ' nodes)');
  ok(count(A.out, /class="cc-(window|revoke)/g) === 0 && count(A.out, /sitedataprocessing\.com\/consent/g) === 0, 'A2 cookie-consent banner DOM and injected consent CSS removed');
  ok(count(A.out, /leadconnectorhq\.com\/loader\.js/g) === 1 && count(A.out, /consent\.example-vendor\.com/g) === 1 && count(A.out, /foundry-custom-head:start/g) === 1, 'A3 install snippet and Custom Code block survive');
  ok(A.rc === 2 && count(A.out, /src="\/adaptify\.js"/g) === 1 && count(A.out, /js\/app\.js/g) === 1 && count(A.out, /css\/site\.css\?v=7/g) === 1, 'A4 CMS-managed reCAPTCHA tags, adaptify.js and the site\'s own assets survive');
  ok(A.forms === 1 && A.sections === 3 && /<my-card>authored custom element<\/my-card>/.test(A.out) && /Body copy that must survive/.test(A.out), 'A5 page content and authored custom element untouched');

  // B — neutralizer round trip -----------------------------------------------
  const sample = FIXTURE.replace('</footer>', '<script src="https://embed.tawk.to/abc/1"></script></footer>');
  const B = await page.evaluate((html) => {
    const n = veNeutralizeThirdParty(html);
    return { tagged: (n.match(/text\/foundry-ga/g) || []).length, appTagged: /foundry-ga"\s+src="js\/app\.js"/.test(n), rcTagged: /foundry-ga"[^>]*data-fourge-rc/.test(n), dbl: /foundry-ga" type="text\/foundry-ga"/.test(n), roundTrip: n.replace(/<script type="text\/foundry-ga"/gi, '<script') === html };
  }, sample);
  ok(B.tagged === 3, 'B1 the 2 Custom Code scripts + the footer chat loader neutralized, nothing else (got ' + B.tagged + ')');
  ok(!B.appTagged && !B.rcTagged && !B.dbl, 'B2 site scripts and reCAPTCHA untouched, no double-tagging');
  ok(B.roundTrip, 'B3 restore regex round-trips byte-for-byte');

  // D — generic "not on disk" guard (pass 2) ---------------------------------
  const disk = '<!DOCTYPE html>\n<html><head><link rel="stylesheet" href="css/site.css?v=3"><script src="js/app.js"></script></head><body><my-card>authored</my-card><main><h1>Hi</h1></main><script src="https://cdn.example.com/lib.js"></script></body></html>';
  const live = '<!DOCTYPE html>\n<html><head><link rel="stylesheet" href="css/site.css?v=4"><script src="js/app.js"></script><link rel="stylesheet" href="https://cdn.acmechat.io/w.css"><link rel="stylesheet" data-fourge-font="" id="fge-font-Lato" href="https://fonts.googleapis.com/css2?family=Lato"><script type="module" src="https://cdn.acmechat.io/w.esm.js"></script></head><body><my-card>authored</my-card><main><h1>Hi</h1></main><div><acme-chat data-x="1"><div>bubble</div></acme-chat></div><script src="https://cdn.example.com/lib.js"></script><script src="https://cdn.acmechat.io/lazy.js"></script></body></html>';
  const D = await page.evaluate(({ live, disk }) => { const doc = new DOMParser().parseFromString(live, 'text/html'); const removed = veStripRuntimeDom(doc, disk); const out = doc.documentElement.outerHTML; return { removed, out }; }, { live, disk });
  ok(D.removed === 4 && !/acme/i.test(D.out) && !/<div><\/div>/.test(D.out), 'D1 unknown vendor element, its scripts and stylesheet removed, empty wrapper dropped (' + D.removed + ' nodes)');
  ok(/<my-card>authored/.test(D.out) && /fge-font-Lato/.test(D.out) && /css\/site\.css\?v=4/.test(D.out) && /js\/app\.js/.test(D.out) && /cdn\.example\.com\/lib\.js/.test(D.out), 'D2 authored custom element, editor font link, cache-busted CSS and authored scripts kept');
  const D3 = await page.evaluate((live) => { const doc = new DOMParser().parseFromString(live, 'text/html'); return { removed: veStripRuntimeDom(doc), acme: /acme-chat/.test(doc.documentElement.outerHTML) }; }, live);
  ok(D3.removed === 0 && D3.acme, 'D3 generic pass skipped when the disk copy is unknown');

  // C — end to end through the editor iframe + veSerializeClean --------------
  const C = await page.evaluate(async (html) => {
    let f = document.getElementById('visual-iframe');
    if (!f) { f = document.createElement('iframe'); f.id = 'visual-iframe'; document.body.appendChild(f); }
    f.srcdoc = html.replace(/<script\b[^>]*\bsrc=["'](https?:)?\/\/[^"']+["'][^>]*><\/script>/gi, ''); // offline: drop remote scripts, keep baked DOM
    await new Promise(r => { f.onload = r; setTimeout(r, 3000); });
    const liveHas = !!f.contentDocument.querySelector('chat-widget');
    const out = veSerializeClean();
    return { liveHas, out };
  }, FIXTURE);
  ok(C.liveHas, 'C0 fixture: live iframe DOM contains the baked <chat-widget>');
  ok(count(C.out, /<chat-widget/g) === 0 && count(C.out, /<style data-styles/g) === 0 && count(C.out, /cc-window/g) === 0, 'C1 veSerializeClean output has no widget or banner DOM');
  ok(/<main>/.test(C.out) && /Body copy that must survive/.test(C.out) && !/__foundry_bridge__|__fe_done__/.test(C.out), 'C2 output keeps page content and carries no editor artifacts');

  await browser.close();
  console.log(fails ? ('\n' + fails + ' FAILURE(S)') : '\nALL TESTS PASSED');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
