# Fourge regression suites

Everything the engine's releases are checked against, in one place that survives any one machine.
They run on every pull request that touches `admin/`, `fleet-dashboard/` or `tests/`
(GitHub Actions workflow *Regression suites*) and locally with one command.

```
cd tests && npm test          # or: bash tests/run.sh from anywhere
```

Needs node 18+, php 8+ with `pdo_sqlite`, `curl`, `python3`, and a Chromium. The harness looks for
`CHROME_PATH`, then a Playwright browsers folder (`PLAYWRIGHT_BROWSERS_PATH` or `~/.cache/ms-playwright`,
which `npx playwright install chromium` fills), then a system Chrome. `npm test` installs `playwright-core`
on first run; the CodeMirror files the admin loads from cdnjs are cached once under `tests/.cache/cm` so the
browser suites never depend on the CDN.

## What runs

`run.sh` serves the repo root with `php -S` on port 8931 (reusing a server that already answers there;
`TEST_PORT` / `TURL` override it) and then runs:

| Step | What | Where |
|---|---|---|
| Browser suites | Headless Chromium drives the **real** `admin/index.html`; `apiCall`, `toast` and `ghMirror` are stubbed with an in-memory file store, `data/*.json` answered inline, third-party requests aborted | `browser/*.mjs` |
| Dispatcher end to end | A throwaway copy of the site with its own `config.secret.php` and SQLite DB, served by `php -S` on port 8932 with `display_errors` on; a real login, one request per action, every response must be valid JSON, and the server log must stay free of PHP notices | `api/e2e_dispatcher.sh` |
| Static checks | `php -l admin/api.php`; every inline `<script>` in the admin parses; `version.json` = `CMS_VERSION` = `FOURGE_API_VERSION` | `run.sh` |

### Browser suites

| Suite | Covers |
|---|---|
| `api_version_banner.mjs` | 1.14.136: the sign-in check that notices an older `api.php` (ping → `api_version`), the banner text, the one-click byte-verified install, refusal reasons shown verbatim |
| `code_editor.mjs` | 1.14.137: line-number click / shift-click selection, active line, ⌘/Ctrl+F in-editor search finding off-screen matches, Esc, document-level routing of the shortcut |
| `element_css.mjs` | 1.14.137: Element CSS — every rule that applies to a selected element (inline, `<style>` blocks, `.css` files, @media/state/pseudo variants, editor overrides, engine read-only, inherited), surgical textual edits, file save with backup and drift refusal, bridge overrides, Add-a-rule, Revert. Loads `fixtures/elcss/` through the normal Pages flow |
| `find_replace.mjs` | 1.14.136: site-wide Find & Replace — text vs. everything scope, match case, whole word, case-preserving replacement, JSON identifier skipping, backups, sitemap/robots rebuild |
| `media_dnd.mjs` | 1.14.137: drop files on the Media panel → upload, overlay, type filter, swallowing drops elsewhere, ignoring non-file drags |
| `site_identity.mjs` | 1.14.135: Site Name / Website URL detected from the site (og:site_name → structured data → logo alt → title suffix → domain; canonical → og:url), Design-tab fill, sign-in fill |
| `ui_dialogs.mjs` | 1.14.137: no bare `confirm(`/`prompt(`/`alert(` in the admin or the fleet dashboard; `uiConfirm`/`uiPrompt`/`uiAlert` behaviour incl. Enter/Esc; a converted call site end to end |

Each suite prints one `ok`/`FAIL` line per assertion and exits non-zero on any failure; `run.sh` exits non-zero if anything failed.

## Writing a suite

Import the harness and drive the real functions:

```js
import { openAdmin } from '../lib/harness.mjs';
const { p, chk, done } = await openAdmin();          // page, assertion helper, finisher
await p.evaluate(() => { _site = { name: 'T' }; });   // the admin's own globals (lexical bindings: assign them bare, not window._site)
chk(await p.evaluate(() => typeof someAdminFunction === 'function'), 'the function exists');
await done('my suite');                               // prints the summary and sets the exit code
```

`openAdmin()` reveals the signed-in shell (needed for layout-dependent clicks), stubs `apiCall` with
`window.__files` (read_file / write_file / list_pages via `window.__pagesList`), logs writes to `window.__writes`
and toasts to `window.__toasts`. `launchPage()` gives just the page for suites that stub the API themselves.
Pages the visual editor should open are best served through the normal flow: put the page in `window.__pagesList`,
answer its URL with `p.route(...)`, call `goPanel('p-pages')` and wait for the bridge (`#__fe_ov__` in the iframe).

## Notes

- `tests/` is excluded from the FTP deploy (`.github/workflows/deploy.yml`) and is never part of an engine update
  (the updater installs only the `files` manifest in `admin/version.json`).
- `tests/.cache/` (CodeMirror cache, server logs, the dispatcher's throwaway site) and `tests/node_modules/` are git-ignored.
- The PHP built-in server is single-threaded and stalls on Chromium's speculative preconnections; `run.sh`
  starts it with `PHP_CLI_SERVER_WORKERS=4`. Headless Chromium also never finishes a real request to a second
  local origin, which is why `element_css.mjs` answers its cross-origin stylesheet by request interception.
