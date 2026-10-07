#!/usr/bin/env bash
# Fourge regression runner — `cd tests && npm test`, or `bash tests/run.sh` from anywhere.
#
# What runs (see tests/README.md):
#   1. tests/browser/*.mjs  — headless-browser suites against the REAL admin page
#   2. tests/api/e2e_dispatcher.sh — the real api.php dispatcher end to end (own copy of the site, own SQLite)
#   3. php -l on admin/api.php, a parse of every inline script in admin/index.html,
#      and the three-way version check (version.json = CMS_VERSION = FOURGE_API_VERSION)
# Needs: node 18+, php 8+ with pdo_sqlite, curl, python3, and a Chromium (CHROME_PATH,
# a Playwright browsers folder, or a system Chrome — see tests/lib/harness.mjs).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
PORT="${TEST_PORT:-8931}"; export TURL="${TURL:-http://127.0.0.1:$PORT/admin/index.html}"
CACHE="$HERE/.cache"; mkdir -p "$CACHE"
overall=0; started=""
TO=""; command -v timeout >/dev/null 2>&1 && TO="timeout 300"

# 1. node dependencies (playwright-core)
if [ ! -d "$HERE/node_modules/playwright-core" ]; then
  echo "== installing node dependencies (tests/package.json)"
  (cd "$HERE" && npm install --no-audit --no-fund --loglevel=error) || { echo "FAIL npm install"; exit 1; }
fi

# 2. CodeMirror: the admin loads it from cdnjs; cache the same files once so the browser
#    suites serve them locally (no CDN dependency, no flakiness). Missing files fall back to the CDN.
CM_BASE="$(grep -o 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/[0-9.]*' "$ROOT/admin/index.html" | head -1)"
if [ -n "$CM_BASE" ]; then
  for p in $(grep -o "$CM_BASE/[^\"']*" "$ROOT/admin/index.html" | sed "s#$CM_BASE/##" | sort -u); do
    f="$CACHE/cm/$p"; [ -s "$f" ] && continue; mkdir -p "$(dirname "$f")"
    curl -sS --max-time 30 -o "$f" "$CM_BASE/$p" 2>/dev/null || { rm -f "$f"; echo "warn: could not cache $p (the code editor suite will use the CDN)"; }
  done
fi

# 3. the admin, served by php -S from the repo root. An existing server on the port is reused.
#    PHP_CLI_SERVER_WORKERS: the single-threaded built-in server stalls on Chromium's
#    speculative preconnections; workers keep the real requests flowing.
if ! curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/index.html"; then
  PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$ROOT" > "$CACHE/php_server.log" 2>&1 &
  started=$!
  for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/index.html" && break; sleep 0.25; done
  curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/index.html" || { echo "FAIL could not start php -S on :$PORT (see $CACHE/php_server.log)"; exit 1; }
fi
trap '[ -n "$started" ] && kill $started 2>/dev/null' EXIT

# 4. browser suites
for f in "$HERE"/browser/*.mjs; do
  echo "=== $(basename "$f") ==="
  (cd "$HERE" && $TO node "$f") || overall=1
  echo
done

# 5. the real api.php dispatcher, end to end
bash "$HERE/api/e2e_dispatcher.sh" || overall=1

# 5b. pure PHP suites lifted out of api.php (no dispatcher, no server)
for f in "$HERE"/api/*.php; do echo "=== $(basename "$f") ==="; php "$f" || overall=1; echo; done

# 6. static checks
php -l "$ROOT/admin/api.php" || overall=1
node -e "
const fs=require('fs');const h=fs.readFileSync(process.argv[1],'utf8');
const m=[...h.matchAll(/<script(?![^>]*src=)[^>]*>([\s\S]*?)<\/script>/g)];let bad=0;
for(const x of m){ try{ new Function(x[1]); }catch(e){ bad++; console.log('SYNTAX?',e.message.slice(0,200)); } }
console.log(bad?'FAIL inline scripts':'ok   inline scripts parse ('+m.length+')'); process.exit(bad?1:0);" "$ROOT/admin/index.html" || overall=1

# 7. a release moves THREE numbers together (the login updater and the sign-in api.php check depend on it)
v1=$(grep -o "^const CMS_VERSION='[^']*'" "$ROOT/admin/index.html" | cut -d"'" -f2)
v2=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"];' "$ROOT/admin/version.json")
v3=$(grep -oE "^define\('FOURGE_API_VERSION', '[^']+'\);" "$ROOT/admin/api.php" | sed -E "s/^define\('FOURGE_API_VERSION', '([^']+)'\);/\1/")
if [ -n "$v1" ] && [ "$v1" = "$v2" ] && [ "$v2" = "$v3" ]; then echo "ok   version consistency: $v1 (CMS_VERSION = version.json = FOURGE_API_VERSION)"
else echo "FAIL version consistency: CMS_VERSION=$v1 version.json=$v2 FOURGE_API_VERSION=$v3"; overall=1; fi

echo
[ $overall = 0 ] && echo "ALL REGRESSION CHECKS PASSED" || echo "REGRESSION FAILED"
exit $overall
