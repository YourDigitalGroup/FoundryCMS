import { launchPage } from '../lib/harness.mjs';
// 1.14.135 — Site Name + Website URL are READ from the site, not typed: detectSiteIdentity()
// (og:site_name → structured data → logo alt → majority "| Name" title suffix → home title →
// domain; canonical → og:url → structured-data url → production-looking own origin),
// the Design-tab fill (blanks only, "Detect again" overwrites, saves once) and the sign-in
// fill of site.json. Real functions in the real admin, api.php scripted.
const {b,p,errs}=await launchPage();
let fail=false; const chk=(c,l)=>{ console.log((c?'ok   ':'FAIL ')+l); if(!c)fail=true; };
chk(errs.length===0,'admin loads with zero JS errors');
await p.evaluate(()=>{
  localStorage.setItem('cd_token','t');
  window.__setup=(files)=>{ window.__files=Object.assign({},files); window.__writes=[]; window.__toasts=[]; window.toast=(m,t)=>window.__toasts.push({m,t}); window.ghMirror=async()=>({ok:true});
    window.apiCall=async(action,body={})=>{
      if(action==='list_pages') return {pages:Object.keys(window.__files).filter(f=>/\.html$/.test(f)).map(f=>({file:f.split('/').pop(),path:f,title:(window.__files[f].match(/<title>([^<]*)/)||[])[1]||'',size:1,modified:'',is_cms:false,snippet:'',has_post_list:false})),root:'/x',postsHtml:false};
      if(action==='read_file'){ if(body.path in window.__files) return {content:window.__files[body.path]}; const e=new Error('File not found: '+body.path); e.status=404; throw e; }
      if(action==='write_file'){ window.__files[body.path]=body.content; window.__writes.push(body.path); return {ok:true,size:body.content.length}; }
      return {ok:true}; }; };
  window.__page=(title,extra)=>'<!doctype html><html><head><meta charset="utf-8"><title>'+title+'</title></head><body>'+(extra||'')+'</body></html>';
});
const LAKE_HOME='<!doctype html><html><head><title>Digital Marketing Agency | North Central Arkansas</title><meta property="og:url" content="https://thelakedigital.com/"><link rel="canonical" href="https://thelakedigital.com/"></head><body><header class="site-header"><a href="/"><img src="/uploads/logo.png" alt="The Lake Digital"></a><nav>x</nav></header><main>home</main></body></html>';
const lakeFiles={'index.html':LAKE_HOME,'ada.html':'<html><head><title>ADA Website Compliance | The Lake Digital</title></head><body>a</body></html>','video.html':'<html><head><title>Amazon Prime Video Advertising | The Lake Digital</title></head><body>b</body></html>','blog.html':'<html><head><title>Digital Marketing Blog for Local Businesses | The Lake Digital</title></head><body>c</body></html>'};
const run=async(files,site,fn)=>p.evaluate(async({files,site,fn})=>{ __setup(files); _site=site; _pages={}; _postsHtmlExists=null; _postListPageIds=null; return await (new Function('return (async()=>{'+fn+'})()'))(); },{files,site,fn});

// ── A. detection ──
let d=await run(lakeFiles,{},'return await detectSiteIdentity();');
chk(d.name==='The Lake Digital' && d.nameSource==='the logo' && d.website==='https://thelakedigital.com' && d.websiteSource==='the canonical address', 'A1 Lake-like site: name from the header logo alt, address from the canonical — got: '+JSON.stringify(d));
const montHome=LAKE_HOME.replace('The Lake Digital','Digital Marketing Monterey').replace(/thelakedigital\.com/g,'digitalmarketingmonterey.com').replace('Digital Marketing Agency | North Central Arkansas','Digital Marketing Monterey | Grow Revenue, Not Just Traffic');
d=await run({'index.html':montHome,'a.html':__p('ADA Website Compliance Monterey | WCAG 2.1 AA Fixes'),'b.html':__p('Amazon Prime Video Advertising | Monterey Digital'),'c.html':__p('AI Chat Assistant for Websites | Monterey'),'e.html':__p('Google AI Search Box Update | Digital Marketing Monterey')},{},'return await detectSiteIdentity();');
function __p(t){ return '<html><head><title>'+t+'</title></head><body>x</body></html>'; }
chk(d.name==='Digital Marketing Monterey' && d.nameSource==='the logo' && d.website==='https://digitalmarketingmonterey.com', 'A2 Monterey-like site with noisy titles: the logo alt wins over an ambiguous title suffix — got: '+JSON.stringify(d));
const richHome='<!doctype html><html><head><title>Home - Acme</title><meta property="og:site_name" content="Acme Roofing &amp; Solar"><script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","name":"Acme Roofing LLC","url":"https://www.acmeroofing.com/"}</script></head><body><header><img src="l.png" alt="Logo"></header></body></html>';
d=await run({'index.html':richHome},{},'return await detectSiteIdentity();');
chk(d.name==='Acme Roofing & Solar' && d.nameSource==='og:site_name' && d.website==='https://www.acmeroofing.com' && d.websiteSource==='structured data', 'A3 og:site_name beats everything for the name (entities decoded); with no canonical the structured-data url gives the address — got: '+JSON.stringify(d));
d=await run({'index.html':'<html><head><title>Welcome</title></head><body><header><img src="l.png" alt="Logo"></header></body></html>','a.html':__p('Services | Brightwater Dental'),'b.html':__p('Contact | Brightwater Dental'),'c.html':__p('Team | Brightwater Dental')},{},'return await detectSiteIdentity();');
chk(d.name==='Brightwater Dental' && d.nameSource==='page titles' && d.website==='', 'A4 a generic logo alt ("Logo") is ignored, the majority title suffix is used; on this dev host (http, 127.0.0.1) no address is guessed — got: '+JSON.stringify(d));
d=await run({'index.html':'<html><head><title>Welcome</title></head><body>plain</body></html>','a.html':__p('Services | A'),'b.html':__p('Contact | B')},{},'return await detectSiteIdentity();');
chk(d.name==='' && d.website==='', 'A5 with nothing to read (no meta, no logo, titles that do not agree) nothing is invented');
d=await run({'index.html':'<html><head><title>Welcome</title><link rel="canonical" href="https://www.bluefin-marine.com/"></head><body>plain</body></html>'},{},'return await detectSiteIdentity();');
chk(d.name==='Bluefin-marine' && d.nameSource==='the domain name' && d.website==='https://www.bluefin-marine.com', 'A6 with only a canonical address the name falls back to the domain (last resort, clearly labelled)');
chk(await p.evaluate(()=>siteIdentityProductionOrigin()===''), 'A7 siteIdentityProductionOrigin() refuses this dev host (http, port) — a signed-in address is only used when it looks like production');

// ── B. Design tab fill: blanks only, saves once, "Detect again" overwrites ──
let r=await run(lakeFiles,{name:'',website:'',phone:'555'},`
  ['si-name','si-website','si-phone'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  _siteInfoLoaded=true;
  await siteIdentityFillForm(false);
  return {name:document.getElementById('si-name').value, web:document.getElementById('si-website').value, hint:document.getElementById('si-identity-hint').textContent, writes:__writes, saved:JSON.parse(__files['data/site.json']||'{}'), toasts:__toasts.map(t=>t.m)};`);
chk(r.name==='The Lake Digital' && r.web==='https://thelakedigital.com', 'B1 blank Site Name / Website URL fields are filled from the site — got: '+JSON.stringify({n:r.name,w:r.web}));
chk(/Detected from your site: Site Name from the logo, Website URL from the canonical address/.test(r.hint), 'B2 the hint says where each value came from — got: '+r.hint.slice(0,120));
chk(r.writes.includes('data/site.json') && r.saved.name==='The Lake Digital' && r.saved.website==='https://thelakedigital.com', 'B3 …and site.json is saved right away (no Save click needed)');
r=await run(lakeFiles,{name:'My Typed Name',website:'https://typed.example'},`
  document.getElementById('si-name').value='My Typed Name'; document.getElementById('si-website').value='https://typed.example'; _siteInfoLoaded=true;
  await siteIdentityFillForm(false);
  return {name:document.getElementById('si-name').value, web:document.getElementById('si-website').value, writes:__writes};`);
chk(r.name==='My Typed Name' && r.web==='https://typed.example' && r.writes.length===0, 'B4 values the operator typed are never overwritten and nothing is saved');
r=await run(lakeFiles,{name:'My Typed Name',website:'https://typed.example'},`
  document.getElementById('si-name').value='My Typed Name'; document.getElementById('si-website').value='https://typed.example'; _siteInfoLoaded=true;
  await siteIdentityFillForm(true);
  return {name:document.getElementById('si-name').value, web:document.getElementById('si-website').value, writes:__writes};`);
chk(r.name==='The Lake Digital' && r.web==='https://thelakedigital.com' && r.writes.includes('data/site.json'), 'B5 "Detect again" (forced) overwrites both and saves');
r=await run(lakeFiles,{},`document.getElementById('si-name').value=''; document.getElementById('si-website').value=''; _siteInfoLoaded=true; await loadSiteInfo(); await new Promise(x=>setTimeout(x,300)); return {name:document.getElementById('si-name').value, web:document.getElementById('si-website').value};`);
chk(r.name==='The Lake Digital' && r.web==='https://thelakedigital.com', 'B6 opening the Design tab (loadSiteInfo → designAutofill) fills them by itself');

// ── C. sign-in fill of site.json ──
r=await run(lakeFiles,{phone:'555',blogSync:{enabled:true}},`await siteIdentityAutofillOnce(); return {site:JSON.parse(__files['data/site.json']||'{}'), writes:__writes, toasts:__toasts.map(t=>t.m)};`);
chk(r.writes.includes('data/site.json') && r.site.name==='The Lake Digital' && r.site.website==='https://thelakedigital.com' && r.site.phone==='555' && r.site.blogSync.enabled===true, 'C1 at sign-in a site.json without name/website gets both written (everything else kept) — got: '+JSON.stringify(r.site));
chk(r.toasts.some(t=>/Site identity read from your site/.test(t)&&/The Lake Digital/.test(t)), 'C2 …and says so — got: '+JSON.stringify(r.toasts));
r=await run(lakeFiles,{name:'Set',website:'https://set.example'},`await siteIdentityAutofillOnce(); return __writes;`);
chk(r.length===0, 'C3 with both already set, sign-in writes nothing');
r=await run(lakeFiles,{name:'Set'},`await siteIdentityAutofillOnce(); const s=JSON.parse(__files['data/site.json']||'{}'); return {writes:__writes, name:s.name, web:s.website};`);
chk(r.writes.length===1 && r.name==='Set' && r.web==='https://thelakedigital.com', 'C4 only the missing half is filled (name kept, address detected)');
chk(await p.evaluate(()=>/siteIdentityAutofillOnce\(\)/.test(initApp.toString())), 'C5 initApp() runs the sign-in fill');
chk(errs.length===0,'no JS errors during any scenario');
await b.close(); console.log(fail?'\nSUITE FAILED':'\nall site identity assertions passed'); process.exit(fail?1:0);
