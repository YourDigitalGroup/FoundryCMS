<?php
// The private AI cost log (1.14.165), the real functions lifted out of admin/api.php with the database's location
// and the MarkUp hub's settings stood in: what an answer costs at the list prices, one CSV line per answer in a
// month's file beside the database, today's and this month's running totals (the agency's day and month), a cell a
// spreadsheet won't run as a formula, the folder's own deny-all rule, and several writers at once.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (substr_count($line, '{') > 0 && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";   // a one-line function
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$KV = [];      // the hub's settings (digest_tz); null: a site with no MarkUp table, where reading one throws
function fourgeMarkupKvGet($k, $d = null) { global $KV; if ($KV === null) throw new RuntimeException('no such table: markup_kv'); return array_key_exists($k, $KV) ? $KV[$k] : $d; }
$DBP = '';     // fourgeDbPath(): the log folder is beside the database
function fourgeDbPath() { global $DBP; return $DBP; }
preg_match('~\nconst FOURGE_GH_SKIP_DIRS = [^\n]+;~', $api, $gm); eval($gm[0]);   // what GitHub mirroring skips
$src = '';
foreach (['cmsGhSkipDir', 'cmsGhShouldMirror', 'fourgeAiPrices', 'fourgeAiWebSearchPrice', 'fourgeAiPriceOf', 'fourgeAiCost', 'fourgeAiLogDir', 'fourgeAiLogTz', 'fourgeAiLogGuard', 'fourgeAiLogPath', 'fourgeAiLogCell', 'fourgeAiCostLog'] as $fn) $src .= lift($api, $fn);
eval($src);
date_default_timezone_set('UTC');

// A writer for the "several at once" check below: php ai_costs.php --writer <db path> <lines> <when>
if (($argv[1] ?? '') === '--writer') {
    $DBP = $argv[2];
    for ($i = 0; $i < (int)$argv[3]; $i++) fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'writer ' . getmypid(), 'line ' . $i, (int)$argv[4]);
    exit(0);
}

$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
$at = function ($s) { return (new DateTime($s, new DateTimeZone('UTC')))->getTimestamp(); };
$near = function ($a, $b) { return $a !== null && abs($a - $b) < 1e-9; };
$tmp = sys_get_temp_dir() . '/fourge-aicosts-' . getmypid(); @mkdir($tmp . '/admin', 0777, true);
$rows = function ($file) { $out = []; if (!is_file($file)) return $out; $h = fopen($file, 'r'); while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) $out[] = $r; fclose($h); return $out; };
$HEADER = ['when', 'site', 'feature', 'model', 'input tokens', 'output tokens', 'cache write tokens', 'cache read tokens', 'web searches', 'cost USD', 'today USD', 'month USD', 'who', 'what for'];

// ── what an answer costs ──
$c = fourgeAiCost('claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5]);
chk($near($c[0], 0.000175) && array_slice($c, 1) === [10, 5, 0, 0, 0], 'Opus 4.8 (MarkUp, the page editor): $5 in / $25 out a million tokens — 10 in + 5 out is $0.000175');
chk($near(fourgeAiCost('claude-sonnet-4-6', ['input_tokens' => 1000000, 'output_tokens' => 1000000])[0], 18.0), 'Sonnet 4.6 (SEO, landing pages): a million in and a million out is $3 + $15');
chk($near(fourgeAiCost('claude-opus-4-8', ['input_tokens' => 0, 'cache_creation_input_tokens' => 1000000, 'cache_read_input_tokens' => 1000000])[0], 6.75), 'cache writes ($6.25) and cache reads ($0.50) at their own prices');
$c = fourgeAiCost('claude-sonnet-4-6', ['input_tokens' => 0, 'output_tokens' => 0, 'server_tool_use' => ['web_search_requests' => 3]]);
chk($near($c[0], 0.03) && $c[5] === 3, 'web searches the model runs: a cent each ($10 a thousand)');
chk($near(fourgeAiCost('claude-haiku-4-5-20251001', ['input_tokens' => 1000000])[0], 1.0) && fourgeAiPriceOf(' Claude-Haiku-4-5 ') === fourgeAiPriceOf('claude-haiku-4-5-20251001'),
    'a dated id (the daily auto-fix\'s claude-haiku-4-5-20251001) is priced as its model; case and spaces aside');
$c = fourgeAiCost('stub', ['input_tokens' => 10, 'output_tokens' => 5]);
chk($c[0] === null && $c[1] === 10 && $c[2] === 5 && fourgeAiPriceOf('claude-opus-4-8-turbo') === null, 'a model with no price here: no cost (not a guess), its tokens still counted');
$models = [];
foreach (['admin/api.php', 'admin/index.html'] as $f) { preg_match_all('~["\'`](claude-(?:opus|sonnet|haiku)[a-z0-9-]*)["\'`]~', (string)file_get_contents($root . '/' . $f), $m); $models = array_merge($models, $m[1]); }
$models = array_values(array_unique($models)); $unpriced = array_values(array_filter($models, function ($m) { return fourgeAiPriceOf($m) === null; }));
chk(count($models) >= 3 && $unpriced === [], 'every model Fourge asks for has a price (' . implode(', ', $models) . ')' . ($unpriced ? ' — no price: ' . implode(', ', $unpriced) : ''));

// ── a cell ──
chk(fourgeAiLogCell('=HYPERLINK("http://evil.example","x")') === "'=HYPERLINK(\"http://evil.example\",\"x\")" && fourgeAiLogCell('+1 555') === "'+1 555" && fourgeAiLogCell('-2') === "'-2" && fourgeAiLogCell('@SUM(A1)') === "'@SUM(A1)",
    'a cell starting = + - @ gets a leading quote: a spreadsheet shows it rather than running it');
chk(fourgeAiLogCell("one\ntwo\r\nthree\tfour\x00") === 'one two three four' && fourgeAiLogCell('MarkUp item 7: a.example/index.html') === 'MarkUp item 7: a.example/index.html', 'one line: line breaks and tabs become spaces; anything else is kept as is');
$long = fourgeAiLogCell(str_repeat('é', 300));
chk(mb_strlen($long) === 200 && mb_substr($long, -1) === '…', 'a long cell is cut to its length with an ellipsis (characters, not bytes)');

// ── the log ──
$DBP = $tmp . '/admin/fourge.db'; $logs = $tmp . '/admin/logs'; $nov = $logs . '/ai-costs-2026-11.csv'; $dec = $logs . '/ai-costs-2026-12.csv';
chk(fourgeAiCostLog('markup', 'claude-opus-4-8', null, 'Pat', 'x', $at('2026-11-20 14:50')) === false && fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 0, 'output_tokens' => 0], 'Pat', 'x', $at('2026-11-20 14:50')) === false && !is_dir($logs),
    'no usage, or none spent: no line (and no folder)');
$KV = ['digest_tz' => 'America/Chicago']; $_SERVER['HTTP_HOST'] = 'fourge.example';
$ok = fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 12000, 'output_tokens' => 1500], 'Pat', 'MarkUp item 7: blaylock.example/projects.html', $at('2026-11-20 14:50'));
$r = $rows($nov);
chk($ok === true && count($r) === 2 && $r[0] === $HEADER, 'the month\'s file is made beside the database (admin/logs/ai-costs-2026-11.csv) with a header line');
chk(($r[1] ?? []) === ['2026-11-20 08:50:00 CST', 'fourge.example', 'markup', 'claude-opus-4-8', '12000', '1500', '0', '0', '0', '0.097500', '0.097500', '0.097500', 'Pat', 'MarkUp item 7: blaylock.example/projects.html'],
    'a line: when (the agency\'s time zone, the MarkUp email\'s), the site, the feature, the model, the tokens, the cost, today and this month so far, who, what for — ' . json_encode($r[1] ?? null));
$ht = (string)@file_get_contents($logs . '/.htaccess');
chk(strpos($ht, 'Require all denied') !== false && strpos($ht, 'Deny from all') !== false && (fileperms($nov) & 0777) === 0640 && (fileperms($logs) & 0007) === 0,
    'the folder refuses the web on its own (Apache 2.4 and 2.2 rules), the file is 0640, the folder closed to others');
fourgeAiCostLog('seo', 'claude-sonnet-4-6', ['input_tokens' => 2000, 'output_tokens' => 400], 'Pat', 'services/pavers.html', $at('2026-11-20 20:00'));
fourgeAiCostLog('auto-fix', 'claude-haiku-4-5-20251001', ['input_tokens' => 1000, 'output_tokens' => 100], 'Fourge (automatic)', 'alt text: pavers', $at('2026-11-21 03:00'));
$r = $rows($nov);
chk(array_slice($r[2], 9, 3) === ['0.012000', '0.109500', '0.109500'] && array_slice($r[3], 9, 3) === ['0.001500', '0.111000', '0.111000'] && $r[3][0] === '2026-11-20 21:00:00 CST',
    'the totals run on: 3 AM UTC on the 21st is still the 20th (9 PM) in Chicago, so "today" carries on');
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-11-21 15:00'));
fourgeAiCostLog('other', 'stub', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-11-21 16:00'));
$r = $rows($nov);
chk(array_slice($r[4], 9, 3) === ['0.000175', '0.000175', '0.111175'], 'a new day in Chicago: today starts again, the month carries on (six decimals: a cheap answer still adds up) — ' . json_encode(array_slice($r[4], 9, 3)));
chk(array_slice($r[5], 3, 9) === ['stub', '10', '5', '0', '0', '0', '', '0.000175', '0.111175'], 'a model with no price: its tokens, an empty cost, the totals unchanged');
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-12-01 05:00'));
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-12-01 07:00'));
$r = $rows($nov); $d = $rows($dec);
chk(count($r) === 7 && $r[6][0] === '2026-11-30 23:00:00 CST' && array_slice($r[6], 9, 3) === ['0.000175', '0.000175', '0.111350'] && count($d) === 2 && $d[0] === $HEADER && $d[1][0] === '2026-12-01 01:00:00 CST' && array_slice($d[1], 9, 3) === ['0.000175', '0.000175', '0.000175'],
    'the month is the agency\'s too: 5 AM UTC on December 1 is November 30 in Chicago; at 1 AM there, a new file with its own totals');
file_put_contents($dec, str_replace("\n", "\r\n", (string)file_get_contents($dec)));   // opened and saved in Excel
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-12-01 08:00'));
$d = $rows($dec);
chk(count($d) === 3 && array_slice($d[2], 9, 3) === ['0.000175', '0.000350', '0.000350'], 'a file saved again with Windows line endings (Excel) still carries its totals');
unset($_SERVER['HTTP_HOST']);
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], '+1 555 0100', "=HYPERLINK(\"http://evil.example\",\"click\")\nsecond line", $at('2026-12-01 09:00'));
fourgeAiCostLog('page-editor', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', 'Fix "hours", please', $at('2026-12-01 10:00'));
$d = $rows($dec);
chk(count($d) === 5 && count($d[3]) === 14 && $d[3][1] === 'server' && $d[3][12] === "'+1 555 0100" && $d[3][13] === "'=HYPERLINK(\"http://evil.example\",\"click\") second line" && $d[4][13] === 'Fix "hours", please',
    'what a person typed stays in its cell: a formula shown, not run; a line break doesn\'t start a line; quotes and commas round-trip; no web request: the site is "server"');
file_put_contents($logs . '/.htaccess', "Allow from all\n");
fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-12-01 11:00'));
chk(strpos((string)file_get_contents($logs . '/.htaccess'), 'Require all denied') !== false, 'the folder\'s rule is put back if it is changed');
// …and admin/.htaccess refuses it as well: the copy shipped, and the one the sign-in writes (fourgeWriteAdminHtaccess)
preg_match('~\nfunction fourgeWriteAdminHtaccess\(\).*?<FilesMatch "([^"]+)">~s', $api, $w); preg_match('~<FilesMatch "([^"]+)">~', (string)file_get_contents($root . '/admin/.htaccess'), $s);
$denies = function ($re, $f) { return $re !== '' && preg_match('~' . str_replace('~', '\~', $re) . '~', $f) === 1; };
$both = function ($f) use ($denies, $w, $s) { return $denies($w[1] ?? '', $f) && $denies($s[1] ?? '', $f); };
chk($both('logs/ai-costs-2026-11.csv') && $both('fourge.db') && $both('fourge.db-wal') && !$denies($w[1] ?? '', 'index.html') && !$denies($s[1] ?? '', 'api.php') && !$denies($s[1] ?? '', 'version.json'),
    'admin/.htaccess, shipped and as the sign-in writes it, refuses the cost log like the database (and still serves the admin)');

// ── the admin's file actions (read_file, write_file, delete_file, upload) can't reach it ──
@symlink($logs, $tmp . '/a-link');
$in = [$nov, $logs, $logs . '/', $tmp . '/admin/logs/../logs/ai-costs-2026-11.csv', $tmp . '/admin/./logs//ai-costs-2026-11.csv', $tmp . '/admin/x/../logs/ai-costs-2026-11.csv', $logs . '/new-folder/notes.txt', $logs . '/.htaccess', $tmp . '/a-link/ai-costs-2026-11.csv'];
$out = [$DBP, $tmp . '/admin/logs-old/ai-costs-2026-11.csv', $tmp . '/admin/logsx', $tmp . '/admin', $tmp . '/admin/logs/../index.html', $tmp . '/logs/ai-costs-2026-11.csv'];
$missed = array_values(array_filter($in, function ($x) { return !fourgeAiLogPath($x); })); $caught = array_values(array_filter($out, 'fourgeAiLogPath'));
chk($missed === [] && $caught === [], 'the log folder and anything in it, however the path is written (.., ., //, a folder not made yet, a symlink); nothing else, not even a folder whose name starts the same'
    . ($missed ? ' — missed: ' . implode(', ', $missed) : '') . ($caught ? ' — caught: ' . implode(', ', $caught) : ''));

chk(!cmsGhShouldMirror('admin/logs/ai-costs-2026-11.csv') && !cmsGhShouldMirror('data/logs/ai-costs-2026-11.csv') && !cmsGhShouldMirror('private/logs/AI-COSTS-2026-11.CSV')
    && cmsGhShouldMirror('data/prices-2026-11.csv') && cmsGhShouldMirror('reports/ai-costs-summary.csv') && cmsGhShouldMirror('index.html'),
    'GitHub mirroring never takes the log, wherever db_path puts it (a site\'s other CSV files still go)');

// ── where the agency's time zone isn't known ──
$DBP = $tmp . '/site2/admin/fourge.db'; @mkdir(dirname($DBP), 0777, true);
$KV = null;   // a site with no MarkUp table: reading the hub's time zone throws
fourgeAiCostLog('auto-fix', 'claude-haiku-4-5-20251001', ['input_tokens' => 1000, 'output_tokens' => 100], 'Fourge (automatic)', 'FAQs: home', $at('2026-11-20 14:50'));
$r = $rows($tmp . '/site2/admin/logs/ai-costs-2026-11.csv');
chk(($r[1][0] ?? '') === '2026-11-20 14:50:00 UTC', 'a site that isn\'t the MarkUp hub logs in the server\'s time zone');
$KV = ['digest_tz' => 'Mars/Olympus'];
chk(fourgeAiLogTz() === 'UTC', 'a time zone PHP doesn\'t know is not used');

// ── never in the way ──
$DBP = $tmp . '/a-file/fourge.db'; file_put_contents($tmp . '/a-file', 'x');
$threw = false; try { $res = fourgeAiCostLog('markup', 'claude-opus-4-8', ['input_tokens' => 10, 'output_tokens' => 5], 'Pat', '', $at('2026-11-20 14:50')); } catch (Throwable $e) { $threw = true; }
chk(!$threw && $res === false, 'a folder it can\'t make: no line, no error (the answer is still returned)');

// ── several at once ──
$DBP = $tmp . '/busy/admin/fourge.db'; @mkdir(dirname($DBP), 0777, true); $KV = [];
$procs = [];
for ($i = 0; $i < 4; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, '--writer', $DBP, '25', (string)$at('2026-11-20 14:50')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
foreach ($procs as $p) proc_close($p);
$r = $rows($tmp . '/busy/admin/logs/ai-costs-2026-11.csv');
$whole = count($r) > 1 && count(array_filter(array_slice($r, 1), function ($l) { return count($l) === 14; })) === count($r) - 1;
chk(count($r) === 101 && $r[0] === $HEADER && $whole && end($r)[11] === '0.017500', 'four writers at once, 25 answers each: one header, 100 whole lines, and the month adds up to 100 × $0.000175 — ' . count($r) . ' lines, month ' . (end($r)[11] ?? '?'));

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "ai_costs: $fail FAILED, $pass passed\n" : "all ai_costs assertions passed ($pass)\n");
exit($fail ? 1 : 0);
