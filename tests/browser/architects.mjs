// Architect accounts in the admin (1.14.148): Scott and Kellar are both Architects, by the
// server's flag or, as a fallback, by address; an Architect sees every Architect-only and
// Super Admin-only setting and has every module whatever role its session carries; and a
// Super Admin cannot edit or delete an Architect account from the Users panel.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

const r = await p.evaluate(() => ({
  kUser: isArchitect({ username: 'kellar@44interactive.com' }),
  kEmail: isArchitect({ username: 'kellar', email: ' Kellar@44interactive.com ' }),
  scott: isArchitect({ username: 'Scott@44interactive.com' }),
  flag: isArchitect({ username: 'someone@example.com', architect: true }),
  other: isArchitect({ username: 'erik@44interactive.com', email: 'erik@44interactive.com', role: 'superadmin' }),
  none: isArchitect(null),
  lvlK: accessLevel({ username: 'kellar@44interactive.com', role: 'admin' }),
  lvlE: accessLevel({ username: 'erik@44interactive.com', role: 'superadmin' }),
}));
chk(r.kUser && r.kEmail && r.scott && r.flag, 'Kellar and Scott are Architects by address (username or email, any case) and anyone flagged architect:true is — ' + JSON.stringify(r));
chk(!r.other && !r.none, 'a Super Admin is not, and neither is no one');
chk(r.lvlK === 4 && r.lvlE === 3, 'Kellar is level 4 even on an older session that says admin; a Super Admin is level 3');

// an older session for Kellar (role admin, no flag) still sees everything an Architect sees
const v = await p.evaluate(() => {
  localStorage.setItem('cd_user', JSON.stringify({ username: 'kellar@44interactive.com', email: 'kellar@44interactive.com', role: 'admin', permissions: { settings: false } }));
  applyRole();
  // what applyRole itself decided for each element (a panel that is simply not the open one is hidden by its own CSS, not by the role)
  const decided = (sel, want) => { const els = [...document.querySelectorAll(sel)]; return els.length > 0 && els.every(el => want(el.style.display)); };
  return { arch: decided('.architect-only', d => d === 'block'), sa: decided('.superadmin-only', d => d !== 'none'), adm: decided('.admin-only', d => d !== 'none'), perms: getEffectivePerms(getSessionUser()) };
});
chk(v.arch && v.sa && v.adm, 'Kellar sees every Architect-only, Super Admin-only and admin-only setting — ' + JSON.stringify({ arch: v.arch, sa: v.sa, adm: v.adm }));
chk(v.perms.settings === true && v.perms.plugins === true && v.perms.users === true, 'and has every module, the Super Admin set, which a per-user limit left on an older session cannot reduce — ' + JSON.stringify(v.perms));

// a Super Admin cannot edit or delete Kellar from the Users panel
const g = await p.evaluate(async () => {
  localStorage.setItem('cd_user', JSON.stringify({ username: 'erik@44interactive.com', email: 'erik@44interactive.com', role: 'superadmin' }));
  applyRole();
  _users = [{ id: 5, username: 'kellar@44interactive.com', email: 'kellar@44interactive.com', role: 'superadmin', architect: true }];
  const idEl = document.getElementById('eu-id'); idEl.value = '5'; idEl.dataset.idx = '0';
  document.getElementById('eu-role').value = 'editor';
  window.__toasts.length = 0;
  const calls = []; const real = window.apiCall; window.apiCall = async (a, b) => { calls.push(a); return real(a, b); };
  window.uiConfirm = async () => true;
  try { await saveEditUser(); await deleteUser(); } finally { window.apiCall = real; }
  return { toasts: window.__toasts.map(t => t.m), calls, archShown: [...document.querySelectorAll('.architect-only')].some(el => getComputedStyle(el).display !== 'none') };
});
chk(g.toasts.includes('Only an Architect can modify an Architect account') && g.toasts.includes('Only an Architect can delete an Architect account'), 'a Super Admin is told only an Architect can modify or delete an Architect account — ' + JSON.stringify(g.toasts));
chk(!g.calls.includes('save_user') && !g.calls.includes('delete_user'), 'and nothing is sent to the server');
chk(g.archShown === false, 'and a Super Admin does not see the Architect-only settings');

await done('architects');
