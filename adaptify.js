/* ADAptify — Fourge CMS accessibility widget.
 * Self-contained, no dependencies. Reads its site-wide config from
 * /data/adaptify.json ({enabled, position, color, iconColor}); the CMS writes
 * that file, so toggling/recoloring in the ADAptify tab affects every page.
 * Visitor choices (contrast, text size, etc.) persist per-browser in
 * localStorage. Drop <script src="/adaptify.js" defer></script> on each page. */
(function () {
  'use strict';
  if (window.__adaptify) return; window.__adaptify = true;

  var CFG_URL  = '/data/adaptify.json';
  var PREFS    = 'adaptify_prefs';
  var DEFAULTS = { enabled: true, position: 'bottom-right', color: '#C8531E', iconColor: '#ffffff' };

  var ACTIONS = [
    { k: 'textPlus',  label: 'Bigger text' },
    { k: 'textMinus', label: 'Smaller text' },
    { k: 'contrast',  label: 'High contrast' },
    { k: 'invert',    label: 'Invert colors' },
    { k: 'grayscale', label: 'Grayscale' },
    { k: 'links',     label: 'Highlight links' },
    { k: 'readable',  label: 'Readable font' },
    { k: 'bigCursor', label: 'Big cursor' },
    { k: 'pause',     label: 'Pause motion' }
  ];

  function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
  function loadPrefs() { try { return JSON.parse(localStorage.getItem(PREFS) || '{}'); } catch (e) { return {}; } }
  function savePrefs(p) { try { localStorage.setItem(PREFS, JSON.stringify(p)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }

  ready(function () {
    fetch(CFG_URL, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .catch(function () { return {}; })
      .then(function (j) {
        var cfg = {}, k;
        for (k in DEFAULTS) cfg[k] = DEFAULTS[k];
        if (j) for (k in j) cfg[k] = j[k];
        var existing = document.getElementById('adaptify-root');
        if (cfg.enabled === false) { if (existing) existing.remove(); clearEffects(); return; }
        build(cfg);
      });
  });

  // The accessibility figure (person, arms out, in a circle).
  function manIcon(color) {
    return '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">'
      + '<circle cx="12" cy="12" r="11" fill="none" stroke="' + esc(color) + '" stroke-width="1.5" opacity="0.9"/>'
      + '<circle cx="12" cy="6.2" r="1.7" fill="' + esc(color) + '"/>'
      + '<path d="M6 9.2h12" stroke="' + esc(color) + '" stroke-width="1.7" stroke-linecap="round"/>'
      + '<path d="M12 8.2v6m0 0l-3 5.4m3-5.4l3 5.4" stroke="' + esc(color) + '" stroke-width="1.7" stroke-linecap="round" fill="none"/>'
      + '</svg>';
  }

  function posStyle(position) {
    var p = { 'bottom-right': 'bottom:20px;right:20px', 'bottom-left': 'bottom:20px;left:20px',
              'top-right': 'top:20px;right:20px', 'top-left': 'top:20px;left:20px',
              'mid-left': 'top:50%;left:20px;transform:translateY(-50%)',
              'mid-right': 'top:50%;right:20px;transform:translateY(-50%)' };
    return p[position] || p['bottom-right'];
  }
  function panelAnchor(position) {
    if (position.indexOf('left') !== -1) return 'left:0';
    return 'right:0';
  }
  function panelVert(position) {
    if (position.indexOf('top') === 0) return 'top:64px';
    if (position.indexOf('mid') === 0) return 'top:50%;transform:translateY(-50%)';
    return 'bottom:64px';
  }

  function build(cfg) {
    var old = document.getElementById('adaptify-root'); if (old) old.remove();
    var prefs = loadPrefs();
    var root = document.createElement('div');
    root.id = 'adaptify-root';
    root.setAttribute('data-pos', cfg.position);

    var btns = ACTIONS.map(function (a) {
      return '<button type="button" class="adaptify-tg" data-act="' + a.k + '" aria-pressed="false">'
        + '<span class="adaptify-tg-dot"></span>' + a.label + '</button>';
    }).join('');

    root.innerHTML =
      style(cfg)
      + '<button type="button" class="adaptify-launch" aria-label="Accessibility options" aria-expanded="false" '
      + 'style="' + posStyle(cfg.position) + ';background:' + esc(cfg.color) + '">' + manIcon(cfg.iconColor) + '</button>'
      + '<div class="adaptify-panel" role="dialog" aria-label="Accessibility menu" style="' + panelAnchor(cfg.position) + ';' + panelVert(cfg.position) + '">'
      + '  <div class="adaptify-head"><span>Accessibility</span>'
      + '    <button type="button" class="adaptify-x" aria-label="Close">&times;</button></div>'
      + '  <div class="adaptify-grid">' + btns + '</div>'
      + '  <button type="button" class="adaptify-reset" data-act="reset">Reset all</button>'
      + '  <div class="adaptify-credit">Accessibility by ADAptify</div>'
      + '</div>';
    document.body.appendChild(root);

    var btn = root.querySelector('.adaptify-launch');
    var pnl = root.querySelector('.adaptify-panel');
    function setOpen(o) { pnl.classList.toggle('open', o); btn.setAttribute('aria-expanded', o ? 'true' : 'false'); }
    btn.addEventListener('click', function () { setOpen(!pnl.classList.contains('open')); });
    root.querySelector('.adaptify-x').addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    root.querySelectorAll('[data-act]').forEach(function (el) {
      el.addEventListener('click', function () {
        var a = el.getAttribute('data-act');
        if (a === 'reset') { prefs = {}; }
        else if (a === 'textPlus') { prefs.text = Math.min(4, (prefs.text || 0) + 1); }
        else if (a === 'textMinus') { prefs.text = Math.max(0, (prefs.text || 0) - 1); }
        else { prefs[a] = !prefs[a]; }
        savePrefs(prefs); applyPrefs(prefs); reflect(root, prefs);
      });
    });
    applyPrefs(prefs); reflect(root, prefs);
  }

  function reflect(root, p) {
    root.querySelectorAll('.adaptify-tg').forEach(function (el) {
      var a = el.getAttribute('data-act');
      var on = (a === 'textPlus' || a === 'textMinus') ? !!p.text : !!p[a];
      el.classList.toggle('on', on);
      el.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function clearEffects() {
    var h = document.documentElement, c = h.className.split(/\s+/).filter(function (x) { return x.indexOf('adaptify-') !== 0; });
    h.className = c.join(' ');
  }

  function applyPrefs(p) {
    var h = document.documentElement;
    ['contrast', 'grayscale', 'invert', 'links', 'readable', 'bigcursor', 'pause'].forEach(function (n) {
      h.classList.remove('adaptify-' + n);
    });
    for (var i = 0; i <= 4; i++) h.classList.remove('adaptify-text-' + i);
    if (p.contrast)  h.classList.add('adaptify-contrast');
    if (p.grayscale) h.classList.add('adaptify-grayscale');
    if (p.invert)    h.classList.add('adaptify-invert');
    if (p.links)     h.classList.add('adaptify-links');
    if (p.readable)  h.classList.add('adaptify-readable');
    if (p.bigCursor) h.classList.add('adaptify-bigcursor');
    if (p.pause)     h.classList.add('adaptify-pause');
    if (p.text)      h.classList.add('adaptify-text-' + p.text);
  }

  function style(cfg) {
    var bigCursor = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 24 24'%3E%3Cpath d='M4 2l16 8-7 2-2 7z' fill='black' stroke='white' stroke-width='1.5'/%3E%3C/svg%3E\") 4 4, auto";
    return '<style id="adaptify-style">'
      + '#adaptify-root{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}'
      + '.adaptify-launch{position:fixed;z-index:2147483600;width:48px;height:48px;border:0;border-radius:50%;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center;padding:0;transition:transform .15s}'
      + '.adaptify-launch:hover{transform:scale(1.08)}.adaptify-launch:focus-visible{outline:3px solid #fff;outline-offset:2px}'
      + '.adaptify-panel{position:fixed;z-index:2147483600;width:268px;max-width:calc(100vw - 24px);max-height:calc(100vh - 96px);overflow:auto;background:#fff;color:#1a1a1a;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.32);padding:14px;display:none}'
      + '.adaptify-panel.open{display:block}'
      + '.adaptify-head{display:flex;align-items:center;justify-content:space-between;font-weight:800;font-size:15px;margin-bottom:10px}'
      + '.adaptify-x{border:0;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:#666}'
      + '.adaptify-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}'
      + '.adaptify-tg{display:flex;align-items:center;gap:7px;text-align:left;border:1px solid #e3e0da;background:#faf9f7;border-radius:9px;padding:9px 10px;font-size:12.5px;font-weight:600;color:#333;cursor:pointer}'
      + '.adaptify-tg:hover{border-color:#bbb}'
      + '.adaptify-tg-dot{width:9px;height:9px;border-radius:50%;background:#ccc;flex-shrink:0}'
      + '.adaptify-tg.on{background:' + esc(cfg.color) + '12;border-color:' + esc(cfg.color) + '}'
      + '.adaptify-tg.on .adaptify-tg-dot{background:' + esc(cfg.color) + '}'
      + '.adaptify-reset{width:100%;margin-top:10px;border:1px solid #e3e0da;background:#fff;border-radius:9px;padding:9px;font-size:12.5px;font-weight:700;cursor:pointer;color:#444}'
      + '.adaptify-credit{text-align:center;font-size:10px;color:#999;margin-top:8px}'
      // ── visitor effect classes (applied to <html>) ──
      + 'html.adaptify-grayscale{filter:grayscale(1)}'
      + 'html.adaptify-invert{filter:invert(1) hue-rotate(180deg)}'
      + 'html.adaptify-grayscale.adaptify-invert{filter:grayscale(1) invert(1) hue-rotate(180deg)}'
      + 'html.adaptify-contrast body{background:#000 !important;color:#fff !important}'
      + 'html.adaptify-contrast a{color:#ffff00 !important}'
      + 'html.adaptify-contrast :is(h1,h2,h3,h4,h5,h6,p,span,li,td){color:#fff !important}'
      + 'html.adaptify-links a{text-decoration:underline !important;outline:2px solid #ffbf00 !important;outline-offset:1px}'
      + 'html.adaptify-readable :not(.adaptify-launch):not(.adaptify-launch *){font-family:Verdana,Tahoma,Arial,sans-serif !important;letter-spacing:.02em;word-spacing:.08em;line-height:1.7 !important}'
      + 'html.adaptify-bigcursor,html.adaptify-bigcursor *{cursor:' + bigCursor + '}'
      + 'html.adaptify-pause *,html.adaptify-pause *::before,html.adaptify-pause *::after{animation-duration:.001s !important;animation-iteration-count:1 !important;transition-duration:.001s !important;scroll-behavior:auto !important}'
      + 'html.adaptify-text-1 body{font-size:108%}html.adaptify-text-2 body{font-size:118%}html.adaptify-text-3 body{font-size:130%}html.adaptify-text-4 body{font-size:145%}'
      // keep the widget itself unaffected by inversion/grayscale
      + 'html.adaptify-invert #adaptify-root,html.adaptify-grayscale #adaptify-root{filter:none}'
      + '</style>';
  }
})();
