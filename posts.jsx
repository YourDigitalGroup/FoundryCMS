// posts.jsx — loads blog data synchronously before page renders
// Skipped in CMS preview mode (no data/posts.json at ../data/posts.json from preview.html)
(function(){
  if(window.__CMS_PREVIEW__){window.blogPosts=[];return;}
  try{
    var x=new XMLHttpRequest();
    x.open('GET','data/posts.json?t='+Date.now(),false);
    x.send();
    window.blogPosts=x.status===200?JSON.parse(x.responseText):[];
  }catch(e){window.blogPosts=[];}
})();
