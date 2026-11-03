<?php
// tests/lib/ghl_stub.php — a stand-in for GoHighLevel's API (services.leadconnectorhq.com)
// for the dispatcher suite: just the calls Fourge Chat makes.
//   php -S 127.0.0.1:8935 -t <empty dir> tests/lib/ghl_stub.php
// and point the engine at it with FOURGE_GHL_API_BASE=http://127.0.0.1:8935 (cmsGhlBase()).
// State (contacts, conversations, messages, Conversation AI agents) and a log of every
// request (method, path, query, Version header, body) live in tests/.cache/ghl_stub_state.json.
// The token is "pit-e2e"; anything else is a 401. Test controls (no auth):
//   POST /__reset          — empty state, default mode
//   POST /__mode  {…}      — merge into the mode:
//        aiV3Only        Conversation AI refuses Version 2021-04-15 ("version" in the message)
//        requireProvider inbound messages need conversationProviderId === providerId
//        providerId      the id requireProvider expects
//        botReplies      (default true) the main Live Chat bot answers inbound chat in auto-pilot
//   POST /__seed  {…}      — merge agents / conversations / messages into the state
//   GET  /__state          — the whole state
$cache = dirname(__DIR__) . '/.cache'; @mkdir($cache, 0777, true);
$stateFile = "$cache/ghl_stub_state.json";
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH)); parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw = (string)file_get_contents('php://input'); $body = $raw !== '' ? json_decode($raw, true) : null; if (!is_array($body)) $body = [];
header('Content-Type: application/json; charset=utf-8');
function out($code, $data) { http_response_code($code); echo json_encode($data); exit; }
function fresh() { return ['contacts' => [], 'conversations' => [], 'messages' => [], 'agents' => [], 'log' => [], 'n' => 0,
                           'mode' => ['aiV3Only' => false, 'requireProvider' => false, 'providerId' => '', 'botReplies' => true]]; }
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
if (!is_array($state)) $state = fresh();
function save() { global $state, $stateFile; file_put_contents($stateFile, json_encode($state), LOCK_EX); }
function nid($p) { global $state; $state['n']++; return $p . '_' . $state['n']; }
// GoHighLevel stamps milliseconds; keep every new message strictly later than the last one.
function stamp($plus = 0) { global $state; $t = max(microtime(true), (float)($state['lastT'] ?? 0) + 0.001) + $plus; $state['lastT'] = $t; return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000)); }

if ($path === '/__reset') { $state = fresh(); save(); out(200, ['ok' => true]); }
if ($path === '/__mode')  { $state['mode'] = array_merge($state['mode'], $body); save(); out(200, $state['mode']); }
if ($path === '/__seed')  { foreach (['agents', 'conversations', 'messages', 'contacts'] as $k) if (isset($body[$k])) $state[$k] = array_merge($state[$k], $body[$k]); save(); out(200, ['ok' => true]); }
if ($path === '/__state') out(200, $state);

$ver = $_SERVER['HTTP_VERSION'] ?? '';
$state['log'][] = ['m' => $method, 'p' => $path, 'q' => $q, 'v' => $ver, 'body' => $body];
save();
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer pit-e2e') out(401, ['statusCode' => 401, 'message' => 'Invalid token: access token is invalid']);
if ($ver === '') out(400, ['statusCode' => 400, 'message' => 'Version header is required']);

// ── Contacts ──
if ($method === 'POST' && $path === '/contacts/upsert') {
    if (empty($body['locationId'])) out(422, ['message' => ['locationId should not be empty']]);
    $email = strtolower((string)($body['email'] ?? ''));
    $id = 'ct_' . substr(md5($email), 0, 10);
    $isNew = !isset($state['contacts'][$id]);
    $c = array_merge($state['contacts'][$id] ?? ['id' => $id, 'tags' => []], $body, ['id' => $id]);
    $state['contacts'][$id] = $c; save();
    out(200, ['new' => $isNew, 'contact' => $c]);
}
if ($method === 'POST' && preg_match('~^/contacts/([^/]+)/tags$~', $path, $m)) {
    if (!isset($state['contacts'][$m[1]])) out(404, ['message' => 'Contact not found']);
    $state['contacts'][$m[1]]['tags'] = array_values(array_unique(array_merge($state['contacts'][$m[1]]['tags'] ?? [], (array)($body['tags'] ?? [])))); save();
    out(201, ['tags' => $state['contacts'][$m[1]]['tags']]);
}

// ── Conversations ──
function lastType($cid) { global $state; $ms = $state['messages'][$cid] ?? []; return $ms ? (string)end($ms)['messageType'] : ''; }
if ($method === 'GET' && $path === '/conversations/search') {
    $out = [];
    foreach ($state['conversations'] as $c) {
        if (!empty($q['contactId']) && $c['contactId'] !== $q['contactId']) continue;
        if (!empty($q['lastMessageType']) && lastType($c['id']) !== $q['lastMessageType']) continue;
        $out[] = $c + ['lastMessageType' => lastType($c['id'])];
    }
    out(200, ['conversations' => array_slice($out, 0, (int)($q['limit'] ?? 20)), 'total' => count($out)]);
}
if ($method === 'POST' && $path === '/conversations/') {
    foreach ($state['conversations'] as $c) if ($c['contactId'] === ($body['contactId'] ?? '')) out(400, ['statusCode' => 400, 'message' => 'Conversation already exists', 'conversationId' => $c['id']]);
    $id = nid('cv');
    $state['conversations'][$id] = ['id' => $id, 'contactId' => (string)($body['contactId'] ?? ''), 'locationId' => (string)($body['locationId'] ?? ''), 'dateAdded' => stamp()];
    save();
    out(201, ['success' => true, 'conversation' => $state['conversations'][$id]]);
}
if ($method === 'POST' && $path === '/conversations/messages/inbound') {
    if (!in_array($ver, ['2021-04-15', 'v3'], true)) out(400, ['message' => 'Unsupported version']);
    $cid = (string)($body['conversationId'] ?? '');
    if (!isset($state['conversations'][$cid])) out(400, ['message' => 'Conversation not found']);
    if (empty($body['type'])) out(422, ['message' => ['type should not be empty']]);
    $prov = (string)($body['conversationProviderId'] ?? '');
    if (!empty($state['mode']['requireProvider'])) {
        if ($prov === '') out(422, ['statusCode' => 422, 'message' => ['conversationProviderId should not be empty']]);
        if ($prov !== $state['mode']['providerId']) out(400, ['statusCode' => 400, 'message' => 'Invalid conversation provider']);
    }
    $type = 'TYPE_' . strtoupper((string)$body['type']);
    $id = nid('msg');
    $state['messages'][$cid][] = ['id' => $id, 'direction' => (string)($body['direction'] ?? 'outbound'), 'messageType' => $type, 'body' => (string)($body['message'] ?? ''),
                                  'dateAdded' => stamp(), 'conversationProviderId' => $prov, 'contactId' => $state['conversations'][$cid]['contactId']];
    // The main bot on Live Chat answers inbound chat on its own (auto-pilot), as Conversation AI would.
    if (!empty($state['mode']['botReplies']) && ($body['direction'] ?? '') === 'inbound' && $type === 'TYPE_LIVE_CHAT') {
        foreach ($state['agents'] as $a) {
            if (!empty($a['isPrimary']) && in_array('Live_Chat', (array)($a['channels'] ?? []), true) && ($a['mode'] ?? '') === 'auto-pilot') {
                $state['messages'][$cid][] = ['id' => nid('msg'), 'direction' => 'outbound', 'messageType' => 'TYPE_LIVE_CHAT', 'body' => 'Bot: ' . (string)($body['message'] ?? ''),
                                              'dateAdded' => stamp(1), 'source' => 'ai', 'contactId' => $state['conversations'][$cid]['contactId']];
                break;
            }
        }
    }
    save();
    out(200, ['success' => true, 'conversationId' => $cid, 'messageId' => $id, 'message' => 'Message created']);
}
if ($method === 'GET' && preg_match('~^/conversations/([^/]+)/messages$~', $path, $m)) {
    if (!isset($state['conversations'][$m[1]])) out(404, ['message' => 'Conversation not found']);
    $ms = array_reverse($state['messages'][$m[1]] ?? []);   // newest first, as GoHighLevel answers
    out(200, ['messages' => ['lastMessageId' => $ms ? $ms[0]['id'] : null, 'nextPage' => false, 'messages' => array_slice($ms, 0, (int)($q['limit'] ?? 20))]]);
}

// ── Conversation AI agents ──
if (strpos($path, '/conversation-ai/') === 0) {
    if (!empty($state['mode']['aiV3Only']) && $ver !== 'v3') out(400, ['statusCode' => 400, 'message' => 'Version header is invalid for this endpoint']);
    if ($method === 'GET' && $path === '/conversation-ai/agents/search') out(200, ['agents' => array_values($state['agents']), 'totalCount' => count($state['agents']), 'count' => count($state['agents'])]);
    if ($method === 'POST' && $path === '/conversation-ai/agents') {
        foreach (['name', 'personality', 'goal', 'instructions'] as $k) if (trim((string)($body[$k] ?? '')) === '') out(422, ['message' => [$k . ' should not be empty']]);
        $id = nid('ag');
        $state['agents'][$id] = ['id' => $id, 'actions' => []] + $body; save();
        out(201, $state['agents'][$id]);
    }
    if (preg_match('~^/conversation-ai/agents/([^/]+)$~', $path, $m)) {
        if (!isset($state['agents'][$m[1]])) out(404, ['message' => 'Agent not found']);
        if ($method === 'GET') out(200, $state['agents'][$m[1]]);
        if ($method === 'PUT') {
            if (!isset($body['autoPilotMaxMessages'])) out(422, ['message' => ['autoPilotMaxMessages must be a number']]);
            // A full replace, the way a PUT may behave: whatever isn't sent is gone.
            $state['agents'][$m[1]] = ['id' => $m[1], 'actions' => $state['agents'][$m[1]]['actions'] ?? []] + $body; save();
            out(200, $state['agents'][$m[1]]);
        }
    }
}
out(404, ['message' => 'Cannot ' . $method . ' ' . $path]);
