<?php
// tests/lib/markup_sign.php — plays the MarkUp hub in the dispatcher suite: prints
// the JSON body of a signed request (see fourgeMarkupVerify in admin/api.php).
//   php tests/lib/markup_sign.php <secret key, base64> <action> <host> <payload JSON> [ts] [nonce]
[$_, $sk, $action, $host, $payload] = array_pad($argv, 5, '');
$ts = isset($argv[5]) && $argv[5] !== '' ? (int)$argv[5] : time();
$nonce = isset($argv[6]) && $argv[6] !== '' ? $argv[6] : rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$msg = "fourge-markup-v1\n" . $action . "\n" . $host . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $payload);
$sig = sodium_crypto_sign_detached($msg, base64_decode($sk));
echo json_encode(['action' => $action, 'payload' => $payload, 'host' => $host, 'ts' => $ts, 'nonce' => $nonce, 'sig' => base64_encode($sig)]);
