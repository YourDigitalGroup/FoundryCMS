import { openAdmin } from '../lib/harness.mjs';
// 1.14.137 — Code editor: click a line number to select the whole line (shift extends),
// active-line highlight, ⌘/Ctrl+F searches the code (CodeMirror search addon, matches off-screen).
const {p,chk,done}=await openAdmin();
const lines=[]; for(let i=1;i<=400;i++) lines.push(i===250?'<p class="needle">find me here</p>':'<p>line '+i+'</p>');
const html='<!DOCTYPE html>\n<html><head><title>T</title></head><body>\n'+lines.join('\n')+'\n</body></html>';
await p.evaluate((html)=>{ _pages={p1:{id:'p1',path:'p1.html',file:'p1.html',title:'T'}}; _curPage='p1'; _veHtml.p1=html; _veOrigHtml.p1=html; goPanel('p-pages'); veSetMode('code'); _cmEditor.setValue(html); _cmLoadedValue=html; },html);
await p.waitForTimeout(200);
let r=await p.evaluate(()=>({mode:_veMode,cm:!!_cmEditor,active:_cmEditor.getOption('styleActiveLine')===true,keys:(()=>{ const k=_cmEditor.getOption('extraKeys'); return {f:k['Cmd-F'],cf:k['Ctrl-F'],s:typeof k['Cmd-S'],h:k['Ctrl-H']}; })(),hasSearch:typeof _cmEditor.getSearchCursor==='function',hasDialog:typeof _cmEditor.openDialog==='function',hint:!!document.getElementById('ve-code-hint'),lines:_cmEditor.lineCount()}));
chk(r.mode==='code'&&r.cm&&r.lines===403,'Code mode opens CodeMirror with the page ('+r.lines+' lines)');
chk(r.active,'the current line is highlighted (styleActiveLine)');
chk(r.keys.f==='findPersistent'&&r.keys.cf==='findPersistent'&&r.keys.s==='function'&&r.keys.h==='replace','⌘/Ctrl+F → in-editor search; ⌘/Ctrl+S → save keep working');
chk(r.hasSearch&&r.hasDialog,'search + dialog addons loaded (searchcursor, dialog)');
chk(r.hint,'a key hint sits in the editor corner');
// gutter click selects the whole line
await p.locator('.CodeMirror-gutter-wrapper .CodeMirror-linenumber',{hasText:/^5$/}).click();   // line 5 (index 4)
r=await p.evaluate(()=>{ const s=_cmEditor.listSelections()[0]; return {from:s.anchor,to:s.head,text:_cmEditor.getSelection()}; });
chk(r.from.line===4&&r.from.ch===0&&r.to.line===5&&r.to.ch===0&&r.text==='<p>line 3</p>\n','click on line number 5 → that whole line is selected (incl. its line break) — '+JSON.stringify(r));
await p.locator('.CodeMirror-gutter-wrapper .CodeMirror-linenumber',{hasText:/^9$/}).click({modifiers:['Shift']});
r=await p.evaluate(()=>{ const s=_cmEditor.listSelections()[0]; return {a:s.anchor.line,h:s.head.line,n:_cmEditor.getSelection().split('\n').length-1}; });
chk(r.a===4&&r.h===9&&r.n===5,'shift-click on line 9 extends the selection to lines 5–9 — '+JSON.stringify(r));
r=await p.evaluate(()=>{ veCmGutterClick(_cmEditor,402,'CodeMirror-linenumbers',{}); const s=_cmEditor.listSelections()[0]; return {a:s.anchor,h:s.head,text:_cmEditor.getSelection()}; });
chk(r.a.line===402&&r.h.line===402&&r.text==='</body></html>','the last line selects to its end (no line break to include)');
// search inside the code: Ctrl+F opens the in-editor search bar; Enter jumps to an off-screen match
await p.evaluate(()=>{ _cmEditor.setCursor({line:0,ch:0}); _cmEditor.focus(); });
await p.keyboard.press('Control+f'); await p.waitForTimeout(150);
chk(await p.evaluate(()=>!!document.querySelector('#ve-code-wrap .CodeMirror-dialog input')),'Ctrl+F inside the editor opens the search bar (not the browser Find)');
await p.keyboard.type('find me here'); await p.keyboard.press('Enter'); await p.waitForTimeout(150);
r=await p.evaluate(()=>{ const c=_cmEditor.getCursor(); const info=_cmEditor.getScrollInfo(); const y=_cmEditor.charCoords({line:c.line,ch:0},'local').top; return {line:c.line,sel:_cmEditor.getSelection(),visible:y>=info.top&&y<=info.top+info.clientHeight,barOpen:!!document.querySelector('#ve-code-wrap .CodeMirror-dialog')}; });
chk(r.line===251&&r.sel==='find me here'&&r.visible,'Enter finds the match 250 lines down, selects it and scrolls it into view — '+JSON.stringify(r));
chk(r.barOpen,'the search bar stays open for the next match (findPersistent)');
await p.keyboard.press('Escape'); await p.waitForTimeout(80);
chk(await p.evaluate(()=>!document.querySelector('#ve-code-wrap .CodeMirror-dialog')),'Esc closes the search bar');
// Ctrl+F with the focus NOT in the editor (just switched to Code) still searches the code
await p.evaluate(()=>{ document.activeElement&&document.activeElement.blur(); document.body.focus(); });
await p.keyboard.press('Control+f'); await p.waitForTimeout(150);
chk(await p.evaluate(()=>!!document.querySelector('#ve-code-wrap .CodeMirror-dialog input')&&document.activeElement===document.querySelector('#ve-code-wrap .CodeMirror-dialog input')),'Ctrl+F with focus elsewhere on the Code screen also opens the editor search');
await p.keyboard.press('Escape');
// …but not while typing in some other field
await p.evaluate(()=>{ const i=document.createElement('input'); i.id='__other'; document.body.appendChild(i); i.focus(); });
await p.keyboard.press('Control+f'); await p.waitForTimeout(100);
chk(await p.evaluate(()=>!document.querySelector('#ve-code-wrap .CodeMirror-dialog')),'…while another input has focus the shortcut is left alone');
await done('code editor');
