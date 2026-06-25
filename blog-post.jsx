// blog-post.jsx — renders a single blog post page
function BlogPostPage({slug}){
  const [post,setPost]=React.useState(null);
  const [site,setSite]=React.useState({});

  React.useEffect(()=>{
    // Load site info for nav branding
    fetch('data/site.json?t='+Date.now())
      .then(r=>r.json()).then(setSite).catch(()=>{});
    // Find post by slug
    const found=(window.blogPosts||[]).find(p=>p.slug===slug);
    if(found)setPost(found);
  },[slug]);

  const Nav=window.CmsNav||window.InteriorNav||null;
  const Footer=window.CmsFooter||window.InteriorFooter||null;

  if(!post)return(
    <>
      {Nav&&<Nav current="blog"/>}
      <main style={{padding:'80px 5%',textAlign:'center',fontFamily:'Manrope,sans-serif',color:'#5a6b7a'}}>
        <p>Post not found.</p>
        <a href="Blog.html" style={{color:'#45BBEC',fontWeight:700}}>← Back to Blog</a>
      </main>
      {Footer&&<Footer/>}
    </>
  );

  return(
    <>
      {Nav&&<Nav current="blog"/>}
      <main>
        <article style={{maxWidth:760,margin:'0 auto',padding:'48px 5%'}}>
          {post.image&&(
            <img src={post.image} alt={post.imageAlt||post.title}
              style={{width:'100%',aspectRatio:'16/9',objectFit:'cover',marginBottom:32,display:'block'}}/>
          )}
          <div style={{marginBottom:20}}>
            {post.category&&<span style={{fontSize:11,fontWeight:800,letterSpacing:'.08em',textTransform:'uppercase',color:'#45BBEC'}}>{post.category}</span>}
            <h1 style={{fontSize:'clamp(1.5rem,4vw,2.25rem)',fontWeight:800,color:'#052942',lineHeight:1.15,marginTop:6,marginBottom:10}}>{post.title}</h1>
            {post.dek&&<p style={{fontSize:16,color:'#5a6b7a',lineHeight:1.7,marginBottom:14}}>{post.dek}</p>}
            <div style={{display:'flex',gap:16,fontSize:12,color:'#5a6b7a',flexWrap:'wrap'}}>
              {post.author&&<span><strong style={{color:'#052942'}}>{post.author}</strong>{post.authorRole&&' — '+post.authorRole}</span>}
              {post.date&&<span>{post.date}</span>}
              {post.read&&<span>{post.read}</span>}
            </div>
          </div>
          <hr style={{border:'none',borderTop:'1px solid #dde2e8',marginBottom:32}}/>
          <div className="post-body" dangerouslySetInnerHTML={{__html:post.body||''}}/>
          <div style={{marginTop:48,paddingTop:24,borderTop:'1px solid #dde2e8'}}>
            <a href="Blog.html" style={{display:'inline-flex',alignItems:'center',gap:6,color:'#45BBEC',fontWeight:700,fontSize:13,textDecoration:'none'}}>← Back to Blog</a>
          </div>
        </article>
      </main>
      {Footer&&<Footer/>}
    </>
  );
}
