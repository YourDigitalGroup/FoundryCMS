// interior-shell.jsx — nav/footer for blog post pages
// Uses the same CmsNav/CmsFooter exported by block-renderer.jsx
// This file exists for backward compatibility with blog-post.jsx
function InteriorNav({current}){
  if(window.CmsNav)return <window.CmsNav current={current||'blog'}/>;
  return null;
}
function InteriorFooter(){
  if(window.CmsFooter)return <window.CmsFooter/>;
  return null;
}
