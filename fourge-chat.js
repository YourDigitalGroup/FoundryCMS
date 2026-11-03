/* Fourge Chat — the website chat widget.
 * Self-contained, no dependencies. Reads the site's chat settings from
 * /data/chat.json (written by the CMS: Analytics → Setup → Chatbot) and talks
 * only to this site's /admin/api.php (chat_start, chat_send, chat_poll), which
 * hands each message to the CRM as a Live Chat message and returns the CRM
 * bot's and team's replies. The visitor's chat id is kept in localStorage so a
 * conversation carries on across pages and visits. Everything renders inside a
 * shadow root, so the site's CSS can't break the widget and the widget's CSS
 * can't touch the site. Loaded by the managed chatbot block in each page's
 * <head> (src="/fourge-chat.js" defer); the CMS also loads it with
 * data-fourge-preview to draw its live preview, which never touches the network. */
(function () {
  'use strict';
  var ME = document.currentScript;
  var PREVIEW_ONLY = !!(ME && ME.hasAttribute('data-fourge-preview'));
  if (!PREVIEW_ONLY) { if (window.__fourgeChat) return; window.__fourgeChat = true; }

  // Paths are resolved from this script's own address, so a site installed in
  // a sub-folder works the same as one at the root.
  var ROOT = (ME && ME.src) ? ME.src.replace(/[?#].*$/, '').replace(/[^\/]*$/, '') : '/';
  var CFG_URL = ROOT + 'data/chat.json';
  var API_URL = ROOT + 'admin/api.php';
  var STORE = 'fourge-chat';

  var DEFAULTS = {
    enabled: false, position: 'bottom-right', color: '#155EEF', theme: 'light', shape: 'rounded', launcher: 'icon', font: 'site',
    title: 'Chat with us', subtitle: 'We usually reply in a few seconds.', welcome: 'Hi there! How can we help today?',
    launcherText: 'Chat with us', avatar: '', askPhone: false, privacyUrl: '', startButton: 'Start chat',
    noReplyText: 'Thanks for your message. Our team will follow up by email shortly.'
  };
  var FAST_MS = 2500, SLOW_MS = 12000, WAIT_MS = 60000;

  function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
  function load() { try { return JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { return {}; } }
  function save(s) { try { localStorage.setItem(STORE, JSON.stringify(s)); } catch (e) {} }
  function merge(a, b) { var o = {}, k; for (k in a) o[k] = a[k]; if (b) for (k in b) if (b[k] !== undefined && b[k] !== null) o[k] = b[k]; return o; }
  function str(v, d) { v = (v == null ? '' : String(v)).trim(); return v || d || ''; }

  // ── COLOR ────────────────────────────────────────────────────────────────
  // The accent can arrive as hex (3/4/6/8 digits) or rgb()/rgba(). Text on the
  // accent is whichever of white or near-black reads better on it.
  function rgb(c) {
    c = String(c == null ? '' : c).trim();
    var m = c.match(/^#?([0-9a-f]{3,8})$/i);
    if (m) {
      var h = m[1];
      if (h.length === 3 || h.length === 4) h = h.split('').map(function (x) { return x + x; }).join('');
      h = h.slice(0, 6);
      if (h.length !== 6) return null;
      return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    }
    var p = c.match(/^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)/i);
    return p ? [Math.round(+p[1]), Math.round(+p[2]), Math.round(+p[3])] : null;
  }
  function lum(c) {
    var v = c.map(function (x) { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function onColor(c) {
    var x = rgb(c); if (!x) return '#ffffff';
    var L = lum(x);
    return (1.05 / (L + 0.05)) >= ((L + 0.05) / (0.0228 + 0.05)) ? '#ffffff' : '#111418';
  }
  function safeColor(c) { return rgb(c) ? String(c).trim() : DEFAULTS.color; }

  // ── ICONS ────────────────────────────────────────────────────────────────
  var ICON_CHAT = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z" fill="currentColor"/><circle cx="8.5" cy="9.5" r="1.2" fill="var(--fc-accent)"/><circle cx="12" cy="9.5" r="1.2" fill="var(--fc-accent)"/><circle cx="15.5" cy="9.5" r="1.2" fill="var(--fc-accent)"/></svg>';
  var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  var ICON_SEND = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4.5 12h11M11 6.5l5.5 5.5-5.5 5.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';

  // ── STYLES ───────────────────────────────────────────────────────────────
  // Sizes are in em of the host, which inherits the page's body font-size —
  // so the accessibility widget's text-size steps grow the chat too.
  function css(cfg) {
    var accent = safeColor(cfg.color), on = onColor(accent);
    var r = cfg.shape === 'square' ? ['4px', '4px', '6px'] : cfg.shape === 'soft' ? ['10px', '10px', '12px'] : ['18px', '16px', '999px'];
    return ''
      + ':host{all:initial;font-size:inherit}'
      + '.fc{--fc-accent:' + accent + ';--fc-on:' + on + ';--fc-r:' + r[0] + ';--fc-br:' + r[1] + ';--fc-lr:' + r[2] + ';'
      + '--fc-bg:#ffffff;--fc-text:#1b1f24;--fc-muted:#5b6470;--fc-line:#e3e6eb;--fc-agent:#f1f3f6;--fc-field:#ffffff;--fc-err:#b42318;--fc-shadow:0 18px 50px rgba(16,24,40,.22);'
      + 'font-family:var(--fc-font,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif);font-size:max(14px,1em);line-height:1.45;color:var(--fc-text);-webkit-font-smoothing:antialiased}'
      + '.fc[data-theme="dark"]{--fc-bg:#16181d;--fc-text:#eef0f3;--fc-muted:#a5adb9;--fc-line:#2b3038;--fc-agent:#23272e;--fc-field:#1c1f25;--fc-err:#ff8a7a;--fc-shadow:0 18px 50px rgba(0,0,0,.55)}'
      + '@media (prefers-color-scheme:dark){.fc[data-theme="auto"]{--fc-bg:#16181d;--fc-text:#eef0f3;--fc-muted:#a5adb9;--fc-line:#2b3038;--fc-agent:#23272e;--fc-field:#1c1f25;--fc-err:#ff8a7a;--fc-shadow:0 18px 50px rgba(0,0,0,.55)}}'
      + '.fc *{box-sizing:border-box;font:inherit;color:inherit;margin:0}'
      + '.fc-sr{position:absolute!important;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}'
      + '.fc-launch{position:fixed;z-index:2147483500;display:flex;align-items:center;gap:.5em;min-width:3.5em;height:3.5em;padding:0 .95em;border:0;border-radius:var(--fc-lr);background:var(--fc-accent);color:var(--fc-on);cursor:pointer;box-shadow:0 6px 22px rgba(16,24,40,.28);transition:transform .15s ease,box-shadow .15s ease;justify-content:center}'
      + '.fc[data-launcher="icon"] .fc-launch{width:3.5em;padding:0}'
      + '.fc[data-launcher="icon"] .fc-launch-text{display:none}'
      + '.fc-launch-text{font-weight:600;font-size:.95em;white-space:nowrap}'
      + '.fc-launch:hover{transform:translateY(-1px);box-shadow:0 10px 26px rgba(16,24,40,.32)}'
      + '.fc-launch svg{flex:none}'
      + '.fc-badge{position:absolute;top:-.3em;right:-.3em;min-width:1.4em;height:1.4em;padding:0 .35em;border-radius:999px;background:#d92d20;color:#fff;font-size:.75em;font-weight:700;line-height:1.4em;text-align:center;box-shadow:0 0 0 2px var(--fc-bg)}'
      + '.fc-panel{position:fixed;z-index:2147483500;display:flex;flex-direction:column;width:min(24em,calc(100vw - 24px));height:min(36em,calc(100vh - 110px));background:var(--fc-bg);color:var(--fc-text);border-radius:var(--fc-r);box-shadow:var(--fc-shadow);overflow:hidden;border:1px solid var(--fc-line)}'
      + '.fc-panel[hidden],.fc [hidden]{display:none!important}'
      + '.fc-head{display:flex;align-items:center;gap:.75em;padding:1em 1em 1em 1.1em;background:var(--fc-accent);color:var(--fc-on)}'
      + '.fc-avatar{width:2.5em;height:2.5em;border-radius:50%;object-fit:cover;flex:none;background:rgba(255,255,255,.2)}'
      + '.fc-head-text{flex:1;min-width:0}'
      + '.fc-title{font-size:1.05em;font-weight:700;line-height:1.25}'
      + '.fc-sub{font-size:.82em;opacity:.88;margin-top:.15em}'
      + '.fc-x{flex:none;display:flex;align-items:center;justify-content:center;width:2.25em;height:2.25em;border:0;border-radius:50%;background:transparent;color:inherit;cursor:pointer}'
      + '.fc-x:hover{background:rgba(255,255,255,.16)}'
      + '.fc-body{flex:1;overflow-y:auto;padding:1em;display:flex;flex-direction:column;gap:.75em;overscroll-behavior:contain}'
      + '.fc-log{list-style:none;padding:0;display:flex;flex-direction:column;gap:.5em}'
      + '.fc-msg{max-width:85%;padding:.6em .85em;border-radius:var(--fc-br);white-space:pre-wrap;overflow-wrap:anywhere;font-size:.95em}'
      + '.fc-msg a{color:inherit;text-decoration:underline}'
      + '.fc-agent{align-self:flex-start;background:var(--fc-agent);border-bottom-left-radius:.3em}'
      + '.fc-visitor{align-self:flex-end;background:var(--fc-accent);color:var(--fc-on);border-bottom-right-radius:.3em}'
      + '.fc-visitor.fc-pending{opacity:.72}'
      + '.fc-visitor.fc-failed{opacity:1;outline:2px solid var(--fc-err);outline-offset:1px}'
      + '.fc-note{align-self:center;max-width:92%;text-align:center;font-size:.82em;color:var(--fc-muted);padding:.2em .4em}'
      + '.fc-note button{border:0;background:none;color:var(--fc-accent);font-weight:600;cursor:pointer;text-decoration:underline;padding:0}'
      + '.fc-typing{align-self:flex-start;display:flex;gap:.3em;padding:.75em .9em;background:var(--fc-agent);border-radius:var(--fc-br);border-bottom-left-radius:.3em}'
      + '.fc-typing i{width:.45em;height:.45em;border-radius:50%;background:var(--fc-muted);animation:fc-b 1.2s infinite ease-in-out}'
      + '.fc-typing i:nth-child(2){animation-delay:.15s}.fc-typing i:nth-child(3){animation-delay:.3s}'
      + '@keyframes fc-b{0%,80%,100%{transform:translateY(0);opacity:.45}40%{transform:translateY(-.3em);opacity:1}}'
      + '.fc-start{display:flex;flex-direction:column;gap:.65em;padding:.9em;border:1px solid var(--fc-line);border-radius:var(--fc-br);background:var(--fc-bg)}'
      + '.fc-start-h{font-weight:700;font-size:.95em}'
      + '.fc-f{display:flex;flex-direction:column;gap:.25em}'
      + '.fc-f label{font-size:.8em;font-weight:600;color:var(--fc-muted)}'
      + '.fc-f input,.fc-f textarea,.fc-compose textarea{width:100%;padding:.6em .7em;border:1px solid var(--fc-line);border-radius:calc(var(--fc-br) * .6);background:var(--fc-field);color:var(--fc-text);font-size:.95em;outline:none}'
      + '.fc-f textarea{resize:vertical;min-height:4.5em}'
      + '.fc-f input:focus,.fc-f textarea:focus,.fc-compose textarea:focus{border-color:var(--fc-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--fc-accent) 25%,transparent)}'
      + '.fc-f [aria-invalid="true"]{border-color:var(--fc-err)}'
      + '.fc-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}'
      + '.fc-go{border:0;border-radius:calc(var(--fc-br) * .6);padding:.7em 1em;background:var(--fc-accent);color:var(--fc-on);font-weight:700;cursor:pointer}'
      + '.fc-go[disabled]{opacity:.6;cursor:default}'
      + '.fc-err{font-size:.82em;color:var(--fc-err)}'
      + '.fc-legal{font-size:.75em;color:var(--fc-muted)}'
      + '.fc-legal a{color:inherit}'
      + '.fc-compose{display:flex;align-items:flex-end;gap:.5em;padding:.7em;border-top:1px solid var(--fc-line);background:var(--fc-bg)}'
      + '.fc-compose textarea{flex:1;resize:none;max-height:7.5em;line-height:1.4}'
      + '.fc-send{flex:none;display:flex;align-items:center;justify-content:center;width:2.6em;height:2.6em;border:0;border-radius:var(--fc-lr);background:var(--fc-accent);color:var(--fc-on);cursor:pointer}'
      + '.fc-send[disabled]{opacity:.5;cursor:default}'
      + '.fc button:focus-visible,.fc a:focus-visible{outline:3px solid var(--fc-accent);outline-offset:2px}'
      + '.fc-head .fc-x:focus-visible{outline-color:var(--fc-on)}'
      + '.fc-launch:focus-visible{outline:3px solid var(--fc-text);outline-offset:3px}'
      + '@media (max-width:480px){.fc-panel.fc-live{inset:0!important;width:100%!important;height:100%!important;max-height:none!important;border-radius:0;border:0}}'
      + '@media (prefers-reduced-motion:reduce){.fc *{transition:none!important;animation:none!important}}'
      + '.fc-preview .fc-launch,.fc-preview .fc-panel{position:absolute}';
  }

  // ── TEXT → DOM (links made clickable, nothing else interpreted) ───────────
  function textInto(el, text) {
    var re = /(https?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)\]])/g, last = 0, m;
    text = String(text || '');
    while ((m = re.exec(text))) {
      if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
      var a = document.createElement('a'); a.href = m[1]; a.textContent = m[1]; a.target = '_blank'; a.rel = 'noopener noreferrer';
      el.appendChild(a); last = m.index + m[1].length;
    }
    if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
  }

  // ── WIDGET ───────────────────────────────────────────────────────────────
  function build(cfg, mount, opts) {
    opts = opts || {};
    var preview = !!opts.preview;
    var right = String(cfg.position || '').indexOf('left') === -1;
    var host = document.createElement('div');
    if (!preview) host.id = 'fourge-chat';
    // Inline, so page CSS aimed at body's children can't reach the host box;
    // font-size stays inherited for the accessibility widget's text steps.
    host.style.cssText = preview ? 'position:absolute;inset:0;font-size:inherit;' : 'all:initial;font-size:inherit;';
    mount.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var st = document.createElement('style'); st.textContent = css(cfg); root.appendChild(st);

    var wrap = document.createElement('div');
    wrap.className = 'fc' + (preview ? ' fc-preview' : '');
    wrap.setAttribute('data-theme', ['dark', 'auto'].indexOf(cfg.theme) !== -1 ? cfg.theme : 'light');
    wrap.setAttribute('data-launcher', cfg.launcher === 'pill' ? 'pill' : 'icon');
    if (cfg.font !== 'system') {
      try { var ff = getComputedStyle(document.body).fontFamily; if (ff) wrap.style.setProperty('--fc-font', ff); } catch (e) {}
    }
    var side = right ? 'right' : 'left';
    var title = str(cfg.title, DEFAULTS.title), label = str(cfg.launcherText, DEFAULTS.launcherText);
    wrap.innerHTML = ''
      + '<button type="button" class="fc-launch" aria-expanded="false" aria-controls="fc-panel" style="' + side + ':20px;bottom:20px">'
      +   ICON_CHAT + '<span class="fc-launch-text"></span><span class="fc-badge" hidden></span></button>'
      + '<section class="fc-panel' + (preview ? '' : ' fc-live') + '" id="fc-panel" role="dialog" aria-modal="false" aria-labelledby="fc-title" hidden style="' + side + ':20px;bottom:88px">'
      +   '<header class="fc-head"><img class="fc-avatar" alt="" hidden><div class="fc-head-text"><h2 class="fc-title" id="fc-title"></h2><p class="fc-sub"></p></div>'
      +   '<button type="button" class="fc-x" aria-label="Close chat">' + ICON_CLOSE + '</button></header>'
      +   '<div class="fc-body"><ol class="fc-log" role="log" aria-live="polite" aria-relevant="additions"></ol>'
      +   '<form class="fc-start" novalidate hidden>'
      +     '<p class="fc-start-h">Tell us who you are and we\'ll get right to it.</p>'
      +     '<div class="fc-f"><label for="fc-name">Name</label><input id="fc-name" name="name" autocomplete="name" maxlength="80" required></div>'
      +     '<div class="fc-f"><label for="fc-email">Email</label><input id="fc-email" name="email" type="email" autocomplete="email" maxlength="160" required></div>'
      +     '<div class="fc-f fc-phone" hidden><label for="fc-phone">Phone (optional)</label><input id="fc-phone" name="phone" type="tel" autocomplete="tel" maxlength="32"></div>'
      +     '<div class="fc-f"><label for="fc-first">How can we help?</label><textarea id="fc-first" name="message" rows="3" maxlength="1000" required></textarea></div>'
      +     '<div class="fc-hp" aria-hidden="true"><label for="fc-website">Leave this empty</label><input id="fc-website" name="website" tabindex="-1" autocomplete="off"></div>'
      +     '<p class="fc-err" role="alert" hidden></p>'
      +     '<button type="submit" class="fc-go"></button>'
      +     '<p class="fc-legal" hidden></p>'
      +   '</form></div>'
      +   '<form class="fc-compose" hidden><label class="fc-sr" for="fc-input">Message</label>'
      +     '<textarea id="fc-input" rows="1" maxlength="1000" placeholder="Type your message…"></textarea>'
      +     '<button type="submit" class="fc-send" aria-label="Send message">' + ICON_SEND + '</button></form>'
      + '</section>';
    root.appendChild(wrap);

    var q = function (s) { return wrap.querySelector(s); };
    var launch = q('.fc-launch'), panel = q('.fc-panel'), log = q('.fc-log'), badge = q('.fc-badge');
    var start = q('.fc-start'), compose = q('.fc-compose'), input = q('#fc-input'), sendBtn = q('.fc-send');
    var errEl = q('.fc-err'), goBtn = q('.fc-go'), body = q('.fc-body');
    launch.setAttribute('aria-label', label);
    q('.fc-launch-text').textContent = label;
    q('.fc-title').textContent = title;
    q('.fc-sub').textContent = str(cfg.subtitle);
    if (!str(cfg.subtitle)) q('.fc-sub').hidden = true;
    goBtn.textContent = str(cfg.startButton, DEFAULTS.startButton);
    if (cfg.askPhone) q('.fc-phone').hidden = false;
    var av = q('.fc-avatar');
    if (/^(https?:\/\/|\/)/i.test(str(cfg.avatar))) { av.src = str(cfg.avatar); av.hidden = false; }
    if (/^(https?:\/\/|\/)/i.test(str(cfg.privacyUrl))) {
      var legal = q('.fc-legal'); legal.hidden = false;
      legal.appendChild(document.createTextNode('By chatting, you agree to our '));
      var pa = document.createElement('a'); pa.href = str(cfg.privacyUrl); pa.target = '_blank'; pa.rel = 'noopener'; pa.textContent = 'privacy policy';
      legal.appendChild(pa); legal.appendChild(document.createTextNode('.'));
    }

    var state = load();                 // { sid, open }
    var rendered = {};                  // server message id → true
    var unread = 0, isOpen = false, timer = null, fastUntil = 0, waiting = false, waitNoted = false, busy = false, primed = false;
    // A reply still on its way when the visitor moved to another page.
    if (state.sid && +state.waitUntil > Date.now()) { waiting = true; fastUntil = +state.waitUntil; }

    function scrollDown() { body.scrollTop = body.scrollHeight; }
    function bubble(from, text, cls) {
      var li = document.createElement('li');
      li.className = 'fc-msg ' + (from === 'visitor' ? 'fc-visitor' : 'fc-agent') + (cls ? ' ' + cls : '');
      var who = document.createElement('span'); who.className = 'fc-sr'; who.textContent = from === 'visitor' ? 'You: ' : title + ': ';
      li.appendChild(who); textInto(li, text);
      log.appendChild(li); scrollDown();
      return li;
    }
    function note(text, actionLabel, action) {
      var li = document.createElement('li'); li.className = 'fc-note'; li.textContent = text;
      if (actionLabel) {
        li.appendChild(document.createTextNode(' '));
        var b = document.createElement('button'); b.type = 'button'; b.textContent = actionLabel; b.onclick = action; li.appendChild(b);
      }
      log.appendChild(li); scrollDown(); return li;
    }
    var typingEl = null;
    function typing(on) {
      if (on && !typingEl) {
        typingEl = document.createElement('li'); typingEl.className = 'fc-typing'; typingEl.setAttribute('aria-label', title + ' is typing');
        typingEl.innerHTML = '<i></i><i></i><i></i>'; log.appendChild(typingEl); scrollDown();
      } else if (!on && typingEl) { typingEl.remove(); typingEl = null; }
    }
    function setBadge() { badge.hidden = !unread; badge.textContent = unread > 9 ? '9+' : String(unread); launch.setAttribute('aria-label', label + (unread ? ' — ' + unread + ' new' : '')); }
    function welcome() { if (str(cfg.welcome)) bubble('agent', str(cfg.welcome), 'fc-welcome'); }

    // ── open / close ──
    function open(focus) {
      isOpen = true; panel.hidden = false; launch.setAttribute('aria-expanded', 'true');
      unread = 0; setBadge(); place();
      if (!preview && window.matchMedia && window.matchMedia('(max-width:480px)').matches) launch.hidden = true;
      if (!preview) { state.open = true; save(state); }
      showMode();
      if (focus !== false) setTimeout(function () { var f = state.sid ? input : q('#fc-name'); if (f) f.focus(); }, 30);
      if (state.sid) poll();
    }
    function close() {
      isOpen = false; panel.hidden = true; launch.hidden = false; launch.setAttribute('aria-expanded', 'false');
      if (!preview) { state.open = false; save(state); }
      launch.focus();
    }
    function showMode() { start.hidden = !!state.sid; compose.hidden = !state.sid; }
    launch.addEventListener('click', function () { isOpen ? close() : open(); });
    q('.fc-x').addEventListener('click', close);
    wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) { e.preventDefault(); close(); } });

    // ── network ──
    function api(action, data) {
      data = data || {}; data.action = action;
      return fetch(API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), credentials: 'omit' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j = j || {}; j.status = r.status; return j; }); },
              function () { return { ok: false, offline: true, error: 'You seem to be offline. Check your connection and try again.' }; });
    }
    function recaptcha() {
      return new Promise(function (resolve) {
        try {
          var s = document.querySelector('script[src*="recaptcha/api.js"]');
          var m = s && /[?&]render=([^&#]+)/.exec(s.src);
          var g = window.grecaptcha;
          if (!m || m[1] === 'explicit' || !g || !g.ready) return resolve('');
          var done = false, t = setTimeout(function () { if (!done) { done = true; resolve(''); } }, 4000);
          g.ready(function () {
            g.execute(decodeURIComponent(m[1]), { action: 'chat_start' }).then(
              function (tok) { if (!done) { done = true; clearTimeout(t); resolve(tok || ''); } },
              function () { if (!done) { done = true; clearTimeout(t); resolve(''); } });
          });
        } catch (e) { resolve(''); }
      });
    }

    function schedule(ms) {
      clearTimeout(timer); timer = null;
      if (!state.sid) return;
      var now = Date.now();
      if (waiting && now > fastUntil) {
        waiting = false; typing(false);
        if (!waitNoted && str(cfg.noReplyText)) { waitNoted = true; note(str(cfg.noReplyText)); }
      }
      var delay = ms || (now < fastUntil ? FAST_MS : (isOpen ? SLOW_MS : 0));
      if (!delay) return;                                   // closed and not waiting on a reply: rest
      if (document.visibilityState === 'hidden') delay = Math.max(delay, 30000);
      timer = setTimeout(poll, delay);
    }
    function poll() {
      if (!state.sid || busy) return;
      busy = true;
      api('chat_poll', { sid: state.sid }).then(function (j) {
        busy = false;
        if (j.expired) { ended(); return; }
        if (j.ok && j.messages) take(j.messages);
        schedule(j.wait ? j.wait * 1000 : 0);
      });
    }
    // Show what the server has: visitor messages already on screen (sent from
    // here) are matched by text, everything else is appended in order.
    function take(list) {
      var gotReply = false;
      list.forEach(function (m) {
        if (!m || !m.id || rendered[m.id]) return;
        rendered[m.id] = true;
        if (m.from === 'visitor') {
          var pend = local(m.text);
          if (pend) { pend.classList.remove('fc-pending'); pend.setAttribute('data-id', m.id); return; }
          var li = bubble('visitor', m.text); li.setAttribute('data-id', m.id);
          return;
        }
        if (typingEl) { typingEl.remove(); typingEl = null; }
        var a = bubble('agent', m.text); a.setAttribute('data-id', m.id);
        (m.files || []).forEach(function (u) { var fl = document.createElement('a'); fl.href = u; fl.target = '_blank'; fl.rel = 'noopener noreferrer'; fl.textContent = 'Attachment'; a.appendChild(document.createTextNode('\n')); a.appendChild(fl); });
        gotReply = true;
        if (!isOpen && primed) { unread++; setBadge(); }   // what was already there when the page loaded isn't "new"
      });
      primed = true;
      if (gotReply && waiting) { waiting = false; typing(false); fastUntil = Date.now() + 15000; state.waitUntil = 0; save(state); }
      if (waiting) typing(true);
    }
    // A message sent from this page that the server hasn't confirmed yet.
    function local(text) {
      var els = log.querySelectorAll('.fc-visitor:not([data-id])');
      for (var i = 0; i < els.length; i++) if (els[i].getAttribute('data-text') === text) return els[i];
      return null;
    }
    function ended() {
      clearTimeout(timer); typing(false); waiting = false;
      state.sid = ''; save(state); showMode();
      note('This chat has ended.', 'Start a new chat', function () { log.innerHTML = ''; rendered = {}; welcome(); showMode(); var f = q('#fc-name'); if (f) f.focus(); });
    }

    function send(text, li) {
      li = li || bubble('visitor', text, 'fc-pending');
      li.setAttribute('data-text', text);
      li.classList.remove('fc-failed'); li.classList.add('fc-pending');
      waiting = true; waitNoted = false; fastUntil = Date.now() + WAIT_MS; typing(false);
      return api('chat_send', { sid: state.sid, message: text }).then(function (j) {
        if (j.expired) { li.remove(); ended(); return false; }
        if (!j.ok) {
          waiting = false; typing(false);
          li.classList.remove('fc-pending'); li.classList.add('fc-failed');
          note(j.error || 'Your message didn\'t go through.', 'Try again', function (ev) { ev.target.parentNode.remove(); send(text, li); });
          return false;
        }
        li.classList.remove('fc-pending'); li.classList.add('fc-sent');
        state.waitUntil = fastUntil; save(state);
        typing(true); schedule(1200);
        return true;
      });
    }

    // ── start form ──
    function fieldErr(name, msg) {
      ['name', 'email', 'phone', 'message'].forEach(function (n) { var f = start.elements[n]; if (f) f.removeAttribute('aria-invalid'); });
      errEl.hidden = !msg; errEl.textContent = msg || '';
      var f = name && start.elements[name];
      if (f) { f.setAttribute('aria-invalid', 'true'); f.focus(); }
    }
    start.addEventListener('submit', function (e) {
      e.preventDefault();
      if (preview) return;
      var name = start.elements.name.value.trim(), email = start.elements.email.value.trim();
      var phone = start.elements.phone.value.trim(), first = start.elements.message.value.trim();
      if (!name) return fieldErr('name', 'Please enter your name.');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return fieldErr('email', 'Please enter a valid email address.');
      if (!first) return fieldErr('message', 'Tell us how we can help.');
      fieldErr('', '');
      goBtn.disabled = true; goBtn.textContent = 'Starting…';
      recaptcha().then(function (tok) {
        return api('chat_start', { name: name, email: email, phone: phone, website: start.elements.website.value, recaptcha: tok, page: location.href });
      }).then(function (j) {
        goBtn.disabled = false; goBtn.textContent = str(cfg.startButton, DEFAULTS.startButton);
        if (!j.ok || !j.sid) { fieldErr(j.field || '', j.error || 'The chat could not start. Please try again.'); return; }
        state.sid = j.sid; state.open = true; save(state);
        start.reset(); showMode();
        send(first);
        setTimeout(function () { input.focus(); }, 30);
      });
    });

    // ── composer ──
    function grow() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; }
    input.addEventListener('input', grow);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); compose.requestSubmit ? compose.requestSubmit() : compose.dispatchEvent(new Event('submit', { cancelable: true })); }
    });
    compose.addEventListener('submit', function (e) {
      e.preventDefault();
      if (preview) return;
      var text = input.value.trim();
      if (!text || !state.sid) return;
      input.value = ''; grow();
      sendBtn.disabled = true;
      send(text).then(function () { sendBtn.disabled = false; });
    });

    // ── placement: clear of the accessibility button and the reCAPTCHA badge ──
    // Both may share the chat's corner and both load late, so the launcher is
    // stacked above whichever is there, re-measured for a while after load,
    // on resize, and whenever the panel opens; the panel opens just above it.
    function place() {
      if (preview) return;
      var vh = window.innerHeight, vw = window.innerWidth, bottom = 20;
      function same(r) { return r && r.width && r.height && (((r.left + r.width / 2) > vw / 2) === right) && r.top > vh / 2; }
      var badgeEl = document.querySelector('.grecaptcha-badge');
      var br = badgeEl && badgeEl.getBoundingClientRect();
      if (same(br)) bottom = Math.max(bottom, Math.round(vh - br.top) + 10);
      var ada = document.querySelector('#adaptify-root .adaptify-launch, .adaptify-launch');
      var ar = ada && ada.getBoundingClientRect();
      if (same(ar)) bottom = Math.max(bottom, Math.round(vh - ar.top) + 12);
      launch.style.bottom = bottom + 'px';
      var lh = launch.getBoundingClientRect().height || 56;
      var pb = bottom + lh + 12;
      panel.style.bottom = pb + 'px';
      panel.style.maxHeight = Math.max(260, vh - pb - 12) + 'px';
    }

    // ── go ──
    welcome();
    showMode();
    if (preview) {
      bubble('visitor', 'Do you have any openings this Saturday?');
      bubble('agent', 'We do! Saturdays we\'re open 9 to 1. Would you like me to save you a spot?');
      start.hidden = true; compose.hidden = false; input.placeholder = 'Type your message…';
      isOpen = true; panel.hidden = false; launch.setAttribute('aria-expanded', 'true');
      panel.style.bottom = '88px'; panel.style.height = 'calc(100% - 108px)';
      return { host: host };
    }
    place();
    var n = 0, iv = setInterval(function () { place(); if (++n >= 30) clearInterval(iv); }, 700);
    window.addEventListener('resize', place);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && state.sid && (isOpen || waiting)) poll(); });
    if (state.sid) {
      if (state.open && !(window.matchMedia && window.matchMedia('(max-width:480px)').matches)) open(false);
      else poll();
    }
    return { host: host, open: open, close: close };
  }

  // The CMS preview: draw the widget into a box with these settings, offline.
  window.FourgeChat = {
    version: 1,
    preview: function (container, settings) {
      if (!container) return null;
      var old = container.querySelector('[data-fourge-chat-preview]'); if (old) old.remove();
      var box = document.createElement('div'); box.setAttribute('data-fourge-chat-preview', '');
      box.style.cssText = 'position:absolute;inset:0;';
      container.appendChild(box);
      return build(merge(DEFAULTS, settings || {}), box, { preview: true });
    }
  };
  if (PREVIEW_ONLY) return;

  ready(function () {
    fetch(CFG_URL, { cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .catch(function () { return {}; })
      .then(function (j) {
        var cfg = merge(DEFAULTS, j || {});
        if (!cfg.enabled || document.getElementById('fourge-chat')) return;
        build(cfg, document.body);
      });
  });
})();
