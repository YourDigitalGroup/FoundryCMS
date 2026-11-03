// Analytics → Setup → Chatbot with Fourge Chat (1.14.149). The real admin page with
// apiCall stubbed (harness openAdmin) and the chat_* calls answered here: choosing
// between Fourge Chat, the CRM's embed code and no chat; the settings and the live
// preview (the real fourge-chat.js); the bot box in each state the CRM can be in; the
// AI test; and what Save & Apply writes — data/chat.json and the loader on every page.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();
const PAGE = '<!doctype html><html><head><meta charset="utf-8"><title>Home</title></head><body><h1>Home</h1></body></html>';
const setup = (site, user) => p.evaluate(({ PAGE, site, user }) => {
  window.__files = { 'index.html': PAGE }; window.__writes = []; window.__toasts = [];
  _pages = { home: { path: 'index.html', file: 'index.html', title: 'Home' } };
  _site = site;
  localStorage.setItem('cd_user', JSON.stringify(user));
  window.__chat = []; window.__answers = {};
  if (!window.__baseApi) window.__baseApi = window.apiCall;
  window.apiCall = async (action, body = {}) => {
    if (action.startsWith('chat_')) {
      window.__chat.push({ action, body });
      const a = window.__answers[action];
      return typeof a === 'function' ? a(body) : (a || { ok: false, error: 'no answer for ' + action });
    }
    return window.__baseApi(action, body);
  };
}, { PAGE, site, user });
const SITE = () => ({ name: 'Bright Smiles Dental', phone: '555-0100', email: 'hi@brightsmiles.example', website: 'https://brightsmiles.example', brand: { primary: '#0B6E4F' }, hours: { weekday: '8am–5pm' } });
const SA = { username: 'sa@example.com', role: 'superadmin' };
const openCard = () => p.evaluate(() => { goPanel('p-analytics'); gaTab('setup'); });
const radio = () => p.evaluate(() => (document.querySelector('input[name="cb-mode"]:checked') || {}).value || '');
const vis = (id) => p.evaluate((id) => !document.getElementById(id).hidden, id);
const botText = () => p.evaluate(() => document.getElementById('cb-bot').innerText);

// 1. a site with no chat, and one saved before Fourge Chat existed
await setup(SITE(), SA);
await openCard();
await p.waitForTimeout(200);
chk((await radio()) === 'none' && !(await vis('cb-embed-wrap')) && !(await vis('cb-fourge-wrap')), 'a site with no chat opens on "No chat", nothing else showing');
chk(/No chat on the site\./.test(await p.evaluate(() => document.getElementById('cb-state').textContent)), 'and the status line says so');
await setup({ ...SITE(), analytics: { chatbot: { embed: '<script src="https://widgets.leadconnectorhq.com/loader.js" data-widget-id="w1"></script>', testing: false } } }, SA);
await openCard();
await p.waitForTimeout(200);
chk((await radio()) === 'embed' && (await vis('cb-embed-wrap')) && (await p.evaluate(() => document.getElementById('cb-embed').value.includes('data-widget-id="w1"'))), 'a site saved with an embed code (no mode yet) opens on "CRM embed code" with its code');

// 2. Fourge Chat: the settings, the defaults, the live preview, the bot box asking for the CRM connection
await setup(SITE(), SA);
await p.evaluate(() => { window.__answers.chat_bot_status = { ok: false, needsConnection: true, error: 'Connect the CRM first: Plugins → Lead Generation → token and Location ID.' }; _cbLoaded = false; });
await openCard();
await p.evaluate(() => { document.querySelector('input[name="cb-mode"][value="fourge"]').click(); });
await p.waitForTimeout(900);
let s = await p.evaluate(() => ({ fourge: !document.getElementById('cb-fourge-wrap').hidden, embed: document.getElementById('cb-embed-wrap').hidden, title: document.getElementById('cbx-title').value, color: fcVal('cbx-color'),
  welcome: document.getElementById('cbx-welcome').value }));
chk(s.fourge && s.embed && s.title === 'Chat with us' && s.color.toLowerCase() === '#0b6e4f' && /How can we help/.test(s.welcome), 'choosing Fourge Chat shows its settings, filled with defaults and the site\'s brand color — ' + JSON.stringify(s));
s = await p.evaluate(() => { const h = document.querySelector('#cb-preview [data-fourge-chat-preview] > div'); const R = h && h.shadowRoot; return { has: !!R, title: R && R.querySelector('#fc-title').textContent, accent: R && getComputedStyle(R.querySelector('.fc-head')).backgroundColor }; });
chk(s.has && s.title === 'Chat with us' && s.accent === 'rgb(11, 110, 79)', 'the preview is the real widget, in the brand color — ' + JSON.stringify(s));
await p.evaluate(() => { const t = document.getElementById('cbx-title'); t.value = 'Talk to Bright Smiles'; t.dispatchEvent(new Event('input', { bubbles: true })); });
await p.waitForTimeout(400);
chk((await p.evaluate(() => document.querySelector('#cb-preview [data-fourge-chat-preview] > div').shadowRoot.querySelector('#fc-title').textContent)) === 'Talk to Bright Smiles', 'typing a new title updates the preview');
let t = await botText();
chk(/Plugins → Lead Generation/.test(t) && /Open Plugins/.test(t), 'without a CRM connection the bot box points to Plugins → Lead Generation — ' + t.replace(/\s+/g, ' ').slice(0, 140));

// 3. no bot yet → instructions written from the site's details → Create the bot
await p.evaluate(() => { window.__answers.chat_bot_status = { ok: true, bot: null, main: null, count: 0 }; cbBotRefresh(); });
await p.waitForTimeout(150);
s = await p.evaluate(() => ({ text: document.getElementById('cb-bot').innerText, name: document.getElementById('cbb-name').value, biz: document.getElementById('cbb-business').value, ins: document.getElementById('cbb-instructions').value }));
chk(/No bot yet/.test(s.text) && s.name === 'Bright Smiles Dental Assistant' && s.biz === 'Bright Smiles Dental', 'no bot yet: a name and business filled in from the site — ' + JSON.stringify({ name: s.name, biz: s.biz }));
chk(/assistant for Bright Smiles Dental/.test(s.ins) && /- Phone: 555-0100/.test(s.ins) && /- Website: https:\/\/brightsmiles\.example/.test(s.ins) && /Monday–Friday 8am–5pm/.test(s.ins) && /Never make up prices/.test(s.ins), 'its starting instructions carry the site\'s phone, website and hours, and the ground rules');
await p.evaluate(() => {
  window.__answers.chat_bot_save = (b) => ({ ok: true, done: 'created', bot: { id: 'ag_1', name: b.name, mode: 'auto-pilot', channels: ['Live_Chat'], isPrimary: true } });
  window.__answers.chat_bot_status = { ok: true, bot: { id: 'ag_1', name: 'Bright Smiles Dental Assistant', mode: 'auto-pilot', channels: ['Live_Chat'], isPrimary: true, businessName: 'Bright Smiles Dental', personality: 'Warm', goal: 'Help', instructions: 'Short.', usesFullPrompt: false }, main: null, count: 1 };
  [...document.querySelectorAll('#cb-bot button')].find(b => /Create the bot/.test(b.textContent)).click();
});
await p.waitForTimeout(250);
s = await p.evaluate(() => ({ save: window.__chat.find(c => c.action === 'chat_bot_save'), text: document.getElementById('cb-bot').innerText, toasts: window.__toasts.map(x => x.m) }));
chk(s.save && s.save.body.op === 'create' && s.save.body.name === 'Bright Smiles Dental Assistant' && /Phone: 555-0100/.test(s.save.body.instructions), 'Create the bot sends op create with the fields as shown');
chk(/Bright Smiles Dental Assistant answers website chat\./.test(s.text) && /Answers on its own/.test(s.text) && /Run AI test/.test(s.text) && s.toasts.some(m => /Bot created/.test(m)), 'and the box then shows the bot answering on its own, with the AI test — ' + s.text.replace(/\s+/g, ' ').slice(0, 120));

// 4. the AI test, step by step, until the bot's reply
let polls = 0;
await p.evaluate(() => {
  window.__answers.chat_ai_test = { ok: true, conversationId: 'cv_t', since: 1000, steps: [{ label: 'Find the bot that answers website chat', ok: true, detail: 'Bright Smiles Dental Assistant (auto-pilot)' }, { label: 'Send a visitor message into the CRM as Live Chat', ok: true, detail: '' }] };
  window.__polls = 0;
  window.__answers.chat_ai_test_poll = () => (++window.__polls < 2 ? { ok: true, done: false, seenInbound: true } : { ok: true, done: true, reply: 'Hi! We can help you book a cleaning.', seconds: 4, replyType: 'TYPE_LIVE_CHAT' });
  document.getElementById('cb-test-btn').click();
});
await p.waitForTimeout(500);
t = await p.evaluate(() => document.getElementById('cb-test').innerText);
chk(/Find the bot that answers website chat/.test(t) && /Waiting for the bot's reply/.test(t), 'Run AI test lists each step, then waits for the reply — ' + t.replace(/\s+/g, ' ').slice(0, 160));
await p.waitForTimeout(6500);
s = await p.evaluate(() => ({ t: document.getElementById('cb-test').innerText, polls: window.__polls, poll: window.__chat.filter(c => c.action === 'chat_ai_test_poll').map(c => c.body)[0], btn: document.getElementById('cb-test-btn').disabled }));
chk(/The bot replied after 4 seconds/.test(s.t) && /Hi! We can help you book a cleaning\./.test(s.t) && /Fourge Chat works with your CRM/.test(s.t) && !s.btn, 'it reports the bot\'s reply, its delay, and that Fourge Chat works — ' + s.t.replace(/\s+/g, ' ').slice(-170));
chk(s.poll && s.poll.conversationId === 'cv_t' && s.poll.since === 1000, 'the polls ask about the test conversation the test opened');
await p.evaluate(() => { window.__answers.chat_ai_test = { ok: false, routeBlocked: true, steps: [{ label: 'Send a visitor message into the CRM as Live Chat', ok: false, detail: 'HTTP 422: conversationProviderId should not be empty' }] }; document.getElementById('cb-test-btn').click(); });
await p.waitForTimeout(300);
t = await p.evaluate(() => document.getElementById('cb-test').innerText);
chk(/conversationProviderId should not be empty/.test(t) && /custom-channel route/.test(t), 'when the CRM refuses the message, the result says so in its words and what comes next');

// 5. a main bot that isn't on Live Chat yet; a bot that only drafts
await p.evaluate(() => { window.__chat = []; window.__answers.chat_bot_status = { ok: true, bot: null, main: { id: 'ag_main', name: 'Front Desk Bot', mode: 'auto-pilot', channels: ['SMS'], isPrimary: true }, count: 1 }; cbBotRefresh(); });
await p.waitForTimeout(150);
t = await botText();
chk(/Front Desk Bot/.test(t) && /isn't answering Live Chat yet/.test(t) && /Use Front Desk Bot for website chat/.test(t), 'an existing main bot is offered for website chat instead of a second bot');
await p.evaluate(() => { window.__answers.chat_bot_save = { ok: true, done: 'adopted', bot: { id: 'ag_main' } }; [...document.querySelectorAll('#cb-bot button')].find(b => /Use Front Desk Bot/.test(b.textContent)).click(); });
await p.waitForTimeout(200);
chk(await p.evaluate(() => { const c = window.__chat.find(c => c.action === 'chat_bot_save'); return !!c && c.body.op === 'use' && !('instructions' in c.body); }), '"Use … for website chat" sends op use, and none of the bot\'s prompt');
await p.evaluate(() => { window.__chat = []; window.__answers.chat_bot_status = { ok: true, bot: { id: 'ag_main', name: 'Front Desk Bot', mode: 'suggestive', channels: ['SMS', 'Live_Chat'], isPrimary: true, usesFullPrompt: true, personality: 'x', goal: 'y', instructions: 'z' }, main: null, count: 1 }; cbBotRefresh(); });
await p.waitForTimeout(150);
s = await p.evaluate(() => ({ text: document.getElementById('cb-bot').textContent, locked: document.getElementById('cbb-instructions').disabled }));
chk(/Drafts replies only/.test(s.text) && /won't get an instant answer/.test(s.text) && s.locked && /full custom prompt/.test(s.text), 'a bot in suggestive mode is flagged, and a bot on a full custom prompt can\'t be edited here — ' + s.text.replace(/\s+/g, ' ').slice(0, 150));
await p.evaluate(() => { window.__answers.chat_bot_save = { ok: true, done: 'updated', bot: { id: 'ag_main' } }; [...document.querySelectorAll('#cb-bot button')].find(b => /Let it answer on its own/.test(b.textContent)).click(); });
await p.waitForTimeout(200);
chk(await p.evaluate(() => { const c = window.__chat.find(c => c.action === 'chat_bot_save'); return !!c && c.body.op === 'update' && c.body.autoPilot === true && c.body.agentId === 'ag_main' && !('instructions' in c.body); }), '"Let it answer on its own" sends op update with autoPilot only');

// 6. Save & Apply: data/chat.json, site.json and the loader on every page (in testing)
await p.evaluate(() => { window.__writes = []; document.getElementById('cb-testing').checked = true; document.getElementById('cbx-phone').checked = true; document.getElementById('cbx-theme').value = 'dark'; });
await p.evaluate(() => gaSaveChatbot());
await p.waitForTimeout(400);
s = await p.evaluate(() => {
  const w = (path) => [...window.__writes].reverse().find(x => x.path === path);
  const chat = w('data/chat.json'), site = w('data/site.json'), home = w('index.html');
  const m = home && home.content.match(/data-b64="([^"]+)"/);
  return { chat: chat && JSON.parse(chat.content), site: site && JSON.parse(site.content).analytics.chatbot, block: home && /<!-- foundry-chatbot:start -->/.test(home.content), payload: m ? atob(m[1]) : '', gate: home && /\?chatbot=true|chatbot/.test(home.content) && /fourge_chatbot_preview/.test(home.content), state: document.getElementById('cb-state').innerText };
});
chk(s.chat && s.chat.enabled === true && s.chat.title === 'Talk to Bright Smiles' && s.chat.theme === 'dark' && s.chat.askPhone === true && s.chat.color.toLowerCase() === '#0b6e4f', 'Save writes data/chat.json: on, with the settings as set — ' + JSON.stringify(s.chat).slice(0, 160));
chk(s.site && s.site.mode === 'fourge' && s.site.testing === true, 'site.json records the choice (mode fourge, in testing)');
chk(s.block && s.payload === '<script src="/fourge-chat.js" defer></script>' && s.gate, 'every page gets the managed chatbot block holding the Fourge Chat loader behind the ?chatbot=true gate');
chk(/In testing\./.test(s.state), 'the status line says it is in testing — ' + s.state.replace(/\s+/g, ' ').slice(0, 100));
await p.evaluate(() => { window.__writes = []; document.getElementById('cb-testing').checked = false; });
await p.evaluate(() => gaSaveChatbot());
await p.waitForTimeout(400);
s = await p.evaluate(() => { const home = [...window.__writes].reverse().find(x => x.path === 'index.html'); return { live: home && /<script src="\/fourge-chat\.js" defer><\/script>/.test(home.content) && !/data-b64/.test(home.content), state: document.getElementById('cb-state').innerText }; });
chk(s.live && /Fourge Chat loads for every visitor/.test(s.state), 'out of testing, the loader is on the pages as is, and the status line says Fourge Chat is live — ' + s.state.replace(/\s+/g, ' ').slice(0, 100));

// 7. back to no chat: the widget's switch goes off and the block comes off the pages
await p.evaluate(() => { window.__writes = []; document.querySelector('input[name="cb-mode"][value="none"]').click(); });
await p.evaluate(() => gaSaveChatbot());
await p.waitForTimeout(400);
s = await p.evaluate(() => { const w = (path) => [...window.__writes].reverse().find(x => x.path === path); const chat = w('data/chat.json'), home = w('index.html'); return { chat: chat && JSON.parse(chat.content), block: home ? /foundry-chatbot/.test(home.content) : null, mode: JSON.parse(w('data/site.json').content).analytics.chatbot.mode }; });
chk(s.chat && s.chat.enabled === false && s.chat.title === 'Talk to Bright Smiles' && s.block === false && s.mode === 'none', 'choosing "No chat" turns the widget off in data/chat.json (keeping its settings), takes the block off the pages, and records mode none — ' + JSON.stringify({ enabled: s.chat && s.chat.enabled, block: s.block, mode: s.mode }));

// 8. below Super Admin: the design can be edited, the bot can't
await setup(SITE(), { username: 'ed@example.com', role: 'admin' });
await p.evaluate(() => { _cbLoaded = false; window.__answers.chat_bot_status = { ok: true, bot: null, main: null, count: 0 }; });
await openCard();
await p.evaluate(() => { document.querySelector('input[name="cb-mode"][value="fourge"]').click(); });
await p.waitForTimeout(300);
t = await botText();
chk(/A Super Admin sets up the bot/.test(t) && !(await p.evaluate(() => window.__chat.some(c => c.action === 'chat_bot_status'))), 'an Admin sees that a Super Admin sets up the bot, and the CRM is not asked');

await done('chat admin');
