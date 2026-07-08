<?php
/**
 * Fourge CMS — Server API
 * Place at: yoursite.com/admin/api.php
 * Add admin/api.php to .gitignore — never commit this file.
 */

require_once __DIR__ . '/db.php';      // SQLite data layer (users, sessions, encrypted secrets)

// ── CONFIGURATION ────────────────────────────────────────────────────────────
// Secrets (API token + Mailgun) are read from config.secret.php when present, so
// deploying or self-updating api.php NEVER clobbers your live values. The inline
// strings are only fallbacks for a brand-new install. config.secret.php is
// gitignored and never deployed — put your real keys there.
$__secret = (function () {
    $f = __DIR__ . '/config.secret.php';
    $c = file_exists($f) ? (include $f) : [];
    return is_array($c) ? $c : [];
})();

// Read SMTP mail credentials out of a legacy PHPMailer "send.php" (the handler a
// site used before it was moved onto the CMS). The file is parsed as TEXT and is
// NEVER included or executed — we only pull the string/number literals from its
// $CONFIG array. This lets an already-launched site keep sending form email with
// no config.secret.php rewrite. Returns [] when there's no usable send.php.
function fourgeLegacySendConfig($root) {
    if (!$root) return [];
    $files = [];
    if (is_file($root . '/send.php')) $files[] = $root . '/send.php';
    else foreach (glob($root . '/*/send.php') ?: [] as $g) $files[] = $g;   // one level down, only if not at root
    foreach ($files as $file) {
        $src = @file_get_contents($file);
        if ($src === false || stripos($src, 'smtp_host') === false) continue;
        $str = function ($key) use ($src) {
            return preg_match('/[\'"]' . $key . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $src, $m) ? trim($m[1]) : '';
        };
        $host = $str('smtp_host'); $user = $str('smtp_username'); $pass = $str('smtp_password');
        if ($host === '' || $user === '' || $pass === '') continue;   // not a usable SMTP block
        $cfg = [
            'smtp_host'     => $host,
            'smtp_port'     => preg_match('/[\'"]smtp_port[\'"]\s*=>\s*(\d+)/', $src, $m) ? (int)$m[1] : 587,
            'smtp_username' => $user,
            'smtp_password' => $pass,
        ];
        $sec = $str('smtp_secure'); if ($sec !== '') $cfg['smtp_secure'] = $sec;
        $fe = $str('from_email');   $fn = $str('from_name');
        if ($fe !== '') $cfg['mg_from'] = ($fn !== '' ? $fn . ' <' . $fe . '>' : $fe);
        $to = $str('to_email');     if ($to !== '') $cfg['mg_notify_to'] = $to;
        return $cfg;
    }
    return [];
}
// Only when config.secret.php configures no mail of its own — explicit config always wins.
if (empty($__secret['smtp_host']) && empty($__secret['mg_api_key'])) {
    $__legacy = fourgeLegacySendConfig(dirname(__DIR__));
    if ($__legacy) $__secret = array_merge($__legacy, $__secret);
}

define('API_TOKEN',    (string)($__secret['api_token'] ?? 'CHANGE_ME')); // optional now (login uses sessions); kept for legacy/external callers
define('PUBLIC_HTML',  realpath(dirname(__DIR__)));

// Mailgun (forms)
define('MG_DOMAIN',    (string)($__secret['mg_domain']    ?? 'mg.example.com'));
define('MG_API_KEY',   (string)($__secret['mg_api_key']   ?? ''));
define('MG_FROM',      (string)($__secret['mg_from']      ?? 'Fourge CMS <postmaster@mg.example.com>'));
define('MG_NOTIFY_TO', (string)($__secret['mg_notify_to'] ?? ''));

// Contact-form email via SMTP (Mailgun/SendGrid/any relay). When these are set,
// the form handler sends over SMTP instead of the Mailgun HTTP API — so a
// migrated site's EXISTING SMTP credentials work as-is, no API key needed. Kept
// in config.secret.php (gitignored) so it never passes through the repo. The
// From address uses mg_from; the recipient uses the form's notify address (or
// mg_notify_to). smtp_secure: 'auto' (STARTTLS on 587, implicit TLS on 465),
// or force 'tls' / 'ssl' / 'none'.
define('SMTP_HOST',   (string)($__secret['smtp_host']     ?? ''));
define('SMTP_PORT',   (int)   ($__secret['smtp_port']     ?? 587));
define('SMTP_USER',   (string)($__secret['smtp_username'] ?? ''));
define('SMTP_PASS',   (string)($__secret['smtp_password'] ?? ''));
define('SMTP_SECURE', (string)($__secret['smtp_secure']   ?? 'auto'));

// Require a secure (HTTPS) connection for sign-in and credential/secret changes.
// Defaults ON. Localhost/dev is always exempt. To allow plain HTTP in an unusual
// setup, add  'require_https' => false  to your config.secret.php array.
define('REQUIRE_HTTPS', array_key_exists('require_https', $__secret) ? (bool)$__secret['require_https'] : true);

// Team onboarding (optional). A NEW email on ONBOARD_EMAIL_DOMAIN that signs in
// with ONBOARD_PASSWORD self-provisions an EDITOR account with a forced first-
// login password change — a convenience for standing up your team across client
// sites. It NEVER overrides an existing account (so it can't be a backdoor into
// established logins), only ever grants the 'editor' role (never Architect), and
// stays OFF unless onboard_password is set. Keep that password in
// config.secret.php, NOT here: api.php ships in a public repo, so a value in this
// file would be world-readable. The domain isn't secret, so it defaults inline.
define('ONBOARD_EMAIL_DOMAIN', strtolower((string)($__secret['onboard_domain']   ?? '44interactive.com')));
define('ONBOARD_PASSWORD',              (string)($__secret['onboard_password'] ?? ''));

// Folders to always exclude from scan (relative paths from public_html root)
// Add any site-specific paths you want hidden from import
define('SKIP_PATHS', [
    'uploads/html-site-boilerplate',
    'boilerplate',
    'backup',
    'backups',
    '_archive',
]);

// Directories to never recurse into. 'data' holds the CMS's own files —
// pages.json, site.json, and revision snapshots written on every save
// (data/rev_rev_<ts>.html) — which must never be scanned as editable pages.
define('SKIP_DIRS', [
    'admin', '.git', '.github', 'node_modules', 'vendor',
    'cgi-bin', 'wp-admin', 'wp-includes', 'data',
]);

// Pattern that uniquely identifies a Fourge CMS shell.
// IMPORTANT: must be specific enough to never match a regular HTML file.
// Only the generated makeShell() output contains this exact string.
define('CMS_PATTERN', 'src="../block-renderer.jsx"');

// ── HTTPS HELPERS ──────────────────────────────────────────────────────────
// True when the request reached us over TLS. Also handles reverse proxies /
// load balancers that terminate TLS at the edge and forward plain HTTP to PHP
// (common on shared hosts), where $_SERVER['HTTPS'] is unset but an
// X-Forwarded-Proto / X-Forwarded-SSL header marks the visitor's leg as HTTPS.
function fourgeIsHttps() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    $xfp = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($xfp === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return false;
}
// True for local development hosts, which are exempt from the HTTPS requirement
// so you can work over http://localhost without a certificate.
function fourgeIsLocalRequest() {
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) return true;
    if (preg_match('/(\.local|\.localhost|\.test)$/', $host)) return true;
    $ra = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return ($ra === '127.0.0.1' || $ra === '::1');
}

// ─────────────────────────────────────────────────────────────────────────────

// Catch ALL PHP output — errors become JSON instead of empty body
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Parse the request body first so auth can be routed per-action.
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];
$action = $body['action'] ?? $_POST['action'] ?? '';

// ── AUTH MODEL ────────────────────────────────────────────────────────────────
//  • 'login' is public — it verifies the email/password itself.
//  • 'send_form' is public — it's the public contact-form endpoint. Site visitors
//    have no token or session, so it must be reachable without auth; spam is held
//    off by the optional reCAPTCHA check inside cmsSendForm.
//  • Account + secret actions require a valid session token (from login).
//  • Legacy file / GA / AI actions accept the shared API_TOKEN OR a session token.
$PUBLIC_ACTIONS  = ['login', 'send_form'];
$SESSION_ACTIONS = ['logout','session','list_users','save_user','delete_user','change_password','get_secrets','set_secret','repo_fetch','set_page_password','install_clean_urls','ghl_test'];

$apiTok      = $_SERVER['HTTP_X_API_TOKEN'] ?? ($body['token'] ?? ($_POST['token'] ?? ''));
$hasApiToken = ($apiTok !== '' && hash_equals(API_TOKEN, (string)$apiTok));

$sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? ($body['session_token'] ?? '');
$authUser     = null;
try { if ($sessionToken) $authUser = fourgeSessionUser(fourgeDb(), $sessionToken); } catch (Throwable $e) { $authUser = null; }

if (!in_array($action, $PUBLIC_ACTIONS, true)) {
    if (in_array($action, $SESSION_ACTIONS, true)) {
        if (!$authUser) {
            ob_end_clean(); http_response_code(401);
            echo json_encode(['error' => 'Not signed in. Please log in again.']); exit;
        }
        // A must-change-password session may ONLY change its own password.
        if (!empty($authUser['must_change_password']) && $action !== 'change_password') {
            ob_end_clean(); http_response_code(403);
            echo json_encode(['error' => 'You must set a new password before continuing.']); exit;
        }
    } elseif (!$hasApiToken && !$authUser) {
        ob_end_clean(); http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Provide a valid Server API token or sign in.']); exit;
    }
}

// ── REQUIRE HTTPS FOR CREDENTIALS ───────────────────────────────────────────
// Passwords are hashed at rest, but a login or secret sent over plain HTTP is
// exposed in transit. The root .htaccess redirects HTTP→HTTPS site-wide; this
// is the server-side backstop for hosts that don't honor .htaccess (e.g. nginx)
// or have mod_rewrite disabled. Localhost/dev is exempt; set 'require_https' =>
// false in config.secret.php only for a deliberate plain-HTTP setup.
$HTTPS_REQUIRED_ACTIONS = ['login', 'change_password', 'set_secret', 'save_user', 'ga_save_credentials', 'set_page_password'];
if (REQUIRE_HTTPS && in_array($action, $HTTPS_REQUIRED_ACTIONS, true) && !fourgeIsHttps() && !fourgeIsLocalRequest()) {
    ob_end_clean(); http_response_code(403);
    echo json_encode(['error' => 'For your security, signing in and changing credentials require a secure (HTTPS) connection. Please load this site over https:// and try again.']);
    exit;
}

try {
    switch ($action) {
        case 'ping':        ob_end_clean(); echo json_encode(['ok' => true, 'root' => PUBLIC_HTML, 'php' => PHP_VERSION, 'version' => '1.2.0', 'db' => true]); break;
        // ── Auth + accounts + secrets (SQLite-backed) ──
        case 'login':           ob_end_clean(); fourgeApiLogin($body); break;
        case 'logout':          ob_end_clean(); fourgeApiLogout($sessionToken); break;
        case 'session':         ob_end_clean(); echo json_encode(['ok' => true, 'user' => fourgePublicUser($authUser)]); break;
        case 'list_users':      ob_end_clean(); fourgeApiListUsers($authUser); break;
        case 'save_user':       ob_end_clean(); fourgeApiSaveUser($authUser, $body); break;
        case 'delete_user':     ob_end_clean(); fourgeApiDeleteUser($authUser, $body); break;
        case 'change_password': ob_end_clean(); fourgeApiChangePassword($authUser, $body); break;
        case 'get_secrets':     ob_end_clean(); fourgeApiGetSecrets($authUser); break;
        case 'set_secret':      ob_end_clean(); fourgeApiSetSecret($authUser, $body); break;
        case 'ghl_test':        ob_end_clean(); fourgeApiGhlTest($authUser, $body); break;
        case 'set_page_password': ob_end_clean(); fourgeApiSetPagePassword($authUser, $body); break;
        case 'install_clean_urls': ob_end_clean(); fourgeApiInstallCleanUrls($authUser); break;
        case 'repo_fetch':      ob_end_clean(); fourgeApiRepoFetch($authUser, $body); break;
        case 'list_pages':  ob_end_clean(); cmsListPages();    break;
        case 'list_media':  ob_end_clean(); cmsListMedia();    break;
        case 'read_file':   ob_end_clean(); cmsReadFile($body); break;
        case 'write_file':  ob_end_clean(); cmsWriteFile($body); break;
        case 'upload':      ob_end_clean(); handleUpload();    break;
        case 'delete_file': ob_end_clean(); cmsDeleteFile($body); break;
        case 'send_form':   ob_end_clean(); cmsSendForm($body); break;
        case 'ga_save_credentials': ob_end_clean(); gaSaveCredentials($body); break;
        case 'ga_status':   ob_end_clean(); gaStatus();          break;
        case 'ga_report':   ob_end_clean(); gaReport($body);     break;
        case 'claude_proxy': ob_end_clean(); claudeProxy($body); break;
        default:
            ob_end_clean();
            echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }
} catch (Throwable $e) {
    $buffered = ob_get_clean();
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'output'  => $buffered ?: null,
    ]);
}

// ── LIST PAGES ────────────────────────────────────────────────────────────────

function cmsListPages() {
    $root       = PUBLIC_HTML;
    $skipFiles  = ['preview.html','404.html','500.html','maintenance.html','coming-soon.html','offline.html'];
    $pages      = [];

    scanHtml($root, $root, $skipFiles, $pages);

    usort($pages, function($a, $b) {
        if ($a['file'] === 'index.html') return -1;
        if ($b['file'] === 'index.html') return  1;
        return strcmp($a['path'], $b['path']);
    });

    echo json_encode(['pages' => $pages, 'root' => $root]);
}

function scanHtml($root, $dir, $skipFiles, &$pages, $depth = 0) {
    if ($depth > 5) return;

    $items = @scandir($dir);
    if (!$items) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $dir . '/' . $item;
        $relPath  = ltrim(str_replace($root, '', $fullPath), '/');

        // Skip explicitly excluded paths (defined at top of file)
        foreach (SKIP_PATHS as $sp) {
            if (strpos($relPath, rtrim($sp, '/')) === 0) continue 2;
        }

        if (is_dir($fullPath)) {
            if (!in_array($item, SKIP_DIRS)) {
                scanHtml($root, $fullPath, $skipFiles, $pages, $depth + 1);
            }
            continue;
        }

        if (!preg_match('/\.html?$/i', $item)) continue;
        if (in_array($item, $skipFiles)) continue;

        $content = @file_get_contents($fullPath);
        if ($content === false) continue;

        // Detect existing Fourge CMS shell — only the generated shell contains this exact string
        $isCMS = strpos($content, CMS_PATTERN) !== false;

        // Extract title
        $title = ucwords(preg_replace('/[-_]/', ' ', pathinfo($item, PATHINFO_FILENAME)));
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $m)) {
            $t = trim(strip_tags($m[1]));
            if ($t) $title = $t;
        }

        $snippet = substr(trim(preg_replace('/\s+/', ' ', strip_tags($content))), 0, 200);

        $pages[] = [
            'file'     => $item,
            'path'     => $relPath,
            'title'    => $title,
            'size'     => filesize($fullPath),
            'modified' => date('Y-m-d H:i', filemtime($fullPath)),
            'is_cms'   => $isCMS,
            'snippet'  => $snippet,
        ];
    }
}

// ── LIST MEDIA ────────────────────────────────────────────────────────────────

function cmsListMedia() {
    $root      = PUBLIC_HTML;
    $imageExts = ['jpg','jpeg','png','webp','gif','svg','ico'];
    $videoExts = ['mp4','mov','webm','ogg'];
    $docExts   = ['pdf','doc','docx','xls','xlsx','ppt','pptx'];
    $allExts   = array_merge($imageExts, $videoExts, $docExts);
    $files     = [];

    scanMedia($root, $root, $allExts, $imageExts, $videoExts, $files);

    usort($files, fn($a, $b) => $b['size'] - $a['size']);

    echo json_encode(['files' => $files, 'root' => $root, 'count' => count($files)]);
}

function scanMedia($root, $dir, $allExts, $imageExts, $videoExts, &$files, $depth = 0) {
    if ($depth > 5) return;

    $items = @scandir($dir);
    if (!$items) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $dir . '/' . $item;
        $relPath  = ltrim(str_replace($root, '', $fullPath), '/');

        foreach (SKIP_PATHS as $sp) {
            if (strpos($relPath, rtrim($sp, '/')) === 0) continue 2;
        }

        if (is_dir($fullPath)) {
            if (!in_array($item, SKIP_DIRS)) {
                scanMedia($root, $fullPath, $allExts, $imageExts, $videoExts, $files, $depth + 1);
            }
            continue;
        }

        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $allExts)) continue;

        $type = in_array($ext, $imageExts) ? 'image'
              : (in_array($ext, $videoExts) ? 'video' : 'doc');

        $sz   = filesize($fullPath);
        $size = $sz > 1048576 ? round($sz/1048576, 1).' MB' : round($sz/1024).' KB';

        $files[] = [
            'name'     => $item,
            'path'     => $relPath,
            'size'     => $size,
            'bytes'    => $sz,
            'type'     => $type,
            'ext'      => $ext,
            'modified' => date('Y-m-d', filemtime($fullPath)),
        ];
    }
}

// ── READ FILE ─────────────────────────────────────────────────────────────────

function gaIsProtectedPath($absPath) {
    $protected = [realpath(__DIR__ . '/ga-service-account.json'), realpath(__DIR__ . '/.ga-token.json')];
    $abs = $absPath ? realpath($absPath) : false;
    // realpath() returns false for non-existent files — also compare raw target names
    $names = ['ga-service-account.json', '.ga-token.json'];
    if ($abs && in_array($abs, array_filter($protected), true)) return true;
    $base = basename($absPath);
    return in_array($base, $names, true) && strpos($absPath, 'admin') !== false;
}

function cmsReadFile($body) {
    $relPath = $body['path'] ?? '';
    $safe    = realpath(PUBLIC_HTML . '/' . $relPath);
    if ($safe && gaIsProtectedPath($safe)) {
        http_response_code(403);
        echo json_encode(['error' => 'This file is protected']);
        return;
    }
    if (!$safe || strpos($safe, PUBLIC_HTML) !== 0 || !is_file($safe)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found: ' . htmlspecialchars($relPath)]);
        return;
    }
    echo json_encode([
        'content'  => file_get_contents($safe),
        'path'     => $relPath,
        'size'     => filesize($safe),
        'modified' => date('Y-m-d H:i', filemtime($safe)),
    ]);
}

// ── WRITE FILE ────────────────────────────────────────────────────────────────

function cmsWriteFile($body) {
    $relPath = $body['path'] ?? ($_POST['path'] ?? '');
    $content = $body['content'] ?? '';
    // Content may arrive three ways:
    //  1) multipart file part (content_file) — primary path; sails past WAFs
    //     that block raw HTML/JS in a JSON POST (same channel as image uploads),
    //  2) base64 in JSON (content_b64) — legacy WAF-bypass,
    //  3) plain JSON content — legacy.
    if (isset($body['content_b64']) && is_string($body['content_b64'])) {
        $decoded = base64_decode($body['content_b64'], true);
        if ($decoded === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid base64 content']);
            return;
        }
        $content = $decoded;
    }
    if (!empty($_FILES['content_file']['tmp_name']) && is_uploaded_file($_FILES['content_file']['tmp_name'])) {
        $c = file_get_contents($_FILES['content_file']['tmp_name']);
        if ($c !== false) $content = $c;
    }
    $dest    = PUBLIC_HTML . '/' . ltrim($relPath, '/');
    if (gaIsProtectedPath($dest)) {
        http_response_code(403);
        echo json_encode(['error' => 'This file is protected — use the Analytics setup panel']);
        return;
    }
    $dir     = dirname($dest);
    $real    = realpath($dir) ?: $dir;
    if (strpos($real, PUBLIC_HTML) !== 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Path not allowed']);
        return;
    }
    // api.php may be auto-updated by the engine updater, but ONLY on installs that
    // keep their secrets in config.secret.php. If that file is absent, this site may
    // still carry secrets inline in api.php — refuse the overwrite so an update can
    // never wipe them. (Move secrets into config.secret.php to enable auto-update.)
    if (realpath($dest) === realpath(__FILE__) && !is_file(__DIR__ . '/config.secret.php')) {
        http_response_code(409);
        echo json_encode(['error' => 'Refusing to overwrite api.php: config.secret.php not found. Move this site\'s secrets into config.secret.php first — then api.php auto-updates.']);
        return;
    }
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($dest, $content) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write: ' . htmlspecialchars($relPath)]);
        return;
    }
    echo json_encode(['ok' => true, 'path' => $relPath, 'size' => strlen($content)]);
}

// ── UPLOAD FILES ──────────────────────────────────────────────────────────────

function handleUpload() {
    if (empty($_FILES['files'])) {
        echo json_encode(['error' => 'No files in request']); return;
    }
    $allowed = ['html','htm','css','jsx','js','json','svg','jpg','jpeg','png','webp','gif','ico','woff','woff2','ttf','otf','pdf'];
    $blocked  = ['php','php3','php4','phtml','phar','asp','aspx','cgi','pl','sh','exe','bat'];
    $results  = [];
    $names    = (array)$_FILES['files']['name'];
    $tmps     = (array)$_FILES['files']['tmp_name'];
    $errors   = (array)$_FILES['files']['error'];

    for ($i = 0; $i < count($names); $i++) {
        $orig = $names[$i]; $tmp = $tmps[$i]; $err = $errors[$i];
        if ($err !== UPLOAD_ERR_OK) { $results[] = ['name'=>$orig,'success'=>false,'error'=>'Upload error '.$err]; continue; }
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '', $orig);
        $ext  = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        if (in_array($ext, $blocked)) { $results[] = ['name'=>$orig,'success'=>false,'error'=>'File type blocked']; continue; }
        $dest = PUBLIC_HTML . '/' . $safe;
        if (move_uploaded_file($tmp, $dest)) {
            $results[] = ['name'=>$safe,'success'=>true,'path'=>$safe];
        } else {
            $results[] = ['name'=>$safe,'success'=>false,'error'=>'Could not save'];
        }
    }
    echo json_encode(['results' => $results]);
}

// ── DELETE FILE ───────────────────────────────────────────────────────────────

function cmsDeleteFile($body) {
    $relPath = $body['path'] ?? '';
    $safe    = realpath(PUBLIC_HTML . '/' . $relPath);
    if (!$safe || strpos($safe, PUBLIC_HTML) !== 0 || !is_file($safe)) {
        http_response_code(404); echo json_encode(['error' => 'File not found']); return;
    }
    unlink($safe);
    echo json_encode(['ok' => true]);
}

// ── MAILGUN FORM ──────────────────────────────────────────────────────────────

function cmsStoreEntry($formId, $fields, $siteUrl) {
    try {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $file = $dir . '/entries.json';
        $entries = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $entries = json_decode($raw, true) ?: [];
        }
        array_unshift($entries, [
            'id'     => uniqid('ent_'),
            'formId' => $formId,
            'date'   => date('Y-m-d H:i'),
            'data'   => $fields,
            'source' => $siteUrl,
        ]);
        // Cap at 1000 entries
        if (count($entries) > 1000) { $entries = array_slice($entries, 0, 1000); }
        file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (Exception $e) { /* non-fatal */ }
}

function cmsRecaptchaSecret() {
    // Read the secret from data/site.json (server-side only; never exposed to client)
    try {
        $file = __DIR__ . '/../data/site.json';
        if (!file_exists($file)) return '';
        $site = json_decode(file_get_contents($file), true);
        if (isset($site['recaptcha']['enabled']) && $site['recaptcha']['enabled'] && !empty($site['recaptcha']['secret'])) {
            return $site['recaptcha']['secret'];
        }
    } catch (Exception $e) {}
    return '';
}

// The v3 score threshold from data/site.json (default 0.5). v2 ignores it.
function cmsRecaptchaThreshold() {
    try {
        $file = __DIR__ . '/../data/site.json';
        if (!file_exists($file)) return 0.5;
        $site = json_decode(file_get_contents($file), true);
        $t = $site['recaptcha']['threshold'] ?? 0.5;
        return is_numeric($t) ? (float)$t : 0.5;
    } catch (Exception $e) { return 0.5; }
}

function cmsVerifyRecaptcha($secret, $token, $threshold = 0.5) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return false;
    $data = json_decode($res, true);
    if (empty($data['success'])) return false;
    // reCAPTCHA v3 returns a 0.0–1.0 score; enforce the configured threshold.
    // v2 (checkbox) returns no score, so a successful verification is enough.
    if (isset($data['score'])) return ((float)$data['score']) >= (float)$threshold;
    return true;
}

// ── GOHIGHLEVEL (GHL) LEAD PUSH ─────────────────────────────────────────────
// Pushes a website form submission into GoHighLevel as a contact (lead) via the
// v2 API, using a Private Integration Token stored encrypted server-side — so no
// GHL form is needed and the token never reaches the browser. Config lives in
// data/site.json { ghl: { enabled, locationId } } + the encrypted 'ghl_token'.
define('GHL_API_BASE', 'https://services.leadconnectorhq.com');
define('GHL_API_VERSION', '2021-07-28');

// Returns ['token'=>, 'locationId'=>] when GHL is enabled + fully configured, else null.
function cmsGhlConfig() {
    try {
        $file = __DIR__ . '/../data/site.json';
        if (!is_file($file)) return null;
        $site = json_decode(file_get_contents($file), true);
        $ghl  = $site['ghl'] ?? null;
        if (!is_array($ghl) || empty($ghl['enabled'])) return null;
        $loc  = trim((string)($ghl['locationId'] ?? ''));
        if ($loc === '') return null;
        $token = '';
        try { $token = (string)fourgeGetSecret(fourgeDb(), 'ghl_token'); } catch (Throwable $e) { $token = ''; }
        if ($token === '') return null;
        return ['token' => $token, 'locationId' => $loc];
    } catch (Throwable $e) { return null; }
}

// PURE (no network): map a submission (assoc fieldName=>value) to a GHL contact
// payload + a human-readable note body. Detects email/phone/name heuristically.
function cmsGhlMapContact($fields, $locationId, $formName, $siteUrl) {
    $humanize = function ($k) {
        $k = preg_replace('/-[a-z0-9]{2,6}$/i', '', (string)$k);   // drop the "-ab12" id suffix
        $k = trim(preg_replace('/\s+/', ' ', preg_replace('/[_\-]+/', ' ', $k)));
        return $k === '' ? 'Field' : ucwords($k);
    };
    $email = ''; $phone = ''; $first = ''; $last = ''; $full = ''; $noteLines = [];
    foreach ((array)$fields as $k => $v) {
        if (is_array($v)) $v = implode(', ', $v);
        $v = trim((string)$v);
        if ($v === '') continue;
        $key = strtolower((string)$k);
        $noteLines[] = $humanize($k) . ': ' . $v;
        if ($email === '' && (strpos($key, 'email') !== false || filter_var($v, FILTER_VALIDATE_EMAIL))) { $email = $v; continue; }
        if ($phone === '' && (preg_match('/phone|tel|mobile|cell/', $key) || preg_match('/^[\+\(]?[\d][\d\s().\-]{6,}$/', $v))) { $phone = $v; continue; }
        if (preg_match('/first/', $key)) { $first = $v; continue; }
        if (preg_match('/last|surname/', $key)) { $last = $v; continue; }
        if ($full === '' && strpos($key, 'name') !== false && strpos($key, 'user') === false && strpos($key, 'file') === false) { $full = $v; }
    }
    if ($first === '' && $full !== '') { $p = preg_split('/\s+/', $full, 2); $first = $p[0]; $last = $p[1] ?? ''; }
    $tags = array_values(array_filter(['Website Lead', $formName ? ('Form: ' . $formName) : '']));
    $contact = ['locationId' => $locationId, 'tags' => $tags, 'source' => 'Website form' . ($formName ? " ({$formName})" : '')];
    if ($first !== '') $contact['firstName'] = $first;
    if ($last  !== '') $contact['lastName']  = $last;
    if ($first === '' && $last === '' && $full !== '') $contact['name'] = $full;
    if ($email !== '') $contact['email'] = $email;
    if ($phone !== '') $contact['phone'] = $phone;
    $note = 'New website form submission' . ($formName ? " — {$formName}" : '') . "\n\n" . implode("\n", $noteLines);
    if ($siteUrl) $note .= "\n\nPage: " . $siteUrl;
    return ['contact' => $contact, 'note' => $note, 'hasContactInfo' => ($email !== '' || $phone !== '')];
}

// Push a submission to GHL. Best-effort; returns true when the contact upserts.
function cmsGhlPushLead($token, $locationId, $fields, $formName, $siteUrl) {
    $map = cmsGhlMapContact($fields, $locationId, $formName, $siteUrl);
    if (!$map['hasContactInfo']) return false;   // no email/phone → nothing GHL can dedupe or act on
    $headers = ['Authorization: Bearer ' . $token, 'Version: ' . GHL_API_VERSION, 'Content-Type: application/json', 'Accept: application/json'];
    $ch = curl_init(GHL_API_BASE . '/contacts/upsert');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($map['contact']), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 12,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300 || !$res) return false;
    $d = json_decode($res, true);
    $contactId = $d['contact']['id'] ?? ($d['id'] ?? '');
    if ($contactId && $map['note']) {   // attach the full submission as a note (best-effort)
        $ch2 = curl_init(GHL_API_BASE . '/contacts/' . rawurlencode($contactId) . '/notes');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['body' => $map['note']]), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch2); curl_close($ch2);
    }
    return true;
}

// Validate token + location with a lightweight read. Returns [bool ok, string message].
function cmsGhlTest($token, $locationId) {
    $ch = curl_init(GHL_API_BASE . '/contacts/?locationId=' . rawurlencode($locationId) . '&limit=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Version: ' . GHL_API_VERSION, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 12,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err)        return [false, 'Could not reach GoHighLevel: ' . $err];
    if ($code === 200) return [true, 'Connected to GoHighLevel — leads will flow in.'];
    if ($code === 401) return [false, 'Token rejected (401) — double-check the Private Integration Token.'];
    if ($code === 403) return [false, 'Access denied (403) — the token likely needs the Contacts scope, or the Location ID is wrong.'];
    if ($code === 404) return [false, 'Not found (404) — check the Location ID.'];
    return [false, 'GoHighLevel returned HTTP ' . $code];
}

function fourgeApiGhlTest($me, $body) {
    if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required']); return; }
    $token = trim((string)($body['token'] ?? ''));
    if ($token === '') { try { $token = (string)fourgeGetSecret(fourgeDb(), 'ghl_token'); } catch (Throwable $e) {} }
    $loc = trim((string)($body['locationId'] ?? ''));
    if ($token === '' || $loc === '') { echo json_encode(['ok' => false, 'message' => 'Enter the token and Location ID first.']); return; }
    list($ok, $msg) = cmsGhlTest($token, $loc);
    echo json_encode(['ok' => $ok, 'message' => $msg]);
}

// Resolve Mailgun config from the encrypted DB secrets first (server-side,
// editable in Settings), falling back to the config.secret.php constants.
function cmsMailgun() {
    $get = function ($name, $fallback) {
        try { $v = fourgeGetSecret(fourgeDb(), $name); return ($v !== null && $v !== '') ? $v : $fallback; }
        catch (Throwable $e) { return $fallback; }
    };
    return [
        'domain' => $get('mg_domain',    MG_DOMAIN),
        'key'    => $get('mg_api_key',   MG_API_KEY),
        'from'   => $get('mg_from',      MG_FROM),
        'notify' => $get('mg_notify_to', MG_NOTIFY_TO),
    ];
}

function cmsSmtpEnabled() {
    return SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '';
}

// Minimal dependency-free SMTP sender (no PHPMailer needed). Speaks enough of
// ESMTP to authenticate and deliver a multipart/alternative message. $opt keys:
// host, port, secure(auto|tls|ssl|none), user, pass, from, fromName, to, toName,
// replyTo, replyName, subject, html, text. Returns true, or false with $err set.
function cmsSmtpSend($opt, &$err) {
    $host = (string)$opt['host']; $port = (int)$opt['port'];
    $secure = $opt['secure'] ?? 'auto';
    if ($secure === 'auto') $secure = ($port === 465) ? 'ssl' : 'tls';
    // Strip CR/LF/NUL from anything that lands in an SMTP command or a raw header
    // (Reply-To comes from the visitor) to prevent command / header injection.
    $strip = function ($s) { return str_replace(["\r", "\n", "\0"], '', (string)$s); };
    $from = $strip($opt['from']); $to = $strip($opt['to']); $rt = $strip($opt['replyTo'] ?? '');

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connect failed: $errstr ($errno)"; return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $d = '';
        while (($l = fgets($fp, 600)) !== false) {
            $d .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;   // last line of a (possibly multiline) reply
        }
        return $d;
    };
    $put  = function ($s) use ($fp) { fwrite($fp, $s . "\r\n"); };
    $code = function ($r) { return (int)substr($r, 0, 3); };
    $bail = function ($m) use (&$err, $fp) { $err = $m; @fclose($fp); return false; };

    $r = $read(); if ($code($r) !== 220) return $bail('greeting: ' . trim($r));
    $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    $put("EHLO $ehlo"); $r = $read();
    if ($code($r) !== 250) { $put("HELO $ehlo"); $r = $read(); if ($code($r) !== 250) return $bail('EHLO: ' . trim($r)); }

    if ($secure === 'tls') {
        $put("STARTTLS"); $r = $read(); if ($code($r) !== 220) return $bail('STARTTLS: ' . trim($r));
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) return $bail('TLS handshake failed');
        $put("EHLO $ehlo"); $r = $read(); if ($code($r) !== 250) return $bail('EHLO(TLS): ' . trim($r));
    }

    $put("AUTH LOGIN"); $r = $read(); if ($code($r) !== 334) return $bail('AUTH not accepted: ' . trim($r));
    $put(base64_encode((string)$opt['user'])); $r = $read(); if ($code($r) !== 334) return $bail('username stage: ' . trim($r));
    $put(base64_encode((string)$opt['pass'])); $r = $read(); if ($code($r) !== 235) return $bail('login rejected: ' . trim($r));

    $put("MAIL FROM:<$from>"); $r = $read(); if ($code($r) !== 250) return $bail('MAIL FROM: ' . trim($r));
    $put("RCPT TO:<$to>");     $r = $read(); if ($code($r) !== 250 && $code($r) !== 251) return $bail('RCPT TO: ' . trim($r));
    $put("DATA");              $r = $read(); if ($code($r) !== 354) return $bail('DATA: ' . trim($r));

    $enc = function ($s) { return '=?UTF-8?B?' . base64_encode((string)$s) . '?='; };   // RFC2047 for names/subject
    $b = 'fge' . bin2hex(random_bytes(10));
    $H = [];
    $H[] = 'Date: ' . date('r');
    $H[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>';
    $H[] = 'From: ' . (($opt['fromName'] ?? '') !== '' ? $enc($opt['fromName']) . ' ' : '') . "<$from>";
    $H[] = 'To: '   . (($opt['toName'] ?? '')   !== '' ? $enc($opt['toName'])   . ' ' : '') . "<$to>";
    if ($rt !== '') $H[] = 'Reply-To: ' . (!empty($opt['replyName']) ? $enc($opt['replyName']) . ' ' : '') . "<$rt>";
    $H[] = 'Subject: ' . $enc($opt['subject']);
    $H[] = 'MIME-Version: 1.0';
    $H[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
    $M  = implode("\r\n", $H) . "\r\n\r\n";
    $M .= '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$opt['text'])) . "\r\n";
    $M .= '--' . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$opt['html'])) . "\r\n";
    $M .= '--' . $b . "--\r\n";
    $M = preg_replace('/^\./m', '..', $M);   // dot-stuff (defensive; base64 has no leading dots)
    fwrite($fp, $M . "\r\n.\r\n");
    $r = $read(); if ($code($r) !== 250) return $bail('message rejected: ' . trim($r));
    $put("QUIT"); @fclose($fp);
    return true;
}

function cmsSendForm($body) {
    $mg      = cmsMailgun();
    $fields  = $body['fields']  ?? [];
    $subject = $body['subject'] ?? 'New Form Submission';
    $toEmail = $body['to']      ?? $mg['notify'];
    $siteUrl = $body['siteUrl'] ?? '';
    $formId  = $body['formId']  ?? '';
    $rcToken = $body['recaptcha'] ?? '';

    // reCAPTCHA verification (only enforced if a secret is configured in site.json)
    $rcSecret = cmsRecaptchaSecret();
    if ($rcSecret) {
        if (!$rcToken || !cmsVerifyRecaptcha($rcSecret, $rcToken, cmsRecaptchaThreshold())) {
            http_response_code(400);
            echo json_encode(['error' => 'reCAPTCHA verification failed. Please try again.']); return;
        }
    }

    // Store the submission in data/entries.json (best-effort, non-fatal)
    cmsStoreEntry($formId, $fields, $siteUrl);

    // Push into GoHighLevel as a lead (best-effort; never blocks the form or email)
    $ghl = cmsGhlConfig();
    if ($ghl) { try { cmsGhlPushLead($ghl['token'], $ghl['locationId'], $fields, $subject, $siteUrl); } catch (Throwable $e) { /* non-fatal */ } }

    if (!$toEmail) {
        // Entry already stored; report success even without email config
        echo json_encode(['ok' => true, 'stored' => true, 'note' => 'Saved (no email configured)']); return;
    }

    $textLines = []; $htmlRows = '';
    foreach ($fields as $label => $value) {
        $textLines[] = "$label: $value";
        $htmlRows .= '<tr><td style="padding:6px 12px;font-weight:600;width:140px;border-bottom:1px solid #eee">' . htmlspecialchars($label) . '</td><td style="padding:6px 12px;border-bottom:1px solid #eee">' . nl2br(htmlspecialchars($value)) . '</td></tr>';
    }

    $text = implode("\n", $textLines) . "\n\n---\nSent from: $siteUrl";
    $html = '<!DOCTYPE html><html><body style="font-family:Inter,Arial,sans-serif;color:#1A1917;max-width:600px;margin:0 auto;padding:24px">
      <h2 style="font-size:18px">' . htmlspecialchars($subject) . '</h2>
      <p style="color:#857F6E;font-size:13px">From: ' . htmlspecialchars($siteUrl) . '</p>
      <table style="width:100%;border-collapse:collapse;border:1px solid #eee">' . $htmlRows . '</table>
      <p style="font-size:11px;color:#A09882;margin-top:16px">Sent via Fourge CMS · ' . date('Y-m-d H:i') . '</p>
    </body></html>';

    // Reply-To = the first submitted value that looks like an email address.
    $replyTo = ''; foreach ($fields as $val) { $v = trim((string)$val); if (filter_var($v, FILTER_VALIDATE_EMAIL)) { $replyTo = $v; break; } }

    // Prefer SMTP when configured (a migrated site's existing SMTP creds work
    // as-is); otherwise fall through to the Mailgun HTTP API below.
    if (cmsSmtpEnabled()) {
        // From: use the configured mg_from, but ignore the built-in example
        // placeholder and fall back to the SMTP login — always a valid sender on
        // the relay's own domain — so a missing or placeholder mg_from can never
        // make the relay reject the message.
        $fromRaw = trim((string)$mg['from']);
        if ($fromRaw === '' || stripos($fromRaw, 'example.com') !== false) $fromRaw = SMTP_USER;
        $fromEmail = $fromRaw; $fromName = '';
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $fromRaw, $mm)) { $fromName = trim($mm[1], " \"'"); $fromEmail = trim($mm[2]); }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = SMTP_USER;
        $err = '';
        $sent = cmsSmtpSend([
            'host' => SMTP_HOST, 'port' => SMTP_PORT, 'secure' => SMTP_SECURE, 'user' => SMTP_USER, 'pass' => SMTP_PASS,
            'from' => $fromEmail, 'fromName' => $fromName, 'to' => $toEmail, 'toName' => '',
            'replyTo' => $replyTo, 'replyName' => '', 'subject' => $subject, 'html' => $html, 'text' => $text,
        ], $err);
        if ($sent) { echo json_encode(['ok' => true]); }
        else { error_log('Fourge SMTP send failed: ' . $err); http_response_code(500); echo json_encode(['error' => 'Email could not be sent right now. Please try again, or contact us directly.']); }
        return;
    }

    $ch = curl_init('https://api.mailgun.net/v3/' . $mg['domain'] . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => 'api:' . $mg['key'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['from'=>$mg['from'],'to'=>$toEmail,'subject'=>$subject,'text'=>$text,'html'=>$html],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr= curl_error($ch);
    curl_close($ch);

    if ($curlErr) { http_response_code(500); echo json_encode(['error' => 'Mail failed: '.$curlErr]); return; }
    if ($code === 200) { echo json_encode(['ok' => true]); }
    else { $d = json_decode($result, true); http_response_code(500); echo json_encode(['error' => 'Mailgun '.$code.': '.($d['message']??$result)]); }
}

// ── GOOGLE ANALYTICS (GA4 Data API proxy) ───────────────────────────────────
// Credentials: a Google Cloud service-account JSON stored in the admin folder
// (never in public data/). Add the service account email as a Viewer on the
// GA4 property: Admin → Property Access Management.

define('GA_CRED_FILE',  __DIR__ . '/ga-service-account.json');
define('GA_TOKEN_CACHE', __DIR__ . '/.ga-token.json');

function gaSaveCredentials($body) {
    $json = $body['credentials'] ?? '';
    if (!$json) { http_response_code(400); echo json_encode(['error' => 'No credentials provided']); return; }
    $cred = json_decode($json, true);
    if (!$cred || empty($cred['client_email']) || empty($cred['private_key']) || ($cred['type'] ?? '') !== 'service_account') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid service account JSON — expected keys: type=service_account, client_email, private_key']);
        return;
    }
    if (file_put_contents(GA_CRED_FILE, json_encode($cred)) === false) {
        http_response_code(500); echo json_encode(['error' => 'Could not write credentials file']); return;
    }
    @chmod(GA_CRED_FILE, 0600);
    @unlink(GA_TOKEN_CACHE); // force new token with new creds
    echo json_encode(['ok' => true, 'client_email' => $cred['client_email']]);
}

function gaStatus() {
    if (!file_exists(GA_CRED_FILE)) { echo json_encode(['configured' => false]); return; }
    $cred = json_decode(file_get_contents(GA_CRED_FILE), true);
    echo json_encode(['configured' => true, 'client_email' => $cred['client_email'] ?? '']);
}

function gaAccessToken() {
    if (!file_exists(GA_CRED_FILE)) throw new Exception('No Google service account uploaded yet (Analytics → Setup)');
    $cred = json_decode(file_get_contents(GA_CRED_FILE), true);
    if (!$cred) throw new Exception('Credentials file is corrupt');

    // Cached token still valid?
    if (file_exists(GA_TOKEN_CACHE)) {
        $c = json_decode(file_get_contents(GA_TOKEN_CACHE), true);
        if ($c && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    }

    $b64 = function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
    $now = time();
    $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode([
        'iss'   => $cred['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $ok = openssl_sign($header . '.' . $claims, $sig, $cred['private_key'], 'sha256WithRSAEncryption');
    if (!$ok) throw new Exception('JWT signing failed — check the private_key in the service account JSON');
    $jwt = $header . '.' . $claims . '.' . $b64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err)  throw new Exception('Token request failed: ' . $err);
    $d = json_decode($res, true);
    if ($code !== 200 || empty($d['access_token'])) {
        throw new Exception('Google token error: ' . ($d['error_description'] ?? $d['error'] ?? ('HTTP ' . $code)));
    }
    file_put_contents(GA_TOKEN_CACHE, json_encode(['token' => $d['access_token'], 'exp' => $now + (int)($d['expires_in'] ?? 3600)]));
    @chmod(GA_TOKEN_CACHE, 0600);
    return $d['access_token'];
}

function gaReport($body) {
    $property = preg_replace('/[^0-9]/', '', $body['propertyId'] ?? '');
    $kind     = ($body['kind'] ?? 'report') === 'realtime' ? 'runRealtimeReport' : 'runReport';
    $request  = $body['request'] ?? null;
    if (!$property) { http_response_code(400); echo json_encode(['error' => 'Missing or invalid GA4 numeric property ID']); return; }
    if (!is_array($request)) { http_response_code(400); echo json_encode(['error' => 'Missing report request body']); return; }
    try {
        $token = gaAccessToken();
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => $e->getMessage()]); return;
    }
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $property . ':' . $kind;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($request),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { http_response_code(500); echo json_encode(['error' => 'GA API request failed: ' . $err]); return; }
    if ($code !== 200) {
        $d = json_decode($res, true);
        $msg = $d['error']['message'] ?? ('HTTP ' . $code);
        if (strpos($msg, 'permission') !== false || $code === 403) {
            $cred = json_decode(@file_get_contents(GA_CRED_FILE), true);
            $msg .= ' — add ' . ($cred['client_email'] ?? 'the service account email') . ' as a Viewer in GA Admin → Property Access Management';
        }
        http_response_code(502); echo json_encode(['error' => 'GA API: ' . $msg]); return;
    }
    echo $res; // pass through Google's JSON
}


// ── CLAUDE AI PROXY ─────────────────────────────────────────────────────────
// Server-side proxy so the Anthropic API key never reaches the browser.
// The browser sends {model, messages, system, tools, max_tokens, thinking, user}
// and this function makes the actual Anthropic call using the key stored in
// admin/config.secret.php (never exposed to the client).
//
// Enforces, in order: AI must be configured, the requesting user must have the
// 'aiEdit' permission, and a per-user hourly rate limit.

function foundrySecret() {
    static $cfg = null;
    if ($cfg === null) {
        $f = __DIR__ . '/config.secret.php';
        $cfg = file_exists($f) ? (include $f) : [];
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}

// Mirror of the client's getEffectivePerms(): role defaults + per-user overrides.
// Reads from the SQLite DB (the source of truth for accounts).
function foundryUserCanAI($username) {
    $roleDefaults = ['superadmin' => true, 'admin' => true, 'editor' => false];
    try { $rec = fourgeGetUser(fourgeDb(), $username); } catch (Throwable $e) { $rec = null; }
    if (!$rec) return false;
    // Explicit per-user override wins (permissions.aiEdit), else role default
    if (!empty($rec['permissions'])) {
        $perms = json_decode($rec['permissions'], true);
        if (is_array($perms) && array_key_exists('aiEdit', $perms)) return (bool)$perms['aiEdit'];
    }
    return !empty($roleDefaults[$rec['role'] ?? 'editor']);
}

// Per-user hourly rate limit, tracked in data/ai_usage.json (best-effort).
function foundryRateOk($username, $limitPerHour) {
    if ($limitPerHour <= 0) return true; // 0 = unlimited
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/ai_usage.json';
    $now  = time();
    $hour = (int)floor($now / 3600);
    $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    // prune old hours
    foreach ($data as $u => $rec) {
        if (($rec['hour'] ?? 0) !== $hour) unset($data[$u]);
    }
    $key = (string)$username ?: 'anon';
    $cur = ($data[$key]['hour'] ?? 0) === $hour ? (int)($data[$key]['count'] ?? 0) : 0;
    if ($cur >= $limitPerHour) return false;
    $data[$key] = ['hour' => $hour, 'count' => $cur + 1];
    @file_put_contents($file, json_encode($data));
    return true;
}

function claudeProxy($body) {
    $secretPath = __DIR__ . '/config.secret.php';
    $cfg = foundrySecret();
    // Prefer the Architect-managed key stored (encrypted) in the DB; fall back
    // to the static anthropic_key in config.secret.php.
    $key = '';
    try { $key = (string)fourgeGetSecret(fourgeDb(), 'claude_key'); } catch (Throwable $e) { $key = ''; }
    if ($key === '') $key = trim($cfg['anthropic_key'] ?? '');
    if (!$key || strpos($key, 'REPLACE') !== false) {
        http_response_code(500);
        // Diagnostic: report exactly which condition failed so setup is unambiguous.
        // Deep diagnostic: report exactly what the server actually loaded.
        $diag = [];
        $diag['expected_path'] = $secretPath;
        $diag['file_exists']   = file_exists($secretPath);
        // Also probe a capitalized variant in case of a stray duplicate
        $altPath = __DIR__ . '/Config.secret.php';
        $diag['capital_C_variant_exists'] = file_exists($altPath);
        $diag['returned_type'] = gettype($cfg);
        $diag['is_array']      = is_array($cfg);
        $diag['array_keys']    = is_array($cfg) ? array_keys($cfg) : null;
        $diag['key_present']   = is_array($cfg) && array_key_exists('anthropic_key', $cfg);
        $diag['key_length']    = is_string($key) ? strlen($key) : 0;
        // Masked preview so we can see if it read a real key without exposing it
        if (is_string($key) && strlen($key) > 12) {
            $diag['key_preview'] = substr($key, 0, 8) . '...' . substr($key, -4);
        } else {
            $diag['key_preview'] = $key;
        }
        $diag['has_REPLACE']   = (is_string($key) && strpos($key, 'REPLACE') !== false);

        if (!file_exists($secretPath)) {
            $reason = 'config.secret.php NOT FOUND at expected path.';
        } elseif (!is_array($cfg)) {
            $reason = 'config.secret.php was found but did NOT return an array (likely a PHP parse error in the file — check for smart-quotes or a stray character).';
        } elseif (!array_key_exists('anthropic_key', $cfg)) {
            $reason = 'File returned an array but has no "anthropic_key" entry. Keys found: ' . implode(', ', array_keys($cfg));
        } elseif (!$key) {
            $reason = 'anthropic_key is present but EMPTY.';
        } else {
            $reason = 'anthropic_key still contains placeholder text "REPLACE".';
        }
        echo json_encode(['error' => 'AI not configured: ' . $reason, 'diag' => $diag]);
        return;
    }

    // Identify the requesting user (sent by the client from its session)
    $username = $body['user'] ?? '';
    if (!foundryUserCanAI($username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account does not have AI editing enabled. Ask an admin to grant the AI Edit module.']);
        return;
    }

    $limit = (int)($cfg['ai_rate_per_hour'] ?? 40);
    if (!foundryRateOk($username, $limit)) {
        http_response_code(429);
        echo json_encode(['error' => 'AI usage limit reached for this hour (' . $limit . '). Try again later.']);
        return;
    }

    // Build the Anthropic request from the client-provided fields (allow-listed)
    $payload = [
        'model'      => $body['model']      ?? 'claude-opus-4-8',
        'max_tokens' => (int)($body['max_tokens'] ?? 4096),
        'messages'   => $body['messages']   ?? [],
    ];
    if (!empty($body['system']))   $payload['system']   = $body['system'];
    if (!empty($body['tools']))    $payload['tools']    = $body['tools'];
    if (!empty($body['thinking'])) $payload['thinking'] = $body['thinking'];

    if (!is_array($payload['messages']) || !count($payload['messages'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No messages provided']);
        return;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { http_response_code(502); echo json_encode(['error' => 'AI request failed: ' . $err]); return; }
    // Pass Anthropic's JSON straight through (success or structured error),
    // preserving the upstream status code so the client's retry logic works.
    http_response_code($code ?: 200);
    echo $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// AUTH + ACCOUNTS + SECRETS  (SQLite-backed — see db.php)
// ─────────────────────────────────────────────────────────────────────────────

function fourgeApiLogin($body) {
    $pdo = fourgeDb();
    // The 'username' field may carry a username OR an email address.
    $identifier = trim($body['username'] ?? '');
    $password   = (string)($body['password'] ?? '');
    if ($identifier === '' || $password === '') {
        http_response_code(400); echo json_encode(['error' => 'Enter your username or email and password']); return;
    }
    $user = fourgeGetUserByLogin($pdo, $identifier);
    if (!$user || !fourgeVerifyPassword($pdo, $user, $password)) {
        // Team onboarding: a NEW email on the agency domain + the shared onboard
        // password self-provisions an editor account (forced password change on
        // first login). Only when no account exists for that email — never an
        // override of an existing login. See the ONBOARD_* config above.
        $user = fourgeTryOnboard($pdo, $identifier, $password);
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Invalid login or password']); return; }
    }
    $token = fourgeCreateSession($pdo, $user['username']);
    $user  = fourgeGetUser($pdo, $user['username']); // reload — verify may have upgraded the hash
    echo json_encode(['ok' => true, 'token' => $token, 'user' => fourgePublicUser($user)]);
}

// Self-provision an editor account for a NEW email on the agency domain when the
// shared onboard password is supplied. Returns the freshly-created user row, or
// null when onboarding doesn't apply: feature off, wrong password, not an email
// on ONBOARD_EMAIL_DOMAIN, or an account already exists for that email (we never
// override an existing login). must_change_password=1 forces a new password on
// first login; until then this same onboard password authenticates the account.
function fourgeTryOnboard($pdo, $identifier, $password) {
    if (ONBOARD_PASSWORD === '') return null;                            // feature off
    if (!hash_equals(ONBOARD_PASSWORD, (string)$password)) return null;  // constant-time
    $email = strtolower(trim((string)$identifier));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;         // must be an email
    $at     = strrpos($email, '@');
    $domain = $at === false ? '' : substr($email, $at + 1);
    if ($domain === '' || $domain !== ONBOARD_EMAIL_DOMAIN) return null; // exact domain match
    if (fourgeLoginTaken($pdo, $email, $email, 0)) return null;          // never override an existing account
    $now  = date('c');
    $hash = password_hash($password, fourgePwAlgo());
    $pdo->prepare("INSERT INTO users (username, display_name, email, first_name, last_name, role, is_architect, password_hash, must_change_password, permissions, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$email, $email, $email, '', '', 'editor', 0, $hash, 1, null, $now, $now]);
    return fourgeGetUser($pdo, $email);
}

function fourgeApiLogout($token) {
    fourgeDeleteSession(fourgeDb(), $token);
    echo json_encode(['ok' => true]);
}

function fourgeApiListUsers($me) {
    $pdo = fourgeDb();
    $myLevel = fourgeLevel($me);
    $rows = $pdo->query("SELECT * FROM users")->fetchAll();
    $out = [];
    foreach ($rows as $u) {
        if (strtolower($u['username']) === strtolower($me['username'])) continue; // hide self
        if (fourgeLevel($u) > $myLevel) continue;                                  // never show higher access
        $out[] = fourgePublicUser($u);
    }
    usort($out, function ($a, $b) { return [$b['role'], $a['username']] <=> [$a['role'], $b['username']]; });
    echo json_encode(['ok' => true, 'users' => $out, 'me' => fourgePublicUser($me)]);
}

function fourgeApiSaveUser($me, $body) {
    $pdo = fourgeDb();
    $myLevel = fourgeLevel($me);
    $id = (isset($body['id']) && $body['id'] !== '' && $body['id'] !== null) ? (int)$body['id'] : 0;

    $role = $body['role'] ?? 'editor';
    if (!in_array($role, ['editor', 'admin', 'superadmin'], true)) $role = 'editor';
    $roleLevel = $role === 'superadmin' ? 3 : ($role === 'admin' ? 2 : 1);
    if ($roleLevel > $myLevel) { http_response_code(403); echo json_encode(['error' => 'You cannot assign a role above your own']); return; }

    $username = strtolower(trim($body['username'] ?? ''));
    $email    = trim($body['email'] ?? '');
    $first    = trim($body['firstName'] ?? '');
    $last     = trim($body['lastName'] ?? '');
    if ($username === '' && $email !== '') $username = strtolower($email);   // default username to the email
    if ($username === '') { http_response_code(400); echo json_encode(['error' => 'A username or email is required']); return; }

    $pw          = (string)($body['password'] ?? '');
    $permissions = array_key_exists('permissions', $body) ? json_encode($body['permissions']) : null;
    if ($role !== 'editor') $permissions = null;  // admin/superadmin inherit all modules
    $mustChange  = !empty($body['mustChangePassword']) ? 1 : 0;
    $display     = trim("$first $last");
    $now = date('c');

    if ($id) {
        // ── EDIT ──
        $st = $pdo->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$id]);
        $existing = $st->fetch();
        if (!$existing) { http_response_code(404); echo json_encode(['error' => 'User not found']); return; }
        if (!empty($existing['is_architect']) && empty($me['is_architect'])) {
            http_response_code(403); echo json_encode(['error' => 'Only the Architect can modify the Architect account']); return;
        }
        if (fourgeLevel($existing) > $myLevel) {
            http_response_code(403); echo json_encode(['error' => 'You cannot manage that account']); return;
        }
        if (fourgeLoginTaken($pdo, $username, $email, $id)) {
            http_response_code(409); echo json_encode(['error' => 'That username or email is already in use']); return;
        }
        if ($permissions === null) $permissions = $existing['permissions'] ?? null;
        if ($role !== 'editor') $permissions = null;
        if ($display === '') $display = $existing['display_name'] ?? $username;
        if ($pw !== '') {
            if (strlen($pw) < 8) { http_response_code(400); echo json_encode(['error' => 'Password must be at least 8 characters']); return; }
            fourgeSetPassword($pdo, $existing['username'], $pw);
        }
        $pdo->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, display_name=?, role=?, permissions=?, must_change_password=?, updated_at=? WHERE id=?")
            ->execute([$username, $email, $first, $last, $display, $role, $permissions, $mustChange, $now, $id]);
        if (strtolower($existing['username']) !== $username) {          // keep sessions valid across a username change
            $pdo->prepare("UPDATE sessions SET username=? WHERE username=?")->execute([$username, $existing['username']]);
        }
    } else {
        // ── CREATE ──
        if (strlen($pw) < 8) { http_response_code(400); echo json_encode(['error' => 'New accounts need a password of at least 8 characters']); return; }
        if (fourgeLoginTaken($pdo, $username, $email, 0)) {
            http_response_code(409); echo json_encode(['error' => 'That username or email is already in use']); return;
        }
        if ($display === '') $display = $username;
        $hash = password_hash($pw, fourgePwAlgo());
        $pdo->prepare("INSERT INTO users (username, display_name, email, first_name, last_name, role, is_architect, password_hash, must_change_password, permissions, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$username, $display, $email, $first, $last, $role, 0, $hash, $mustChange, $permissions, $now, $now]);
    }
    echo json_encode(['ok' => true]);
}

function fourgeApiDeleteUser($me, $body) {
    $pdo = fourgeDb();
    $username = strtolower(trim($body['username'] ?? ''));
    if ($username === '') { http_response_code(400); echo json_encode(['error' => 'username required']); return; }
    if ($username === strtolower($me['username'])) { http_response_code(400); echo json_encode(['error' => "You can't delete your own account"]); return; }
    $u = fourgeGetUser($pdo, $username);
    if (!$u) { http_response_code(404); echo json_encode(['error' => 'User not found']); return; }
    if (!empty($u['is_architect'])) { http_response_code(403); echo json_encode(['error' => 'The Architect account cannot be deleted']); return; }
    if (fourgeLevel($u) > fourgeLevel($me)) { http_response_code(403); echo json_encode(['error' => 'You cannot delete that account']); return; }
    $pdo->prepare("DELETE FROM users WHERE username=?")->execute([$username]);
    $pdo->prepare("DELETE FROM sessions WHERE username=?")->execute([$username]);
    echo json_encode(['ok' => true]);
}

function fourgeApiChangePassword($me, $body) {
    $pdo = fourgeDb();
    $new = (string)($body['new'] ?? '');
    if (strlen($new) < 8) { http_response_code(400); echo json_encode(['error' => 'Password must be at least 8 characters']); return; }
    $u = fourgeGetUser($pdo, $me['username']);
    if (!$u) { http_response_code(404); echo json_encode(['error' => 'Account not found']); return; }
    // Normal change requires the current password. A forced first-login change
    // (must_change flag set) is authorized by the valid session alone.
    if (empty($u['must_change_password'])) {
        if (!fourgeVerifyPassword($pdo, $u, (string)($body['old'] ?? ''))) {
            http_response_code(403); echo json_encode(['error' => 'Current password is incorrect']); return;
        }
    }
    fourgeSetPassword($pdo, $u['username'], $new);
    $pdo->prepare("UPDATE users SET must_change_password=0, updated_at=? WHERE username=?")->execute([date('c'), $u['username']]);
    echo json_encode(['ok' => true]);
}

// Secrets whose cleartext the browser legitimately needs (the Architect's
// browser publishes to GitHub directly; the Mailgun routing fields are shown so
// they can be edited). The Mailgun API KEY stays status-only — never sent back.
function fourgeClientFullSecrets() { return ['github_pat', 'repo_override', 'mg_domain', 'mg_from', 'mg_notify_to']; }

function fourgeApiGetSecrets($me) {
    $pdo = fourgeDb();
    $myLevel = fourgeLevel($me);
    $full = fourgeClientFullSecrets();
    $secrets = []; $status = [];
    foreach (fourgeSecretPolicy() as $name => $lvl) {
        if ($myLevel < $lvl) continue;
        $val = fourgeGetSecret($pdo, $name);
        $status[$name] = ($val !== null && $val !== '');
        if (in_array($name, $full, true) && $val !== null) $secrets[$name] = $val;
    }
    // Cast to objects so empty maps serialize as {} (not []) for the client.
    echo json_encode(['ok' => true, 'secrets' => (object)$secrets, 'status' => (object)$status, 'level' => $myLevel]);
}

function fourgeApiSetSecret($me, $body) {
    $pdo   = fourgeDb();
    $name  = (string)($body['name'] ?? '');
    $value = (string)($body['value'] ?? '');
    if (!array_key_exists($name, fourgeSecretPolicy())) {
        http_response_code(400); echo json_encode(['error' => 'Unknown setting: ' . htmlspecialchars($name)]); return;
    }
    if (fourgeLevel($me) < fourgeSecretLevel($name)) {
        http_response_code(403); echo json_encode(['error' => 'You do not have access to that setting']); return;
    }
    if ($value === '') {
        $pdo->prepare("DELETE FROM secrets WHERE name=?")->execute([$name]); // empty = clear
    } else {
        fourgeSetSecret($pdo, $name, $value, $me['username']);
    }
    echo json_encode(['ok' => true]);
}

// ── PER-PAGE PASSWORD PROTECTION (PHP session gate) ─────────────────────────────
// Stores a bcrypt hash per protected page in admin/protect.secret.php (a .php file,
// never served as source), (re)writes the public _fourge_gate.php, and maintains a
// managed RewriteRule block in the root .htaccess so protected pages route through
// the gate. The gate shows a branded password form and serves the page only after
// the visitor unlocks it (PHP session).
function fourgeProtectStorePath() { return __DIR__ . '/protect.secret.php'; }
function fourgeLoadProtectMap() {
    $f = fourgeProtectStorePath();
    if (is_file($f)) { $m = include $f; if (is_array($m)) return $m; }
    return [];
}
function fourgeSaveProtectMap($map) {
    $out = "<?php\n// Fourge per-page password hashes — NEVER served as source (PHP executes this).\n// Managed by the CMS; do not edit by hand.\nreturn " . var_export($map, true) . ";\n";
    return file_put_contents(fourgeProtectStorePath(), $out) !== false;
}
function fourgeWriteGateFile() {
    $src = <<<'GATE'
<?php
// Fourge page gate — protects the pages listed in admin/protect.secret.php.
// Managed by the CMS; do not edit by hand.
$store = __DIR__ . '/admin/protect.secret.php';
$map = is_file($store) ? (include $store) : array();
if (!is_array($map)) $map = array();
$p = isset($_GET['p']) ? (string)$_GET['p'] : '';
$p = ltrim(str_replace('\\', '/', $p), '/');
if ($p === '' || strpos($p, '..') !== false || !array_key_exists($p, $map)) { http_response_code(404); echo 'Not found'; exit; }
$file = realpath(__DIR__ . '/' . $p);
$root = realpath(__DIR__);
if (!$file || strpos($file, $root) !== 0 || !is_file($file)) { http_response_code(404); echo 'Not found'; exit; }
$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '') === 'https');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>$secure));
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
}
session_name('fourge_gate');
session_start();
if (!empty($_SESSION['fourge_unlocked'][$p])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    readfile($file); exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = isset($_POST['fourge_pw']) ? (string)$_POST['fourge_pw'] : '';
    if ($pw !== '' && password_verify($pw, $map[$p])) {
        session_regenerate_id(true);
        $_SESSION['fourge_unlocked'][$p] = true;
        header('Location: /' . $p); exit;
    }
    usleep(400000);
    $err = 'Incorrect password. Please try again.';
}
// 401 for every form render (visitor is not unlocked). No WWW-Authenticate header,
// so browsers show this HTML form rather than the native Basic-Auth popup.
http_response_code(401);
$e = function($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Protected page</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f2ee;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1814;padding:20px}
.card{background:#fff;border:1px solid #e6e1d8;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);padding:34px 30px;width:100%;max-width:380px;text-align:center}
.lock{width:46px;height:46px;border-radius:12px;background:#fdf0e8;color:#c8531e;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
h1{font-size:18px;margin:0 0 6px}p.sub{font-size:13px;color:#6b6557;margin:0 0 20px}
input[type=password]{width:100%;padding:11px 13px;border:1px solid #d9d3c8;border-radius:10px;font-size:14px;margin-bottom:12px}
input[type=password]:focus{outline:none;border-color:#c8531e;box-shadow:0 0 0 3px rgba(200,83,30,.12)}
button{width:100%;padding:11px;background:#c8531e;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:#b0481a}.err{background:#fceae6;color:#b3261e;border:1px solid #f3c0bb;border-radius:8px;padding:8px 10px;font-size:12px;margin-bottom:12px}
</style></head>
<body><div class="card">
<div class="lock"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></div>
<h1>This page is protected</h1><p class="sub">Enter the password to continue.</p>
<?php if ($err) echo '<div class="err">' . $e($err) . '</div>'; ?>
<form method="post" action="/<?php echo $e($p); ?>">
<input type="password" name="fourge_pw" placeholder="Password" autofocus autocomplete="current-password">
<button type="submit">Unlock</button>
</form>
</div></body></html>
GATE;
    return file_put_contents(PUBLIC_HTML . '/_fourge_gate.php', $src) !== false;
}
function fourgeWriteProtectHtaccess($paths) {
    $htPath  = PUBLIC_HTML . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Protected Pages';
    $end   = '# END Fourge Protected Pages';
    $rules = '';
    if ($paths) {
        $rules = "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
        foreach ($paths as $p) {
            $pat = str_replace('.', '\\.', $p);
            $rules .= 'RewriteRule ^' . $pat . '$ /_fourge_gate.php?p=' . $p . ' [L,QSA]' . "\n";
        }
        $rules .= "</IfModule>\n";
    }
    $block = $begin . "\n" . $rules . $end;
    if (strpos($existing, $begin) !== false && strpos($existing, $end) !== false) {
        $existing = preg_replace('/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s', $block, $existing);
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// Clean URLs: serve /page from /page.html and 301 the .html form away, so each
// page has one extensionless address. Managed as its own delimited block so it
// coexists with the HTTPS redirect and the password-gate block. The CMS calls
// this (via install_clean_urls) whenever it applies SEO — and once per session
// on load — so the server rule and the extensionless links the CMS writes into
// pages always ship together and a site can't advertise URLs it can't serve.
function fourgeWriteCleanUrlHtaccess() {
    $htPath   = PUBLIC_HTML . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Clean URLs';
    $end   = '# END Fourge Clean URLs';
    // Nowdoc (single-quoted) so \s \. $1 %1 are all taken literally, and the
    // closing marker sits at column 0 for pre-7.3 PHP compatibility.
    $rules = <<<'HT'
<IfModule mod_rewrite.c>
  RewriteEngine On
  # /index.html -> the site root (one canonical home URL)
  RewriteCond %{REQUEST_URI} !^/(admin|data)/ [NC]
  RewriteCond %{THE_REQUEST} \s/+index\.html?[\s?] [NC]
  RewriteRule ^ / [R=301,L]
  # Any explicit .html request -> its extensionless URL (301)
  RewriteCond %{REQUEST_URI} !^/(admin|data)/ [NC]
  RewriteCond %{THE_REQUEST} \s/+(.+?)\.html[\s?] [NC]
  RewriteRule ^ /%1 [R=301,L]
  # Extensionless request -> serve the matching .html file when it exists
  RewriteCond %{REQUEST_URI} !^/(admin|data)/ [NC]
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME}\.html -f
  RewriteRule ^(.+?)/?$ $1.html [L]
</IfModule>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        // Replace the existing managed block in place (substr splice, NOT
        // preg_replace: the block contains $1, which preg would treat as a
        // backreference).
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
function fourgeApiInstallCleanUrls($me) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    if (!fourgeWriteCleanUrlHtaccess()) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write .htaccess (check that the site root is writable by PHP)']);
        return;
    }
    echo json_encode(['ok' => true]);
}
function fourgeApiSetPagePassword($me, $body) {
    $path = (string)($body['path'] ?? '');
    $password = (string)($body['password'] ?? '');
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $lower = strtolower($path);
    if ($path === '' || strpos($path, '..') !== false) {
        http_response_code(400); echo json_encode(['error' => 'Invalid path']); return;
    }
    if (strpos($lower, 'admin/') === 0 || strpos($lower, 'data/') === 0 || !preg_match('/\.html?$/', $lower)) {
        http_response_code(400); echo json_encode(['error' => 'Only site .html pages can be password-protected']); return;
    }
    $map = fourgeLoadProtectMap();
    if ($password === '') {
        unset($map[$path]);                          // disable protection
    } else {
        if (strlen($password) < 4) {
            http_response_code(400); echo json_encode(['error' => 'Password must be at least 4 characters']); return;
        }
        $map[$path] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (!fourgeSaveProtectMap($map))   { http_response_code(500); echo json_encode(['error' => 'Could not save the password store']); return; }
    if (!fourgeWriteGateFile())        { http_response_code(500); echo json_encode(['error' => 'Could not write the page gate']); return; }
    if (!fourgeWriteProtectHtaccess(array_keys($map))) { http_response_code(500); echo json_encode(['error' => 'Could not update .htaccess']); return; }
    echo json_encode(['ok' => true, 'protected' => array_keys($map)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// SELF-UPDATE FETCH
// Fetches a single CMS engine file from the template repo so the browser can
// write it back to THIS server. Public repos resolve over raw.githubusercontent
// with no auth; PRIVATE repos use the server-held github_pat via the GitHub
// Contents API. Locked down: session-only, an explicit path allow-list (never
// api.php / config.secret.php / site data), and a strict owner/repo shape.
// ─────────────────────────────────────────────────────────────────────────────
function fourgeUpdateFetchAllow() {
    return [
        'admin/version.json',
        'admin/index.html',
        'admin/db.php',
        'block-renderer.jsx',
        'blog-post.jsx',
        'interior-shell.jsx',
        'posts.jsx',
        'preview.html',
        'adaptify.js',
        'admin/api.php',
    ];
}

function fourgeApiRepoFetch($me, $body) {
    $repo   = trim($body['repo'] ?? '');
    $branch = trim($body['branch'] ?? 'main');
    if ($branch === '') $branch = 'main';
    $path   = trim($body['path'] ?? '');

    if (!in_array($path, fourgeUpdateFetchAllow(), true)) {
        http_response_code(400);
        echo json_encode(['error' => 'That file is not part of the update set.']);
        return;
    }
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$~', $repo)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad repository (expected owner/repo).']);
        return;
    }
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]*$~', $branch)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad branch name.']);
        return;
    }

    // PRIVATE repos: authenticated GitHub Contents API (raw media type).
    $pat = null;
    try { $pat = fourgeGetSecret(fourgeDb(), 'github_pat'); } catch (Throwable $e) { $pat = null; }
    $patNote = ' No GitHub token is saved (Settings → GitHub), which a private repo needs.';
    if ($pat) {
        $api = "https://api.github.com/repos/{$repo}/contents/" . $path . '?ref=' . rawurlencode($branch);
        $ch  = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $pat,
                'Accept: application/vnd.github.raw',
                'User-Agent: Fourge-CMS-Updater',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!$err && $code >= 200 && $code < 300 && $res !== '') {
            echo json_encode(['ok' => true, 'content' => $res, 'source' => 'api', 'branch' => $branch]);
            return;
        }
        // Capture WHY the authenticated call failed, so the client can guide the user.
        if ($err) {
            $patNote = ' GitHub API request failed: ' . $err . '.';
        } else {
            $msg = '';
            $j = json_decode((string)$res, true);
            if (is_array($j) && !empty($j['message'])) $msg = $j['message'];
            if ($code === 401)      $patNote = ' The saved GitHub token is invalid or expired (401).';
            elseif ($code === 404)  $patNote = ' The saved GitHub token cannot see ' . $repo . ' or that file (404) — give the token access to this private repo.';
            elseif ($code === 403)  $patNote = ' GitHub denied the token (403' . ($msg ? ': ' . $msg : '') . ') — check its scope/rate limit.';
            else                    $patNote = ' GitHub API responded ' . $code . ($msg ? ' ("' . $msg . '")' : '') . '.';
        }
        // fall through to public raw as a last resort
    }

    // PUBLIC repos: raw.githubusercontent (no token).
    $rawUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/" . $path;
    $ch = curl_init($rawUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: Fourge-CMS-Updater'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { http_response_code(502); echo json_encode(['error' => 'Repo fetch failed: ' . $err]); return; }
    if ($code < 200 || $code >= 300) {
        http_response_code(502);
        echo json_encode(['error' => "Couldn't fetch {$path} from {$repo}@{$branch} (HTTP {$code})." . ($code === 404 ? $patNote : '')]);
        return;
    }
    echo json_encode(['ok' => true, 'content' => $res, 'source' => 'raw', 'branch' => $branch]);
}
