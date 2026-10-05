import { openAdmin, TURL } from '../lib/harness.mjs';
// 1.14.137 — every browser confirm()/prompt() is an in-app dialog: uiConfirm / uiPrompt / uiAlert,
// keyboard (Enter / Esc), and the converted call sites.
const {p,chk,done}=await openAdmin();
const src=await (await fetch(TURL)).text();
const raw=(src.match(/[^a-zA-Z_.](confirm|prompt|alert)\(/g)||[]);
chk(raw.length===0,'admin source has no bare confirm( / prompt( / alert( left (the window.* fallbacks inside the ui* helpers are the only natives) — found '+raw.length);
chk((src.match(/await uiConfirm\(/g)||[]).length>=38,'38+ call sites await uiConfirm — '+(src.match(/await uiConfirm\(/g)||[]).length);
const fleet=await (await fetch(new URL('/fleet-dashboard/index.html',TURL))).text();
chk((fleet.match(/[^a-zA-Z_.](confirm|prompt|alert)\(/g)||[]).length===0 && /fdDialog\(/.test(fleet),'fleet dashboard uses its own in-page dialog too');

// uiConfirm: OK / Cancel / Enter / Esc
let r=await p.evaluate(async()=>{ const pr=uiConfirm('Really?','Yes do it','Check'); await new Promise(x=>setTimeout(x,50)); const m=document.getElementById('m-uiconfirm'); const out={open:m.classList.contains('on'),title:document.getElementById('uiconfirm-title').textContent,msg:document.getElementById('uiconfirm-msg').textContent,ok:document.getElementById('uiconfirm-ok').textContent,focus:document.activeElement&&document.activeElement.id}; document.getElementById('uiconfirm-ok').click(); out.val=await pr; out.closed=!m.classList.contains('on'); return out; });
chk(r.open&&r.title==='Check'&&r.msg==='Really?'&&r.ok==='Yes do it'&&r.val===true&&r.closed,'uiConfirm: opens with title/message/OK label, OK resolves true and closes — '+JSON.stringify(r));
chk(r.focus==='uiconfirm-ok','uiConfirm: focus lands on the OK button (so Enter confirms)');
r=await p.evaluate(async()=>{ const pr=uiConfirm('Again?'); await new Promise(x=>setTimeout(x,50)); document.querySelector('#m-uiconfirm .mf .fd-btn-secondary').click(); return await pr; });
chk(r===false,'uiConfirm: Cancel resolves false');
await p.evaluate(()=>{ window.__pr=uiConfirm('Esc?'); }); await p.waitForTimeout(80); await p.keyboard.press('Escape');
r=await p.evaluate(async()=>await window.__pr); chk(r===false,'uiConfirm: Escape resolves false');
await p.evaluate(()=>{ window.__pr=uiConfirm('Enter?'); }); await p.waitForTimeout(80); await p.keyboard.press('Enter');
r=await p.evaluate(async()=>await window.__pr); chk(r===true,'uiConfirm: Enter resolves true');
r=await p.evaluate(async()=>{ const pr=uiConfirm('line one\n\nline two'); await new Promise(x=>setTimeout(x,30)); const ws=getComputedStyle(document.getElementById('uiconfirm-msg')).whiteSpace; uiConfirmResolve(false); await pr; return ws; });
chk(r==='pre-line','uiConfirm: message keeps its line breaks (white-space: pre-line)');

// uiPrompt
r=await p.evaluate(async()=>{ const pr=uiPrompt('Link URL:','https://','Add link','Insert a link','https://example.com'); await new Promise(x=>setTimeout(x,60)); const inp=document.getElementById('uiprompt-input'); const out={open:document.getElementById('m-uiprompt').classList.contains('on'),def:inp.value,focused:document.activeElement===inp,selected:inp.selectionStart===0&&inp.selectionEnd===inp.value.length,title:document.getElementById('uiprompt-title').textContent,ok:document.getElementById('uiprompt-ok').textContent,ph:inp.placeholder}; inp.value='https://x.test/p'; document.getElementById('uiprompt-ok').click(); out.val=await pr; return out; });
chk(r.open&&r.def==='https://'&&r.focused&&r.selected&&r.title==='Insert a link'&&r.ok==='Add link'&&r.ph==='https://example.com'&&r.val==='https://x.test/p','uiPrompt: default value pre-filled and selected, OK resolves the typed text — '+JSON.stringify(r));
await p.evaluate(()=>{ window.__pr=uiPrompt('Type:'); }); await p.waitForTimeout(80); await p.keyboard.type('hello'); await p.keyboard.press('Enter');
r=await p.evaluate(async()=>await window.__pr); chk(r==='hello','uiPrompt: Enter in the field submits');
await p.evaluate(()=>{ window.__pr=uiPrompt('Type:'); }); await p.waitForTimeout(80); await p.keyboard.press('Escape');
r=await p.evaluate(async()=>await window.__pr); chk(r===null,'uiPrompt: Escape resolves null (same contract as window.prompt)');
// uiAlert
r=await p.evaluate(async()=>{ const pr=uiAlert('Saved.','Done'); await new Promise(x=>setTimeout(x,50)); const out={open:document.getElementById('m-uialert').classList.contains('on'),msg:document.getElementById('uialert-msg').textContent,title:document.getElementById('uialert-title').textContent}; document.getElementById('uialert-ok').click(); await pr; out.closed=!document.getElementById('m-uialert').classList.contains('on'); return out; });
chk(r.open&&r.msg==='Saved.'&&r.title==='Done'&&r.closed,'uiAlert: opens and resolves on OK');

// A converted call site behaves: deleting an element asks in-app, Cancel sends nothing to the page
r=await p.evaluate(async()=>{ _veCurSel={fid:'f1',tag:'p'}; const ifr=document.getElementById('visual-iframe'); const sent=[]; const orig=ifr.contentWindow.postMessage; ifr.contentWindow.postMessage=(m)=>sent.push(m); const pr=veElemAction('delete'); await new Promise(x=>setTimeout(x,60)); const out={open:document.getElementById('m-uiconfirm').classList.contains('on'),msg:document.getElementById('uiconfirm-msg').textContent,ok:document.getElementById('uiconfirm-ok').textContent}; uiConfirmResolve(false); await pr; out.sent=sent.length; const pr2=veElemAction('delete'); await new Promise(x=>setTimeout(x,60)); uiConfirmResolve(true); await pr2; out.sentAfterOk=sent.length; ifr.contentWindow.postMessage=orig; _veCurSel=null; return out; });
chk(r.open&&/Delete this element/.test(r.msg)&&r.ok==='Delete element'&&r.sent===0&&r.sentAfterOk===1,'veElemAction("delete"): in-app confirm, Cancel does nothing, OK sends the delete — '+JSON.stringify(r));
r=await p.evaluate(async()=>{ const pr=peLink(); await new Promise(x=>setTimeout(x,60)); const out={open:document.getElementById('m-uiprompt').classList.contains('on'),def:document.getElementById('uiprompt-input').value}; uiPromptResolve(null); await pr; return out; });
chk(r.open&&r.def==='https://','peLink(): the rich-text link button asks for the URL in-app');
await done('ui dialogs');
