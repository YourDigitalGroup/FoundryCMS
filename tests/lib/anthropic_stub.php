<?php
// tests/lib/anthropic_stub.php — a stand-in for Anthropic's Messages API for the dispatcher suite.
//   php -S 127.0.0.1:8936 -t <empty dir> tests/lib/anthropic_stub.php
// and point the engine at it with FOURGE_ANTHROPIC_API_BASE=http://127.0.0.1:8936 (foundryAnthropicBase()).
// Mode, read from tests/.cache/anthropic_stub_mode on every request:
//   ok (default) — 200 with one text block
//   overloaded   — 529 overloaded_error, the answer Anthropic gives when it is busy
// Every request is counted in tests/.cache/anthropic_stub_count.
$cache = dirname(__DIR__) . '/.cache'; @mkdir($cache, 0777, true);
$mode = is_file("$cache/anthropic_stub_mode") ? trim((string)file_get_contents("$cache/anthropic_stub_mode")) : 'ok';
$n = (int)@file_get_contents("$cache/anthropic_stub_count"); file_put_contents("$cache/anthropic_stub_count", (string)($n + 1), LOCK_EX);
header('Content-Type: application/json');
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/v1/messages' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(404); echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'no route']]); exit; }
if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '') { http_response_code(401); echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'x-api-key header is required']]); exit; }
if ($mode === 'overloaded') { http_response_code(529); echo json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]); exit; }
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
echo json_encode(['id' => 'msg_stub_' . ($n + 1), 'type' => 'message', 'role' => 'assistant', 'model' => (string)($body['model'] ?? ''),
    'content' => [['type' => 'text', 'text' => '{"title":"Stub answer"}']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
