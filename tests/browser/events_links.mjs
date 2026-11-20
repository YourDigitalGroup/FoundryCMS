// Events (1.14.164): an event's several links.
//  - the editor: add, label and remove links (the last row empties instead of going away), an older event's
//    single address opens as its first link, an address typed without https:// gets it, one that can't be a
//    link (javascript:) isn't saved; url still holds the first link for anything that reads one address;
//  - the event page (the published runtime, served for real): one button per link, the first in the brand
//    colour and the others outlined, "More Info" for a link with no label, a javascript: link never shown,
//    an older event's single address still a button, and the structured data's url the first link;
//  - published event pages pick the runtime up at the next sign-in (EVENTS_RUNTIME_SWEEP_VERSION).
import { openAdmin, TURL } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const ed = await p.evaluate(async () => {
  window.saveEventsJson = async () => { window.__saved = JSON.parse(JSON.stringify(_events)); };
  window.seoSitemapRefresh = () => {};
  window.renderEvents = () => {};
  _events = [];
  openNewEvent();
  const rows = () => [...document.querySelectorAll('#ev-links .ev-link-row')];
  const rows0 = rows().length;
  document.getElementById('ev-title').value = 'Fall Gala';
  document.getElementById('ev-start').value = '2026-12-05';
  const setRow = (i, label, url) => { const r = rows()[i]; r.querySelector('.ev-link-label').value = label; r.querySelector('.ev-link-url').value = url; };
  setRow(0, 'Buy tickets', 'https://tickets.example/gala');
  evLinkAdd(); setRow(1, '', 'www.example.org/rsvp');
  evLinkAdd(); setRow(2, 'Facebook event', 'https://facebook.com/events/1');
  evLinkAdd();                                                    // left empty: not saved
  evLinkRemove(rows()[2].querySelector('.ev-link-x'));            // "Facebook event" taken out again
  await saveEvent();
  const saved = (window.__saved || [])[0] || {};
  // an event saved before links existed: one address, no label
  _events = [{ id: 'old1', title: 'Old Event', slug: 'old-event', start: '2026-01-01', end: '2026-01-01', url: 'https://old.example/info' }];
  editEvent('old1');
  const oldRows = rows().map(r => [r.querySelector('.ev-link-label').value, r.querySelector('.ev-link-url').value]);
  evLinkRemove(rows()[0].querySelector('.ev-link-x'));
  const afterLast = rows().map(r => r.querySelector('.ev-link-url').value);
  rows()[0].querySelector('.ev-link-url').value = 'javascript:alert(1)';
  window.__toasts = []; window.__saved = null;
  await saveEvent();
  const bad = { saved: window.__saved, toast: window.__toasts.map(t => t.m).join(' | ') };
  // Duplicate carries the links over
  _events = [{ id: 'src1', title: 'Monthly Meetup', slug: 'monthly-meetup', start: '2026-02-01', links: [{ label: 'RSVP', url: 'https://rsvp.example/m' }, { label: 'Map', url: 'https://maps.example/x' }], url: 'https://rsvp.example/m' }];
  duplicateEvent('src1');
  const dup = rows().map(r => [r.querySelector('.ev-link-label').value, r.querySelector('.ev-link-url').value]);
  closeM('m-event');
  return { rows0, saved, oldRows, afterLast, bad, dup, sweep: semverCmp(EVENTS_RUNTIME_SWEEP_VERSION, '1.14.164') >= 0 };
});
chk(ed.rows0 === 1, 'a new event opens with one empty link row, as the single field was');
chk(JSON.stringify(ed.saved.links) === JSON.stringify([{ label: 'Buy tickets', url: 'https://tickets.example/gala' }, { label: '', url: 'https://www.example.org/rsvp' }]) && ed.saved.url === 'https://tickets.example/gala',
  'several links are saved in order (an address typed without https:// gets it, an empty row is left out, a removed one is gone); url keeps the first for anything that reads one address — ' + JSON.stringify(ed.saved.links));
chk(JSON.stringify(ed.oldRows) === JSON.stringify([['', 'https://old.example/info']]), 'an event saved before links opens with its one address as its first link — ' + JSON.stringify(ed.oldRows));
chk(JSON.stringify(ed.afterLast) === JSON.stringify(['']), 'removing the only link empties its row instead of taking the row away');
chk(ed.bad.saved === null && /“javascript:alert\(1\)” isn't a web address/.test(ed.bad.toast), 'an address that can\'t be a link (javascript:) isn\'t saved, and the toast says which — ' + ed.bad.toast);
chk(JSON.stringify(ed.dup) === JSON.stringify([['RSVP', 'https://rsvp.example/m'], ['Map', 'https://maps.example/x']]), 'Duplicate carries the links over — ' + JSON.stringify(ed.dup));
chk(ed.sweep, 'published event pages get the new runtime at the next sign-in (the sweep version is this release\'s)');

// ── the event page, served for real ──
const assets = await p.evaluate(() => eventsRuntimeAssets());
const origin = new URL(TURL).origin;
const EVENTS = [
  { id: '1', title: 'Fall Gala', slug: 'fall-gala', start: '2026-12-05', end: '2026-12-05', description: 'A night out.', url: 'https://tickets.example/gala',
    links: [{ label: 'Buy tickets', url: 'https://tickets.example/gala' }, { label: '', url: 'https://www.example.org/rsvp' }, { label: 'Bad', url: 'javascript:alert(1)' }, { label: 'Facebook event', url: 'https://facebook.com/events/1' }] },
  { id: '2', title: 'Old Event', slug: 'old-event', start: '2026-01-01', url: 'https://old.example/info' },
  { id: '3', title: 'No Links', slug: 'no-links', start: '2026-02-01' },
];
const p2 = await p.context().newPage();
const errs2 = []; p2.on('pageerror', e => errs2.push(e.message));
await p2.route('**/*', route => {
  const u = route.request().url();
  if (/\/data\/events\.json/.test(u)) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(EVENTS) });
  if (/\/event\.html/.test(u)) return route.fulfill({ status: 200, contentType: 'text/html', body: '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Event</title></head><body><div data-fourge-event-single><div class="fev-empty">Loading…</div></div>' + assets + '</body></html>' });
  return route.abort();
});
const single = async (slug) => {
  await p2.goto(origin + '/event.html?e=' + slug, { waitUntil: 'domcontentloaded' });
  await p2.waitForFunction(() => document.querySelector('.fev-single') || /not found/.test(document.body.textContent), null, { timeout: 5000 });
  return p2.evaluate(() => ({
    links: [...document.querySelectorAll('.fev-links a')].map(a => [a.textContent, a.getAttribute('href'), a.className, a.getAttribute('target')]),
    ld: [...document.querySelectorAll('script[type="application/ld+json"]')].map(s => JSON.parse(s.textContent).url || null),
    alt: (() => { const a = document.querySelectorAll('.fev-links a')[1]; return a ? getComputedStyle(a).backgroundColor : ''; })(),
  }));
};
const gala = await single('fall-gala');
chk(JSON.stringify(gala.links) === JSON.stringify([
  ['Buy tickets', 'https://tickets.example/gala', 'fev-cta', '_blank'], ['More Info', 'https://www.example.org/rsvp', 'fev-cta fev-cta-alt', '_blank'], ['Facebook event', 'https://facebook.com/events/1', 'fev-cta fev-cta-alt', '_blank']]),
  'the event page shows one button per link in order — the first the main one, the others outlined, "More Info" where there is no label, a javascript: link never — ' + JSON.stringify(gala.links));
chk(gala.alt === 'rgba(0, 0, 0, 0)' && JSON.stringify(gala.ld) === JSON.stringify(['https://tickets.example/gala']), '…the others have no fill, and the structured data\'s url is the first link — ' + gala.alt + ' ' + JSON.stringify(gala.ld));
const old = await single('old-event');
chk(JSON.stringify(old.links) === JSON.stringify([['More Info', 'https://old.example/info', 'fev-cta', '_blank']]) && JSON.stringify(old.ld) === JSON.stringify(['https://old.example/info']), 'an event saved before links keeps its More Info button — ' + JSON.stringify(old.links));
const none = await single('no-links');
chk(none.links.length === 0 && JSON.stringify(none.ld) === JSON.stringify([null]), 'an event without links shows none');
chk(errs2.length === 0, 'the event page ran without JS errors' + (errs2.length ? ' — ' + errs2.join(' | ') : ''));
await p2.close();

await done('events_links');
