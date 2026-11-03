// The Fleet Dashboard is not a page of the site (1.14.150). Sites built from the
// engine's repository carried a copy of the agency's Fleet Dashboard tool
// (fleet-dashboard/), and its index.html showed up in Pages. The admin now leaves
// it out wherever it comes from: the server scan, or an entry an earlier scan left
// in data/pages.json. Pages with similar names stay.
import { openAdmin } from '../lib/harness.mjs';
const pagesJson = JSON.stringify({
  home: { title: 'Home', file: 'index.html', path: 'index.html' },
  'fleet-dashboard-index': { title: 'Fleet Dashboard', file: 'index.html', path: 'fleet-dashboard/index.html' },
});
const { p, chk, done } = await openAdmin({ pagesJson });
await p.evaluate(() => {
  const pg = (path, title) => ({ file: path.split('/').pop(), path, title, size: 100, modified: '', is_cms: false, snippet: '', has_post_list: false });
  window.__pagesList = [pg('index.html', 'Home'), pg('fleet-dashboard/index.html', 'Fleet Dashboard'), pg('about.html', 'About'), pg('services/fleet-dashboard-pricing.html', 'Fleet pricing')];
  _site = { name: 'Client' };
});
await p.evaluate(() => loadPages());
await p.waitForTimeout(200);
const r = await p.evaluate(() => Object.values(_pages).map(x => x.path || x.file));
chk(!r.some(x => /^fleet-dashboard\//i.test(x)), 'Pages leaves out the Fleet Dashboard, from the scan and from data/pages.json alike — ' + JSON.stringify(r));
chk(['index.html', 'about.html', 'services/fleet-dashboard-pricing.html'].every(x => r.includes(x)), 'and keeps every real page, including one with a similar name');
const t = await p.evaluate(() => ['fleet-dashboard/index.html', '/fleet-dashboard/index.html', './Fleet-Dashboard/Index.html', 'services/fleet-dashboard-pricing.html', 'fleet-dashboard.html'].map(isImportablePath));
chk(JSON.stringify(t) === JSON.stringify([false, false, false, true, true]), 'only paths inside the fleet-dashboard/ folder are left out, in any case or prefix — ' + JSON.stringify(t));
await done('fleet page');
