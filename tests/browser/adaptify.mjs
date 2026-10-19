// ADAptify panel stays inside the window (1.14.143). The widget hangs its panel off
// one edge and used a fixed "100vh − 96px" cap, so a panel lifted above a reCAPTCHA
// badge (same corner), or one on a short window, ran past the top of the screen
// where its header and first rows could not be reached. Now the cap is the room
// between the panel's own anchor and the far edge of the visible window, re-fitted
// whenever the badge or the window changes, and the rest scrolls inside the panel.
import { launchPage, makeChk } from '../lib/harness.mjs';
const { chk, failed } = makeChk();
const { b, p, errs } = await launchPage();
const ORIGIN = 'http://127.0.0.1:' + (process.env.TEST_PORT || '8931');
const page = (badge) => `<!doctype html><html><head><meta charset="utf-8"><title>ADA fit</title><style>body{margin:0;min-height:300vh;font-family:sans-serif}</style></head><body><h1>Page</h1>
${badge ? `<div class="grecaptcha-badge" style="position:fixed;${badge};width:256px;height:60px;background:#ccc"></div>` : ''}
<script src="/adaptify.js" defer></script></body></html>`;
let cfg = { enabled: true, position: 'bottom-right', color: '#C8531E', iconColor: '#ffffff' };
let html = page('right:14px;bottom:14px');
await p.route('**/data/adaptify.json*', r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cfg) }));
await p.route(ORIGIN + '/ada-test.html*', r => r.fulfill({ status: 200, contentType: 'text/html', body: html }));
const measure = () => p.evaluate(() => {
  const pnl = document.querySelector('.adaptify-panel'), btn = document.querySelector('.adaptify-launch'), head = pnl.querySelector('.adaptify-head');
  const r = pnl.getBoundingClientRect(), h = head.getBoundingClientRect();
  return { top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height), vh: window.innerHeight, scrolls: pnl.scrollHeight > pnl.clientHeight + 1, headTop: Math.round(h.top), maxH: pnl.style.maxHeight, pnlBottom: pnl.style.bottom, btnBottom: btn.style.bottom, open: pnl.classList.contains('open') };
});
const openWidget = async (w, h) => {
  await p.setViewportSize({ width: w, height: h });
  await p.goto(ORIGIN + '/ada-test.html', { waitUntil: 'load' });
  await p.waitForSelector('.adaptify-launch', { timeout: 10000 });
  await p.waitForTimeout(250);   // the badge is measured right after build
  await p.click('.adaptify-launch');
  await p.waitForTimeout(100);
};

// 1. the report: bottom-right widget, reCAPTCHA badge in the same corner, a 520px-tall window
await openWidget(1000, 520);
let m = await measure();
chk(m.open && m.btnBottom === '84px' && m.pnlBottom === '128px', 'the badge (60px tall, 14px up) lifts the button to 84px and the panel to 128px — ' + JSON.stringify(m));
chk(m.top >= 0 && m.headTop >= 0, 'the panel no longer runs past the top of the window: its top edge and header are on screen — top ' + m.top + 'px, header ' + m.headTop + 'px');
chk(m.bottom <= m.vh && m.scrolls, 'it stops above the badge and scrolls inside (bottom ' + m.bottom + ' of ' + m.vh + ', scrollable ' + m.scrolls + ')');
chk(m.maxH === (520 - 128 - 12) + 'px', 'the cap is the room above the panel: window − its own offset − 12px = ' + m.maxH);

// 2. the window shrinks while it is open → re-fitted
await p.setViewportSize({ width: 1000, height: 380 });
await p.waitForTimeout(150);
m = await measure();
chk(m.top >= 0 && m.bottom <= m.vh && m.maxH === (380 - 128 - 12) + 'px', 'after a resize to 380px the panel still fits (top ' + m.top + ', bottom ' + m.bottom + ' of ' + m.vh + ', cap ' + m.maxH + ')');

// 3. …and grows back when there is room
await p.setViewportSize({ width: 1000, height: 1100 });
await p.waitForTimeout(150);
const tall = await measure();
chk(tall.top >= 0 && tall.height > m.height && tall.maxH === (1100 - 128 - 12) + 'px', 'on a tall window it grows again (height ' + tall.height + 'px, cap ' + tall.maxH + ')');

// 4. no badge: the default 64px offset, and still never past the top on a short window
html = page('');
await openWidget(1000, 420);
m = await measure();
chk(m.pnlBottom === '64px' && m.top >= 0 && m.bottom <= m.vh && m.scrolls && m.maxH === (420 - 64 - 12) + 'px', 'without a badge the panel sits 64px up and fits a 420px window (cap ' + m.maxH + ')');

// 5. badge in the OTHER corner: no lift, no overlap problem, still fits
html = page('left:14px;bottom:14px');
await openWidget(1000, 420);
m = await measure();
chk(m.btnBottom === '20px' && m.pnlBottom === '64px' && m.top >= 0, 'a badge in the opposite corner leaves the widget where it was, and the panel still fits');

// 6. top-anchored and middle-anchored placements fit too
cfg = { ...cfg, position: 'top-right' }; html = page('');
await openWidget(1000, 420);
m = await measure();
chk(m.top >= 0 && m.bottom <= m.vh && m.scrolls && m.maxH === (420 - 64 - 12) + 'px', 'top-right: the panel hangs from the top and stops above the bottom edge (bottom ' + m.bottom + ' of ' + m.vh + ')');
cfg = { ...cfg, position: 'mid-right' };
await openWidget(1000, 420);
m = await measure();
chk(m.top >= 0 && m.bottom <= m.vh && m.scrolls && m.maxH === (420 - 24) + 'px', 'mid-right: centered, inside the window on both edges (top ' + m.top + ', bottom ' + m.bottom + ' of ' + m.vh + ')');

chk(errs.length === 0, 'no JS errors during any scenario' + (errs.length ? (' — ' + errs.join(' | ').slice(0, 300)) : ''));
await b.close();
console.log(failed() ? '\nadaptify SUITE FAILED' : '\nall adaptify assertions passed');
process.exit(failed() ? 1 : 0);
