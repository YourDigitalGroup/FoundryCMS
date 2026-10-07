<?php
// GitHub client pieces (1.14.141), lifted out of admin/api.php by name and run without the
// dispatcher: cmsGhBase() (the api.github.com base and its test override), cmsGhSkipCi() (every
// mirror commit carries [skip ci] exactly once), cmsGhReason()/cmsGhExplain() (a rejected token,
// the rate limit, an invisible repo, an unreachable GitHub — each one plain sentence), and
// fourgeSitePut() (the server-side writer: writes like file_put_contents, mirrors only when the
// bytes changed and the path is mirror-eligible, never fails the write).
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (preg_match('~\}\s*$~', $line) && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";   // a one-line function
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$tmp = sys_get_temp_dir() . '/fourge-ghclient-' . getmypid(); @mkdir($tmp . '/x', 0777, true); @mkdir($tmp . '/admin', 0777, true);
define('PUBLIC_HTML', $tmp);
// stand-ins for the pieces fourgeSitePut leans on
$MIRRORED = [];
function cmsGhRelPath($abs) { $abs = realpath($abs) ?: (string)$abs; if (strpos($abs, PUBLIC_HTML) === 0) $abs = substr($abs, strlen(PUBLIC_HTML)); return ltrim(str_replace('\\', '/', $abs), '/'); }
function cmsGhShouldMirror($rel) { return strpos($rel, 'admin/') !== 0 && $rel !== 'config.secret.php'; }
function cmsGhMirrorBytes($rel, $bytes, $msg = '', $cfg = null, $remoteSha = null) { global $MIRRORED; $MIRRORED[] = [$rel, $bytes, $msg]; return ['ok' => true]; }
$src = '';
foreach (['cmsGhBase', 'cmsGhLast', 'cmsGhReason', 'cmsGhExplain', 'cmsGhSkipCi', 'fourgeSitePut', 'fourgeMirrorLater'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// 1. the API base
putenv('FOURGE_GH_API_BASE');
chk(cmsGhBase() === 'https://api.github.com', 'cmsGhBase() is api.github.com by default');
putenv('FOURGE_GH_API_BASE=http://127.0.0.1:8934/');
chk(cmsGhBase() === 'http://127.0.0.1:8934', 'FOURGE_GH_API_BASE points the engine at a stub (trailing slash dropped)');
putenv('FOURGE_GH_API_BASE=not a url');
chk(cmsGhBase() === 'https://api.github.com', 'a FOURGE_GH_API_BASE that is not an http(s) URL is ignored');
putenv('FOURGE_GH_API_BASE');

// 2. [skip ci] on every mirror commit, exactly once
chk(cmsGhSkipCi('Fourge: update index.html') === 'Fourge: update index.html [skip ci]', 'cmsGhSkipCi appends [skip ci]');
chk(cmsGhSkipCi('Fourge: update index.html [skip ci]') === 'Fourge: update index.html [skip ci]', '…and never twice');
chk(cmsGhSkipCi('x [SKIP CI]') === 'x [SKIP CI]', '…case-insensitively');
chk(cmsGhSkipCi('') === 'Fourge: update [skip ci]', 'an empty message gets a default before the marker');

// 3. what GitHub's answer means, in one sentence
$last = function ($code, $message = '', $headers = [], $curl = '') { cmsGhLast(['code' => $code, 'message' => $message, 'headers' => $headers, 'curl' => $curl]); };
$last(401, 'Bad credentials');
chk(cmsGhReason(401) === 'bad_token', '401 → reason bad_token');
$e = cmsGhExplain('GitHub sync did not start');
chk(strpos($e, 'GitHub sync did not start: GitHub rejected the saved token (401 Bad credentials)') === 0 && strpos($e, 'Settings → GitHub Integration') !== false, '401 explained: the token was rejected, save a new one under Settings → GitHub Integration — ' . $e);
$last(403, 'API rate limit exceeded', ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string)gmmktime(14, 30, 0, 1, 1, 2030)]);
chk(cmsGhReason(403) === 'rate_limit', '403 with X-RateLimit-Remaining: 0 → reason rate_limit');
$e = cmsGhExplain('Sync');
chk(strpos($e, 'rate limit') !== false && strpos($e, 'resets at 14:30 UTC') !== false, 'the rate limit is explained with the reset time — ' . $e);
$last(403, 'Resource not accessible by personal access token', ['x-ratelimit-remaining' => '4000']);
chk(cmsGhReason(403) === 'forbidden', 'any other 403 → reason forbidden');
$e = cmsGhExplain('Sync');
chk(strpos($e, '403') !== false && strpos($e, 'Resource not accessible by personal access token') !== false && strpos($e, 'permission') !== false, 'a 403 carries GitHub’s own message and points at permissions — ' . $e);
$last(404, 'Not Found');
chk(cmsGhReason(404) === 'repo', '404 → reason repo');
$e = cmsGhExplain('Could not confirm the repo is private');
chk(strpos($e, '404') !== false && strpos($e, 'cannot see this repository') !== false && strpos($e, 'Resource owner') !== false, '404 explained: the token cannot see the repo; check owner/name and the fine-grained token’s Resource owner — ' . $e);
$last(0, '', [], 'Could not resolve host: api.github.com');
chk(cmsGhReason(0) === 'network', 'no HTTP answer → reason network');
chk(cmsGhExplain('Sync') === 'Sync: GitHub could not be reached (Could not resolve host: api.github.com).', 'a transport failure says "could not be reached" with the curl error — and ONLY then');
$last(500, 'boom');
chk(cmsGhReason(500) === 'github' && cmsGhExplain('Sync') === 'Sync: GitHub returned 500 — boom.', 'anything else: the status and GitHub’s message');
chk(cmsGhExplain('Lookup', 404, ['message' => 'Not Found'], null) === cmsGhExplain('Lookup', 404, null, null) && strpos(cmsGhExplain('Lookup', 404, ['message' => 'Not Found'], null), '404') !== false, 'explicit code/body arguments work too (for callers that still hold them)');

// 4. fourgeSitePut: write like file_put_contents, mirror only what changed
$MIRRORED = [];
$f = PUBLIC_HTML . '/x/a.txt';
$r = fourgeSitePut($f, "hello", 'Fourge: server rules');
chk($r === 5 && file_get_contents($f) === 'hello', 'fourgeSitePut writes the bytes and returns the count, like file_put_contents');
chk(count($MIRRORED) === 1 && $MIRRORED[0][0] === 'x/a.txt' && $MIRRORED[0][1] === 'hello' && $MIRRORED[0][2] === 'Fourge: server rules (x/a.txt)', 'a new file is mirrored at once, with the caller’s message and the path');
$r = fourgeSitePut($f, "hello", 'Fourge: server rules');
chk($r === 5 && count($MIRRORED) === 1, 'writing the same bytes again mirrors nothing (no GitHub call on a no-op save)');
$r = fourgeSitePut($f, "hello2");
chk($r === 6 && count($MIRRORED) === 2 && $MIRRORED[1][2] === 'Fourge: update x/a.txt', 'changed bytes are mirrored; without a message the commit says "Fourge: update <path>"');
$r = fourgeSitePut(PUBLIC_HTML . '/admin/gh-sync-state.php', "<?php exit; ?>\n{}", 'x');
chk($r !== false && count($MIRRORED) === 2, 'a file the mirror policy excludes (admin/) is written but never pushed');
$r = fourgeSitePut(PUBLIC_HTML . '/no-such-dir/b.txt', 'x');
chk($r === false && count($MIRRORED) === 2, 'a failed write returns false and mirrors nothing');

// tidy up
@unlink($f); @unlink(PUBLIC_HTML . '/admin/gh-sync-state.php'); @rmdir($tmp . '/x'); @rmdir($tmp . '/admin'); @rmdir($tmp);
echo "\n" . ($fail ? "SUITE FAILED ($fail failed, $pass passed)" : "all GitHub client assertions passed ($pass)") . "\n";
exit($fail ? 1 : 0);
