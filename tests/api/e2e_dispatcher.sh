#!/bin/bash
# End-to-end through the REAL api.php dispatcher: a throwaway copy of the site with its
# own config.secret.php + SQLite DB, served by php -S (with worker processes: the canonical-host
# check POSTs to the site itself) with display_errors=On, a real
# login session, one request per action. Every response must be valid JSON.
set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
S="$SRC/tests/.cache/e2e_site"
PORT="${E2E_PORT:-8932}"; BASE=http://127.0.0.1:$PORT/admin/api.php
GHPORT="${E2E_GH_PORT:-8934}"; GHLOG="$SRC/tests/.cache/gh_stub_log.jsonl"; GHSTATE="$SRC/tests/.cache/gh_stub_state.json"; GHMODE="$SRC/tests/.cache/gh_stub_mode"
GHLPORT="${E2E_GHL_PORT:-8935}"; GHLSTATE="$SRC/tests/.cache/ghl_stub_state.json"
ANPORT="${E2E_ANTHROPIC_PORT:-8936}"; ANMODE="$SRC/tests/.cache/anthropic_stub_mode"; ANCOUNT="$SRC/tests/.cache/anthropic_stub_count"
export NO_PROXY="127.0.0.1,localhost${NO_PROXY:+,$NO_PROXY}" no_proxy="127.0.0.1,localhost${no_proxy:+,$no_proxy}"   # the engine's curl must reach the stub directly
command -v python3 >/dev/null 2>&1 || { echo "FAIL python3 is required for the dispatcher suite"; exit 1; }
rm -rf "$S"; mkdir -p "$S"
# a throwaway copy of the site (never the repo itself): no .git, no tests, no scratch folders
tar -C "$SRC" --exclude=.git --exclude=tests --exclude='_test_scratch_*' -cf - . | tar -C "$S" -xf -
cat > "$S/admin/config.secret.php" <<'PHP'
<?php
return [
  'db_secret_key' => 'e2e-0123456789abcdef0123456789abcdef-e2e',
  'db_path'       => __DIR__ . '/fourge-e2e.db',
  'api_token'     => 'e2e-api-token',
  'require_https' => false,
  'onboard_password' => 'E2eOnboard!234',
];
PHP
php -d display_errors=1 -r 'define("PUBLIC_HTML", $argv[1]); require $argv[1]."/admin/db.php"; $pdo=fourgeDb(); fourgeSetPassword($pdo,"admin@44interactive.com","E2ePass!234"); $pdo->exec("UPDATE users SET must_change_password=0, is_architect=1 WHERE username=\"admin@44interactive.com\""); fourgeSetPassword($pdo,"editor44i","E2ePass!234"); $pdo->exec("UPDATE users SET role=\"superadmin\", must_change_password=0, is_architect=0 WHERE username=\"editor44i\""); $u=fourgeGetUser($pdo,"admin@44interactive.com"); echo "user ready: role=",($u["role"]??"?"),"\n";' "$S" || { echo "FAIL could not prepare the test DB"; exit 1; }
php -d display_errors=1 -r 'define("PUBLIC_HTML", $argv[1]); require $argv[1]."/admin/db.php"; $pdo=fourgeDb(); fourgeSetPassword($pdo,"kellar@44interactive.com","E2ePass!234"); $pdo->prepare("UPDATE users SET is_architect=0, role=?, permissions=?, must_change_password=0 WHERE username=?")->execute(["admin","{\"pages\":true}","kellar@44interactive.com"]); $u=fourgeGetUser($pdo,"kellar@44interactive.com"); echo "kellar ready as on an older site: role=",$u["role"]," architect=",$u["is_architect"],"\n";' "$S" || { echo "FAIL could not prepare Kellar"; exit 1; }
dbq(){ php -d display_errors=1 -r 'define("PUBLIC_HTML", $argv[1]); require $argv[1]."/admin/db.php"; $pdo=fourgeDb(); eval($argv[2]);' "$S" "$1"; }
# the stand-in for api.github.com (tests/lib/gh_stub.php) — the engine is pointed at it with FOURGE_GH_API_BASE
mkdir -p "$S.ghroot"; rm -f "$GHLOG" "$GHSTATE"; echo ok > "$GHMODE"
php -S 127.0.0.1:$GHPORT -t "$S.ghroot" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 "$SRC/tests/lib/gh_stub.php" > "$S.ghstub.log" 2>&1 &
GHSRV=$!
# the stand-in for GoHighLevel (tests/lib/ghl_stub.php) — the engine is pointed at it with FOURGE_GHL_API_BASE
mkdir -p "$S.ghlroot"; rm -f "$GHLSTATE"
php -S 127.0.0.1:$GHLPORT -t "$S.ghlroot" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 "$SRC/tests/lib/ghl_stub.php" > "$S.ghlstub.log" 2>&1 &
GHLSRV=$!
# the stand-in for Anthropic's Messages API (tests/lib/anthropic_stub.php) — FOURGE_ANTHROPIC_API_BASE
mkdir -p "$S.anroot"; echo ok > "$ANMODE"; echo 0 > "$ANCOUNT"
php -S 127.0.0.1:$ANPORT -t "$S.anroot" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 "$SRC/tests/lib/anthropic_stub.php" > "$S.anstub.log" 2>&1 &
ANSRV=$!
# opcache.revalidate_freq=0: the built-in server caches compiled PHP (opcache.enable_cli does not cover it), and the
# suite edits config.secret.php mid-run; without it a request within 2 s of an edit still sees the old file.
FOURGE_GH_API_BASE="http://127.0.0.1:$GHPORT" FOURGE_GHL_API_BASE="http://127.0.0.1:$GHLPORT" FOURGE_ANTHROPIC_API_BASE="http://127.0.0.1:$ANPORT" FOURGE_PROBE_ALLOW_LOCAL=1 FOURGE_PROBE_RESOLVE="e2e-site.example:$PORT:127.0.0.1,other-site.example:$GHPORT:127.0.0.1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t "$S" -d opcache.revalidate_freq=0 -d display_errors=1 -d error_reporting=32767 -d log_errors=0 > "$S.server.log" 2>&1 &
SRV=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$GHPORT/user" && break; sleep 0.2; done
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$GHLPORT/__state" && break; sleep 0.2; done
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$ANPORT/" && break; sleep 0.2; done
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/index.html" && break; sleep 0.2; done
pass=0; fail=0
chk(){ if [ "$1" = 0 ]; then echo "ok   $2"; pass=$((pass+1)); else echo "FAIL $2"; fail=$((fail+1)); fi; }
TOK=""
call(){ local name="$1"; local body="$2"; shift 2
  local out; out=$(curl -s -w $'\n__STATUS__%{http_code}' -X POST -H 'Content-Type: application/json' ${TOK:+-H "X-Session-Token: $TOK"} --data "$body" "$@" "$BASE")
  local status="${out##*__STATUS__}"; local resp="${out%$'\n'__STATUS__*}"
  if printf '%s' "$resp" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null; then chk 0 "$name → HTTP $status, valid JSON: $(printf '%s' "$resp" | head -c 110 | tr '\n' ' ')"; LAST="$resp"; return 0
  else chk 1 "$name → HTTP $status, NOT JSON: $(printf '%s' "$resp" | head -c 400 | tr '\n' ' ')"; LAST="$resp"; return 1; fi; }
call "login (bad password → 401 JSON)" '{"action":"login","username":"admin@44interactive.com","password":"wrong"}'
call "login" '{"action":"login","username":"admin@44interactive.com","password":"E2ePass!234"}'
TOK=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))' 2>/dev/null)
[ -n "$TOK" ] && chk 0 "login returned a session token" || chk 1 "login returned a session token"
call "ping" '{"action":"ping"}'
V=$(python3 -c "import json;print(json.load(open('$SRC/admin/version.json'))['version'])")
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d.get('api_version')=='$V' and d.get('version')=='$V', d" && chk 0 "ping reports api_version $V (1.14.136: the sign-in stale-api check reads this)" || chk 1 "ping api_version — got: $LAST"
call "session" '{"action":"session"}'
[ -f "$S/fleet-dashboard/index.html" ] && chk 0 "the site copy carries fleet-dashboard/, as a site built from this repository does" || chk 1 "the e2e copy has no fleet-dashboard/ to remove"
call "install_clean_urls (login self-heal chain: htaccess, blog sync tick…)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("fleetDashboardCopy")=="removed", d' && [ ! -e "$S/fleet-dashboard" ] && chk 0 "the sign-in self-heal removes that Fleet Dashboard copy from the site (fleetDashboardCopy: removed)" || chk 1 "fleet copy removal — got: $(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("fleetDashboardCopy"))' 2>/dev/null) / left: $(ls -A "$S/fleet-dashboard" 2>&1 | tr '\n' ' ')"
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("cacheHeaders") is True, d' && chk 0 "install_clean_urls reports cacheHeaders:true (the managed browser-caching block)" || chk 1 "cacheHeaders — got: $(printf '%s' "$LAST" | head -c 200)"
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("llms") is True, d' && head -1 "$S/llms.txt" | grep -q '^<!-- generated by Fourge' && chk 0 "the sign-in self-heal writes an llms.txt for a site that has none (1.14.152: FOURGE_LLMS_MARK was defined below the dispatcher, so it never did)" || chk 1 "llms.txt at sign-in — got: llms=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("llms"))' 2>/dev/null) / $(head -c 120 "$S/llms.txt" 2>&1)"
HT="$S/.htaccess"
grep -q '^# BEGIN Fourge cache headers' "$HT" && grep -q '^# END Fourge cache headers' "$HT" && [ "$(grep -c '^# BEGIN Fourge cache headers' "$HT")" = 1 ] && chk 0 ".htaccess carries exactly one managed cache block after sign-in" || chk 1 "cache block markers in .htaccess: $(grep -c 'Fourge cache headers' "$HT")"
python3 - "$HT" <<'PY' && chk 0 "the block sets no-cache for html/css/js/json and a day for images/fonts, inside <IfModule mod_headers.c>" || chk 1 "cache block content"
import re,sys; t=open(sys.argv[1]).read(); b=t[t.index('# BEGIN Fourge cache headers'):t.index('# END Fourge cache headers')]
assert '<IfModule mod_headers.c>' in b and '</IfModule>' in b
assert re.search(r'<FilesMatch "\\\.\(html\?\|css\|js\|json\)\$">\s*Header set Cache-Control "no-cache"', b), b
assert re.search(r'<FilesMatch "\\\.\(jpe\?g\|png\|gif\|webp\|svg\|ico\|woff2\?\)\$">\s*Header set Cache-Control "public, max-age=86400"', b), b
PY
M1=$(md5sum "$HT" | cut -d' ' -f1)
call "install_clean_urls again (idempotent)" '{"action":"install_clean_urls"}'
[ "$(md5sum "$HT" | cut -d' ' -f1)" = "$M1" ] && chk 0 "running the self-heal twice leaves .htaccess byte-identical" || chk 1 ".htaccess changed on the second run"
printf '<html><head><title>gate</title></head><body>secret</body></html>' > "$S/e2e-gate.html"
call "set_page_password ON (password gate writes its own .htaccess block)" '{"action":"set_page_password","path":"e2e-gate.html","password":"hunter22"}'
grep -q '^# BEGIN Fourge cache headers' "$HT" && [ "$(grep -c '^# BEGIN Fourge cache headers' "$HT")" = 1 ] && grep -q '_fourge_gate.php?p=e2e-gate.html' "$HT" && chk 0 "password gate ON: its rule is in, the cache block is still there once" || chk 1 "gate ON clobbered the cache block"
call "set_page_password OFF" '{"action":"set_page_password","path":"e2e-gate.html","password":""}'
grep -q '^# BEGIN Fourge cache headers' "$HT" && [ "$(grep -c '^# BEGIN Fourge cache headers' "$HT")" = 1 ] && ! grep -q 'e2e-gate.html' "$HT" && chk 0 "password gate OFF: its rule is gone, the cache block is still there once" || chk 1 "gate OFF clobbered the cache block"
rm -f "$S/e2e-gate.html" "$S/_fourge_gate.php"
printf '<html><head><title>E2E list</title></head><body><div data-fourge-posts></div></body></html>' > "$S/e2e-postlist.html"; printf '<html><head><title>E2E plain</title></head><body>plain</body></html>' > "$S/e2e-plain.html"; rm -f "$S/posts.html"
call "list_pages" '{"action":"list_pages"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("postsHtml") is False; m={p["path"]:p for p in d["pages"]}; assert m["e2e-postlist.html"]["has_post_list"] is True and m["e2e-plain.html"]["has_post_list"] is False' && chk 0 "list_pages reports postsHtml and has_post_list" || chk 1 "list_pages keys — got: $(printf '%s' "$LAST" | head -c 300)"
rm -f "$S/e2e-postlist.html" "$S/e2e-plain.html"
cp -r "$SRC/fleet-dashboard" "$S/fleet-dashboard"; printf '<?php return [];\n' > "$S/fleet-dashboard/config.secret.php"
call "install_clean_urls (a SET-UP Fleet Dashboard lives in this web root)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("fleetDashboardCopy")=="kept", d' && [ -f "$S/fleet-dashboard/index.html" ] && [ -f "$S/fleet-dashboard/config.secret.php" ] && chk 0 "a Fleet Dashboard that has been set up (config.secret.php) is never removed (kept)" || chk 1 "set-up dashboard — got: $(printf '%s' "$LAST" | head -c 200)"
call "list_pages (with that dashboard in the web root)" '{"action":"list_pages"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); p=[x["path"] for x in d["pages"]]; assert not [x for x in p if x.lower().startswith("fleet-dashboard/")], p' && chk 0 "…and is never listed as a page of the site" || chk 1 "list_pages listed the dashboard: $(printf '%s' "$LAST" | head -c 300)"
rm -f "$S/fleet-dashboard/config.secret.php"; printf 'notes' > "$S/fleet-dashboard/notes.txt"
call "install_clean_urls (the folder also holds a file of the site's own)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("fleetDashboardCopy")=="kept", d' && [ -f "$S/fleet-dashboard/notes.txt" ] && [ -f "$S/fleet-dashboard/index.html" ] && chk 0 "a fleet-dashboard folder holding anything beyond the dashboard's own files is left exactly as it is" || chk 1 "foreign file — got: $(printf '%s' "$LAST" | head -c 200)"
rm -rf "$S/fleet-dashboard"
call "list_media" '{"action":"list_media"}'
call "list_users" '{"action":"list_users"}'
call "read_file data/site.json" '{"action":"read_file","path":"data/site.json"}'
SITE=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["content"]))')
call "write_file data/site.json (JSON body → inline GitHub hook)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":$SITE}"
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and "gh" in d and d["gh"].get("reason") in ("no_repo","no_token"), d' && chk 0 "write_file answers ok with a gh verdict (no repo → no_repo/no_token)" || chk 1 "write_file gh verdict — got: $(printf '%s' "$LAST" | head -c 300)"
call "write_file data/site.json seeded with sync bookkeeping (sync off)" '{"action":"write_file","path":"data/site.json","content":"{\"name\":\"E2E\",\"blogSync\":{\"enabled\":false,\"syncedIds\":[\"a\",\"b\"],\"lastResult\":\"seeded\"}}"}'
call "write_file data/site.json from a stale admin copy (no syncedIds)" '{"action":"write_file","path":"data/site.json","content":"{\"name\":\"E2E2\",\"blogSync\":{\"enabled\":false,\"sourceUrl\":\"https://www.44idigital.com\"}}"}'
call "read_file data/site.json (after the stale save)" '{"action":"read_file","path":"data/site.json"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.loads(json.load(sys.stdin)["content"]); bs=d["blogSync"]; assert d["name"]=="E2E2" and bs["sourceUrl"]=="https://www.44idigital.com" and bs["syncedIds"]==["a","b"] and bs["lastResult"]=="seeded", d' && chk 0 "a stale admin save of site.json keeps the server's syncedIds/lastResult while the operator's changes land" || chk 1 "site.json bookkeeping guard — got: $(printf '%s' "$LAST" | head -c 300)"
call "write_file data/site.json (restore the original)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":$SITE}"
out=$(curl -s -w $'\n__STATUS__%{http_code}' -H "X-Session-Token: $TOK" -F action=write_file -F path=data/e2e-test.txt -F 'content_file=@-;filename=content.txt' "$BASE" <<< "hello e2e"); resp="${out%$'\n'__STATUS__*}"; printf '%s' "$resp" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok"), d' 2>/dev/null && chk 0 "write_file multipart (the editor's channel) → $(printf '%s' "$resp" | head -c 100)" || chk 1 "write_file multipart NOT ok/JSON: $(printf '%s' "$resp" | head -c 300)"
out=$(curl -s -w $'\n__STATUS__%{http_code}' -H "X-Session-Token: $TOK" -F action=write_file -F path=data/e2e-test.txt "$BASE"); resp="${out%$'\n'__STATUS__*}"; st="${out##*__STATUS__}"; printf '%s' "$resp" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("reason")=="content_missing", d' 2>/dev/null && [ "$st" = 400 ] && [ "$(cat "$S/data/e2e-test.txt")" = "hello e2e" ] && chk 0 "write_file multipart with NO file part → 400 content_missing, existing file untouched" || chk 1 "multipart without part: HTTP $st $(printf '%s' "$resp" | head -c 200)"
call "write_file via content_b64 (the client's fallback channel)" "{\"action\":\"write_file\",\"path\":\"data/e2e-test.txt\",\"content_b64\":\"$(printf 'via b64' | base64)\",\"content_length\":7}"
[ "$(cat "$S/data/e2e-test.txt")" = "via b64" ] && chk 0 "…and the decoded bytes are what landed on disk" || chk 1 "b64 content on disk: $(cat "$S/data/e2e-test.txt")"
call "delete_file data/e2e-test.txt" '{"action":"delete_file","path":"data/e2e-test.txt"}'
call "gh_mirror (unconfigured → no_repo)" '{"action":"gh_mirror","path":"data/site.json","content":"{}","message":"e2e"}'
call "gh_sync_all (unconfigured)" '{"action":"gh_sync_all","offset":0}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason") in ("no_repo","no_token"), d' && chk 0 "gh_sync_all without a repo/token answers no_repo/no_token" || chk 1 "gh_sync_all unconfigured — got: $(printf '%s' "$LAST" | head -c 200)"

# ── GitHub mirror, end to end against the stub api.github.com ─────────────────────────────
qcall(){ LAST=$(curl -s -X POST -H 'Content-Type: application/json' ${TOK:+-H "X-Session-Token: $TOK"} --data "$1" "$BASE"); }
gh_sync(){ # run gh_sync_all to completion the way the admin does; LAST = the final answer, SYNC_CALLS = batches asked for
  local off=0 run="" nxt; SYNC_CALLS=0
  for i in $(seq 1 80); do
    qcall "{\"action\":\"gh_sync_all\",\"offset\":$off${run:+,\"run\":\"$run\"}}"; SYNC_CALLS=$((SYNC_CALLS+1))
    nxt=$(printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); n=d.get("next"); print("" if not d.get("ok") or n is None else n)' 2>/dev/null) || nxt=""
    run=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("run") or "")' 2>/dev/null)
    [ -z "$nxt" ] && break; off=$nxt
  done; }
ghlog(){ python3 - "$GHLOG" "$@" <<'PY'
import json,sys,os
log=[json.loads(l) for l in open(sys.argv[1])] if os.path.exists(sys.argv[1]) else []
exec(sys.argv[2])
PY
}
echo 401 > "$GHMODE"; : > "$GHLOG"
call "set_secret github_pat (Architect)" '{"action":"set_secret","name":"github_pat","value":"stub-token"}'
call "write_file data/site.json with github.repo while the token is rejected" '{"action":"write_file","path":"data/site.json","content":"{\"name\":\"E2E\",\"github\":{\"repo\":\"stub/site\",\"branch\":\"main\"}}"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); g=d.get("gh") or {}; assert d.get("ok") and g.get("ok") is False and g.get("reason")=="bad_token" and "rejected the saved token" in g.get("error",""), d' && chk 0 "the save lands and its gh verdict says the token was rejected (reason bad_token), not \"could not be reached\"" || chk 1 "write_file gh verdict with a bad token — got: $(printf '%s' "$LAST" | head -c 300)"
: > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="bad_token" and d.get("error","").startswith("GitHub sync did not start: GitHub rejected the saved token (401 Bad credentials)"), d' && chk 0 "gh_sync_all with a rejected token stops at once: reason bad_token, one sentence" || chk 1 "gh_sync_all bad token — got: $(printf '%s' "$LAST" | head -c 300)"
[ "$SYNC_CALLS" = 1 ] && ghlog 'assert len(log)==1 and log[0]["m"]=="GET" and log[0]["p"]=="/repos/stub/site", log' && chk 0 "…after exactly ONE request to GitHub (the repo probe), not one per file" || chk 1 "requests made with a bad token: $(wc -l < "$GHLOG") (batches: $SYNC_CALLS)"
call "gh_set_private (token rejected)" '{"action":"gh_set_private"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="bad_token" and "rejected the saved token" in d.get("error",""), d' && chk 0 "gh_set_private explains the rejected token too" || chk 1 "gh_set_private bad token — got: $(printf '%s' "$LAST" | head -c 300)"
call "gh_test (token rejected)" '{"action":"gh_test"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="bad_token" and d.get("error","").startswith("Token check failed: GitHub rejected the saved token"), d' && chk 0 "gh_test: Token check failed: GitHub rejected the saved token…" || chk 1 "gh_test bad token — got: $(printf '%s' "$LAST" | head -c 300)"
echo ok > "$GHMODE"; : > "$GHLOG"
call "write_file e2e-mirror.html (a save mirrors at once — the repo's first commit)" '{"action":"write_file","path":"e2e-mirror.html","content":"<html><body>mirror me</body></html>"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and (d.get("gh") or {}).get("ok") is True, d' && chk 0 "write_file reports gh.ok:true" || chk 1 "write_file gh — got: $(printf '%s' "$LAST" | head -c 300)"
ghlog 'puts=[l for l in log if l["m"]=="PUT" and l["p"]=="/repos/stub/site/contents/e2e-mirror.html"]; assert len(puts)==1 and puts[0]["msg"]=="Fourge: update e2e-mirror.html [skip ci]", log' && chk 0 "…with ONE commit whose message ends in [skip ci] (the site repo's deploy workflow must not run on mirror commits)" || chk 1 "mirror PUT/message — log: $(head -c 400 "$GHLOG")"
: > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d.get("next") is None and d["done"]==d["total"] and d["failed"]==0 and d["pushed"]>=3 and d["upToDate"]>=1 and d.get("commit"), d' && chk 0 "gh_sync_all pushes the whole site: $(printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["total"],"files,",d["pushed"],"pushed,",d["upToDate"],"already current,",d["skipped"],"skipped, in",'"$SYNC_CALLS"',"batch(es), commit",d["commit"][:7])')" || chk 1 "gh_sync_all — got: $(printf '%s' "$LAST" | head -c 400)"
PUSHED=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("pushed",0))')
ghlog "c=[l for l in log if l['m']=='POST' and l['p'].endswith('/git/commits')]; t=[l for l in log if l['m']=='POST' and l['p'].endswith('/git/trees')]; r=[l for l in log if l['m']=='PATCH' and l['p'].endswith('/git/refs/heads/main')]; b=[l for l in log if l['m']=='POST' and l['p'].endswith('/git/blobs')]; puts=[l for l in log if l['m']=='PUT']; assert len(c)==1 and len(t)==1 and len(r)==1 and len(b)==$PUSHED and not puts, (len(c),len(t),len(r),len(b),len(puts)); assert c[0]['msg'].startswith('Fourge: sync $PUSHED file') and c[0]['msg'].endswith('[skip ci]'), c[0]" && chk 0 "…as ONE commit (one tree, one ref update, $PUSHED blobs, no per-file PUTs): \"Fourge: sync $PUSHED files to GitHub [skip ci]\"" || chk 1 "one-commit shape — log: $(python3 -c "import json,sys,collections; print(collections.Counter((json.loads(l)['m'],json.loads(l)['p'].split('/')[-1]) for l in open('$GHLOG')))")"
python3 - "$GHSTATE" <<'PY' && chk 0 "the repo now holds the site files the policy mirrors (.htaccess, preview.html, block-renderer.jsx, data/site.json, e2e-mirror.html) and none it must not (admin/, users.json, config.secret.php, the DB)" || chk 1 "repo contents after sync"
import json,sys; s=json.load(open(sys.argv[1])); head=s["refs"]["main"]; tree=s["trees"][s["commits"][head]["tree"]]
assert len(s["commits"])==2, len(s["commits"])
for p in (".htaccess","preview.html","block-renderer.jsx","data/site.json","e2e-mirror.html"): assert p in tree, p
for p in tree: assert not p.startswith("admin/") and not p.startswith(".git") and p not in ("data/users.json","config.secret.php") and not p.endswith((".db",".log")), p
PY
: > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["pushed"]==0 and d.get("commit") is None and d["failed"]==0 and d["upToDate"]==d["total"]-d["skipped"], d' && ghlog 'assert not [l for l in log if l["m"] in ("POST","PUT","PATCH")], log' && chk 0 "a second sync finds everything current: 0 pushed, no commit, nothing written to GitHub" || chk 1 "second sync — got: $(printf '%s' "$LAST" | head -c 300)"
call "gh_test" '{"action":"gh_test"}'
printf '%s' "$LAST" | python3 -c 'import json,sys,re; d=json.load(sys.stdin); assert d.get("ok") and d["login"]=="stub-user" and d["repo"]=="stub/site" and d["private"] is True and d["canPush"] is True and re.match(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$", d.get("expires","")), d' && chk 0 "gh_test: login, repo, private, canPush and the token expiry from GitHub's header ($(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin)["expires"])'))" || chk 1 "gh_test — got: $(printf '%s' "$LAST" | head -c 300)"
# a Fleet Dashboard copy that reached the site's repository is removed there too, so a deploy can't bring it back (1.14.150)
cp -r "$SRC/fleet-dashboard" "$S/fleet-dashboard"; gh_sync
python3 -c "import json; s=json.load(open('$GHSTATE')); t=s['trees'][s['commits'][s['refs']['main']]['tree']]; assert all(('fleet-dashboard/'+f) in t for f in ('index.html','api.php','config.secret.example.php','.htaccess','data/.htaccess')), sorted(t)" && chk 0 "(setup) the copy is in the site's repository, as a mirrored site would have it" || chk 1 "setup: fleet-dashboard not mirrored"
: > "$GHLOG"
call "install_clean_urls (an unconfigured Fleet Dashboard copy, also in the repository)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("fleetDashboardCopy")=="removed", d' && [ ! -e "$S/fleet-dashboard" ] && ghlog 'd=[l for l in log if l["m"]=="DELETE"]; assert sorted(l["p"].split("/contents/",1)[1] for l in d)==sorted("fleet-dashboard/"+f for f in ("index.html","api.php","config.secret.example.php",".htaccess","data/.htaccess")) and all(l["msg"].endswith("[skip ci]") for l in d), d' && python3 -c "import json; s=json.load(open('$GHSTATE')); t=s['trees'][s['commits'][s['refs']['main']]['tree']]; assert not [p for p in t if p.startswith('fleet-dashboard/')], sorted(t)" && chk 0 "the copy is removed from the server and from the site's repository (5 deletes, each [skip ci])" || chk 1 "repo prune — got: $(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("fleetDashboardCopy"))' 2>/dev/null) / log: $(head -c 400 "$GHLOG")"
# a Super Admin (no architect flag) runs the sync and the test; the token itself stays Architect-only (1.14.142)
ARCH_TOK="$TOK"
call "login as a Super Admin" '{"action":"login","username":"editor44i","password":"E2ePass!234"}'
TOK=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))' 2>/dev/null)
[ -n "$TOK" ] && chk 0 "the Super Admin session has a token" || chk 1 "Super Admin login — got: $(printf '%s' "$LAST" | head -c 200)"
call "gh_test as a Super Admin" '{"action":"gh_test"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["login"]=="stub-user" and d["repo"]=="stub/site", d' && chk 0 "a Super Admin can test the connection (the server holds the token; only the verdict comes back)" || chk 1 "gh_test as a Super Admin — got: $(printf '%s' "$LAST" | head -c 300)"
: > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d.get("next") is None and d["failed"]==0 and d.get("repo")=="stub/site", d' && chk 0 "a Super Admin can run the sync, and the answer names the repo it synced to ($(printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["repo"],"—",d["pushed"],"pushed,",d["upToDate"],"already current")'))" || chk 1 "gh_sync_all as a Super Admin — got: $(printf '%s' "$LAST" | head -c 300)"
call "gh_set_private as a Super Admin" '{"action":"gh_set_private"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is True, d' && chk 0 "…and keep the repo private" || chk 1 "gh_set_private as a Super Admin — got: $(printf '%s' "$LAST" | head -c 200)"
call "set_secret github_pat as a Super Admin (refused)" '{"action":"set_secret","name":"github_pat","value":"nope"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "error" in d and "access" in d["error"].lower(), d' && chk 0 "…but cannot change the token (Architect only)" || chk 1 "set_secret as a Super Admin — got: $(printf '%s' "$LAST" | head -c 200)"
call "get_secrets as a Super Admin" '{"action":"get_secrets"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "github_pat" not in (d.get("secrets") or {}) and "github_pat" not in (d.get("status") or {}) and "repo_override" not in (d.get("secrets") or {}), d' && chk 0 "…and neither the token nor the repo override ever reaches a Super Admin's browser" || chk 1 "get_secrets as a Super Admin — got: $(printf '%s' "$LAST" | head -c 200)"
TOK="$ARCH_TOK"
printf '<html><body>gate 2</body></html>' > "$S/e2e-gate2.html"; : > "$GHLOG"
call "set_page_password ON (server-side writers mirror: .htaccess + the gate script)" '{"action":"set_page_password","path":"e2e-gate2.html","password":"hunter22"}'
ghlog 'puts={l["p"].split("/contents/",1)[1]:l["msg"] for l in log if l["m"]=="PUT"}; assert ".htaccess" in puts and "_fourge_gate.php" in puts and all(m.endswith("[skip ci]") for m in puts.values()), puts' && chk 0 "the password gate's .htaccess rule and _fourge_gate.php were pushed the moment they were written, each [skip ci]" || chk 1 "gate ON mirror — log: $(head -c 400 "$GHLOG")"
: > "$GHLOG"
call "install_clean_urls (nothing changes → nothing is pushed)" '{"action":"install_clean_urls"}'
ghlog 'assert not [l for l in log if l["m"]=="PUT" and l["p"].endswith("/contents/.htaccess")], log' && chk 0 "an unchanged .htaccess is not pushed again (no GitHub write on a no-op sign-in self-heal)" || chk 1 "install_clean_urls pushed .htaccess although nothing changed"
: > "$GHLOG"
call "set_page_password OFF" '{"action":"set_page_password","path":"e2e-gate2.html","password":""}'
ghlog 'assert [l for l in log if l["m"]=="PUT" and l["p"].endswith("/contents/.htaccess") and l["msg"].endswith("[skip ci]")], log' && chk 0 "turning the gate off pushes the changed .htaccess" || chk 1 "gate OFF mirror — log: $(head -c 400 "$GHLOG")"
rm -f "$S/e2e-gate2.html" "$S/_fourge_gate.php"
call "gh_set_private (the stub repo is already private)" '{"action":"gh_set_private"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d.get("already") is True, d' && chk 0 "gh_set_private: already private" || chk 1 "gh_set_private — got: $(printf '%s' "$LAST" | head -c 200)"
echo ratelimit > "$GHMODE"; : > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="rate_limit" and "rate limit" in d["error"] and "resets at" in d["error"], d' && [ "$SYNC_CALLS" = 1 ] && chk 0 "a used-up rate limit stops the sync at once with the reset time" || chk 1 "rate limit — got: $(printf '%s' "$LAST" | head -c 300)"
echo notfound > "$GHMODE"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="repo" and "404" in d["error"] and "Resource owner" in d["error"], d' && chk 0 "a repo the token cannot see: reason repo, the 404 explained (owner/name, token access, Resource owner)" || chk 1 "not found — got: $(printf '%s' "$LAST" | head -c 300)"
# a brand-new, EMPTY repository (a new client site): the Git Data API refuses trees until the first commit exists,
# so the first file goes through the Contents API and everything else follows in one commit
echo ok > "$GHMODE"; rm -f "$GHSTATE"; : > "$GHLOG"; gh_sync
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d.get("next") is None and d["failed"]==0 and d["pushed"]==d["total"]-d["skipped"] and d.get("commit"), d' && ghlog 'puts=[l for l in log if l["m"]=="PUT"]; c=[l for l in log if l["m"]=="POST" and l["p"].endswith("/git/commits")]; assert len(puts)==1 and len(c)==1 and puts[0]["msg"].endswith("[skip ci]") and c[0]["msg"].endswith("[skip ci]"), (puts,c)' && python3 -c "import json; s=json.load(open('$GHSTATE')); assert len(s['commits'])==2 and 'main' in s['refs'], len(s['commits'])" && chk 0 "an empty repository gets its first file through the Contents API and the rest in ONE commit (2 commits total, both [skip ci]): $(printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["pushed"],"of",d["total"],"pushed")')" || chk 1 "empty-repo sync — got: $(printf '%s' "$LAST" | head -c 300) / log: $(python3 -c "import json,collections; print(collections.Counter((json.loads(l)['m'],json.loads(l)['p'].split('/')[-1]) for l in open('$GHLOG')))")"
# ── One address for the site: parked domains → the Website URL (1.14.144) ─────────────────
call "host_probe (public)" '{"action":"host_probe"}'
INSTALL=$(printf '%s' "$LAST" | python3 -c 'import json,sys,re; d=json.load(sys.stdin); i=d.get("install",""); assert d.get("ok") and re.match(r"^[0-9a-f]{64}$", i), d; print(i)') && chk 0 "host_probe answers this installation's id ($(printf '%s' "$INSTALL" | head -c 12)…)" || chk 1 "host_probe — got: $(printf '%s' "$LAST" | head -c 200)"
canon_site(){ printf '{"name":"E2E","website":"%s"}' "$1" > "$S/data/site.json"; chk 0 "site.json on disk: Website URL = ${1:-(empty)} (written directly, as a site's file holds it — the admin write path is guarded, see below)"; }
canon_site "http://e2e-site.example:$PORT"
call "canonical_host (force: this server answers as itself)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d.get('ok') and d['on'] is True and d['reason']=='verified' and d['host']=='e2e-site.example:$PORT' and d['changed'] is True, d" && chk 0 "the Website URL answered as this installation → on, verified, written" || chk 1 "canonical_host verified — got: $(printf '%s' "$LAST" | head -c 300)"
[ "$(head -1 "$HT")" = "# BEGIN Fourge Canonical Host — managed by Fourge, do not edit by hand" ] && [ "$(grep -c '^# BEGIN Fourge Canonical Host' "$HT")" = 1 ] && grep -q "RewriteRule ^ http://e2e-site.example:$PORT%{REQUEST_URI} \[R=301,L\]" "$HT" && grep -q 'RewriteCond %{HTTP_HOST} !\\.(fourge\\.com|' "$HT" && chk 0 "the block sits on line 1 of .htaccess, once, redirecting to http://e2e-site.example:$PORT and sparing non-production hosts" || chk 1 ".htaccess canonical block: $(head -3 "$HT" | tr '\n' ' ')"
M2=$(md5sum "$HT" | cut -d' ' -f1)
call "canonical_host again (cached verdict)" '{"action":"canonical_host"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is True and d["changed"] is False, d' && [ "$(md5sum "$HT" | cut -d' ' -f1)" = "$M2" ] && chk 0 "a second run changes nothing (byte-identical .htaccess, verdict from the cache)" || chk 1 "canonical_host second run — got: $(printf '%s' "$LAST" | head -c 200)"
printf '<html><body>gate 3</body></html>' > "$S/e2e-gate3.html"
call "set_page_password ON with the canonical block in place" '{"action":"set_page_password","path":"e2e-gate3.html","password":"hunter22"}'
call "set_page_password OFF" '{"action":"set_page_password","path":"e2e-gate3.html","password":""}'
rm -f "$S/e2e-gate3.html" "$S/_fourge_gate.php"
[ "$(head -1 "$HT")" = "# BEGIN Fourge Canonical Host — managed by Fourge, do not edit by hand" ] && [ "$(grep -c '^# BEGIN Fourge Canonical Host' "$HT")" = 1 ] && chk 0 "the password gate on/off leaves the canonical block on top, once" || chk 1 "gate clobbered the canonical block"
call "install_clean_urls (sign-in chain reports it)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); c=d.get("canonicalHost") or {}; assert c.get("on") is True and c.get("reason")=="verified", d' && chk 0 "install_clean_urls reports canonicalHost on/verified" || chk 1 "install_clean_urls canonicalHost — got: $(printf '%s' "$LAST" | head -c 300)"
canon_site "http://other-site.example:$GHPORT"
call "canonical_host (force: another installation answers there)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is False and d["reason"]=="elsewhere" and "another installation" in d["detail"], d' && ! grep -q 'Fourge Canonical Host' "$HT" && chk 0 "a Website URL that answers as a different installation → off, elsewhere, block removed" || chk 1 "canonical_host elsewhere — got: $(printf '%s' "$LAST" | head -c 300)"
canon_site "http://nothing-here.example:1"
call "canonical_host (force: nothing listens there)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is False and d["reason"]=="unreachable", d' && ! grep -q 'Fourge Canonical Host' "$HT" && chk 0 "an address that cannot be reached → off, unreachable, nothing written" || chk 1 "canonical_host unreachable — got: $(printf '%s' "$LAST" | head -c 300)"
canon_site "http://e2e-site.example:$PORT"
call "canonical_host (force: back to this server)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is True and d["reason"]=="verified", d' && grep -q 'Fourge Canonical Host' "$HT" && chk 0 "…and back on once the address answers as this site again" || chk 1 "canonical_host back on — got: $(printf '%s' "$LAST" | head -c 300)"
# 1.14.145: a dev/staging address is never a redirect target, and the Website URL follows the real domain
canon_site "http://dev.e2e-site.example:$PORT"
call "canonical_host (force: a dev link as the Website URL)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is False and d["reason"]=="nonprod_url", d' && ! grep -q 'Fourge Canonical Host' "$HT" && chk 0 "a development address as the Website URL → refused (nonprod_url), block removed, nothing probed" || chk 1 "canonical_host nonprod — got: $(printf '%s' "$LAST" | head -c 300)"
canon_site "https://dev.e2e.fourge.com"
call "install_clean_urls signed in at the site's real address (Host: e2e-site.example)" '{"action":"install_clean_urls"}' -H "Host: e2e-site.example:$PORT"
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); w=d.get('websiteAuto') or {}; c=d.get('canonicalHost') or {}; assert w.get('from')=='https://dev.e2e.fourge.com' and w.get('to')=='http://e2e-site.example:$PORT', d; assert c.get('on') is True and c.get('reason')=='verified' and c.get('host')=='e2e-site.example:$PORT', d" && chk 0 "a sign-in at the real domain replaces a dev-link Website URL with that domain, and the redirect is verified and written in the same call" || chk 1 "websiteAuto — got: $(printf '%s' "$LAST" | head -c 400)"
call "read_file data/site.json (after the auto-fill)" '{"action":"read_file","path":"data/site.json"}'
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.loads(json.load(sys.stdin)['content']); assert d.get('website')=='http://e2e-site.example:$PORT' and d.get('name')=='E2E', d" && chk 0 "site.json carries the new Website URL and keeps everything else" || chk 1 "site.json after auto-fill — got: $(printf '%s' "$LAST" | head -c 300)"
call "install_clean_urls signed in through a parked domain (Host: parked-e2e.example)" '{"action":"install_clean_urls"}' -H "Host: parked-e2e.example:$PORT"
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d.get('websiteAuto') is None and (d.get('canonicalHost') or {}).get('host')=='e2e-site.example:$PORT', d" && chk 0 "a sign-in through another production host leaves a production Website URL alone" || chk 1 "parked sign-in — got: $(printf '%s' "$LAST" | head -c 300)"
call "install_clean_urls signed in at a dev host (Host: dev.e2e-site.example)" '{"action":"install_clean_urls"}' -H "Host: dev.e2e-site.example:$PORT"
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d.get('websiteAuto') is None, d" && chk 0 "a sign-in at a dev host never becomes the Website URL" || chk 1 "dev sign-in — got: $(printf '%s' "$LAST" | head -c 300)"
# 1.14.147: …but that dev link is remembered, and — the site being live at its Website URL — forwards there too
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.load(sys.stdin); c=d.get('canonicalHost') or {}; assert c.get('devHosts')==['dev.e2e-site.example'] and c.get('devLinkRedirect') is True and c.get('on') is True, d" && chk 0 "the sign-in chain remembers dev.e2e-site.example as this site's development link" || chk 1 "devHosts — got: $(printf '%s' "$LAST" | head -c 300)"
grep -q 'RewriteCond %{HTTP_HOST} ^(dev\\.e2e-site\\.example)(:\[0-9\]+)?$ \[NC\]' "$HT" && [ "$(grep -c "RewriteRule ^ http://e2e-site.example:$PORT%{REQUEST_URI} \\[R=301,L\\]" "$HT")" = 2 ] && chk 0 "…and the block now forwards that dev link by name, ahead of the generic rule" || chk 1 "dev-link rule missing: $(grep -n 'dev\\.e2e-site' "$HT" | head -2 | tr '\n' ' ')"
call "read_file data/site.json (devHosts recorded)" '{"action":"read_file","path":"data/site.json"}'
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.loads(json.load(sys.stdin)['content']); assert d.get('devHosts')==['dev.e2e-site.example'] and d.get('website')=='http://e2e-site.example:$PORT', d" && chk 0 "site.json lists the development link and keeps the Website URL" || chk 1 "site.json devHosts — got: $(printf '%s' "$LAST" | head -c 300)"
printf '{"name":"E2E","website":"http://e2e-site.example:%s","devHosts":["dev.e2e-site.example"],"devLinkRedirect":false}' "$PORT" > "$S/data/site.json"
call "canonical_host (force: the dev-link switch turned off)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is True and d["devLinkRedirect"] is False and d["devHosts"]==["dev.e2e-site.example"], d' && ! grep -q 'dev\\.e2e-site' "$HT" && [ "$(grep -c 'RewriteRule ^ http://e2e-site.example' "$HT")" = 1 ] && chk 0 "with the switch off the dev link is left alone: one generic rule, the link not named" || chk 1 "switch off — got: $(printf '%s' "$LAST" | head -c 300) / $(grep -c 'RewriteRule' "$HT") rules"
call "write_file data/site.json from a stale admin copy (Website URL = the dev link)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":\"{\\\"name\\\":\\\"E2E\\\",\\\"website\\\":\\\"https://dev.e2e.fourge.com\\\"}\"}"
call "read_file data/site.json (a stale admin copy tried to put the dev link back)" '{"action":"read_file","path":"data/site.json"}'
printf '%s' "$LAST" | python3 -c "import json,sys; d=json.loads(json.load(sys.stdin)['content']); assert d.get('website')=='http://e2e-site.example:$PORT', d" && chk 0 "a save carrying a dev link cannot move a production Website URL backwards" || chk 1 "write guard — got: $(printf '%s' "$LAST" | head -c 300)"
canon_site ""
call "canonical_host (force: Website URL cleared)" '{"action":"canonical_host","force":true}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") and d["on"] is False and d["reason"]=="no_website", d' && ! grep -q 'Fourge Canonical Host' "$HT" && chk 0 "clearing the Website URL takes the block out" || chk 1 "canonical_host no_website — got: $(printf '%s' "$LAST" | head -c 300)"
call "write_file data/site.json (restore the original)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":$SITE}"
rm -f "$S/e2e-mirror.html"
call "blog_sync_admin status" '{"action":"blog_sync_admin","op":"status"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("subscribers")==0 and d.get("subscriberSites")==[], d' && chk 0 "blog_sync_admin status reports subscribers:0 on a fresh site" || chk 1 "status subscribers — got: $(printf '%s' "$LAST" | head -c 200)"
call "blog_sync_admin notify (no partners)" '{"action":"blog_sync_admin","op":"notify"}'
call "blog_sync_tick" '{"action":"blog_sync_tick"}'
call "recaptcha_status" '{"action":"recaptcha_status"}'
call "seo_pkg_admin status" '{"action":"seo_pkg_admin","op":"status"}'
# ── Architect accounts: Scott and Kellar are Architects on every site (1.14.148) ────────────────
ARCH_TOK3="$TOK"
call "login as kellar@44interactive.com (a plain admin here, as on a site seeded before he was an Architect)" '{"action":"login","username":"kellar@44interactive.com","password":"E2ePass!234"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); u=d["user"]; assert u["architect"] is True and u["role"]=="superadmin" and u["permissions"]=={}, u' && chk 0 "Kellar signs in as an Architect and Super Admin with every module: restored before his first sign-in on the new engine" || chk 1 "Kellar promotion — got: $(printf '%s' "$LAST" | head -c 300)"
KTOK=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))' 2>/dev/null)
KID=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin)["user"]["id"])' 2>/dev/null)
TOK="$KTOK"
call "get_secrets as Kellar" '{"action":"get_secrets"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); s=d.get("status") or {}; assert d.get("level")==4 and "github_pat" in s and "repo_override" in s and "claude_key" in s, d' && chk 0 "…with Architect access to every secret: the GitHub token, repository override and AI key settings are his" || chk 1 "get_secrets as Kellar — got: $(printf '%s' "$LAST" | head -c 300)"
call "list_users as Kellar" '{"action":"list_users"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); n=[u["username"] for u in d["users"]]; assert "scott@44interactive.com" in n and "admin@44interactive.com" in n, n' && chk 0 "…and sees the other Architect accounts, Scott included" || chk 1 "list_users as Kellar — got: $(printf '%s' "$LAST" | head -c 300)"
call "login as the Super Admin" '{"action":"login","username":"editor44i","password":"E2ePass!234"}'
TOK=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))' 2>/dev/null)
call "list_users as a Super Admin" '{"action":"list_users"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); n=[u["username"] for u in d["users"]]; assert "kellar@44interactive.com" not in n and "scott@44interactive.com" not in n, n' && chk 0 "Architect accounts stay invisible to a Super Admin, Kellar's now as Scott's" || chk 1 "list_users as a Super Admin — got: $(printf '%s' "$LAST" | head -c 300)"
call "save_user demoting Kellar as a Super Admin" "{\"action\":\"save_user\",\"id\":$KID,\"username\":\"kellar@44interactive.com\",\"email\":\"kellar@44interactive.com\",\"role\":\"editor\"}"
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("error")=="Only an Architect can modify an Architect account", d' && chk 0 "a Super Admin cannot change Kellar's account" || chk 1 "edit Kellar as a Super Admin — got: $(printf '%s' "$LAST" | head -c 300)"
call "delete_user Kellar as a Super Admin" '{"action":"delete_user","username":"kellar@44interactive.com"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("error")=="An Architect account cannot be deleted", d' && chk 0 "…or delete it" || chk 1 "delete Kellar as a Super Admin — got: $(printf '%s' "$LAST" | head -c 300)"
call "save_user creating an account with an Architect address as a Super Admin" '{"action":"save_user","username":"scott2","email":"scott@44interactive.com","role":"editor","password":"Whatever!234"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "belongs to a Fourge Architect account" in d.get("error",""), d' && chk 0 "a Super Admin cannot create an account with an Architect address (it would be promoted)" || chk 1 "reserved create — got: $(printf '%s' "$LAST" | head -c 300)"
call "save_user creating a plain editor as a Super Admin" '{"action":"save_user","username":"e2e-plain@example.com","email":"e2e-plain@example.com","role":"editor","password":"Whatever!234"}'
call "list_users (find the plain editor)" '{"action":"list_users"}'
PID=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print([u["id"] for u in json.load(sys.stdin)["users"] if u["username"]=="e2e-plain@example.com"][0])' 2>/dev/null)
call "save_user moving that editor onto Kellar's address as a Super Admin" "{\"action\":\"save_user\",\"id\":$PID,\"username\":\"e2e-plain@example.com\",\"email\":\"kellar@44interactive.com\",\"role\":\"editor\"}"
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "belongs to a Fourge Architect account" in d.get("error",""), d' && chk 0 "…nor move an existing account onto one" || chk 1 "reserved edit — got: $(printf '%s' "$LAST" | head -c 300)"
TOK="$ARCH_TOK3"
call "save_user demoting Kellar to editor as an Architect" "{\"action\":\"save_user\",\"id\":$KID,\"username\":\"kellar@44interactive.com\",\"email\":\"kellar@44interactive.com\",\"role\":\"editor\",\"permissions\":{\"pages\":true}}"
call "list_users as an Architect" '{"action":"list_users"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); k=[u for u in d["users"] if u["username"]=="kellar@44interactive.com"][0]; assert k["architect"] is True and k["role"]=="superadmin" and k["permissions"]=={}, k' && chk 0 "a demotion does not stick, even by an Architect: Kellar is back to Architect and Super Admin at once" || chk 1 "demotion self-heal — got: $(printf '%s' "$LAST" | head -c 300)"
call "delete_user Kellar as an Architect" '{"action":"delete_user","username":"kellar@44interactive.com"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("error")=="An Architect account cannot be deleted", d' && chk 0 "no one can delete an Architect account" || chk 1 "delete Kellar as an Architect — got: $(printf '%s' "$LAST" | head -c 300)"
dbq '$pdo->prepare("DELETE FROM sessions WHERE username=?")->execute(["kellar@44interactive.com"]); $pdo->prepare("DELETE FROM users WHERE username=?")->execute(["kellar@44interactive.com"]);'
TOK=""
call "sign in as Kellar with the team onboarding password on a site without his account" '{"action":"login","username":"kellar@44interactive.com","password":"E2eOnboard!234"}'
[ "$(dbq '$s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE lower(username)=? OR lower(email)=?"); $s->execute(["kellar@44interactive.com","kellar@44interactive.com"]); echo $s->fetchColumn();')" = 0 ] && printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("error")=="Invalid login or password", d' && chk 0 "the team onboarding password never creates an Architect account" || chk 1 "onboarding an Architect address — got: $(printf '%s' "$LAST" | head -c 300)"
call "sign in as new.hire@44interactive.com with the team onboarding password" '{"action":"login","username":"new.hire@44interactive.com","password":"E2eOnboard!234"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); u=d["user"]; assert u["role"]=="editor" and u["architect"] is False, u' && chk 0 "…while it still onboards an ordinary team address as an editor" || chk 1 "onboarding — got: $(printf '%s' "$LAST" | head -c 300)"
TOK="$ARCH_TOK3"
call "save_user creating Kellar's account as an Architect, on a site that lacks it" '{"action":"save_user","username":"kellar@44interactive.com","email":"kellar@44interactive.com","firstName":"Kellar","role":"editor","password":"E2ePass!234"}'
TOK=""
call "login as the newly added Kellar" '{"action":"login","username":"kellar@44interactive.com","password":"E2ePass!234"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); u=d["user"]; assert u["architect"] is True and u["role"]=="superadmin", u' && chk 0 "an Architect can add the account where a site lacks it, and it is an Architect account from the start" || chk 1 "Architect-created Kellar — got: $(printf '%s' "$LAST" | head -c 300)"
TOK="$ARCH_TOK3"

# ── Website chat: Fourge Chat, end to end against a stub GoHighLevel (1.14.149) ─────────────────
ghl(){ local b="${2:-}"; [ -z "$b" ] && b='{}'; curl -s -X POST -H 'Content-Type: application/json' --data "$b" "http://127.0.0.1:$GHLPORT$1" >/dev/null; }
ghlq(){ curl -s "http://127.0.0.1:$GHLPORT/__state" | python3 -c 'import json,sys; s=json.load(sys.stdin); exec(sys.argv[1])' "$1"; }    # an assertion over the stub's state (s)
ans(){ printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); exec(sys.argv[1])' "$1"; }                       # an assertion over the last answer (d)
wjson(){ call "$1" "$(python3 -c 'import json,sys; print(json.dumps({"action":"write_file","path":sys.argv[1],"content":sys.argv[2]}))' "$2" "$3")"; }
chatjson(){ python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); exec(sys.argv[2])' "$S/data/chat.json" "$1"; }            # an assertion over data/chat.json (d)
TOK="$ARCH_TOK3"; ghl /__reset
wjson "write data/site.json (CRM Location ID; form leads off)" data/site.json '{"name":"E2E Dental","phone":"555-0100","ghl":{"enabled":false,"locationId":"loc_e2e"}}'
call "chat_bot_status before a CRM token is saved" '{"action":"chat_bot_status"}'
ans 'assert d.get("ok") is False and d.get("needsConnection") is True and "Plugins" in d["error"], d' && chk 0 "chat_bot_status without a CRM token: needsConnection, pointing to Plugins → Lead Generation" || chk 1 "no token — got: $(printf '%s' "$LAST" | head -c 200)"
call "set_secret ghl_token" '{"action":"set_secret","name":"ghl_token","value":"pit-e2e"}'
TOK=""
call "chat_start while Fourge Chat is off (public)" '{"action":"chat_start","name":"Pat","email":"pat@example.com"}'
ans 'assert d.get("ok") is False and "not available" in d.get("error",""), d' && ghlq 'assert not s["log"], s["log"]' && chk 0 "chat_start answers \"not available\" while data/chat.json doesn't switch the chat on, and the CRM is never called" || chk 1 "chat off — got: $(printf '%s' "$LAST" | head -c 200)"
TOK="$ARCH_TOK3"
wjson "write data/chat.json (Fourge Chat on)" data/chat.json '{"enabled":true,"title":"Chat with E2E Dental"}'
call "chat_bot_status (no bots yet)" '{"action":"chat_bot_status"}'
ans 'assert d.get("ok") and d["bot"] is None and d["main"] is None and d["count"]==0, d' && ghlq 'l=[x for x in s["log"] if x["p"]=="/conversation-ai/agents/search"]; assert len(l)==1 and l[0]["v"]=="2021-04-15", l' && chk 0 "chat_bot_status: no bot yet (asked with Version 2021-04-15)" || chk 1 "status, no bots — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_bot_save create without instructions" '{"action":"chat_bot_save","op":"create","name":"E2E Assistant"}'
ans 'assert d.get("ok") is False and "Fill in" in d.get("error",""), d' && chk 0 "creating the bot needs its name, personality, goal and instructions" || chk 1 "create, missing — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_bot_save create" '{"action":"chat_bot_save","op":"create","name":"E2E Assistant","businessName":"E2E Dental","personality":"Warm","goal":"Help visitors","instructions":"Keep it short."}'
ans 'b=d["bot"]; assert d.get("ok") and d["done"]=="created" and b["isPrimary"] is True and b["channels"]==["Live_Chat"] and b["mode"]=="auto-pilot", d' && ghlq 'a=list(s["agents"].values()); assert len(a)==1 and a[0]["isPrimary"] is True and a[0]["channels"]==["Live_Chat"] and a[0]["mode"]=="auto-pilot" and a[0]["waitTimeUnit"]=="seconds" and a[0]["instructions"]=="Keep it short.", a' && chk 0 "chat_bot_save create: a new main bot on Live Chat that answers on its own, within seconds" || chk 1 "create — got: $(printf '%s' "$LAST" | head -c 300)"
AGENT=$(curl -s "http://127.0.0.1:$GHLPORT/__state" | python3 -c 'import json,sys; print(list(json.load(sys.stdin)["agents"])[0])')
chatjson "assert d.get('agentId')=='$AGENT' and d.get('enabled') is True and d.get('title')=='Chat with E2E Dental', d" && chk 0 "data/chat.json records the bot ($AGENT) and keeps the admin's own settings" || chk 1 "chat.json after create: $(cat "$S/data/chat.json" | head -c 200)"
call "chat_bot_status (the bot answers website chat)" '{"action":"chat_bot_status"}'
ans "assert d.get('ok') and d['bot']['id']=='$AGENT' and d['bot']['instructions']=='Keep it short.' and d['bot']['usesFullPrompt'] is False, d" && chk 0 "chat_bot_status shows the bot with its prompt for editing" || chk 1 "status with bot — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_bot_save create again" '{"action":"chat_bot_save","op":"create","name":"X","personality":"p","goal":"g","instructions":"i"}'
ans 'assert d.get("ok") is False and "already has a main bot" in d.get("error",""), d' && chk 0 "a second main bot is never created" || chk 1 "second create — got: $(printf '%s' "$LAST" | head -c 200)"
# the visitor's side
TOK=""
call "chat_start with a bad email (public)" '{"action":"chat_start","name":"Pat Visitor","email":"not-an-email"}'
ans 'assert d.get("ok") is False and d.get("field")=="email", d' && chk 0 "chat_start points at the field to fix (email)" || chk 1 "bad email — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_start with the honeypot filled (public)" '{"action":"chat_start","name":"Bot","email":"bot@example.com","website":"http://spam.example"}'
ans 'assert d.get("ok") is False, d' && ghlq 'cs=s["contacts"]; cs=list(cs.values()) if isinstance(cs,dict) else cs; assert not any(c.get("email")=="bot@example.com" for c in cs), cs' && chk 0 "a filled honeypot starts nothing and creates no contact" || chk 1 "honeypot — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_start (public)" '{"action":"chat_start","name":"Pat Visitor","email":"Pat@Example.com","phone":"(555) 010-2000"}'
SID=$(printf '%s' "$LAST" | python3 -c 'import json,sys,re; d=json.load(sys.stdin); s=d.get("sid",""); assert d.get("ok") and re.match(r"^[0-9a-f]{32}$",s), d; print(s)') && chk 0 "chat_start hands out a random 32-hex chat id" || chk 1 "chat_start — got: $(printf '%s' "$LAST" | head -c 200)"
ghlq 'c=[x for x in s["contacts"].values() if x.get("email")=="pat@example.com"]; assert len(c)==1 and c[0]["firstName"]=="Pat" and c[0]["lastName"]=="Visitor" and c[0]["source"]=="Website chat" and c[0]["phone"]=="(555) 010-2000" and c[0]["tags"]==["website chat"] and c[0]["locationId"]=="loc_e2e", c; assert len(s["conversations"])==1' && chk 0 "…and the CRM has the visitor as a contact (source Website chat, tagged \"website chat\") with one conversation" || chk 1 "contact/conversation in the stub"
[ "$(dbq '$r=$pdo->query("SELECT * FROM chat_sessions")->fetchAll(); echo (count($r)===1 && strlen($r[0]["sid_hash"])===64 && $r[0]["hidden"]==="[]") ? "ok" : json_encode($r);')" = ok ] && ! grep -qs "$SID" "$S/admin/fourge-e2e.db" "$S/admin/fourge-e2e.db-wal" && chk 0 "the chat id is kept only as a hash (and the new conversation had nothing to hide)" || chk 1 "chat_sessions row: $(dbq 'echo json_encode($pdo->query("SELECT * FROM chat_sessions")->fetchAll());' | head -c 300)"
call "chat_send with an unknown chat id (public)" '{"action":"chat_send","sid":"0123456789abcdef0123456789abcdef","message":"hi"}'
ans 'assert d.get("ok") is False and d.get("expired") is True, d' && chk 0 "an unknown chat id is an ended chat (expired), not someone else's" || chk 1 "unknown sid — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_send (public)" "{\"action\":\"chat_send\",\"sid\":\"$SID\",\"message\":\"Do you take new patients?\"}"
ans 'assert d.get("ok") is True and d.get("id"), d' && ghlq 'l=[x for x in s["log"] if x["p"]=="/conversations/messages/inbound"]; assert len(l)==1 and l[0]["v"]=="2021-04-15" and l[0]["body"]["type"]=="Live_Chat" and l[0]["body"]["direction"]=="inbound" and l[0]["body"]["message"]=="Do you take new patients?" and "conversationProviderId" not in l[0]["body"], l' && chk 0 "chat_send hands the message to the CRM as an inbound Live Chat message" || chk 1 "chat_send — got: $(printf '%s' "$LAST" | head -c 200)"
CV=$(curl -s "http://127.0.0.1:$GHLPORT/__state" | python3 -c 'import json,sys; print(list(json.load(sys.stdin)["conversations"])[0])')
ghl /__seed "$(curl -s "http://127.0.0.1:$GHLPORT/__state" | python3 -c "import json,sys,time; s=json.load(sys.stdin); m=s['messages']['$CV']; m.insert(0,{'id':'msg_old','direction':'inbound','messageType':'TYPE_LIVE_CHAT','body':'An old chat','dateAdded':'2020-01-01T00:00:00.000Z'}); m.append({'id':'msg_sms','direction':'outbound','messageType':'TYPE_SMS','body':'Your code is 1234','dateAdded':time.strftime('%Y-%m-%dT%H:%M:%S.000Z',time.gmtime(time.time()+5))}); print(json.dumps({'messages':{'$CV':m}}))")"
call "chat_poll (public)" "{\"action\":\"chat_poll\",\"sid\":\"$SID\"}"
ans 'm=d["messages"]; assert [(x["from"],x["text"]) for x in m]==[("visitor","Do you take new patients?"),("agent","Bot: Do you take new patients?")], m; assert all(x["id"] and x["at"] for x in m)' && chk 0 "chat_poll returns the visitor's message and the bot's reply — not a text message on the same contact, not a 2020 chat" || chk 1 "chat_poll — got: $(printf '%s' "$LAST" | head -c 300)"
call "chat_start again with the same email (public)" '{"action":"chat_start","name":"Someone Else","email":"pat@example.com"}'
SID2=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("sid",""))')
call "chat_poll for that second chat (public)" "{\"action\":\"chat_poll\",\"sid\":\"$SID2\"}"
ans 'assert d.get("ok") and d["messages"]==[], d' && chk 0 "someone typing the same email address starts a chat that shows nothing of the first one" || chk 1 "second chat on the same contact — got: $(printf '%s' "$LAST" | head -c 300)"
for i in 2 3 4 5 6 7 8; do qcall "{\"action\":\"chat_send\",\"sid\":\"$SID\",\"message\":\"message $i\"}"; done
call "chat_send, the 9th in a minute from one chat (public)" "{\"action\":\"chat_send\",\"sid\":\"$SID\",\"message\":\"too many\"}"
ans 'assert d.get("ok") is False and "quickly" in d.get("error",""), d' && ghlq 'assert len([x for x in s["log"] if x["p"]=="/conversations/messages/inbound"])==8' && chk 0 "the 9th message in a minute from one chat is refused (429) before it reaches the CRM" || chk 1 "rate limit — got: $(printf '%s' "$LAST" | head -c 200)"
dbq '$pdo->exec("UPDATE chat_sessions SET last_active=" . (time() - 8 * 86400));'
call "chat_poll on a chat silent for 8 days (public)" "{\"action\":\"chat_poll\",\"sid\":\"$SID\"}"
ans 'assert d.get("ok") is False and d.get("expired") is True, d' && chk 0 "a chat that has been silent for a week has ended" || chk 1 "expiry — got: $(printf '%s' "$LAST" | head -c 200)"
dbq '$pdo->exec("UPDATE chat_sessions SET last_active=" . time());'
TOK="$ARCH_TOK3"
wjson "write data/chat.json (Fourge Chat off)" data/chat.json "{\"enabled\":false,\"agentId\":\"$AGENT\"}"
TOK=""
call "chat_poll while Fourge Chat is off (public)" "{\"action\":\"chat_poll\",\"sid\":\"$SID\"}"
ans 'assert d.get("ok") is False and "not available" in d.get("error",""), d' && chk 0 "switching Fourge Chat off closes the public calls at once" || chk 1 "chat off — got: $(printf '%s' "$LAST" | head -c 200)"
TOK="$ARCH_TOK3"
wjson "write data/chat.json (Fourge Chat on)" data/chat.json "{\"enabled\":true,\"agentId\":\"$AGENT\"}"
# the AI test (the check that the CRM's bot answers chat messages handed to it this way)
call "chat_ai_test" '{"action":"chat_ai_test"}'
ans 'assert d.get("ok") and d["steps"] and all(x["ok"] for x in d["steps"]) and d.get("conversationId") and d.get("since"), d' && chk 0 "chat_ai_test: bot found, test contact, conversation, message handed over ($(printf '%s' "$LAST" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["steps"]))') steps, all ok)" || chk 1 "chat_ai_test — got: $(printf '%s' "$LAST" | head -c 300)"
TC=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("conversationId",""))'); TS=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("since",0))')
call "chat_ai_test_poll" "{\"action\":\"chat_ai_test_poll\",\"conversationId\":\"$TC\",\"since\":$TS}"
ans 'assert d.get("ok") and d["done"] is True and d["reply"].startswith("Bot: Hi! This is an automatic test") and d["seenInbound"] is True and d["replyType"]=="TYPE_LIVE_CHAT" and d["seconds"] is not None, d' && ghlq 'c=[x for x in s["contacts"].values() if x.get("email")=="fourge-chat-test@example.com"]; assert len(c)==1 and c[0]["firstName"]=="Fourge" and c[0]["lastName"]=="Chat Test", c' && chk 0 "chat_ai_test_poll sees the bot's reply to the test contact Fourge Chat Test" || chk 1 "chat_ai_test_poll — got: $(printf '%s' "$LAST" | head -c 300)"
ghl /__seed "$(curl -s "http://127.0.0.1:$GHLPORT/__state" | python3 -c "import json,sys; s=json.load(sys.stdin); a=s['agents']['$AGENT']; a['mode']='suggestive'; a['knowledgeBaseIds']=['kb_1']; print(json.dumps({'agents':{'$AGENT':a}}))")"
call "chat_ai_test with a bot that only drafts replies" '{"action":"chat_ai_test"}'
ans 'b=[x for x in d["steps"] if x["label"]=="The bot answers on its own"]; assert d.get("ok") and b and b[0]["ok"] is False and "suggestive" in b[0]["detail"], d' && chk 0 "chat_ai_test flags a bot that only drafts replies (suggestive mode)" || chk 1 "suggestive — got: $(printf '%s' "$LAST" | head -c 300)"
call "chat_bot_save update (let it answer on its own)" "{\"action\":\"chat_bot_save\",\"op\":\"update\",\"agentId\":\"$AGENT\",\"autoPilot\":true}"
ghlq "a=s['agents']['$AGENT']; p=[x for x in s['log'] if x['m']=='PUT'][-1]; assert p['p']=='/conversation-ai/agents/$AGENT' and a['mode']=='auto-pilot' and a['knowledgeBaseIds']==['kb_1'] and a['instructions']=='Keep it short.' and a['isPrimary'] is True and a['channels']==['Live_Chat'] and a['autoPilotMaxMessages']==50, (a,p)" && ans 'assert d.get("ok") and d["done"]=="updated", d' && chk 0 "\"Let it answer on its own\" switches it to auto-pilot and keeps its knowledge bases and prompt (the PUT replaces the whole bot)" || chk 1 "autoPilot update — got: $(printf '%s' "$LAST" | head -c 300)"
call "chat_bot_save update (the prompt, edited in Fourge)" "{\"action\":\"chat_bot_save\",\"op\":\"update\",\"agentId\":\"$AGENT\",\"name\":\"E2E Assistant\",\"businessName\":\"E2E Dental\",\"personality\":\"Cheerful\",\"goal\":\"Book visits\",\"instructions\":\"Offer a booking link.\"}"
ghlq "a=s['agents']['$AGENT']; assert a['personality']=='Cheerful' and a['goal']=='Book visits' and a['instructions']=='Offer a booking link.' and a['knowledgeBaseIds']==['kb_1'] and a['mode']=='auto-pilot', a" && chk 0 "editing the bot in Fourge saves its prompt and keeps everything else" || chk 1 "prompt update — got: $(printf '%s' "$LAST" | head -c 300)"
# a CRM that already has a main bot (on other channels), and a Conversation AI API that insists on v3
ghl /__reset; ghl /__mode '{"aiV3Only":true}'
ghl /__seed '{"agents":{"ag_main":{"id":"ag_main","name":"Main Bot","isPrimary":true,"channels":["SMS","FB"],"mode":"auto-pilot","personality":"p","goal":"g","instructions":"i","knowledgeBaseIds":["kb_9"],"autoPilotMaxMessages":30,"actions":[{"id":"act1","type":"triggerWorkflow"}]}}}'
call "chat_bot_status (a main bot on SMS and Facebook only)" '{"action":"chat_bot_status"}'
ans 'assert d.get("ok") and d["bot"] is None and d["main"]["name"]=="Main Bot", d' && ghlq 'v=[x["v"] for x in s["log"] if x["p"]=="/conversation-ai/agents/search"]; assert v==["2021-04-15","v3"], v' && chk 0 "chat_bot_status offers the CRM's existing main bot, after retrying with Version v3 when 2021-04-15 is refused" || chk 1 "main bot / v3 — got: $(printf '%s' "$LAST" | head -c 300)"
call "chat_bot_save use" '{"action":"chat_bot_save","op":"use"}'
ans 'assert d.get("ok") and d["done"]=="adopted", d' && ghlq 'a=s["agents"]["ag_main"]; assert a["channels"]==["SMS","FB","Live_Chat"] and a["isPrimary"] is True and a["knowledgeBaseIds"]==["kb_9"] and a["autoPilotMaxMessages"]==30 and a["personality"]=="p" and a["actions"]==[{"id":"act1","type":"triggerWorkflow"}], a' && chatjson "assert d.get('agentId')=='ag_main', d" && chk 0 "\"Use Main Bot for website chat\" adds Live Chat to it, changes nothing else, and data/chat.json points at it" || chk 1 "use — got: $(printf '%s' "$LAST" | head -c 300)"
ghl /__mode '{"aiV3Only":false}'
# GoHighLevel may insist on a conversation provider id for chat messages
ghl /__mode '{"requireProvider":true,"providerId":"prov_live_e2e"}'
call "chat_ai_test (a provider id is required and none can be found)" '{"action":"chat_ai_test"}'
ans 'assert d.get("ok") is False and d.get("routeBlocked") is True and "conversationProviderId should not be empty" in d["steps"][-1]["detail"], d' && chk 0 "when the CRM refuses chat messages added this way, the test says so (routeBlocked) in the CRM's own words" || chk 1 "route blocked — got: $(printf '%s' "$LAST" | head -c 300)"
ghl /__seed '{"contacts":{"ct_prev":{"id":"ct_prev","email":"prev@example.com","tags":[]}},"conversations":{"cv_prev":{"id":"cv_prev","contactId":"ct_prev","locationId":"loc_e2e"}},"messages":{"cv_prev":[{"id":"msg_prev","direction":"inbound","messageType":"TYPE_LIVE_CHAT","body":"hello from the CRM widget","dateAdded":"2026-01-01T00:00:00.000Z","conversationProviderId":"prov_live_e2e"}]}}'
call "chat_ai_test (an earlier Live Chat carries the provider id)" '{"action":"chat_ai_test"}'
ans 'p=[x for x in d["steps"] if "channel id" in x["label"]]; assert d.get("ok") and p and p[0]["ok"] and "prov_live_e2e" in p[0]["detail"], d' && chatjson "assert d.get('providerId')=='prov_live_e2e', d" && chk 0 "…and finds the id on an earlier Live Chat, uses it, and saves it in data/chat.json" || chk 1 "provider discovery — got: $(printf '%s' "$LAST" | head -c 300)"
TOK=""
call "chat_start (public; the provider id is saved)" '{"action":"chat_start","name":"Lee","email":"lee@example.com"}'
SID3=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("sid",""))')
call "chat_send (public; the provider id is saved)" "{\"action\":\"chat_send\",\"sid\":\"$SID3\",\"message\":\"Hello\"}"
ans 'assert d.get("ok"), d' && ghlq 'l=[x for x in s["log"] if x["p"]=="/conversations/messages/inbound"][-1]; assert l["body"].get("conversationProviderId")=="prov_live_e2e", l' && chk 0 "visitor messages carry the saved provider id" || chk 1 "provider id on chat_send — got: $(printf '%s' "$LAST" | head -c 200)"
ghl /__mode '{"requireProvider":false}'
TOK="$ARCH_TOK3"
call "set_secret ghl_token (a token the CRM rejects)" '{"action":"set_secret","name":"ghl_token","value":"pit-wrong"}'
call "chat_bot_status with a rejected token" '{"action":"chat_bot_status"}'
ans 'assert d.get("ok") is False and "HTTP 401" in d["error"] and "scopes" in d["error"], d' && chk 0 "a rejected token is explained, with the scopes the token needs" || chk 1 "rejected token — got: $(printf '%s' "$LAST" | head -c 300)"
call "set_secret ghl_token (restored)" '{"action":"set_secret","name":"ghl_token","value":"pit-e2e"}'
dbq '$pdo->prepare("INSERT INTO users (username, email, role, is_architect, password_hash, must_change_password, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")->execute(["chat-editor@example.com","chat-editor@example.com","editor",0,password_hash("E2ePass!234", fourgePwAlgo()),0,date("c"),date("c")]);'
TOK=""
call "login as an editor" '{"action":"login","username":"chat-editor@example.com","password":"E2ePass!234"}'
TOK=$(printf '%s' "$LAST" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token",""))' 2>/dev/null)
call "chat_bot_status as an editor" '{"action":"chat_bot_status"}'
ans 'assert d.get("error")=="Super Admin access required", d' && chk 0 "the bot box is Super Admin only (it uses the CRM token)" || chk 1 "editor bot status — got: $(printf '%s' "$LAST" | head -c 200)"
call "chat_ai_test as an editor" '{"action":"chat_ai_test"}'
ans 'assert d.get("error")=="Super Admin access required", d' && chk 0 "…and so is the AI test (its replies cost AI credit)" || chk 1 "editor AI test — got: $(printf '%s' "$LAST" | head -c 200)"
# Analytics switched off for an editor (1.14.151): the analytics calls refuse them; on by default as before
call "ga_status as an editor (Analytics on by default)" '{"action":"ga_status"}'
ans 'assert "error" not in d and "configured" in d, d' && chk 0 "an editor with no module changes still gets Analytics data" || chk 1 "editor ga_status — got: $(printf '%s' "$LAST" | head -c 200)"
dbq '$pdo->prepare("UPDATE users SET permissions=? WHERE username=?")->execute(["{\"pages\":true,\"analytics\":false}","chat-editor@example.com"]);'
call "ga_status as an editor with Analytics switched off" '{"action":"ga_status"}'
ans 'assert d.get("error","").startswith("Analytics is switched off for your account"), d' && chk 0 "with Analytics switched off in Users, the editor gets none of its data (ga_status refused at once, same session)" || chk 1 "editor ga_status off — got: $(printf '%s' "$LAST" | head -c 200)"
call "ga_report as that editor" '{"action":"ga_report","propertyId":"1","kind":"report","request":{}}'
ans 'assert d.get("error","").startswith("Analytics is switched off for your account"), d' && chk 0 "…nor reports (ga_report)" || chk 1 "editor ga_report off — got: $(printf '%s' "$LAST" | head -c 200)"
call "ga_save_credentials as that editor" '{"action":"ga_save_credentials","json":"{}"}'
ans 'assert d.get("error","").startswith("Analytics is switched off for your account"), d' && chk 0 "…and cannot change its credentials (ga_save_credentials)" || chk 1 "editor ga_save_credentials off — got: $(printf '%s' "$LAST" | head -c 200)"
TOK="$ARCH_TOK3"
call "ga_status as an Architect" '{"action":"ga_status"}'
ans 'assert "error" not in d and "configured" in d, d' && chk 0 "an Architect always has Analytics" || chk 1 "architect ga_status — got: $(printf '%s' "$LAST" | head -c 200)"
# Analytics → Setup saves the Google credentials again: GA_CRED_FILE used to be define()d below the dispatcher,
# so every ga_* call died with "Undefined constant" on PHP 8 (fixed in 1.14.151)
call "ga_save_credentials as an Architect" "$(python3 -c 'import json; print(json.dumps({"action":"ga_save_credentials","credentials":json.dumps({"type":"service_account","client_email":"e2e@e2e.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY-----\\nx\\n-----END PRIVATE KEY-----\\n"})}))')"
ans 'assert d.get("ok") is True and d.get("client_email")=="e2e@e2e.iam.gserviceaccount.com", d' && [ -f "$S/admin/ga-service-account.json" ] && chk 0 "ga_save_credentials stores the service account (no more \"Undefined constant GA_CRED_FILE\")" || chk 1 "ga_save_credentials — got: $(printf '%s' "$LAST" | head -c 200)"
call "ga_status after saving credentials" '{"action":"ga_status"}'
ans 'assert d.get("configured") is True and d.get("client_email")=="e2e@e2e.iam.gserviceaccount.com", d' && chk 0 "…and ga_status reports them" || chk 1 "ga_status configured — got: $(printf '%s' "$LAST" | head -c 200)"
rm -f "$S/admin/ga-service-account.json"
# AI requests (1.14.151): 300 an hour per user by default (it was 40), and only answers count — an attempt Anthropic turns away is free
call "set_secret claude_key" '{"action":"set_secret","name":"claude_key","value":"sk-ant-e2e"}'
echo ok > "$ANMODE"; echo 0 > "$ANCOUNT"; rm -f "$S/data/ai_usage.json"
AIB='{"action":"claude_proxy","user":"admin@44interactive.com","model":"stub","max_tokens":10,"messages":[{"role":"user","content":"hi"}]}'
aiused(){ python3 -c "import json,sys; d=json.load(open('$S/data/ai_usage.json')); print(d.get('admin@44interactive.com',{}).get('count',0))" 2>/dev/null || echo 0; }
for i in $(seq 1 41); do qcall "$AIB"; done
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("content",[{}])[0].get("text")=="{\"title\":\"Stub answer\"}", d' && [ "$(aiused)" = 41 ] && [ "$(cat "$ANCOUNT")" = 41 ] && chk 0 "41 AI requests in one hour are all answered (the old limit stopped at 40), and all 41 count" || chk 1 "41 AI requests — last: $(printf '%s' "$LAST" | head -c 200) / counted $(aiused) / stub saw $(cat "$ANCOUNT")"
echo overloaded > "$ANMODE"
call "claude_proxy while Anthropic is overloaded" "$AIB"
ans 'assert d.get("error",{}).get("type")=="overloaded_error", d' && [ "$(aiused)" = 41 ] && chk 0 "an attempt Anthropic turns away (overloaded) does not count toward the hour" || chk 1 "overloaded — got: $(printf '%s' "$LAST" | head -c 200) / counted $(aiused)"
echo ok > "$ANMODE"
python3 - "$S/admin/config.secret.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read(); i=s.rindex('];'); open(p,'w').write(s[:i]+"  'ai_rate_per_hour' => 42,\n"+s[i:])
PY
call "claude_proxy (the 42nd answer; this site's own limit is 42)" "$AIB"
ans 'assert d.get("content"), d' && [ "$(aiused)" = 42 ] && chk 0 "a site's own limit (ai_rate_per_hour in config.secret.php) still applies: the 42nd is answered" || chk 1 "42nd — got: $(printf '%s' "$LAST" | head -c 200)"
SEEN=$(cat "$ANCOUNT")
call "claude_proxy (over this site's limit)" "$AIB"
ans 'import re; e=d.get("error",""); assert re.match(r"^AI usage limit reached for this hour \(42 requests\)\. It resets in \d+ minutes?\.$", e), d' && [ "$(cat "$ANCOUNT")" = "$SEEN" ] && chk 0 "…and the next is refused before it reaches Anthropic, saying when the hour resets" || chk 1 "over the limit — got: $(printf '%s' "$LAST" | head -c 200)"
python3 - "$S/admin/config.secret.php" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read(); open(p,'w').write(s.replace("  'ai_rate_per_hour' => 42,\n",""))
PY
rm -f "$S/data/ai_usage.json"

# SEO platform deploy package (1.14.152): the platform now writes format "ydg-deploy-package" (it was
# "44i-deploy-package", the only one accepted), and its content arrived with the title tag, meta
# description and H1 run into the slug field — a filename hundreds of characters long
for f in pages.json seo.json posts.json; do if [ -f "$S/data/$f" ]; then cp "$S/data/$f" "$S.pkgbak.$f"; else rm -f "$S.pkgbak.$f"; fi; done
python3 - "$S.pkg.json" <<'PY'
import json,sys
body='<p>Our yard stocks brick, block and natural stone for builders across the county, with delivery to the job site and pallets cut to order.</p><h1>Inner heading</h1><p>Ask about contractor pricing.</p>'
item=lambda **k: dict({'body_html':body,'seo_title':None,'seo_description':None,'approved':True,'qa':[],'schedule':None}, **k)
pkg={'format':'ydg-deploy-package','format_version':1,'generated_at':'2026-11-04T22:10:02Z','site':'https://e2e-site.example','client':{'name':'E2E Masonry','tier':'pro'},'manual_tasks':[],
 'content':[
  item(external_id='e2e-pkg-1',title='Brick Supply Testville, IN',post_type='page',kind='landing',status='publish',
       slug='/brick-supply-testville-in TITLE TAG: Brick Supply for Testville, IN | E2E Masonry META DESCRIPTION: E2E Masonry supplies brick, block and stone to Testville, IN builders. Call today to order or ask about contractor pricing. H1: Brick Supply Serving Testville, Indiana'),
  item(external_id='e2e-pkg-2',title='Choosing Landscape Stone',post_type='post',kind='blog',status='schedule',schedule='2099-01-01T15:00:00Z',
       slug='/choosing-landscape-stone TITLE TAG: Choosing Landscape Stone in Testville META DESCRIPTION: How to pick landscape stone that lasts: the sizes, the colours and what each one costs. H1: How to Choose Landscape Stone'),
  item(external_id='e2e-pkg-3',title='Retaining Walls',post_type='page',kind='pillar',status='draft',
       slug='retaining walls '+'and more retaining wall block options for every kind of backyard project in the county '*2,
       seo_title='Retaining Wall Block in Testville | E2E Masonry',seo_description='Retaining wall block in every size and colour, stocked in Testville. Visit the yard or call for a quote.'),
  item(external_id='e2e-pkg-4',title='Pavers',post_type='page',kind='landing',status='publish',slug='/pavers-testville')]}
json.dump({'action':'seo_package','mode':'preview','package':pkg},open(sys.argv[1],'w'))
PY
call "seo_package preview (format ydg-deploy-package)" "$(cat "$S.pkg.json")"
ans 'a=d["applied"]; n=lambda x: next(i.get("note","") for i in a if i["item"]==x); assert d.get("dry_run") is True and d["counts"]["applied"]==4 and not d["skipped"], d; assert "came inside the slug field" in n("Brick Supply Testville, IN") and "came inside the slug field" in n("Choosing Landscape Stone") and "slug field" not in n("Pavers"), a; assert "created brick-supply-testville-in.html" in n("Brick Supply Testville, IN"), a' && [ ! -e "$S/brick-supply-testville-in.html" ] && chk 0 "a package in the platform's new format is accepted; the preview splits the title tag, description and H1 out of the slug field, and writes nothing" || chk 1 "package preview — got: $(printf '%s' "$LAST" | head -c 400)"
python3 -c "import json,sys; b=json.load(open(sys.argv[1])); b['mode']='apply'; json.dump(b,open(sys.argv[1],'w'))" "$S.pkg.json"
call "seo_package apply" "$(cat "$S.pkg.json")"
P1="$S/brick-supply-testville-in.html"
ans 'assert d.get("ok") is True and d["counts"]["applied"]==4, d' && grep -q '<title>Brick Supply for Testville, IN | E2E Masonry</title>' "$P1" && grep -q '<meta name="description" content="E2E Masonry supplies brick, block and stone to Testville, IN builders. Call today to order or ask about contractor pricing." data-fourge-seo>' "$P1" && grep -q '<h1>Brick Supply Serving Testville, Indiana</h1>' "$P1" && [ "$(grep -c '<h1' "$P1")" = 1 ] && grep -q '<meta name="robots" content="index,follow"' "$P1" && chk 0 "the page is created at brick-supply-testville-in.html, with the package's title tag, meta description and H1 (one H1), and live" || chk 1 "package page — report: $(printf '%s' "$LAST" | head -c 200) / title: $(grep -o '<title>[^<]*</title>' "$P1" 2>&1) / h1: $(grep -o '<h1>[^<]*</h1>' "$P1" 2>&1 | tr '\n' ' ')"
python3 - "$S" <<'PY' && chk 0 "the post gets its slug, and the package's description as its excerpt (the post's meta description), scheduled; a page whose slug was a long sentence gets a short file name, and its seo_title / seo_description fields are used" || chk 1 "package post / long slug / seo fields"
import json,sys,os
S=sys.argv[1]
post=[p for p in json.load(open(S+'/data/posts.json')) if p.get('extId')=='e2e-pkg-2'][0]
assert post['slug']=='choosing-landscape-stone' and post['excerpt']=='How to pick landscape stone that lasts: the sizes, the colours and what each one costs.' and post['published'] is False and post['publishAt'].startswith('2099-01-01'), post
pages=json.load(open(S+'/data/pages.json')); seo=json.load(open(S+'/data/seo.json'))
pid=[k for k,v in pages.items() if isinstance(v,dict) and v.get('extId')=='e2e-pkg-3'][0]
assert len(pages[pid]['file'])<=105 and pages[pid]['file'].startswith('retaining-walls-and-more-retaining-wall-block') and os.path.isfile(S+'/'+pages[pid]['file']), pages[pid]
assert seo[pid]['title']=='Retaining Wall Block in Testville | E2E Masonry' and seo[pid]['description'].startswith('Retaining wall block in every size'), seo[pid]
assert pages[pid]['draft'] is True, pages[pid]
PY
call "seo_package preview (the old format name, 44i-deploy-package)" "$(python3 -c "import json,sys; b=json.load(open(sys.argv[1])); b['mode']='preview'; b['package']['format']='44i-deploy-package'; print(json.dumps(b))" "$S.pkg.json")"
ans 'assert d.get("ok") is True and d.get("dry_run") is True, d' && chk 0 "a package in the old format name is still accepted" || chk 1 "44i format — got: $(printf '%s' "$LAST" | head -c 200)"
call "seo_package (some other JSON)" '{"action":"seo_package","mode":"preview","package":{"format":"something-else","format_version":1}}'
ans 'assert d.get("ok") is False and "expected format \"ydg-deploy-package\"" in d.get("error",""), d' && chk 0 "any other file is refused, naming the format it expects" || chk 1 "other format — got: $(printf '%s' "$LAST" | head -c 200)"

# AI auto-fix (1.14.152): its constants were defined below the dispatcher, so the button and the daily
# run both ended in "Undefined constant FOURGE_AI_STATE"
echo ok > "$ANMODE"; SEEN=$(cat "$ANCOUNT")
call "ai_autofix (preview)" '{"action":"ai_autofix","dry":true,"metaCap":50,"altCap":0,"linkCap":0,"faqCap":0}'
ans 'assert d.get("ok") is True and "totals" in d, d; assert {"page":"Pavers","wrote":"title","was":"weak/missing"} in d["metas"], d["metas"]' && [ "$(cat "$ANCOUNT")" -gt "$SEEN" ] && [ ! -e "$S/data/ai_autofix.json" ] && chk 0 "the AI auto-fix runs again: its preview asks the AI about the page with a weak title (Pavers) and writes nothing" || chk 1 "ai_autofix preview — got: $(printf '%s' "$LAST" | head -c 400)"
call "ai_autofix (run)" '{"action":"ai_autofix","metaCap":0,"altCap":0,"linkCap":0,"faqCap":0}'
ans 'assert d.get("ok") is True and d.get("dry_run") is False, d' && python3 -c "import json; s=json.load(open('$S/data/ai_autofix.json')); assert s.get('last_run'), s" && chk 0 "…and a run saves its state (data/ai_autofix.json)" || chk 1 "ai_autofix run — got: $(printf '%s' "$LAST" | head -c 300)"
for f in pages.json seo.json posts.json; do if [ -f "$S.pkgbak.$f" ]; then mv "$S.pkgbak.$f" "$S/data/$f"; else rm -f "$S/data/$f"; fi; done
rm -f "$S/brick-supply-testville-in.html" "$S/pavers-testville.html" "$S"/retaining-walls-and-more-*.html "$S/data/seo-package-report.json" "$S/data/ai_autofix.json" "$S.pkg.json"
call "delete_file data/chat.json" '{"action":"delete_file","path":"data/chat.json"}'
call "write_file data/site.json (restore the original)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":$SITE}"

TOK=""
call "blog_sync_poke (public)" '{"action":"blog_sync_poke"}'
call "blog_sync_poke with push from a non-source (public)" '{"action":"blog_sync_poke","push":1,"source":"https://evil.example"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is True and d.get("pushed") is False, d' && chk 0 "a push poke from a host that is not the configured source answers pushed:false" || chk 1 "push poke — got: $(printf '%s' "$LAST" | head -c 200)"
call "blog_sync_subscribe with a non-https site (public)" '{"action":"blog_sync_subscribe","site":"http://partner.example"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="bad_site", d' && chk 0 "blog_sync_subscribe refuses a non-https site with reason bad_site" || chk 1 "subscribe — got: $(printf '%s' "$LAST" | head -c 200)"
call "form_view (public)" '{"action":"form_view","formId":"e2e-form"}'
call "unknown action → JSON error" '{"action":"definitely_not_an_action"}'
call "no session on a session action → 401 JSON" '{"action":"list_users"}'
kill $SRV $GHSRV $GHLSRV $ANSRV 2>/dev/null; wait $SRV $GHSRV $GHLSRV $ANSRV 2>/dev/null
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.server.log"; then chk 1 "php -S log has PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.server.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "php -S log shows no PHP warnings/notices/deprecations during the whole run"; fi
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.ghstub.log"; then chk 1 "the GitHub stub logged PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.ghstub.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "the GitHub stub ran without PHP warnings"; fi
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.ghlstub.log"; then chk 1 "the GoHighLevel stub logged PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.ghlstub.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "the GoHighLevel stub ran without PHP warnings"; fi
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.anstub.log"; then chk 1 "the Anthropic stub logged PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.anstub.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "the Anthropic stub ran without PHP warnings"; fi
rm -rf "$S" "$S.server.log" "$S.ghstub.log" "$S.ghroot" "$GHLOG" "$GHSTATE" "$GHMODE" "$S.ghlstub.log" "$S.ghlroot" "$GHLSTATE" "$S.anstub.log" "$S.anroot" "$ANMODE" "$ANCOUNT"
echo; echo "$([ $fail = 0 ] && echo "all end-to-end dispatcher assertions passed ($pass)" || echo "SUITE FAILED ($fail failed, $pass passed)")"
exit $([ $fail = 0 ] && echo 0 || echo 1)
