// Targeted landing pages and the hour's AI allowance (1.14.151). Each page is one
// AI request. When the allowance runs out mid-batch, every page after that would
// fail the same way, so the batch stops there: one message saying when the hour
// resets, and the pages already written kept for approval. Any other failure on
// one page doesn't stop the rest. A failed page is shown under its own city.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const LIMIT = 'AI usage limit reached for this hour (300 requests). It resets in 12 minutes.';
const SRC = '<html><head><title>Roof Repair</title><meta name="description" content="x"></head><body><h1>Roof Repair</h1><p>We fix roofs across the region.</p></body></html>';

// AI stand-in: answers from a script ('ok' / 'limit' / 'timeout'), one entry per request
const setAi = (script) => p.evaluate((script) => {
  window.__ai = { script, calls: 0 };
  window.callClaudeAPI = async (body) => {
    const step = window.__ai.script[Math.min(window.__ai.calls, window.__ai.script.length - 1)];
    window.__ai.calls++;
    if (step === 'limit') throw new Error('AI usage limit reached for this hour (300 requests). It resets in 12 minutes.');
    if (step === 'timeout') throw new Error('Request timed out');
    const city = (/version for "([^"]+)"/.exec(body.messages[0].content) || [])[1] || '?';
    return { content: [{ type: 'text', text: JSON.stringify({ title: 'Roof Repair in ' + city, metaDescription: 'Roof repair for ' + city, h1: 'Roof Repair in ' + city, introHtml: '<p>Serving ' + city + '.</p>', replacements: [] }) }] };
  };
}, script);
const state = () => p.evaluate(() => ({
  calls: window.__ai.calls,
  written: _tlpPreviews.filter(x => !x.error).map(x => x.city),
  failed: _tlpPreviews.filter(x => x.error).map(x => x.city + ': ' + x.error),
  toasts: window.__toasts.map(t => t.m),
  headings: Array.from(document.querySelectorAll('#tlp-preview-area h3')).map(h => h.textContent),
  publish: (document.querySelector('#tlp-preview-area .fd-btn-primary') || {}).textContent || '',
}));

await p.evaluate((SRC) => {
  window.__files['roof-repair.html'] = SRC;
  _pages = { 'roof-repair': { title: 'Roof Repair', file: 'roof-repair.html' } };
  localStorage.setItem('cd_api_url', '/admin/api.php');
  localStorage.setItem('cd_user', JSON.stringify({ username: 'ed@example.com', role: 'editor', permissions: { aiEdit: true } }));
  const box = document.createElement('div');
  box.innerHTML = '<input type="checkbox" class="tlp-src-chk" value="roof-repair" checked>';
  document.body.appendChild(box);
  document.getElementById('tlp-cities').value = 'Fargo, ND\nMoorhead, MN\nBismarck, ND\nMinot, ND\nGrand Forks, ND';
}, SRC);

// 1. city list × source pages: the allowance runs out on the 3rd page
await setAi(['ok', 'ok', 'limit']);
await p.evaluate(() => { window.__toasts = []; return generateTLPs(); });
let s = await state();
chk(s.calls === 3, 'the batch stops at the page the allowance ran out on: 3 requests for 5 cities, not 5 — ' + s.calls);
chk(s.written.join('|') === 'Fargo|Moorhead', 'the two pages written before it are kept for approval — ' + s.written.join(', '));
const lim = s.toasts.filter(m => /limit reached/.test(m));
chk(lim.length === 1 && lim[0].includes('It resets in 12 minutes.') && /pages written so far are below/.test(lim[0]), 'one message says the hour ran out, when it resets, and that the pages so far are kept — ' + JSON.stringify(s.toasts));
chk(s.failed.length === 1 && s.failed[0] === 'Bismarck: ' + LIMIT, 'the page it stopped on is shown as failed, with the reason — ' + JSON.stringify(s.failed));
chk(!s.headings.includes('undefined') && s.headings.join('|') === 'Fargo, ND|Moorhead, MN|Bismarck, ND', 'a failed page sits under its own city (it was filed under "undefined") — ' + s.headings.join(' | '));
chk(/Publish All Approved Pages \(2\)/.test(s.publish), 'and only the written pages count toward Publish — ' + s.publish);

// 2. any other failure on one page doesn't stop the rest
await setAi(['ok', 'timeout', 'ok', 'ok', 'ok']);
await p.evaluate(() => { window.__toasts = []; return generateTLPs(); });
s = await state();
chk(s.calls === 5 && s.written.length === 4 && s.failed.length === 1 && /Moorhead: Request timed out/.test(s.failed[0]), 'a page that fails for another reason (a timeout) is skipped and the batch carries on — ' + JSON.stringify(s));
chk(!s.toasts.some(m => /limit reached/.test(m)), 'with no limit message');

// 3. pages from the 44i platform feed stop the same way
await p.evaluate(() => {
  _tlpClient = { keywords: [] };
  _tlpRows = ['Fargo, ND', 'Moorhead, MN', 'Bismarck, ND', 'Minot, ND'].map((g, i) => ({ on: true, pageId: 'roof-repair', pair: { service: 'Roof Repair', geo_location: g, suggested_title: 'Roof Repair ' + i, suggested_slug: 'roof-repair-' + i } }));
});
await setAi(['ok', 'limit']);
await p.evaluate(() => { window.__toasts = []; return generateTLPsFromFeed(); });
s = await state();
chk(s.calls === 2 && s.written.join('|') === 'Fargo' && s.toasts.filter(m => /limit reached/.test(m)).length === 1, 'pages from the 44i platform stop at the limit too, keeping what was written — ' + JSON.stringify(s));

await done('landing pages AI limit');
