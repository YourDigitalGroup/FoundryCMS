/* ADAptify — Fourge CMS accessibility widget.
 * Self-contained, no dependencies. Reads its site-wide config from
 * /data/adaptify.json ({enabled, position, color, iconColor}); the CMS writes
 * that file, so toggling/recoloring in the ADAptify tab affects every page.
 * Visitor choices (contrast, text size, reading aids, etc.) persist per-browser
 * in localStorage. Drop the loader tag on each page (src="/adaptify.js" defer). */
(function () {
  'use strict';
  if (window.__adaptify) return; window.__adaptify = true;

  var CFG_URL  = '/data/adaptify.json';
  var PREFS    = 'adaptify_prefs';
  var DEFAULTS = { enabled: true, position: 'bottom-right', color: '#C8531E', iconColor: '#ffffff' };

  // Visitor-pref ranges for the steppers (each step = one click).
  var STEP = {
    fontSize:      { min: -4, max: 10 },   // % = 100 + n*10
    scaling:       { min:  0, max:  8 },   // zoom = (100 + n*10)/100
    lineHeight:    { min:  0, max:  6 },   // line-height = 1.4 + n*0.25
    letterSpacing: { min:  0, max:  8 }    // px = n*0.6
  };
  // Curated swatch palette (matches the ADAptify color rows).
  var PALETTE = ['#ffffff', '#000000', '#2b6cb0', '#7c4dad', '#c0392b', '#d68a1e', '#2f8f9d', '#5a8f3c'];

  var jsFeatures = {}; // active teardown fns, keyed by feature

  function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
  function loadPrefs() { try { return JSON.parse(localStorage.getItem(PREFS) || '{}'); } catch (e) { return {}; } }
  function savePrefs(p) { try { localStorage.setItem(PREFS, JSON.stringify(p)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  ready(function () {
    fetch(CFG_URL, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .catch(function () { return {}; })
      .then(function (j) {
        var cfg = {}, k;
        for (k in DEFAULTS) cfg[k] = DEFAULTS[k];
        if (j) for (k in j) cfg[k] = j[k];
        var existing = document.getElementById('adaptify-root');
        if (cfg.enabled === false) { if (existing) existing.remove(); clearAll(); return; }
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
  function panelAnchor(position) { return position.indexOf('left') !== -1 ? 'left:0' : 'right:0'; }
  function panelVert(position) {
    if (position.indexOf('top') === 0) return 'top:64px';
    if (position.indexOf('mid') === 0) return 'top:50%;transform:translateY(-50%)';
    return 'bottom:64px';
  }

  // reCAPTCHA drops a floating badge into a bottom corner (default bottom-right).
  // When ADAptify shares that corner the two overlap (see the widget sitting on
  // top of the badge), so lift the launch button + panel to just above the badge.
  // The badge loads asynchronously and may not exist when we build, so we re-check
  // for a short window and stop once we've measured it (or the page has none).
  function avoidRecaptcha(btn, pnl, position) {
    if (!btn || position.indexOf('bottom') !== 0) return;   // only bottom corners can collide
    var wantRight = position.indexOf('right') !== -1;
    function apply() {
      var badge = document.querySelector('.grecaptcha-badge');
      if (!badge) { btn.style.bottom = '20px'; if (pnl) pnl.style.bottom = '64px'; return false; }
      var r = badge.getBoundingClientRect();
      if (!r.width || !r.height) return false;              // in the DOM but not laid out yet — keep waiting
      var badgeOnRight = (r.left + r.width / 2) > (window.innerWidth / 2);
      // Only lift when the badge is in the SAME corner as the widget; otherwise
      // they don't overlap and we leave the widget where the site placed it.
      var b = (badgeOnRight === wantRight) ? Math.max(20, Math.round(window.innerHeight - r.top) + 10) : 20;
      btn.style.bottom = b + 'px';
      if (pnl) pnl.style.bottom = (b + 44) + 'px';          // panel opens just above the button (matches the 20→64 default)
      return true;                                          // measured — final decision made
    }
    if (apply()) return;
    var n = 0, iv = setInterval(function () { if (apply() || ++n >= 24) clearInterval(iv); }, 600);
  }

  // ── PANEL MARKUP ────────────────────────────────────────────────────────
  function tg(act, label) {
    return '<button type="button" class="adaptify-tg" data-act="' + act + '" aria-pressed="false">'
      + '<span class="adaptify-tg-dot"></span><span>' + label + '</span></button>';
  }
  function stepper(key, label) {
    return '<div class="adaptify-step" data-step="' + key + '">'
      + '<div class="adaptify-step-top"><span>' + label + '</span><b class="adaptify-step-val" data-val="' + key + '">100%</b></div>'
      + '<div class="adaptify-step-row">'
      + '<button type="button" class="adaptify-step-btn" data-act="dec:' + key + '" aria-label="Decrease ' + label + '">&minus;</button>'
      + '<button type="button" class="adaptify-step-btn adaptify-step-reset" data-act="zero:' + key + '">Reset</button>'
      + '<button type="button" class="adaptify-step-btn" data-act="inc:' + key + '" aria-label="Increase ' + label + '">+</button>'
      + '</div></div>';
  }
  function colorRow(kind, label) {
    var sw = PALETTE.map(function (c) {
      return '<button type="button" class="adaptify-sw" data-act="col:' + kind + ':' + c + '" '
        + 'style="background:' + esc(c) + '" aria-label="' + label + ' ' + esc(c) + '"></button>';
    }).join('');
    return '<div class="adaptify-colsec"><div class="adaptify-sub">' + label + '</div>'
      + '<div class="adaptify-sw-row">' + sw
      + '<button type="button" class="adaptify-sw-clear" data-act="col:' + kind + ':">Reset</button></div></div>';
  }
  function section(title, inner) {
    return '<div class="adaptify-sec"><div class="adaptify-sec-h">' + title + '</div>' + inner + '</div>';
  }

  function build(cfg) {
    var old = document.getElementById('adaptify-root'); if (old) old.remove();
    var prefs = normalize(loadPrefs());
    var root = document.createElement('div');
    root.id = 'adaptify-root';
    root.setAttribute('data-pos', cfg.position);

    var content = section('Content',
      '<div class="adaptify-grid">'
        + tg('readable', 'Readable Font') + tg('hltitles', 'Highlight Titles')
        + tg('links', 'Highlight Links') + tg('magnifier', 'Text Magnifier')
      + '</div>'
      + stepper('scaling', 'Content Scaling') + stepper('fontSize', 'Font Size')
      + stepper('lineHeight', 'Line Height') + stepper('letterSpacing', 'Letter Spacing')
      + '<div class="adaptify-sub">Text Align</div>'
      + '<div class="adaptify-grid3">'
        + tg('align:left', 'Left') + tg('align:center', 'Center') + tg('align:right', 'Right')
      + '</div>');

    var color = section('Color',
      '<div class="adaptify-grid">'
        + tg('contrast:dark', 'Dark Contrast') + tg('contrast:light', 'Light Contrast')
        + tg('contrast:high', 'High Contrast') + tg('mono', 'Monochrome')
        + tg('invert', 'Invert Colors') + tg('sat:high', 'High Saturation')
        + tg('sat:low', 'Low Saturation')
      + '</div>'
      + colorRow('text', 'Text Color') + colorRow('title', 'Title Color') + colorRow('bg', 'Background'));

    var nav = section('Navigation',
      '<div class="adaptify-grid">'
        + tg('mute', 'Mute Sounds') + tg('hideimg', 'Hide Images')
        + tg('guide', 'Reading Guide') + tg('stopanim', 'Stop Animations')
        + tg('mask', 'Reading Mask') + tg('hlhover', 'Highlight on Hover')
        + tg('hlclick', 'Highlight on Click') + tg('bigcursor', 'Large Cursor')
      + '</div>');

    root.innerHTML =
      style(cfg)
      + '<button type="button" class="adaptify-launch" aria-label="Accessibility options" aria-expanded="false" '
      + 'style="' + posStyle(cfg.position) + ';background:' + esc(cfg.color) + '">' + manIcon(cfg.iconColor) + '</button>'
      + '<div class="adaptify-panel" role="dialog" aria-label="Accessibility menu" style="' + panelAnchor(cfg.position) + ';' + panelVert(cfg.position) + '">'
      + '  <div class="adaptify-head"><span>Accessibility</span>'
      + '    <button type="button" class="adaptify-x" aria-label="Close">&times;</button></div>'
      + '  <div class="adaptify-body">' + content + color + nav + '</div>'
      + '  <button type="button" class="adaptify-reset" data-act="reset">Reset all settings</button>'
      + '  <div class="adaptify-credit">Accessibility by ADAptify</div>'
      + '</div>';
    // Append to <html> (a sibling of <body>), NOT into <body>. Every visitor
    // effect is scoped to `body`, so a sibling widget can never be recolored,
    // filtered, zoomed, or resized by the page-level rules — true isolation
    // (a descendant cannot escape an ancestor's filter, so this placement is
    // the only reliable way to keep the panel readable under contrast/invert).
    document.documentElement.appendChild(root);

    var btn = root.querySelector('.adaptify-launch');
    var pnl = root.querySelector('.adaptify-panel');
    function setOpen(o) { pnl.classList.toggle('open', o); btn.setAttribute('aria-expanded', o ? 'true' : 'false'); }
    btn.addEventListener('click', function () { setOpen(!pnl.classList.contains('open')); });
    root.querySelector('.adaptify-x').addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    root.querySelectorAll('[data-act]').forEach(function (el) {
      el.addEventListener('click', function () { dispatch(el.getAttribute('data-act'), prefs, root); });
    });

    applyAll(prefs); reflect(root, prefs);
    avoidRecaptcha(btn, pnl, cfg.position);
  }

  // Fill in any missing keys so reads never hit undefined.
  function normalize(p) {
    p = p || {};
    ['fontSize', 'scaling', 'lineHeight', 'letterSpacing'].forEach(function (k) { p[k] = p[k] || 0; });
    return p;
  }

  // ── CLICK ROUTING ─────────────────────────────────────────────────────────
  function dispatch(act, prefs, root) {
    var parts = act.split(':');
    var head = parts[0];

    if (act === 'reset') { for (var k in prefs) delete prefs[k]; normalize(prefs); }
    else if (head === 'inc' || head === 'dec' || head === 'zero') {
      var key = parts[1], r = STEP[key];
      if (head === 'zero') prefs[key] = 0;
      else prefs[key] = clamp((prefs[key] || 0) + (head === 'inc' ? 1 : -1), r.min, r.max);
    }
    else if (head === 'align') prefs.align = (prefs.align === parts[1]) ? '' : parts[1];
    else if (head === 'contrast') prefs.contrast = (prefs.contrast === parts[1]) ? '' : parts[1];
    else if (head === 'sat') prefs.sat = (prefs.sat === parts[1]) ? '' : parts[1];
    else if (head === 'col') { var v = parts.slice(2).join(':'); prefs[parts[1] + 'Color'] = v || ''; }
    else prefs[head] = !prefs[head]; // plain toggle

    savePrefs(prefs); applyAll(prefs); reflect(root, prefs);
  }

  // ── REFLECT ACTIVE STATE INTO THE UI ───────────────────────────────────────
  function reflect(root, p) {
    root.querySelectorAll('.adaptify-tg').forEach(function (el) {
      var a = el.getAttribute('data-act'), on = false, parts = a.split(':');
      if (parts[0] === 'align') on = p.align === parts[1];
      else if (parts[0] === 'contrast') on = p.contrast === parts[1];
      else if (parts[0] === 'sat') on = p.sat === parts[1];
      else on = !!p[a];
      el.classList.toggle('on', on);
      el.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    root.querySelectorAll('.adaptify-step-val').forEach(function (el) {
      var key = el.getAttribute('data-val'), n = p[key] || 0;
      if (key === 'fontSize' || key === 'scaling') el.textContent = (100 + n * 10) + '%';
      else el.textContent = n ? ('+' + n) : '0';
    });
    root.querySelectorAll('.adaptify-sw').forEach(function (el) {
      var a = el.getAttribute('data-act').split(':'); // col:kind:value
      el.classList.toggle('on', p[a[1] + 'Color'] === a.slice(2).join(':'));
    });
  }

  // ── APPLY EFFECTS ───────────────────────────────────────────────────────────
  function applyAll(p) {
    var h = document.documentElement;
    // toggle classes
    var classMap = {
      'adaptify-readable': p.readable, 'adaptify-hltitles': p.hltitles, 'adaptify-links': p.links,
      'adaptify-hideimg': p.hideimg, 'adaptify-stopanim': p.stopanim, 'adaptify-hlhover': p.hlhover,
      'adaptify-bigcursor': p.bigcursor,
      'adaptify-c-dark': p.contrast === 'dark', 'adaptify-c-light': p.contrast === 'light',
      'adaptify-c-high': p.contrast === 'high'
    };
    for (var c in classMap) h.classList.toggle(c, !!classMap[c]);

    // dynamic style block (steppers, alignment, custom colors, composed filter)
    var st = document.getElementById('adaptify-dyn');
    if (!st) { st = document.createElement('style'); st.id = 'adaptify-dyn'; document.head.appendChild(st); }
    st.textContent = dynCss(p);

    // JS-driven features
    syncFeature('magnifier', p.magnifier, magnifierOn);
    syncFeature('guide', p.guide, guideOn);
    syncFeature('mask', p.mask, maskOn);
    syncFeature('mute', p.mute, muteOn);
    syncFeature('hlclick', p.hlclick, hlClickOn);
  }

  function dynCss(p) {
    var css = '';
    if (p.fontSize) css += 'body{font-size:' + (100 + p.fontSize * 10) + '%!important}';
    if (p.scaling)  css += 'body{zoom:' + ((100 + p.scaling * 10) / 100) + '}';
    var sel = 'body :is(p,li,a,h1,h2,h3,h4,h5,h6,td,th,blockquote,dd,dt,figcaption)';
    var parts = [];
    if (p.lineHeight)    parts.push('line-height:' + (1.4 + p.lineHeight * 0.25) + '!important');
    if (p.letterSpacing) parts.push('letter-spacing:' + (p.letterSpacing * 0.6) + 'px!important');
    if (p.align)         parts.push('text-align:' + p.align + '!important');
    if (parts.length) css += sel + '{' + parts.join(';') + '}';
    if (p.textColor)  css += 'body :is(p,li,a,td,th,blockquote,dd,dt,figcaption){color:' + p.textColor + '!important}';
    if (p.titleColor) css += 'body :is(h1,h2,h3,h4,h5,h6){color:' + p.titleColor + '!important}';
    if (p.bgColor)    css += 'body,body :is(section,header,footer,main,article,aside,nav){background-color:' + p.bgColor + '!important}';
    var f = [];
    if (p.mono)          f.push('grayscale(1)');
    if (p.invert)        f.push('invert(1) hue-rotate(180deg)');
    if (p.sat === 'high') f.push('saturate(1.9)');
    if (p.sat === 'low')  f.push('saturate(.45)');
    if (f.length) css += 'body{filter:' + f.join(' ') + '}';
    return css;
  }

  // Start/stop a JS feature based on its boolean state.
  function syncFeature(name, want, starter) {
    if (want && !jsFeatures[name]) jsFeatures[name] = starter();
    else if (!want && jsFeatures[name]) { jsFeatures[name](); delete jsFeatures[name]; }
  }

  // Text magnifier — floating box shows the hovered element's text, enlarged.
  function magnifierOn() {
    var box = document.createElement('div'); box.id = 'adaptify-mag'; document.documentElement.appendChild(box);
    function move(e) {
      var t = e.target;
      if (!t || (t.closest && t.closest('#adaptify-root'))) { box.style.display = 'none'; return; }
      var txt = (t.innerText || t.textContent || '').trim();
      if (!txt) { box.style.display = 'none'; return; }
      box.textContent = txt.slice(0, 240);
      box.style.display = 'block';
      var top = e.clientY + 24, left = e.clientX - 20;
      box.style.top = Math.min(top, window.innerHeight - box.offsetHeight - 10) + 'px';
      box.style.left = clamp(left, 10, window.innerWidth - box.offsetWidth - 10) + 'px';
    }
    document.addEventListener('mousemove', move, true);
    return function () { document.removeEventListener('mousemove', move, true); box.remove(); };
  }

  // Reading guide — a colored line that tracks the cursor's vertical position.
  function guideOn() {
    var line = document.createElement('div'); line.id = 'adaptify-guide'; document.documentElement.appendChild(line);
    function move(e) { line.style.top = e.clientY + 'px'; }
    document.addEventListener('mousemove', move, true);
    return function () { document.removeEventListener('mousemove', move, true); line.remove(); };
  }

  // Reading mask — dim everything except a horizontal band around the cursor.
  function maskOn() {
    var top = document.createElement('div'), bot = document.createElement('div');
    top.className = bot.className = 'adaptify-mask';
    top.style.top = '0'; bot.style.bottom = '0'; // anchor each band explicitly
    document.documentElement.appendChild(top); document.documentElement.appendChild(bot);
    function move(e) {
      var band = 80;
      top.style.height = Math.max(0, e.clientY - band / 2) + 'px';
      bot.style.height = Math.max(0, window.innerHeight - e.clientY - band / 2) + 'px';
    }
    move({ clientY: window.innerHeight / 2 });
    document.addEventListener('mousemove', move, true);
    return function () { document.removeEventListener('mousemove', move, true); top.remove(); bot.remove(); };
  }

  // Mute sounds — silence all media now and any that tries to play later.
  function muteOn() {
    function muteAll() { document.querySelectorAll('audio,video').forEach(function (m) { m.muted = true; }); }
    muteAll();
    var onPlay = function (e) { if (e.target && e.target.muted === false) e.target.muted = true; };
    document.addEventListener('play', onPlay, true);
    var iv = setInterval(muteAll, 1500);
    return function () { document.removeEventListener('play', onPlay, true); clearInterval(iv); };
  }

  // Highlight on click — outline the most recently clicked element.
  function hlClickOn() {
    var last = null;
    function onClick(e) {
      var t = e.target;
      if (!t || (t.closest && t.closest('#adaptify-root'))) return;
      if (last) last.classList.remove('adaptify-clicked');
      t.classList.add('adaptify-clicked'); last = t;
    }
    document.addEventListener('click', onClick, true);
    return function () { document.removeEventListener('click', onClick, true); if (last) last.classList.remove('adaptify-clicked'); };
  }

  // Tear down everything (used when the site disables the widget).
  function clearAll() {
    var h = document.documentElement;
    h.className = h.className.split(/\s+/).filter(function (x) { return x.indexOf('adaptify-') !== 0; }).join(' ');
    var st = document.getElementById('adaptify-dyn'); if (st) st.remove();
    for (var n in jsFeatures) { jsFeatures[n](); delete jsFeatures[n]; }
  }

  function style(cfg) {
    var bigCursor = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 24 24'%3E%3Cpath d='M4 2l16 8-7 2-2 7z' fill='black' stroke='white' stroke-width='1.5'/%3E%3C/svg%3E\") 4 4, auto";
    return '<style id="adaptify-style">'
      + '#adaptify-root{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}'
      + '.adaptify-launch{position:fixed;z-index:2147483600;width:48px;height:48px;border:0;border-radius:50%;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center;padding:0;transition:transform .15s}'
      + '.adaptify-launch:hover{transform:scale(1.08)}.adaptify-launch:focus-visible{outline:3px solid #fff;outline-offset:2px}'
      + '.adaptify-panel{position:fixed;z-index:2147483600;width:332px;max-width:calc(100vw - 24px);max-height:calc(100vh - 96px);overflow:auto;background:#fff!important;color:#1a1a1a!important;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.32);padding:14px;display:none}'
      + '.adaptify-panel.open{display:block}'
      + '.adaptify-head{display:flex;align-items:center;justify-content:space-between;font-weight:800;font-size:15px;margin-bottom:10px;color:#1a1a1a!important}'
      + '.adaptify-x{border:0;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:#666!important}'
      + '.adaptify-sec{margin-bottom:12px}'
      + '.adaptify-sec-h{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8a!important;margin:6px 0 8px;border-top:1px solid #eee;padding-top:10px}'
      + '.adaptify-sec:first-child .adaptify-sec-h{border-top:0;padding-top:0}'
      + '.adaptify-sub{font-size:11px;font-weight:700;color:#777!important;margin:10px 0 6px}'
      + '.adaptify-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}'
      + '.adaptify-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}'
      + '.adaptify-tg{display:flex;align-items:center;gap:7px;text-align:left;border:1px solid #e3e0da!important;background:#faf9f7!important;border-radius:9px;padding:9px 10px;font-size:12px;font-weight:600;color:#333!important;cursor:pointer;min-height:40px}'
      + '.adaptify-tg:hover{border-color:#bbb!important}'
      + '.adaptify-tg-dot{width:9px;height:9px;border-radius:50%;background:#ccc!important;flex-shrink:0}'
      + '.adaptify-tg.on{background:' + esc(cfg.color) + '14!important;border-color:' + esc(cfg.color) + '!important;color:#1a1a1a!important}'
      + '.adaptify-tg.on .adaptify-tg-dot{background:' + esc(cfg.color) + '!important}'
      + '.adaptify-step{border:1px solid #ececec!important;border-radius:9px;padding:8px 10px;margin-top:8px;background:#fcfcfb!important}'
      + '.adaptify-step-top{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;color:#333!important;margin-bottom:6px}'
      + '.adaptify-step-val{color:' + esc(cfg.color) + '!important}'
      + '.adaptify-step-row{display:grid;grid-template-columns:1fr 1.4fr 1fr;gap:6px}'
      + '.adaptify-step-btn{border:1px solid #e0ddd6!important;background:#fff!important;border-radius:7px;padding:7px 0;font-size:14px;font-weight:800;color:#444!important;cursor:pointer;line-height:1}'
      + '.adaptify-step-btn:hover{border-color:#bbb!important}'
      + '.adaptify-step-reset{font-size:11px;font-weight:700}'
      + '.adaptify-colsec{margin-top:8px}'
      + '.adaptify-sw-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}'
      + '.adaptify-sw{width:24px;height:24px;border-radius:6px;border:1px solid #ccc!important;cursor:pointer;padding:0}'
      + '.adaptify-sw.on{outline:2px solid ' + esc(cfg.color) + ';outline-offset:1px;border-color:' + esc(cfg.color) + '!important}'
      + '.adaptify-sw-clear{border:1px solid #e0ddd6!important;background:#fff!important;border-radius:7px;padding:4px 10px;font-size:11px;font-weight:700;color:#555!important;cursor:pointer}'
      + '.adaptify-reset{width:100%;margin-top:10px;border:1px solid #e3e0da!important;background:#fff!important;border-radius:9px;padding:10px;font-size:12.5px;font-weight:700;cursor:pointer;color:#444!important}'
      + '.adaptify-credit{text-align:center;font-size:10px;color:#999!important;margin-top:8px}'
      // keep the widget itself fully readable regardless of page-level effects
      + '#adaptify-root,#adaptify-root *{filter:none!important;letter-spacing:normal!important;zoom:1!important}'
      // ── visitor effect classes (applied to <html>) ──
      + 'html.adaptify-hltitles :is(h1,h2,h3,h4,h5,h6){background:#fff59d!important;color:#222!important;box-shadow:0 0 0 2px #fbc02d!important}'
      + 'html.adaptify-links a{text-decoration:underline !important;outline:2px solid #ffbf00 !important;outline-offset:1px}'
      + 'html.adaptify-readable body :is(p,li,a,h1,h2,h3,h4,h5,h6,td,th,span,blockquote,label){font-family:Verdana,Tahoma,Arial,sans-serif !important;letter-spacing:.02em;word-spacing:.08em;line-height:1.7 !important}'
      + 'html.adaptify-hideimg :is(img,picture,video,svg,canvas){visibility:hidden !important}'
      + 'html.adaptify-hideimg #adaptify-root :is(svg){visibility:visible !important}'
      + 'html.adaptify-stopanim *,html.adaptify-stopanim *::before,html.adaptify-stopanim *::after{animation-duration:.001s !important;animation-iteration-count:1 !important;transition-duration:.001s !important;scroll-behavior:auto !important}'
      + 'html.adaptify-bigcursor,html.adaptify-bigcursor *{cursor:' + bigCursor + '}'
      + 'html.adaptify-hlhover body *:hover{outline:3px solid #1d4ed8 !important;outline-offset:2px}'
      + '.adaptify-clicked{outline:3px solid #16a34a !important;outline-offset:2px}'
      // contrast themes
      + 'html.adaptify-c-dark body{background:#0f1115 !important}'
      + 'html.adaptify-c-dark body :is(section,header,footer,main,article,aside,nav){background-color:#0f1115 !important}'
      + 'html.adaptify-c-dark body :is(p,li,h1,h2,h3,h4,h5,h6,span,td,th,label,blockquote,figcaption){color:#e8e8e8 !important}'
      + 'html.adaptify-c-dark body a{color:#7fb2ff !important}'
      + 'html.adaptify-c-light body{background:#ffffff !important}'
      + 'html.adaptify-c-light body :is(section,header,footer,main,article,aside,nav){background-color:#ffffff !important}'
      + 'html.adaptify-c-light body :is(p,li,h1,h2,h3,h4,h5,h6,span,td,th,label,blockquote,figcaption){color:#111 !important}'
      + 'html.adaptify-c-light body a{color:#0b5cab !important}'
      + 'html.adaptify-c-high body{background:#000 !important}'
      + 'html.adaptify-c-high body :is(section,header,footer,main,article,aside,nav){background-color:#000 !important}'
      + 'html.adaptify-c-high body :is(p,li,h1,h2,h3,h4,h5,h6,span,td,th,label,blockquote,figcaption){color:#fff !important}'
      + 'html.adaptify-c-high body a{color:#ff0 !important}'
      // reading aids + magnifier
      + '#adaptify-guide{position:fixed;left:0;width:100%;height:0;border-top:3px solid ' + esc(cfg.color) + ';box-shadow:0 0 0 9999px rgba(0,0,0,.0);z-index:2147483500;pointer-events:none;opacity:.85}'
      + '.adaptify-mask{position:fixed;left:0;width:100%;background:rgba(0,0,0,.62);z-index:2147483500;pointer-events:none}'
      + '#adaptify-mag{position:fixed;z-index:2147483600;max-width:340px;background:#111;color:#fff;font-size:22px;line-height:1.4;font-weight:600;padding:10px 14px;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.4);pointer-events:none;display:none}'
      + '</style>';
  }
})();
