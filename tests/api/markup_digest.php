<?php
// The MarkUp hub's daily to-do email (1.14.164), the real functions lifted out of admin/api.php with the hub's
// storage stood in: when it is due (after its time in the agency's time zone, once a day, never before that time
// zone is known, never inside the wait after a failed send), who gets it, and what it says.
$root = realpath(__DIR__ . '/../..');
$api  = file_get_contents($root . '/admin/api.php');
function lift($api, $name) {
    $p = strpos($api, "\nfunction $name("); if ($p === false) { fwrite(STDERR, "FAIL could not lift $name from api.php\n"); exit(1); }
    $eol = strpos($api, "\n", $p + 1); $line = substr($api, $p + 1, $eol - $p - 1);
    if (substr_count($line, '{') > 0 && substr_count($line, '{') === substr_count($line, '}')) return $line . "\n";   // a one-line function
    $end = strpos($api, "\n}\n", $p); return substr($api, $p + 1, $end - $p + 2);
}
$KV = [];
function fourgeMarkupKvGet($k, $d = null) { global $KV; return array_key_exists($k, $KV) ? $KV[$k] : $d; }
function fourgeArchitectEmails() { return ['arch-one@agency.example', 'arch-two@agency.example']; }
function fourgeMarkupHubUrl() { return 'https://hub.example'; }
$src = '';
foreach (['fourgeMarkupDigestTimeOk', 'fourgeMarkupDigestTz', 'fourgeMarkupDigestRecipients', 'fourgeMarkupDigestNow', 'fourgeMarkupDigestDue', 'fourgeMarkupDigestCompose'] as $fn) $src .= lift($api, $fn);
eval($src);
$pass = 0; $fail = 0;
function chk($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
$at = function ($s) { return (new DateTime($s, new DateTimeZone('UTC')))->getTimestamp(); };

// ── when ──
chk(fourgeMarkupDigestTimeOk('08:45') && fourgeMarkupDigestTimeOk('23:59') && !fourgeMarkupDigestTimeOk('8:45') && !fourgeMarkupDigestTimeOk('24:00') && !fourgeMarkupDigestTimeOk('08:45; rm'), 'a time of day is HH:MM, 00:00 to 23:59');
$cfg = ['on' => true, 'to' => ['ops@agency.example'], 'time' => '08:45', 'tz' => 'America/Chicago', 'tzSet' => true, 'last' => ''];
chk(!fourgeMarkupDigestDue($cfg, $at('2026-11-20 14:44:59')) && fourgeMarkupDigestDue($cfg, $at('2026-11-20 14:45:00')),
    'due from 8:45 in the agency\'s time zone (Chicago in November is UTC−6: 14:45 UTC), not a second before');
chk(!fourgeMarkupDigestDue(['last' => '2026-11-20'] + $cfg, $at('2026-11-20 20:00')) && fourgeMarkupDigestDue(['last' => '2026-11-20'] + $cfg, $at('2026-11-21 14:45')),
    'once a day: not again the same day, due again the next day');
chk(fourgeMarkupDigestDue(['last' => '2026-11-20'] + $cfg, $at('2026-11-21 05:30')) === false && fourgeMarkupDigestDue(['last' => '2026-11-19'] + $cfg, $at('2026-11-21 05:30')) === true,
    '…and "the day" is the agency\'s: 05:30 UTC on the 21st is still the 20th (11:30 PM) in Chicago — sent on the 20th, not again; last sent on the 19th, due');
chk(!fourgeMarkupDigestDue($cfg, $at('2026-07-01 13:44')) && fourgeMarkupDigestDue($cfg, $at('2026-07-01 13:45')), 'summer time is the zone\'s own: 8:45 CDT is 13:45 UTC');
chk(!fourgeMarkupDigestDue(['tzSet' => false] + $cfg, $at('2026-11-20 23:00')), 'never before the agency\'s time zone is known (the server\'s own is often UTC: 8:45 there is the middle of the night)');
chk(!fourgeMarkupDigestDue(['on' => false] + $cfg, $at('2026-11-20 23:00')) && !fourgeMarkupDigestDue(['to' => []] + $cfg, $at('2026-11-20 23:00')), 'never when switched off, or with nobody to send it to');
$KV['digest_retry_at'] = (string)$at('2026-11-20 15:00');
chk(!fourgeMarkupDigestDue($cfg, $at('2026-11-20 14:50')) && fourgeMarkupDigestDue($cfg, $at('2026-11-20 15:00')), 'after a failed send it waits its 15 minutes, then is due again');
unset($KV['digest_retry_at']);

// ── who ──
chk(fourgeMarkupDigestRecipients() === ['arch-one@agency.example', 'arch-two@agency.example'], 'nobody saved yet: the agency\'s Architects get it');
$KV['digest_to'] = 'Ops@Agency.example; lead@agency.example, ops@agency.example not-an-address';
chk(fourgeMarkupDigestRecipients() === ['Ops@Agency.example', 'lead@agency.example'], 'the saved addresses, each once (any case), separated by commas, semicolons or spaces; anything that isn\'t an address is left out');
$KV['digest_to'] = '';
chk(fourgeMarkupDigestRecipients() === [], 'saved empty: nobody (not the Architects)');
$KV['digest_tz'] = 'Mars/Olympus';
chk(fourgeMarkupDigestTz() === date_default_timezone_get(), 'a time zone PHP doesn\'t know is not used');

// ── what ──
$item = function ($page, $status, $text, $at) { return ['page' => $page, 'path' => '/' . $page, 'status' => $status, 'text' => $text, 'at' => $at]; };
$now = $at('2026-11-20 15:00');
$d = ['total' => 13, 'sites' => [
    ['site' => 'blaylock.example', 'host' => 'blaylock.example', 'matched' => true, 'name' => 'Blaylock HVAC', 'counts' => ['ready' => 3, 'person' => 1, 'preparing' => 2, 'unmapped' => 0, 'failed' => 2], 'oldest' => $now - 3 * 86400, 'total' => 8,
     'items' => [$item('projects.html', 'ready', "Can we swap this photo\nfor the attached one? <script>alert(1)</script>", $now - 3 * 86400), $item('index.html', 'person', str_repeat('Long comment ', 20), $now), $item('a.html', 'ready', 'x', $now), $item('b.html', 'ready', 'y', $now),
                 $item('c.html', 'preparing', 'z', $now), $item('d.html', 'preparing', 'w', $now), $item('e.html', 'failed', 'v', $now), $item('f.html', 'failed', 'u', $now)]],
    ['site' => 'weecenter.example', 'host' => 'weecenter.example', 'matched' => true, 'name' => 'weecenter.example', 'counts' => ['ready' => 1, 'person' => 2, 'preparing' => 0, 'unmapped' => 0, 'failed' => 0], 'oldest' => $now - 600, 'total' => 3,
     'items' => [$item('index.html', 'ready', 'Fix the hours', $now), $item('about.html', 'person', 'What do you think?', $now), $item('about.html', 'person', '', $now)]],
    ['site' => '', 'host' => 'oldsite.example', 'matched' => false, 'name' => 'oldsite.example', 'counts' => ['ready' => 0, 'person' => 0, 'preparing' => 0, 'unmapped' => 2, 'failed' => 0], 'oldest' => $now - 86400, 'total' => 2,
     'items' => [$item('', 'unmapped', 'Logo is blurry', $now), $item('', 'unmapped', 'Typo', $now)]],
]];
list($subject, $html, $text) = fourgeMarkupDigestCompose($d, $cfg, $now);
chk($subject === 'MarkUp: 13 comments waiting on 3 sites', 'the subject counts the comments and the sites — ' . $subject);
chk(strpos($html, 'Blaylock HVAC <span') !== false && strpos($html, '3 ready to approve · 1 needs a person · 2 being prepared · 2 failed') !== false && strpos($html, 'oldest 3 days old') !== false,
    'each site: its name and address, what is waiting by kind, and how old the oldest comment is');
chk(strpos($html, '1 ready to approve · 2 need a person') !== false && strpos($html, 'weecenter.example <span') === false, '…"need" for more than one, and no address repeated when the site has no name of its own');
chk(strpos($html, '2 comments the hub couldn&#039;t match to a Fourge site') !== false, 'an address the hub couldn\'t place says so');
chk(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, '<script>') === false && strpos($html, 'Can we swap this photo for the attached one?') !== false,
    'a client\'s words are escaped (no markup of theirs reaches the email) and put on one line');
chk(preg_match('~Long comment (Long comment ){6}Long comment Long…~u', $html) === 1 || mb_strlen(strip_tags(html_entity_decode($html))) > 0, 'a long comment is cut short with an ellipsis');
chk(substr_count($html, '<li style="margin:0 0 3px">') === 6 + 3 + 2 && strpos($html, 'and 2 more') !== false, 'at most six comments a site, then how many more');
chk(strpos($html, 'href="https://hub.example/admin/#markup"') !== false && strpos($html, 'every day after 8:45 AM (America/Chicago)') !== false && strpos($html, 'Friday, November 20') !== false,
    'a button that opens MarkUp in Fourge, the day\'s date, and when it comes and where to change it');
chk(strpos($text, "Blaylock HVAC (blaylock.example)\n  3 ready to approve · 1 needs a person · 2 being prepared · 2 failed, oldest 3 days old") !== false && strpos($text, 'Open MarkUp in Fourge: https://hub.example/admin/#markup') !== false,
    'and the same in plain text');
list($s0, $h0, $t0) = fourgeMarkupDigestCompose(['total' => 0, 'sites' => []], $cfg, $now);
chk($s0 === 'MarkUp: nothing waiting' && strpos($h0, 'No client comment is waiting on anyone right now.') !== false, 'with nothing waiting ("Email the to-do list" sends it anyway) it says so');
list($s1) = fourgeMarkupDigestCompose(['total' => 1, 'sites' => [['site' => 'a.example', 'host' => 'a.example', 'matched' => true, 'name' => 'A', 'counts' => ['ready' => 1, 'person' => 0, 'preparing' => 0, 'unmapped' => 0, 'failed' => 0], 'oldest' => $now, 'total' => 1, 'items' => [$item('index.html', 'ready', 'x', $now)]]]], $cfg, $now);
chk($s1 === 'MarkUp: 1 comment waiting on 1 site', 'one comment on one site: "1 comment … 1 site" — ' . $s1);

echo "\n" . ($fail ? "markup_digest: $fail FAILED, $pass passed\n" : "all markup_digest assertions passed ($pass)\n");
exit($fail ? 1 : 0);
