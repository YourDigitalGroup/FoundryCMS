// MarkUp reviews, the agency hub's queue (1.14.159): the half that runs in the browser.
//  - finding the element a comment is about: its MarkUp element path (CSS, XPath or a list;
//    nearest data-fourge-block), its ancestors' paths, the text the client selected — or a
//    person, with the reason; how it was found and how sure that is;
//  - checking the AI's answer against the AI Markup op schema and the element (and once more);
//  - applying ops the way the editor bridge does (per-device rules in #fourge-responsive);
//  - generate → approve / edit → approve / decline / revert against stubbed hub actions;
//  - the queue's tabs, groups and cards, the settings, and the menu item on the hub only.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const PAGE = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Smith Dental</title>
<style id="fourge-responsive" data-fourge-resp>.fge-r3{padding:10px !important;}
@media (max-width:600px){.fge-r3{padding:4px !important;}}
</style></head><body>
<header data-fourge-block="bhdr0001"><nav data-fourge-block="bnav0001"><a href="/" data-fourge-block="blnk0001">Home</a></nav></header>
<main data-fourge-block="bmain001">
<section class="hero fge-r3" data-fourge-block="bsec0001"><h1 data-fourge-block="bh1x0001" style="color:#111111">Welcome to Smith Dental</h1>
<p data-fourge-block="bp1x0001">We are open <strong>Monday to Friday</strong>, 8am to 5pm.</p>
<figure data-fourge-block="bfig0001"><picture data-fourge-block="bpic0001"><source srcset="/img/team.webp" type="image/webp"><img src="/img/team.jpg" srcset="/img/team-2x.jpg 2x" alt="Our team" data-fourge-block="bimg0001"></picture></figure>
</section>
<section data-fourge-block="bsec0002"><h2 data-fourge-block="bh2x0001">Services</h2><ul data-fourge-block="bul00001"><li data-fourge-block="bli00001">Cleanings</li><li data-fourge-block="bli00002">Whitening</li></ul>
<p data-fourge-block="bp2x0001">Call us at 555-0100.</p><p data-fourge-block="bp3x0001">Call us at 555-0100 today.</p></section>
</main></body></html>`;
const SHA = 'a'.repeat(64);

// ── 1. finding the element ──
const res = await p.evaluate((PAGE) => {
  const r = (th) => { const doc = mkParse(PAGE); const x = mkResolve(doc, th); const t = x.el ? mkTarget(x.el) : null; return { m: x.method, c: x.confidence, b: t ? t.getAttribute('data-fourge-block') : null, note: x.note || '' }; };
  return {
    pathHl: r({ elements: [{ path: 'html > body > main > section.hero > p > strong' }], highlight: 'Monday to Friday' }),
    pathNoHl: r({ elements: [{ path: 'main > section:nth-of-type(2) > h2' }] }),
    pathWrong: r({ elements: [{ path: 'main > section:nth-of-type(2) > h2' }], highlight: 'open Monday to Friday' }),
    parentHl: r({ elements: [{ path: '#gone' }], parents: [{ path: 'main', depth: 1 }, { path: 'html > body > main > section.hero', depth: 2 }], highlight: 'Welcome to Smith' }),
    parentOnly: r({ elements: [{ path: '#gone' }], parents: [{ path: 'html > body > main > section.hero', depth: 1 }] }),
    text: r({ elements: [{ path: '#gone' }], highlight: 'Services' }),
    twice: r({ elements: [{ path: '#gone' }], highlight: 'Call us at 555-0100' }),
    nothing: r({ elements: [{ path: '#gone' }] }),
    xpath: r({ elements: [{ path: '/html/body/main/section[2]/h2' }] }),
    list: r({ elements: [{ path: '["html","body","main","section.hero","h1"]' }] }),
    badSel: r({ elements: [{ path: 'div[[[' }], highlight: 'Whitening' }),
  };
}, PAGE);
chk(res.pathHl.m === 'path' && res.pathHl.c === 0.95 && res.pathHl.b === 'bp1x0001', 'by element path, checked against the selected text: the nearest element with a data-fourge-block id (a <strong> → its paragraph), confidence 95% — ' + JSON.stringify(res.pathHl));
chk(res.pathNoHl.m === 'path' && res.pathNoHl.c === 0.8 && res.pathNoHl.b === 'bh2x0001', '…by element path alone (no text selected): 80% — ' + JSON.stringify(res.pathNoHl));
chk(res.pathWrong.m === 'text' && res.pathWrong.b === 'bp1x0001' && res.pathWrong.c === 0.8 && /text decided/.test(res.pathWrong.note), '…a path landing on an element without the selected text: the text decides — ' + JSON.stringify(res.pathWrong));
chk(res.parentHl.m === 'path' && res.parentHl.b === 'bh1x0001' && res.parentHl.c === 0.85, 'the pinned element\'s path is gone: its nearest ancestor\'s path, narrowed by the selected text — ' + JSON.stringify(res.parentHl));
chk(res.parentOnly.m === 'parent' && res.parentOnly.b === 'bsec0001' && res.parentOnly.c === 0.45, '…with no text selected, only the container, at low confidence — ' + JSON.stringify(res.parentOnly));
chk(res.text.m === 'text' && res.text.b === 'bh2x0001' && res.text.c === 0.75, 'no path matches: the text the client selected finds it — ' + JSON.stringify(res.text));
chk(res.twice.m === 'none' && res.twice.b === null && /appears 2 times/.test(res.twice.note), '…but text that appears twice is not guessed at: a person decides — ' + JSON.stringify(res.twice));
chk(res.nothing.m === 'none' && res.nothing.b === null && /screenshot/.test(res.nothing.note), 'nothing matches: needs a person, who gets the screenshot — ' + JSON.stringify(res.nothing));
chk(res.xpath.b === 'bh2x0001' && res.list.b === 'bh1x0001', 'an XPath, or a list of steps, works as an element path too');
chk(res.badSel.m === 'text' && res.badSel.b === 'bli00002', 'a path that isn\'t valid CSS doesn\'t throw: the text takes over — ' + JSON.stringify(res.badSel));

// ── 2. checking the AI's answer ──
const val = await p.evaluate((PAGE) => {
  const doc = mkParse(PAGE);
  const h1 = doc.querySelector('h1'), img = doc.querySelector('img'), fig = doc.querySelector('figure');
  const v = (o, b) => { const x = mkValidate(typeof o === 'string' ? o : JSON.stringify(o), b || h1); return x.ok ? 'ok' : x.error; };
  const base = { classification: 'text_edit', summary: 'Change the heading', device: 'all', confidence: 0.9 };
  return {
    good: v({ ...base, ops: [{ field: 'text', value: 'Hello' }] }),
    fenced: v('```json\n' + JSON.stringify({ ...base, ops: [{ field: 'text', value: 'Hello' }] }) + '\n```'),
    prose: v('Sure! Here it is: ' + JSON.stringify({ ...base, ops: [{ field: 'size', value: '32px' }] })),
    notJson: v('I would change the heading.'),
    field: v({ ...base, ops: [{ field: 'font', value: 'Arial' }] }),
    css: v({ ...base, classification: 'style', ops: [{ field: 'color', value: 'banana' }] }),
    cssInject: v({ ...base, classification: 'style', ops: [{ field: 'color', value: 'red;position:fixed' }] }),
    script: v({ ...base, ops: [{ field: 'html', value: 'Hi <img src=x onerror=alert(1)>' }] }),
    question: v({ ...base, classification: 'question_or_vague', ops: [{ field: 'text', value: 'x' }] }),
    empty: v({ ...base, ops: [] }),
    mobileText: v({ ...base, device: 'mobile', ops: [{ field: 'text', value: 'x' }] }),
    mobileStyle: v({ ...base, classification: 'style', device: 'mobile', ops: [{ field: 'size', value: '22px' }] }),
    srcOnText: v({ ...base, classification: 'image_swap', ops: [{ field: 'src', value: 'https://cdn.example/a.png' }] }),
    srcOnFig: v({ ...base, classification: 'image_swap', ops: [{ field: 'src', value: 'https://cdn.example/a.png' }] }, fig),
    jsSrc: v({ ...base, classification: 'image_swap', ops: [{ field: 'src', value: 'javascript:alert(1)' }] }, img),
    textOnImg: v({ ...base, ops: [{ field: 'text', value: 'x' }] }, img),
    badClass: v({ ...base, classification: 'rewrite', ops: [] }),
    clean: (() => { const x = mkValidate(JSON.stringify({ ...base, ops: [{ field: 'html', value: 'Call <a href="tel:5550100">555-0100</a> <b>now</b>' }] }), h1); return x.ok ? x.value.ops[0].value : x.error; })(),
    sanitized: mkSanitizeHtml('<p onclick="x()">Hi <a href="javascript:alert(1)">x</a><script>bad()</script><iframe src="//evil"></iframe><a href="/ok" target="_blank">ok</a></p>'),
  };
}, PAGE);
chk(val.good === 'ok' && val.fenced === 'ok' && val.prose === 'ok', 'a valid answer passes, also wrapped in a code fence or a sentence');
chk(/not a JSON object/.test(val.notJson) && /unknown field "font"/.test(val.field) && /not a valid color/.test(val.css) && /plain CSS value/.test(val.cssInject), 'refused: no JSON, an unknown field, a value that isn\'t valid CSS for its property, and a value trying to add declarations — ' + [val.notJson, val.field, val.css, val.cssInject].join(' | '));
chk(/scripts, frames, forms or event handlers/.test(val.script), 'refused: html with an event handler — ' + val.script);
chk(/must have no ops/.test(val.question) && /needs at least one op/.test(val.empty) && /classification must be/.test(val.badClass), 'a question carries no ops, a change carries at least one, and only the six classifications exist');
chk(/mobile-only change can only change styles/.test(val.mobileText) && val.mobileStyle === 'ok', 'a mobile-only change is for styles only (text is the same on every screen)');
chk(/needs an image element/.test(val.srcOnText) && val.srcOnFig === 'ok' && /http\(s\) address/.test(val.jsSrc) && /can't change an image/.test(val.textOnImg), 'image fields need an image (a figure holding one image counts), a javascript: address is refused, and text can\'t go on an image');
chk(val.clean === 'Call <a href="tel:5550100">555-0100</a> <b>now</b>', 'harmless html (links, bold) is kept as written — ' + val.clean);
chk(!/onclick|javascript|script|iframe/.test(val.sanitized) && /<a href="\/ok" target="_blank">ok<\/a>/.test(val.sanitized), 'html is cleaned of handlers, script links, scripts and frames before it touches a page — ' + val.sanitized);

// ── 3. applying ops as the editor does ──
const ap = await p.evaluate((PAGE) => {
  const doc = mkParse(PAGE);
  const h1 = doc.querySelector('h1'), sec = doc.querySelector('section.hero'), img = doc.querySelector('img'), li = doc.querySelector('li');
  const u1 = mkApplyOps(doc, h1, [{ field: 'color', value: '#c0392b' }], 'all').undo;
  const u2 = mkApplyOps(doc, h1, [{ field: 'size', value: '22px' }], 'mobile').undo;
  const u3 = mkApplyOps(doc, sec, [{ field: 'padding', value: '20px' }], 'all').undo;
  mkApplyOps(doc, img, [{ field: 'src', value: '/assets/markup/new.png' }, { field: 'alt', value: 'New team photo' }, { field: 'imgHref', value: '/team' }], 'all');
  mkApplyOps(doc, li, [{ field: 'text', value: 'Deep cleanings\nand checkups' }], 'all');
  // the editor's own text survives a read and a write unchanged
  const ed = mkParse('<html><head><style id="fourge-responsive">.fge-r1{color:red !important;}\n@media (max-width:1024px){.fge-r1{width:50% !important;}}\n@media (max-width:600px){.fge-r1{font-size:12px !important;}.fge-r2{margin:0 auto !important;}}\n</style></head><body></body></html>');
  const before = ed.getElementById('fourge-responsive').textContent;
  mkRespWrite(ed, mkRespRead(ed));
  return {
    resp: doc.getElementById('fourge-responsive').textContent,
    h1: h1.outerHTML, u1, u2, u3,
    pic: doc.querySelector('figure').innerHTML,
    li: li.innerHTML,
    same: ed.getElementById('fourge-responsive').textContent === before,
  };
}, PAGE);
chk(/^<h1 data-fourge-block="bh1x0001" class="fge-r4">/.test(ap.h1) && ap.resp.split('\n')[0] === '.fge-r3{padding:20px !important;}.fge-r4{color:#c0392b !important;}', 'a style op gives the element its own fge-r class (after the page\'s highest) and a desktop rule; its old inline color moves into the rule first, as in the editor — ' + ap.h1 + ' / ' + ap.resp.split('\n')[0]);
chk(ap.resp.includes('@media (max-width:600px){.fge-r3{padding:4px !important;}.fge-r4{font-size:22px !important;}}'), 'a mobile-only op lands in the 600px rules, next to what was there — ' + ap.resp);
chk(JSON.stringify(ap.u1) === JSON.stringify([{ cls: 'fge-r4', dev: 'desktop', prop: 'color', before: null }]) && JSON.stringify(ap.u3) === JSON.stringify([{ cls: 'fge-r3', dev: 'desktop', prop: 'padding', before: '10px' }]) && ap.u2[0].dev === 'mobile', 'each style op records what the rule held before (for Revert) — ' + JSON.stringify([ap.u1, ap.u2, ap.u3]));
chk(!/<source/.test(ap.pic) && /<a href="\/team"><img src="\/assets\/markup\/new.png" alt="New team photo"/.test(ap.pic) && !/srcset/.test(ap.pic), 'an image swap drops the <source> and srcset that would keep the old picture, sets alt, and links the image — ' + ap.pic);
chk(ap.li === 'Deep cleanings<br>and checkups', 'a text op replaces the text (a line break becomes <br>) — ' + ap.li);
chk(ap.same, 'the editor\'s #fourge-responsive text reads and writes back exactly the same');

// ── 4. generating changes (hub actions and Claude stubbed) ──
await p.evaluate(({ PAGE, SHA }) => {
  window.__calls = []; window.__ai = []; window.__aiAnswers = [];
  window.__page = { html: PAGE, sha: SHA };
  const find = id => window.__items.find(i => i.id === id);
  window.apiCall = async (action, body = {}) => {
    window.__calls.push({ action, body: JSON.parse(JSON.stringify(body)) });
    if (action === 'markup_hub_item' && body.op === 'refresh') return { ok: true, item: find(body.id) };
    if (action === 'markup_hub_item' && body.op === 'save') return { ok: true, item: { ...find(body.id), status: body.status, gen: body.gen } };
    if (action === 'markup_hub_item') return { ok: true, item: { ...find(body.id), status: body.op === 'decline' ? 'declined' : 'new' } };
    if (action === 'markup_hub_page') return { ok: true, html: window.__page.html, sha256: window.__page.sha, url: 'https://client.example/index.html', base: 'https://client.example' };
    if (action === 'markup_hub_apply') return { ok: true, loop: { state: 'done' }, item: { ...find(body.id), status: body.mode === 'revert' ? 'reverted' : 'applied' } };
    if (action === 'markup_hub_image') return { ok: true, url: '/assets/markup/20261117-abc123-new-logo.png' };
    if (action === 'markup_hub_queue') return { ok: true, items: window.__items, sites: window.__sites, status: window.__status };
    if (action === 'markup_hub_status') return window.__status;
    return { ok: true };
  };
  window.callClaudeAPI = async (body) => { window.__ai.push(JSON.parse(JSON.stringify(body))); const a = window.__aiAnswers.shift(); if (a instanceof Error) throw a; return { content: [{ type: 'text', text: typeof a === 'string' ? a : JSON.stringify(a) }] }; };
  window.uiConfirm = async () => true;
  window.__sites = [{ site: 'client.example', name: 'Smith Dental', base: 'https://client.example', pages: [{ path: 'index.html', title: 'Home' }, { path: 'about.html', title: 'About' }], hosts: ['client.example'] }];
  _mk.sites = window.__sites;
  window.__thread = (o) => ({ id: 'th', url: 'https://client.example/', elements: [{ path: 'html > body > main > section.hero > h1' }], parents: [], highlight: '', viewMode: 'desktop', viewport: { w: 1440, h: 900 },
    screenshot: { url: 'https://cdn.example/shot.png' }, messages: [{ id: 'm1', text: 'Please change this heading to "Welcome to Smith Family Dental".', author: 'Casey', at: 1763380000, attachments: [], loom: [] }], ...o });
  window.__newItem = (id, th) => ({ id, threadId: 'th' + id, markupId: 'mk', site: 'client.example', page: 'index.html', status: 'new', threadSig: '0123456789abcdef', thread: th, gen: null, markup: { appUrl: 'https://app.markup.io/markup/mk' } });
}, { PAGE, SHA });
const gen = (item, answers) => p.evaluate(({ item, answers }) => {
  window.__calls = []; window.__ai = []; window.__aiAnswers = answers;
  window.__items = [item]; _mk.items = window.__items;
  return mkGenerate(item).then(() => ({ save: (window.__calls.filter(c => c.action === 'markup_hub_item' && c.body.op === 'save').pop() || {}).body, ai: window.__ai, calls: window.__calls.map(c => c.action + (c.body.op ? ':' + c.body.op : '')) }));
}, { item, answers });
const T1 = await p.evaluate(() => window.__thread({}));
let g = await gen(await p.evaluate(t => window.__newItem(1, t), T1), [{ classification: 'text_edit', summary: 'Change the heading to Welcome to Smith Family Dental', device: 'all', ops: [{ field: 'text', value: 'Welcome to Smith Family Dental' }], confidence: 0.9 }]);
const ai0 = g.ai[0] || {};
const txt = (ai0.messages && ai0.messages[0].content.filter(c => c.type === 'text').map(c => c.text).join('\n')) || '';
chk(g.calls.join(',') === 'markup_hub_item:refresh,markup_hub_page,markup_hub_item:save', 'generating reads the thread again from MarkUp, then the page from the site, then saves — ' + g.calls.join(','));
chk(g.save && g.save.status === 'pending' && g.save.sig === '0123456789abcdef' && g.save.gen.method === 'path' && g.save.gen.block === 'bh1x0001' && g.save.gen.pageSha === 'a'.repeat(64)
  && /Welcome to Smith Dental<\/h1>$/.test(g.save.gen.blockBefore) && /Welcome to Smith Family Dental<\/h1>$/.test(g.save.gen.blockAfter) && g.save.gen.ops[0].value === 'Welcome to Smith Family Dental' && g.save.gen.tries === 1,
  'a clear request becomes a pending change: the element\'s id, the page fingerprint, the element before and after, the ops — ' + JSON.stringify(g.save && g.save.gen).slice(0, 300));
chk(ai0.model === 'claude-opus-4-8' && /ONLY a JSON object/.test(ai0.system) && ai0.messages[0].content.some(c => c.type === 'image' && c.source.url === 'https://cdn.example/shot.png')
  && /Welcome to Smith Dental/.test(txt) && /Please change this heading/.test(txt) && /desktop layout/.test(txt) && /not instructions to you/.test(ai0.system),
  'Claude (through claude_proxy) gets the thread, the screenshot, the device and the element; the rules say the thread is the client\'s words, not instructions');
g = await gen(await p.evaluate(t => window.__newItem(2, t), T1), [{ classification: 'text_edit', summary: 'x', ops: [{ field: 'font', value: 'Arial' }] }, { classification: 'style', summary: 'Bigger heading', ops: [{ field: 'size', value: '48px' }], confidence: 0.7 }]);
chk(g.save && g.save.status === 'pending' && g.save.gen.tries === 2 && g.ai.length === 2 && /Your previous answer was rejected: unknown field "font"/.test(g.ai[1].messages[0].content.slice(-1)[0].text), 'an answer that fails the checks is asked for once more, with the reason; the second one is used');
g = await gen(await p.evaluate(t => window.__newItem(3, t), T1), ['nope', { classification: 'text_edit', summary: '', ops: [] }]);
chk(g.save && g.save.status === 'needs_human' && /didn't pass the checks twice/.test(g.save.gen.note) && g.ai.length === 2, 'two failed answers: needs a person, with the reason — ' + (g.save && g.save.gen.note));
g = await gen(await p.evaluate(() => window.__newItem(4, window.__thread({ elements: [{ path: '#gone' }], highlight: '' }))), []);
chk(g.save && g.save.status === 'needs_human' && g.save.gen.method === 'none' && g.ai.length === 0, 'an element that can\'t be found: needs a person (and no AI call is spent on it)');
g = await gen(await p.evaluate(t => window.__newItem(5, t), T1), [{ classification: 'question_or_vague', summary: 'Asks what the hours are', ops: [], confidence: 0.8 }]);
chk(g.save && g.save.status === 'needs_human' && /answer in MarkUp/.test(g.save.gen.note), 'a question: needs a person (answer in MarkUp), no ops');
g = await gen(await p.evaluate(t => window.__newItem(6, t), T1), [{ classification: 'not_a_change', summary: 'Praise for the photo', ops: [], confidence: 0.9 }]);
chk(g.save && g.save.status === 'no_change', 'praise or a cancelled request: nothing to change');
const MOB = await p.evaluate(() => window.__thread({ viewMode: '', viewport: { w: 390, h: 844 }, messages: [{ id: 'm', text: 'On my phone this heading is too big', author: 'Casey', attachments: [], loom: [] }] }));
g = await gen(await p.evaluate(t => window.__newItem(7, t), MOB), [{ classification: 'style', summary: 'Smaller heading on phones', device: 'mobile', ops: [{ field: 'size', value: '24px' }], confidence: 0.8 }]);
chk(g.save && g.save.gen.device === 'mobile' && /mobile layout \(390×844 viewport\)/.test(g.ai[0].messages[0].content.slice(-1)[0].text) && g.save.gen.styleUndo.length === 1 && g.save.gen.styleUndo[0].dev === 'mobile' && g.save.gen.styleUndo[0].prop === 'font-size',
  'a comment made on a phone tells Claude it was the mobile layout; a mobile-only change is kept for the 600px rules');

// ── 5. approving ──
const approve = (item, page, fn) => p.evaluate(({ item, page, fn }) => {
  window.__calls = []; window.__toasts = [];
  window.__items = [item]; _mk.items = window.__items;
  if (page) window.__page = page;
  return (fn === 'edit' ? (mkEditToggle(item.id), mkEditSet(item.id, 0, 'value', 'Hello there'), mkEditApprove(item.id)) : mkApprove(item.id)).then(() => ({
    apply: (window.__calls.find(c => c.action === 'markup_hub_apply') || {}).body, calls: window.__calls.map(c => c.action), toasts: window.__toasts.map(t => t.m),
  }));
}, { item, page, fn });
const pendingH1 = await p.evaluate(({ PAGE, SHA }) => {
  const doc = mkParse(PAGE); const h1 = doc.querySelector('h1');
  return { id: 10, threadId: 'th10', site: 'client.example', page: 'index.html', status: 'pending', thread: window.__thread({}),
    gen: { classification: 'text_edit', summary: 'Change the heading', device: 'all', ops: [{ field: 'text', value: 'Welcome to Smith Family Dental' }], block: 'bh1x0001', locator: mkLocator(h1), blockBefore: h1.outerHTML, pageSha: SHA } };
}, { PAGE, SHA });
let a = await approve(pendingH1, { html: PAGE, sha: SHA });
chk(a.apply && a.apply.mode === 'apply' && a.apply.expect === SHA && /<h1 data-fourge-block="bh1x0001" style="color:#111111">Welcome to Smith Family Dental<\/h1>/.test(a.apply.html) && /^<!DOCTYPE html>\n<html lang="en">/.test(a.apply.html)
  && a.apply.block === 'bh1x0001' && /Welcome to Smith Dental/.test(a.apply.blockBefore) && /Smith Family Dental/.test(a.apply.blockAfter) && a.apply.ops[0].value === 'Welcome to Smith Family Dental',
  'Approve builds the new page from the page as it is now, changes only that element, and sends it with the fingerprint it was built from — ' + JSON.stringify(a.apply && { ...a.apply, html: (a.apply.html || '').length }).slice(0, 300));
const noIds = PAGE.replace(/ data-fourge-block="[^"]*"/g, '');
const pendingNoIds = await p.evaluate(({ noIds }) => {
  const doc = mkParse(noIds); const h1 = doc.querySelector('h1');
  return { id: 11, threadId: 'th11', site: 'client.example', page: 'index.html', status: 'pending', thread: window.__thread({}),
    gen: { classification: 'text_edit', summary: 'x', device: 'all', ops: [{ field: 'text', value: 'New' }], block: '', locator: mkLocator(h1), blockBefore: h1.outerHTML, pageSha: 'c'.repeat(64) } };
}, { noIds });
a = await approve(pendingNoIds, { html: noIds.replace('<p>Call us at 555-0100 today.</p>', '<p>Call us today.</p>'), sha: 'd'.repeat(64) });
const stamped = a.apply ? (a.apply.html.match(/data-fourge-block="/g) || []).length : 0;
chk(a.apply && />New<\/h1>/.test(a.apply.html) && stamped >= 15 && /Call us today/.test(a.apply.html), 'a page saved before element ids existed: found by its place (the page changed elsewhere, the element didn\'t), and it gets its ids with this change — ' + stamped + ' ids');
a = await approve(pendingH1, { html: PAGE.replace('Welcome to Smith Dental', 'Welcome to Smith Dental!'), sha: 'e'.repeat(64) });
chk(!a.apply && a.toasts.some(t => /element changed on the site since this change was made/.test(t)), 'the element itself changed since: nothing is sent, and the toast says to regenerate — ' + a.toasts.join(' | '));
const swap = await p.evaluate(({ PAGE, SHA }) => {
  const doc = mkParse(PAGE); const img = doc.querySelector('img');
  return { id: 12, threadId: 'th12', site: 'client.example', page: 'index.html', status: 'pending',
    thread: window.__thread({ messages: [{ id: 'm', text: 'Use this photo instead', author: 'Casey', attachments: [{ url: 'https://cdn.example/new-logo.png', mimeType: 'image/png', name: 'new-logo.png' }], loom: [] }] }),
    gen: { classification: 'image_swap', summary: 'Swap the team photo', device: 'all', ops: [{ field: 'src', value: 'https://cdn.example/new-logo.png' }], block: 'bimg0001', locator: mkLocator(img), blockBefore: img.outerHTML, pageSha: SHA } };
}, { PAGE, SHA });
a = await approve(swap, { html: PAGE, sha: SHA });
chk(a.calls.join(',').startsWith('markup_hub_page,markup_hub_image') && a.apply && a.apply.ops[0].value === '/assets/markup/20261117-abc123-new-logo.png' && /<img src="\/assets\/markup\/20261117-abc123-new-logo.png" alt="Our team"/.test(a.apply.html) && !/team\.webp|team-2x/.test(a.apply.html),
  'an image swap copies the client\'s attachment onto their site first, and the page uses that copy — ' + a.calls.join(','));
a = await approve(pendingH1, { html: PAGE, sha: SHA }, 'edit');
chk(a.apply && a.apply.edited === true && a.apply.ops[0].value === 'Hello there' && />Hello there<\/h1>/.test(a.apply.html), 'Edit, then approve: the edited change is what goes live (marked as edited)');
const badEdit = await p.evaluate((item) => { window.__calls = []; window.__toasts = []; window.__items = [item]; _mk.items = window.__items; mkEditToggle(item.id); mkEditSet(item.id, 0, 'field', 'color'); mkEditSet(item.id, 0, 'value', 'banana'); return mkEditApprove(item.id).then(() => ({ calls: window.__calls.length, toasts: window.__toasts.map(t => t.m) })); }, pendingH1);
chk(badEdit.calls === 0 && badEdit.toasts.some(t => /Not valid yet: "banana" is not a valid color/.test(t)), 'an edit gets the same checks as the AI\'s change — ' + badEdit.toasts.join(' | '));
const decl = await p.evaluate((item) => { window.__calls = []; window.__items = [item]; _mk.items = window.__items; return mkDecline(item.id).then(() => window.__calls.map(c => c.action + ':' + (c.body.op || ''))); }, pendingH1);
chk(decl.join(',') === 'markup_hub_item:decline', 'Decline asks, then only marks it declined (the hub posts nothing to MarkUp)');

// ── 6. reverting ──
const applied = await p.evaluate(({ PAGE, SHA }) => {
  const doc = mkParse(PAGE); const h1 = doc.querySelector('h1'); const before = h1.outerHTML;
  const undo = mkApplyOps(doc, h1, [{ field: 'color', value: '#c0392b' }], 'all').undo;
  return { item: { id: 20, threadId: 'th20', site: 'client.example', page: 'index.html', status: 'applied', thread: window.__thread({}), gen: {},
    applied: { afterSha: 'f'.repeat(64), hasPageBefore: true, block: 'bh1x0001', blockBefore: before, blockAfter: h1.outerHTML, styleUndo: undo, summary: 'Red heading' } },
    after: mkSerialize(doc, PAGE) };
}, { PAGE, SHA });
const revert = (page) => p.evaluate(({ item, page }) => { window.__calls = []; window.__toasts = []; window.__items = [item]; _mk.items = window.__items; window.__page = page; return mkRevert(item.id).then(() => ({ body: (window.__calls.find(c => c.action === 'markup_hub_apply') || {}).body, toasts: window.__toasts.map(t => t.m) })); }, { item: applied.item, page });
let rv = await revert({ html: applied.after, sha: 'f'.repeat(64) });
chk(rv.body && rv.body.mode === 'revert' && rv.body.full === true && rv.body.expect === 'f'.repeat(64) && !rv.body.html, 'Revert, page untouched since: the hub puts back the whole page as it was');
rv = await revert({ html: applied.after.replace('8am to 5pm', '9am to 6pm'), sha: '9'.repeat(64) });
chk(rv.body && rv.body.mode === 'revert' && !rv.body.full && /<h1 data-fourge-block="bh1x0001" style="color:#111111">Welcome to Smith Dental<\/h1>/.test(rv.body.html) && /9am to 6pm/.test(rv.body.html) && !/fge-r4/.test(rv.body.html),
  'Revert after other edits to the page: only the element and its style rule go back; the other edit stays — ' + (rv.body ? rv.body.html.match(/<h1[^>]*>[^<]*<\/h1>/)[0] : ''));

// ── 7. the queue ──
const ui = await p.evaluate(({ PAGE, SHA }) => {
  localStorage.setItem('cd_user', JSON.stringify({ username: 'arch@example.com', role: 'superadmin', architect: true }));
  _mk.status = { ok: true, isHub: true, hasKey: true, canSetup: true, hub: 'https://fourge.com', workspace: { name: 'Agency WS' }, webhook: { registered: true, mode: 'pretty', url: 'https://fourge.com/integrations/markup/webhook/x', at: 0 }, events: { pending: 0, failed: 0 }, counts: {}, triggerStatuses: ['approved'], statusesSeen: ['editing', 'Approved'], publicKey: 'PK' };
  const doc = mkParse(PAGE); const h1 = doc.querySelector('h1');
  const th = window.__thread({});
  _mk.items = [
    { id: 1, site: 'client.example', page: 'index.html', status: 'pending', thread: th, gen: { classification: 'text_edit', summary: 'Change the heading', ops: [{ field: 'text', value: 'Hi' }], method: 'path', methodConfidence: 0.95, confidence: 0.9, blockBefore: h1.outerHTML, pageSha: SHA }, markup: { appUrl: 'https://app.markup.io/markup/mk' }, updatedAt: 0 },
    { id: 2, site: 'client.example', page: 'about.html', status: 'pending', thread: th, gen: { classification: 'style', summary: 'Bigger', ops: [{ field: 'size', value: '40px' }], method: 'text', methodConfidence: 0.75, blockBefore: h1.outerHTML }, updatedAt: 0 },
    { id: 3, site: '', page: '', status: 'unmapped', thread: { ...th, url: 'https://other.example/x' }, note: 'other.example did not answer as a Fourge site.', updatedAt: 0 },
    { id: 4, site: 'client.example', page: 'index.html', status: 'needs_human', thread: th, gen: { method: 'none', note: 'x' }, note: 'Couldn\'t find the element', updatedAt: 0 },
    { id: 5, site: 'client.example', page: 'index.html', status: 'applied', thread: th, gen: {}, applied: { byName: 'Scott', at: 0, ops: [{ field: 'text', value: 'Hi' }] }, loop: { state: 'resolve', error: 'MarkUp answered HTTP 500.' }, updatedAt: 0 },
  ];
  _mk.filter = 'pending'; _mk.showSettings = false; mkRender();
  const root = document.getElementById('mk-root');
  const tabs = [...root.querySelectorAll('.mk-tab')].map(t => t.textContent);
  const pend = { sites: [...root.querySelectorAll('.mk-site > h3')].map(x => x.textContent), pages: [...root.querySelectorAll('.mk-page > h4')].map(x => x.textContent), cards: root.querySelectorAll('.mk-card').length,
    approve: root.querySelectorAll('.mk-card button.fd-btn-primary').length, msg: /Please change this heading/.test(root.textContent), shot: !!root.querySelector('img.mk-shot[src="https://cdn.example/shot.png"]'),
    method: /Element found by its MarkUp element path \(confidence 95%\)/.test(root.textContent), bulk: !!root.querySelector('button[onclick="mkBulkApprove()"]') };
  mkFilter('unmapped');
  const um = { site: !!root.querySelector('#mk-as-site-3'), pages: [...(root.querySelector('#mk-as-page-3') || { options: [] }).options].map(o => o.value), note: /did not answer as a Fourge site/.test(root.textContent), group: /Not matched to a site/.test(root.textContent) };
  mkFilter('applied');
  const apl = { told: /MarkUp hasn't been told yet: MarkUp answered HTTP 500/.test(root.textContent), retry: !!root.querySelector('button[onclick="mkItemOp(5,\'loop\')"]'), revert: !!root.querySelector('button[onclick="mkRevert(5)"]') };
  _mk.showSettings = true; mkFilter('pending');
  const st = { key: !!root.querySelector('#mk-key'), trig: [...root.querySelectorAll('.mk-trig')].map(x => x.value + ':' + x.checked), hook: /integrations\/markup\/webhook/.test(root.textContent), pk: /PK/.test(root.querySelector('.mk-settings').textContent) };
  _mk.status = { ok: true, isHub: true, hasKey: false, canSetup: false, counts: {} }; mkRender();
  const sa = { noKey: /isn't connected yet\. An Architect connects it once/.test(root.textContent), noField: !root.querySelector('#mk-key') };
  return { tabs, pend, um, apl, st, sa };
}, { PAGE, SHA });
chk(ui.tabs.join(',') === 'Pending2,Needs a person1,Unmapped1,Applied1,Declined0,Failed0', 'the queue\'s filters, with counts: Pending, Needs a person, Unmapped, Applied, Declined, Failed — ' + ui.tabs.join(','));
chk(ui.pend.sites.length === 1 && /Smith Dental/.test(ui.pend.sites[0]) && ui.pend.pages.length === 2 && ui.pend.cards === 2 && ui.pend.approve === 2, 'pending changes are grouped by site, then page, one card each with Approve (plus Approve selected) — ' + JSON.stringify(ui.pend));
chk(ui.pend.msg && ui.pend.shot && ui.pend.method && ui.pend.bulk, 'a card shows the client\'s thread, MarkUp\'s screenshot, and how the element was found, with its confidence');
chk(ui.um.site && ui.um.pages.join(',') === 'index.html,about.html' && ui.um.note && ui.um.group, 'an unmapped comment says why, under "Not matched to a site", with a site and page to pick — ' + JSON.stringify(ui.um));
chk(ui.apl.told && ui.apl.retry && ui.apl.revert, 'an applied change offers Revert, and says when MarkUp hasn\'t been told yet (with Tell MarkUp again)');
chk(ui.st.key && ui.st.trig.join(',') === 'editing:false,approved:true' && ui.st.hook && ui.st.pk, 'the Architect\'s settings: the key field, the statuses MarkUp has sent as triggers (Approved on), the webhook address and the hub key — ' + JSON.stringify(ui.st));
chk(ui.sa.noKey && ui.sa.noField, 'before MarkUp is connected, a Super Admin is told an Architect connects it (no key field)');

// ── 8. the menu item: on the hub only ──
const nav = await p.evaluate(async () => {
  const ni = document.getElementById('ni-markup');
  window.__status = { ok: true, isHub: true, counts: { pending: 2, needs_human: 1, applied: 4 } };
  localStorage.setItem('cd_user', JSON.stringify({ username: 'sa@example.com', role: 'superadmin' }));
  await mkNavCheck(); const hub = { shown: ni.style.display !== 'none', badge: document.getElementById('ni-markup-count').textContent };
  window.__status = { ok: true, isHub: false };
  await mkNavCheck(); const other = ni.style.display === 'none';
  window.__status = { ok: true, isHub: true, counts: {} };
  localStorage.setItem('cd_user', JSON.stringify({ username: 'ed@example.com', role: 'admin' }));
  window.__calls = [];
  await mkNavCheck(); const admin = { hidden: ni.style.display === 'none', asked: window.__calls.length };
  return { hub, other, admin };
});
chk(nav.hub.shown && nav.hub.badge === '3', 'on the hub, a Super Admin gets the MarkUp menu item with the count waiting on a person — ' + JSON.stringify(nav.hub));
chk(nav.other && nav.admin.hidden && nav.admin.asked === 0, 'on any other site, or for an Admin, there is no MarkUp menu item (an Admin\'s browser doesn\'t even ask)');

await done('markup_hub');
