<?php
// Fleet Dashboard — collector + status API for 44 Interactive's cross-site
// board. This is NOT part of any client's Fourge site and is never touched
// by the CMS auto-updater: it is a separate, small tool deployed once, on
// its own domain or subdirectory, to receive the status report every
// opted-in Fourge site's login self-heal already POSTs on its own
// (admin/api.php: fourgeFleetReport). It never talks to a client site
// directly — sites push their own status here; this file never pulls.
//
// New site deployed → someone signs into it to finish setup → the login
// self-heal reports it here automatically. That is the entire mechanism
// behind "the list updates itself" — no separate registration step exists
// to forget.
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

define('FLEET_ROOT', __DIR__);

$cfgFile = FLEET_ROOT . '/config.secret.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.secret.php is missing — copy config.secret.example.php and fill it in.']);
    exit;
}
$cfg = require $cfgFile;

$dataDir = FLEET_ROOT . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$sitesFile = $dataDir . '/sites.json';

function fleetReadSites($sitesFile) {
    if (!is_file($sitesFile)) return [];
    $j = json_decode((string)@file_get_contents($sitesFile), true);
    return is_array($j) ? $j : [];
}
function fleetWriteSites($sitesFile, $sites) {
    return @file_put_contents($sitesFile, json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}
// Case-insensitive header lookup — getallheaders() isn't always present
// (e.g. under some FPM/CGI setups), so fall back to the $_SERVER form.
function fleetBearerToken() {
    $h = '';
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
// A login/report over plain HTTP puts the password or the shared key on the
// wire in the clear. Localhost is exempted so this is testable without TLS.
function fleetRequireHttps() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $local = (strpos($host, 'localhost') !== false) || (strpos($host, '127.0.0.1') !== false);
    if (!$https && !$local) {
        http_response_code(400);
        echo json_encode(['error' => 'This endpoint requires HTTPS']);
        return false;
    }
    return true;
}
function fleetSessionStart() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function fleetRequireSession() {
    fleetSessionStart();
    if (empty($_SESSION['fleet_authed'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not signed in']);
        exit;
    }
}

$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = [];
$action = (string)($_GET['action'] ?? $body['action'] ?? '');
unset($body['action']);   // a control field, never part of a stored site record

switch ($action) {
    // Called by a Fourge site's own login self-heal (fourgeFleetReport in
    // admin/api.php). Bearer-gated by the ONE shared key every reporting
    // site is given — simple to operate for a small, known fleet, and a
    // leaked key only ever lets someone write a status row for a URL they
    // already control; the payload carries no secrets of the reporting
    // site's own, and viewing the registry needs the separate dashboard
    // password below.
    case 'register': {
        if (!fleetRequireHttps()) break;
        $key = fleetBearerToken();
        if ($key === '' || !hash_equals((string)($cfg['report_key'] ?? ''), $key)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or missing report key']);
            break;
        }
        $url = trim((string)($body['url'] ?? ''));
        if ($url === '') {
            http_response_code(400);
            echo json_encode(['error' => 'No site URL in the report — set Design → Website URL on the reporting site first']);
            break;
        }
        $sites = fleetReadSites($sitesFile);
        $body['url'] = $url;
        $body['receivedAt'] = gmdate('c');
        $sites[$url] = $body;   // upsert by URL — a site reporting again just refreshes its own row
        if (!fleetWriteSites($sitesFile, $sites)) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not save the report']);
            break;
        }
        echo json_encode(['ok' => true]);
        break;
    }
    case 'login': {
        if (!fleetRequireHttps()) break;
        fleetSessionStart();
        $pw   = (string)($body['password'] ?? '');
        $hash = (string)($cfg['dashboard_password_hash'] ?? '');
        if ($pw === '' || $hash === '' || !password_verify($pw, $hash)) {
            http_response_code(401);
            echo json_encode(['error' => 'Wrong password']);
            break;
        }
        session_regenerate_id(true);
        $_SESSION['fleet_authed'] = true;
        echo json_encode(['ok' => true]);
        break;
    }
    case 'logout': {
        fleetSessionStart();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['ok' => true]);
        break;
    }
    case 'whoami': {
        fleetSessionStart();
        echo json_encode(['ok' => true, 'authed' => !empty($_SESSION['fleet_authed'])]);
        break;
    }
    case 'status': {
        fleetRequireSession();
        $sites = fleetReadSites($sitesFile);
        // Newest report first — the sites someone touched most recently are
        // usually the ones worth looking at.
        uasort($sites, function ($a, $b) {
            return strcmp((string)($b['receivedAt'] ?? ''), (string)($a['receivedAt'] ?? ''));
        });
        echo json_encode(['ok' => true, 'sites' => array_values($sites)]);
        break;
    }
    // A decommissioned or retired site never stops existing on its own — this
    // is the deliberate, human "take it off the board" action.
    case 'delete_site': {
        fleetRequireSession();
        $url = trim((string)($body['url'] ?? ''));
        if ($url === '') { http_response_code(400); echo json_encode(['error' => 'No URL given']); break; }
        $sites = fleetReadSites($sitesFile);
        unset($sites[$url]);
        fleetWriteSites($sitesFile, $sites);
        echo json_encode(['ok' => true]);
        break;
    }
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
