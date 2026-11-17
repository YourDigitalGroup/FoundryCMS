<?php
// tests/lib/markup_stub.php — a stand-in for MarkUp.io's API (https://api.markup.io/api/v2)
// for the dispatcher suite: the calls the MarkUp hub makes.
//   php -S 127.0.0.1:8938 -t <empty dir> tests/lib/markup_stub.php
// and point the engine at it with FOURGE_MARKUP_API_BASE=http://127.0.0.1:8938/api/v2.
// The key is "mk-e2e-key" (Bearer) with Markup-API-Version 2023-02-22; anything else is a 401.
// State (markups, threads, webhook registrations, mode) and a log of every request
// (method, path, query, body, whether the key was right) live in tests/.cache/markup_stub_state.json.
// Thread lists answer with summaries (no messages or elements), as a list may, so the
// hub has to read each thread; like MarkUp's API, a list asked for with a "resolved"
// filter in the query string is refused (400 "resolved must be a boolean value."). Test controls (no auth):
//   POST /__reset          — empty state, default mode
//   POST /__seed  {…}      — merge markups / threads (by id) into the state
//   POST /__mode  {…}      — merge into the mode: failResolve (n) answers the next n resolves with a 500;
//                            strictNumbers refuses limit/offset on a thread list as well
//   GET  /__state          — the whole state
//   GET  /files/<name>.png — a tiny PNG (attachments and screenshots point here)
$cache = dirname(__DIR__) . '/.cache'; @mkdir($cache, 0777, true);
$stateFile = "$cache/markup_stub_state.json";
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH)); parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw = (string)file_get_contents('php://input'); $body = $raw !== '' ? json_decode($raw, true) : null; if (!is_array($body)) $body = [];
function out($code, $data) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($data); exit; }
function fresh() { return ['markups' => [], 'threads' => [], 'hooks' => [], 'log' => [], 'n' => 0, 'mode' => ['failResolve' => 0]]; }
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
if (!is_array($state)) $state = fresh();
function save() { global $state, $stateFile; file_put_contents($stateFile, json_encode($state), LOCK_EX); }

if ($path === '/files/logo.png' || $path === '/files/shot.png') {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    exit;
}
if ($path === '/__reset') { $state = fresh(); save(); out(200, ['ok' => true]); }
if ($path === '/__state') out(200, $state);
if ($path === '/__mode') { $state['mode'] = array_merge($state['mode'], $body); save(); out(200, ['ok' => true]); }
if ($path === '/__seed') {
    foreach (['markups', 'threads'] as $k) foreach ((array)($body[$k] ?? []) as $x) if (is_array($x) && isset($x['id'])) $state[$k][(string)$x['id']] = array_merge($state[$k][(string)$x['id']] ?? [], $x);
    save(); out(200, ['ok' => true]);
}

$authOk = ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer mk-e2e-key' && ($_SERVER['HTTP_MARKUP_API_VERSION'] ?? '') === '2023-02-22';
$state['log'][] = ['method' => $method, 'path' => $path, 'query' => $q, 'body' => $body, 'auth' => $authOk];
save();
if (strpos($path, '/api/v2/') !== 0) out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'no route']]);
if (!$authOk) out(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']]);
$p = substr($path, strlen('/api/v2'));

if ($method === 'GET' && $p === '/workspace') out(200, ['data' => ['id' => 'ws_e2e', 'name' => 'E2E Agency', 'rootFolderId' => 'f_root', 'archiveFolderId' => null]]);

if ($method === 'GET' && $p === '/markups') {
    $all = array_values($state['markups']);
    $start = (int)($q['since'] ?? 0); $limit = max(1, (int)($q['limit'] ?? 25));
    $page = array_slice($all, $start, $limit);
    $more = $start + $limit < count($all);
    out(200, ['data' => ['data' => $page, 'hasMore' => $more, 'nextCursor' => $more ? (string)($start + $limit) : '', 'prevCursor' => '']]);
}
if ($method === 'GET' && preg_match('~^/markups/([^/]+)$~', $p, $m)) {
    if (!isset($state['markups'][$m[1]])) out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Markup not found']]);
    out(200, ['data' => $state['markups'][$m[1]]]);
}
if ($method === 'GET' && $p === '/threads') {
    // As MarkUp's API does: its "resolved" filter must be a true boolean, which no query string can carry.
    if (array_key_exists('resolved', $q)) out(400, ['error' => ['code' => 'BAD_REQUEST', 'message' => 'resolved must be a boolean value.']]);
    // mode strictNumbers: limit/offset refused the same way (in case MarkUp's API does that too)
    if (!empty($state['mode']['strictNumbers']) && (array_key_exists('limit', $q) || array_key_exists('offset', $q))) out(400, ['error' => ['code' => 'BAD_REQUEST', 'message' => 'limit must be a number conforming to the specified constraints.']]);
    $list = [];
    foreach ($state['threads'] as $t) {
        if ((string)($t['markupId'] ?? '') !== (string)($q['markupId'] ?? '')) continue;
        $s = $t; unset($s['messages'], $s['elements'], $s['parents'], $s['markupId']);   // a summary (resolved kept)
        $list[] = $s;
    }
    $off = (int)($q['offset'] ?? 0); $limit = max(1, (int)($q['limit'] ?? 50));
    out(200, ['data' => ['threads' => array_slice($list, $off, $limit), 'query' => ['resolved' => false], 'offset' => $off, 'total' => count($list), 'cursorId' => null]]);
}
if (preg_match('~^/threads/([^/]+)(/messages|/resolve|/unresolve)?$~', $p, $m)) {
    $id = $m[1]; $act = $m[2] ?? '';
    if (!isset($state['threads'][$id])) out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Thread not found']]);
    if ($method === 'GET' && $act === '') { $t = $state['threads'][$id]; unset($t['markupId']); out(200, ['data' => $t]); }
    if ($method === 'POST' && $act === '/messages') {
        $state['n']++;
        $msg = ['id' => 'msg_' . $state['n'], 'threadId' => $id, 'message' => (string)($body['content'] ?? ''), 'content' => (string)($body['content'] ?? ''),
                'user' => ['id' => 'u_agency', 'name' => 'Agency', 'email' => 'agency@example.com', 'guest' => false], 'attachments' => [], 'loomVideos' => [], 'createdAt' => time() * 1000];
        $state['threads'][$id]['messages'][] = $msg;
        save(); out(200, ['data' => $msg]);
    }
    if ($method === 'POST' && $act === '/resolve') {
        if ((int)$state['mode']['failResolve'] > 0) { $state['mode']['failResolve']--; save(); out(500, ['error' => ['code' => 'INTERNAL', 'message' => 'Something broke']]); }
        $state['threads'][$id]['resolved'] = true; save(); out(200, ['data' => ['threadId' => $id, 'modifiedAt' => time() * 1000]]);
    }
    if ($method === 'POST' && $act === '/unresolve') { $state['threads'][$id]['resolved'] = false; save(); out(200, ['data' => ['threadId' => $id, 'modifiedAt' => time() * 1000]]); }
}
if ($method === 'GET' && $p === '/webhook-registrations') out(200, ['data' => ['data' => array_values(array_filter($state['hooks'], function ($h) use ($q) { return $h['workspaceId'] === ($q['workspaceId'] ?? ''); }))]]);
if ($method === 'POST' && $p === '/webhook-registrations') {
    $state['n']++; $id = 'wh_' . $state['n'];
    $state['hooks'][$id] = ['id' => $id, 'url' => (string)($body['url'] ?? ''), 'workspaceId' => (string)($body['workspaceId'] ?? ''), 'eventTypes' => (array)($body['eventTypes'] ?? []), 'enabled' => !empty($body['enabled']), 'signingKey' => 'sk_' . $id];
    save(); out(200, ['data' => $state['hooks'][$id]]);
}
if ($method === 'POST' && preg_match('~^/webhook-registrations/([^/]+)$~', $p, $m)) {
    if (!isset($state['hooks'][$m[1]])) out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Registration not found']]);
    foreach (['url', 'eventTypes', 'enabled'] as $k) if (array_key_exists($k, $body)) $state['hooks'][$m[1]][$k] = $body[$k];
    save(); out(200, ['data' => $state['hooks'][$m[1]]]);
}
out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'no route: ' . $method . ' ' . $p]]);
