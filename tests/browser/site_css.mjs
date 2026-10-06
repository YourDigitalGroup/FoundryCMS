import { openAdmin } from '../lib/harness.mjs';
// 1.14.138 — Site CSS: one window with every stylesheet on the site (site-wide custom CSS, every
// linked .css file + @imports, each page's own <style> blocks), edited centrally with live preview
// in the open page and saved where each lives (file + backup, site.json + every page, the page file).
const {p,chk,done}=await openAdmin();
const SITE_CSS='/* site */\n.hero h1 {\n  font-size: 40px;\n}\n.btn { color: red; }\n';
const INDEX='<!doctype html><html><head><meta charset="utf-8"><title>Home</title>\n<link rel="stylesheet" href="/css/site.css">\n<style>/* home */ .hero h1 { color: rgb(10, 20, 30); }</style>\n<style id="fourge-typography">h1{font-weight:700}</style>\n</head><body><section class="hero"><h1 id="t">Hi</h1></section><!-- <style>.commented{}</style> --></body></html>';
const ABOUT='<html><head><title>About</title><link rel="stylesheet" href="css/site.css"><link rel="stylesheet" href="assets/extra.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"><style>.about { padding: 4px; }</style></head><body><script>var s="<style>.inscript{}</style>";</script>x</body></html>';
await p.route('http://127.0.0.1:8931/index.html*', r=>r.fulfill({status:200,contentType:'text/html',body:INDEX}));
await p.route('http://127.0.0.1:8931/css/site.css*', r=>r.fulfill({status:200,contentType:'text/css',body:SITE_CSS}));
await p.evaluate(({INDEX,ABOUT,SITE_CSS})=>{
  Object.assign(window.__files,{'index.html':INDEX,'about.html':ABOUT,'css/site.css':SITE_CSS,'assets/extra.css':'@import url("more.css");\n.x { margin: 0; }\n','assets/more.css':'.more { z-index: 1; }\n'});
  window.__pagesList=[{file:'index.html',path:'index.html',title:'Home',size:1,modified:'',is_cms:false,snippet:'',has_post_list:false},{file:'about.html',path:'about.html',title:'About',size:1,modified:'',is_cms:false,snippet:'',has_post_list:false}];
  window.__mirrors=[]; window.ghMirror=async(path)=>{ window.__mirrors.push(path); return {ok:true}; };
  _site={website:'http://127.0.0.1:8931',customCss:'.btn{border-radius:999px}'};
  goPanel('p-pages');
},{INDEX,ABOUT,SITE_CSS});
await p.waitForFunction(()=>{ const f=document.getElementById('visual-iframe'); const d=f&&f.contentDocument; return !!(_curPage&&d&&d.getElementById('t')&&d.getElementById('__fe_ov__')); },null,{timeout:15000});
await p.waitForTimeout(500);
chk(await p.evaluate(()=>{ const a=document.getElementById('ve-elcss-btn'), b=document.getElementById('ve-sitecss-btn'); return !!(a&&b&&a.nextElementSibling===b&&b.textContent.trim()==='Site CSS'); }),'the toolbar has a "Site CSS" button right after Element CSS');
await p.evaluate(()=>veSiteCssOpen());
await p.waitForFunction(()=>document.querySelectorAll('#sitecss-list .sitecss-item').length>=5,null,{timeout:15000});
const items=async()=>p.evaluate(()=>[...document.querySelectorAll('#sitecss-list .sitecss-item')].map(b=>({key:b.dataset.key,name:b.querySelector('.n').textContent.trim(),sub:b.querySelector('.s').textContent,dirty:b.classList.contains('dirty'),on:b.classList.contains('on')})));
let it=await items();
chk(it.map(i=>i.key).join(' | ')==='site | f:assets/extra.css | f:assets/more.css | f:css/site.css | b:index.html#1 | b:about.html#1','every stylesheet on the site is listed, grouped: site-wide, files (incl. the @import), each page’s own block — got: '+it.map(i=>i.key).join(' | '));
chk(it[0].sub.indexOf('Design → Custom CSS')===0 && /used by 2 pages/.test(it[3].sub) && /used by 1 page$/.test(it[1].sub) && /imported by assets\/extra\.css/.test(it[2].sub) && it[4].sub==='.hero h1' && it[5].sub==='.about','…with where each is used — got: '+JSON.stringify(it.map(i=>i.sub)));
chk(!it.some(i=>/typography|googleapis|commented|inscript/.test(i.key+i.sub)),'engine-managed styles, third-party links, commented-out and in-script <style> text are not sources');
chk(it[0].on && await p.evaluate(()=>_sitecss.cm.getValue()==='.btn{border-radius:999px}'),'the site-wide custom CSS opens first in the editor');
// ── a stylesheet file: edit → live in the open page → save (backup, file, mirror)
await p.evaluate(()=>siteCssShow('f:css/site.css'));
chk(await p.evaluate(()=>_sitecss.cm.getValue())===SITE_CSS,'picking a file shows its text');
chk(await p.evaluate(()=>/live in the editor/.test(document.getElementById('sitecss-head').textContent)&&/used by 2 pages/.test(document.getElementById('sitecss-head').textContent)),'the header says the open page uses it (live) and how many pages do');
await p.evaluate(()=>_sitecss.cm.setValue(_sitecss.cm.getValue().replace('40px','44px')));
await p.waitForTimeout(100);
let r=await p.evaluate(()=>{ const d=document.getElementById('visual-iframe').contentDocument; const l=d.querySelector('link[href="/css/site.css"]'); const pv=l&&l.nextElementSibling; return {dirty:document.querySelector('.sitecss-item[data-key="f:css/site.css"]').classList.contains('dirty'),disabled:!!(l&&l.disabled),pv:!!(pv&&pv.tagName==='STYLE'&&pv.getAttribute('data-foundry')==='sitecss'&&/44px/.test(pv.textContent)),fs:getComputedStyle(d.getElementById('t')).fontSize,saveOn:!document.getElementById('sitecss-save').disabled,inSerial:/data-sitecss/.test(veSerializeClean())}; });
chk(r.dirty&&r.disabled&&r.pv&&r.fs==='44px','editing the file shows live: the page’s <link> is paused and a preview style carries the new text (h1 is 44px now) — '+JSON.stringify(r));
chk(r.saveOn&&!r.inSerial,'Save lights up; the preview style never reaches the saved page (veSerializeClean)');
await p.evaluate(()=>{ window.__writes=[]; window.__toasts=[]; window.__mirrors=[]; });
await p.evaluate(()=>siteCssSave());
await p.waitForTimeout(100);
r=await p.evaluate(()=>({writes:window.__writes.map(w=>w.path),file:window.__files['css/site.css'],bak:window.__files['data/bak/css__site.css'],mirrors:window.__mirrors,toasts:window.__toasts.map(t=>t.m),dirty:document.querySelector('.sitecss-item[data-key="f:css/site.css"]').classList.contains('dirty')}));
chk(r.writes.join(',')==='data/bak/css__site.css,css/site.css'&&r.bak===SITE_CSS&&r.file===SITE_CSS.replace('40px','44px')&&r.mirrors.join(',')==='css/site.css'&&!r.dirty,'Save: previous copy to data/bak/, the file written, mirrored to GitHub, no longer dirty — '+JSON.stringify([r.writes,r.mirrors]));
chk(r.toasts.some(t=>/Saved css\/site\.css/.test(t)),'…and the toast names the file');
// drift: the server copy changed under us → refused
await p.evaluate(()=>{ window.__files['css/site.css']='/* replaced on the server */'; window.__writes=[]; window.__toasts=[]; _sitecss.cm.setValue(_sitecss.cm.getValue()+'\n.late { top: 0; }\n'); });
await p.evaluate(()=>siteCssSave()); await p.waitForTimeout(50);
r=await p.evaluate(()=>({writes:window.__writes.length,toasts:window.__toasts.map(t=>t.m),dirty:document.querySelector('.sitecss-item[data-key="f:css/site.css"]').classList.contains('dirty')}));
chk(r.writes===0&&r.dirty&&r.toasts.some(t=>/changed on the server since it was read here/.test(t)),'a file that changed on the server is not overwritten; the edit stays unsaved — '+JSON.stringify(r.toasts));
await p.evaluate(()=>{ window.__files['css/site.css']=_sitecss.sources.find(s=>s.key==='f:css/site.css').baseline; siteCssRevert(); });
chk(await p.evaluate(()=>!document.querySelector('.sitecss-item[data-key="f:css/site.css"]').classList.contains('dirty')&&/44px/.test(_sitecss.cm.getValue())&&!/late/.test(_sitecss.cm.getValue())),'Revert goes back to the saved version');
// ── the site-wide custom CSS: live block in the open page, then site.json + every page
await p.evaluate(()=>{ siteCssShow('site'); _sitecss.cm.setValue('.btn { border-radius: 4px; }'); });
await p.waitForTimeout(80);
r=await p.evaluate(()=>{ const d=document.getElementById('visual-iframe').contentDocument; const el=d.getElementById('fourge-site-css'); return {live:!!el&&el.textContent==='.btn { border-radius: 4px; }'}; });
chk(r.live,'editing the site-wide CSS shows live in the open page (its #fourge-site-css block)');
await p.evaluate(()=>{ window.__writes=[]; window.__mirrors=[]; window.__toasts=[]; });
await p.evaluate(()=>siteCssSave()); await p.waitForTimeout(100);
r=await p.evaluate(()=>({site:JSON.parse(window.__files['data/site.json']).customCss,cssVar:_site.customCss,ta:document.getElementById('si-customcss').value,idx:window.__files['index.html'],about:window.__files['about.html'],writes:window.__writes.map(w=>w.path),toasts:window.__toasts.map(t=>t.m)}));
chk(r.site==='.btn { border-radius: 4px; }'&&r.cssVar===r.site&&r.ta===r.site,'Save: site.json carries the CSS, _site and the Design tab box are in step');
chk(/<style id="fourge-site-css" data-fourge-site-css>\.btn \{ border-radius: 4px; \}<\/style>\n<\/head>/.test(r.idx)&&/<style id="fourge-site-css" data-fourge-site-css>\.btn \{ border-radius: 4px; \}<\/style>\n<\/head>/.test(r.about)&&r.writes.join(',')==='data/site.json,index.html,about.html','…and every page gets the block (the Design → Apply CSS to all pages path) — writes: '+r.writes.join(','));
chk(r.toasts.some(t=>/Site-wide CSS saved and applied to 2 pages/.test(t)),'…with a summary toast');
// ── a page’s own <style> block: live in the open page, saved inside that page only
await p.evaluate(()=>{ siteCssShow('b:index.html#1'); });
chk(await p.evaluate(()=>_sitecss.cm.getValue()==='/* home */ .hero h1 { color: rgb(10, 20, 30); }'),'a page block shows exactly its text');
await p.evaluate(()=>_sitecss.cm.setValue('/* home */ .hero h1 { color: rgb(0, 128, 0); }'));
await p.waitForTimeout(80);
chk(await p.evaluate(()=>getComputedStyle(document.getElementById('visual-iframe').contentDocument.getElementById('t')).color==='rgb(0, 128, 0)'),'…and edits show live in the open page');
await p.evaluate(()=>{ window.__writes=[]; });
await p.evaluate(()=>siteCssSave()); await p.waitForTimeout(80);
r=await p.evaluate(()=>({idx:window.__files['index.html'],writes:window.__writes.map(w=>w.path)}));
const expectIdx=INDEX.replace('rgb(10, 20, 30)','rgb(0, 128, 0)').replace('</head>','<style id="fourge-site-css" data-fourge-site-css>.btn { border-radius: 4px; }</style>\n</head>');
chk(r.idx===expectIdx&&r.writes.join(',')==='data/bak/index.html,index.html','Save rewrites only that block inside the page file (everything else byte for byte; backup first) — '+(r.idx===expectIdx?'exact':JSON.stringify(r.idx.slice(0,220))));
// ── filter by content, Save all, close guard
await p.evaluate(()=>{ document.getElementById('sitecss-filter').value='.about'; siteCssFilter(); });
it=await items(); chk(it.length===1&&it[0].key==='b:about.html#1','the filter finds a stylesheet by the CSS it contains — got: '+it.map(i=>i.key).join(','));
await p.evaluate(()=>{ document.getElementById('sitecss-filter').value='extra'; siteCssFilter(); });
it=await items(); chk(it.map(i=>i.key).join(',')==='f:assets/extra.css,f:assets/more.css','…or by name — the import shows too because it is used by extra.css (a page name finds its stylesheets the same way) — got: '+it.map(i=>i.key).join(','));
await p.evaluate(()=>{ document.getElementById('sitecss-filter').value=''; siteCssFilter(); });
await p.evaluate(()=>{ siteCssShow('f:assets/extra.css'); _sitecss.cm.setValue('@import url("more.css");\n.x { margin: 1px; }\n'); siteCssShow('f:assets/more.css'); _sitecss.cm.setValue('.more { z-index: 2; }\n'); });
r=await p.evaluate(()=>({saveall:document.getElementById('sitecss-saveall').textContent,dis:document.getElementById('sitecss-saveall').disabled}));
chk(r.saveall==='Save all (2)'&&!r.dis,'two dirty sources → "Save all (2)"');
await p.evaluate(()=>{ window.__writes=[]; }); await p.evaluate(()=>siteCssSaveAll()); await p.waitForTimeout(80);
r=await p.evaluate(()=>({writes:window.__writes.map(w=>w.path),dirty:siteCssDirty().length,extra:window.__files['assets/extra.css'],more:window.__files['assets/more.css']}));
chk(r.dirty===0&&/margin: 1px/.test(r.extra)&&/z-index: 2/.test(r.more)&&r.writes.join(',')==='data/bak/assets__extra.css,assets/extra.css,data/bak/assets__more.css,assets/more.css','Save all saves each dirty source in turn');
await p.evaluate(()=>{ siteCssShow('f:css/site.css'); _sitecss.cm.setValue('.dirty { x: y; }'); });
await p.evaluate(()=>{ window.__closed=siteCssClose(); }); await p.waitForTimeout(80);
r=await p.evaluate(()=>({confirm:document.getElementById('m-uiconfirm').classList.contains('on'),msg:document.getElementById('uiconfirm-msg').textContent}));
chk(r.confirm&&/Unsaved changes in 1 source \(css\/site\.css\)/.test(r.msg),'closing with unsaved changes asks first — '+r.msg);
await p.evaluate(()=>uiConfirmResolve(false)); await p.waitForTimeout(50);
chk(await p.evaluate(()=>document.getElementById('m-sitecss').classList.contains('on')&&_sitecss.cm.getValue()==='.dirty { x: y; }'),'Cancel keeps the window and the edit');
await p.evaluate(()=>{ window.__closed=siteCssClose(); }); await p.waitForTimeout(80); await p.evaluate(()=>uiConfirmResolve(true)); await p.waitForTimeout(80);
r=await p.evaluate(()=>{ const d=document.getElementById('visual-iframe').contentDocument; const l=d.querySelector('link[href="/css/site.css"]'); return {open:document.getElementById('m-sitecss').classList.contains('on'),dirty:siteCssDirty().length,pv:(l.nextElementSibling&&l.nextElementSibling.getAttribute('data-sitecss'))||null,pvText:l.nextElementSibling?l.nextElementSibling.textContent:''}; });
chk(!r.open&&r.dirty===0&&/44px/.test(r.pvText)&&!/dirty/.test(r.pvText),'Discard closes, reverts the edit and the live preview goes back to the saved file');
await done('site css');
