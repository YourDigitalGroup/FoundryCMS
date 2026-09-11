#!/usr/bin/env node
/**
 * Fourge widget audit — find third-party widget DOM baked into published pages.
 *
 * Background: before engine 1.14.65 the visual editor saved the LIVE document,
 * so a chat widget (GoHighLevel/LeadConnector etc.), cookie banner or reCAPTCHA
 * that had already rendered got written into the .html file. The next visit ran
 * the loader again and visitors saw two chat bubbles. This script scans every
 * page in a site's sitemap.xml and reports the tell-tale markup per page, so a
 * whole fleet of Fourge sites can be checked from one terminal.
 *
 * Usage:
 *   node docs/tools/widget-audit.mjs https://example.com
 *   node docs/tools/widget-audit.mjs https://example.com --json > audit.json
 *
 * Exit code 0 = clean, 1 = at least one page carries baked-in widget DOM,
 * 2 = the site or its sitemap could not be fetched. No dependencies; Node 18+.
 * This file lives under docs/ and is NOT deployed to the web server.
 */

const site = (process.argv[2] || '').replace(/\/+$/, '');
const asJson = process.argv.includes('--json');
if (!/^https?:\/\//.test(site)) {
  console.error('Usage: node widget-audit.mjs https://your-site.com [--json]');
  process.exit(2);
}

// What to count. "baked" patterns are runtime OUTPUT that should never be in a
// file; "install" patterns are legitimate snippets (counted for context — two of
// them on one page is a double install).
const CHECKS = [
  { key: 'chatWidgetEl',  kind: 'baked',   re: /<chat-widget\b/gi,                                          label: 'GHL <chat-widget> element' },
  { key: 'ghlBundle',     kind: 'baked',   re: /leadconnectorhq\.com\/chat-widget\/chat-widget(\.esm)?\.js/gi, label: 'GHL widget bundle script' },
  { key: 'ghlPhoneLib',   kind: 'baked',   re: /leadconnectorhq\.com\/libphonenumber/gi,                    label: 'GHL phone library' },
  { key: 'stencilStyle',  kind: 'baked',   re: /<style data-styles/gi,                                      label: 'Stencil <style data-styles>' },
  { key: 'loaderInstance',kind: 'baked',   re: /data-loader-instance-id=/gi,                                label: 'loader instance id attr' },
  { key: 'cookieBanner',  kind: 'baked',   re: /class="cc-(?:window|revoke)/gi,                             label: 'cookie-consent banner DOM' },
  { key: 'recaptchaRt',   kind: 'baked',   re: /gstatic\.com\/recaptcha\/releases\/|class="grecaptcha-badge/gi, label: 'reCAPTCHA runtime' },
  { key: 'originTrial',   kind: 'baked',   re: /http-equiv="origin-trial"/gi,                               label: 'origin-trial meta (injected)' },
  { key: 'adaptifyRoot',  kind: 'baked',   re: /id="adaptify-root"/gi,                                      label: 'ADAptify live root' },
  { key: 'otherChatDom',  kind: 'baked',   re: /id="(?:tidio-chat|intercom-container|hubspot-messages-iframe-container|drift-widget-container|podium-website-widget|birdeye-webchat|tawkchat-container)"|class="crisp-client/gi, label: 'other chat vendor container' },
  { key: 'ghlLoader',     kind: 'install', re: /leadconnectorhq\.com\/loader\.js/gi,                        label: 'GHL install snippet (loader.js)' },
  { key: 'otherLoader',   kind: 'install', re: /embed\.tawk\.to|code\.tidio\.co|widget\.intercom\.io|js\.hs-scripts\.com|js\.driftt\.com|client\.crisp\.chat|connect\.podium\.com|birdeye\.com\/embed/gi, label: 'other chat install snippet' },
  { key: 'customHead',    kind: 'info',    re: /<!-- foundry-custom-head:start -->/g,                       label: 'Custom Code head block' },
  { key: 'customBody',    kind: 'info',    re: /<!-- foundry-custom-body:start -->/g,                       label: 'Custom Code body block' },
];

async function fetchText(url) {
  const res = await fetch(url, { headers: { 'user-agent': 'Mozilla/5.0 fourge-widget-audit' }, redirect: 'follow' });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  return res.text();
}

function count(re, html) { re.lastIndex = 0; return (html.match(re) || []).length; }

(async () => {
  let urls = [];
  try {
    const sm = await fetchText(site + '/sitemap.xml');
    urls = [...sm.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map(m => m[1]);
  } catch (e) {
    console.error('Could not read sitemap.xml (' + e.message + '); auditing the homepage only.');
  }
  if (!urls.length) urls = [site + '/'];

  const rows = [];
  for (const url of urls) {
    let html;
    try { html = await fetchText(url); } catch (e) { rows.push({ url, error: e.message }); continue; }
    const counts = {}; for (const c of CHECKS) counts[c.key] = count(c.re, html);
    const baked = CHECKS.filter(c => c.kind === 'baked' && counts[c.key] > 0).map(c => c.label);
    const installs = CHECKS.filter(c => c.kind === 'install').reduce((n, c) => n + counts[c.key], 0);
    rows.push({ url, counts, baked, installs, doubleInstall: installs > 1 });
  }

  const dirty = rows.filter(r => (r.baked && r.baked.length) || r.doubleInstall);
  if (asJson) { console.log(JSON.stringify({ site, pages: rows.length, dirty: dirty.length, rows }, null, 2)); }
  else {
    console.log(`\nFourge widget audit — ${site}\n${rows.length} page(s) from sitemap, ${dirty.length} with problems\n`);
    const w = Math.min(70, Math.max(24, ...rows.map(r => r.url.replace(site, '').length + 2)));
    console.log('page'.padEnd(w) + 'status');
    for (const r of rows) {
      const path = (r.url.replace(site, '') || '/').padEnd(w);
      if (r.error) { console.log(path + 'FETCH FAILED — ' + r.error); continue; }
      if (!r.baked.length && !r.doubleInstall) { console.log(path + 'clean' + (r.installs ? ` (${r.installs} install snippet)` : '')); continue; }
      const parts = [];
      if (r.baked.length) parts.push('BAKED-IN: ' + r.baked.join(', '));
      if (r.doubleInstall) parts.push(`DOUBLE INSTALL: ${r.installs} loader snippets`);
      console.log(path + parts.join(' | '));
    }
    if (dirty.length) {
      console.log('\nFix: update the site to engine 1.14.65+ then open each flagged page in the CMS editor and click Save');
      console.log('(the new engine strips the baked-in markup on save). Keep exactly one install snippet, in Analytics → Custom Code.');
    }
  }
  process.exit(dirty.length ? 1 : 0);
})().catch(e => { console.error(e.message || e); process.exit(2); });
