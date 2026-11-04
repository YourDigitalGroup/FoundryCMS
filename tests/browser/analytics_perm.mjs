// Analytics can be switched off per editor (1.14.151). An Admin turns the Analytics
// module off for an editor in Users → Module Access; that editor then has no
// Analytics menu item, and the panel won't open from anywhere that links to it
// (Plugins → "Open Custom Code"). It stays on by default, as it always was, and
// every role above editor always has it.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();
const as = (user) => p.evaluate((user) => { localStorage.setItem('cd_user', JSON.stringify(user)); window.__toasts = []; applyRole(); }, user);
const navShown = () => p.evaluate(() => { const el = document.getElementById('ni-analytics'); return !!el && el.style.display !== 'none'; });
const tryOpen = () => p.evaluate(() => {
  goPanel('p-dash');
  window.__toasts = [];
  navTo('analytics');
  return { open: document.getElementById('p-analytics').classList.contains('on'), toasts: window.__toasts.map(t => t.m) };
});

// 1. an editor, untouched: Analytics as before
await as({ username: 'ed@example.com', role: 'editor' });
chk(await navShown(), 'an editor with no module changes still has the Analytics menu item (on by default, as before)');
let r = await tryOpen();
chk(r.open && !r.toasts.length, 'and the panel opens for them');

// 2. switched off for this editor
await as({ username: 'ed@example.com', role: 'editor', permissions: { analytics: false } });
chk(!(await navShown()), 'with Analytics switched off, the editor has no Analytics menu item');
r = await tryOpen();
chk(!r.open && r.toasts.some(m => /Analytics is switched off for your account/.test(m)), 'and a link to it (Plugins → Open Custom Code) does not open it; it says why — ' + JSON.stringify(r));
chk(await p.evaluate(() => document.getElementById('p-dash').classList.contains('on')), 'the editor stays where they were');

// 3. above editor: always on
for (const role of ['admin', 'superadmin']) {
  await as({ username: role + '@example.com', role, permissions: { analytics: false } });
  chk(await navShown(), 'a' + (role === 'admin' ? 'n Admin' : ' Super Admin') + ' always has Analytics');
}
await as({ username: 'scott@44interactive.com', role: 'editor', architect: true, permissions: { analytics: false } });
chk(await navShown(), 'and so does an Architect, whatever an old session says');

// 4. Users → Module Access: the switch, on by default, and what Save sends
const t = await p.evaluate(() => {
  const host = document.createElement('div'); document.body.appendChild(host);
  host.innerHTML = buildModuleToggles('editor', { ...ROLE_DEFAULTS.editor }, 'tst');
  const tog = document.getElementById('tst-perm-analytics');
  const label = tog && tog.closest('label').innerText.replace(/\s+/g, ' ').trim();
  const before = getTogglePerms('tst').analytics;
  tog.classList.remove('on');
  const after = getTogglePerms('tst').analytics;
  host.innerHTML = buildModuleToggles('editor', { ...ROLE_DEFAULTS.editor, analytics: false }, 'tst');
  const savedOff = document.getElementById('tst-perm-analytics').classList.contains('on');
  host.remove();
  return { label, before, after, savedOff };
});
chk(/^Analytics/.test(t.label || '') && t.before === true, 'Module Access lists Analytics, on by default — ' + t.label);
chk(t.after === false && t.savedOff === false, 'switching it off is what gets saved for the editor, and an editor saved with it off shows it off');
const mods = await p.evaluate(() => { const perms = getEffectivePerms({ role: 'editor', permissions: { analytics: false } }); return EDITOR_MODULES.filter(m => perms[m.key]).map(m => m.label); });
chk(!mods.includes('Analytics') && mods.includes('Pages'), 'the Users list shows that editor without Analytics — ' + mods.join(', '));

await done('analytics permission');
