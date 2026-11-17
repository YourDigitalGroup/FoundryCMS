// MarkUp reviews, the site's side (1.14.158):
//  - every save gives the page's content elements a permanent data-fourge-block id
//    (the hub finds the element a client's comment is about by it), keeps the ids
//    it gave before, and gives a duplicated element its own;
//  - lists and widgets the page fills in at runtime get none;
//  - Plugins → MarkUp reviews: on unless switched off (site.json markup.enabled).
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const page = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Blocks</title><style>p{color:red}</style></head>
<body>
<header><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></header>
<main>
<section class="hero"><h1>Welcome &amp; hello</h1><p>Intro text with <strong>bold</strong> and <em>emphasis</em>.</p><img src="/a.jpg" alt="A"></section>
<section><div class="card"><h2>Card</h2><p>One</p></div><div class="card" data-fourge-block="bkeep01"><h2>Card 2</h2><p data-fourge-block="bkeep02">Two</p></div></section>
<div data-fourge-posts="6"><article><h3 data-fourge-block="bstale1">A post the page draws itself</h3></article></div>
<svg width="10" height="10"><a href="#"><text>svg link</text></a></svg>
<script>document.write('<p>x</p>')</script>
</main>
<footer><p>&copy; 2026 Example</p></footer>
</body></html>`;

const r1 = await p.evaluate((src) => {
  const out = fourgeStampBlockIds(src);
  const d = new DOMParser().parseFromString(out, 'text/html');
  const ids = [...d.querySelectorAll('[data-fourge-block]')].map(e => e.getAttribute('data-fourge-block'));
  const missing = [...d.body.querySelectorAll('h1,h2,p,li,a,img,section,div,ul,nav,header,main,footer')]
    .filter(e => !e.closest('[data-fourge-posts],svg,script') && !e.hasAttribute('data-fourge-block')).map(e => e.tagName);
  // the page is otherwise the same: take the ids away again and compare with the original, parsed the same way
  d.querySelectorAll('[data-fourge-block]').forEach(e => e.removeAttribute('data-fourge-block'));
  const o = new DOMParser().parseFromString(src, 'text/html'); o.querySelectorAll('[data-fourge-block]').forEach(e => e.removeAttribute('data-fourge-block'));
  return {
    out, ids, missing, same: d.documentElement.outerHTML === o.documentElement.outerHTML,
    doctype: /^<!DOCTYPE html>\n<html lang="en">/.test(out),
    inPosts: d.querySelector('[data-fourge-posts]') && out.includes('bstale1'),
    svg: /<a href="#"><text>/.test(out) && !/<svg[^>]*data-fourge-block/.test(out),
    strongEm: !/<(strong|em)[^>]*data-fourge-block/.test(out),
    again: fourgeStampBlockIds(out) === out,
  };
}, page);
chk(r1.missing.length === 0 && r1.ids.length >= 20, 'every content element (headings, paragraphs, links, list items, images, sections, divs…) gets a data-fourge-block id — ' + r1.ids.length + ' set' + (r1.missing.length ? ', missing on ' + r1.missing.join(',') : ''));
chk(new Set(r1.ids).size === r1.ids.length && r1.ids.filter(i => !/^bkeep0[12]$/.test(i)).every(i => /^b[a-z0-9]{7}$/.test(i)), 'the ids are unique and short (b + 7 letters/digits)');
chk(r1.ids.includes('bkeep01') && r1.ids.includes('bkeep02'), 'ids the page already had are kept');
chk(!r1.inPosts && r1.svg && r1.strongEm, 'nothing inside a list the page draws at runtime (posts), an SVG or a script gets one (and an old one there is taken out); inline <strong>/<em> are left alone');
chk(r1.same && r1.doctype, 'the page is otherwise unchanged — the same document once the ids are taken away, its doctype and <html lang> kept');
chk(r1.again, 'stamping a page that already has its ids gives back the very same text (a save changes nothing more)');

// a duplicated element (the editor's Duplicate copies the attribute too): the first keeps the id, the copy gets a new one
const r2 = await p.evaluate(() => {
  const src = '<!DOCTYPE html><html><head></head><body><div data-fourge-block="borig01"><p data-fourge-block="borig02">A</p></div><div data-fourge-block="borig01"><p data-fourge-block="borig02">A</p></div></body></html>';
  const d = new DOMParser().parseFromString(fourgeStampBlockIds(src), 'text/html');
  return [...d.querySelectorAll('[data-fourge-block]')].map(e => e.getAttribute('data-fourge-block'));
});
chk(r2.length === 4 && r2[0] === 'borig01' && r2[1] === 'borig02' && r2[2] !== 'borig01' && r2[3] !== 'borig02' && new Set(r2).size === 4, 'a duplicated element: the original keeps its ids, the copy gets new ones — ' + JSON.stringify(r2));

// a page with no <body> (a fragment, a partial) is not touched
const r3 = await p.evaluate(() => { const s = '<p>just a fragment</p>'; return fourgeStampBlockIds(s) === s; });
chk(r3, 'text without a <body> (a fragment) is returned as it was');

// every save stamps: veFinalizePageHtml is what every page write goes through
const r4 = await p.evaluate((src) => { const out = veFinalizePageHtml(src.replace(/ data-fourge-block="[^"]*"/g, ''), null); return (out.match(/data-fourge-block="/g) || []).length; }, page);
chk(r4 >= 20, 'a page saved through the editor (veFinalizePageHtml) comes out with its ids — ' + r4);

// the editor's own document (stampable in place, as the MarkUp queue uses it)
const r5 = await p.evaluate(() => { const d = document.implementation.createHTMLDocument('x'); d.body.innerHTML = '<section><p>a</p><p>b</p></section>'; const n = fourgeStampBlockIdsDoc(d); return [n, fourgeStampBlockIdsDoc(d)]; });
chk(r5[0] === 3 && r5[1] === 0, 'fourgeStampBlockIdsDoc stamps a document in place and reports how many it set (then 0) — ' + JSON.stringify(r5));

// Plugins → MarkUp reviews
const card = () => p.evaluate(() => {
  const box = document.getElementById('s-markup-on');
  const sec = box && box.closest('.pl-addon');
  return { checked: box ? box.checked : null, badge: sec ? sec.querySelector('.pl-badge').textContent : null };
});
await p.evaluate(() => { _site = { name: 'E2E' }; renderPlugins(); });
let c = await card();
chk(c.checked === true && c.badge === 'On', 'Plugins shows MarkUp reviews, on for a site that never set it — ' + JSON.stringify(c));
await p.evaluate(() => { window.__writes = []; window.__toasts = []; document.getElementById('s-markup-on').checked = false; return saveMarkupSwitch(); });
let w = await p.evaluate(() => { const x = window.__writes.filter(x => x.path === 'data/site.json').pop(); return x ? JSON.parse(x.content) : null; });
chk(w && w.markup && w.markup.enabled === false, 'switching it off saves markup.enabled = false in site.json — ' + JSON.stringify(w && w.markup));
await p.evaluate(() => renderPlugins());
c = await card();
chk(c.checked === false && c.badge === 'Off', '…and the card then shows Off');
await p.evaluate(() => { window.__writes = []; document.getElementById('s-markup-on').checked = true; return saveMarkupSwitch(); });
w = await p.evaluate(() => { const x = window.__writes.filter(x => x.path === 'data/site.json').pop(); return x ? JSON.parse(x.content) : null; });
chk(w && w.markup && w.markup.enabled === true, 'switching it back on saves markup.enabled = true');

await done('block_ids');
