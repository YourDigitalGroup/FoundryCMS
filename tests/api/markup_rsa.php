<?php
// MarkUp's signatures without PHP's sodium extension (1.14.163), the real functions lifted out of admin/api.php
// with the storage around them stood in: a hub whose PHP has no sodium makes an RSA key (OpenSSL) and a client
// site whose PHP has none checks that signature. On fourge.com and its client sites (hosting without sodium) the
// hub made no key at all, every site answered "did not answer as a Fourge site" and each comment stayed Unmapped.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    // a one-line function (its braces balance on the line, a trailing comment or not) is the whole function
    if (substr_count($line, '{') > 0 && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
// stand-ins for the storage: one install's secret store and key/value table, and its database for the nonces
$KV = []; $SECRETS = []; $IS_HUB = false; $FETCH = '';
function fourgeMarkupKvDb() { return null; }
function fourgeMarkupKvGet($k, $d = null) { global $KV; return array_key_exists($k, $KV) ? $KV[$k] : $d; }
function fourgeMarkupKvSet($k, $v) { global $KV; if ($v === null) unset($KV[$k]); else $KV[$k] = $v; }
function fourgeGetSecret($pdo, $n) { global $SECRETS; return $SECRETS[$n] ?? null; }
function fourgeSetSecret($pdo, $n, $v, $by) { global $SECRETS; $SECRETS[$n] = $v; }
function fourgeMarkupIsHub() { global $IS_HUB; return $IS_HUB; }
function fourgeMarkupFetchHubKey() { global $FETCH, $KV; if (is_array($FETCH)) { foreach ($FETCH as $k => $v) $KV[$k] = $v; return 'fetched'; } return ''; }
function fourgeDb() { static $pdo = null; if (!$pdo) { $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return $pdo; }
$src = '';
foreach (['fourgeSodiumOn', 'fourgeOpensslSignOn', 'fourgeMarkupHubRsaKeys', 'fourgeMarkupHubKeys', 'fourgeMarkupHostKey', 'fourgeMarkupVerify', 'fourgeMarkupHubKeypair', 'fourgeMarkupHubRsaKeypair'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
// A request as fourgeMarkupSiteCall builds it: the message, then whichever signatures the hub could make.
function req($action, $payload, $host, $edSk, $rsaPem, $ts = null, $nonce = null) {
    $json = json_encode((object)$payload); $ts = $ts ?? time(); $nonce = $nonce ?? rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $msg = "fourge-markup-v1\n" . $action . "\n" . $host . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $json);
    $sig = $edSk ? base64_encode(sodium_crypto_sign_detached($msg, $edSk)) : '';
    $rs = ''; if ($rsaPem && openssl_sign($msg, $raw, $rsaPem, OPENSSL_ALGO_SHA256)) $rs = base64_encode($raw);
    return ['action' => $action, 'payload' => $json, 'host' => $host, 'ts' => $ts, 'nonce' => $nonce, 'sig' => $sig, 'sig_rs' => $rs];
}
$_SERVER['HTTP_HOST'] = 'client.example';

// ── the hub, its PHP without sodium ──
putenv('FOURGE_NO_SODIUM=1'); $IS_HUB = true;
chk(fourgeSodiumOn() === false && fourgeOpensslSignOn() === true, '(a host without sodium, with openssl — as fourge.com\'s)');
chk(fourgeMarkupHubKeypair(true) === null, 'the Ed25519 key can\'t be made there (this is where the hub used to stop: no key at all)');
$rk = fourgeMarkupHubRsaKeypair(true);
chk(is_array($rk) && preg_match('~^-----BEGIN (RSA )?PRIVATE KEY-----~', $SECRETS['markup_hub_rsa'] ?? '') && strpos($KV['hub_pk_rsa'] ?? '', '-----BEGIN PUBLIC KEY-----') === 0,
    'an RSA key is made instead: the private half in the encrypted secret store, the public half published (hub_pk_rsa)');
$again = fourgeMarkupHubRsaKeypair(true);
chk($again['pk'] === $rk['pk'] && $again['sk'] === $rk['sk'], '…made once and kept: asking again gives the same key');
$HUB_RSA_PUB = $KV['hub_pk_rsa']; $HUB_RSA_SK = $rk['sk'];

// ── a client site, its PHP without sodium either ──
$KV = []; $SECRETS = []; $IS_HUB = false; $FETCH = ['hub_pk_fetched_rsa' => $HUB_RSA_PUB];
$r = req('markup_hello', ['a' => 1], 'client.example', null, $HUB_RSA_SK);
chk($r['sig'] === '' && strlen(base64_decode($r['sig_rs'])) === 256, '(the hub\'s request carries only its RSA signature)');
$v = fourgeMarkupVerify($r, 'markup_hello');
chk($v === ['a' => 1] && ($KV['hub_pk_fetched_rsa'] ?? '') === $HUB_RSA_PUB, 'the client site fetches the hub\'s keys the first time and accepts the RSA-signed request');
$FETCH = '';
$v = fourgeMarkupVerify($r, 'markup_hello');
chk(is_array($v) && $v[0] === 401 && strpos($v[1], 'already used') !== false, '…the same request again is refused (each nonce once)');
$v = fourgeMarkupVerify(req('markup_hello', ['a' => 1], 'client.example', null, $HUB_RSA_SK), 'markup_hello');
chk($v === ['a' => 1], '…and the next request is checked with the key it now keeps, no fetch needed');
$t = req('markup_read', ['path' => 'a.html'], 'client.example', null, $HUB_RSA_SK); $t['payload'] = json_encode(['path' => 'admin/api.php']);
$v = fourgeMarkupVerify($t, 'markup_read');
chk(is_array($v) && $v[0] === 401 && strpos($v[1], 'does not match a trusted hub') !== false, 'a request changed after signing is refused');
$v = fourgeMarkupVerify(req('markup_read', [], 'client.example', null, $HUB_RSA_SK), 'markup_write');
chk(is_array($v) && $v[0] === 401, 'a request signed for one action, sent as another, is refused');
$v = fourgeMarkupVerify(req('markup_hello', [], 'other.example', null, $HUB_RSA_SK), 'markup_hello');
chk(is_array($v) && $v[0] === 401 && strpos($v[1], 'different site') !== false, 'a request signed for another site is refused');
$v = fourgeMarkupVerify(req('markup_hello', [], 'client.example', null, $HUB_RSA_SK, time() - 600), 'markup_hello');
chk(is_array($v) && $v[0] === 401 && strpos($v[1], 'too old') !== false, 'a request ten minutes old is refused');
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($other, $otherSk);
$v = fourgeMarkupVerify(req('markup_hello', [], 'client.example', null, $otherSk), 'markup_hello');
chk(is_array($v) && $v[0] === 401 && strpos($v[1], 'does not match a trusted hub') !== false, 'a request signed with any other RSA key is refused');
if (function_exists('sodium_crypto_sign_keypair')) {
    $ed = sodium_crypto_sign_keypair();
    $v = fourgeMarkupVerify(req('markup_hello', [], 'client.example', sodium_crypto_sign_secretkey($ed), null), 'markup_hello');
    chk(is_array($v) && $v[0] === 401 && $v[1] === 'Missing or malformed signature.', 'an Ed25519-only request means nothing to a site without sodium: refused, never a PHP error');
}
$KV = [];
$v = fourgeMarkupVerify(req('markup_hello', [], 'client.example', null, $HUB_RSA_SK), 'markup_hello');
chk(is_array($v) && $v[0] === 503, 'a site that could not fetch any hub key says it does not trust a hub yet (503)');

// ── with sodium: a request carrying both signatures is accepted on either key ──
putenv('FOURGE_NO_SODIUM');
if (function_exists('sodium_crypto_sign_keypair')) {
    $ed = sodium_crypto_sign_keypair(); $edPk = base64_encode(sodium_crypto_sign_publickey($ed));
    $KV = ['hub_pk_fetched' => $edPk];
    $v = fourgeMarkupVerify(req('markup_hello', ['x' => 2], 'client.example', sodium_crypto_sign_secretkey($ed), $HUB_RSA_SK), 'markup_hello');
    chk($v === ['x' => 2], 'a site with sodium checks the Ed25519 signature (the RSA one rides along unused)');
    $KV = ['hub_pk_fetched_rsa' => $HUB_RSA_PUB];
    $v = fourgeMarkupVerify(req('markup_hello', ['x' => 3], 'client.example', sodium_crypto_sign_secretkey($ed), $HUB_RSA_SK), 'markup_hello');
    chk($v === ['x' => 3], '…and one that only knows the hub\'s RSA key still accepts the request on that signature');
}

// ── the note on a comment the hub couldn't place says what to do (the real fourgeMarkupSiteFor, the call stood in) ──
$CALLS = []; $ANSWER = null;
function fourgeMarkupSites() { return []; }
function fourgeMarkupSiteSave($base, $hello, $hk) { return ['site' => $hk, 'base' => $base]; }
function fourgeMarkupSiteAt($origin, $hk) { return null; }
function fourgeMarkupSiteCall($base, $action, $payload, $timeout = 20) { global $CALLS, $ANSWER; $CALLS[] = $base; return $ANSWER; }
eval(lift($api, 'fourgeMarkupSiteFor'));
$place = function ($answer) { global $CALLS, $ANSWER; $CALLS = []; $ANSWER = $answer; $note = ''; $s = fourgeMarkupSiteFor('https://client.example/about', $note); return [$s, $note, count($CALLS)]; };
$old = "This server's PHP has no sodium extension, so it cannot check the hub's signature.";   // what a site before 1.14.163 answers on such a server
list($s, $note, $n) = $place([500, ['ok' => false, 'error' => $old], $old, 'https://client.example']);
chk($s === null && strpos($note, 'client.example runs a Fourge older than 1.14.163') === 0 && strpos($note, 'sign in to its admin once') !== false && $n === 1,
    'a site not yet updated, on a server without sodium: the Unmapped note says to sign in to that site once and pull again (not "did not answer as a Fourge site")');
list($s, $note, $n) = $place([401, ['ok' => false, 'error' => 'Missing or malformed signature.'], 'Missing or malformed signature.', 'https://client.example']);
chk($s === null && strpos($note, 'client.example cannot check the hub\'s signature yet: it needs Fourge 1.14.163 or later') === 0 && $n === 1,
    'a site before 1.14.163 with sodium, sent only the RSA signature (a hub without sodium): the note says to update it the same way');
list($s, $note, $n) = $place([401, ['ok' => false, 'error' => 'The signature does not match a trusted hub.'], 'The signature does not match a trusted hub.', 'https://client.example']);
chk($s === null && $note === 'client.example refused the hub\'s signature (The signature does not match a trusted hub.).', '…a signature the site checked and refused is still reported as before');
$neither = "This server's PHP has neither the sodium nor the openssl extension, so it cannot check the hub's signature.";
list($s, $note, $n) = $place([500, ['ok' => false, 'error' => $neither], $neither, 'https://client.example']);
chk($s === null && $note === 'client.example cannot check the hub\'s signature: ' . $neither, '…one updated but with neither extension says so in its own words');
list($s, $note, $n) = $place([0, null, 'The hub cannot sign its requests: this server\'s PHP could make neither an Ed25519 key (sodium extension) nor an RSA key (openssl extension).', '']);
chk($s === null && strpos($note, 'The hub cannot sign its requests') === 0 && $n === 1, 'a hub that cannot sign says so, asked once (not blamed on the site, not three addresses tried)');
list($s, $note, $n) = $place([0, null, 'No answer from client.example (timeout).', '']);
chk($s === null && strpos($note, 'did not answer as a Fourge site') !== false && $n === 2, 'a site that does not answer is still tried at https and http, and noted as before');

echo "\n" . ($fail ? "markup_rsa: $fail FAILED, $pass passed\n" : "all markup_rsa assertions passed ($pass)\n");
exit($fail ? 1 : 0);
