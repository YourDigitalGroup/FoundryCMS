import { launchPage, ROOT } from '../lib/harness.mjs'; import fs from 'node:fs';
// 1.14.136 — the admin notices an api.php older than itself (ping → api_version) and
// offers a one-click, byte-verified install; refusals are shown word for word; the
// updater records why its own api.php step failed. Real functions, api.php scripted.
const {b,p,errs}=await launchPage();
let fail=false; const chk=(c,l)=>{ console.log((c?'ok   ':'FAIL ')+l); if(!c)fail=true; };
chk(errs.length===0,'admin loads with zero JS errors');
// three numbers, one release
const api=fs.readFileSync(ROOT+'/admin/api.php','utf8'), idx=fs.readFileSync(ROOT+'/admin/index.html','utf8'), vj=JSON.parse(fs.readFileSync(ROOT+'/admin/version.json','utf8')).version;
const vApi=(api.match(/^define\('FOURGE_API_VERSION', '([^']+)'\);/m)||[])[1], vCms=(idx.match(/^const CMS_VERSION='([^']+)';/m)||[])[1];
chk(vApi && vApi===vCms && vCms===vj, 'V1 FOURGE_API_VERSION, CMS_VERSION and version.json agree — '+JSON.stringify({vApi,vCms,vj}));
chk(/case 'ping':.*'api_version' => FOURGE_API_VERSION/.test(api), 'V2 ping reports api_version from the constant');
const setup=`localStorage.setItem('cd_token','t'); window.__writes=[]; window.__t=[]; window.toast=(m,t)=>window.__t.push({m,t}); window.getTplRepo=()=>'YourDigitalGroup/FoundryCMS@main'; window.fetchRepoFile=async(r,b,path)=>{ window.__fetched=path; return window.__apiFile; }; window.__apiFile='<?php\\n$PUBLIC_ACTIONS=[];\\n// new api\\n'; window.__writeFail=null; window.__shortWrite=false; window.__installed=false;`;
const run=async(ping,extra)=>p.evaluate(async({setup,ping,extra})=>{ eval(setup); localStorage.removeItem('fourge_api_install_error'); window.__ping=ping; window.apiCall=async(a,body={})=>{ if(a==='ping') return window.__ping; if(a==='write_file'){ window.__writes.push({path:body.path,len:body.content.length}); if(window.__writeFail) throw new Error(window.__writeFail); return {ok:true,size:window.__shortWrite?body.content.length-1:body.content.length}; } return {ok:true}; }; return await (new Function('return (async()=>{'+extra+'})()'))(); },{setup,ping,extra});
// A. detection
let r=await run({ok:true,version:'1.2.0'},`const r=await apiVersionCheck(); return {r, shown:document.getElementById('api-banner').classList.contains('show'), msg:document.getElementById('api-msg').textContent};`);
chk(r.r.stale===true && r.shown && /older than 1\.14\.136/.test(r.msg) && new RegExp('this admin is '+vCms).test(r.msg) && /Unknown action/.test(r.msg), 'A1 an api.php that reports no api_version (every build before 1.14.136) raises the banner — got: '+r.msg.slice(0,140));
r=await run({ok:true,api_version:'1.14.120'},`const r=await apiVersionCheck(); return {r, msg:document.getElementById('api-msg').textContent, shown:document.getElementById('api-banner').classList.contains('show')};`);
chk(r.r.stale===true && r.shown && /version 1\.14\.120 while this admin is/.test(r.msg), 'A2 an older api_version is named in the banner');
r=await run({ok:true,api_version:vCms},`const r=await apiVersionCheck(); return {r, shown:document.getElementById('api-banner').classList.contains('show')};`);
chk(r.r.stale===false && !r.shown, 'A3 a matching api_version hides the banner');
r=await run({ok:true,api_version:'1.14.999'},`const r=await apiVersionCheck(); return r;`);
chk(r.stale===false, 'A4 a newer api.php than the admin is not "stale"');
r=await run({ok:true,version:'1.2.0'},`localStorage.setItem('fourge_api_install_error',JSON.stringify({at:'2026-10-01T10:00:00Z',version:'1.14.135',error:'Refusing to overwrite api.php: config.secret.php not found.'})); await apiVersionCheck(); return document.getElementById('api-msg').textContent;`);
chk(/Last install attempt \(.*\): Refusing to overwrite api\.php: config\.secret\.php not found\./.test(r), 'A5 the reason recorded by the updater is shown in the banner — got: '+r.slice(-120));
// B. the one-click install
r=await run({ok:true,version:'1.2.0'},`window.__pingAfter=true; const origCall=window.apiCall; window.apiCall=async(a,b)=>{ if(a==='ping'&&window.__installed) return {ok:true,api_version:CMS_VERSION}; const x=await origCall(a,b); if(a==='write_file') window.__installed=true; return x; }; const realReload=location.reload; let reloaded=false; try{ Object.defineProperty(window,'__r',{value:1}); }catch(e){} const ok=await installApiPhpNow(document.getElementById('api-install-btn')); return {ok, fetched:window.__fetched, writes:window.__writes, shown:document.getElementById('api-banner').classList.contains('show'), toasts:window.__t.map(t=>t.m), err:localStorage.getItem('fourge_api_install_error')};`);
chk(r.ok===true && r.fetched==='admin/api.php' && r.writes.length===1 && r.writes[0].path==='admin/api.php' && !r.shown && r.toasts.some(t=>/api\.php installed \(\d+ bytes\)/.test(t)) && r.err===null, 'B1 Install fetches admin/api.php from the engine repo, writes it, verifies the byte count, clears the banner and the recorded error — got: '+JSON.stringify({ok:r.ok,writes:r.writes,toasts:r.toasts}));
r=await run({ok:true,version:'1.2.0'},`window.__apiFile='<html>not php</html>'; const ok=await installApiPhpNow(null); return {ok, writes:window.__writes, msg:document.getElementById('api-msg').textContent, err:JSON.parse(localStorage.getItem('fourge_api_install_error')||'null')};`);
chk(r.ok===false && r.writes.length===0 && /does not look like the engine file/.test(r.msg) && r.err && /does not look like/.test(r.err.error), 'B2 a download that is not the engine file is never written; the reason is shown and recorded');
r=await run({ok:true,version:'1.2.0'},`window.__writeFail='Refusing to install admin/api.php: it does not parse on this server (PHP 7.3): syntax error, unexpected token "fn"'; const ok=await installApiPhpNow(null); return {ok, msg:document.getElementById('api-msg').textContent, err:JSON.parse(localStorage.getItem('fourge_api_install_error')||'null')};`);
chk(r.ok===false && /Could not install api\.php: Refusing to install admin\/api\.php: it does not parse on this server \(PHP 7\.3\)/.test(r.msg) && /upload admin\/api\.php from the engine repo by FTP/.test(r.msg) && r.err && /PHP 7\.3/.test(r.err.error), "B3 the server's refusal is shown word for word, with the manual fallback, and recorded");
r=await run({ok:true,version:'1.2.0'},`window.__shortWrite=true; const ok=await installApiPhpNow(null); return {ok, msg:document.getElementById('api-msg').textContent};`);
chk(r.ok===false && /Short write of admin\/api\.php/.test(r.msg) && /old file was kept/.test(r.msg), 'B4 a short write is reported as such');
// C. wiring
chk(await p.evaluate(()=>/apiVersionCheck\(\)/.test(initApp.toString())), 'C1 initApp() runs the check after the update gate');
chk(await p.evaluate(()=>/fourge_api_install_error/.test(doUpdate.toString())), 'C2 doUpdate() records why its api.php step failed');
chk(errs.length===0,'no JS errors during any scenario');
await b.close(); console.log(fail?'\nSUITE FAILED':'\nall api version banner assertions passed'); process.exit(fail?1:0);
