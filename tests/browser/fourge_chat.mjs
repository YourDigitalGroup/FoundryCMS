// Fourge Chat — the website chat widget (fourge-chat.js, 1.14.149). The real widget file,
// served by run.sh's php -S, on a throwaway page; data/chat.json and admin/api.php are
// answered here, so this suite is about the widget: what it shows, what it sends, where it
// sits next to the accessibility button and the reCAPTCHA badge, that it carries a chat
// across page loads, that nothing a reply contains is ever run as markup, and the CMS preview.
import { launchPage, makeChk } from '../lib/harness.mjs';
const { chk, failed } = makeChk();
const { b, p, errs } = await launchPage();
const ORIGIN = 'http://127.0.0.1:' + (process.env.TEST_PORT || '8931');

const BASE = { enabled: true, title: 'Chat with Bright Smiles', subtitle: 'We usually reply in a few seconds.', welcome: 'Hi! How can we help?',
  launcherText: 'Chat with us', color: '#0B6E4F', position: 'bottom-right', theme: 'light', shape: 'rounded', launcher: 'icon' };
let cfg = { ...BASE };
let extras = '';            // markup dropped onto the test page (a fake ADA button, a badge…)
let calls = [];             // what the widget sent to admin/api.php
let thread = [];            // what chat_poll answers
let pollMode = 'ok';        // 'ok' | 'expired'
const page = (preview) => `<!doctype html><html><head><meta charset="utf-8"><title>Chat test</title>
<style>body{margin:0;min-height:200vh;font-family:Georgia,serif;font-size:16px}</style></head><body><h1>Bright Smiles Dental</h1>${extras}
${preview ? '<div id="box" style="position:relative;width:360px;height:560px"></div><script src="/fourge-chat.js" data-fourge-preview></script>' : '<script src="/fourge-chat.js" defer></script>'}
</body></html>`;
let preview = false;
await p.route(ORIGIN + '/chat-test.html*', r => r.fulfill({ status: 200, contentType: 'text/html', body: page(preview) }));
await p.route('**/data/chat.json*', r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cfg) }));
await p.route(ORIGIN + '/admin/api.php', async r => {
  const d = r.request().postDataJSON() || {};
  calls.push(d);
  const json = (status, body) => r.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  if (d.action === 'chat_start') return json(200, { ok: true, sid: '0123456789abcdef0123456789abcdef' });
  if (d.action === 'chat_send') { thread.push({ id: 'v' + thread.length, from: 'visitor', text: d.message, files: [], at: new Date().toISOString() }); return json(200, { ok: true, id: 'v' + (thread.length - 1) }); }
  if (d.action === 'chat_poll') return pollMode === 'expired' ? json(401, { ok: false, expired: true, error: 'This chat has ended.' }) : json(200, { ok: true, messages: thread });
  return json(400, { ok: false, error: 'unexpected ' + d.action });
});
const sh = (sel) => p.evaluate((sel) => { const h = document.getElementById('fourge-chat'); return !!(h && h.shadowRoot && h.shadowRoot.querySelector(sel)); }, sel);
const q = (fn, arg) => p.evaluate(new Function('arg', 'const R=document.getElementById("fourge-chat").shadowRoot; return (' + fn + ')(R, arg);'), arg);
const open = async (w = 1200, h = 800, clearStore = true) => {
  await p.setViewportSize({ width: w, height: h });
  await p.goto(ORIGIN + '/chat-test.html', { waitUntil: 'load' });
  if (clearStore) { await p.evaluate(() => { try { localStorage.removeItem('fourge-chat'); } catch (e) {} }); await p.reload({ waitUntil: 'load' }); }
  await p.waitForTimeout(300);
};

// 1. switched off: nothing on the page
cfg = { ...BASE, enabled: false };
await open();
chk(!(await p.evaluate(() => !!document.getElementById('fourge-chat'))), 'with the chat switched off in data/chat.json the widget adds nothing to the page');

// 2. the launcher: named, collapsed, in its corner, stacked above an accessibility button that shares it
cfg = { ...BASE };
extras = '<button class="adaptify-launch" style="position:fixed;right:20px;bottom:20px;width:48px;height:48px">A</button>';
await open();
let l = await q(R => { const b = R.querySelector('.fc-launch'); return { name: b.getAttribute('aria-label'), exp: b.getAttribute('aria-expanded'), bottom: b.style.bottom, right: b.style.right, panel: R.querySelector('.fc-panel').hidden }; });
chk(l.name === 'Chat with us' && l.exp === 'false' && l.panel === true, 'the chat button is labelled "Chat with us", collapsed, panel closed — ' + JSON.stringify(l));
chk(l.bottom === '80px' && l.right === '20px', 'it sits above the accessibility button in the same corner (20 + 48 + 12 = 80px up) — ' + l.bottom);
extras = '<div class="grecaptcha-badge" style="position:fixed;right:14px;bottom:14px;width:256px;height:60px;background:#ccc"></div>';
await open();
l = await q(R => R.querySelector('.fc-launch').style.bottom);
chk(l === '84px', 'and above a reCAPTCHA badge in that corner (14 + 60 + 10 = 84px up) — ' + l);
extras = '';

// 3. open: the welcome, the start form, focus on the first field
await open();
await q(R => R.querySelector('.fc-launch').click());
await p.waitForTimeout(120);
let s = await q(R => ({ panel: !R.querySelector('.fc-panel').hidden, exp: R.querySelector('.fc-launch').getAttribute('aria-expanded'), title: R.querySelector('#fc-title').textContent,
  welcome: R.querySelector('.fc-welcome') && R.querySelector('.fc-welcome').textContent, form: !R.querySelector('.fc-start').hidden, compose: R.querySelector('.fc-compose').hidden,
  focus: R.activeElement && R.activeElement.id, phone: R.querySelector('.fc-phone').hidden, font: getComputedStyle(R.querySelector('.fc')).fontFamily }));
chk(s.panel && s.exp === 'true' && s.title === 'Chat with Bright Smiles' && /How can we help\?$/.test(s.welcome || ''), 'opening shows the title and the welcome message — ' + JSON.stringify({ title: s.title, welcome: s.welcome }));
chk(s.form && s.compose && s.focus === 'fc-name', 'a new visitor gets the start form, with focus on Name (the composer waits) — focus: ' + s.focus);
chk(s.phone === true, 'no phone field unless the site asks for one');
chk(/Georgia/.test(s.font), 'the widget takes the site\'s font — ' + s.font);

// 4. validation stays in the widget
calls = [];
await q(R => { const f = R.querySelector('.fc-start'); f.elements.name.value = 'Pat'; f.elements.email.value = 'not-an-email'; f.elements.message.value = 'Hello'; f.requestSubmit(); });
await p.waitForTimeout(80);
s = await q(R => ({ err: R.querySelector('.fc-err').textContent, inv: R.querySelector('#fc-email').getAttribute('aria-invalid'), focus: R.activeElement && R.activeElement.id }));
chk(/valid email/.test(s.err) && s.inv === 'true' && s.focus === 'fc-email' && calls.length === 0, 'a bad email is caught before anything is sent: message, aria-invalid, focus on the field — ' + JSON.stringify(s));

// 5. start + first message → the reply arrives
thread = [];
await q(R => { const f = R.querySelector('.fc-start'); f.elements.email.value = 'pat@example.com'; f.elements.message.value = 'Do you take new patients?'; f.requestSubmit(); });
await p.waitForTimeout(400);
const start = calls.find(c => c.action === 'chat_start'), send = calls.find(c => c.action === 'chat_send');
chk(start && start.name === 'Pat' && start.email === 'pat@example.com' && start.website === '' && send && send.sid === '0123456789abcdef0123456789abcdef' && send.message === 'Do you take new patients?',
  'Start chat sends name + email (honeypot empty), then the first message with the chat id — ' + JSON.stringify({ start, send }));
s = await q(R => ({ form: R.querySelector('.fc-start').hidden, compose: !R.querySelector('.fc-compose').hidden, visitor: [...R.querySelectorAll('.fc-visitor')].map(x => x.textContent), typing: !!R.querySelector('.fc-typing') }));
chk(s.form && s.compose && s.visitor.length === 1 && /Do you take new patients\?$/.test(s.visitor[0]) && s.typing, 'the form gives way to the composer, the message shows, and a typing indicator waits for the reply — ' + JSON.stringify(s));
chk(await p.evaluate(() => JSON.parse(localStorage.getItem('fourge-chat') || '{}').sid === '0123456789abcdef0123456789abcdef'), 'the chat id is remembered in localStorage');
thread.push({ id: 'a1', from: 'agent', text: 'Yes! See https://example.com/new-patients <img src=x onerror="window.__xss=1">', files: [], at: new Date().toISOString() });
await p.waitForTimeout(3200);
s = await q(R => { const a = R.querySelectorAll('.fc-agent:not(.fc-welcome)'); const last = a[a.length - 1]; return { n: a.length, text: last && last.textContent, link: last && last.querySelector('a') && last.querySelector('a').href, rel: last && last.querySelector('a') && last.querySelector('a').rel, img: !!(last && last.querySelector('img')), typing: !!R.querySelector('.fc-typing'), visitors: R.querySelectorAll('.fc-visitor').length }; });
chk(s.n === 1 && /^.*Yes! See https:\/\/example\.com\/new-patients <img/.test(s.text || '') && !s.typing && s.visitors === 1, 'the reply appears once, the typing indicator goes, and the visitor\'s own message is not duplicated — ' + JSON.stringify(s));
chk(s.link === 'https://example.com/new-patients' && /noopener/.test(s.rel) && !s.img && !(await p.evaluate(() => window.__xss)), 'a link in a reply is clickable (noopener), and markup in a reply stays text — nothing runs');

// 6. composer: Enter sends, Escape closes and hands focus back to the button
calls = [];
await q(R => { const t = R.querySelector('#fc-input'); t.focus(); t.value = 'What are your hours?'; });
await p.keyboard.press('Enter');
await p.waitForTimeout(250);
chk(calls.some(c => c.action === 'chat_send' && c.message === 'What are your hours?'), 'Enter in the composer sends the message');
await p.keyboard.press('Escape');
await p.waitForTimeout(80);
s = await q(R => ({ panel: R.querySelector('.fc-panel').hidden, exp: R.querySelector('.fc-launch').getAttribute('aria-expanded'), focus: R.activeElement && R.activeElement.className }));
chk(s.panel && s.exp === 'false' && /fc-launch/.test(s.focus || ''), 'Escape closes the panel and returns focus to the chat button — ' + JSON.stringify(s));

// 7. a new page: the conversation carries on, nothing doubled, nothing counted as new
await open(1200, 800, false);
await p.waitForTimeout(500);
s = await q(R => ({ visitor: R.querySelectorAll('.fc-visitor').length, agent: R.querySelectorAll('.fc-agent:not(.fc-welcome)').length, badge: R.querySelector('.fc-badge').hidden, compose: !R.querySelector('.fc-compose').hidden }));
chk(s.visitor === 2 && s.agent === 1 && s.badge === true && s.compose, 'on the next page the chat is restored from the server (2 visitor messages, 1 reply), with no unread badge for what was already read — ' + JSON.stringify(s));

// 8. an ended chat: said plainly, and the start form is back
pollMode = 'expired';
await q(R => { const b = R.querySelector('.fc-launch'); if (b.getAttribute('aria-expanded') !== 'true') b.click(); });
await p.waitForTimeout(400);
s = await q(R => ({ note: [...R.querySelectorAll('.fc-note')].map(n => n.textContent).join(' | '), form: !R.querySelector('.fc-start').hidden, compose: R.querySelector('.fc-compose').hidden }));
chk(/This chat has ended\./.test(s.note) && s.form && s.compose && !(await p.evaluate(() => JSON.parse(localStorage.getItem('fourge-chat') || '{}').sid)), 'an expired chat says "This chat has ended.", forgets the chat id and offers the start form again — ' + JSON.stringify(s));
pollMode = 'ok';

// 9. design choices: dark, left, icon + text, square, a phone field
cfg = { ...BASE, theme: 'dark', position: 'bottom-left', launcher: 'pill', shape: 'square', askPhone: true, launcherText: 'Questions? Chat' };
await open();
s = await q(R => { const b = R.querySelector('.fc-launch'); const t = R.querySelector('.fc-launch-text'); return { theme: R.querySelector('.fc').getAttribute('data-theme'), left: b.style.left, right: b.style.right, text: t.textContent, shown: getComputedStyle(t).display !== 'none', on: getComputedStyle(b).color, phone: R.querySelector('.fc-phone').hidden }; });
chk(s.theme === 'dark' && s.left === '20px' && !s.right && s.text === 'Questions? Chat' && s.shown && s.phone === false, 'dark theme, bottom left, icon-and-text button, phone field — ' + JSON.stringify(s));
chk(s.on === 'rgb(255, 255, 255)', 'text on the dark green accent is white, picked for contrast — ' + s.on);
cfg = { ...BASE, color: '#F5D547' };
await open();
s = await q(R => getComputedStyle(R.querySelector('.fc-launch')).color);
chk(s === 'rgb(17, 20, 24)', 'and near-black on a pale yellow accent — ' + s);

// 10. a phone-sized window: the open chat fills the screen
cfg = { ...BASE };
await open(390, 760);
await q(R => R.querySelector('.fc-launch').click());
await p.waitForTimeout(120);
s = await q(R => { const r = R.querySelector('.fc-panel').getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), btn: R.querySelector('.fc-launch').hidden }; });
chk(s.x === 0 && s.y === 0 && s.w === 390 && s.h === 760 && s.btn, 'on a 390px phone the open chat fills the screen and the button steps aside — ' + JSON.stringify(s));

// 11. the CMS preview: the same widget drawn into a box, offline
preview = true; calls = [];
await p.setViewportSize({ width: 1200, height: 800 });
await p.goto(ORIGIN + '/chat-test.html', { waitUntil: 'load' });
await p.waitForTimeout(200);
s = await p.evaluate(() => {
  const box = document.getElementById('box');
  window.FourgeChat.preview(box, { title: 'Preview title', color: '#7A2E8E', theme: 'dark' });
  const host = box.querySelector('[data-fourge-chat-preview] > div'); const R = host && host.shadowRoot;
  return { has: !!R, global: !!document.getElementById('fourge-chat'), title: R && R.querySelector('#fc-title').textContent, open: R && !R.querySelector('.fc-panel').hidden,
    msgs: R && R.querySelectorAll('.fc-msg').length, theme: R && R.querySelector('.fc').getAttribute('data-theme'), inBox: R && getComputedStyle(R.querySelector('.fc-panel')).position };
});
await p.waitForTimeout(300);
chk(s.has && !s.global && s.title === 'Preview title' && s.open && s.msgs >= 3 && s.theme === 'dark' && s.inBox === 'absolute', 'FourgeChat.preview draws the widget, open with sample messages, inside the box (not over the page) — ' + JSON.stringify(s));
chk(calls.length === 0, 'the preview never calls the server');
preview = false;

chk(errs.length === 0, 'no JS errors during any scenario' + (errs.length ? (' — ' + errs.join(' | ').slice(0, 300)) : ''));
await b.close();
console.log(failed() ? '\nfourge_chat SUITE FAILED' : '\nall fourge_chat assertions passed');
process.exit(failed() ? 1 : 0);
