<?php
// MarkUp hub (1.14.159), the pure parts lifted out of admin/api.php: which page file an
// address means, how two addresses of one site compare (www., ports), which install is the
// hub, which addresses the hub will call (never its own network), MarkUp's statuses and
// timestamps, the hub's copy of a thread (no e-mail addresses) and what a change may hold.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (preg_match('~\}\s*$~', $line) && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$src = '';
foreach (['fourgeMarkupHostKey', 'fourgeMarkupHostPortKey', 'fourgeMarkupHubUrl', 'fourgeMarkupIsHub', 'fourgeMarkupMatchPage', 'fourgeMarkupUrlPath', 'fourgeMarkupOverridePath',
          'fourgeMarkupNormStatus', 'fourgeMarkupTs', 'fourgeMarkupJson', 'fourgeMarkupSlimThread', 'fourgeMarkupThreadSig', 'fourgeMarkupOpFields', 'fourgeMarkupCleanOps',
          'fourgeMarkupCleanGen', 'fourgeMarkupSafeTarget', 'fourgeMarkupData'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// 1. an address path → the page file
$pages = [['path' => 'index.html'], ['path' => 'about.html'], ['path' => 'services/index.html'], ['path' => 'services/cleanings.html'], ['path' => 'Contact-Us.html'], ['path' => 'blog.htm']];
$cases = ['/' => 'index.html', '' => 'index.html', '/index' => 'index.html', '/index.html' => 'index.html', '/about' => 'about.html', '/about/' => 'about.html', '/about.html' => 'about.html',
          '/services' => 'services/index.html', '/services/' => 'services/index.html', '/services/cleanings' => 'services/cleanings.html', '/services/cleanings/' => 'services/cleanings.html',
          '/contact-us' => 'Contact-Us.html', '//about' => 'about.html', '/blog' => 'blog.htm', '/Services/Cleanings' => 'services/cleanings.html', '/caf%C3%A9' => '', '/nope' => '', '/services/nope' => ''];
$bad = [];
foreach ($cases as $in => $want) { $got = fourgeMarkupMatchPage($in, $pages); if ($got !== $want) $bad[] = "$in → '$got' (want '$want')"; }
chk(!$bad, 'address paths find their page file: / and /index, with or without a trailing slash or .html, a folder\'s index.html, any letter case — ' . ($bad ? implode('; ', $bad) : count($cases) . ' cases'));
chk(fourgeMarkupMatchPage('/about', ['about.html', 'x.html']) === 'about.html', 'a plain list of paths works too');
chk(fourgeMarkupUrlPath('https://a.example/services/cleanings/?x=1#top') === '/services/cleanings/' && fourgeMarkupUrlPath('https://a.example') === '/' && fourgeMarkupUrlPath('https://a.example/caf%C3%A9') === '/café', 'the path of an address: query and fragment dropped, decoded');
chk(fourgeMarkupOverridePath('https://a.example/about/') === '/about' && fourgeMarkupOverridePath('https://a.example/') === '/' && fourgeMarkupOverridePath('https://a.example') === '/', 'a remembered page is keyed by its path without a trailing slash (/about and /about/ are one)');

// 2. two addresses of one site
chk(fourgeMarkupHostKey('WWW.Client.com:8080') === 'client.com' && fourgeMarkupHostKey('client.com') === 'client.com' && fourgeMarkupHostKey('dev.client.fourge.com') === 'dev.client.fourge.com', 'a host compares without www., port or letter case (a staging host stays itself)');
chk(fourgeMarkupHostPortKey('www.fourge.com', 443) === 'fourge.com' && fourgeMarkupHostPortKey('127.0.0.1', 8932) === '127.0.0.1:8932' && fourgeMarkupHostPortKey('fourge.com', 80) === 'fourge.com', '…and for one install the port counts, unless it is 80 or 443');

// 3. which install is the hub
putenv('FOURGE_MARKUP_HUB_URL');
$_SERVER['HTTP_HOST'] = 'www.fourge.com';
chk(fourgeMarkupHubUrl() === 'https://fourge.com' && fourgeMarkupIsHub(), 'the hub is fourge.com unless the server says otherwise (reached at www. too)');
$_SERVER['HTTP_HOST'] = 'client.com';
chk(!fourgeMarkupIsHub(), '…and no client site is the hub');
putenv('FOURGE_MARKUP_HUB_URL=http://127.0.0.1:8932/');
$_SERVER['HTTP_HOST'] = '127.0.0.1:8932';
$a = fourgeMarkupIsHub();
$_SERVER['HTTP_HOST'] = '127.0.0.1:8939';
chk($a && !fourgeMarkupIsHub() && fourgeMarkupHubUrl() === 'http://127.0.0.1:8932', 'FOURGE_MARKUP_HUB_URL moves the hub; on one machine the port tells two installs apart');
putenv('FOURGE_MARKUP_HUB_URL');

// 4. the addresses the hub calls
putenv('FOURGE_PROBE_ALLOW_LOCAL');
$refused = [];
foreach (['http://127.0.0.1/x', 'http://localhost/', 'http://10.0.0.5/', 'http://192.168.1.9/', 'http://169.254.169.254/latest/meta-data/', 'https://[::1]/', 'ftp://93.184.216.34/', 'https://93.184.216.34:8443/', 'http://intranet.local/', 'not a url', 'file:///etc/passwd'] as $u)
    if (fourgeMarkupSafeTarget($u) !== null) $refused[] = $u;
chk(!$refused, 'the hub never calls this server\'s own network, another port or another scheme (a comment can name any address) — ' . ($refused ? 'let through: ' . implode(', ', $refused) : 'all refused'));
chk(fourgeMarkupSafeTarget('https://93.184.216.34/admin/api.php') === ['https://93.184.216.34/admin/api.php', ''], '…a public address is fine');
putenv('FOURGE_PROBE_ALLOW_LOCAL=1');
chk(fourgeMarkupSafeTarget('http://127.0.0.1:8939/x') === ['http://127.0.0.1:8939/x', ''], '…the test harness may reach its local servers');
putenv('FOURGE_PROBE_ALLOW_LOCAL');

// 5. MarkUp's statuses and timestamps
chk(fourgeMarkupNormStatus('Ready for changes') === 'ready_for_changes' && fourgeMarkupNormStatus(' APPROVED ') === 'approved' && fourgeMarkupNormStatus('in-review!') === 'in_review', 'statuses compare as lower_case_words, however MarkUp spells them');
chk(fourgeMarkupTs(1763380000000) === 1763380000 && fourgeMarkupTs(1763380000) === 1763380000 && fourgeMarkupTs('2025-11-17T11:46:40Z') === 1763380000 && fourgeMarkupTs(null) === 0 && fourgeMarkupTs([]) === 0, 'timestamps: milliseconds, seconds or an ISO date');
chk(fourgeMarkupData(['data' => ['id' => 'x']]) === ['id' => 'x'] && fourgeMarkupData(['nope' => 1]) === [] && fourgeMarkupData(null) === [], 'MarkUp\'s {"data": …} envelope, and an answer without one');

// 6. the hub's copy of a thread
$t = ['id' => 'th1', 'number' => 3, 'resolved' => false, 'canonicalUrl' => '', 'originalUrl' => 'https://client.com/about',
      'elements' => [['path' => 'body > h1', 'offsetXPercentage' => 12.5, 'offsetYPercentage' => '40']], 'parents' => [['path' => ['body', 'main'], 'depth' => 1]],
      'textHighlight' => ['text' => 'Old heading', 'startOffset' => 0], 'viewModeId' => 'mobile', 'browserContext' => ['viewportWidth' => 390, 'viewportHeight' => 844, 'os' => 'iOS'],
      'screenshot' => ['url' => 'https://cdn.markup.io/s.png', 'width' => 390], 'user' => ['name' => 'Casey', 'email' => 'casey@client.com'], 'createdAt' => 1763380000000,
      'messages' => [
          ['id' => 'm1', 'message' => 'Change this heading', 'user' => ['name' => 'Casey', 'email' => 'casey@client.com', 'guest' => true], 'createdAt' => 1763380000000,
           'attachments' => [['id' => 'a1', 'url' => 'https://cdn.markup.io/a.png', 'mimeType' => 'image/png', 'originalFilename' => 'logo.png'], ['id' => 'a2']], 'loomVideos' => [['sharedUrl' => 'https://loom.com/x', 'title' => 'Walkthrough']]],
          ['id' => 'm2', 'message' => 'deleted one', 'deletedAt' => 1763380000500, 'user' => ['name' => 'Casey']],
          ['id' => 'm3', 'content' => 'Never mind, keep it', 'user' => ['name' => 'Casey']],
      ]];
$s = fourgeMarkupSlimThread($t);
chk($s['url'] === 'https://client.com/about' && $s['highlight'] === 'Old heading' && $s['viewMode'] === 'mobile' && $s['viewport']['w'] === 390 && $s['screenshot']['url'] === 'https://cdn.markup.io/s.png' && $s['at'] === 1763380000,
    'the copy keeps the address (canonical, else original), the selected text, the device and viewport, the screenshot');
chk(count($s['messages']) === 2 && $s['messages'][0]['text'] === 'Change this heading' && $s['messages'][1]['text'] === 'Never mind, keep it' && $s['messages'][0]['guest'] === true
    && count($s['messages'][0]['attachments']) === 1 && $s['messages'][0]['attachments'][0]['name'] === 'logo.png' && $s['messages'][0]['loom'][0]['url'] === 'https://loom.com/x',
    '…every message still there, in order (a later "never mind" included), with its attachments and Loom videos; deleted ones dropped');
chk(strpos(fourgeMarkupJson($s), '@') === false, '…and no e-mail address anywhere');
chk($s['elements'][0] === ['path' => 'body > h1', 'depth' => 0, 'x' => 12.5, 'y' => 40.0] && $s['parents'][0]['path'] === '["body","main"]', '…the element path as given (a list is kept as JSON) with its offsets');
$sig = fourgeMarkupThreadSig($s);
$t2 = $t; $t2['messages'][] = ['id' => 'm4', 'message' => 'Actually make it blue', 'user' => ['name' => 'Casey']];
$t3 = $t; $t3['screenshot']['url'] = 'https://cdn.markup.io/s2.png';
chk(preg_match('~^[a-f0-9]{16}$~', $sig) && fourgeMarkupThreadSig(fourgeMarkupSlimThread($t2)) !== $sig && fourgeMarkupThreadSig(fourgeMarkupSlimThread($t3)) === $sig, 'a new reply changes the thread\'s fingerprint (its change is made again); a new screenshot link doesn\'t');

// 7. what a stored change may hold
$g = fourgeMarkupCleanGen(['classification' => 'text_edit', 'summary' => str_repeat('x', 500), 'device' => 'phone', 'confidence' => 7, 'method' => 'magic',
    'ops' => [['field' => 'text', 'value' => 'Hi'], ['field' => 'onclick', 'value' => 'x'], ['field' => 'size', 'value' => 22], ['field' => 'html', 'value' => ['nested']], 'junk'],
    'block' => 'b"><script>', 'pageSha' => 'nothex', 'styleUndo' => [['cls' => 'fge-r4', 'dev' => 'mobile', 'prop' => 'font-size', 'before' => null], ['cls' => 'x', 'dev' => 'mobile', 'prop' => 'color']], 'extra' => 'dropped']);
chk($g['ops'] === [['field' => 'text', 'value' => 'Hi'], ['field' => 'size', 'value' => '22']] && strlen($g['summary']) === 300 && $g['device'] === 'all' && $g['confidence'] === 1.0 && $g['method'] === 'none'
    && $g['block'] === '' && $g['pageSha'] === '' && count($g['styleUndo']) === 1 && !isset($g['extra']),
    'a stored change keeps only the AI Markup fields, known values and sane lengths');
chk(fourgeMarkupOpFields() === ['text', 'html', 'size', 'weight', 'color', 'bgColor', 'align', 'lineHeight', 'padding', 'margin', 'radius', 'width', 'height', 'src', 'alt', 'imgHref'], 'the op fields are exactly the page editor\'s Markup fields');

echo "\n" . ($fail ? "MARKUP MAP SUITE FAILED ($fail failed, $pass passed)" : "all markup map assertions passed ($pass)") . "\n";
exit($fail ? 1 : 0);
