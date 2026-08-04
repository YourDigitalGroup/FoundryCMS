# Migration — Indexing & Canonicalization Layer

**Engine release:** Fourge `1.14.84` (the release the original spec called "1.3.0").
**Applies to:** every site running the shared engine, including the ~16 live client sites.
**Rollout:** automatic. Sites pull the engine from `main` at the next admin login; the
server-side pieces install themselves during that same login.

The whole point of this release is that a site can be *found* — and that the *wrong*
version of a site can't be. Nothing in it changes rendered page content on a site that
takes no action.

---

## 1. What happens with no human involvement

Ordered by when it happens.

### At the next admin login

The existing login self-heal (`install_clean_urls`) now also calls
`fourgeWriteIndexingHtaccess()`. That splices a single marker-delimited block into the
site's `.htaccess`:

```
# BEGIN Fourge Indexing
… rules …
# END Fourge Indexing
```

It is inserted **above** `# BEGIN Fourge Clean URLs`, because the clean-URL catch-all
rewrite would otherwise swallow the redirect. Re-running is byte-identical: the block is
stripped and reinserted, never appended twice. `.htaccess` is not in
`UPDATE_FILES_ALLOWED`, so engine updates can never clobber it.

Three things go in that block:

| Rule | Effect on a live site |
|---|---|
| `Options -MultiViews` (inside `<IfModule mod_rewrite.c>`) | None visible. Stops Apache/LiteSpeed content negotiation from resolving `/page` behind the CMS's back, which is what made `/page`, `/page/`, and `/page.html` all serve 200s. Kept inside the `IfModule` on purpose — if `mod_rewrite` were missing, MultiViews would be the *only* thing serving clean URLs, and turning it off unconditionally would take the site down. |
| `/page/` → `/page` 301 | None on any URL that currently works. The rule is gated on `!-f` and `!-d`, so it only fires for a slashed URL that is neither a real file nor a real directory — i.e. a URL that 404s today. `/admin/` and `/data/` are excluded outright. |
| Non-production `noindex` | None on the configured domain (see below). On a dev/staging host it adds `X-Robots-Tag: noindex, nofollow` and serves a `Disallow: /` robots file. |

Also created if absent: `/_fourge_robots_nonprod.txt` (the alternate robots file the
rewrite serves). It is inert on production.

**The non-production guard is deliberately conservative.** It matches an explicit list of
host *shapes* — `dev.`/`staging.`/`stg.`/`test.`/`qa.`/`uat.`/`preview.`/`beta.`/`demo.`/
`sandbox.` prefixes, `*.fourge.com`, `localhost`, `.local`, a bare IP — and then, as the
last word, unconditionally exempts the site's own configured host and its www/non-www
twin. There is **no** "anything I don't recognise is non-production" catch-all, because a
client with a second live domain pointing at the same site would have been silently
de-indexed by one. If the production host can't be determined (Website URL empty), the
entire guard is omitted rather than guessed at.

The production host is read **only** from `data/site.json` → `website`. Never from the
incoming request, which could be the dev host we're guarding against.

### On the next page save (per page)

`veFinalizePageHtml` already stamped full SEO into pages that have a record in
`seo.json`. Pages with **no** SEO record previously got nothing at all — that is where the
missing canonicals came from. They now get a floor, via `seoMinimalStamp()`:

- `<link rel="canonical" href="…" data-fourge-canon>` — self-referencing, absolute
- `<meta name="robots" content="index,follow" data-fourge-canon>` (`noindex` if the page is a draft)

This is **purely additive**. It never removes or overwrites a title, description, social
tag, or anything else the page already declares, and it skips its own tag entirely if the
page already has a hand-written `canonical` or `robots`. Imported pages with authored
`<head>` markup keep it. The `data-fourge-canon` marker means the next save replaces these
nodes instead of stacking duplicates.

If Website URL is empty, `seoMinimalStamp` emits **nothing**. No guessed host ever reaches
a page.

### On the next page save / delete / post publish (site-wide)

`sitemap.xml` is now refreshed automatically (`seoSitemapRefresh()` — best-effort,
fire-and-forget, can't fail or slow the save). Previously it was only rewritten when
someone clicked Generate in the SEO panel, so most sites' sitemaps were months stale.

---

## 2. What changed for anyone who was relying on old behaviour

**Fourge no longer guesses your domain.** This is the one behavioural regression, and it
is intentional.

Canonical tags, `og:url`, the sitemap, and the `Sitemap:` line in `robots.txt` previously
fell back to `autoSiteBase()` — the origin the admin happened to be open on — when Design
→ Website URL was blank. That fallback is how a staging hostname ends up in hundreds of
live canonical tags, and it is the root cause of the bug class this release exists to
close. All four now come from one helper, `fourgeCanonicalBase()`, which reads Website URL
and nothing else.

Consequence for a site with Website URL blank:

- Canonicals and `og:url` are **omitted** rather than wrong.
- Generating a sitemap **fails with an explicit message** instead of writing wrong URLs.
- Post-Launch Check reports `Canonical URLs — FAIL: Design → Website URL is empty`.

That is a louder failure than before, and a much cheaper one than a de-indexed site.

**Sitemap dates are now honest.** `lastmod` comes from real human edits recorded in
`data/revisions.json`, keyed by clean path, newest wins. File mtime is deliberately *not*
used: a bulk re-stamp touches every file and would mark the whole site "modified today,"
which is exactly the signal Google discounts. A page whose real date is unknown carries
**no** `<lastmod>` at all. Over 45,000 URLs, `/sitemap.xml` becomes a `<sitemapindex>` and
the URLs move into `sitemap-1.xml`, `sitemap-2.xml`, … (protocol limit is 50,000).

**Schema is one entity, not one per page.** The organization node now carries a stable
`@id` of `{base}/#business`; page nodes reference it via `provider` / `isPartOf` /
`publisher` instead of re-declaring the business. Non-home pages also emit a
`BreadcrumbList`. Existing `seo.json` records are untouched — this only changes the JSON-LD
written on the next save. The Organization-type picker dropped the deprecated
`ProfessionalService` (it still appears for any site already set to it, so nobody's saved
value silently changes).

---

## 3. Backfill

There is no CLI. Backfill is two clicks per site, and both are things an operator already
does:

1. **Log in to the site's admin.** Installs the `.htaccess` block and the non-prod robots
   file. Nothing else required.
2. **SEO → Save & Publish.** Re-stamps every page, which is what gives already-deployed
   pages their canonical, and rewrites `sitemap.xml` with real dates.

Order doesn't matter. Both are idempotent — running them repeatedly produces byte-identical
output.

If the site is behind LiteSpeed page cache or a CDN, **purge it after step 2.** There is no
automatic cache-flush hook in this release; a cached copy of a page will keep serving the
old `<head>` until it expires.

---

## 4. Per-site human decisions

These cannot be automated and should be checked once per site.

| Decision | Why a human has to make it |
|---|---|
| **Design → Website URL must be the exact domain you want indexed** — right protocol, and www or non-www to match what's actually canonical for that site. | Fourge will not infer it. Getting www wrong here points every canonical at a host that may 301 elsewhere. This is the single most important field in the release. |
| **Confirm no live alias host looks non-production.** | If a client's real domain is something like `demo.acmeroofing.com`, the shape-match would flag it — but only if it is *not* the configured Website URL. Set Website URL to that host and it is exempted. Read the emitted `.htaccess` block if in doubt. |
| **Decide whether any page should stay out of the index.** | The new floor stamps `index,follow` on pages that had no SEO record. If a page was relying on *having no robots tag plus not being linked* to stay out of Google, mark it noindex in the SEO panel (or keep it a draft) before publishing. |
| **Existing hand-written canonicals win.** | If a page has an authored canonical that points somewhere wrong, this release will not fix it — it deliberately doesn't touch it. Post-Launch Check flags mismatched hosts under "Canonical URLs". |

---

## 5. Verification — from outside the code

Every item, with the concrete check.

**`.htaccess` block installed and idempotent**
```bash
sed -n '/# BEGIN Fourge Indexing/,/# END Fourge Indexing/p' /path/to/public_html/.htaccess
cp .htaccess /tmp/a && # log in to the admin again, then:
diff /tmp/a .htaccess && echo "idempotent"
```
Expect the block to appear once, above `# BEGIN Fourge Clean URLs`, and the diff to be
empty after a second login.

**Trailing-slash 301 fires only where it should**
```bash
curl -sI https://client.com/about/  | head -1   # → 301, Location: /about
curl -sI https://client.com/about   | head -1   # → 200
curl -sI https://client.com/admin/  | head -1   # → 200 (excluded)
```

**Production host is not de-indexed**
```bash
curl -sI https://client.com/ | grep -i x-robots-tag        # → no output
curl -s  https://client.com/robots.txt | head -3           # → the real robots.txt
```

**Dev host is de-indexed** (same server, dev hostname)
```bash
curl -sI https://dev.client.com/ | grep -i x-robots-tag    # → noindex, nofollow
curl -s  https://dev.client.com/robots.txt                 # → Disallow: /
```

**Every page has a canonical**
```bash
for p in "" about services contact; do
  echo -n "/$p → "; curl -s "https://client.com/$p" | grep -o '<link rel="canonical"[^>]*>' | head -1
done
```
Expect a self-referencing absolute URL on each, on the configured host, extensionless, no
trailing slash, home = `https://client.com/`.

**The minimal stamp is additive** — on a page that had no SEO record, diff the file before
and after a save: the only added lines should be the two `data-fourge-canon` tags.
```bash
diff <(git show HEAD:about.html) about.html
```

**Sitemap is current and honest**
```bash
curl -s https://client.com/sitemap.xml | head -20
curl -s https://client.com/sitemap.xml | grep -c '<url>'
curl -s https://client.com/sitemap.xml | grep -c '<lastmod>'
```
`loc` values must match the canonicals exactly (same host, extensionless). The `lastmod`
count being *lower* than the `url` count is correct, not a bug — pages with no recorded
edit date carry no date.

**`robots.txt` points at the sitemap on the right host**
```bash
curl -s https://client.com/robots.txt | grep -i sitemap
```

**Structured data**: paste any page into
[validator.schema.org](https://validator.schema.org/) — expect one organization node with
`@id` ending `#business`, a page node referencing it, and a `BreadcrumbList` on non-home
pages.

**In-CMS**: Settings → **Post-Launch Check**. New sections: *Canonical URLs*, *Headings*,
*Structured data*.

---

## 6. Deliberately not in this release

Each of these is a redirect or a publish gate — the two categories that can take a live
site down — so they belong in an opt-in release with its own review, not in an automatic
self-heal that lands on 16 sites at once.

- **www/HTTPS host canonicalization redirects.** Whether a site should be www or non-www
  cannot be inferred, and a wrong guess 301s an indexed domain into a hostname that may
  not have a certificate. Needs an explicit per-site choice in the UI first.
- **Trailing-slash policy beyond the 404-only fix.** Redirecting slashed URLs that
  *currently work* would change indexed URLs; that needs to be a deliberate decision.
- **Hand-editable redirect map UI.** The importer can write redirects
  (`cmsPkgWriteRedirects`); there is no screen for authoring them yet.
- **A publish-time gate on missing title/description.** Blocking a save is a behaviour
  change for every editor; warn-only first.
- **LSCache / CDN purge hook.** Manual purge for now (§3).
- **A CLI backfill.** Login + Save & Publish covers it; a CLI would need its own auth path.
