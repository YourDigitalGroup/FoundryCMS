// Plugins → SEO Platform → Import package (1.14.152). The platform now labels its
// files "ydg-deploy-package" (they said "44i-deploy-package"), and the screen took
// only the old name: Preview and Apply package stayed greyed out, and the reason
// sat in a small grey line under them, so clicking Apply "did nothing". Both
// names are accepted, Apply sends the package, and a file that can't be used
// says why in a toast as well.
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();

await p.evaluate(() => {
  window.uiConfirm = async () => true;
  window.seoLoadData = async () => {}; window.loadPages = async () => {};
  window.__pkgCalls = [];
  const base = window.apiCall;
  window.apiCall = async (action, body = {}) => {
    if (action === 'seo_package') {
      window.__pkgCalls.push(body);
      return { ok: true, dry_run: body.mode !== 'apply', source: { client: 'E2E Masonry', tier: 'pro' },
               applied: [{ type: 'content_page', item: 'Brick Supply Testville, IN', note: 'created brick-supply-testville-in.html' }],
               skipped: [], manual: [], counts: { applied: 1, skipped: 0, manual: 0 } };
    }
    return base(action, body);
  };
});

const pkg = (format) => JSON.stringify({ format, format_version: 1, generated_at: '2026-11-04T22:10:02Z', site: 'https://e2e-site.example',
  business: { name: 'E2E Masonry' }, content: [{ external_id: 'x1', title: 'Brick Supply Testville, IN', body_html: '<p>Brick.</p>', post_type: 'page', status: 'draft' }],
  manual_tasks: [{ kind: 'gbp_post', title: 'GBP post' }] });
const pick = async (name, text) => {
  await p.evaluate(() => { window.__toasts = []; });
  await p.setInputFiles('#pkg-file', { name, mimeType: 'application/json', buffer: Buffer.from(text) });
  await p.waitForFunction(() => !/^Reading /.test(document.getElementById('pkg-status').textContent), null, { timeout: 5000 });
  return p.evaluate(() => ({
    status: document.getElementById('pkg-status').textContent,
    preview: document.getElementById('pkg-preview').disabled, apply: document.getElementById('pkg-apply').disabled,
    toasts: window.__toasts.map(t => t.m + ' [' + t.t + ']'),
  }));
};

// 1. the platform's file as it is sent now
let s = await pick('deploy-e2e-site.example_-2026-11-04.json', pkg('ydg-deploy-package'));
chk(!s.preview && !s.apply && /^Loaded deploy-e2e-site/.test(s.status) && /1 content, 0 redirects, 1 manual task/.test(s.status) && !s.toasts.length,
  'a "ydg-deploy-package" file is loaded and Preview / Apply package are enabled — ' + JSON.stringify(s));
await p.evaluate(() => document.getElementById('pkg-apply').click());
await p.waitForFunction(() => window.__pkgCalls.length === 1, null, { timeout: 5000 });
const sent = await p.evaluate(() => ({ call: window.__pkgCalls[0], report: document.getElementById('pkg-report').textContent, toasts: window.__toasts.map(t => t.m) }));
chk(sent.call.mode === 'apply' && sent.call.package.format === 'ydg-deploy-package' && sent.call.package.content.length === 1,
  'clicking Apply package sends the package to the server to apply — ' + JSON.stringify(sent.call).slice(0, 160));
chk(/^Applied/.test(sent.report) && sent.toasts.some(m => /^Package applied — 1 change/.test(m)), 'and the report and a confirmation follow — ' + sent.report.slice(0, 80));

// 2. the old name still works
s = await pick('deploy-old.json', pkg('44i-deploy-package'));
chk(!s.apply && /^Loaded/.test(s.status), 'a file with the old name, "44i-deploy-package", still loads');

// 3. files that can't be used say why, in a toast as well as the status line
s = await pick('export.json', pkg('something-else'));
chk(s.apply && s.preview && s.toasts.length === 1 && /not a deploy package from the SEO platform/.test(s.toasts[0]) && /\[err\]$/.test(s.toasts[0]) && s.status === s.toasts[0].replace(/ \[err\]$/, ''),
  'another JSON file keeps the buttons off and says why in a toast — ' + JSON.stringify(s.toasts));
s = await pick('broken.json', '{"format": "ydg-deploy-package",');
chk(s.apply && s.toasts.length === 1 && /not valid JSON/.test(s.toasts[0]), 'a broken file says it is not valid JSON — ' + JSON.stringify(s.toasts));
s = await pick('future.json', JSON.stringify({ format: 'ydg-deploy-package', format_version: 2 }));
chk(s.apply && s.toasts.length === 1 && /format version 2/.test(s.toasts[0]), 'a newer format version says to update Fourge first — ' + JSON.stringify(s.toasts));

await done('SEO package file');
