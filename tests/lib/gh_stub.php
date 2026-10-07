<?php
// tests/lib/gh_stub.php — a stand-in for api.github.com for the regression suites.
//   php -S 127.0.0.1:8934 -t <empty dir> tests/lib/gh_stub.php
// and point the engine at it with FOURGE_GH_API_BASE=http://127.0.0.1:8934 (cmsGhBase()).
// Mode, read from tests/.cache/gh_stub_mode on every request:
//   ok (default) — a private repo the token can push to: /user (with the token-expiry header
//                  GitHub sends for fine-grained tokens), the repo, the Git Data API (refs,
//                  commits, trees, blobs) and the Contents API, all backed by one JSON state file
//   401          — every request answers 401 "Bad credentials" (an expired or revoked token)
//   ratelimit    — 403 with X-RateLimit-Remaining: 0
//   notfound     — 404 "Not Found" for everything (a repo the token cannot see)
// Every request is appended to tests/.cache/gh_stub_log.jsonl (method, path, query, commit
// message, body size) so a suite can prove how many calls the engine made and what it said in
// the commit messages. The repo lives in tests/.cache/gh_stub_state.json.
$cache = dirname(__DIR__) . '/.cache'; @mkdir($cache, 0777, true);
$modeFile = "$cache/gh_stub_mode"; $logFile = "$cache/gh_stub_log.jsonl"; $stateFile = "$cache/gh_stub_state.json";
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH)); parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw = (string)file_get_contents('php://input'); $body = $raw !== '' ? json_decode($raw, true) : null; if (!is_array($body)) $body = [];
file_put_contents($logFile, json_encode(['m' => $method, 'p' => $path, 'q' => $q, 'msg' => $body['message'] ?? null, 'bytes' => strlen($raw)]) . "\n", FILE_APPEND | LOCK_EX);
$mode = is_file($modeFile) ? trim((string)file_get_contents($modeFile)) : 'ok';
$EXP = gmdate('Y-m-d H:i:s', time() + 30 * 86400) . ' UTC';   // GitHub's format: "2026-11-06 20:44:45 UTC"
header('Content-Type: application/json; charset=utf-8');
header('X-RateLimit-Limit: 5000'); header('X-RateLimit-Remaining: ' . ($mode === 'ratelimit' ? '0' : '4999')); header('X-RateLimit-Reset: ' . (time() + 1800));
function out($code, $data, $hdrs = []) { http_response_code($code); foreach ($hdrs as $k => $v) header("$k: $v"); echo json_encode($data); exit; }
if ($mode === '401') out(401, ['message' => 'Bad credentials', 'documentation_url' => 'https://docs.github.com/rest']);
if ($mode === 'ratelimit') out(403, ['message' => 'API rate limit exceeded for user ID 1.']);
if ($mode === 'notfound') out(404, ['message' => 'Not Found']);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth, 'Bearer ') !== 0 || trim(substr($auth, 7)) === '') out(401, ['message' => 'Requires authentication']);
$tokHdrs = ['GitHub-Authentication-Token-Expiration' => $EXP, 'X-OAuth-Scopes' => 'repo'];
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
if (!is_array($state)) $state = ['blobs' => [], 'trees' => [], 'commits' => [], 'refs' => []];
function save() { global $state, $stateFile; file_put_contents($stateFile, json_encode($state), LOCK_EX); }
function blobSha($bytes) { return sha1('blob ' . strlen($bytes) . "\0" . $bytes); }
function newTree($map) { global $state; ksort($map); $sha = sha1('tree ' . json_encode($map)); $state['trees'][$sha] = $map; return $sha; }
function newCommit($tree, $parents, $message) { global $state; $sha = sha1('commit ' . $tree . json_encode($parents) . $message . count($state['commits'])); $state['commits'][$sha] = ['tree' => $tree, 'parents' => array_values($parents), 'message' => $message, 'n' => count($state['commits']) + 1]; return $sha; }
function headMap($branch) { global $state; $h = $state['refs'][$branch] ?? null; if (!$h) return null; return $state['trees'][$state['commits'][$h]['tree']] ?? []; }
if ($method === 'GET' && $path === '/user') out(200, ['login' => 'stub-user', 'id' => 1], $tokHdrs);
if (!preg_match('~^/repos/([^/]+)/([^/]+)(/.*)?$~', $path, $m)) out(404, ['message' => 'stub: no route for ' . $method . ' ' . $path]);
$repo = $m[1] . '/' . $m[2]; $rest = $m[3] ?? '';
if ($rest === '' || $rest === '/') {
    if ($method === 'GET') out(200, ['full_name' => $repo, 'private' => true, 'default_branch' => 'main', 'permissions' => ['admin' => true, 'push' => true, 'pull' => true]], $tokHdrs);
    if ($method === 'PATCH') out(200, ['full_name' => $repo, 'private' => !empty($body['private'])]);
}
if ($method === 'GET' && preg_match('~^/git/ref/heads/(.+)$~', $rest, $mm)) {
    $b = $mm[1]; if (empty($state['refs'][$b])) out(404, ['message' => 'Not Found']);
    out(200, ['ref' => 'refs/heads/' . $b, 'object' => ['type' => 'commit', 'sha' => $state['refs'][$b]]]);
}
if ($method === 'GET' && preg_match('~^/git/commits/([0-9a-f]+)$~', $rest, $mm)) {
    $c = $state['commits'][$mm[1]] ?? null; if (!$c) out(404, ['message' => 'Not Found']);
    out(200, ['sha' => $mm[1], 'message' => $c['message'], 'tree' => ['sha' => $c['tree']], 'parents' => array_map(function ($p) { return ['sha' => $p]; }, $c['parents'])]);
}
if ($method === 'GET' && preg_match('~^/git/trees/([^/]+)$~', $rest, $mm)) {
    $k = $mm[1]; $map = null; $sha = $k;
    if (isset($state['trees'][$k])) $map = $state['trees'][$k];
    elseif (isset($state['commits'][$k])) { $sha = $state['commits'][$k]['tree']; $map = $state['trees'][$sha] ?? []; }
    elseif (isset($state['refs'][$k])) { $sha = $state['commits'][$state['refs'][$k]]['tree']; $map = $state['trees'][$sha] ?? []; }
    if ($map === null) out(empty($state['commits']) ? 409 : 404, ['message' => empty($state['commits']) ? 'Git Repository is empty.' : 'Not Found']);
    $tree = []; foreach ($map as $p => $s) $tree[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => $s, 'size' => strlen(base64_decode($state['blobs'][$s] ?? ''))];
    out(200, ['sha' => $sha, 'tree' => $tree, 'truncated' => false]);
}
if ($method === 'POST' && $rest === '/git/blobs') {
    $bytes = (($body['encoding'] ?? 'utf-8') === 'base64') ? base64_decode((string)($body['content'] ?? ''), true) : (string)($body['content'] ?? '');
    if ($bytes === false) out(422, ['message' => 'content is not valid base64']);
    $sha = blobSha($bytes); $state['blobs'][$sha] = base64_encode($bytes); save();
    out(201, ['sha' => $sha, 'url' => "/repos/$repo/git/blobs/$sha"]);
}
if ($method === 'POST' && $rest === '/git/trees') {
    $map = [];
    if (!empty($body['base_tree'])) { if (!isset($state['trees'][$body['base_tree']])) out(422, ['message' => 'base_tree is not a valid tree']); $map = $state['trees'][$body['base_tree']]; }
    foreach ((array)($body['tree'] ?? []) as $e) {
        $p = (string)($e['path'] ?? ''); if ($p === '') out(422, ['message' => 'tree.path is required']);
        if (!array_key_exists('sha', $e) || $e['sha'] === null) { unset($map[$p]); continue; }
        if (!isset($state['blobs'][$e['sha']])) out(422, ['message' => 'tree.sha ' . $e['sha'] . ' is not a valid blob']);
        $map[$p] = $e['sha'];
    }
    $sha = newTree($map); save(); out(201, ['sha' => $sha]);
}
if ($method === 'POST' && $rest === '/git/commits') {
    if (empty($body['tree']) || !isset($state['trees'][$body['tree']])) out(422, ['message' => 'tree is not a valid tree']);
    $sha = newCommit($body['tree'], (array)($body['parents'] ?? []), (string)($body['message'] ?? '')); save();
    out(201, ['sha' => $sha, 'tree' => ['sha' => $body['tree']], 'message' => $body['message'] ?? '']);
}
if ($method === 'PATCH' && preg_match('~^/git/refs/heads/(.+)$~', $rest, $mm)) {
    $b = $mm[1]; $new = (string)($body['sha'] ?? ''); if (!isset($state['commits'][$new])) out(422, ['message' => 'Object does not exist']);
    $cur = $state['refs'][$b] ?? null;
    if ($cur && empty($body['force']) && !in_array($cur, $state['commits'][$new]['parents'], true)) out(422, ['message' => 'Update is not a fast forward']);
    $state['refs'][$b] = $new; save(); out(200, ['ref' => 'refs/heads/' . $b, 'object' => ['type' => 'commit', 'sha' => $new]]);
}
if ($method === 'POST' && $rest === '/git/refs') {
    $ref = (string)($body['ref'] ?? ''); $b = preg_replace('~^refs/heads/~', '', $ref); $new = (string)($body['sha'] ?? '');
    if (!isset($state['commits'][$new])) out(422, ['message' => 'Object does not exist']);
    if (isset($state['refs'][$b])) out(422, ['message' => 'Reference already exists']);
    $state['refs'][$b] = $new; save(); out(201, ['ref' => $ref, 'object' => ['type' => 'commit', 'sha' => $new]]);
}
if (preg_match('~^/contents(/.*)?$~', $rest, $mm)) {
    $p = ltrim((string)($mm[1] ?? ''), '/'); $branch = (string)($q['ref'] ?? $body['branch'] ?? 'main');
    $map = headMap($branch) ?? [];
    if ($method === 'GET') {
        if ($p !== '' && isset($map[$p])) { $bytes = base64_decode($state['blobs'][$map[$p]] ?? ''); out(200, ['type' => 'file', 'path' => $p, 'name' => basename($p), 'sha' => $map[$p], 'size' => strlen($bytes), 'encoding' => 'base64', 'content' => chunk_split(base64_encode($bytes), 60, "\n")]); }
        $prefix = $p === '' ? '' : $p . '/'; $list = []; $seen = [];
        foreach ($map as $fp => $s) {
            if ($prefix !== '' && strpos($fp, $prefix) !== 0) continue;
            $tail = substr($fp, strlen($prefix)); $seg = explode('/', $tail)[0]; if (isset($seen[$seg])) continue; $seen[$seg] = 1;
            $isFile = ($tail === $seg); $list[] = ['type' => $isFile ? 'file' : 'dir', 'path' => $prefix . $seg, 'name' => $seg, 'sha' => $isFile ? $s : sha1($prefix . $seg)];
        }
        if (!$list) out(404, ['message' => 'Not Found']);
        out(200, $list);
    }
    if ($method === 'PUT') {
        if ($p === '') out(404, ['message' => 'Not Found']);
        $bytes = base64_decode((string)($body['content'] ?? ''), true); if ($bytes === false) out(422, ['message' => 'content is not valid base64']);
        $exists = isset($map[$p]);
        if ($exists && empty($body['sha'])) out(422, ['message' => "Invalid request.\n\n\"sha\" wasn't supplied."]);
        if ($exists && $body['sha'] !== $map[$p]) out(409, ['message' => $p . ' does not match ' . $body['sha']]);
        if (!$exists && !empty($body['sha'])) out(422, ['message' => $p . ' does not exist']);
        $sha = blobSha($bytes); $state['blobs'][$sha] = base64_encode($bytes); $map[$p] = $sha;
        $tree = newTree($map); $parent = $state['refs'][$branch] ?? null;
        $commit = newCommit($tree, $parent ? [$parent] : [], (string)($body['message'] ?? '')); $state['refs'][$branch] = $commit; save();
        out($exists ? 200 : 201, ['content' => ['path' => $p, 'sha' => $sha], 'commit' => ['sha' => $commit, 'message' => $body['message'] ?? '']]);
    }
    if ($method === 'DELETE') {
        if ($p === '' || !isset($map[$p])) out(404, ['message' => 'Not Found']);
        if (($body['sha'] ?? '') !== $map[$p]) out(409, ['message' => $p . ' does not match ' . ($body['sha'] ?? '')]);
        unset($map[$p]); $tree = newTree($map); $commit = newCommit($tree, [$state['refs'][$branch]], (string)($body['message'] ?? '')); $state['refs'][$branch] = $commit; save();
        out(200, ['content' => null, 'commit' => ['sha' => $commit, 'message' => $body['message'] ?? '']]);
    }
}
out(404, ['message' => 'stub: no route for ' . $method . ' ' . $path]);
