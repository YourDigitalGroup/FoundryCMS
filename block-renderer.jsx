// block-renderer.jsx
// Renders all block types. Exports: window.renderBlock, window.CmsNav, window.CmsFooter, window.BlockPage

// ─── DATA LOADING (skipped in preview mode) ─────────────────────
let _pages={}, _seo={}, _site={}, _inj=[];

if(!window.__CMS_PREVIEW__){
  (function(){
    function syncGet(url){try{var x=new XMLHttpRequest();x.open('GET',url+'?t='+Date.now(),false);x.send();return x.status===200?JSON.parse(x.responseText):null;}catch{return null;}}
    _pages = syncGet('data/pages.json') || {};
    _seo   = syncGet('data/seo.json')   || {};
    _site  = syncGet('data/site.json')  || {};
    _inj   = syncGet('data/injections.json') || [];
  })();
}

// ─── HELPERS ────────────────────────────────────────────────────
const E = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const {useState,useEffect,useRef} = React;

// ─── NAV ─────────────────────────────────────────────────────────
function CmsNav({current}){
  const [open,setOpen]=useState(false);
  const c=_site.colors||{};
  const primary=c.primary||'#052942', accent=c.accent||'#45BBEC';
  // Nav links come from site.json → nav array. Falls back to pages.json keys.
  const navItems = (_site.nav && _site.nav.length > 0)
    ? _site.nav
    : Object.keys(_pages).map(id=>({id, label:_pages[id]?.title||id, file:_pages[id]?.file||(id==='home'?'index.html':id+'.html')}));
  return(
    <nav style={{background:primary,position:'sticky',top:0,zIndex:100}}>
      <div style={{maxWidth:1200,margin:'0 auto',padding:'0 5%',display:'flex',alignItems:'center',justifyContent:'space-between',height:68}}>
        <a href={navItems[0]?.file||'index.html'} style={{display:'flex',alignItems:'center',gap:10,textDecoration:'none'}}>
          {_site.logo
            ?<img src={_site.logo} alt={_site.name} style={{height:36,display:'block'}}/>
            :<div style={{display:'flex',alignItems:'center',gap:9}}>
              <div style={{width:32,height:32,background:accent,display:'flex',alignItems:'center',justifyContent:'center'}}>
                <svg width="16" height="16" fill="white" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
              </div>
              <span style={{color:'white',fontWeight:800,fontSize:15}}>{_site.name||'Site'}</span>
            </div>
          }
        </a>
        <div style={{display:'flex',alignItems:'center',gap:28}} className="nav-desktop">
          {navItems.map(l=>(
            <a key={l.id} href={l.file} style={{color:current===l.id?accent:'rgba(255,255,255,.7)',fontWeight:current===l.id?700:500,fontSize:13,textDecoration:'none',transition:'color .12s',letterSpacing:'.01em'}}>{l.label}</a>
          ))}
        </div>
        <button onClick={()=>setOpen(!open)} style={{background:'none',border:'none',color:'rgba(255,255,255,.8)',cursor:'pointer',display:'none',padding:4}} className="nav-toggle" aria-label="Menu">
          <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d={open?'M18 6L6 18M6 6l12 12':'M3 12h18M3 6h18M3 18h18'} stroke="currentColor" strokeWidth="2" strokeLinecap="round"/></svg>
        </button>
      </div>
      {open&&(
        <div style={{background:primary,padding:'12px 5% 20px',borderTop:'1px solid rgba(255,255,255,.1)'}}>
          {navItems.map(l=><a key={l.id} href={l.file} style={{display:'block',padding:'10px 0',color:'rgba(255,255,255,.8)',fontWeight:500,fontSize:14,textDecoration:'none',borderBottom:'1px solid rgba(255,255,255,.07)'}}>{l.label}</a>)}
        </div>
      )}
      <style>{`.nav-toggle{display:none!important}@media(max-width:768px){.nav-desktop{display:none!important}.nav-toggle{display:flex!important}}`}</style>
    </nav>
  );
}

// ─── FOOTER ──────────────────────────────────────────────────────
function CmsFooter(){
  const c=_site.colors||{};
  const primary=c.primary||'#052942', accent=c.accent||'#45BBEC';
  const s=_site;const so=s.social||{};const a=s.address||{};
  const socials=[['facebook','M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z'],['linkedin','M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z M4 6a2 2 0 100-4 2 2 0 000 4z'],['instagram','M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'],['youtube','M22.54 6.42a2.78 2.78 0 00-1.95-1.97C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 00-1.95 1.96A29 29 0 001 12a29 29 0 00.46 5.58A2.78 2.78 0 003.41 19.6C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 001.95-1.95A29 29 0 0023 12a29 29 0 00-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z'],['twitter','M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z']];
  return(
    <footer style={{background:primary,color:'rgba(255,255,255,.65)',fontFamily:'Manrope,sans-serif'}}>
      <div style={{maxWidth:1200,margin:'0 auto',padding:'52px 5% 28px',display:'grid',gridTemplateColumns:'1fr 1fr 1fr',gap:40}}>
        <div>
          <div style={{fontWeight:800,fontSize:15,color:'white',marginBottom:10}}>{s.name||'Company'}</div>
          {s.tagline&&<div style={{fontSize:13,lineHeight:1.7,marginBottom:14}}>{s.tagline}</div>}
          <div style={{display:'flex',gap:10,flexWrap:'wrap'}}>
            {socials.map(([net,path])=>so[net]&&(
              <a key={net} href={so[net]} target="_blank" rel="noopener" style={{width:32,height:32,background:'rgba(255,255,255,.1)',display:'flex',alignItems:'center',justifyContent:'center',color:'rgba(255,255,255,.6)',transition:'all .12s',textDecoration:'none'}}>
                <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24"><path d={path}/></svg>
              </a>
            ))}
          </div>
        </div>
        <div>
          <div style={{fontWeight:700,fontSize:11,letterSpacing:'.1em',textTransform:'uppercase',color:'rgba(255,255,255,.35)',marginBottom:12}}>Company</div>
          {[{l:'Home',h:'index.html'},{l:'About',h:'About.html'},{l:'Services',h:'Services.html'},{l:'Locations',h:'Locations.html'},{l:'Blog',h:'Blog.html'},{l:'Contact',h:'Contact.html'}].map(l=>(
            <a key={l.l} href={l.h} style={{display:'block',color:'rgba(255,255,255,.55)',fontSize:13,marginBottom:6,textDecoration:'none',transition:'color .12s'}}>{l.l}</a>
          ))}
        </div>
        <div>
          <div style={{fontWeight:700,fontSize:11,letterSpacing:'.1em',textTransform:'uppercase',color:'rgba(255,255,255,.35)',marginBottom:12}}>Contact</div>
          {s.phone&&<div style={{fontSize:13,marginBottom:6}}><a href={'tel:'+s.phone} style={{color:'rgba(255,255,255,.65)',textDecoration:'none'}}>{s.phone}</a></div>}
          {s.email&&<div style={{fontSize:13,marginBottom:6}}><a href={'mailto:'+s.email} style={{color:'rgba(255,255,255,.65)',textDecoration:'none'}}>{s.email}</a></div>}
          {a.street&&<div style={{fontSize:13,lineHeight:1.7,color:'rgba(255,255,255,.5)'}}>{a.street}<br/>{a.city}{a.city&&a.state?', ':''}{a.state} {a.zip}</div>}
          {s.hours?.weekday&&<div style={{fontSize:12,marginTop:10,color:'rgba(255,255,255,.4)'}}>{s.hours.weekday}</div>}
        </div>
      </div>
      <div style={{borderTop:'1px solid rgba(255,255,255,.08)',padding:'14px 5%',maxWidth:1200,margin:'0 auto',display:'flex',alignItems:'center',justifyContent:'space-between',fontSize:11,gap:12,flexWrap:'wrap'}}>
        <span>© {new Date().getFullYear()} {s.name||'Company'}. All rights reserved.</span>
      </div>
    </footer>
  );
}

// ─── BLOCK RENDERER ──────────────────────────────────────────────
function renderBlock(block){
  if(!block||!block.type)return null;
  const d=block.data||{}, c=_site.colors||{};
  const primary=c.primary||'#052942', accent=c.accent||'#45BBEC', green=c.green||'#80C241';
  const key=block.id||block.type+Math.random();

  if(block.type==='hero') return(
    <section key={key} style={{background:primary,color:'white',padding:'72px 5%',overflow:'hidden'}}>
      <div style={{maxWidth:1200,margin:'0 auto',display:'grid',gridTemplateColumns:d.image?'1fr 1fr':'1fr',gap:48,alignItems:'center',direction:d.imagePosition==='left'?'rtl':'ltr'}}>
        <div style={{direction:'ltr'}}>
          {d.eyebrow&&<div style={{fontSize:11,fontWeight:800,letterSpacing:'.14em',textTransform:'uppercase',color:accent,marginBottom:12}}>{d.eyebrow}</div>}
          <h1 style={{fontSize:'clamp(2rem,5vw,3.5rem)',fontWeight:800,color:'white',lineHeight:1.1,marginBottom:d.heading2?6:20}}>{d.heading1||'Your Headline'}</h1>
          {d.heading2&&<h2 style={{fontSize:'clamp(1.1rem,3vw,1.75rem)',fontWeight:400,color:'rgba(255,255,255,.7)',lineHeight:1.3,marginBottom:20}}>{d.heading2}</h2>}
          {d.body&&<p style={{fontSize:16,lineHeight:1.7,color:'rgba(255,255,255,.75)',marginBottom:28,maxWidth:540}}>{d.body}</p>}
          <div style={{display:'flex',gap:12,flexWrap:'wrap'}}>
            {d.cta1Text&&<a href={d.cta1Href||'#'} style={{background:accent,color:'white',padding:'13px 24px',fontWeight:700,fontSize:13,letterSpacing:'.04em',textTransform:'uppercase',textDecoration:'none',display:'inline-flex',alignItems:'center',gap:6}}>{d.cta1Text}</a>}
            {d.cta2Text&&<a href={d.cta2Href||'#'} style={{background:'transparent',color:'white',border:'2px solid rgba(255,255,255,.4)',padding:'11px 22px',fontWeight:700,fontSize:13,letterSpacing:'.04em',textTransform:'uppercase',textDecoration:'none',display:'inline-flex',alignItems:'center',gap:6}}>{d.cta2Text}</a>}
          </div>
        </div>
        {d.image&&<div style={{direction:'ltr'}}><img src={d.image} alt={d.imageAlt||''} style={{width:'100%',borderRadius:0,display:'block'}}/></div>}
      </div>
    </section>
  );

  if(block.type==='section-header') return(
    <div key={key} style={{padding:'52px 5% 36px',textAlign:d.alignment||'center',maxWidth:1200,margin:'0 auto'}}>
      {d.eyebrow&&<div style={{fontSize:11,fontWeight:800,letterSpacing:'.14em',textTransform:'uppercase',color:accent,marginBottom:10}}>{d.eyebrow}</div>}
      <h2 style={{fontSize:'clamp(1.75rem,4vw,2.75rem)',fontWeight:800,color:primary,lineHeight:1.15,marginBottom:d.subheading?14:0}}>{d.heading||'Section Heading'}</h2>
      {d.subheading&&<p style={{fontSize:17,color:'#5a6b7a',lineHeight:1.6,maxWidth:620,margin:d.alignment!=='left'?'0 auto':0}}>{d.subheading}</p>}
    </div>
  );

  if(block.type==='text-image') return(
    <section key={key} style={{padding:'64px 5%'}}>
      <div style={{maxWidth:1200,margin:'0 auto',display:'grid',gridTemplateColumns:'1fr 1fr',gap:60,alignItems:'center',direction:d.imagePosition==='left'?'rtl':'ltr'}}>
        <div style={{direction:'ltr'}}>
          {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,lineHeight:1.2,marginBottom:16}}>{d.heading}</h2>}
          {d.body&&<p style={{fontSize:15,lineHeight:1.75,color:'#5a6b7a',marginBottom:16}}>{d.body}</p>}
          {d.bullets?.length>0&&<ul style={{listStyle:'none',padding:0,marginBottom:20}}>{d.bullets.map((b,i)=><li key={i} style={{display:'flex',alignItems:'flex-start',gap:10,marginBottom:8,fontSize:14,color:'#5a6b7a'}}><span style={{width:6,height:6,background:accent,borderRadius:'50%',flexShrink:0,marginTop:6}}></span>{b}</li>)}</ul>}
          {d.ctaText&&<a href={d.ctaHref||'#'} style={{display:'inline-flex',alignItems:'center',gap:6,background:primary,color:'white',padding:'11px 20px',fontWeight:700,fontSize:12,letterSpacing:'.05em',textTransform:'uppercase',textDecoration:'none'}}>{d.ctaText}</a>}
        </div>
        {d.image&&<div style={{direction:'ltr'}}><img src={d.image} alt={d.imageAlt||''} style={{width:'100%',display:'block'}}/></div>}
      </div>
    </section>
  );

  if(block.type==='cards-grid'){const cols=parseInt(d.columns)||4;return(
    <section key={key} style={{padding:'52px 5%',background:'#f8fafc'}}>
      <div style={{maxWidth:1200,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:36}}>{d.heading}</h2>}
        <div style={{display:'grid',gridTemplateColumns:`repeat(${cols},1fr)`,gap:16}}>
          {(d.cards||[]).map((card,i)=>(
            <a key={i} href={card.href||'#'} style={{background:'white',border:'1px solid #e5e7eb',padding:'20px 16px',textDecoration:'none',display:'block',transition:'border-color .15s'}}>
              <div style={{width:36,height:36,background:accent,marginBottom:12}}></div>
              <div style={{fontWeight:700,fontSize:14,color:primary,marginBottom:6}}>{card.title}</div>
              {card.desc&&<div style={{fontSize:13,color:'#5a6b7a',lineHeight:1.6}}>{card.desc}</div>}
            </a>
          ))}
        </div>
        {d.viewAllText&&<div style={{textAlign:'center',marginTop:28}}><a href={d.viewAllHref||'#'} style={{color:accent,fontWeight:700,fontSize:13,textDecoration:'none'}}>{d.viewAllText} →</a></div>}
      </div>
    </section>
  );}

  if(block.type==='rich-text') return(
    <section key={key} style={{padding:'48px 5%'}}>
      <div style={{maxWidth:d.fullWidth?'100%':760,margin:'0 auto',fontSize:16,lineHeight:1.8,color:'#2d3748'}} dangerouslySetInnerHTML={{__html:d.content||''}}/>
    </section>
  );

  if(block.type==='stats-row') return(
    <section key={key} style={{background:primary,padding:'52px 5%'}}>
      <div style={{maxWidth:1200,margin:'0 auto',display:'grid',gridTemplateColumns:`repeat(${(d.items||[]).length||4},1fr)`,gap:24,textAlign:'center'}}>
        {(d.items||[]).map((it,i)=>(
          <div key={i}>
            <div style={{fontSize:'clamp(2rem,5vw,3.25rem)',fontWeight:800,color:accent,lineHeight:1,letterSpacing:'-.04em'}}>{it.value}</div>
            <div style={{fontSize:14,fontWeight:700,color:'white',marginTop:6}}>{it.label}</div>
            {it.sub&&<div style={{fontSize:12,color:'rgba(255,255,255,.5)',marginTop:2}}>{it.sub}</div>}
          </div>
        ))}
      </div>
    </section>
  );

  if(block.type==='cta-band'){
    const bgs={navy:primary,cyan:accent,green:green,white:'white'};const bg=bgs[d.background]||primary;
    const isDark=d.background!=='white';
    return(
      <section key={key} style={{background:bg,padding:'64px 5%',textAlign:'center'}}>
        <div style={{maxWidth:720,margin:'0 auto'}}>
          <h2 style={{fontSize:'clamp(1.75rem,4vw,2.75rem)',fontWeight:800,color:isDark?'white':primary,marginBottom:d.body?14:24}}>{d.heading||'Ready?'}</h2>
          {d.body&&<p style={{fontSize:17,lineHeight:1.6,color:isDark?'rgba(255,255,255,.75)':'#5a6b7a',marginBottom:28}}>{d.body}</p>}
          <div style={{display:'flex',gap:12,justifyContent:'center',flexWrap:'wrap'}}>
            {d.cta1Text&&<a href={d.cta1Href||'#'} style={{background:isDark?'white':primary,color:isDark?primary:'white',padding:'13px 26px',fontWeight:700,fontSize:13,letterSpacing:'.05em',textTransform:'uppercase',textDecoration:'none'}}>{d.cta1Text}</a>}
            {d.cta2Text&&<a href={d.cta2Href||'#'} style={{background:'transparent',color:isDark?'rgba(255,255,255,.8)':primary,border:'2px solid currentColor',padding:'11px 24px',fontWeight:700,fontSize:13,letterSpacing:'.05em',textTransform:'uppercase',textDecoration:'none'}}>{d.cta2Text}</a>}
          </div>
        </div>
      </section>
    );
  }

  if(block.type==='faq-accordion'){
    function FAQ(){const [open,setOpen]=useState(null);return(
      <section style={{padding:'52px 5%'}}>
        <div style={{maxWidth:800,margin:'0 auto'}}>
          {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:32}}>{d.heading}</h2>}
          {(d.items||[]).map((it,i)=>(
            <div key={i} style={{borderBottom:'1px solid #e5e7eb'}}>
              <button onClick={()=>setOpen(open===i?null:i)} style={{width:'100%',textAlign:'left',padding:'16px 0',background:'none',border:'none',display:'flex',justifyContent:'space-between',alignItems:'center',cursor:'pointer',fontFamily:'inherit',fontSize:15,fontWeight:700,color:primary,gap:12}}>
                {it.question}
                <span style={{flexShrink:0,fontSize:18,color:accent,transform:open===i?'rotate(45deg)':'none',transition:'transform .2s'}}>+</span>
              </button>
              {open===i&&<div style={{paddingBottom:16,fontSize:14,lineHeight:1.75,color:'#5a6b7a'}}>{it.answer}</div>}
            </div>
          ))}
        </div>
      </section>
    );}
    return <FAQ key={key}/>;
  }

  if(block.type==='testimonials') return(
    <section key={key} style={{background:'#f8fafc',padding:'52px 5%'}}>
      <div style={{maxWidth:1200,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:36}}>{d.heading}</h2>}
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fit,minmax(280px,1fr))',gap:20}}>
          {(d.items||[]).map((t,i)=>(
            <div key={i} style={{background:'white',border:'1px solid #e5e7eb',padding:24}}>
              <div style={{fontSize:32,color:accent,lineHeight:1,marginBottom:10}}>"</div>
              <p style={{fontSize:14,lineHeight:1.8,color:'#5a6b7a',marginBottom:16,fontStyle:'italic'}}>{t.quote}</p>
              <div style={{display:'flex',alignItems:'center',gap:10}}>
                {t.photo&&<img src={t.photo} alt={t.name} style={{width:40,height:40,borderRadius:'50%',objectFit:'cover'}}/>}
                <div><div style={{fontWeight:700,fontSize:13,color:primary}}>{t.name}</div>{t.role&&<div style={{fontSize:12,color:'#9ca3af'}}>{t.role}</div>}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );

  if(block.type==='blog-grid'){const posts=(window.blogPosts||[]).slice(0,d.postCount||4);return(
    <section key={key} style={{padding:'52px 5%'}}>
      <div style={{maxWidth:1200,margin:'0 auto'}}>
        <div style={{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:28,gap:12,flexWrap:'wrap'}}>
          {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary}}>{d.heading}</h2>}
          {d.viewAllText&&<a href={d.viewAllHref||'Blog.html'} style={{color:accent,fontWeight:700,fontSize:13,textDecoration:'none'}}>{d.viewAllText} →</a>}
        </div>
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(260px,1fr))',gap:20}}>
          {posts.map((p,i)=>(
            <a key={i} href={'Blog - '+p.title?.replace(/[:?()]/g,'').trim()+'.html'} style={{textDecoration:'none',display:'block',background:'white',border:'1px solid #e5e7eb',overflow:'hidden'}}>
              {p.image&&<img src={p.image} alt={p.imageAlt||p.title} style={{width:'100%',aspectRatio:'16/9',objectFit:'cover',display:'block'}}/>}
              <div style={{padding:16}}>
                {p.category&&<div style={{fontSize:10,fontWeight:800,letterSpacing:'.1em',textTransform:'uppercase',color:accent,marginBottom:6}}>{p.category}</div>}
                <div style={{fontSize:15,fontWeight:700,color:primary,lineHeight:1.3,marginBottom:6}}>{p.title}</div>
                {p.dek&&<div style={{fontSize:13,color:'#5a6b7a',lineHeight:1.6,marginBottom:10,display:'-webkit-box',WebkitLineClamp:2,WebkitBoxOrient:'vertical',overflow:'hidden'}}>{p.dek}</div>}
                <div style={{fontSize:11,color:'#9ca3af'}}>{p.author}{p.date?' · '+p.date:''}</div>
              </div>
            </a>
          ))}
        </div>
      </div>
    </section>
  );}

  if(block.type==='locations') return(
    <section key={key} style={{padding:'52px 5%',background:'#f8fafc'}}>
      <div style={{maxWidth:1200,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:32}}>{d.heading}</h2>}
        <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill,minmax(280px,1fr))',gap:20}}>
          {(d.items||[]).map((loc,i)=>(
            <div key={i} style={{background:'white',border:'1px solid #e5e7eb',padding:20}}>
              <div style={{fontWeight:800,fontSize:14,color:primary,marginBottom:10}}>{loc.name}</div>
              {loc.address&&<div style={{fontSize:13,color:'#5a6b7a',marginBottom:3}}>{loc.address}</div>}
              {loc.city&&<div style={{fontSize:13,color:'#5a6b7a',marginBottom:8}}>{loc.city}</div>}
              {loc.weekday&&<div style={{fontSize:12,color:'#9ca3af',marginBottom:2}}>Mon–Fri: {loc.weekday}</div>}
              {loc.sat&&<div style={{fontSize:12,color:'#9ca3af',marginBottom:2}}>Sat: {loc.sat}</div>}
              {loc.sun&&<div style={{fontSize:12,color:'#9ca3af',marginBottom:8}}>Sun: {loc.sun}</div>}
              {loc.phone1&&<a href={'tel:'+loc.phone1} style={{display:'block',fontSize:13,fontWeight:700,color:accent,textDecoration:'none',marginBottom:2}}>{loc.phone1}</a>}
              {loc.phone2&&<a href={'tel:'+loc.phone2} style={{display:'block',fontSize:13,fontWeight:700,color:accent,textDecoration:'none'}}>{loc.phone2}</a>}
            </div>
          ))}
        </div>
      </div>
    </section>
  );

  if(block.type==='gallery'){const cols=parseInt(d.columns)||3;return(
    <section key={key} style={{padding:'52px 5%'}}>
      <div style={{maxWidth:1200,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:28}}>{d.heading}</h2>}
        <div style={{display:'grid',gridTemplateColumns:`repeat(${cols},1fr)`,gap:12}}>
          {(d.images||[]).map((img,i)=>(
            <div key={i} style={{overflow:'hidden'}}>
              <img src={img.src} alt={img.caption||''} style={{width:'100%',aspectRatio:'4/3',objectFit:'cover',display:'block'}}/>
              {d.captions&&img.caption&&<div style={{padding:'6px 2px',fontSize:11,color:'#9ca3af'}}>{img.caption}</div>}
            </div>
          ))}
        </div>
      </div>
    </section>
  );}

  if(block.type==='video'){const embedUrl=d.url?.includes('youtube.com')||d.url?.includes('youtu.be')?'https://www.youtube.com/embed/'+(d.url.split('v=')[1]||d.url.split('/').pop()):d.url;return(
    <section key={key} style={{padding:'48px 5%'}}>
      <div style={{maxWidth:d.fullWidth?'100%':860,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:24}}>{d.heading}</h2>}
        <div style={{position:'relative',paddingBottom:d.aspectRatio==='4/3'?'75%':d.aspectRatio==='1/1'?'100%':'56.25%',height:0,overflow:'hidden'}}>
          <iframe src={embedUrl} style={{position:'absolute',top:0,left:0,width:'100%',height:'100%',border:'none'}} allowFullScreen title={d.heading||'Video'}/>
        </div>
      </div>
    </section>
  );}

  if(block.type==='spacer'){const sizes={sm:32,md:64,lg:96,xl:128};const h=sizes[d.size]||64;return(
    <div key={key} style={{height:h,display:'flex',alignItems:'center',padding:'0 5%'}}>
      {d.divider&&<hr style={{width:'100%',border:'none',borderTop:'1px solid #e5e7eb',margin:0}}/>}
    </div>
  );}

  if(block.type==='form') return(
    <section key={key} style={{padding:'52px 5%',background:'#f8fafc'}}>
      <div style={{maxWidth:640,margin:'0 auto'}}>
        {d.heading&&<h2 style={{fontSize:'clamp(1.5rem,3.5vw,2.25rem)',fontWeight:800,color:primary,textAlign:'center',marginBottom:28}}>{d.heading}</h2>}
        <form style={{background:'white',padding:28,border:'1px solid #e5e7eb'}} onSubmit={async e=>{
          e.preventDefault();
          const fd=new FormData(e.target);
          const fields={};
          let hasName='',hasEmail='';
          (d.fields||[]).forEach(f=>{
            const v=fd.get(f.label)||e.target.querySelector(`[placeholder="${f.placeholder||f.label}"]`)?.value||'';
            fields[f.label]=v;
            if(f.type==='email')hasEmail=v;
            if(f.type==='text'&&f.label.toLowerCase().includes('name'))hasName=v;
          });
          const apiUrl=_site.apiUrl||'';
          const apiToken=_site.apiToken||'';
          const notifyEmail=_site.notifyEmail||'';
          const btn=e.target.querySelector('button[type="submit"]');
          if(btn){btn.disabled=true;btn.textContent='Sending…';}
          try{
            if(apiUrl&&apiToken){
              const res=await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','X-Api-Token':apiToken},body:JSON.stringify({action:'send_form',token:apiToken,fields,subject:d.heading||'Form Submission',to:notifyEmail,siteUrl:_site.website||window.location.origin})});
              const result=await res.json();
              if(!result.ok)throw new Error(result.error||'Send failed');
            }
            e.target.reset();
            if(btn){btn.disabled=false;btn.textContent=d.submitText||'Submit';}
            const msg=e.target.parentElement.querySelector('.form-success');
            if(msg)msg.style.display='block';
          }catch(err){
            if(btn){btn.disabled=false;btn.textContent=d.submitText||'Submit';}
            alert('Sorry, there was an error sending your message. Please try again or contact us directly.');
          }
        }}>
          {(d.fields||[]).map((f,i)=>(
            <div key={i} style={{marginBottom:14}}>
              <label style={{display:'block',fontSize:11,fontWeight:800,letterSpacing:'.08em',textTransform:'uppercase',color:'#5a6b7a',marginBottom:5}}>{f.label}{f.required&&' *'}</label>
              {f.type==='textarea'
                ?<textarea required={f.required} placeholder={f.placeholder} rows={4} style={{width:'100%',padding:'9px 11px',border:'1.5px solid #dde2e8',fontFamily:'inherit',fontSize:14,outline:'none',resize:'vertical',boxSizing:'border-box'}}/>
                :<input type={f.type||'text'} required={f.required} placeholder={f.placeholder} style={{width:'100%',padding:'9px 11px',border:'1.5px solid #dde2e8',fontFamily:'inherit',fontSize:14,outline:'none',boxSizing:'border-box'}}/>
              }
            </div>
          ))}
          <div className="form-success" style={{display:'none',padding:'12px',background:'#E8F0E8',color:'#2D4A2D',fontSize:14,marginBottom:8,borderRadius:3}}>{d.successMessage||'Thank you! We'll be in touch soon.'}</div>
          <button type="submit" style={{background:primary,color:'white',border:'none',padding:'12px 24px',fontFamily:'inherit',fontWeight:700,fontSize:13,letterSpacing:'.05em',textTransform:'uppercase',cursor:'pointer',width:'100%',marginTop:8}}>{d.submitText||'Submit'}</button>
        </form>
      </div>
    </section>
  );

  if(block.type==='code') return(
    <section key={key} style={{padding:'32px 5%'}}>
      <div style={{maxWidth:860,margin:'0 auto'}}>
        {d.label&&<div style={{fontSize:12,fontWeight:700,color:'#5a6b7a',marginBottom:8}}>{d.label}</div>}
        <pre style={{background:'#0d1117',color:'#e6edf3',padding:'18px 20px',overflow:'auto',fontSize:13,lineHeight:1.7,fontFamily:"ui-monospace,'SF Mono',Menlo,monospace"}}><code>{d.code}</code></pre>
      </div>
    </section>
  );

  if(block.type==='html'||block.type==='html-embed') return(
    <div key={key} dangerouslySetInnerHTML={{__html:d.html||''}}/>
  );

  return <div key={key} style={{padding:'20px 5%',color:'#9ca3af',fontSize:12}}>Unknown block: {block.type}</div>;
}

// ─── INJECT PLUGINS ──────────────────────────────────────────────
function injectPlugins(pageId){
  if(window.__CMS_PREVIEW__)return;
  (_inj||[]).filter(p=>p.active!==false).sort((a,b)=>(a.priority||50)-(b.priority||50)).forEach(p=>{
    const pages=p.pages||['all'];
    if(!pages.includes('all')&&!pages.includes(pageId))return;
    if(p.device==='desktop'&&window.innerWidth<=768)return;
    if(p.device==='mobile'&&window.innerWidth>768)return;
    if(p.test&&!location.search.includes('preview=1'))return;
    const div=document.createElement('div');div.innerHTML=p.code||'';
    const target=p.loc==='head'?document.head:document.body;
    div.childNodes.forEach(n=>{
      const clone=n.cloneNode(true);
      if(clone.tagName==='SCRIPT'){const s=document.createElement('script');Array.from(clone.attributes).forEach(a=>s.setAttribute(a.name,a.value));s.textContent=clone.textContent;target.appendChild(s);}
      else target.appendChild(clone);
    });
  });
}

// ─── SEO META INJECTION ──────────────────────────────────────────
function injectSEO(pageId){
  if(window.__CMS_PREVIEW__)return;
  const s=_seo[pageId]||{};
  if(s.titleTag)document.title=s.titleTag;
  const setMeta=(name,content,attr='name')=>{if(!content)return;let el=document.querySelector(`meta[${attr}="${name}"]`);if(!el){el=document.createElement('meta');el.setAttribute(attr,name);document.head.appendChild(el);}el.content=content;};
  setMeta('description',s.metaDescription);setMeta('keywords',s.focusKeyword);
  setMeta('og:title',s.ogTitle||s.titleTag,'property');setMeta('og:description',s.ogDescription||s.metaDescription,'property');setMeta('og:image',s.ogImage,'property');
  if(s.canonical){let el=document.querySelector('link[rel="canonical"]');if(!el){el=document.createElement('link');el.rel='canonical';document.head.appendChild(el);}el.href=s.canonical;}
  if(s.schema){const ld=document.createElement('script');ld.type='application/ld+json';const site=_site||{};const a=site.address||{};ld.textContent=JSON.stringify({"@context":"https://schema.org","@type":s.schema,"name":site.name||'','url':site.website||'','telephone":site.phone||"','address':{"@type":"PostalAddress","streetAddress":a.street||'','addressLocality":a.city||"','addressRegion':a.state||'','postalCode":a.zip||"','addressCountry':a.country||'US'}});document.head.appendChild(ld);}
}

// ─── PAGE COMPONENT ──────────────────────────────────────────────
function BlockPage({pageId,current}){
  const page=_pages[pageId]||{blocks:[]};
  useEffect(()=>{
    if(page.css){const el=document.createElement('style');el.id='page-css';el.textContent=page.css;document.head.appendChild(el);return()=>{document.getElementById('page-css')?.remove();};}
  },[pageId]);
  useEffect(()=>{injectSEO(pageId);injectPlugins(pageId);},[pageId]);
  return(
    <>
      <CmsNav current={current||pageId}/>
      <main>{(page.blocks||[]).map(b=>renderBlock(b))}</main>
      <CmsFooter/>
    </>
  );
}

// ─── EXPORTS ─────────────────────────────────────────────────────
window.BlockPage    = BlockPage;
window.renderBlock  = renderBlock;
window.CmsNav       = CmsNav;
window.CmsFooter    = CmsFooter;
