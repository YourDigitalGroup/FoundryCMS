<?php
// tests/lib/smtp_stub.php — a stand-in SMTP relay for the dispatcher suite.
//   php tests/lib/smtp_stub.php <port> <log file>
// Speaks just enough ESMTP for cmsSmtpSend() over a plain connection (smtp_secure
// 'none'): EHLO/HELO, AUTH LOGIN (any credentials), MAIL FROM, RCPT TO, DATA,
// RSET, NOOP, QUIT. A recipient whose address starts with "refused" gets 550, the
// way a relay refuses an address. Each message it accepts is appended to the log
// as one JSON line: {"from": …, "rcpt": [accepted recipients], "data": raw message}.
$port = (int)($argv[1] ?? 8937);
$log  = (string)($argv[2] ?? (dirname(__DIR__) . '/.cache/smtp_stub.jsonl'));
$srv = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$srv) { fwrite(STDERR, "smtp stub: cannot listen on $port: $errstr\n"); exit(1); }
while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if (!$c) continue;
    stream_set_timeout($c, 15);
    $say = function ($s) use ($c) { fwrite($c, $s . "\r\n"); };
    $say('220 smtp-stub ESMTP ready');
    $from = ''; $rcpt = [];
    while (($line = fgets($c)) !== false) {
        $cmd = rtrim($line, "\r\n");
        $up = strtoupper($cmd);
        if (strpos($up, 'EHLO') === 0)      { $say('250-smtp-stub'); $say('250 AUTH LOGIN'); }
        elseif (strpos($up, 'HELO') === 0)  { $say('250 smtp-stub'); }
        elseif ($up === 'AUTH LOGIN')       { $say('334 VXNlcm5hbWU6'); fgets($c); $say('334 UGFzc3dvcmQ6'); fgets($c); $say('235 2.7.0 Authentication successful'); }
        elseif (preg_match('/^MAIL FROM:\s*<([^>]*)>/i', $cmd, $m)) { $from = $m[1]; $rcpt = []; $say('250 2.1.0 OK'); }
        elseif (preg_match('/^RCPT TO:\s*<([^>]*)>/i', $cmd, $m)) {
            if (stripos($m[1], 'refused') === 0) { $say('550 5.1.1 Recipient address rejected'); }
            else { $rcpt[] = $m[1]; $say('250 2.1.5 OK'); }
        }
        elseif ($up === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($c)) !== false) { if (rtrim($l, "\r\n") === '.') break; $data .= $l; }
            file_put_contents($log, json_encode(['from' => $from, 'rcpt' => $rcpt, 'data' => $data]) . "\n", FILE_APPEND | LOCK_EX);
            $say('250 2.0.0 OK queued');
        }
        elseif ($up === 'RSET')             { $from = ''; $rcpt = []; $say('250 2.0.0 OK'); }
        elseif ($up === 'QUIT')             { $say('221 2.0.0 Bye'); break; }
        else                                { $say('250 2.0.0 OK'); }
    }
    fclose($c);
}
