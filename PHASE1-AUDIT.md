# Phase 1 — Indexing & Canonicalization Audit

**Audited:** engine `CMS_VERSION = 1.14.83` (`admin/index.html:2299`).
**Note on version:** the prompt says "currently v1.2.0". That string is the
**API contract version** returned by the `ping` action (`admin/api.php:227`), not
the engine version. The engine is on 1.14.83 and several subsystems in this audit
were built in 1.14.77–1.14.83, i.e. *after* the July 2026 `dev.riverfront` audit
that the prompt cites. Findings below come from reading the current code.

**Method:** every row cites the file and line that actually implements (or should
implement) the behavior. Where the honest answer is "cannot be determined from
source alone", it says so and names the live check that would settle it.

---

## A. URL form and canonicalization

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 1 | Single config value for canonical URL form | **Missing** | `admin/index.html:5917` | There is no config value for URL *form*. The form is **hard-coded** in `cleanUrlPath()`: strip leading slashes, strip `index.html`, strip `.html`. Extensionless, no trailing slash, root = `/`. It is not selectable and not recorded anywhere. The only related config is the site's **origin** (`_site.website` → `normSiteBase()`, `:12262`). Comment at `:5910` states "Clean URLs are always on" — an intentional product decision, but it means an opt-in/opt-out or trailing-slash variant has no home today. |
| 2 | One helper renders public URLs; links + canonical + sitemap all call it | **Partial** | see list | `cleanUrlPath()` **is** the shared path helper and is correctly called by canonical (`:6268`), sitemap pages (`:6329`), sitemap posts (`:6339`), the dashboard SERP preview (`:4528`, `:4311`) and the Post-Launch clean-URL probe (`:6690`). **But the origin is concatenated by hand at every one of those call sites** (`base+'/'+cleanUrlPath(...)`), and four more places build public URLs independently: `admin/index.html:5361` (posts.html single-post canonical, uses `location.origin` + `?p=slug` — a different URL *shape* entirely), `:12284` and `:12298` (preview/open-live), and `admin/api.php:2704` (the package importer's canonical, via its own `cmsPkgNormPath()` re-implementation of the same normalization in PHP). **That is the root-cause class the prompt names — there are already two independent normalizers (JS `cleanUrlPath`, PHP `cmsPkgNormPath`) and ~8 hand-built origin concatenations.** |
| 3 | Every rendered page emits a self-referencing absolute canonical | **Partial** | `:6268`, guard at `:3625` | `seoInjectIntoHtml()` always emits `<link rel="canonical">` when an origin is resolvable — canonical does **not** require a per-page SEO record. And `seoStampAllPages()` (`:6131`) applies it to *every* page unconditionally. **The gap:** the per-save path `veFinalizePageHtml()` (`:3625`) only stamps a page when it already has SEO data **or** a prior `data-fourge-seo` marker. So a brand-new page, saved individually, with no SEO record, ships with **no canonical** until someone runs SEO → Save & Publish. Coverage is therefore "guaranteed after a global publish, not guaranteed per page". |
| 4 | Trailing slash consistent across links, canonicals, sitemap | **Correct (emitted form)** / **Partial (served form)** | `:5917`, `:5923`, api.php `:2056` | All three emitters agree: extensionless, no trailing slash, home = `origin + '/'`. Internal links via `cleanHref()` (`:5923`) produce `/about`; canonical and sitemap produce `https://host/about`. Consistent. **However** the server rules (`api.php:2056`) rewrite only the *no-slash* form: `RewriteCond %{REQUEST_FILENAME}\.html -f` fails for `/about/` (it tests `/about/.html`), so **`/about/` is not served and not redirected — it 404s.** There is no trailing-slash normalization redirect in either direction. |

## B. Sitemap generator

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 5 | Where generation happens / what triggers it | **Partial** | `:6323` build, `:6347` write | Triggered by exactly three paths: SEO **Save & Publish** (`:6154` inside `seoStampAllPages`), the explicit **"Build my sitemap now"** button (`:6360`), and the domain **Find & Replace** tool (`:7053`). **Not triggered by:** an ordinary page save (`veSavePage`, `:12188` — verified, no sitemap call), page delete (`deletePageNow`, `:9163`), page rename, post publish/unpublish, or scheduled-content publishing (`cmsPkgTick`). A deleted page therefore stays in `sitemap.xml` until someone re-publishes SEO → soft-404s in Search Console. The prompt's assumption "Fourge auto-generates a sitemap on publish" is true only for *SEO* publish, not *page* publish. |
| 6 | `<loc>` matches served form exactly | **Partial** | `:6329`, `:12262` | Shape is right: absolute, no `.html`, no trailing slash, built from the shared path helper. **Risk is the host, not the path:** when Design → Website URL is blank, `normSiteBase()` falls back to `autoSiteBase()` (`:12272`), which derives the origin from **whatever host the admin tab was opened on** — so a sitemap generated from a dev/preview or non-www admin session emits that host in every `<loc>`. Post-Launch Check flags the mismatch after the fact (`:6772`), but generation does not refuse. |
| 7 | `<lastmod>` from content-modified time | **Missing / wrong** | `:6343` | `const lastmod = new Date().toISOString().slice(0,10)` — **one build-time date, stamped identically on every URL**, refreshed to "today" on every regeneration. This is precisely the case Google discounts, and it poisons the signal for the whole file. No per-page mtime is consulted even though the server has it (`filemtime`) and `data/revisions.json` records real per-page save times. |
| 8 | `<priority>` / `<changefreq>` absent | **Correct** | `:6344` | Neither is emitted. Nothing to remove. |
| 9 | Drafts / noindex / non-canonical variants excluded | **Correct** | `:6325–6341` | Excludes per-page `noindex` and `draft` (`:6326`), stray `uploads/*.html` (`:6327`), and unpublished posts (`:6336`). One deliberate inclusion worth confirming with you: **TLP pages are included** — `getPageList()` (`:8640`) returns them, and the comment at `:8649` says that is intentional ("SEO, sitemap … keep using getPageList() so TLPs stay real, indexed, published pages"). Non-canonical variants can't exist: one file, one URL. |
| 10 | 50,000-URL / 50 MB guard + sitemap-index fallback | **Missing** | `:6323` | No count check, no byte check, no `<sitemapindex>` path. Largest live site is ~779 URLs, so this is latent, not active. |

## C. robots.txt

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 11 | Generated or static | **Correct (generated)** | `:6364` | `seoWriteRobots()` writes `/robots.txt` from `seoGlobal().robotsTxt`, defaulting to `User-agent: *\nAllow: /`. |
| 12 | Absolute `Sitemap:` line | **Correct, conditional** | `:6366` | Appends `Sitemap: <base>/sitemap.xml` unless the custom text already has a `Sitemap:` line. **Conditional on an origin being resolvable** — same `autoSiteBase()` fallback risk as B6. |
| 13 | Anything blocking render-critical CSS/JS | **Correct** | `:6365` | Default blocks nothing. Custom text is operator-authored; Post-Launch Check hard-fails on `Disallow: /` (`:6720`). |

## D. Non-production hostname guard

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 14 | Hostname-based environment detection | **Missing** | — | **None exists.** The only host logic in the codebase is `fourgeIsLocalRequest()` (`api.php:143`), which detects `localhost`/loopback to exempt dev from the HTTPS requirement. No dev/staging/preview pattern matching anywhere. |
| 15 | Non-prod hosts emit `noindex,nofollow` | **Missing** | `:6271` | `seoInjectIntoHtml()` emits `robots` content purely from the page's own `noindex` flag and draft state. A dev host serves `index,follow` on every page. **This is the July 2026 finding, still unfixed** — and now worse, because the SEO layer actively *stamps* `index,follow` where previously there may have been no tag at all. |
| 16 | Non-prod excluded from sitemap; robots `Disallow: /` | **Missing** | `:6323`, `:6364` | Neither is host-aware. A dev site generates a full sitemap of dev URLs and a permissive robots.txt. |

## E. .htaccess scaffold

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 17 | Generated from template, or hand-written | **Partial (managed blocks, not a template)** | `api.php:2056`, `:2100`, `:2131`, `:2299` | Fourge does not own the file; it **splices marker-delimited blocks** into whatever is there: `# BEGIN Fourge Clean URLs` (`:2056`), `Fourge Posts CORS` (`:2100`), `Fourge SEO Platform API` (`:2131`), `Fourge Package Redirects` / `Fourge Package Headers` (`:2299`+), and the page-protect gate block. Installed by the once-per-session login self-heal, so it survives auto-update — and `.htaccess` is **not** in `UPDATE_FILES_ALLOWED` (`index.html:2310`), so an update can never clobber it. That part is sound. What's absent is a single ordered scaffold; blocks are independent and their relative order is only partly controlled (the SEO-API and redirect writers deliberately splice *above* the clean-URL block; nothing else asserts order). |
| 18 | `Options -MultiViews` | **Missing** | `api.php:2056` | Not emitted anywhere. On a default cPanel/LiteSpeed vhost MultiViews is commonly on, which means the server may resolve `/about` → `about.html` by content negotiation **before** mod_rewrite is consulted. Today that is invisible (the rewrite would do the same thing), but it will silently defeat any trailing-slash or canonical-form enforcement added later. |
| 19 | `/page.html` 301s to the clean URL | **Correct** | `api.php:2062–2065` | Two rules, both keyed on `%{THE_REQUEST}` (the literal request line, so they can't loop on the internal rewrite): `index.html` → `/` `[R=301,L]`, then `(.+?)\.html` → `/%1` `[R=301,L]`. Order is right — both precede the internal rewrite at `:2068`. **Caveat: this is verified by reading the rules, not by observing a response.** LiteSpeed's mod_rewrite emulation and `THE_REQUEST` handling should be confirmed on a live host (curl below). |
| 20 | HTTPS + host (www) canonicalization server-side | **Missing** | — | Fourge writes **neither**. `api.php:206` comments that "the root .htaccess redirects HTTP→HTTPS site-wide", but no Fourge code emits such a rule — grep for `%{HTTPS}` / `HTTP_HOST` in `api.php` returns only unrelated PHP host reads. So HTTPS enforcement is whatever cPanel/AutoSSL happens to have written per site, unmanaged and unverified, and **www vs non-www is not enforced at all** — both hostnames serve 200 on every page, which is a site-wide duplicate-content condition that no canonical tag fully resolves. |
| 21 | Per-site migration redirect map surviving auto-update | **Partial** | `api.php:2299`, `data/redirects.json` | A real mechanism exists as of 1.14.82: rules are stored in `data/redirects.json` (outside the updated tree) and rendered into the `# BEGIN Fourge Package Redirects` block, merged and deduped by source path, with source/target validation. **But** it is written *only* by the SEO-platform package importer — there is no UI to add a migration 301 by hand, and no import path from a CSV/list, which is what a domain migration actually needs. |

## F. Head metadata

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 22 | Title + description required at publish | **Missing (reported, not enforced)** | `:6265–6269`, `:6745` | Nothing blocks a publish. `seoInjectIntoHtml` simply omits `<title>`/description when the record is empty; Post-Launch Check lists offenders after the fact (`:6745`), and SEO → Generate All can fill them. No gate at save time. |
| 23 | Open Graph + Twitter Card, with image fallback | **Correct** | `:6272–6282` | Emits `og:title`, `og:description`, `og:image`, `og:url`, `og:type` (article for post-ish ids) and `twitter:card|title|description|image`. **Image fallback exists**: `s.ogImage || seoGlobal().defaultOg` (`:6272`) — a site-wide default OG image. Same per-save coverage caveat as A3. (This was a July-2026 finding and is now genuinely fixed.) |
| 24 | Single-H1 / heading-hierarchy check | **Partial** | `:5996` | The data is collected — `_seoFacts[pid].h1 = doc.querySelectorAll('h1').length` (`:5996`) — and surfaced in the SEO panel's per-page facts. There is **no rule, warning, or gate** on `h1 !== 1`, and no hierarchy (h2-before-h3) validation. Post-Launch Check does not include it. |

## G. Structured data

| # | Item | Status | Where | Finding |
|---|---|---|---|---|
| 25 | Schema emitted, page-type aware | **Partial** | `:6221` | `seoBuildSchema()` emits a `@graph` with (a) an organization node from the business profile, and (b) a page node whose `@type` is a **manually chosen per-page field** (`s.schemaType`, default `WebPage`), plus `FAQPage` when a page has AEO Q&A data (`:6244`). Against the expected map: **Home** — org entity is emitted but has **no `@id`** (see 26/27); **Service pages** — `Service` is only emitted if a human picks it, and **`BreadcrumbList` is never emitted anywhere**; **Blog posts** — `posts.html` emits **`Article`, not `BlogPosting`** (`:5373`), and blog *pages* converted from posts get whatever `schemaType` they were given; **FAQ** — correct and automatic. No node references another by `@id`, so the org entity is re-declared rather than referenced. |
| 26 | LocalBusiness subtype is current | **Incorrect** | `:6076` | The picker offers `LocalBusiness, Organization, ProfessionalService, HomeAndConstructionBusiness, GeneralContractor, Store, Restaurant` (`:6076`). **`ProfessionalService` is deprecated and is on the list; `MarketingAgency` — the correct type for the agency's own clients — is not offered at all.** Default is `LocalBusiness`. |
| 27 | Nested LocalBusiness includes required `address` | **Partial** | `:6229` | `address` is emitted as a `PostalAddress` **only when `site.address.street` or `.city` is filled** (`:6229`); otherwise a `LocalBusiness`-typed node ships with no address, which fails Google's requirement, with no warning in the UI. Strictly there is no *nested* LocalBusiness (the org node is a top-level `@graph` member), so the nesting form of the bug doesn't apply. Separately: JSON-LD arriving via the deploy-package importer is **passed through unvalidated** (`api.php:2606`) — the platform is trusted to emit valid schema. |

---

## What actually needs building — ordered by indexing impact

1. **Non-production noindex guard (D14–16) — nothing exists.** Highest impact and lowest risk. A dev/preview host today generates `index,follow` on every page, a full sitemap of dev URLs, and a permissive robots.txt. That is an indexable duplicate of the client's site, and the SEO layer is actively stamping the permissive tag. Needs: host-pattern detection (must confirm the real patterns with you — `dev.*`, `staging.*`, `*.fourge.com`?), forced `noindex,nofollow`, sitemap suppression, `Disallow: /` robots, and a visible banner in the admin so nobody mistakes a dev site for broken SEO.
2. **Guarantee canonical coverage per save (A3).** The global publish path is correct; the per-page path can ship a page with no canonical. One-line fix to the `veFinalizePageHtml` guard, but it needs a decision: always stamp every save, or keep hand-written `<head>` markup on never-configured pages untouched (the reason the guard exists — it protects imported pages).
3. **Collapse URL derivation to one helper (A2) before anything else is built on top.** Two normalizers (JS + PHP) and ~8 hand-built origin concatenations. This is the stated root-cause class; item 4 and 6 below both depend on it being fixed first.
4. **Honest `lastmod` (B7).** Build-time date on every URL discredits the whole sitemap. Real per-page timestamps are available (`filemtime`, or `data/revisions.json`). If a real value can't be sourced for an entry, omit the tag for that entry.
5. **Sitemap freshness triggers (B5).** Regenerate on page save/delete/rename, post publish/unpublish, and scheduled publish — or accept staleness explicitly and say so in the UI. Deleted pages currently linger.
6. **`.htaccess` scaffold: `Options -MultiViews`, HTTPS, and host canonicalization (E18, E20).** www vs non-www serving 200 on both is a site-wide duplicate condition. Needs a per-site "preferred host" decision — cannot be inferred safely.
7. **Trailing-slash policy (A4/E19).** Today `/about/` 404s. Decide: 301 slash → no-slash (matches the emitted form), or accept 404. Either way it should be deliberate and enforced.
8. **Schema completeness (G25–27).** Drop `ProfessionalService`, add `MarketingAgency`; stable `@id` on the org entity with pages referencing it instead of re-declaring; `BreadcrumbList` on non-home pages; `BlogPosting` for posts; warn when a `LocalBusiness` type has no address.
9. **Sitemap 50k/50MB guard + index fallback (B10).** Latent at current scale; cheap insurance.
10. **Title/description publish gate and H1 rule (F22, F24).** Data already collected; only the rule and the surfacing are missing.
11. **Hand-editable redirect map UI (E21).** The storage and rendering exist; the human entry point doesn't.

## Flagged: cannot be determined from source alone

- **E19 / E18 — actual server behavior on LiteSpeed.** The rules are correct as written and correctly ordered; whether LiteSpeed's rewrite emulation honors `%{THE_REQUEST}` identically, and whether MultiViews is on for a given vhost, can only be observed live. Checks: `curl -sI https://site/page.html` (expect `301` → `/page`), `curl -sI https://site/page` (expect `200`), `curl -sI https://site/page/` (currently expect `404`).
- **E20 — whether a per-site HTTPS redirect already exists.** cPanel/AutoSSL often writes one. Not visible from this repo; needs a per-site `.htaccess` read.
- **LSCache flush hook.** No cache-invalidation call exists anywhere in the codebase. Whether stale `.htaccess`/page output is actually served after a change is host-dependent; if LSCache is active this will need a purge step, most likely a manual/documented one.
- **The real non-production host patterns.** The prompt cites `dev.riverfront.fourge.com`; the codebase contains no host list to confirm against. Needs your answer before D can be implemented safely — a wrong pattern would `noindex` a production site.

---

**Phase 1 ends here. No code changed, nothing committed.** Awaiting review before Phase 2.
