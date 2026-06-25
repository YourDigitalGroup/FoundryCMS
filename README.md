# Fourge CMS Template

This is the base template for all Fourge sites. Every new client site is created from this repo — it contains the CMS, renderer, and blank starter data.

## Quick start for a new site

1. Use **Site Factory → New Repository** inside any existing Fourge CMS to create a new repo from this template
2. Add 4 GitHub Actions secrets to the new repo (Settings → Secrets → Actions):
   - `FTP_SERVER`
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_SERVER_DIR`
3. Push any commit → site deploys automatically

## File structure

```
admin/index.html        CMS admin panel
block-renderer.jsx      Universal page renderer — all block types
preview.html            Live preview for the block editor
posts.jsx               Blog data loader
blog-post.jsx           Individual blog post renderer
interior-shell.jsx      Nav/footer shim for blog post pages
interior.css            Starter site stylesheet
data/site.json          Business info, colors, GitHub config
data/pages.json         All page content as blocks
data/posts.json         Blog posts
data/seo.json           Per-page SEO & AEO data
data/injections.json    Plugin code snippets
index.html              Home page shell
About.html              About page shell
Services.html           Services page shell
Locations.html          Locations page shell
FAQ.html                FAQ page shell
Contact.html            Contact page shell
Blog.html               Blog page shell
.github/workflows/      GitHub Actions deploy workflow
```

## Updating the CMS

To push a CMS update to all future sites, edit this repo. All sites created after the update automatically get the new version. Existing sites need a manual pull.

## First login

Default password: `admin123` — change it in Settings after first login.
