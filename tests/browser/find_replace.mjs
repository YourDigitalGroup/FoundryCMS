import { launchPage } from '../lib/harness.mjs';
// 1.14.136 — ONE site-wide Find & Replace. The matcher (case-preserving when Match case
// is off, whole words), the "text people read" scope (tags, links, class names, file
// names, script/style/code untouched; alt/title/meta descriptions changed), JSON
// string-values-only, the literal "everything" scope for domain swaps, and the real
// scan → confirm → replace flow with api.php scripted. The Go Live card is gone.
const {b,p,errs}=await launchPage({siteJson:'{"name":"T"}'});
let fail=false; const chk=(c,l)=>{ console.log((c?'ok   ':'FAIL ')+l); if(!c)fail=true; };
chk(errs.length===0,'admin loads with zero JS errors');

// ── A. one card, generic wording ──
const A=await p.evaluate(()=>({cards:document.querySelectorAll('h2').length?[...document.querySelectorAll('h2')].filter(h=>/Find/.test(h.textContent)).map(h=>h.textContent):[], golive:!!document.getElementById('go-find'), find:document.getElementById('ur-find')?.placeholder, repl:document.getElementById('ur-replace')?.placeholder, scope:document.getElementById('ur-scope')?.value, word:document.getElementById('ur-word')?.checked, matchCase:document.getElementById('ur-case')?.checked, desc:document.querySelector('#ur-find')?.closest('.si-sec')?.querySelector('.desc')?.textContent||''}));
chk(A.cards.length===1 && A.cards[0]==='Find & Replace' && !A.golive, 'A1 exactly one card, titled "Find & Replace"; the Go Live card is gone — got: '+JSON.stringify(A.cards));
chk(A.find==='epoxy' && A.repl==='coating' && A.scope==='text' && A.word===true && A.matchCase===false && /epoxy/.test(A.desc) && !/Launch|Go Live/.test(A.desc), 'A2 generic wording and defaults: text scope, whole words on, match case off, no "Launch"/"Go Live"');

// ── B. the matcher ──
const B=await p.evaluate(()=>{
  const m=frMatcher('epoxy','coating',{matchCase:false,wholeWord:true});
  const m2=frMatcher('epoxy','coating',{matchCase:true,wholeWord:false});
  const m3=frMatcher('dev-client.44interactive.com','www.client.com',{matchCase:true,wholeWord:false});
  return {a:m.replace('Our epoxy floors. Epoxy lasts. EPOXY SALE. epoxyresin.'), c1:m.count('epoxy Epoxy EPOXY epoxyresin'), b:m2.replace('epoxy Epoxy epoxyresin'), d:m3.replace('https://dev-client.44interactive.com/x and dev-client.44interactive.com')};
});
chk(B.a==='Our coating floors. Coating lasts. COATING SALE. epoxyresin.' && B.c1===3, 'B1 match case off keeps capitals (Epoxy→Coating, EPOXY→COATING); whole word leaves "epoxyresin" alone — got: '+B.a);
chk(B.b==='coating Epoxy coatingresin', 'B2 match case on + whole word off: exact case only, inside words too — got: '+B.b);
chk(B.d==='https://www.client.com/x and www.client.com', 'B3 a domain with dots and dashes is matched literally');

// ── C. text scope on HTML ──
const HTML='<!doctype html><html><head><title>Epoxy Floors | Acme</title><meta name="description" content="Best epoxy in town"><meta property="og:title" content="Epoxy Floors"><style>.epoxy{color:red}</style></head><body class="page-epoxy"><a href="/epoxy-floors" class="epoxy-link" title="See our epoxy work">Garage epoxy</a><img src="/images/epoxy-hero.jpg" alt="epoxy floor"><code>epoxy()</code><pre>epoxy pre</pre><script>var epoxy=1;</script><p data-x="epoxy">Why EPOXY beats paint, epoxy is tough.</p><!-- epoxy comment --></body></html>';
const C=await p.evaluate((HTML)=>{ const m=frMatcher('epoxy','coating',{matchCase:false,wholeWord:true}); const r=frHtmlText(HTML,m,'replace'); return {count:r.count, out:r.text}; },HTML);
chk(C.out.includes('<title>Coating Floors | Acme</title>') && C.out.includes('content="Best coating in town"') && C.out.includes('content="Coating Floors"') && C.out.includes('title="See our coating work"') && C.out.includes('alt="coating floor"') && C.out.includes('>Garage coating</a>') && C.out.includes('<p data-x="epoxy">Why COATING beats paint, coating is tough.</p>'), 'C1 text people read changes: title, meta description, og:title, link text, title/alt attributes, paragraph (case kept)');
chk(C.out.includes('.epoxy{color:red}') && C.out.includes('class="page-epoxy"') && C.out.includes('href="/epoxy-floors"') && C.out.includes('class="epoxy-link"') && C.out.includes('src="/images/epoxy-hero.jpg"') && C.out.includes('<code>epoxy()</code>') && C.out.includes('<pre>epoxy pre</pre>') && C.out.includes('var epoxy=1;') && C.out.includes('data-x="epoxy"') && C.out.includes('<!-- epoxy comment -->'), 'C2 untouched: CSS, class names, href, src/file names, <code>, <pre>, <script>, data attributes, comments');
chk(C.count===8, 'C3 the count equals the number of visible changes (8) — got '+C.count);

// ── D. JSON: string values only; "all" scope validates JSON ──
const D=await p.evaluate(()=>{
  const posts=[{id:'p1',slug:'epoxy-guide',title:'Epoxy guide',excerpt:'epoxy basics',blocks:[{type:'paragraph',html:'<p>We love <a href="/epoxy">epoxy</a>.</p>'},{type:'image',url:'/images/epoxy.jpg',alt:'epoxy'}]}];
  const text=frProcess(JSON.stringify(posts),'json','epoxy','coating',{matchCase:false,wholeWord:true,scope:'text'},'replace');
  const j=JSON.parse(text.next);
  const all=frProcess('{"a":"x"}','json','"a"','"b":"q","c"',{matchCase:true,wholeWord:false,scope:'all'},'replace');
  const allBad=frProcess('{"a":"x"}','json','"a"','oops',{matchCase:true,wholeWord:false,scope:'all'},'replace');
  return {count:text.count, slug:j[0].slug, title:j[0].title, excerpt:j[0].excerpt, html:j[0].blocks[0].html, url:j[0].blocks[1].url, alt:j[0].blocks[1].alt, allOk:all.next, allBad:allBad.refused||null};
});
chk(D.slug==='epoxy-guide' && D.url==='/images/epoxy.jpg' && D.title==='Coating guide' && D.excerpt==='coating basics' && D.html==='<p>We love <a href="/epoxy">coating</a>.</p>' && D.alt==='coating' && D.count===4, 'D1 JSON text scope: titles, excerpts, block text and alt change; slug, image path and the link inside block HTML do not — got: '+JSON.stringify(D));
const D2=await p.evaluate(()=>{ const m=frMatcher('epoxy','coating',{matchCase:false,wholeWord:true}); const r=frJsonText({category:'Epoxy',topic:'epoxy',email:'epoxy@acme.com',color:'#epoxy1',featured:'/images/blog-sync/abc-epoxy.jpg',canonicalUrl:'https://x.com/epoxy',date:'2026-09-01',note:'epoxy done right'},m,'replace'); return r.value; });
chk(D2.category==='Coating' && D2.topic==='coating' && D2.note==='coating done right' && D2.email==='epoxy@acme.com' && D2.color==='#epoxy1' && D2.featured==='/images/blog-sync/abc-epoxy.jpg' && D2.canonicalUrl==='https://x.com/epoxy', 'D1b single-word labels and sentences change; emails, colours, paths and URLs never do — got: '+JSON.stringify(D2));
chk(D.allOk==='{"b":"q","c":"x"}' && /corrupt this JSON/.test(D.allBad), 'D2 "everything" scope on JSON: a valid result is written, a result that breaks the JSON is refused');

// ── E. scan → replace flow through the real functions ──
const E=await p.evaluate(async(HTML)=>{
  localStorage.setItem('cd_token','t');
  const files={'index.html':HTML,'about.html':'<html><head><title>About</title></head><body><p>No match here</p></body></html>','data/posts.json':JSON.stringify([{id:'1',slug:'epoxy-101',title:'Epoxy 101',blocks:[]}]),'data/site.json':'{"name":"Acme Epoxy","tagline":"epoxy pros"}','settings.css':'.epoxy{color:red}'};
  const writes=[]; window.toast=(m,t)=>{ window.__t=(window.__t||[]); window.__t.push({m,t}); };
  window.uiConfirm=async()=>true; window.seoWriteSitemap=async()=>{}; window.seoWriteRobots=async()=>{}; window.seoLoadData=async()=>{}; window.ghMirror=async()=>({ok:true});
  window.apiCall=async(a,body={})=>{ if(a==='list_pages') return {pages:Object.keys(files).filter(f=>/\.html$/.test(f)).map(f=>({file:f,path:f,title:f,size:1,modified:'',is_cms:false,snippet:'',has_post_list:false})),root:'/x',postsHtml:false}; if(a==='read_file'){ if(body.path in files) return {content:files[body.path]}; const e=new Error('nf'); e.status=404; throw e; } if(a==='write_file'){ writes.push(body.path); files[body.path]=body.content; return {ok:true,size:body.content.length}; } return {ok:true}; };
  _pages={}; _postsHtmlExists=null; _postListPageIds=null; _site={name:'Acme Epoxy'};
  document.getElementById('ur-find').value='epoxy'; document.getElementById('ur-replace').value='coating'; document.getElementById('ur-case').checked=false; document.getElementById('ur-word').checked=true; document.getElementById('ur-scope').value='text';
  await urlReplaceScan();
  const plan=document.getElementById('ur-results').textContent; const btn=document.getElementById('ur-apply').textContent; const scanned={..._urScan};
  await urlReplaceApply();
  return {plan, btn, rows:scanned.rows, writes, index:files['index.html'], posts:JSON.parse(files['data/posts.json']), site:JSON.parse(files['data/site.json']), css:files['settings.css'], toasts:window.__t};
},HTML);
chk(E.rows && E.rows.map(r=>r.path+':'+r.n).join(',')==='index.html:8,data/posts.json:1,data/site.json:2' && /Replace 11 matches/.test(E.btn) && /text people read only/.test(E.plan), 'E1 Scan lists per-file counts for the text scope (CSS and the no-match page excluded) and the button says how many — got: '+JSON.stringify(E.rows)+' / '+E.btn);
chk(E.writes.filter(w=>w.startsWith('data/bak/')).length===3 && E.writes.includes('index.html') && E.writes.includes('data/posts.json') && E.writes.includes('data/site.json') && !E.writes.includes('settings.css') && !E.writes.includes('about.html'), 'E2 Replace backs up each changed file to data/bak/ then writes it; untouched files are not written — writes: '+E.writes.join(' '));
chk(E.index.includes('>Garage coating</a>') && E.index.includes('href="/epoxy-floors"') && E.posts[0].title==='Coating 101' && E.posts[0].slug==='epoxy-101' && E.site.name==='Acme Coating' && E.site.tagline==='coating pros' && E.css==='.epoxy{color:red}', 'E3 the files on disk show exactly the text-scope result');
chk(E.toasts.some(t=>t.t==='ok'&&/Replaced 11 occurrence\(s\) across 3 file\(s\)/.test(t.m)), 'E4 the summary toast reports what happened — got: '+JSON.stringify(E.toasts));
chk(errs.length===0,'no JS errors during any scenario');
await b.close(); console.log(fail?'\nSUITE FAILED':'\nall find & replace assertions passed'); process.exit(fail?1:0);
