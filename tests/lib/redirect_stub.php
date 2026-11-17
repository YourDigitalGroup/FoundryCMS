<?php
// tests/lib/redirect_stub.php — a development link that went live: every request is sent on (301) to
// REDIRECT_TO with its path, as the dev-link rule a live Fourge site writes into .htaccess does.
//   REDIRECT_TO=http://client-e2e.example:8939 php -S 127.0.0.1:8940 -t <empty dir> tests/lib/redirect_stub.php
header('Location: ' . rtrim((string)getenv('REDIRECT_TO'), '/') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
echo 'Moved Permanently';
