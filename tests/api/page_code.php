<?php
// The AI auto-fix's page rewrites never reach into code (1.14.162), the pure parts lifted out of
// admin/api.php: fourgeMaskCode / fourgeUnmaskCode / fourgeOnMarkup hide what is inside <script>,
// <style>, <template>, <textarea>, <noscript> and comments while a rewrite runs, and
// fourgeRepairScriptAlts takes back out the alt="…" the alt-text pass had put inside scripts
// (rallyforgood.com: the events runtime's "<img class=\"fev-s-img\" …" became
// "<img alt="…" class=\"fev-s-img\" …", and every event stayed on Loading…).
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (preg_match('~\}\s*$~', $line) && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$src = '';
foreach (['fourgeMaskCode', 'fourgeUnmaskCode', 'fourgeOnMarkup', 'fourgeRepairScriptAlts', 'fourgeAeoStampFaq', 'cmsPkgStripManagedSeo', 'cmsPkgStampSeo'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// The events runtime's line as the admin writes it, and as the alt-text pass left it on rallyforgood.com.
$RT  = '    +(ev.featured?"<img class=\"fev-s-img\" src=\""+esc(ev.featured)+"\" alt=\""+esc(dec(ev.title))+"\">":"")';
$BAD = '    +(ev.featured?"<img alt="Rally for Good event gathering with community participants and volunteers outdoors" class=\"fev-s-img\" src=\""+esc(ev.featured)+"\" alt=\""+esc(dec(ev.title))+"\">":"")';
$POSTS    = '+(p.featured?"<div class=\"fp-thumb\"><img src=\""+esc(media(p.featured,src))+"\" alt=\""+esc(dec(p.title))+"\" loading=\"lazy\"></div>":"")';
$POSTSBAD = '+(p.featured?"<div class=\"fp-thumb\"><img alt="What does the image actually show? (For example: people at an event, a rally scene, etc.)" src=\""+esc(media(p.featured,src))+"\" alt=\""+esc(dec(p.title))+"\" loading=\"lazy\"></div>":"")';
$page = function ($rt, $posts) { return "<!DOCTYPE html>\n<html lang=\"en\"><head>\n<meta charset=\"utf-8\">\n"
    . "<script>var cut=\"</head>\";var t=\"<title>no</title>\";</script>\n<title>Events</title>\n"
    . "<style>.x{background:url(\"<img src=x>\")}</style>\n</head>\n<body>\n<main>\n<h1>Events</h1>\n"
    . "<img src=\"assets/team-photo.jpg\">\n<img src=\"assets/hero.jpg\" alt=\"\">\n<img src=\"assets/logo.png\" alt=\"Logo\">\n"
    . "<!-- <img src=\"old.jpg\"> -->\n<template><img src=\"tpl.jpg\"></template>\n<textarea><img src=\"ta.jpg\"></textarea>\n"
    . "<noscript><img height=\"1\" width=\"1\" src=\"https://px.example/p.gif\"></noscript>\n</main>\n"
    . "<script id=\"fourge-events-js\">\n(function(){\nfunction paint(ev){\n  return \"\"\n" . $rt . "\n    +\"</article>\";\n}\nfunction posts(p){\n  return \"\"\n" . $posts . ";\n}\n})();\n</script>\n"
    . "<script type=\"application/json\" id=\"d\">[\"<img src=\\\"j.jpg\\\">\"]</script>\n</body></html>\n"; };
$clean = $page($RT, $POSTS);

// ── 1. hiding the code while a rewrite runs ──
chk(fourgeOnMarkup($clean, function ($h) { return $h; }) === $clean, 'a rewrite that changes nothing gives back the page byte for byte');
$rx = '~<img\b(?![^>]*\balt\s*=\s*"\s*[^"\s])(?![^>]*\balt\s*=\s*\x27\s*[^\x27\s])[^>]*>~i';   // the alt-text pass's own pattern
$m = fourgeMaskCode($clean);
preg_match_all($rx, $clean, $all); preg_match_all($rx, $m['html'], $seen);
chk(count($all[0]) >= 8 && count($seen[0]) === 2 && strpos($seen[0][0], 'team-photo.jpg') !== false && strpos($seen[0][1], 'hero.jpg') !== false,
    'the alt-text pass\'s pattern, run on the whole file, matches the images inside scripts, the style, the template, the textarea, the noscript and the comment (' . count($all[0]) . '); with the code hidden it sees only the page\'s two images that need alt text');
$out = fourgeOnMarkup($clean, function ($h) use ($rx) {
    return preg_replace_callback($rx, function ($t) {
        if (preg_match('~\balt\s*=\s*("\s*"|\x27\s*\x27)~i', $t[0])) return preg_replace('~\balt\s*=\s*("\s*"|\x27\s*\x27)~i', 'alt="Described"', $t[0], 1);
        return preg_replace('~^<img\b~i', '<img alt="Described"', $t[0], 1);
    }, $h);
});
$expect = str_replace(['<img src="assets/team-photo.jpg">', '<img src="assets/hero.jpg" alt="">'], ['<img alt="Described" src="assets/team-photo.jpg">', '<img src="assets/hero.jpg" alt="Described">'], $clean);
chk($out === $expect, 'the same pass through fourgeOnMarkup: both images described, and every script, style, template, textarea, noscript and comment exactly as it was');
chk(strpos($out, $RT) !== false && strpos($out, $POSTS) !== false, '…the events and blog runtimes\' image templates untouched (the line that broke rallyforgood.com\'s events)');
chk(fourgeOnMarkup($clean, function ($h) { return fourgeOnMarkup($h, function ($x) { return str_replace('<h1>Events</h1>', '<h1>All events</h1>', $x); }); }) === str_replace('<h1>Events</h1>', '<h1>All events</h1>', $clean), 'a rewrite nested inside another (code hidden twice) still gives back every hidden part');
$dup = fourgeOnMarkup($clean, function ($h) { return preg_replace('~(<main>)~', '$1' . substr($h, strpos($h, '<script id="fourge-events-js">'), 200), $h, 1); });
chk($dup === $clean, 'a rewrite that copies a hidden part somewhere else is refused: the page comes back unchanged');
chk(fourgeOnMarkup('', function ($h) { return $h . 'x'; }) === 'x' && fourgeOnMarkup('<p>no code</p>', function ($h) { return str_replace('no', 'some', $h); }) === '<p>some code</p>', 'a page without any code is rewritten as before');

// ── 2. the FAQ schema and the SEO stamp land in the real <head>, never in a script that mentions </head> ──
$faq = [['question' => 'What is it?', 'answer' => 'A long enough answer for the FAQ schema to accept it.'], ['question' => 'Who is it for?', 'answer' => 'Everyone who wants to rally for good in their community.']];
$f1 = fourgeOnMarkup($clean, function ($h) use ($faq) { return fourgeAeoStampFaq($h, $faq); });
$raw = fourgeAeoStampFaq($clean, $faq);
chk(strpos($raw, 'var cut="<script type="application/ld+json" data-fourge-aeo-faq>') !== false, '(without hiding: the FAQ block lands inside the script that mentions </head>, breaking it)');
$headEnd = strpos($f1, "\n</head>");
chk(substr_count($f1, 'data-fourge-aeo-faq') === 1 && strpos($f1, 'data-fourge-aeo-faq') < $headEnd && strpos($f1, 'var cut="</head>";') !== false, 'with fourgeOnMarkup it goes before the real </head>, and the script is left as it was');
$f2 = fourgeOnMarkup($f1, function ($h) use ($faq) { return fourgeAeoStampFaq($h, array_reverse($faq)); });
chk(substr_count($f2, 'data-fourge-aeo-faq') === 1 && strpos($f2, '"name":"Who is it for?"') < strpos($f2, '"name":"What is it?"'), '…and a second run replaces the earlier block instead of adding another (its hidden content goes with it)');
$s1 = fourgeOnMarkup($clean, function ($h) { return cmsPkgStampSeo($h, ['title' => 'Our Events', 'description' => 'Upcoming events.', 'extraJsonld' => [['@type' => 'Thing']]], 'https://a.example/events', false); });
chk(strpos($s1, '<title>Our Events</title>') !== false && strpos($s1, 'var t="<title>no</title>";') !== false && strpos($s1, 'var cut="</head>";') !== false && strpos($s1, 'data-fourge-seo') < strpos($s1, "\n</head>"),
    'the SEO stamp (titles and meta descriptions) changes the real <title> and adds its tags in the real <head>, not the strings in a script');
$s2 = fourgeOnMarkup($s1, function ($h) { return cmsPkgStampSeo($h, ['title' => 'Our Events', 'description' => 'Upcoming events, updated.', 'extraJsonld' => [['@type' => 'Thing']]], 'https://a.example/events', false); });
chk(substr_count($s2, '<script type="application/ld+json" data-fourge-seo>') === 1 && strpos($s2, 'Upcoming events, updated.') !== false, '…and stamping again replaces its own structured-data block, once');

// ── 3. repairing what the alt-text pass broke before 1.14.162 ──
$broken = $page($BAD, $POSTSBAD);
list($fixed, $n) = fourgeRepairScriptAlts($broken);
chk($fixed === $clean && $n === 2, 'a page whose events and blog runtimes got an alt="…" inside their code is given back exactly as it was before (' . $n . ' taken out)');
list($again, $n2) = fourgeRepairScriptAlts($fixed);
chk($again === $fixed && $n2 === 0, '…and a repaired (or never broken) page is left alone');
$ends = '<script>var s="<img alt="A photo""+attrs+">";</script>';
list($e1, $c1) = fourgeRepairScriptAlts($ends);
chk($e1 === '<script>var s="<img"+attrs+">";</script>' && $c1 === 1, 'an image tag that ends the string right after the inserted alt is repaired too');
$legit = "<!DOCTYPE html><html><body><img alt=\"Logo\" src=\"logo.png\">\n<script>var a='<img alt=\"Logo\" src=\"x.png\">';var b=\"<img alt=\\\"Q\\\" src=\\\"q.png\\\">\";</script>\n<script type=\"application/json\">[\"<img alt=\\\"J\\\" src=\\\"j.png\\\">\"]</script></body></html>";
list($l1, $c2) = fourgeRepairScriptAlts($legit);
chk($l1 === $legit && $c2 === 0, 'an alt the page or its code wrote on purpose is never taken out: markup, a single-quoted string with plain quotes, escaped quotes, JSON data');

echo "\n" . ($fail ? "page_code: $fail FAILED, $pass passed\n" : "all page_code assertions passed ($pass)\n");
exit($fail ? 1 : 0);
