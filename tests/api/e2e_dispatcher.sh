#!/bin/bash
# End-to-end through the REAL api.php dispatcher: a throwaway copy of the site with its
# own config.secret.php + SQLite DB, served by php -S with display_errors=On, a real
# login session, one request per action. Every response must be valid JSON.
set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
S="$SRC/tests/.cache/e2e_site"
PORT="${E2E_PORT:-8932}"; BASE=http://127.0.0.1:$PORT/admin/api.php
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
php -d display_errors=1 -r 'define("PUBLIC_HTML", $argv[1]); require $argv[1]."/admin/db.php"; $pdo=fourgeDb(); fourgeSetPassword($pdo,"admin@44interactive.com","E2ePass!234"); $pdo->exec("UPDATE users SET must_change_password=0"); $u=fourgeGetUser($pdo,"admin@44interactive.com"); echo "user ready: role=",($u["role"]??"?"),"\n";' "$S" || { echo "FAIL could not prepare the test DB"; exit 1; }
php -S 127.0.0.1:$PORT -t "$S" -d display_errors=1 -d error_reporting=32767 -d log_errors=0 > "$S.server.log" 2>&1 &
SRV=$!
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
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null
if grep -qiE "PHP (Warning|Notice|Deprecated|Fatal|Parse)" "$S.server.log"; then chk 1 "php -S log has PHP notices/warnings: $(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' "$S.server.log" | head -3 | cut -c1-200 | tr '\n' ' ')"; else chk 0 "php -S log shows no PHP warnings/notices/deprecations during the whole run"; fi
rm -rf "$S" "$S.server.log"
echo; echo "$([ $fail = 0 ] && echo "all end-to-end dispatcher assertions passed ($pass)" || echo "SUITE FAILED ($fail failed, $pass passed)")"
exit $([ $fail = 0 ] && echo 0 || echo 1)
