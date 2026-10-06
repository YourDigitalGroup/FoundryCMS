import { openAdmin } from '../lib/harness.mjs';
// Layout regression guard (1.14.139). In 1.14.136 one extra </div> at the end of the Settings panel
// closed .main early, so Site Foundry, Users, Revisions, ADAptify, Reviews, Map and Events rendered
// beside an empty main area. Every panel must live inside .main and fill it when opened, with no errors.
const {p,chk,errs,done}=await openAdmin();
// console errors that are not resource 404s / aborted third-party requests (the test server has no uploads, no CDN)
const cons=[]; p.on('console',m=>{ if(m.type()==='error'&&!/Failed to load resource/.test(m.text())) cons.push(m.text().slice(0,200)); });
await p.evaluate(()=>{ document.getElementById('fx-loader')?.remove(); _site={name:'Layout',website:'http://127.0.0.1:8931'}; });
const outside=await p.evaluate(()=>{ const main=document.querySelector('.main'); return [...document.querySelectorAll('.panel')].filter(x=>!main.contains(x)).map(x=>x.id); });
chk(outside.length===0,'every .panel is inside .main (the markup nests correctly) — outside: '+JSON.stringify(outside));
chk(await p.evaluate(()=>{ const main=document.querySelector('.main'); return [...main.parentElement.children].filter(x=>x.classList.contains('panel')).length===0; }),'no panel is a sibling of .main in .body-row');
const ids=await p.evaluate(()=>[...document.querySelectorAll('.panel')].map(x=>x.id));
chk(ids.length>=20&&ids.includes('p-factory')&&ids.includes('p-events'),'the admin has its full set of panels ('+ids.length+')');
const bad=[];
for(const id of ids){
  const before=errs.length+cons.length;
  await p.evaluate((id)=>goPanel(id),id); await p.waitForTimeout(250);
  const r=await p.evaluate((id)=>{ const main=document.querySelector('.main'); const mb=main.getBoundingClientRect(); const el=document.getElementById(id); const bb=el.getBoundingClientRect(); return {display:getComputedStyle(el).display,x:Math.round(bb.x),w:Math.round(bb.width),h:Math.round(bb.height),mainX:Math.round(mb.x),mainW:Math.round(mb.width),others:[...document.querySelectorAll('.panel.on')].filter(x=>x.id!==id).map(x=>x.id)}; },id);
  const newErrs=errs.slice(before).concat(cons.slice(before));
  if(!(r.display!=='none'&&r.x===r.mainX&&Math.abs(r.w-r.mainW)<=1&&r.h>100&&r.others.length===0&&newErrs.length===0)) bad.push(id+': '+JSON.stringify(r)+(newErrs.length?' errors: '+newErrs.join(' | '):''));
}
chk(bad.length===0,'opening each panel fills the main area, hides the others and throws nothing — '+(bad.length?bad.join('\n     '):ids.length+' panels'));
// the two panels from the report, by name
for(const [nav,id] of [['factory','p-factory'],['users','p-users'],['events','p-events']]){
  await p.evaluate((nav)=>navTo(nav),nav); await p.waitForTimeout(200);
  const r=await p.evaluate((id)=>{ const el=document.getElementById(id); const bb=el.getBoundingClientRect(); const mb=document.querySelector('.main').getBoundingClientRect(); return {on:el.classList.contains('on'),x:Math.round(bb.x),mainX:Math.round(mb.x),w:Math.round(bb.width),mainW:Math.round(mb.width)}; },id);
  chk(r.on&&r.x===r.mainX&&Math.abs(r.w-r.mainW)<=1,'navTo("'+nav+'") shows '+id+' across the whole main area — '+JSON.stringify(r));
}
// Site Foundry's own two-column layout: the tab rail and the panel area sit side by side inside it
const sf=await p.evaluate(()=>{ navTo('factory'); const el=document.getElementById('p-factory'); const k=[...el.children].map(c=>{ const b=c.getBoundingClientRect(); return {x:Math.round(b.x),w:Math.round(b.width)}; }); const scan=document.getElementById('ifs-scan-btn').getBoundingClientRect(); return {k,scanVisible:scan.width>0&&scan.x>k[0].x+k[0].w}; });
chk(sf.k.length===2&&sf.k[0].w===180&&sf.k[1].x===sf.k[0].x+180&&sf.k[1].w>600&&sf.scanVisible,'Site Foundry: 180px tab rail + the import area beside it, "Scan public_html" visible — '+JSON.stringify(sf));
await done('panel layout');
