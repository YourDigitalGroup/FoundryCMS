# Fourge CMS — Claude Design prompt

Paste this into Claude Design **after** a site is built (run it per page, or on
the whole site) so the output is clean, editable, and CMS-compatible.

```
Refactor this page's HTML to be compatible with our CMS, WITHOUT changing how
the page looks. Keep the exact same design, layout, copy, colors, fonts, and
images — only adjust the markup structure and naming. Apply ALL of the following:

1. STATIC HTML ONLY. Output a plain .html file whose full content is present in
   the HTML source (view-source shows the real content, not an empty shell).
   No React/Vue/JSX, no client-side framework, no <div id="root"> that fills in
   via JavaScript, no build step, no web components or shadow DOM.

2. SEMANTIC LANDMARKS — exactly one of each per page, using the real tags (NOT
   <div class="header">):
   • the site header  -> <header>…</header>
   • the primary nav  -> <nav>…</nav>   (inside the header is fine)
   • the main content -> <main>…</main>
   • the footer       -> <footer>…</footer>
   The <header>, <nav>, and <footer> must be IDENTICAL across every page so they
   can be managed as one shared template.

3. ALL CSS IN ONE PLACE: a single <style> block in <head> (or one linked
   stylesheet). Do not scatter <style> blocks through the <body>, and don't rely
   on external CDN frameworks for layout. Prefer classes over inline style="".

4. NAME EVERY SECTION: break the main content into <section> elements, each with
   a clear id and class describing its purpose, e.g.
   <section id="hero" class="hero">, id="services", id="testimonials",
   id="contact-cta". One logical block per <section>.

5. IMAGES: real <img src="…" alt="…"> tags with descriptive alt text. No text
   baked into images.

6. HEADINGS/TEXT: proper <h1>–<h6> and <p> tags, exactly one <h1> per page.

7. Each page is a complete standalone document (<!DOCTYPE html> … </html>) with
   its own <title> and <meta name="description"> in <head>.

8. FAQ / ACCORDION sections MUST use this exact structure so the CMS can edit
   each question and answer and add/remove items:
   <section id="faqs" class="faqs">
     <div class="faqs-inner">
       <div class="faqs-head">
         <p class="eyebrow-light">FAQs</p>
         <h2 class="h2-light">Frequently Asked Questions</h2>
       </div>
       <div class="faq-item">
         <button type="button" class="faq-q" aria-expanded="false"><span>Your question?</span><span class="faq-sign">+</span></button>
         <div class="faq-answer"><p>Your answer.</p></div>
       </div>
       <!-- repeat one .faq-item per FAQ -->
     </div>
   </section>
   Rules: each FAQ is ONE .faq-item. The question is the FIRST <span> inside
   .faq-q (the .faq-sign span is just the +/− indicator). The answer is a <p>
   inside .faq-answer. Open/close by flipping aria-expanded on the .faq-q button.

9. FILE NAMING: the homepage MUST be named index.html. Every other page MUST use
   a single .html extension (about.html, services.html, contact.html, etc.) —
   never .dc.html, .html.html, or any double/other extension.

Return the complete updated HTML. Do not change the visual result — only the
structure, tags, ids, and class names.
```

## Why these matter for the CMS

- **#1 (static HTML)** — a JS-rendered app can't be edited by the visual editor.
- **#2 (semantic `<header>`/`<footer>`)** — required for "Save header to all pages".
- **#8 (FAQ structure)** — the editor's FAQ panel keys off `.faq-item`, `.faq-q`
  (first `<span>`), and `.faq-answer` to edit/add/remove FAQs.
- **#9 (file naming)** — pages are discovered by the server scan and keyed by
  filename; `index.html` becomes the "home" page and odd extensions break links.
