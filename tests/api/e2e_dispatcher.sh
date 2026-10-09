#!/bin/bash
# End-to-end through the REAL api.php dispatcher: a throwaway copy of the site with its
# own config.secret.php + SQLite DB, served by php -S with display_errors=On, a real
# login session, one request per action. Every response must be valid JSON.
set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
S="$SRC/tests/.cache/e2e_site"
PORT="${E2E_PORT:-8932}"; BASE=http://127.0.0.1:$PORT/admin/api.php
GHPORT="${E2E_GH_PORT:-8934}"; GHLOG="$SRC/tests/.cache/gh_stub_log.jsonl"; GHSTATE="$SRC/tests/.cache/gh_stub_state.json"; GHMODE="$SRC/tests/.cache/gh_stub_mode"
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
];
PHP
php -d display_errors=1 -r 'define("PUBLIC_HTML", $argv[1]); require $argv[1]."/admin/db.php"; $pdo=fourgeDb(); fourgeSetPassword($pdo,"admin@44interactive.com","E2ePass!234"); $pdo->exec("UPDATE users SET must_change_password=0, is_architect=1 WHERE username=\"admin@44interactive.com\""); fourgeSetPassword($pdo,"editor44i","E2ePass!234"); $pdo->exec("UPDATE users SET role=\"superadmin\", must_change_password=0, is_architect=0 WHERE username=\"editor44i\""); $u=fourgeGetUser($pdo,"admin@44interactive.com"); echo "user ready: role=",($u["role"]??"?"),"\n";' "$S" || { echo "FAIL could not prepare the test DB"; exit 1; }
# the stand-in for api.github.com (tests/lib/gh_stub.php) — the engine is pointed at it with FOURGE_GH_API_BASE
mkdir -p "$S.ghroot"; rm -f "$GHLOG" "$GHSTATE"; echo ok > "$GHMODE"
php -S 127.0.0.1:$GHPORT -t "$S.ghroot" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 "$SRC/tests/lib/gh_stub.php" > "$S.ghstub.log" 2>&1 &
GHSRV=$!
FOURGE_GH_API_BASE="http://127.0.0.1:$GHPORT" php -S 127.0.0.1:$PORT -t "$S" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 > "$S.server.log" 2>&1 &
SRV=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$GHPORT/user" && break; sleep 0.2; done
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
call "install_clean_urls (login self-heal chain: htaccess, blog sync tick…)" '{"action":"install_clean_urls"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("cacheHeaders") is True, d' && chk 0 "install_clean_urls reports cacheHeaders:true (the managed browser-caching block)" || chk 1 "cacheHeaders — got: $(printf '%s' "$LAST" | head -c 200)"
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
call "write_file data/site.json (restore the original)" "{\"action\":\"write_file\",\"path\":\"data/site.json\",\"content\":$SITE}"
rm -f "$S/e2e-mirror.html"
call "blog_sync_admin status" '{"action":"blog_sync_admin","op":"status"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("subscribers")==0 and d.get("subscriberSites")==[], d' && chk 0 "blog_sync_admin status reports subscribers:0 on a fresh site" || chk 1 "status subscribers — got: $(printf '%s' "$LAST" | head -c 200)"
call "blog_sync_admin notify (no partners)" '{"action":"blog_sync_admin","op":"notify"}'
call "blog_sync_tick" '{"action":"blog_sync_tick"}'
call "recaptcha_status" '{"action":"recaptcha_status"}'
call "seo_pkg_admin status" '{"action":"seo_pkg_admin","op":"status"}'
TOK=""
call "blog_sync_poke (public)" '{"action":"blog_sync_poke"}'
call "blog_sync_poke with push from a non-source (public)" '{"action":"blog_sync_poke","push":1,"source":"https://evil.example"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is True and d.get("pushed") is False, d' && chk 0 "a push poke from a host that is not the configured source answers pushed:false" || chk 1 "push poke — got: $(printf '%s' "$LAST" | head -c 200)"
call "blog_sync_subscribe with a non-https site (public)" '{"action":"blog_sync_subscribe","site":"http://partner.example"}'
printf '%s' "$LAST" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is False and d.get("reason")=="bad_site", d' && chk 0 "blog_sync_subscribe refuses a non-https site with reason bad_site" || chk 1 "subscribe — got: $(printf '%s' "$LAST" | head -c 200)"
call "form_view (public)" '{"action":"form_view","formId":"e2e-form"}'
call "unknown action → JSON error" '{"action":"definitely_not_an_action"}'
call "no session on a session action → 401 JSON" '{"action":"list_users"}'
kill $SRV $GHSRV 2>/dev/null; wait $SRV $GHSRV 2>/dev/null
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.server.log"; then chk 1 "php -S log has PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.server.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "php -S log shows no PHP warnings/notices/deprecations during the whole run"; fi
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.ghstub.log"; then chk 1 "the GitHub stub logged PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.ghstub.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "the GitHub stub ran without PHP warnings"; fi
rm -rf "$S" "$S.server.log" "$S.ghstub.log" "$S.ghroot" "$GHLOG" "$GHSTATE" "$GHMODE"
echo; echo "$([ $fail = 0 ] && echo "all end-to-end dispatcher assertions passed ($pass)" || echo "SUITE FAILED ($fail failed, $pass passed)")"
exit $([ $fail = 0 ] && echo 0 || echo 1)
