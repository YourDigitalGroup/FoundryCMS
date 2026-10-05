// Shared harness for the Fourge admin regression suites (tests/browser/*.mjs).
//
// - launches headless Chromium: CHROME_PATH, else a Playwright browsers folder
//   (PLAYWRIGHT_BROWSERS_PATH or ~/.cache/ms-playwright), else a system Chrome
// - the admin is loaded from TURL (default http://127.0.0.1:8931/admin/index.html,
//   served by tests/run.sh with php -S from the repo root)
// - CodeMirror is served from tests/.cache/cm when run.sh has cached it (no CDN
//   dependency); every other third-party request is aborted so suites stay fast
//   and deterministic; data/pages.json and data/site.json are answered inline
// - launchPage(): just the page, for suites that stub the API themselves
// - openAdmin(): the page with apiCall/toast/ghMirror stubs (an in-memory file
//   store at window.__files, writes logged to window.__writes, toasts to
//   window.__toasts), the signed-in shell revealed so layout-dependent clicks
//   work, and chk()/done() helpers
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const TESTS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const ROOT = path.resolve(TESTS, '..');
export const TURL = process.env.TURL || ('http://127.0.0.1:' + (process.env.TEST_PORT || '8931') + '/admin/index.html');
const CM_CACHE = path.join(TESTS, '.cache', 'cm');
const TYPES = { '.js': 'application/javascript', '.css': 'text/css' };

export function chromePath() {
  if (process.env.CHROME_PATH && fs.existsSync(process.env.CHROME_PATH)) return process.env.CHROME_PATH;
  const cands = [];
  for (const base of [process.env.PLAYWRIGHT_BROWSERS_PATH, path.join(os.homedir(), '.cache', 'ms-playwright')].filter(Boolean)) {
    let dirs = []; try { dirs = fs.readdirSync(base); } catch (e) {}
    for (const d of dirs.filter(d => /^chromium-\d+$/.test(d)).sort().reverse()) {
      cands.push(path.join(base, d, 'chrome-linux', 'chrome'), path.join(base, d, 'chrome-linux64', 'chrome'),
        path.join(base, d, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
        path.join(base, d, 'chrome-mac-arm64', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'));
    }
  }
  cands.push('/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium');
  const hit = cands.find(c => fs.existsSync(c));
  if (hit) return hit;
  try { const p = chromium.executablePath(); if (p && fs.existsSync(p)) return p; } catch (e) {}
  throw new Error('No Chromium found. Set CHROME_PATH=/path/to/chrome, or install one with: npx playwright install chromium');
}

export async function launchPage(opts = {}) {
  const b = await chromium.launch({ executablePath: chromePath(), headless: true, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  const origin = new URL(TURL).origin;
  await p.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith(origin) || u.startsWith('http://127.0.0.1:') || u.startsWith('http://localhost:')) return route.continue();
    const m = u.match(/\/codemirror\/[\d.]+\/(.+)$/);
    if (m) {
      const f = path.join(CM_CACHE, m[1]);
      if (fs.existsSync(f)) return route.fulfill({ status: 200, contentType: TYPES[path.extname(f)] || 'text/plain', body: fs.readFileSync(f) });
      return route.continue();   // not cached (offline run.sh): let the CDN answer
    }
    return route.abort();
  });
  await p.route('**/data/pages.json*', r => r.fulfill({ status: 200, contentType: 'application/json', body: opts.pagesJson || '{}' }));
  await p.route('**/data/site.json*', r => r.fulfill({ status: 200, contentType: 'application/json', body: opts.siteJson || '{}' }));
  await p.goto(TURL, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);
  return { b, p, errs };
}

export function makeChk() {
  let fail = false;
  const chk = (c, l) => { console.log((c ? 'ok   ' : 'FAIL ') + l); if (!c) fail = true; };
  return { chk, failed: () => fail };
}

export async function openAdmin(opts = {}) {
  const { b, p, errs } = await launchPage(opts);
  await p.evaluate(() => {
    localStorage.setItem('cd_token', 't');
    window.__files = {}; window.__writes = []; window.__toasts = [];
    window.toast = (m, t) => window.__toasts.push({ m, t });
    window.ghMirror = async () => ({ ok: true });
    // reveal the app shell the way sign-in does, so layout-dependent interactions (clicks in the editor) work
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('shell').classList.add('on');
    window.apiCall = async (action, body = {}) => {
      if (action === 'read_file') { if (body.path in window.__files) return { content: window.__files[body.path] }; const e = new Error('File not found: ' + body.path); e.status = 404; throw e; }
      if (action === 'write_file') { window.__files[body.path] = body.content; window.__writes.push({ path: body.path, content: body.content }); return { ok: true, size: body.content.length }; }
      if (action === 'list_pages') return { pages: window.__pagesList || [], root: '/x', postsHtml: false };
      return { ok: true };
    };
  });
  const { chk, failed } = makeChk();
  return {
    b, p, errs, chk,
    done: async (name) => {
      chk(errs.length === 0, 'no JS errors during any scenario' + (errs.length ? (' — ' + errs.join(' | ').slice(0, 300)) : ''));
      await b.close();
      console.log(failed() ? ('\n' + name + ' SUITE FAILED') : ('\nall ' + name + ' assertions passed'));
      process.exit(failed() ? 1 : 0);
    }
  };
}
