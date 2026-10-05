import { openAdmin, ROOT } from '../lib/harness.mjs';
import fs from 'fs';
// 1.14.137 — Element CSS: every rule that applies to the selected element, editable in place and
// persisted where it lives (style attribute / <style> text / .css file via the API / editor overrides).
const {p,chk,done}=await openAdmin();
const STYLE_CSS=fs.readFileSync(ROOT+'/tests/fixtures/elcss/style.css','utf8');
const PAGE=`<!doctype html><html><head><meta charset="utf-8"><title>T</title>
<link rel="stylesheet" href="/tests/fixtures/elcss/style.css">
<style>
/* page styles */
.hero h1 { color: rgb(10, 20, 30); font-size: 40px; /* big */ }
.hero h1:hover { color: rgb(200, 0, 0); }
@media (max-width: 600px) {
  .hero h1 {
    font-size: 24px;
  }
}
h1::after { content: "!"; }
body { font-family: Georgia, serif; }
.hero { padding: 30px; }
</style>
<style id="fourge-responsive">.fge-r7{padding:11px !important;}
@media (max-width:1024px){.fge-r7{padding:5px !important;}}</style>
<style id="fourge-posts-css">.hero h1{letter-spacing:1px}</style>
<link rel="stylesheet" href="http://127.0.0.1:8933/ext.css">
</head><body>
<section class="hero"><h1 id="hero-title" class="fge-r7" style="margin-top: 7px; color: rgb(1, 2, 3)">Hello</h1><p>para</p></section>
</body></html>`;
// the real flow: the Pages panel lists the server's pages, auto-opens the first one and the editor fetches it from the site
await p.route('http://127.0.0.1:8931/page.html*', r=>r.fulfill({status:200,contentType:'text/html',body:PAGE}));
// the cross-origin stylesheet is answered by interception (headless Chromium stalls real requests to a second local origin); it is still another origin, so its rules are unreadable from the page
await p.route('http://127.0.0.1:8933/ext.css*', r=>r.fulfill({status:200,contentType:'text/css',body:'.hero h1{word-spacing:2px}'}));
await p.evaluate(({PAGE,STYLE_CSS})=>{ window.__files['tests/fixtures/elcss/style.css']=STYLE_CSS; window.__files['page.html']=PAGE; window.__pagesList=[{file:'page.html',path:'page.html',title:'T',size:PAGE.length,modified:'',is_cms:false,snippet:'',has_post_list:false}]; _site={website:'http://127.0.0.1:8931'}; goPanel('p-pages'); },{PAGE,STYLE_CSS});
await p.waitForFunction(()=>{ const f=document.getElementById('visual-iframe'); const d=f&&f.contentDocument; return !!(_curPage&&d&&d.getElementById('hero-title')&&d.getElementById('__fe_ov__')); },null,{timeout:15000});
await p.waitForTimeout(800);
chk(await p.evaluate(()=>{ const d=document.getElementById('visual-iframe').contentDocument; return !!(d&&d.getElementById('hero-title')); }),'the page is open in the visual editor (loaded through the normal Pages flow)');
// nothing selected yet
let r=await p.evaluate(async()=>{ _veCurSel=null; window.__toasts=[]; await veElementCssOpen(); return window.__toasts.map(t=>t.m); });
chk(r.some(t=>/Click an element on the page first/.test(t)),'with nothing selected the button says to click an element first');
// select the heading through the bridge's own click handler (a synthetic click inside the frame; Playwright's pointer click does not reach it headless)
await p.frames().find(f=>/page\.html/.test(f.url())).evaluate(()=>{ document.getElementById('hero-title').dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,clientX:5,clientY:5})); });
await p.waitForFunction(()=>_veCurSel&&_veCurSel.tag==='h1',null,{timeout:4000});
await p.evaluate(()=>veElementCssOpen());
await p.waitForFunction(()=>document.querySelectorAll('#elcss-body .elcss-card').length>3,null,{timeout:8000});
const cards=async()=>p.evaluate(()=>[...document.querySelectorAll('#elcss-body .elcss-card')].map(c=>({key:c.dataset.key,sel:c.querySelector('.elcss-sel').textContent,badges:[...c.querySelectorAll('.elcss-badge')].map(b=>b.textContent),decls:c.querySelector('textarea').value,ro:c.classList.contains('ro'),readonly:c.querySelector('textarea').hasAttribute('readonly'),btn:(c.querySelector('.elcss-apply')||{}).textContent||''})));
let cs=await cards();
const find=(sel,badge)=>cs.find(c=>c.sel===sel&&(!badge||c.badges.some(b=>b===badge||b.indexOf(badge)!==-1))&&!c.badges.some(b=>/^@media/.test(b)));
chk(cs[0].sel==='style="…"'&&cs[0].decls==='margin-top: 7px;\ncolor: rgb(1, 2, 3);','the inline style is first, one declaration per line — '+JSON.stringify(cs[0].decls));
const base=find('.hero h1','<style> block 1');
chk(!!base&&base.decls==='color: rgb(10, 20, 30);\nfont-size: 40px; /* big */','the page <style> rule shows the author’s own text (comment kept) — '+JSON.stringify(base&&base.decls));
const hov=find('.hero h1:hover'); chk(!!hov&&hov.badges.includes(':hover'),'the :hover rule is listed with a state badge');
const med=cs.find(c=>c.sel==='.hero h1'&&c.badges.some(b=>/@media \(max-width: 600px\)/.test(b)));
chk(!!med&&med.decls==='font-size: 24px;','the @media variant is listed with its condition');
const aft=find('h1::after'); chk(!!aft&&aft.badges.includes('::after'),'the ::after rule is listed with a pseudo-element badge');
const lnk=find('.hero h1','style.css'); chk(!!lnk&&lnk.decls==='font-weight: 700;\nline-height: 1.1;'&&lnk.btn==='Save to file','the rule from the stylesheet FILE is listed with its text and a "Save to file" button — '+JSON.stringify(lnk&&[lnk.decls,lnk.btn]));
const lst=cs.find(c=>/\.hero h1,\s*\.hero h2/.test(c.sel)); chk(!!lst&&lst.decls==='text-transform: uppercase;','a selector list that includes the element is matched');
const rd=cs.filter(c=>c.badges.includes('Editor override')); chk(rd.length===2&&rd.some(c=>c.decls==='padding: 11px;'&&!c.badges.some(b=>/@media/.test(b)))&&rd.some(c=>c.decls==='padding: 5px;'&&c.badges.some(b=>/1024px/.test(b))),'the editor’s own desktop + tablet overrides are listed without the !important noise — '+JSON.stringify(rd.map(c=>c.decls)));
const eng=find('.hero h1','Blog post list (engine runtime)'); chk(!!eng&&eng.ro&&eng.readonly&&eng.btn===''&&eng.badges.includes('read-only'),'an engine-managed stylesheet is read-only with a pointer to where it is managed');
const body=cs.find(c=>c.sel==='body'); chk(!!body&&body.badges.some(b=>b==='via <body>')&&body.decls==='font-family: Georgia, serif;','inherited: body{font-family} shows under “inherited” with the ancestor named');
chk(!cs.some(c=>c.sel==='.hero'),'…but .hero{padding} (not inheritable) is not listed');
chk(cs.indexOf(hov)<cs.indexOf(base),'more specific rules come first (:hover above the base rule)');
r=await p.evaluate(()=>({foot:(document.querySelector('#elcss-body .elcss-footnote')||{}).textContent||'',unreadable:_elcss.unreadable,sheets:Array.from(_elcss.doc.styleSheets).map(s=>{ let n=-1,err=''; try{ n=s.cssRules.length; }catch(e){ err=e.name; } return {href:s.href,node:s.ownerNode&&s.ownerNode.tagName+'#'+s.ownerNode.id,n,err}; })}));
chk(/Not readable here.*127\.0\.0\.1:8933\/ext\.css/.test(r.foot),'a cross-origin stylesheet is named as not readable here — '+JSON.stringify(r));
chk(await p.evaluate(()=>document.getElementById('elcss-title').textContent.indexOf('Element CSS — h1#hero-title')===0),'the window title names the element');

// ── edit the page <style> rule: only that block changes, byte for byte elsewhere
const setAndApply=async(key,text,btnSel)=>p.evaluate(async({key,text})=>{ const ta=document.querySelector('#elcss-body textarea[data-key="'+key+'"]'); ta.value=text; ta.dispatchEvent(new Event('input')); await elcssApply(key); },{key,text});
await setAndApply(base.key,'color: rgb(0, 128, 0);\nfont-size: 40px; /* big */\nmargin-left: 3px;');
r=await p.evaluate(()=>{ const d=document.getElementById('visual-iframe').contentDocument; const st=d.querySelectorAll('style')[0].textContent; const h=d.getElementById('hero-title'); return {st,ml:getComputedStyle(h).marginLeft,unsaved:document.getElementById('ve-unsaved').classList.contains('on')}; });
chk(r.st.indexOf('\n/* page styles */\n.hero h1 { color: rgb(0, 128, 0); font-size: 40px; /* big */ margin-left: 3px; }\n.hero h1:hover { color: rgb(200, 0, 0); }\n@media (max-width: 600px) {\n  .hero h1 {\n    font-size: 24px;\n  }\n}')===0,'editing the <style> rule rewrites just its declarations (one-line rule stays one line; comment, neighbours untouched) — got: '+JSON.stringify(r.st.slice(0,140)));
chk(r.ml==='3px'&&r.unsaved,'…the change is live in the editor and the page is marked unsaved');
cs=await cards(); const med2=cs.find(c=>c.sel==='.hero h1'&&c.badges.some(b=>/600px/.test(b)));
await setAndApply(med2.key,'font-size: 22px;\nline-height: 1.2;');
r=await p.evaluate(()=>document.getElementById('visual-iframe').contentDocument.querySelectorAll('style')[0].textContent);
chk(r.indexOf('@media (max-width: 600px) {\n  .hero h1 {\n    font-size: 22px;\n    line-height: 1.2;\n  }\n}')!==-1,'a multi-line rule inside @media keeps its indentation — got: '+JSON.stringify(r.slice(r.indexOf('@media'),r.indexOf('@media')+90)));
// serialised page carries it (what Save writes)
chk(await p.evaluate(()=>/font-size: 22px;\n    line-height: 1\.2;/.test(veSerializeClean())),'veSerializeClean() (what Save writes) carries the edited stylesheet');

// ── inline style
await setAndApply('in','margin-top: 9px;\ncolor: rgb(1, 2, 3);');
r=await p.evaluate(()=>document.getElementById('visual-iframe').contentDocument.getElementById('hero-title').getAttribute('style'));
chk(r==='margin-top: 9px; color: rgb(1, 2, 3);','inline style edits write the style attribute — '+JSON.stringify(r));
await p.evaluate(async()=>{ window.__toasts=[]; const ta=document.querySelector('#elcss-body textarea[data-key="in"]'); ta.value='margin-top: 9px;\ncolr: red;'; await elcssApply('in'); });
r=await p.evaluate(()=>window.__toasts.map(t=>t.m));
chk(r.some(t=>/does not understand: colr: red/.test(t)),'a declaration the browser does not understand is applied but flagged — '+JSON.stringify(r));
await p.evaluate(async()=>{ await elcssRevert('in'); });
r=await p.evaluate(()=>document.getElementById('visual-iframe').contentDocument.getElementById('hero-title').getAttribute('style'));
chk(r==='margin-top: 7px; color: rgb(1, 2, 3);','Revert puts the original back — '+JSON.stringify(r));

// ── the stylesheet file: live in the editor + written to the server with a backup
cs=await cards(); const lnk2=cs.find(c=>c.sel==='.hero h1'&&c.badges.includes('style.css'));
await p.evaluate(()=>{ window.__writes=[]; window.__toasts=[]; });
await setAndApply(lnk2.key,'font-weight: 500;\nline-height: 1.1;');
r=await p.evaluate(()=>({writes:window.__writes.map(w=>w.path),file:window.__files['tests/fixtures/elcss/style.css'],bak:window.__files['data/bak/tests__fixtures__elcss__style.css'],fw:getComputedStyle(document.getElementById('visual-iframe').contentDocument.getElementById('hero-title')).fontWeight,toasts:window.__toasts.map(t=>t.m)}));
chk(r.writes.join(',')==='data/bak/tests__fixtures__elcss__style.css,tests/fixtures/elcss/style.css','the .css file is backed up to data/bak/ then written — '+r.writes.join(','));
chk(r.file===STYLE_CSS.replace('  font-weight: 700;\n  line-height: 1.1;','  font-weight: 500;\n  line-height: 1.1;')&&r.bak===STYLE_CSS,'only that rule’s declarations changed in the file; the backup is the previous copy');
chk(r.fw==='500'&&r.toasts.some(t=>/Saved to tests\/fixtures\/elcss\/style\.css/.test(t)),'…live in the editor, and the toast names the file');
// server copy drifted → refuse rather than clobber
await p.evaluate(()=>{ window.__files['tests/fixtures/elcss/style.css']='/* someone replaced this file */ .other{color:red}'; window.__writes=[]; window.__toasts=[]; });
cs=await cards(); const lnk3=cs.find(c=>c.sel==='.hero h1'&&c.badges.includes('style.css'));
await setAndApply(lnk3.key,'font-weight: 300;');
r=await p.evaluate(()=>({writes:window.__writes.length,toasts:window.__toasts.map(t=>t.m)}));
chk(r.writes===0&&r.toasts.some(t=>/does not match the stylesheet the editor loaded/.test(t)),'if the file on the server no longer matches, nothing is written and the user is told — '+JSON.stringify(r.toasts));
await p.evaluate(()=>{ window.__files['tests/fixtures/elcss/style.css']=window.__files['tests/fixtures/elcss/style.css']; });

// ── editor override (bridge): the responsive sheet is regenerated
cs=await cards(); const rdesk=cs.find(c=>c.badges.includes('Editor override')&&!c.badges.some(b=>/@media/.test(b)));
await setAndApply(rdesk.key,'padding: 13px;\nborder-radius: 4px;');
await p.waitForTimeout(150);
r=await p.evaluate(()=>document.getElementById('visual-iframe').contentDocument.getElementById('fourge-responsive').textContent);
chk(/\.fge-r7\{padding:13px !important;border-radius:4px !important;\}/.test(r)&&/@media \(max-width:1024px\)\{\.fge-r7\{padding(-top)?:5px !important;/.test(r),'the desktop override goes through the bridge (regenerated with !important; tablet rule kept) — '+JSON.stringify(r));

// ── add a rule
r=await p.evaluate(async()=>{ const out={sug:document.getElementById('elcss-new-sel').value,note:document.getElementById('elcss-new-note').textContent}; document.getElementById('elcss-new-decls').value='border-bottom: 2px solid rgb(0, 0, 255);'; await elcssAddRule(); const d=document.getElementById('visual-iframe').contentDocument; out.style=(d.getElementById('fourge-element-css')||{}).textContent; out.bb=getComputedStyle(d.getElementById('hero-title')).borderBottomWidth; out.serial=/#hero-title \{\n  border-bottom: 2px solid rgb\(0, 0, 255\);\n\}/.test(veSerializeClean()); return out; });
chk(r.sug==='#hero-title'&&r.note==='Matches just this element','the add-rule selector is suggested from the element and checked live — '+JSON.stringify([r.sug,r.note]));
chk(/#hero-title \{\n  border-bottom: 2px solid rgb\(0, 0, 255\);\n\}/.test(r.style||'')&&r.bb==='2px'&&r.serial,'the new rule lands in a page <style id="fourge-element-css"> block, applies live and is part of what Save writes');
cs=await cards(); const added=cs.find(c=>c.sel==='#hero-title');
chk(!!added&&added.badges.includes('Element CSS rules')&&!added.ro&&cs.indexOf(added)===1,'…and shows up as the top (most specific) editable rule');
chk(await p.evaluate(()=>{ document.getElementById('elcss-new-sel').value='.nope'; elcssNewSelCheck(); return document.getElementById('elcss-new-note').textContent; })==='Does not match the selected element','a selector that misses the element is flagged');
// Esc: first leaves the text box, then closes the window
await p.evaluate(()=>document.querySelector('#elcss-body textarea').focus());
await p.keyboard.press('Escape'); r=await p.evaluate(()=>({open:document.getElementById('m-elcss').classList.contains('on'),ta:document.activeElement&&document.activeElement.tagName}));
chk(r.open&&r.ta!=='TEXTAREA','Esc inside a text box only leaves the box');
await p.keyboard.press('Escape'); chk(await p.evaluate(()=>!document.getElementById('m-elcss').classList.contains('on')),'Esc again closes the window');
chk(await p.evaluate(()=>!!document.getElementById('ve-elcss-btn')&&document.getElementById('ve-elcss-btn').textContent.trim()==='Element CSS'),'the toolbar has the Element CSS button');
await done('element css');
