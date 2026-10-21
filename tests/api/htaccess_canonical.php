<?php
// Parked domains → the Website URL (1.14.144): the pure core of the canonical-host block,
// lifted out of admin/api.php. Covers: the Website URL as a redirect target, the rule text
// (every other host → the address, path kept, 301; non-production shapes and .well-known
// never redirected), insertion at the very top of the file (before force-HTTPS and the
// clean-URL rewrites), replacement for a new address, exact removal, idempotency, and the
// other .htaccess writers (cache headers, clean URLs, password gate) leaving it alone.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (preg_match('~\}\s*$~', $line) && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$tmp = sys_get_temp_dir() . '/fourge-canon-' . getmypid(); @mkdir($tmp, 0777, true);
define('PUBLIC_HTML', $tmp);
function fourgeSitePut($abs, $bytes, $msg = '') { return @file_put_contents($abs, (string)$bytes); }
$src = '';
foreach (['fourgeIsNonProdHost','fourgeIsDevLinkHost','fourgeCanonicalHostMarkers','fourgeCanonicalHostTarget','fourgeCanonicalHostBlock','fourgeCanonicalHostCurrent','fourgeCanonicalHostApplyText',
          'fourgeCacheHtMarkers','fourgeCacheHtaccessBlock','fourgeCacheHtaccessFindUnmarked','fourgeCacheHtaccessApply','fourgeWriteCacheHtaccess','fourgeWriteProtectHtaccess','fourgeWriteCleanUrlHtaccess'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
list($BEGIN, $END) = fourgeCanonicalHostMarkers();

// 1. the Website URL as a target
$t = fourgeCanonicalHostTarget('https://www.Example.com/');
chk($t && $t['host'] === 'www.example.com' && $t['url'] === 'https://www.example.com' && $t['hostPort'] === 'www.example.com', 'https://www.Example.com/ → https://www.example.com (lower-case host, no path)');
$t = fourgeCanonicalHostTarget('example.com');
chk($t && $t['url'] === 'https://example.com', 'a bare domain is taken as https://');
$t = fourgeCanonicalHostTarget('http://127.0.0.1:8932');
chk($t && $t['hostPort'] === '127.0.0.1:8932' && $t['url'] === 'http://127.0.0.1:8932' && $t['host'] === '127.0.0.1', 'host:port is kept for a non-standard port');
chk(fourgeCanonicalHostTarget('https://example.com:443')['url'] === 'https://example.com', 'the standard port is dropped');
chk(fourgeCanonicalHostTarget('') === null && fourgeCanonicalHostTarget('   ') === null, 'an empty Website URL is no target');
chk(fourgeCanonicalHostTarget('not a url!') === null && fourgeCanonicalHostTarget('https://exa mple.com') === null && fourgeCanonicalHostTarget('https://a..b') === null, 'junk is no target');

// 1b. what is never a public address nor a redirect target (1.14.145)
foreach (['dev.client.fourge.com', 'client.fourge.com', 'dev.example.com', 'staging-site.example.com', 'stage2.example.com', 'testing.example.com', 'localhost', 'localhost:8080', '127.0.0.1', '10.0.0.5:8932', '[::1]', 'intranet', 'site.local', 'site.test', 'preview.pages.dev', 'app.netlify.app', 'x.vercel.app', 'abc.ngrok-free.app', 'x.trycloudflare.com', ''] as $h) chk(fourgeIsNonProdHost($h) === true, 'non-production: ' . ($h === '' ? '(empty)' : $h));
foreach (['www.example.com', 'example.com', 'securityservicesms.com', 'shop.example.co.uk', 'developer-tools.com', 'testimonials.example.com', 'www.devonshire.com', 'new.example.com', 'devlinks.com', 'knutsonfamilydental.com'] as $h) chk(fourgeIsNonProdHost($h) === false, 'production: ' . $h);
foreach (['knutsondentistry.devlink9.info', 'devlink9.info', 'site.devlink.info', 'client.devlink12.com'] as $h) chk(fourgeIsNonProdHost($h) === true, 'the agency’s devlink addresses are development links: ' . $h);
// 1c. a development LINK (a dev host with a real name) vs merely local
foreach (['knutsondentistry.devlink9.info', 'dev.example.com', 'client.fourge.com', 'staging-site.example.com'] as $h) chk(fourgeIsDevLinkHost($h) === true, 'a development link: ' . $h);
foreach (['localhost', '127.0.0.1', '10.0.0.5', '[::1]', 'site.local', 'site.test', 'intranet', 'www.example.com', 'knutsonfamilydental.com'] as $h) chk(fourgeIsDevLinkHost($h) === false, 'not a development link: ' . $h);

// 2. the block
$T = fourgeCanonicalHostTarget('https://www.example.com');
$B = fourgeCanonicalHostBlock($T);
chk(strpos($B, $BEGIN) === 0 && substr($B, -strlen($END)) === $END, 'the block starts with the BEGIN marker and ends with the END marker');
chk(strpos($B, '<IfModule mod_rewrite.c>') !== false && strpos($B, '</IfModule>') !== false, 'the rules sit inside <IfModule mod_rewrite.c>');
chk(strpos($B, 'RewriteCond %{HTTP_HOST} !^www\.example\.com(:[0-9]+)?$ [NC]') !== false, 'the site’s own host (any port) is never redirected');
chk(strpos($B, 'RewriteRule ^ https://www.example.com%{REQUEST_URI} [R=301,L]') !== false, 'every other host → the Website URL, path kept (query follows on its own), 301');
foreach (['!(^|\.)devlink[0-9]*\.[a-z]+(:[0-9]+)?$ [NC]', '!^(dev|staging|stage|stg|test|testing|qa|uat|preview|beta|demo|sandbox)[0-9]*[.\-] [NC]', '!\.(fourge\.com|local|test|localhost|internal|lan|pages\.dev|netlify\.app|vercel\.app|herokuapp\.com|github\.io|ngrok\.io|ngrok-free\.app|trycloudflare\.com)(:[0-9]+)?$ [NC]', '!^localhost [NC]', '!^[0-9.]+(:[0-9]+)?$', '!^\['] as $c) {
    chk(strpos($B, 'RewriteCond %{HTTP_HOST} ' . $c) !== false, 'non-production hosts are never redirected: ' . $c);
}
chk(strpos($B, 'RewriteCond %{REQUEST_URI} !^/\.well-known/ [NC]') !== false, 'certificate challenges under /.well-known/ are never redirected');
chk(fourgeCanonicalHostCurrent($B) === 'https://www.example.com', 'fourgeCanonicalHostCurrent reads the address back out of the block');
chk(substr_count($B, 'RewriteRule ^ ') === 1 && strpos($B, 'development link') === false, 'without remembered development links there is one rule');
// 2b. the site’s own development links forward too, by name, ahead of the generic rule (1.14.147)
$BD = fourgeCanonicalHostBlock($T, ['knutsondentistry.devlink9.info', 'Dev.Example.com:8080', 'www.example.com', '', 'bad host!']);
chk(strpos($BD, 'RewriteCond %{HTTP_HOST} ^(knutsondentistry\.devlink9\.info|dev\.example\.com)(:[0-9]+)?$ [NC]') !== false, 'the remembered dev links are named (lower-cased, port dropped; the target itself, blanks and junk left out)');
chk(substr_count($BD, 'RewriteRule ^ https://www.example.com%{REQUEST_URI} [R=301,L]') === 2 && strpos($BD, 'development link') < strpos($BD, '!^www\.example\.com'), 'their rule comes first, then the generic one — both to the Website URL');
chk(preg_match('~\^\(knutsondentistry[^\n]*\n  RewriteCond %\{REQUEST_URI\} !\^/\\\.well-known/ \[NC\]\n  RewriteRule~', $BD) === 1, 'the dev-link rule spares /.well-known/ too');
chk(fourgeCanonicalHostCurrent($BD) === 'https://www.example.com' && fourgeCanonicalHostApplyText(fourgeCanonicalHostApplyText('', $B), $BD) !== null, 'a block with dev links replaces one without (the file changes when a link is remembered)');
chk(fourgeCanonicalHostCurrent("RewriteEngine On\n") === '', '…and finds none in a file without the block');

// 3. placement: the very top, before force-HTTPS and the clean-URL rewrites
$tpl = file_get_contents($root . '/.htaccess');
$out = fourgeCanonicalHostApplyText($tpl, $B);
chk(strpos($out, $BEGIN) === 0, 'in the template .htaccess the block goes at the very top');
chk(strpos($out, $END . "\n\n# Fourge — force HTTPS") !== false, '…directly above the force-HTTPS block, so a parked http:// address lands on the right https:// one in a single hop');
chk(substr($out, strlen($B) + 2) === $tpl, 'the rest of the file is untouched, byte for byte');
chk(fourgeCanonicalHostApplyText($out, $B) === null, 'applying the same block again changes nothing');
chk(fourgeCanonicalHostApplyText($tpl, null) === null, 'removing a block that is not there changes nothing');
chk(fourgeCanonicalHostApplyText($out, null) === $tpl, 'removing the block restores the original file exactly');
$B2 = fourgeCanonicalHostBlock(fourgeCanonicalHostTarget('https://example.net'));
$out2 = fourgeCanonicalHostApplyText($out, $B2);
chk(substr_count($out2, $BEGIN) === 1 && fourgeCanonicalHostCurrent($out2) === 'https://example.net' && strpos($out2, 'www.example.com') === false, 'a new Website URL replaces the block in place (one block, new address)');
chk(fourgeCanonicalHostApplyText('', $B) === $B . "\n", 'an empty .htaccess gets just the block');
chk(fourgeCanonicalHostApplyText("\n\nRewriteEngine On\n", $B) === $B . "\n\nRewriteEngine On\n", 'leading blank lines are not kept above the block');

// 4. the other writers leave it alone
$ht = PUBLIC_HTML . '/.htaccess';
file_put_contents($ht, $out);
$before = file_get_contents($ht); fourgeWriteCacheHtaccess();
chk(file_get_contents($ht) === $before && strpos($before, $BEGIN) === 0, 'the cache-headers writer (its block already in place) changes nothing and leaves this block on top');
chk(fourgeWriteCleanUrlHtaccess() && strpos(file_get_contents($ht), $BEGIN) === 0 && substr_count(file_get_contents($ht), $BEGIN) === 1, 'the clean-URL writer adds its block and leaves this one on top');
chk(fourgeWriteProtectHtaccess(['secret.html']) && strpos(file_get_contents($ht), $BEGIN) === 0 && substr_count(file_get_contents($ht), $BEGIN) === 1 && strpos(file_get_contents($ht), '_fourge_gate.php?p=secret.html') !== false, 'the password gate ON keeps it on top');
chk(fourgeWriteProtectHtaccess([]) && strpos(file_get_contents($ht), $BEGIN) === 0 && substr_count(file_get_contents($ht), $BEGIN) === 1, 'the password gate OFF keeps it too');
chk(fourgeCanonicalHostApplyText(file_get_contents($ht), null) !== null && strpos(fourgeCanonicalHostApplyText(file_get_contents($ht), null), $BEGIN) === false, 'and the block can still be taken out cleanly afterwards');

@unlink($ht); @unlink(PUBLIC_HTML . '/_fourge_gate.php'); @rmdir($tmp);
echo "\n" . ($fail ? "SUITE FAILED ($fail failed, $pass passed)" : "all canonical host assertions passed ($pass)") . "\n";
exit($fail ? 1 : 0);
