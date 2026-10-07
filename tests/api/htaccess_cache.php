<?php
// Managed browser-caching block (1.14.140): the pure core and the writer, lifted out of
// admin/api.php so they run without the dispatcher. Covers: fresh file, template parity,
// a hand-written unmarked block (what renewalsf had) swapped in place with its heading,
// bare FilesMatch blocks, a stale marked block replaced, idempotency (second run writes
// nothing), managed blocks that mention Cache-Control left alone, and the other .htaccess
// writers (clean URLs, password gate on/off) leaving the block intact.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $re = '~\nfunction ' . preg_quote($name, '~') . '\(.*?\n}\n~s';
    if (!preg_match($re, $api, $m)) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    return $m[0];
}
$tmp = sys_get_temp_dir() . '/fourge-htcache-' . getmypid(); @mkdir($tmp, 0777, true);
define('PUBLIC_HTML', $tmp);
// 1.14.141: the writers mirror to GitHub through fourgeSitePut (covered by gh_client.php); here it is just the write.
function fourgeSitePut($abs, $bytes, $msg = '') { return @file_put_contents($abs, (string)$bytes); }
$src = '';
foreach (['fourgeCacheHtMarkers','fourgeCacheHtaccessBlock','fourgeCacheHtaccessFindUnmarked','fourgeCacheHtaccessApply','fourgeWriteCacheHtaccess','fourgeWriteProtectHtaccess','fourgeWriteCleanUrlHtaccess'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
list($BEGIN, $END) = fourgeCacheHtMarkers();
$BLOCK = fourgeCacheHtaccessBlock();
$count = function ($t, $needle) { return substr_count($t, $needle); };

// 1. the block itself
chk(strpos($BLOCK, $BEGIN) === 0 && substr($BLOCK, -strlen($END)) === $END, 'the block starts with the BEGIN marker and ends with the END marker');
chk(preg_match('~<IfModule mod_headers\.c>\s*<FilesMatch "\\\\\.\(html\?\|css\|js\|json\)\$">\s*Header set Cache-Control "no-cache"\s*</FilesMatch>\s*<FilesMatch "\\\\\.\(jpe\?g\|png\|gif\|webp\|svg\|ico\|woff2\?\)\$">\s*Header set Cache-Control "public, max-age=86400"\s*</FilesMatch>\s*</IfModule>~', $BLOCK) === 1, 'rules: html/css/js/json → no-cache, images/fonts → public, max-age=86400, all inside <IfModule mod_headers.c>');

// 2. template parity: the repo's .htaccess carries exactly this block
$tpl = file_get_contents($root . '/.htaccess');
chk(strpos($tpl, $BLOCK) !== false, 'the new-site template .htaccess carries the identical block (template and engine stay in sync)');
chk($count($tpl, $BEGIN) === 1 && fourgeCacheHtaccessApply($tpl) === null, 'running the upgrade on the template changes nothing (already exactly in place)');

// 3. fresh / empty file → appended
$out = fourgeCacheHtaccessApply('');
chk($out === $BLOCK . "\n", 'an empty .htaccess gets just the block');
$plain = "RewriteEngine On\nRewriteRule ^old$ /new [R=301,L]\n";
$out = fourgeCacheHtaccessApply($plain);
chk(strpos($out, $plain) === 0 && substr_count($out, $BEGIN) === 1 && substr($out, -strlen($END) - 1) === $END . "\n", 'a file without Fourge blocks keeps its content and gets the block appended');

// 4. next to the managed clean-URL block
$cleanUrls = "# BEGIN Fourge Clean URLs\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n</IfModule>\n# END Fourge Clean URLs";
$site = "# BEGIN Fourge Default Headers\n<IfModule mod_headers.c>\n  Header always set X-Frame-Options \"SAMEORIGIN\"\n</IfModule>\n# END Fourge Default Headers\n\n" . $cleanUrls . "\n\n# BEGIN Fourge Protected Pages\n# END Fourge Protected Pages\n";
$out = fourgeCacheHtaccessApply($site);
chk(strpos($out, $cleanUrls . "\n\n" . $BLOCK . "\n\n# BEGIN Fourge Protected Pages") !== false && $count($out, $BEGIN) === 1, 'on a live site the block lands right after the clean-URL block, before the password-gate block');
chk(strpos($out, "# BEGIN Fourge Default Headers") === 0 && strpos($out, 'X-Frame-Options') !== false, '…and every other managed block is untouched');

// 5. renewalsf: a hand-written block without markers, with its own heading → swapped in place
$hand = "# Browser caching — added by hand 2026-10-01\n# Revalidate pages, cache images a day\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.(html?|css|js|json)$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n  <FilesMatch \"\\.(jpe?g|png|gif|webp|svg|ico|woff2?)$\">\n    Header set Cache-Control \"public, max-age=86400\"\n  </FilesMatch>\n</IfModule>";
$renewal = "# Fourge — force HTTPS\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteCond %{HTTPS} off\n  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>\n\n" . $hand . "\n\n" . $cleanUrls . "\n";
$out = fourgeCacheHtaccessApply($renewal);
chk($out === str_replace($hand, $BLOCK, $renewal), 'an unmarked hand-written cache block is swapped for the marked version in place, heading included, nothing else moves');
chk($count($out, 'Header set Cache-Control') === 2 && $count($out, '<IfModule mod_headers.c>') === 1, '…so there is exactly one cache policy afterwards (no second copy)');
chk(fourgeCacheHtaccessApply($out) === null, '…and a second run changes nothing');
// unmarked block with different values (an older hand policy) is still recognised and replaced
$older = str_replace(['"no-cache"', 'max-age=86400'], ['"max-age=300"', 'max-age=604800'], $hand);
$out = fourgeCacheHtaccessApply("A\n\n" . $older . "\n\nB\n");
chk($out === "A\n\n" . $BLOCK . "\n\nB\n", 'a hand-written block with other values is recognised by what it does (Cache-Control for these file types) and replaced');
// bare FilesMatch blocks (no IfModule), two in a row with a comment between
$bare = "<FilesMatch \"\\.(css|js)$\">\n  Header set Cache-Control \"max-age=31536000\"\n</FilesMatch>\n# images\n<FilesMatch \"\\.(png|jpg)$\">\n  Header set Cache-Control \"max-age=86400\"\n</FilesMatch>";
$out = fourgeCacheHtaccessApply("Options -Indexes\n\n" . $bare . "\n\n" . $cleanUrls . "\n");
chk($out === "Options -Indexes\n\n" . $BLOCK . "\n\n" . $cleanUrls . "\n", 'bare FilesMatch cache blocks (no IfModule) are replaced as one run');

// 6. managed blocks that mention Cache-Control are not "unmarked" and stay
$pkg = "# BEGIN Fourge Package Headers\n<IfModule mod_headers.c>\n  Header always set Cache-Control \"no-store\"\n</IfModule>\n# END Fourge Package Headers\n";
$out = fourgeCacheHtaccessApply($pkg);
chk(strpos($out, $pkg) === 0 && $count($out, $BEGIN) === 1, 'a Fourge-managed block that happens to set Cache-Control is left alone and the cache block is added separately');

// 7. a stale marked block is replaced; a marked block that matches is left byte for byte
$stale = str_replace('max-age=86400', 'max-age=3600', $BLOCK);
$out = fourgeCacheHtaccessApply("X\n\n" . $stale . "\n\nY\n");
chk($out === "X\n\n" . $BLOCK . "\n\nY\n", 'an outdated marked block is replaced in place');
chk(fourgeCacheHtaccessApply("X\n\n" . $BLOCK . "\n\nY\n") === null, 'a current marked block → null (nothing to write)');

// 8. the writer on disk: idempotent, and the other writers leave it alone
$ht = PUBLIC_HTML . '/.htaccess';
@unlink($ht);
chk(fourgeWriteCacheHtaccess() && file_get_contents($ht) === $BLOCK . "\n", 'fourgeWriteCacheHtaccess() creates the file with the block');
$m1 = md5_file($ht); $t1 = filemtime($ht); touch($ht, time() - 100); clearstatcache();
chk(fourgeWriteCacheHtaccess() && md5_file($ht) === $m1 && filemtime($ht) === time() - 100, 'running it again writes nothing (same bytes, same mtime)');
chk(fourgeWriteCleanUrlHtaccess() && $count(file_get_contents($ht), $BEGIN) === 1 && strpos(file_get_contents($ht), '# BEGIN Fourge Clean URLs') !== false, 'the clean-URL writer adds its block and keeps the cache block');
chk(fourgeWriteProtectHtaccess(['secret.html']) && $count(file_get_contents($ht), $BEGIN) === 1 && strpos(file_get_contents($ht), 'RewriteRule ^secret\.html$ /_fourge_gate.php?p=secret.html') !== false, 'turning the password gate ON keeps the cache block');
chk(fourgeWriteProtectHtaccess([]) && $count(file_get_contents($ht), $BEGIN) === 1 && strpos(file_get_contents($ht), '_fourge_gate') === false, 'turning it OFF keeps the cache block too');
$after = file_get_contents($ht);
chk(fourgeWriteCacheHtaccess() && file_get_contents($ht) === $after, 'and the cache writer afterwards still changes nothing');
chk(preg_match_all('~<IfModule mod_headers\.c>~', $BLOCK) === 1 && preg_match('~# BEGIN Fourge cache headers.*?<IfModule mod_headers\.c>.*?</IfModule>\s*# END Fourge cache headers~s', $BLOCK) === 1, 'the directives sit inside <IfModule mod_headers.c>, so a host without mod_headers skips them');

@unlink($ht); @rmdir($tmp);
echo "\n" . ($fail ? "SUITE FAILED ($fail failed, $pass passed)" : "all htaccess cache assertions passed ($pass)") . "\n";
exit($fail ? 1 : 0);
