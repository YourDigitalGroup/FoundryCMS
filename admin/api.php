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
// Record where the mail credentials ultimately came from so the "Send test email"
// diagnostic can show it (config.secret.php vs the legacy send.php vs none).
$GLOBALS['__mail_source'] = (!empty($__secret['smtp_host']) || !empty($__secret['mg_api_key'])) ? 'config.secret.php' : 'none';
if (empty($__secret['smtp_host']) && empty($__secret['mg_api_key'])) {
    $__legacy = fourgeLegacySendConfig(dirname(__DIR__));
    if ($__legacy) { $__secret = array_merge($__legacy, $__secret); $GLOBALS['__mail_source'] = 'send.php'; }
}

define('API_TOKEN',    (string)($__secret['api_token'] ?? 'CHANGE_ME')); // optional now (login uses sessions); kept for legacy/external callers
define('PUBLIC_HTML',  realpath(dirname(__DIR__)));

// ── Not hoisted ──────────────────────────────────────────────────────────────
// PHP defines a top-level `const` only when execution reaches its line. The
// request dispatcher below runs handlers long before the bottom of this file, so
// every constant a handler may touch has to be declared up here — declared next
// to the function that uses it, it is "Undefined constant" at request time (which
// is exactly how form file uploads and the GitHub mirror hooks failed).
// Form uploads (fourgeStoreFormUpload): accepted types and per-file size cap.
const FOURGE_UPLOAD_ALLOWED_EXT = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','webp','txt','csv','zip'];
const FOURGE_UPLOAD_MAX_BYTES = 10485760;   // 10 MB per file
// GitHub mirror (cmsGhShouldMirror / cmsGhMaxBytes): folders never mirrored, and
// the largest file the mirror will push — see the policy comment by those functions.
const FOURGE_GH_SKIP_DIRS = ['.git', 'admin', 'node_modules', 'cgi-bin', 'data/uploads'];
const FOURGE_GH_MAX_BYTES = 20971520;

// Mailgun (forms)
define('MG_DOMAIN',    (string)($__secret['mg_domain']    ?? 'mg.example.com'));
define('MG_API_KEY',   (string)($__secret['mg_api_key']   ?? ''));
define('MG_FROM',      (string)($__secret['mg_from']      ?? 'Fourge CMS <postmaster@mg.example.com>'));
define('MG_NOTIFY_TO', (string)($__secret['mg_notify_to'] ?? ''));

// GoHighLevel (Lead Generation) — must be defined BEFORE the request dispatch
// below, since define() runs top-to-bottom (functions are hoisted; constants are not).
define('GHL_API_BASE',    'https://services.leadconnectorhq.com');
define('GHL_API_VERSION', '2021-07-28');

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
// $_GET is honored last so the pretty machine endpoints installed in .htaccess
// (/api/seo-platform/package → admin/api.php?action=seo_package) can carry the
// action while the POST body is the raw package JSON.
$action = $body['action'] ?? $_POST['action'] ?? ($_GET['action'] ?? '');

// ── AUTH MODEL ────────────────────────────────────────────────────────────────
//  • 'login' is public — it verifies the email/password itself.
//  • 'send_form' is public — it's the public contact-form endpoint. Site visitors
//    have no token or session, so it must be reachable without auth; spam is held
//    off by the optional reCAPTCHA check inside cmsSendForm.
//  • Account + secret actions require a valid session token (from login).
//  • Legacy file / GA / AI actions accept the shared API_TOKEN OR a session token.
//  • 'seo_package' / 'seo_pkg_tick' are listed public ONLY so the machine-to-machine
//    Bearer-token path can reach them; both handlers authenticate internally
//    (session OR constant-time Bearer match) and refuse everything else.
//    'blog_sync_tick' is the same shape, for the same reason: an external
//    scheduler needs to reach it with no session at all.
//  • 'check_form_password' / 'form_view' are public for the same reason as
//    'send_form': a site visitor has no token or session. Neither exposes
//    anything beyond a single form's own pass/fail check or view counter.
$PUBLIC_ACTIONS  = ['login', 'send_form', 'check_form_password', 'form_view', 'seo_package', 'seo_pkg_tick', 'blog_sync_tick', 'blog_sync_poke'];
$SESSION_ACTIONS = ['logout','session','list_users','save_user','delete_user','change_password','get_secrets','set_secret','repo_fetch','set_page_password','install_clean_urls','ghl_test','ghl_dashboard','ghl_messages','ghl_send','ghl_form_def','gh_mirror','gh_set_private','gh_sync_all','send_test_email','recaptcha_status','seo_pkg_admin','ai_endpoint_test','blog_sync_admin'];

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
$HTTPS_REQUIRED_ACTIONS = ['login', 'change_password', 'set_secret', 'save_user', 'ga_save_credentials', 'set_page_password', 'seo_pkg_admin', 'blog_sync_admin'];
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
        case 'ghl_dashboard':   ob_end_clean(); fourgeApiGhlDashboard($authUser, $body); break;
        case 'ghl_messages':    ob_end_clean(); fourgeApiGhlMessages($authUser, $body); break;
        case 'ghl_send':        ob_end_clean(); fourgeApiGhlSend($authUser, $body); break;
        case 'ghl_form_def':    ob_end_clean(); fourgeApiGhlFormDef($authUser, $body); break;
        case 'gh_mirror':       ob_end_clean(); fourgeApiGhMirror($authUser, $body); break;
        case 'gh_set_private':  ob_end_clean(); fourgeApiGhSetPrivate($authUser, $body); break;
        case 'gh_sync_all':     ob_end_clean(); fourgeApiGhSyncAll($authUser, $body); break;
        case 'send_test_email': ob_end_clean(); fourgeApiSendTestEmail($authUser, $body); break;
        case 'recaptcha_status': ob_end_clean(); fourgeApiRecaptchaStatus($authUser, $body); break;
        case 'ai_endpoint_test': ob_end_clean(); fourgeApiAiEndpointTest($authUser, $body); break;
        case 'secret_exposure':    ob_end_clean(); fourgeApiSecretExposure($authUser, $body); break;
        case 'ai_autofix':         ob_end_clean(); fourgeApiAiAutofix($authUser, $body); break;
        case 'reviews_fetch':      ob_end_clean(); fourgeApiReviewsFetch($authUser, $body); break;
        case 'reviews_find_place': ob_end_clean(); fourgeApiReviewsFindPlace($authUser, $body); break;
        case 'map_geocode':        ob_end_clean(); fourgeApiMapGeocode($authUser, $body); break;
        case 'tlp_feed':           ob_end_clean(); fourgeApiTlpFeed($authUser, $body); break;
        case 'seo_package':     ob_end_clean(); fourgeApiSeoPackage($authUser, $body); break;
        case 'seo_pkg_tick':    ob_end_clean(); fourgeApiSeoPkgTick($authUser, $body); break;
        case 'seo_pkg_admin':   ob_end_clean(); fourgeApiSeoPkgAdmin($authUser, $body); break;
        case 'seo_pkg_publish_all': ob_end_clean(); fourgeApiSeoPkgPublishAll($authUser, $body); break;
        case 'blog_sync_tick':  ob_end_clean(); fourgeApiBlogSyncTick($authUser, $body); break;
        case 'blog_sync_poke':  ob_end_clean(); fourgeApiBlogSyncPoke(); break;
        case 'blog_sync_admin': ob_end_clean(); fourgeApiBlogSyncAdmin($authUser, $body); break;
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
        case 'check_form_password': ob_end_clean(); fourgeApiCheckFormPassword($body); break;
        case 'form_view':   ob_end_clean(); fourgeApiFormView($body); break;
        case 'hash_form_password': ob_end_clean(); fourgeApiHashFormPassword($body); break;
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

    // postsHtml: whether the generated blog page exists — the Posts tab shows
    // "Publish Blog Page" as the fix when synced posts have nowhere to display.
    echo json_encode(['pages' => $pages, 'root' => $root, 'postsHtml' => is_file($root . '/posts.html')]);
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
            // Does this page carry the live post list ([data-fourge-posts])? Lets the
            // admin say WHERE synced/published posts actually appear on the site.
            'has_post_list' => strpos($content, 'data-fourge-posts') !== false,
        ];
    }
}

// ── LIST MEDIA ────────────────────────────────────────────────────────────────

function cmsListMedia() {
    $root      = PUBLIC_HTML;
    $imageExts = ['jpg','jpeg','png','webp','gif','svg','ico'];
    $videoExts = ['mp4','m4v','mov','webm','ogv','ogg'];
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
    // Content may arrive three ways:
    //  1) multipart file part (content_file) — primary path; sails past WAFs
    //     that block raw HTML/JS in a JSON POST (same channel as image uploads),
    //  2) base64 in JSON (content_b64) — the client's fallback when the file
    //     part doesn't make it (see apiCall), also a WAF-bypass,
    //  3) plain JSON content.
    // $content stays null until one of them actually delivered something: a
    // request that sent content which never arrived must be an ERROR, never an
    // empty write. (An upload cut off mid-flight during an engine update once
    // wrote a 0-byte api.php — a dead API that could no longer update itself.)
    $content = null;
    if (is_array($body) && array_key_exists('content', $body) && is_string($body['content'])) $content = $body['content'];
    if (isset($body['content_b64']) && is_string($body['content_b64'])) {
        $decoded = base64_decode($body['content_b64'], true);
        if ($decoded === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid base64 content']);
            return;
        }
        $content = $decoded;
    }
    $isMultipart = isset($_FILES['content_file']) || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
    if ($isMultipart) {
        $fp  = $_FILES['content_file'] ?? null;
        $err = is_array($fp) ? (int)($fp['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || empty($fp['tmp_name']) || !is_uploaded_file($fp['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'The file content did not arrive (' . cmsUploadErrText($err) . ') — nothing was written.', 'reason' => 'content_missing']);
            return;
        }
        $c = file_get_contents($fp['tmp_name']);
        if ($c === false) { http_response_code(400); echo json_encode(['error' => 'The uploaded content could not be read — nothing was written.', 'reason' => 'content_missing']); return; }
        $content = $c;
    }
    if ($content === null) {
        http_response_code(400);
        echo json_encode(['error' => 'No content was sent — nothing was written.', 'reason' => 'content_missing']);
        return;
    }
    // The client states how many bytes it sent. Content that PHP received
    // "successfully" but shorter than that is still a truncated write.
    $declared = $body['content_length'] ?? ($_POST['content_length'] ?? null);
    if ($declared !== null && $declared !== '' && (int)$declared !== strlen($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'The file content arrived incomplete (' . strlen($content) . ' of ' . (int)$declared . ' bytes) — nothing was written.', 'reason' => 'content_missing']);
        return;
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
    // Engine files get a pre-flight before anything touches disk: a PHP file must
    // parse on THIS server's PHP (a truncated download, or syntax this host's PHP
    // version doesn't know, would otherwise replace a working api.php with one
    // that answers every request with an error page — and a CMS whose API is dead
    // can't even update itself back), and the admin page must at least be whole.
    $guard = cmsWriteFilePreflight($dest, $content);
    if ($guard !== '') { http_response_code(409); echo json_encode(['error' => $guard]); return; }
    // Atomic write: the whole file lands under a temp name first and is renamed
    // over the old one only once every byte made it. A short write (a disk quota
    // hit mid-file) can therefore never leave a half-written page or api.php.
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
    $n = @file_put_contents($tmp, $content);
    if ($n === false || $n !== strlen($content)) {
        @unlink($tmp);
        http_response_code(500);
        echo json_encode(['error' => 'Could not write ' . htmlspecialchars($relPath) . ($n === false ? ' (folder not writable?)' : ' — only ' . (int)$n . ' of ' . strlen($content) . ' bytes fit (disk quota?)') . '. The existing file was left untouched.']);
        return;
    }
    if (!@rename($tmp, $dest)) {
        // A host where rename-over can't replace the file (rare): fall back to a
        // direct write, but only now that the full content is known to fit.
        $ok = @file_put_contents($dest, $content) === strlen($content);
        @unlink($tmp);
        if (!$ok) { http_response_code(500); echo json_encode(['error' => 'Could not write: ' . htmlspecialchars($relPath)]); return; }
    }
    // Mirror the saved bytes to the site's GitHub repo right here, and report the
    // outcome as `gh` so the client can say "Saved to server + GitHub" truthfully
    // instead of pushing a second time itself. gh=0 opts a write out: the revision
    // snapshots use it, since they mirror themselves asynchronously and a page
    // save should not wait on them. A mirror problem never fails the save.
    $ghOpt = $body['gh'] ?? ($_POST['gh'] ?? null);
    $gh = ($ghOpt === '0' || $ghOpt === 0 || $ghOpt === false)
        ? ['ok' => false, 'reason' => 'opted_out']
        : cmsGhAfterWrite(cmsGhRelPath($dest), $content);
    echo json_encode(['ok' => true, 'path' => $relPath, 'size' => strlen($content), 'gh' => $gh]);
}

// Plain words for a PHP upload error code (cmsWriteFile's multipart channel).
function cmsUploadErrText($code) {
    switch ((int)$code) {
        case UPLOAD_ERR_OK:         return 'no error reported';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'bigger than this server accepts';
        case UPLOAD_ERR_PARTIAL:    return 'the upload was cut off before it finished';
        case UPLOAD_ERR_NO_FILE:    return 'no file part was received';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'the server could not store the upload';
        default:                    return 'upload error ' . (int)$code;
    }
}
// The pre-flight for cmsWriteFile(): '' when the write may proceed, otherwise the
// reason it must not. Only engine files are judged — an operator's own pages are
// theirs to write however they like.
function cmsWriteFilePreflight($dest, $content) {
    $rel  = cmsGhRelPath($dest);
    $base = strtolower(basename($rel));
    if (strpos($rel, 'admin/') === 0 && substr($base, -4) === '.php') {
        if (strlen($content) < 1000) return 'Refusing to install ' . $rel . ': the file is only ' . strlen($content) . ' bytes — the download looks incomplete. The current file was left untouched.';
        try { token_get_all($content, TOKEN_PARSE); }
        catch (\Throwable $e) {
            return 'Refusing to install ' . $rel . ': it does not parse on this server\'s PHP ' . PHP_VERSION . ' (line ' . $e->getLine() . ': ' . $e->getMessage() . '). The current file was left untouched.';
        }
    }
    if ($rel === 'admin/index.html') {
        if (strlen($content) < 10000 || !preg_match('~</html>\s*$~i', $content) || strpos($content, "const CMS_VERSION='") === false)
            return 'Refusing to install admin/index.html: the download looks incomplete (no closing </html> or no version constant). The current file was left untouched.';
    }
    return '';
}

// ── UPLOAD FILES ──────────────────────────────────────────────────────────────

function handleUpload() {
    if (empty($_FILES['files'])) {
        echo json_encode(['error' => 'No files in request']); return;
    }
    // Optional destination folder ('to'), so a file can be written back where it
    // already lives (the image optimizer overwrites images in their own folder,
    // e.g. images/). Traversal-safe: the folder must ALREADY exist and resolve
    // inside the web root — nothing is ever created or written outside it.
    $destDir = PUBLIC_HTML; $toRel = '';
    $to = isset($_POST['to']) ? str_replace('\\', '/', trim((string)$_POST['to'], "/ \t\n\r")) : '';
    if ($to !== '') {
        if (preg_match('~(^|/)\.\.(/|$)~', $to) || strpos($to, "\0") !== false) { echo json_encode(['error' => 'Bad destination folder']); return; }
        $cand = realpath(PUBLIC_HTML . '/' . $to);
        if (!$cand || strpos($cand, PUBLIC_HTML) !== 0 || !is_dir($cand)) { echo json_encode(['error' => 'Destination folder not found']); return; }
        $destDir = $cand; $toRel = $to . '/';
    }
    // Every type the CMS actually works with: page/web assets, the images and
    // documents the media library lists, and video for the video block.
    //
    // This list is now ENFORCED. It has existed since the first version of this
    // function but nothing ever consulted it — only $blocked was checked, and a
    // deny-list can't cover what it hasn't thought of. ".htaccess" passed it, and
    // an .htaccess in the web root can turn on PHP execution for any extension;
    // so could .phps/.pht/.php7/.module. An allow-list can't be outflanked that
    // way. $blocked is kept as a second line of defence.
    $allowed = [
        'html','htm','css','jsx','js','json','svg','xml','txt','csv',            // web + data
        'jpg','jpeg','png','webp','gif','ico','avif','bmp','tif','tiff',          // images
        'mp4','m4v','mov','webm','ogv','ogg',                                     // video
        'mp3','wav','m4a','aac',                                                  // audio
        'woff','woff2','ttf','otf','eot',                                         // fonts
        'pdf','doc','docx','xls','xlsx','ppt','pptx','zip',                       // documents
    ];
    $blocked  = ['php','php3','php4','php5','php7','php8','phtml','phps','pht','phar','htaccess','htpasswd','asp','aspx','jsp','cgi','pl','py','rb','sh','bash','exe','bat','cmd','com','dll','so'];
    $results  = [];
    $names    = (array)$_FILES['files']['name'];
    $tmps     = (array)$_FILES['files']['tmp_name'];
    $errors   = (array)$_FILES['files']['error'];
    // A video is the first file most people upload that is big enough to hit the
    // server's own limits, and "Upload error 1" is not a useful thing to read.
    $errText = function ($code) {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $lim = trim((string)ini_get('upload_max_filesize'));
                return 'Bigger than this server accepts' . ($lim !== '' ? ' (limit ' . $lim . ')' : '')
                     . ' — compress the video or raise upload_max_filesize and post_max_size in PHP.';
            case UPLOAD_ERR_PARTIAL:   return 'The upload was cut off before it finished — try again.';
            case UPLOAD_ERR_NO_FILE:   return 'No file was received.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE: return 'The server could not write the file to disk.';
            default: return 'Upload failed (code ' . (int)$code . ').';
        }
    };

    for ($i = 0; $i < count($names); $i++) {
        $orig = $names[$i]; $tmp = $tmps[$i]; $err = $errors[$i];
        if ($err !== UPLOAD_ERR_OK) { $results[] = ['name'=>$orig,'success'=>false,'error'=>$errText($err)]; continue; }
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '', $orig);
        // A name that is nothing but dots/extension ("...", ".htaccess" once the
        // leading dot survives sanitising) has no basename to speak of.
        if ($safe === '' || ltrim($safe, '.') === '' || strpos($safe, '.') === false) {
            $results[] = ['name'=>$orig,'success'=>false,'error'=>'Needs a normal filename with an extension']; continue;
        }
        $ext  = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        if (in_array($ext, $blocked, true) || $safe[0] === '.') { $results[] = ['name'=>$orig,'success'=>false,'error'=>'File type blocked']; continue; }
        if (!in_array($ext, $allowed, true)) {
            $results[] = ['name'=>$orig,'success'=>false,'error'=>'.' . $ext . ' files are not accepted']; continue;
        }
        $dest = $destDir . '/' . $safe;
        if (move_uploaded_file($tmp, $dest)) {
            $res = ['name'=>$safe,'success'=>true,'path'=>$toRel . $safe];
            // Same inline GitHub mirror as cmsWriteFile(), binary-safe — so images,
            // videos, fonts and documents reach the repo too, not just text files.
            $ghOpt = $_POST['gh'] ?? null;
            $res['gh'] = ($ghOpt === '0') ? ['ok' => false, 'reason' => 'opted_out']
                                          : cmsGhAfterWrite(cmsGhRelPath($dest), (string)@file_get_contents($dest));
            $results[] = $res;
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
    $rel = cmsGhRelPath($safe);
    unlink($safe);
    // Keep the repo in step: a file removed here is removed there (gh=0 opts out,
    // as for cmsWriteFile). Reported, never fatal.
    $ghOpt = $body['gh'] ?? null;
    $gh = ($ghOpt === '0' || $ghOpt === 0 || $ghOpt === false) ? ['ok' => false, 'reason' => 'opted_out'] : cmsGhAfterDelete($rel);
    echo json_encode(['ok' => true, 'gh' => $gh]);
}

// ── MAILGUN FORM ──────────────────────────────────────────────────────────────

function cmsStoreEntry($formId, $fields, $siteUrl, $recaptcha = null) {
    try {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $file = $dir . '/entries.json';
        $entries = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $entries = json_decode($raw, true) ?: [];
        }
        $entry = [
            'id'     => uniqid('ent_'),
            'formId' => $formId,
            'date'   => date('Y-m-d H:i'),
            'data'   => $fields,
            'source' => $siteUrl,
        ];
        // Only ever 'passed' or 'allowed_unverified' here — cmsSendForm rejects
        // a 'blocked' submission before this is ever called, so a stored entry
        // never carries that verdict. Omitted entirely (not even a null) when
        // there was nothing to check, so old entries and "passed" entries stay
        // visually distinct from a site that simply has reCAPTCHA off.
        if (is_array($recaptcha) && !empty($recaptcha['outcome'])) {
            $entry['recaptcha'] = ['outcome' => $recaptcha['outcome'], 'score' => $recaptcha['score'] ?? null, 'reason' => $recaptcha['reason'] ?? null];
        }
        array_unshift($entries, $entry);
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

// Appends each reCAPTCHA verdict to data/recaptcha-debug.json as a bounded,
// OLDEST-FIRST log (no secrets, no PII — just the verdict) capped at 1000,
// same cap cmsStoreEntry already uses for entries.json. Oldest-first (not
// unshift) is deliberate: Post-Launch Check's reCAPTCHA section already reads
// this as entries.slice(-10) to mean "the 10 most recent", so recent entries
// belong at the END of the array. Before this, the file held only a single
// overwritten record — that dormant .slice(-10) logic never actually saw more
// than one entry. A pre-upgrade file may still hold that old flat-object
// shape; it's one throwaway diagnostic record, not worth migrating, so it's
// simply superseded (not appended to) on the first write under this code.
function cmsRecaptchaLog($rec) {
    try {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir) || !is_writable($dir)) return;
        $file = $dir . '/recaptcha-debug.json';
        $log = [];
        if (is_file($file)) {
            $existing = json_decode((string)@file_get_contents($file), true);
            if (is_array($existing) && ($existing === [] || array_keys($existing) === range(0, count($existing) - 1))) $log = $existing;
        }
        $log[] = $rec;
        if (count($log) > 1000) $log = array_slice($log, -1000);
        @file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {}
}

// Verify a submission's reCAPTCHA token and decide whether to BLOCK it. Returns
// the full verdict record (score, reason, hostname, etc.) — also appended to the
// debug log so the CMS can show it — whose 'outcome' key is one of three values:
//   'passed'             — verified OK (v3 score >= threshold, or v2 success).
//   'blocked'            — verified AND scored below the threshold: a real bot.
//   'allowed_unverified' — verification could NOT run (no token, wrong keys, Google
//                          unreachable). The submission is ALLOWED so a legitimate
//                          lead is never lost just because reCAPTCHA is misconfigured
//                          — a form is only ever BLOCKED when Google actively scores
//                          it as a bot. The exact reason is recorded so the setup
//                          problem is visible in Plugins → reCAPTCHA (and can be fixed,
//                          which turns real protection back on automatically).
// Common Google error-codes: 'invalid-input-secret' (wrong/mixed v2↔v3 secret),
// 'invalid-input-response' (bad/expired token, or a v2 key used for v3),
// 'timeout-or-duplicate' (token reused/stale).
function cmsVerifyRecaptcha($secret, $token, $threshold = 0.5) {
    $rec = ['at'=>date('c'), 'outcome'=>'', 'ok'=>false, 'reason'=>'', 'score'=>null, 'threshold'=>(float)$threshold, 'tokenReceived'=>($token!=='' && $token!==null), 'hostname'=>''];
    $finish = function ($outcome, $reason) use (&$rec) {
        $rec['outcome'] = $outcome; $rec['reason'] = $reason; $rec['ok'] = ($outcome === 'passed');
        error_log('Fourge reCAPTCHA: ' . strtoupper($outcome) . ' — ' . $reason);
        cmsRecaptchaLog($rec);
        return $rec;   // the full verdict (incl. score) — cmsSendForm attaches it to the stored entry
    };
    $letThrough = ' The submission was let through so a real lead is not lost — but reCAPTCHA is NOT protecting this form until this is fixed.';
    if ($token === '' || $token === null) {
        return $finish('allowed_unverified', 'No token received from the form — the reCAPTCHA script did not load on the page (the badge is not showing). In Plugins → reCAPTCHA, click Save to publish it to your pages, and make sure the keys are reCAPTCHA v3 keys.' . $letThrough);
    }
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch); $curlErr = curl_error($ch);
    curl_close($ch);
    if (!$res) return $finish('allowed_unverified', 'No response from Google siteverify' . ($curlErr ? ' (' . $curlErr . ')' : '') . ' — the server may be blocking outbound HTTPS.' . $letThrough);
    $data = json_decode($res, true);
    if (!empty($data['hostname'])) $rec['hostname'] = $data['hostname'];
    if (isset($data['score']))     $rec['score']    = (float)$data['score'];
    if (empty($data['success'])) {
        $codes = isset($data['error-codes']) ? implode(', ', (array)$data['error-codes']) : 'unknown';
        $hint = '';
        if (strpos($codes, 'invalid-input-secret') !== false)        $hint = ' — the SECRET key is wrong, or a v2 secret was pasted for a v3 site.';
        elseif (strpos($codes, 'invalid-input-response') !== false)  $hint = ' — the token was invalid/expired, or the SITE key is a v2 key being used with v3. Both keys must come from a reCAPTCHA v3 registration.';
        elseif (strpos($codes, 'timeout-or-duplicate') !== false)    $hint = ' — the token expired or was reused.';
        return $finish('allowed_unverified', 'Google rejected the verification: ' . $codes . $hint . $letThrough);
    }
    // reCAPTCHA v3 returns a 0.0–1.0 score; the threshold check is the ONLY place a
    // submission is actually blocked. v2 (checkbox) returns no score, so a successful
    // verification is enough.
    if (isset($data['score'])) {
        if (((float)$data['score']) >= (float)$threshold)
            return $finish('passed', 'passed with score ' . $data['score'] . ' (threshold ' . $threshold . ')');
        return $finish('blocked', 'score ' . $data['score'] . ' is below your threshold ' . $threshold . ' — this looks like a bot. Lower the threshold in Plugins → reCAPTCHA if real visitors are being blocked.');
    }
    return $finish('passed', 'passed (v2 checkbox — no score)');
}

// hCaptcha: an alternative to reCAPTCHA (Plugins → hCaptcha), same site-wide
// on/off + two-key shape as reCAPTCHA above, read straight from data/site.json.
function cmsHcaptchaSecret() {
    try {
        $file = __DIR__ . '/../data/site.json';
        if (!file_exists($file)) return '';
        $site = json_decode(file_get_contents($file), true);
        if (!empty($site['hcaptcha']['enabled']) && !empty($site['hcaptcha']['secret'])) {
            return $site['hcaptcha']['secret'];
        }
    } catch (Exception $e) {}
    return '';
}
// hCaptcha's checkbox challenge has no score to threshold against (unlike
// reCAPTCHA v3) — same non-losing philosophy as reCAPTCHA v2: a successful
// verification passes, anything else (no token, bad token, hCaptcha
// unreachable) is 'allowed_unverified' rather than actively blocking, so a
// real lead is never lost to a misconfigured or momentarily-down check.
function cmsVerifyHcaptcha($secret, $token) {
    $rec = ['at' => date('c'), 'outcome' => '', 'ok' => false, 'reason' => '', 'score' => null, 'tokenReceived' => ($token !== '' && $token !== null)];
    $finish = function ($outcome, $reason) use (&$rec) {
        $rec['outcome'] = $outcome; $rec['reason'] = $reason; $rec['ok'] = ($outcome === 'passed');
        error_log('Fourge hCaptcha: ' . strtoupper($outcome) . ' — ' . $reason);
        return $rec;
    };
    $letThrough = ' The submission was let through so a real lead is not lost — but hCaptcha is NOT protecting this form until this is fixed.';
    if ($token === '' || $token === null) {
        return $finish('allowed_unverified', 'No token received from the form — the hCaptcha widget did not load on the page.' . $letThrough);
    }
    $ch = curl_init('https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch); $curlErr = curl_error($ch);
    curl_close($ch);
    if (!$res) return $finish('allowed_unverified', 'No response from hCaptcha siteverify' . ($curlErr ? ' (' . $curlErr . ')' : '') . ' — the server may be blocking outbound HTTPS.' . $letThrough);
    $data = json_decode($res, true);
    if (empty($data['success'])) {
        $codes = isset($data['error-codes']) ? implode(', ', (array)$data['error-codes']) : 'unknown';
        return $finish('allowed_unverified', 'hCaptcha rejected the verification: ' . $codes . '.' . $letThrough);
    }
    return $finish('passed', 'passed');
}

// Admin diagnostic: report the current reCAPTCHA config + the outcome of the most
// recent submission check, so the exact reason a form is blocked is visible in the
// CMS (Plugins → reCAPTCHA) without needing server-log access. Never returns keys.
function fourgeApiRecaptchaStatus($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    $cfg = ['enabled' => false, 'version' => '', 'hasSecret' => false, 'hasSiteKey' => false, 'threshold' => cmsRecaptchaThreshold()];
    try {
        $site = json_decode(@file_get_contents(__DIR__ . '/../data/site.json'), true);
        $rc = ($site['recaptcha'] ?? []);
        $cfg['enabled']    = !empty($rc['enabled']);
        $cfg['version']    = (string)($rc['version'] ?? '');
        $cfg['hasSecret']  = !empty($rc['secret']);
        $cfg['hasSiteKey'] = !empty($rc['siteKey']);
    } catch (Throwable $e) {}
    // data/recaptcha-debug.json is now a bounded, oldest-first log (see
    // cmsRecaptchaLog) — 'last' stays the single most recent record, flat,
    // for this panel's own direct field reads (last.score, last.reason, …);
    // the full log rides along as last.entries for anything that wants
    // recent history, e.g. Post-Launch Check's "how many of the last 10 were
    // blocked". A file still in the old pre-upgrade single-record shape (not
    // a list) is treated as no history yet rather than guessed at — it's
    // superseded the next time a form is submitted.
    $log = [];
    try {
        $j = @file_get_contents(__DIR__ . '/../data/recaptcha-debug.json');
        $d = json_decode((string)$j, true);
        if (is_array($d) && ($d === [] || array_keys($d) === range(0, count($d) - 1))) $log = $d;
    } catch (Throwable $e) {}
    $last = null;
    if ($log) { $last = $log[count($log) - 1]; $last['entries'] = $log; }
    echo json_encode(['ok' => true, 'config' => $cfg, 'last' => $last]);
}

// ── GOHIGHLEVEL (GHL) LEAD PUSH ─────────────────────────────────────────────
// Pushes a website form submission into GoHighLevel as a contact (lead) via the
// v2 API, using a Private Integration Token stored encrypted server-side — so no
// GHL form is needed and the token never reaches the browser. Config lives in
// data/site.json { ghl: { enabled, locationId } } + the encrypted 'ghl_token'.
// (GHL_API_BASE / GHL_API_VERSION are define()d at the top of this file, BEFORE
// the request dispatch, so they exist by the time these functions run.)

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
function cmsGhlMapContact($fields, $locationId, $formName, $siteUrl, $mapping = null) {
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
    // A form matched to a CRM form (cmsGhlApplyMapping) overrides the heuristics:
    // its explicit standard-field values win, and its custom fields ride along so
    // automations can key on them (the note still carries the full submission).
    if ($mapping && !empty($mapping['mapped'])) {
        foreach (($mapping['contact'] ?? []) as $k => $v) { if ($v !== '' && $v !== null) $contact[$k] = $v; }
        if (!empty($mapping['custom'])) $contact['customFields'] = $mapping['custom'];
        // A single "Name" field mapped to the CRM's first_name arrives as the
        // FULL name. Without this, firstName became the whole string while the
        // heuristic's split lastName survived underneath — every contact came
        // out like "Amy Jenson" / "Jenson". When the mapping supplies
        // first_name but no last_name: drop any heuristic lastName, and if the
        // mapped value contains whitespace, split it (first token → firstName,
        // remainder → lastName; single-token names keep lastName empty).
        $mc = $mapping['contact'] ?? [];
        if (!empty($mc['firstName']) && empty($mc['lastName'])) {
            unset($contact['lastName']);
            $fn = trim((string)$mc['firstName']);
            if (preg_match('/\s/', $fn)) {
                $p = preg_split('/\s+/', $fn, 2);
                $contact['firstName'] = $p[0];
                if (isset($p[1]) && trim($p[1]) !== '') $contact['lastName'] = trim($p[1]);
            }
        }
    }
    $note = 'New website form submission' . ($formName ? " — {$formName}" : '') . "\n\n" . implode("\n", $noteLines);
    if ($siteUrl) $note .= "\n\nPage: " . $siteUrl;
    $hasContact = ($email !== '' || $phone !== '' || !empty($contact['email']) || !empty($contact['phone']));
    return ['contact' => $contact, 'note' => $note, 'hasContactInfo' => $hasContact];
}

// Push a submission to GHL. Best-effort; returns true when the contact upserts.
// When the CMS form is matched to a CRM form (its fields carry a 'ghl' mapping,
// set in the form builder from the CRM form's share link), the submission's
// values are sent as the CRM's REAL fields — standard ones and custom fields —
// so automations that key on those fields fire with the data they need.
function cmsGhlPushLead($token, $locationId, $fields, $formName, $siteUrl, $formId = '') {
    $mapping = null;
    if ($formId !== '') { try { $mapping = cmsGhlFormMapping($formId, $fields); } catch (Throwable $e) {} }
    $map = cmsGhlMapContact($fields, $locationId, $formName, $siteUrl, $mapping);
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

// Look up one form's own definition in data/forms.json by id — the shared
// lookup behind cmsGhlFormMapping, cmsFormWebhookConfig, the form-password
// check, and the response-limit/close-date check below.
function cmsFormById($formId) {
    if ($formId === '') return null;
    $forms = json_decode(@file_get_contents(__DIR__ . '/../data/forms.json'), true);
    if (!is_array($forms)) return null;
    foreach ($forms as $f) { if (($f['id'] ?? '') === $formId) return $f; }
    return null;
}
// Generic outbound webhook config for one form — its settings.webhookUrl/
// webhookSecret, or null if the form has none configured.
function cmsFormWebhookConfig($formId) {
    $form = cmsFormById($formId);
    if (!$form) return null;
    $url = trim((string)($form['settings']['webhookUrl'] ?? ''));
    if ($url === '') return null;
    return ['url' => $url, 'secret' => (string)($form['settings']['webhookSecret'] ?? '')];
}
// POSTs one submission as JSON to an operator-supplied URL. Guarded by the same
// https-and-public-host check every other operator-supplied URL in this codebase
// gets (fourgeTlpUrlOk — see the TLP feed / Fleet Dashboard / custom AI endpoint).
// When a secret is set, the raw JSON body is HMAC-SHA256 signed the same way
// GitHub/Stripe webhooks are, so the receiving end can verify it really came from
// this form and wasn't forged or replayed by a different sender.
function cmsSendWebhook($url, $secret, $payload) {
    if (!fourgeTlpUrlOk($url)) return false;
    $body = json_encode($payload);
    $headers = ['Content-Type: application/json'];
    if ($secret !== '') {
        $headers[] = 'X-Fourge-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// Returns a user-facing reason this form is closed (past its close date, or
// at its response cap), or '' if it's open. Checked before reCAPTCHA/hCaptcha
// in cmsSendForm — no point verifying a captcha for a submission that can't
// be accepted anyway.
function cmsFormClosedReason($form) {
    $s = $form['settings'] ?? [];
    $closeDate = trim((string)($s['closeDate'] ?? ''));
    if ($closeDate !== '') {
        $ts = strtotime($closeDate . ' 23:59:59');
        if ($ts !== false && time() > $ts) return 'This form is no longer accepting responses.';
    }
    $max = (int)($s['maxResponses'] ?? 0);
    if ($max > 0 && cmsCountEntriesForForm((string)($form['id'] ?? '')) >= $max) {
        return 'This form has reached its response limit.';
    }
    return '';
}
function cmsCountEntriesForForm($formId) {
    $entries = json_decode(@file_get_contents(__DIR__ . '/../data/entries.json'), true);
    if (!is_array($entries)) return 0;
    $n = 0;
    foreach ($entries as $e) { if (($e['formId'] ?? '') === $formId) $n++; }
    return $n;
}
// A submission itself carries the password it was unlocked with (the client
// only reveals the real form after a successful check_form_password call —
// see ffCheckFormPassword in admin/index.html), so cmsSendForm can verify it
// independently. Without this, a password-protected form's real gate would
// be purely a client-side courtesy: anyone could skip straight to send_form.
function cmsFormPasswordOk($form, $submitted) {
    $hash = (string)($form['settings']['password'] ?? '');
    if ($hash === '') return true;   // not password-protected
    return $submitted !== '' && password_verify($submitted, $hash);
}
// Public: verify a visitor-entered password against a password-protected
// form's stored hash (bcrypt, same approach as page passwords — see
// fourgeApiSetPagePassword). A low-stakes gate on who can see/submit a form,
// not a high-security boundary, so no extra rate-limiting beyond bcrypt's
// own inherent slowness.
function fourgeApiCheckFormPassword($body) {
    $formId = (string)($body['formId'] ?? '');
    $password = (string)($body['password'] ?? '');
    $form = cmsFormById($formId);
    $hash = (string)($form['settings']['password'] ?? '');
    if ($hash === '') { echo json_encode(['ok' => true]); return; }   // not password-protected — nothing to check
    if ($password !== '' && password_verify($password, $hash)) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password']);
    }
}
// Public, best-effort view counter feeding the Forms list's analytics — never
// blocks or errors loudly; a lost increment under concurrent writes is an
// acceptable tradeoff for not needing file locking on every page view.
function fourgeApiFormView($body) {
    $formId = (string)($body['formId'] ?? '');
    if ($formId === '') { echo json_encode(['ok' => false]); return; }
    try {
        $file = __DIR__ . '/../data/form-analytics.json';
        $data = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        if (!isset($data[$formId]) || !is_array($data[$formId])) $data[$formId] = ['views' => 0];
        $data[$formId]['views'] = (int)($data[$formId]['views'] ?? 0) + 1;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true]);
}
// The form builder calls this once when the admin sets/changes a form's
// password, then saves the returned hash into settings.password itself via
// the normal saveForm()/write_file flow — so this endpoint only ever hashes,
// it never touches forms.json (no second write path to conflict with the
// builder's own full-array save). Requires the same auth the read_file/
// write_file actions already require (session or the shared API token) —
// the dispatcher gates that before this function ever runs.
function fourgeApiHashFormPassword($body) {
    $password = (string)($body['password'] ?? '');
    if (strlen($password) < 4) { http_response_code(400); echo json_encode(['error' => 'Password must be at least 4 characters']); return; }
    echo json_encode(['ok' => true, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
}

// Same slug the form renderer uses for input names (admin/index.html slugify()),
// so submitted keys — slug(label)-<first 4 of field id> — can be matched back to
// the form's field definitions here on the server.
function cmsSlug($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower((string)$s) : strtolower((string)$s);
    return preg_replace('/[^a-z0-9-]/', '', preg_replace('/\s+/', '-', $s));
}

// Build the CRM push mapping for one submission: read the form's definition from
// data/forms.json, and for every field carrying a 'ghl' mapping, find its
// submitted value and file it under the CRM's REAL field — a standard contact
// field (firstName/email/companyName/…) or a custom field (by id/key). Hidden
// CRM fields the form was matched with (settings.ghlAuto) are sent as constants.
function cmsGhlFormMapping($formId, $fields) {
    $form = cmsFormById($formId);
    if (!$form) return null;
    return cmsGhlApplyMapping($form, $fields);
}
function cmsGhlApplyMapping($form, $fields) {
    $STD = ['first_name'=>'firstName','last_name'=>'lastName','email'=>'email','phone'=>'phone',
            'full_name'=>'name','company_name'=>'companyName','organization'=>'companyName'];
    $contact = []; $custom = []; $mapped = false;
    // Submitted values for a form field: keys are slug(label)-<id4>, with optional
    // -sub suffixes (name -first/-last, address -street/-city/…) or [] (checkboxes).
    $valuesFor = function ($f) use ($fields) {
        $p = cmsSlug($f['label'] ?? '') . '-' . substr((string)($f['id'] ?? ''), 0, 4);
        $out = [];
        foreach ((array)$fields as $k => $v) {
            $k = (string)$k;
            if (is_array($v)) $v = implode(', ', $v);
            $v = trim((string)$v); if ($v === '') continue;
            if ($k === $p || $k === $p.'[]')            $out[''] = $v;
            elseif (strpos($k, $p.'-') === 0)           $out[substr($k, strlen($p)+1)] = $v;
            elseif ($k === ($f['label'] ?? ''))         $out[''] = $v;   // hidden fields post by label
        }
        return $out;
    };
    foreach ((array)($form['fields'] ?? []) as $f) {
        $g = $f['ghl'] ?? null;
        if (!$g || !is_array($g)) continue;
        $vals = $valuesFor($f);
        if (!$vals) continue;
        $flat = trim(implode(' ', array_values($vals)));
        if (!empty($g['std'])) {
            $std = (string)$g['std'];
            if ($std === 'address') {
                if (isset($vals['street'])) $contact['address1']   = $vals['street'];
                if (isset($vals['city']))   $contact['city']       = $vals['city'];
                if (isset($vals['state']))  $contact['state']      = $vals['state'];
                if (isset($vals['zip']))    $contact['postalCode'] = $vals['zip'];
            } elseif (isset($STD[$std])) {
                if ($std === 'full_name' && isset($vals['first'])) $flat = trim($vals['first'].' '.($vals['last'] ?? ''));
                $contact[$STD[$std]] = $flat;
            }
            $mapped = true;
        } elseif (!empty($g['id']) || !empty($g['key'])) {
            $entry = ['field_value' => $flat];
            if (!empty($g['id'])) $entry['id'] = (string)$g['id'];
            else $entry['key'] = preg_replace('/^contact\./', '', (string)$g['key']);
            $custom[] = $entry; $mapped = true;
        }
    }
    foreach ((array)($form['settings']['ghlAuto'] ?? []) as $a) {   // hidden CRM constants (e.g. Source Page)
        if (!is_array($a) || (!isset($a['id']) && !isset($a['key'])) || !isset($a['value']) || $a['value']==='') continue;
        $entry = ['field_value' => (string)$a['value']];
        if (!empty($a['id'])) $entry['id'] = (string)$a['id'];
        else $entry['key'] = preg_replace('/^contact\./', '', (string)$a['key']);
        $custom[] = $entry; $mapped = true;
    }
    return $mapped ? ['mapped' => true, 'contact' => $contact, 'custom' => $custom] : null;
}

// ── CRM FORM DEFINITION (share-link import) ─────────────────────────
// The CRM's form share page embeds its full definition (fields, custom-field ids
// and keys, picklists, hidden defaults) in a NUXT devalue payload — public, no
// token needed. Parse it so the form builder can match CMS fields to the CRM
// form's REAL fields. Devalue is a flat array where object/array members are
// integer POINTERS into the same array; resolve with cycle + depth guards.
function cmsGhlParseFormWidget($html) {
    if (!preg_match('~<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)</script>~s', (string)$html, $m)) return null;
    $arr = json_decode($m[1], true);
    if (!is_array($arr)) return null;
    $isList = function ($a) { if (!is_array($a)) return false; $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; };
    $res = function ($i, $depth, $seen) use (&$res, $arr, $isList) {
        if (!is_int($i)) return $i;
        if ($i < 0 || $i >= count($arr) || $depth > 20 || isset($seen[$i])) return null;
        $v = $arr[$i]; $seen[$i] = true;
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) $out[$k] = $res($x, $depth + 1, $seen);
            return $out;
        }
        return $v;
    };
    foreach ($arr as $v) {
        if (!is_array($v) || $isList($v) || !isset($v['form'], $v['locationId'])) continue;
        $form = $res($v['form'], 0, []);
        if (!is_array($form) || !isset($form['fields'])) continue;
        $fields = [];
        foreach ((array)$form['fields'] as $f) {
            if (!is_array($f)) continue;
            $type = (string)($f['type'] ?? '');
            if ($type === 'submit' || ($f['tag'] ?? '') === 'button') continue;
            $o = [
                'label'    => trim(html_entity_decode(strip_tags((string)($f['label'] ?? ($f['name'] ?? ''))))),
                'type'     => $type,
                'required' => !empty($f['required']),
                'standard' => !empty($f['standard']),
            ];
            if ($o['standard']) {
                $o['std'] = (string)($f['tag'] ?? ($f['hiddenFieldQueryKey'] ?? ''));
            } else {
                $o['id']       = (string)($f['id'] ?? ($f['Id'] ?? ''));
                $o['key']      = (string)($f['fieldKey'] ?? '');
                $o['dataType'] = (string)($f['dataType'] ?? '');
                if (!empty($f['picklistOptions']) && is_array($f['picklistOptions'])) $o['options'] = array_values(array_map('strval', $f['picklistOptions']));
                if (!empty($f['hidden'])) { $o['hidden'] = true; $o['hiddenValue'] = (string)($f['hiddenFieldValue'] ?? ''); }
            }
            $fields[] = $o;
        }
        return [
            'locationId' => (string)$res($v['locationId'], 0, []),
            'name'       => (string)$res($v['name'] ?? -1, 0, []),
            'fields'     => $fields,
        ];
    }
    return null;
}
// Admin action: fetch a CRM form's public share page and return its parsed field
// definition. Only a form ID is accepted (or a share link it's extracted from) and
// the URL is constructed server-side — the server never fetches a caller-supplied
// URL, so this can't be used to probe internal hosts.
function fourgeApiGhlFormDef($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    $raw = trim((string)($body['form'] ?? ''));
    $id = '';
    if (preg_match('~/widget/form/([A-Za-z0-9_-]{6,64})~', $raw, $m)) $id = $m[1];
    elseif (preg_match('~^[A-Za-z0-9_-]{6,64}$~', $raw)) $id = $raw;
    if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Paste the form’s share link (it looks like …/widget/form/…).']); return; }
    $ch = curl_init('https://api.leadconnectorhq.com/widget/form/' . $id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err || $code !== 200 || !$res) { http_response_code(502); echo json_encode(['error' => 'Could not load the form’s share page' . ($err ? ' ('.$err.')' : ($code ? ' (HTTP '.$code.')' : '')) . ' — check the link.']); return; }
    $def = cmsGhlParseFormWidget($res);
    if (!$def || empty($def['fields'])) { http_response_code(422); echo json_encode(['error' => 'That page didn’t contain a readable form definition — make sure it’s the form’s share link.']); return; }
    echo json_encode(['ok' => true, 'formId' => $id, 'locationId' => $def['locationId'], 'name' => $def['name'], 'fields' => $def['fields']]);
}

// ── GITHUB MIRROR (server-side) ─────────────────────────────────────
// Pushes a file into the site's GitHub repo FROM THE SERVER, using the
// github_pat stored encrypted in the DB — so page saves and revision snapshots
// mirror for EVERY signed-in user, from any browser. (Previously the push ran
// in the browser with the Architect-only PAT, so any non-Architect session
// silently skipped the mirror — server copies existed, GitHub stayed empty.)
// The token is only USED here; it is never returned to the caller.
function cmsGhMirrorCfg() {
    $repo = ''; $branch = 'main'; $token = '';
    try {
        $site = json_decode(@file_get_contents(__DIR__ . '/../data/site.json'), true);
        $gh = $site['github'] ?? [];
        $repo = (string)($gh['repo'] ?? '');
        if (!empty($gh['branch'])) $branch = (string)$gh['branch'];
    } catch (Throwable $e) {}
    try { $ov = (string)fourgeGetSecret(fourgeDb(), 'repo_override'); if ($ov !== '') $repo = $ov; } catch (Throwable $e) {}
    try { $token = (string)fourgeGetSecret(fourgeDb(), 'github_pat'); } catch (Throwable $e) {}
    return [$repo, $branch, $token];
}
// One GitHub REST call. Returns [httpCode, decodedJson|null, curlError].
// $timeout grows with the payload (a 20 MB image is a ~27 MB base64 body, and
// a shared host can take a while to push that); the connect phase is always
// capped so an unreachable GitHub costs a save seconds, not the full budget.
function cmsGhApi($method, $url, $token, $payload = null, $timeout = 25) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'User-Agent: FourgeCMS', 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => max(5, (int)$timeout),
    ];
    if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return [$code, $res ? json_decode($res, true) : null, $err];
}

// ── What belongs in the site's repo ──────────────────────────────────
// Everything under the web root that IS the site — pages, data/*.json, images,
// video, fonts, documents, .htaccess files — minus what must never land in a
// repo:
//  • .git internals, and the CMS engine itself (admin/): api.php can carry
//    inline secrets on installs without config.secret.php, and the engine is
//    updated from the FoundryCMS template repo by the updater anyway, so a copy
//    in the site's own repo would only ever be a stale fork;
//  • credentials and PII: users.json (password hashes), entries.json and
//    data/uploads/ (form submissions and their attachments — once in git
//    history they are there forever), .htpasswd, the databases (and their
//    WAL/SHM siblings), any dot-file or dot-folder other than .htaccess and
//    .well-known (.env, .ga-token.json, .DS_Store, .github …);
//  • pure churn: the form-analytics counter, the reCAPTCHA debug log, logs,
//    backups and temp files.
function cmsGhSkipDir($relDir) {
    $relDir = trim(str_replace('\\', '/', (string)$relDir), '/');
    if ($relDir === '') return false;
    foreach (FOURGE_GH_SKIP_DIRS as $d) { if (strcasecmp($relDir, $d) === 0) return true; }
    $seg = basename($relDir);
    return $seg === '' || $seg === '.' || $seg === '..' || ($seg[0] === '.' && $seg !== '.well-known');
}
function cmsGhShouldMirror($rel) {
    $rel = str_replace('\\', '/', ltrim((string)$rel, '/'));
    if ($rel === '' || strpos($rel, "\0") !== false || substr($rel, -1) === '/') return false;
    $parts = explode('/', $rel);
    $base  = array_pop($parts);
    $dir   = '';
    foreach ($parts as $seg) {
        $dir = ($dir === '' ? $seg : $dir . '/' . $seg);
        if ($seg === '' || $seg === '.' || $seg === '..' || cmsGhSkipDir($dir)) return false;
    }
    $lb = strtolower($base);
    if ($lb === '' || $lb === '.' || $lb === '..') return false;
    if ($lb[0] === '.' && $lb !== '.htaccess') return false;
    if (preg_match('~\.(db|sqlite|sqlite3|db-wal|db-shm|sqlite-wal|sqlite-shm|sqlite3-wal|sqlite3-shm|log|bak|tmp|swp)$~', $lb)) return false;
    if (in_array($lb, ['error_log', 'php_errorlog', 'thumbs.db', 'config.secret.php', 'ga-service-account.json'], true)) return false;
    if (preg_match('~^data/(users|entries|form-analytics|recaptcha-debug)\.json$~i', $rel)) return false;
    return true;
}
// A file's path relative to the web root, forward-slashed — the path it has in the repo.
function cmsGhRelPath($absPath) {
    $abs = realpath($absPath) ?: (string)$absPath;
    if (strpos($abs, PUBLIC_HTML) === 0) $abs = substr($abs, strlen(PUBLIC_HTML));
    return ltrim(str_replace('\\', '/', $abs), '/');
}
// Biggest file the mirror will push. GitHub's Contents API stops at 100 MB, but
// a PHP request has to hold the file, its base64 form and the JSON body at once,
// so this also bows to memory_limit. 20 MB covers every image, PDF and short
// clip the media library realistically stores; anything bigger stays server-only
// and is reported as skipped rather than failing the save.
function cmsGhMaxBytes() {
    $ml = trim((string)ini_get('memory_limit'));
    if ($ml === '' || $ml === '-1') return FOURGE_GH_MAX_BYTES;
    $n = (float)$ml; $u = strtolower(substr($ml, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    $room = (int)(($n - memory_get_usage(true)) / 4);
    return max(1048576, min(FOURGE_GH_MAX_BYTES, $room));
}
// Local git blob id of a byte string — the very sha GitHub reports for a file,
// so "does the repo already hold exactly these bytes?" needs no download.
function cmsGhBlobSha($bytes) { return sha1('blob ' . strlen($bytes) . "\0" . $bytes); }
function cmsGhContentsUrl($repo, $rel) {
    $enc = ($rel === '') ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    return 'https://api.github.com/repos/' . $repo . '/contents' . $enc;
}
function cmsGhFail($what, $c, $d, $e) {
    return ['ok' => false, 'reason' => 'github', 'error' => $what . ' ' . (int)$c . (isset($d['message']) ? ' — ' . $d['message'] : ($e ? ' — ' . $e : ''))];
}
// The repo's blob sha for a path: a sha string, '' when the file isn't in the
// repo, or false when GitHub couldn't be asked. The per-file lookup stops at
// 1 MB (GitHub answers 403 "too large"); the parent folder's listing carries
// every entry's sha whatever its size, so that is the fallback.
function cmsGhRemoteSha($repo, $branch, $token, $rel) {
    list($c, $d) = cmsGhApi('GET', cmsGhContentsUrl($repo, $rel) . '?ref=' . rawurlencode($branch), $token);
    if ($c === 200 && isset($d['sha'])) return (string)$d['sha'];
    if ($c === 404) return '';
    if ($c === 403) {
        $dir = dirname($rel); if ($dir === '.' || $dir === '/') $dir = '';
        list($lc, $ld) = cmsGhApi('GET', cmsGhContentsUrl($repo, $dir) . '?ref=' . rawurlencode($branch), $token);
        if ($lc === 200 && is_array($ld)) {
            foreach ($ld as $ent) { if (is_array($ent) && ($ent['path'] ?? '') === $rel) return (string)($ent['sha'] ?? ''); }
            return '';
        }
        if ($lc === 404) return '';
    }
    return false;
}
// Every blob in the branch as path => sha, from one call — or null when GitHub
// couldn't give a complete listing (callers then look files up one at a time).
// A repo with no commits yet has no tree (404/409): nothing is there, which is
// an honest empty map.
function cmsGhFetchTree($repo, $branch, $token) {
    list($c, $d) = cmsGhApi('GET', 'https://api.github.com/repos/' . $repo . '/git/trees/' . rawurlencode($branch) . '?recursive=1', $token, null, 40);
    if ($c === 404 || $c === 409) return [];
    if ($c !== 200 || !is_array($d) || !empty($d['truncated']) || !isset($d['tree']) || !is_array($d['tree'])) return null;
    $map = [];
    foreach ($d['tree'] as $ent) { if (($ent['type'] ?? '') === 'blob' && isset($ent['path'], $ent['sha'])) $map[$ent['path']] = (string)$ent['sha']; }
    return $map;
}
// Mirror one file's bytes into the repo — text or binary alike. Same result
// shape the gh_mirror action has always returned: ['ok'=>true],
// ['ok'=>true,'skipped'=>true] (the repo already holds these exact bytes) or
// ['ok'=>false,'reason'=>…,'error'=>…]. $cfg lets a bulk run reuse one
// cmsGhMirrorCfg() lookup; $remoteSha (a blob sha, or '' for "known absent")
// lets it reuse one tree listing instead of a GET per file.
function cmsGhMirrorBytes($rel, $bytes, $msg = '', $cfg = null, $remoteSha = null) {
    $rel = str_replace('\\', '/', ltrim((string)$rel, '/'));
    $bytes = (string)$bytes;
    if (!cmsGhShouldMirror($rel)) return ['ok' => false, 'reason' => 'bad_path', 'error' => 'That file can’t be mirrored.'];
    $max = cmsGhMaxBytes();
    if (strlen($bytes) > $max) return ['ok' => false, 'reason' => 'too_big', 'error' => 'Too big to mirror (' . round(strlen($bytes) / 1048576, 1) . ' MB — the limit is ' . round($max / 1048576) . ' MB)'];
    list($repo, $branch, $token) = is_array($cfg) ? $cfg : cmsGhMirrorCfg();
    if ($repo === '' || !preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) return ['ok' => false, 'reason' => 'no_repo'];
    if ($token === '') return ['ok' => false, 'reason' => 'no_token'];
    $msg = trim((string)$msg); if ($msg === '') $msg = 'Fourge: update ' . $rel;
    if (!is_string($remoteSha)) {
        $remoteSha = cmsGhRemoteSha($repo, $branch, $token, $rel);
        if ($remoteSha === false) return ['ok' => false, 'reason' => 'github', 'error' => 'GitHub could not be reached to check ' . $rel];
    }
    $local = cmsGhBlobSha($bytes);
    if ($remoteSha !== '' && $remoteSha === $local) return ['ok' => true, 'skipped' => true];
    $url = cmsGhContentsUrl($repo, $rel);
    $payload = ['message' => $msg, 'content' => base64_encode($bytes), 'branch' => $branch];
    if ($remoteSha !== '') $payload['sha'] = $remoteSha;
    $timeout = 25 + (int)ceil(strlen($bytes) / 262144);   // +1 s per 256 KB
    list($c, $d, $e) = cmsGhApi('PUT', $url, $token, $payload, $timeout);
    if ($c === 409 || $c === 422) {
        // The sha we held was stale (someone pushed in between), or a tree
        // listing said "absent" and the file is there after all: look it up
        // fresh and try once more.
        $fresh = cmsGhRemoteSha($repo, $branch, $token, $rel);
        if (is_string($fresh) && $fresh !== $remoteSha) {
            if ($fresh !== '' && $fresh === $local) return ['ok' => true, 'skipped' => true];
            if ($fresh === '') unset($payload['sha']); else $payload['sha'] = $fresh;
            list($c, $d, $e) = cmsGhApi('PUT', $url, $token, $payload, $timeout);
        }
    }
    if ($c >= 200 && $c < 300) return ['ok' => true];
    error_log('Fourge GitHub mirror failed for ' . $rel . ': HTTP ' . $c . ' ' . ($d['message'] ?? $e));
    return cmsGhFail('GitHub returned', $c, $d, $e);
}
function cmsGhDeletePath($rel, $msg = '', $cfg = null) {
    $rel = str_replace('\\', '/', ltrim((string)$rel, '/'));
    if (!cmsGhShouldMirror($rel)) return ['ok' => false, 'reason' => 'bad_path', 'error' => 'That file can’t be mirrored.'];
    list($repo, $branch, $token) = is_array($cfg) ? $cfg : cmsGhMirrorCfg();
    if ($repo === '' || !preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) return ['ok' => false, 'reason' => 'no_repo'];
    if ($token === '') return ['ok' => false, 'reason' => 'no_token'];
    $msg = trim((string)$msg); if ($msg === '') $msg = 'Fourge: delete ' . $rel;
    $sha = cmsGhRemoteSha($repo, $branch, $token, $rel);
    if ($sha === false) return ['ok' => false, 'reason' => 'github', 'error' => 'GitHub could not be reached to check ' . $rel];
    if ($sha === '') return ['ok' => true, 'skipped' => 'absent'];   // nothing to prune
    list($c, $d, $e) = cmsGhApi('DELETE', cmsGhContentsUrl($repo, $rel), $token, ['message' => $msg, 'sha' => $sha, 'branch' => $branch]);
    if ($c >= 200 && $c < 300) return ['ok' => true];
    error_log('Fourge GitHub delete failed for ' . $rel . ': HTTP ' . $c . ' ' . ($d['message'] ?? $e));
    return cmsGhFail('GitHub delete failed', $c, $d, $e);
}
// The write_file / upload / delete_file hooks. GitHub trouble must never touch
// a save that has already succeeded on disk: every outcome comes back as data
// in the response's `gh` field, and a path the repo must not hold reports
// 'excluded' (so the engine updater writing admin/ is a quiet no-op here).
function cmsGhAfterWrite($rel, $bytes) {
    try {
        $rel = str_replace('\\', '/', ltrim((string)$rel, '/'));
        if (!cmsGhShouldMirror($rel)) return ['ok' => false, 'reason' => 'excluded'];
        return cmsGhMirrorBytes($rel, $bytes, 'Fourge: update ' . $rel);
    } catch (Throwable $e) { return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()]; }
}
function cmsGhAfterDelete($rel) {
    try {
        $rel = str_replace('\\', '/', ltrim((string)$rel, '/'));
        if (!cmsGhShouldMirror($rel)) return ['ok' => false, 'reason' => 'excluded'];
        return cmsGhDeletePath($rel, 'Fourge: delete ' . $rel);
    } catch (Throwable $e) { return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()]; }
}
function fourgeApiGhMirror($me, $body) {
    // Any signed-in user: mirroring rides along with saving. cmsGhShouldMirror()
    // keeps it to real site files — no traversal, and never .git internals, the
    // engine, credentials or form PII (see the policy above).
    $path = str_replace('\\', '/', (string)($body['path'] ?? ''));
    $del  = !empty($body['delete']);
    $msg  = trim((string)($body['message'] ?? ''));
    if ($path === '' || $path[0] === '/' || !cmsGhShouldMirror($path)) {
        http_response_code(400); echo json_encode(['ok' => false, 'reason' => 'bad_path', 'error' => 'That file can’t be mirrored.']); return;
    }
    echo json_encode($del ? cmsGhDeletePath($path, $msg) : cmsGhMirrorBytes($path, (string)($body['content'] ?? ''), $msg));
}
// A client site's own repo is source control for its business content — no
// reason for it to be publicly readable. Idempotent: a GET first, so an
// already-private repo is a silent no-op rather than a redundant PATCH.
// Admin+ only, same as the content sync this rides alongside (see
// ghSyncSiteContent()/ghEnsureRepoPrivate() in admin/index.html).
function fourgeApiGhSetPrivate($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin access required']); return; }
    list($repo, , $token) = cmsGhMirrorCfg();
    if ($repo === '' || !preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) { echo json_encode(['ok' => false, 'reason' => 'no_repo']); return; }
    if ($token === '') { echo json_encode(['ok' => false, 'reason' => 'no_token']); return; }
    $base = 'https://api.github.com/repos/' . $repo;
    list($gc, $gd) = cmsGhApi('GET', $base, $token);
    if ($gc === 200 && !empty($gd['private'])) { echo json_encode(['ok' => true, 'already' => true]); return; }
    if ($gc !== 200) {
        // 404 here is almost never "no such repo": GitHub answers 404 for a
        // private repo the token can't see, so say what to check.
        $hint = ($gc === 404) ? ' — check the repo name (owner/name) in Settings, and that this token was granted access to that repository' : '';
        echo json_encode(['ok' => false, 'reason' => 'github', 'error' => 'GitHub returned ' . $gc . ' looking up the repo' . $hint]); return;
    }
    list($c, $d, $e) = cmsGhApi('PATCH', $base, $token, ['private' => true]);
    if ($c >= 200 && $c < 300) { echo json_encode(['ok' => true]); return; }
    error_log('Fourge GitHub set-private failed for ' . $repo . ': HTTP ' . $c . ' ' . ($d['message'] ?? $e));
    // Pushing files needs only "Contents: write"; flipping visibility needs repo
    // administration rights, which most tokens made for mirroring don't carry.
    $hint = ($c === 403 || $c === 404) ? ' The token needs repository administration rights to change visibility: "Administration: Read and write" on a fine-grained token, or the full "repo" scope on a classic one.' : '';
    echo json_encode(['ok' => false, 'reason' => 'github', 'error' => 'GitHub returned ' . $c . (isset($d['message']) ? ' — ' . $d['message'] : ($e ? ' — ' . $e : '')) . $hint]);
}
// Every file under the web root that belongs in the repo, as sorted relative
// paths — the same policy cmsGhShouldMirror() applies to single writes, with
// whole folders pruned early so .git/ and admin/ are never even walked.
// Symlinks are skipped: a link out of the web root must not pull outside files in.
function cmsGhListSiteFiles() {
    $root = PUBLIC_HTML; $out = [];
    $walk = function ($dir, $relDir) use (&$walk, &$out) {
        $items = @scandir($dir); if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item; $rel = ($relDir === '' ? $item : $relDir . '/' . $item);
            if (is_link($full)) continue;
            if (is_dir($full)) { if (!cmsGhSkipDir($rel)) $walk($full, $rel); continue; }
            if (is_file($full) && cmsGhShouldMirror($rel)) $out[] = $rel;
        }
    };
    $walk($root, '');
    sort($out, SORT_STRING);
    return $out;
}
// Push EVERY site file to the repo, from the server, in time-boxed batches: the
// client calls with {offset} and keeps calling while `next` isn't null. One
// recursive tree listing per call gives every remote sha at once, so a file the
// repo already holds byte-for-byte costs nothing — a repeat run over a caught-up
// site is a few GETs and no commits. Admin+ only, like gh_set_private.
// Response: {ok,total,done,next,pushed,upToDate,failed,skipped,errors}.
function fourgeApiGhSyncAll($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin access required']); return; }
    $cfg = cmsGhMirrorCfg(); list($repo, $branch, $token) = $cfg;
    if ($repo === '' || !preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) { echo json_encode(['ok' => false, 'reason' => 'no_repo']); return; }
    if ($token === '') { echo json_encode(['ok' => false, 'reason' => 'no_token']); return; }
    @set_time_limit(90);
    $files  = cmsGhListSiteFiles(); $total = count($files);
    $offset = max(0, (int)($body['offset'] ?? 0));
    $limit  = min(100, max(1, (int)($body['batch'] ?? 50)));
    $budget = 18.0; $t0 = microtime(true);
    $tree   = ($offset < $total) ? cmsGhFetchTree($repo, $branch, $token) : [];
    $max    = cmsGhMaxBytes();
    $pushed = 0; $upToDate = 0; $failed = 0; $skipped = 0; $errors = []; $i = $offset;
    for (; $i < $total && $i < $offset + $limit; $i++) {
        if ($i > $offset && (microtime(true) - $t0) > $budget) break;   // the client continues from `next`
        $rel  = $files[$i];
        $size = @filesize(PUBLIC_HTML . '/' . $rel);
        if ($size === false) { $skipped++; continue; }
        if ($size > $max) { $skipped++; if (count($errors) < 12) $errors[] = $rel . ': too big to mirror (' . round($size / 1048576, 1) . ' MB)'; continue; }
        $bytes = @file_get_contents(PUBLIC_HTML . '/' . $rel);
        if ($bytes === false) { $skipped++; continue; }
        $remote = ($tree === null) ? null : ($tree[$rel] ?? '');
        $r = cmsGhMirrorBytes($rel, $bytes, 'Fourge: sync ' . $rel . ' to GitHub', $cfg, $remote);
        unset($bytes);
        if (!empty($r['ok'])) { if (!empty($r['skipped'])) $upToDate++; else $pushed++; }
        else { $failed++; if (count($errors) < 12) $errors[] = $rel . ': ' . ($r['error'] ?? $r['reason'] ?? 'failed'); }
    }
    echo json_encode(['ok' => true, 'total' => $total, 'done' => $i, 'next' => ($i < $total ? $i : null),
                      'pushed' => $pushed, 'upToDate' => $upToDate, 'failed' => $failed, 'skipped' => $skipped, 'errors' => $errors]);
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
    if ($err)        return [false, 'Could not reach the lead connection: ' . $err];
    if ($code === 200) return [true, 'Connected — leads will flow in.'];
    if ($code === 401) return [false, 'Token rejected (401) — double-check the Private Integration Token.'];
    if ($code === 403) return [false, 'Access denied (403) — the token likely needs the Contacts scope, or the Location ID is wrong.'];
    if ($code === 404) return [false, 'Not found (404) — check the Location ID.'];
    return [false, 'The lead connection returned HTTP ' . $code];
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

// Fetch recent leads + conversations for the Lead Generation dashboard. Normalizes
// GHL's fields defensively so a shape change degrades gracefully rather than breaking.
function cmsGhlDashboard($token, $locationId) {
    $hdr = ['Authorization: Bearer ' . $token, 'Version: ' . GHL_API_VERSION, 'Accept: application/json'];
    $get = function ($url) use ($hdr) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 15]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code < 200 || $code >= 300 || !$res) return null;
        return json_decode($res, true);
    };
    $out = ['contacts' => [], 'conversations' => [], 'total' => 0, 'error' => ''];
    $cd = $get(GHL_API_BASE . '/contacts/?locationId=' . rawurlencode($locationId) . '&limit=25');
    if ($cd === null) { $out['error'] = 'Could not load leads — check the connection in Plugins.'; return $out; }
    foreach (($cd['contacts'] ?? []) as $c) {
        $name = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
        if ($name === '') $name = $c['contactName'] ?? ($c['name'] ?? '');
        $out['contacts'][] = [
            'id' => $c['id'] ?? '', 'name' => $name, 'email' => $c['email'] ?? '', 'phone' => $c['phone'] ?? '',
            'source' => $c['source'] ?? '', 'tags' => $c['tags'] ?? [], 'date' => $c['dateAdded'] ?? ($c['dateUpdated'] ?? ''),
        ];
    }
    $out['total'] = $cd['meta']['total'] ?? ($cd['total'] ?? count($out['contacts']));
    $vd = $get(GHL_API_BASE . '/conversations/search?locationId=' . rawurlencode($locationId) . '&limit=25');   // best-effort
    if (is_array($vd)) {
        foreach (($vd['conversations'] ?? []) as $v) {
            $out['conversations'][] = [
                'id' => $v['id'] ?? '', 'name' => $v['fullName'] ?? ($v['contactName'] ?? ($v['email'] ?? '')),
                'last' => $v['lastMessageBody'] ?? ($v['lastMessage'] ?? ''), 'type' => $v['lastMessageType'] ?? ($v['type'] ?? ''),
                'date' => $v['lastMessageDate'] ?? ($v['dateUpdated'] ?? ''),
                'contactId' => $v['contactId'] ?? '',
            ];
        }
    }
    return $out;
}

// Dashboard data — any signed-in user may view, but only when GHL is enabled.
function fourgeApiGhlDashboard($me, $body) {
    $cfg = cmsGhlConfig();
    if (!$cfg) { echo json_encode(['ok' => false, 'error' => 'Lead Generation isn\'t set up yet.']); return; }
    $d = cmsGhlDashboard($cfg['token'], $cfg['locationId']);
    echo json_encode(['ok' => empty($d['error']), 'contacts' => $d['contacts'], 'conversations' => $d['conversations'], 'total' => $d['total'], 'error' => $d['error']]);
}

// Map a conversation's raw channel/type to the send-message API's `type` enum.
function cmsGhlSendType($raw) {
    $t = strtoupper((string)$raw);
    if (strpos($t, 'LIVE') !== false || strpos($t, 'CHAT') !== false || strpos($t, 'WEBCHAT') !== false) return 'Live_Chat';
    if (strpos($t, 'EMAIL') !== false) return 'Email';
    if (strpos($t, 'WHATSAPP') !== false) return 'WhatsApp';
    if (strpos($t, 'INSTAGRAM') !== false || strpos($t, '_IG') !== false) return 'IG';
    if (strpos($t, 'FACEBOOK') !== false || strpos($t, '_FB') !== false || strpos($t, 'MESSENGER') !== false) return 'FB';
    return 'SMS';   // safe default for phone-based threads
}

// Fetch the message thread for one conversation, normalized into a flat list
// (oldest-first) the chat UI can render. GHL nests messages one level deep and
// returns newest-first, both handled here.
function cmsGhlMessages($token, $conversationId) {
    $hdr = ['Authorization: Bearer ' . $token, 'Version: ' . GHL_API_VERSION, 'Accept: application/json'];
    $ch = curl_init(GHL_API_BASE . '/conversations/' . rawurlencode($conversationId) . '/messages?limit=100');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 15]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) return [null, 'Could not reach the lead connection: ' . $err];
    $j = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $m = is_array($j) ? ($j['message'] ?? ($j['msg'] ?? '')) : '';
        if (is_array($m)) $m = implode('; ', $m);
        return [null, 'The lead connection returned HTTP ' . $code . ($m ? ': ' . $m : '')];
    }
    return [cmsGhlParseMessages($j), ''];
}

// PURE (no network): normalize a GHL messages response into a flat, oldest-first
// list. Handles the nested { messages: { messages: [...] } } and flat
// { messages: [...] } shapes, and GHL's newest-first ordering.
function cmsGhlParseMessages($j) {
    $rawList = [];
    if (isset($j['messages']['messages']) && is_array($j['messages']['messages'])) $rawList = $j['messages']['messages'];
    elseif (isset($j['messages']) && is_array($j['messages'])) $rawList = $j['messages'];
    $msgs = [];
    foreach ($rawList as $m) {
        if (!is_array($m)) continue;
        $dir = strtolower((string)($m['direction'] ?? ''));
        $att = (isset($m['attachments']) && is_array($m['attachments'])) ? array_values(array_filter(array_map('strval', $m['attachments']), 'strlen')) : [];
        $msgs[] = [
            'id'      => $m['id'] ?? '',
            'body'    => (string)($m['body'] ?? ($m['message'] ?? '')),
            'inbound' => ($dir === 'inbound'),
            'type'    => $m['messageType'] ?? ($m['type'] ?? ''),
            'date'    => $m['dateAdded'] ?? ($m['dateUpdated'] ?? ''),
            'attachments' => $att,
        ];
    }
    usort($msgs, function ($a, $b) { return strcmp((string)$a['date'], (string)$b['date']); });   // oldest → newest
    return $msgs;
}

// Send an outbound reply. Best-effort; returns GHL's error verbatim so a
// channel/permission problem is visible in the UI. Sends to the contact on the
// conversation's channel (GHL routes it into the existing thread).
function cmsGhlSendMessage($token, $contactId, $rawType, $text) {
    $type = cmsGhlSendType($rawType);
    $payload = json_encode(['type' => $type, 'contactId' => $contactId, 'message' => $text]);
    $hdr = ['Authorization: Bearer ' . $token, 'Version: ' . GHL_API_VERSION, 'Content-Type: application/json', 'Accept: application/json'];
    $ch = curl_init(GHL_API_BASE . '/conversations/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 15]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) return [false, 'Could not reach the lead connection: ' . $err, $type];
    if ($code >= 200 && $code < 300) return [true, '', $type];
    $j = json_decode($res, true);
    $m = is_array($j) ? ($j['message'] ?? ($j['msg'] ?? '')) : '';
    if (is_array($m)) $m = implode('; ', $m);
    return [false, 'Message not sent (' . $code . ')' . ($m ? ': ' . $m : ''), $type];
}

// Conversation thread + reply — any signed-in user (the module is team-facing),
// but only when Lead Generation is enabled. The token stays on the server.
function fourgeApiGhlMessages($me, $body) {
    $cfg = cmsGhlConfig();
    if (!$cfg) { echo json_encode(['ok' => false, 'error' => 'Lead Generation isn\'t set up yet.']); return; }
    $cid = trim((string)($body['conversationId'] ?? ''));
    if ($cid === '') { echo json_encode(['ok' => false, 'error' => 'Missing conversation id.']); return; }
    list($msgs, $err) = cmsGhlMessages($cfg['token'], $cid);
    if ($msgs === null) { echo json_encode(['ok' => false, 'error' => $err]); return; }
    echo json_encode(['ok' => true, 'messages' => $msgs]);
}

function fourgeApiGhlSend($me, $body) {
    $cfg = cmsGhlConfig();
    if (!$cfg) { echo json_encode(['ok' => false, 'error' => 'Lead Generation isn\'t set up yet.']); return; }
    $contactId = trim((string)($body['contactId'] ?? ''));
    $text      = trim((string)($body['message'] ?? ''));
    if ($contactId === '' || $text === '') { echo json_encode(['ok' => false, 'error' => 'Nothing to send.']); return; }
    list($ok, $err, $type) = cmsGhlSendMessage($cfg['token'], $contactId, (string)($body['type'] ?? ''), $text);
    echo json_encode(['ok' => $ok, 'error' => $err, 'channel' => $type]);
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

// Resolve the SMTP envelope-From the same way for the live form and the test
// diagnostic: use the configured mg_from, but ignore the built-in example
// placeholder and fall back to the SMTP login — always a valid sender on the
// relay's own domain — so a missing or placeholder mg_from can never make the
// relay reject the message. Returns [email, displayName].
function cmsSmtpFrom($mgFrom) {
    $fromRaw = trim((string)$mgFrom);
    if ($fromRaw === '' || stripos($fromRaw, 'example.com') !== false) $fromRaw = SMTP_USER;
    $fromEmail = $fromRaw; $fromName = '';
    if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $fromRaw, $mm)) { $fromName = trim($mm[1], " \"'"); $fromEmail = trim($mm[2]); }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = SMTP_USER;
    return [$fromEmail, $fromName];
}

// data/uploads/ holds files visitors attach to a form submission. Never
// execute anything in it (a renamed/disguised script must stay inert even if
// a hosting config ignores php_flag) and never let it be browsed/listed —
// each file's URL is an unguessable token, not something to enumerate.
function fourgeWriteUploadsHtaccess() {
    $dir = PUBLIC_HTML . '/data/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $htPath   = $dir . '/.htaccess';
    $existing = is_file($htPath) ? (string)file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Uploads Guard';
    $end   = '# END Fourge Uploads Guard';
    $rules = <<<'HT'
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<FilesMatch "\.(php\d?|phtml|phar|cgi|pl|py|sh|exe)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
Options -Indexes
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// data/entries.json holds every submitted lead (name/email/phone/message, and
// now file-upload paths) — unlike pages/posts/site.json, no legitimate visitor
// page ever fetches it, so (like users.json) it has no business being
// publicly readable. Same managed-marker splice as fourgeWriteAdminHtaccess.
function fourgeWriteEntriesHtaccess() {
    $dir = PUBLIC_HTML . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $htPath   = $dir . '/.htaccess';
    $existing = is_file($htPath) ? (string)file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Entries Guard';
    $end   = '# END Fourge Entries Guard';
    $rules = <<<'HT'
<FilesMatch "^entries\.json$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// Extensions allowed through a form's File Upload field — common document/
// image types a lead-gen form realistically needs (resumes, photos, quotes).
// Anything else (scripts, executables, disguised extensions) is refused.
// Moves one $_FILES entry into data/uploads/<formId>/, validated and safely
// renamed (random token + sanitized basename — never the caller's own
// filename verbatim). Returns a site-relative URL on success, or null on any
// failure (bad extension, too large, upload error); the caller just omits the
// field rather than failing the whole submission over one bad attachment.
function fourgeStoreFormUpload($formId, $file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return null;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > FOURGE_UPLOAD_MAX_BYTES) return null;
    $orig = (string)($file['name'] ?? 'file');
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, FOURGE_UPLOAD_ALLOWED_EXT, true)) return null;
    $base = trim((string)preg_replace('~[^A-Za-z0-9_-]+~', '-', pathinfo($orig, PATHINFO_FILENAME)), '-');
    if ($base === '') $base = 'file';
    $base = substr($base, 0, 60);
    $formSlug = trim((string)preg_replace('~[^A-Za-z0-9_-]+~', '-', (string)$formId), '-');
    if ($formSlug === '') $formSlug = 'form';
    $dir = PUBLIC_HTML . '/data/uploads/' . $formSlug;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '-' . $base . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return null;
    return 'data/uploads/' . $formSlug . '/' . $name;
}
function cmsSendForm($body) {
    $mg = cmsMailgun();
    // A submission carrying a file upload arrives as multipart/form-data (see
    // ffSubmit's hasFile branch) instead of JSON, so $body is empty here —
    // fall back to $_POST/$_FILES, the same way $action already does above
    // when there's no JSON body to read.
    if (empty($body) && (!empty($_POST) || !empty($_FILES))) {
        $formId  = (string)($_POST['formId']  ?? '');
        $subject = (string)($_POST['subject'] ?? 'New Form Submission');
        $toEmail = (string)($_POST['to']      ?? $mg['notify']);
        $siteUrl = (string)($_POST['siteUrl'] ?? '');
        $rcToken = (string)($_POST['recaptcha'] ?? '');
        $hcToken = (string)($_POST['hcaptcha'] ?? '');
        $formPassword = (string)($_POST['formPassword'] ?? '');
        $metaKeys = ['action', 'formId', 'subject', 'to', 'siteUrl', 'recaptcha', 'hcaptcha', 'formPassword'];
        $fields = [];
        foreach ($_POST as $k => $v) {
            if (in_array($k, $metaKeys, true)) continue;
            $fields[$k] = is_array($v) ? implode(', ', $v) : $v;
        }
        foreach ($_FILES as $k => $file) {
            $url = fourgeStoreFormUpload($formId, $file);
            if ($url) $fields[$k] = $url;
        }
    } else {
    $fields  = $body['fields']  ?? [];
    $subject = $body['subject'] ?? 'New Form Submission';
    $toEmail = $body['to']      ?? $mg['notify'];
    $siteUrl = $body['siteUrl'] ?? '';
    $formId  = $body['formId']  ?? '';
    $rcToken = $body['recaptcha'] ?? '';
    $hcToken = $body['hcaptcha'] ?? '';
    $formPassword = $body['formPassword'] ?? '';
    }

    // Response limit / close date / password protection — checked first, since
    // there's no point verifying a captcha (below) for a submission that can't
    // be accepted anyway. $formDef is looked up again later by
    // cmsFormWebhookConfig()/cmsGhlFormMapping(); that's an accepted small
    // redundancy rather than threading one more parameter through both.
    $formDef = cmsFormById($formId);
    if ($formDef) {
        $closedReason = cmsFormClosedReason($formDef);
        if ($closedReason !== '') {
            http_response_code(403);
            echo json_encode(['error' => $closedReason]); return;
        }
        if (!cmsFormPasswordOk($formDef, $formPassword)) {
            http_response_code(403);
            echo json_encode(['error' => 'Incorrect password.']); return;
        }
    }

    // reCAPTCHA (only when a secret is configured in site.json). The check ALWAYS
    // runs so its outcome is recorded for the diagnostic, but a submission is blocked
    // ONLY when Google actively scores it as a bot ('blocked'). A misconfiguration
    // (no token / wrong keys / Google unreachable) returns 'allowed_unverified' and
    // the lead is let through, so a broken setup never silently loses real leads.
    $rcSecret = cmsRecaptchaSecret();
    $rcVerdict = null;
    if ($rcSecret) {
        $rcVerdict = cmsVerifyRecaptcha($rcSecret, $rcToken, cmsRecaptchaThreshold());
        if (($rcVerdict['outcome'] ?? '') === 'blocked') {
            http_response_code(400);
            echo json_encode(['error' => 'Your submission looked automated and was blocked. Please try again.']); return;
        }
    }
    // hCaptcha: an alternative to reCAPTCHA, same non-losing philosophy — but it
    // has no score to threshold against, so (like reCAPTCHA v2) it never actively
    // blocks, only verifies-and-logs. If reCAPTCHA already produced a verdict above,
    // that one stays authoritative on the stored entry; hCaptcha's only fills in
    // when reCAPTCHA isn't configured.
    $hcSecret = cmsHcaptchaSecret();
    if ($hcSecret) {
        $hcVerdict = cmsVerifyHcaptcha($hcSecret, $hcToken);
        if (!$rcVerdict) $rcVerdict = $hcVerdict;
    }

    // Store the submission in data/entries.json (best-effort, non-fatal). The
    // verdict rides along so the score is on the entry itself, not just a
    // separate log an admin has to cross-reference by timestamp.
    cmsStoreEntry($formId, $fields, $siteUrl, $rcVerdict);

    // Push into GoHighLevel as a lead (best-effort; never blocks the form or email)
    $ghl = cmsGhlConfig();
    if ($ghl) { try { cmsGhlPushLead($ghl['token'], $ghl['locationId'], $fields, $subject, $siteUrl, $formId); } catch (Throwable $e) { /* non-fatal */ } }

    // Generic outbound webhook: a per-form URL (Settings → this form → Outbound
    // Webhook) gets the raw submission as signed JSON — Zapier/Make/Slack/your own
    // endpoint, the operator's choice. Best-effort; never blocks the form or email.
    try {
        $webhook = cmsFormWebhookConfig($formId);
        if ($webhook && $webhook['url']) {
            cmsSendWebhook($webhook['url'], $webhook['secret'], [
                'formId' => $formId, 'subject' => $subject, 'siteUrl' => $siteUrl,
                'fields' => $fields, 'date' => date('c'),
            ]);
        }
    } catch (Throwable $e) { /* non-fatal */ }

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
        list($fromEmail, $fromName) = cmsSmtpFrom($mg['from']);
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

// Send a diagnostic test email down the SAME path a real form submission uses
// (SMTP when configured, otherwise the Mailgun HTTP API) and report back exactly
// what happened — method, host, From, config source, and the precise failure
// stage from cmsSmtpSend (e.g. "login rejected: 535 …", "MAIL FROM: 550 …",
// "connect failed"). Populates $info with the resolved config for the UI.
// Returns [bool success, string humanMessage].
function cmsSendTestEmail($to, &$info) {
    $mg   = cmsMailgun();
    $info = [
        'to'     => $to,
        'source' => $GLOBALS['__mail_source'] ?? 'config.secret.php',
        'method' => 'none',
    ];
    $subject = 'Fourge CMS test email';
    $text = "This is a test email sent from your Fourge CMS mail settings.\n"
          . "If you received it, outbound email from your site is working.\n\n"
          . 'Sent: ' . date('Y-m-d H:i:s');
    $html = '<!DOCTYPE html><html><body style="font-family:Inter,Arial,sans-serif;color:#1A1917;max-width:560px;margin:0 auto;padding:24px">'
          . '<h2 style="font-size:18px;margin:0 0 8px">Fourge CMS test email ✓</h2>'
          . '<p style="color:#555;font-size:14px">If you\'re reading this, your site\'s outbound email is working — form notifications will be delivered.</p>'
          . '<p style="font-size:11px;color:#A09882;margin-top:16px">Sent via Fourge CMS · ' . date('Y-m-d H:i') . '</p>'
          . '</body></html>';

    // Prefer SMTP when configured — mirrors cmsSendForm's method choice exactly.
    if (cmsSmtpEnabled()) {
        list($fromEmail, $fromName) = cmsSmtpFrom($mg['from']);
        $secure = SMTP_SECURE;
        if ($secure === 'auto') $secure = (SMTP_PORT === 465) ? 'auto → ssl (implicit TLS)' : 'auto → STARTTLS';
        $info['method'] = 'SMTP';
        $info['host']   = SMTP_HOST . ':' . SMTP_PORT;
        $info['secure'] = $secure;
        $info['user']   = SMTP_USER;
        $info['from']   = ($fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail);
        $err = '';
        $sent = cmsSmtpSend([
            'host' => SMTP_HOST, 'port' => SMTP_PORT, 'secure' => SMTP_SECURE, 'user' => SMTP_USER, 'pass' => SMTP_PASS,
            'from' => $fromEmail, 'fromName' => $fromName, 'to' => $to, 'toName' => '',
            'replyTo' => '', 'replyName' => '', 'subject' => $subject, 'html' => $html, 'text' => $text,
        ], $err);
        if ($sent) return [true, 'Test email sent over SMTP to ' . $to . '. Check that inbox (and the spam folder).'];
        return [false, 'SMTP send failed at stage — ' . $err];
    }

    // Otherwise the Mailgun HTTP API (only if a real key + domain are configured).
    if ($mg['key'] !== '' && $mg['domain'] !== '' && stripos($mg['domain'], 'example.com') === false) {
        $info['method'] = 'Mailgun API';
        $info['host']   = 'api.mailgun.net (' . $mg['domain'] . ')';
        $info['from']   = $mg['from'];
        $ch = curl_init('https://api.mailgun.net/v3/' . $mg['domain'] . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $mg['key'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['from'=>$mg['from'],'to'=>$to,'subject'=>$subject,'text'=>$text,'html'=>$html],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ce = curl_error($ch); curl_close($ch);
        if ($ce) return [false, 'Mailgun connection error: ' . $ce];
        if ($code === 200) return [true, 'Test email sent over the Mailgun API to ' . $to . '. Check that inbox (and the spam folder).'];
        $d = json_decode($res, true);
        return [false, 'Mailgun rejected the message (' . $code . '): ' . ($d['message'] ?? $res)];
    }

    return [false, 'No mail method is configured. Add SMTP settings (in send.php or config.secret.php) or a Mailgun API key.'];
}

// Admin diagnostic endpoint: run cmsSendTestEmail and return the structured
// result to the CMS so mail failures are self-explaining instead of a generic
// "could not be sent". Level 2+ (admin) — surfaces host/From but never secrets.
function fourgeApiSendTestEmail($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    $mg = cmsMailgun();
    $to = trim((string)($body['to'] ?? ''));
    if ($to === '') $to = trim((string)($me['email'] ?? ''));
    if ($to === '') $to = trim((string)$mg['notify']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Enter a valid recipient email address to send the test to.']); return;
    }
    $info = [];
    list($ok, $msg) = cmsSendTestEmail($to, $info);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $info));
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
    // null = caller didn't send this field at all (as opposed to an explicit
    // false) — resolved below into "preserve the existing value" for an edit,
    // or "off by default" for a brand-new account. Never just default it to 0
    // unconditionally: the admin UI now sends this explicitly on every save,
    // but a caller that doesn't know about the field yet must not silently
    // CLEAR an existing user's pending forced password change out from under
    // them just by saving an unrelated edit.
    $mustChange  = array_key_exists('mustChangePassword', $body) ? (!empty($body['mustChangePassword']) ? 1 : 0) : null;
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
        if ($mustChange === null) $mustChange = (int)!empty($existing['must_change_password']); // not sent → leave as-is
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
        if ($mustChange === null) $mustChange = 0; // not sent → off by default, same as before this field existed
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
// Names in this list have their VALUE (not just saved/not-saved status)
// returned to the browser — fleet_url was documented in fourgeSecretPolicy's
// own comment as belonging here ("handed back to the browser so it can be
// seen and edited") but was never actually added, so the Dashboard address
// field silently rendered blank on every reload despite saving correctly.
function fourgeClientFullSecrets() { return ['github_pat', 'repo_override', 'mg_domain', 'mg_from', 'mg_notify_to', 'tlp_url', 'fleet_url', 'ai_endpoint_url', 'ai_endpoint_model']; }

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
// data/posts.json is the site's public blog feed (the same file every blog
// page already fetches). This opens it to CROSS-ORIGIN reads so other sites —
// e.g. white-label group sites syndicating the flagship blog — can render it
// with the post-list block's data-src option. Scope is deliberately tight:
// GET-only CORS on exactly posts.json; nothing else in data/ is affected.
// Managed-marker splice, so any existing data/.htaccess content is preserved.
function fourgeWritePostsCorsHtaccess() {
    $dir = PUBLIC_HTML . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir)) return false;
    $htPath   = $dir . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Posts CORS';
    $end   = '# END Fourge Posts CORS';
    $rules = <<<'HT'
<IfModule mod_headers.c>
  <FilesMatch "^(posts|reviews|map|events)\.json$">
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET"
  </FilesMatch>
</IfModule>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// The 44i SEO platform posts deploy packages to the documented pretty paths
// /api/seo-platform/package and /api/seo-platform/tick. Apache rewrites them
// onto this file with the action in the query string, so the platform's POST
// body stays the raw package JSON. Written by the same login self-heal that
// installs clean URLs; marker-spliced, IfModule-guarded, best-effort.
function fourgeWriteSeoApiHtaccess() {
    $htPath   = PUBLIC_HTML . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge SEO Platform API';
    $end   = '# END Fourge SEO Platform API';
    $rules = <<<'HT'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^api/seo-platform/package/?$ admin/api.php?action=seo_package [L,QSA]
  RewriteRule ^api/seo-platform/tick/?$ admin/api.php?action=seo_pkg_tick [L,QSA]
</IfModule>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        // Must sit ABOVE the clean-URL block: that block rewrites any
        // extensionless request to <path>.html, which would swallow these.
        $cu = strpos($existing, '# BEGIN Fourge Clean URLs');
        if ($cu !== false) $existing = substr($existing, 0, $cu) . $block . "\n\n" . substr($existing, $cu);
        else $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// ── BLOG SYNC: partner-site syndication ─────────────────────────────────────
// Copies every post from a source Fourge site (e.g. 44idigital.com blogging
// for its partner network) into THIS site's own posts.json, so this site's
// blog page renders them like any other local post. Split into a network
// half (fetch) and a pure half (apply) so the merge/dedupe logic is
// unit-testable without ever calling out to a real site.
//
// Whole-object copy is deliberate: 44i's posts carry extra fields (authorRole,
// authorBio, topic, category, readMins) this CMS's own post editor doesn't
// know about. Re-mapping field-by-field would silently drop them; copying the
// object and only overriding what MUST be site-local (id, slug, plus the
// bookkeeping fields below) keeps them intact for any partner theme that
// wants to render them.
//
// MEDIA IS LOCALIZED, not hotlinked: a synced post's featured image and its
// image/video block files are downloaded from the source site into this
// site's own media library (images/blog-sync/, which the Media panel already
// lists) and the post is rewritten to those local paths. Hotlinking would
// leave every partner page's images pointing at the source domain — wrong
// branding story, broken pages the day the source reorganizes its files, and
// a per-pageview dependency on someone else's server. Only files ON the sync
// source's own host are fetched (same trust boundary as posts.json itself;
// a third-party CDN URL inside a post is left alone), the download itself never
// follows a redirect — it runs on the origin the feed fetch already resolved, so
// a www-canonical source works — and a failed download degrades to
// the absolute source URL so the post still renders.
function fourgeWriteBlogSyncApiHtaccess() {
    $htPath   = PUBLIC_HTML . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Blog Sync API';
    $end   = '# END Fourge Blog Sync API';
    $rules = <<<'HT'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^api/blog-sync/tick/?$ admin/api.php?action=blog_sync_tick [L,QSA]
</IfModule>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        // Must sit ABOVE the clean-URL block: that block rewrites any
        // extensionless request to <path>.html, which would swallow this.
        $cu = strpos($existing, '# BEGIN Fourge Clean URLs');
        if ($cu !== false) $existing = substr($existing, 0, $cu) . $block . "\n\n" . substr($existing, $cu);
        else $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
function fourgeBlogSyncUid() {
    // Mirrors the client's uid(): Math.random().toString(36).slice(2,9) — a
    // 7-char base36 id. Only needs to be locally unique, not cryptographic.
    $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
    $s = '';
    for ($i = 0; $i < 7; $i++) $s .= $chars[random_int(0, 35)];
    return $s;
}
function fourgeBlogSyncAuthorized($me, $body) {
    if ($me && fourgeLevel($me) >= 2) return true;
    $tok = '';
    $hdr = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($hdr !== '' && preg_match('~^Bearer\s+(.+)$~i', $hdr, $m)) $tok = trim($m[1]);
    if ($tok === '') $tok = trim((string)($_SERVER['HTTP_X_BLOG_SYNC_TOKEN'] ?? ''));
    if ($tok === '') $tok = trim((string)($body['sync_token'] ?? ''));
    if ($tok === '') return false;
    $stored = '';
    try { $stored = (string)fourgeGetSecret(fourgeDb(), 'blog_sync_token'); } catch (Throwable $e) { $stored = ''; }
    if ($stored === '') return false;
    return hash_equals($stored, $tok);
}
// Same site? The source is configured as an origin (https://44idigital.com), and
// many sites 301 their apex to www. (or back). Following that one hop is the
// difference between a working sync and "could not reach the source blog" — but a
// redirect to any OTHER host is refused: the SSRF guard only vetted the configured
// one, and a look-alike suffix (44idigital.com.evil.example) is not the same site.
function fourgeBlogSyncSameSite($hostA, $hostB) {
    $a = strtolower(preg_replace('~^www\.~i', '', trim((string)$hostA)));
    $b = strtolower(preg_replace('~^www\.~i', '', trim((string)$hostB)));
    return $a !== '' && $a === $b;
}
// Network half: fetch <source>/data/posts.json, following up to 4 same-site https
// redirects (apex ↔ www), and keep only what the source's own blog runtime would
// show right now (published, or scheduled with a publishAt that has passed — the
// exact rule loadBlog() uses). Returns
//   ['ok'=>true,  'posts'=>[…], 'base'=>'https://www.example.com']   the origin that actually served the list
//   ['ok'=>false, 'error'=>'why, in plain words', 'base'=>…]          for lastResult / "Check now"
function fourgeBlogSyncFetchRemote($sourceUrl) {
    $sourceUrl = rtrim(trim((string)$sourceUrl), '/');
    $p = @parse_url($sourceUrl);
    if ($sourceUrl === '' || !$p || empty($p['host'])) return ['ok' => false, 'error' => 'no source address is configured', 'base' => ''];
    if (($p['scheme'] ?? '') !== 'https') return ['ok' => false, 'error' => 'the source address must start with https://', 'base' => $sourceUrl];
    if (!fourgeTlpUrlOk($sourceUrl)) return ['ok' => false, 'error' => 'the source address is not one this server is allowed to fetch', 'base' => $sourceUrl];
    $origHost = (string)$p['host'];
    $base = $sourceUrl;
    $url  = $sourceUrl . '/data/posts.json';
    for ($hop = 0; $hop <= 4; $hop++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,   // every hop is vetted by hand below
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'FourgeCMS Blog Sync',
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        if ($code >= 300 && $code < 400) {
            if ($loc === '') return ['ok' => false, 'error' => 'the source redirected (HTTP ' . $code . ') without saying where', 'base' => $base];
            $lp = @parse_url($loc);
            if ($lp && empty($lp['host'])) {   // a relative Location — resolve against the address just fetched
                $cur = @parse_url($url);
                $loc = ($cur['scheme'] ?? 'https') . '://' . ($cur['host'] ?? $origHost) . (isset($cur['port']) ? ':' . $cur['port'] : '') . (substr($loc, 0, 1) === '/' ? $loc : '/' . $loc);
                $lp  = @parse_url($loc);
            }
            if (!$lp || empty($lp['host'])) return ['ok' => false, 'error' => 'the source redirected to an address that could not be understood (' . $loc . ')', 'base' => $base];
            if (($lp['scheme'] ?? '') !== 'https') return ['ok' => false, 'error' => 'the source redirected to a non-https address (' . $loc . ')', 'base' => $base];
            if (!fourgeBlogSyncSameSite($origHost, $lp['host'])) return ['ok' => false, 'error' => 'the source redirected to a different site (https://' . $lp['host'] . ') — if that is the right address, use it as the source', 'base' => $base];
            if (!fourgeTlpUrlOk($loc)) return ['ok' => false, 'error' => 'the source redirected to an address this server is not allowed to fetch', 'base' => $base];
            $url  = $loc;
            $base = 'https://' . $lp['host'] . (isset($lp['port']) ? ':' . $lp['port'] : '');
            continue;
        }
        if ($code === 200 && $raw !== false) {
            $data = json_decode((string)$raw, true);
            if (is_array($data) && isset($data['posts']) && is_array($data['posts'])) $data = $data['posts'];   // a {posts:[…]} wrapper is fine too
            $isList = is_array($data) && ($data === [] || array_keys($data) === range(0, count($data) - 1));
            if (!$isList) return ['ok' => false, 'error' => $base . '/data/posts.json is not a list of posts', 'base' => $base];
            $now = time(); $live = [];
            foreach ($data as $post) {
                if (!is_array($post) || empty($post['id'])) continue;
                if (!empty($post['published'])) { $live[] = $post; continue; }
                $publishAt = $post['publishAt'] ?? null;
                if ($publishAt) {
                    $t = strtotime((string)$publishAt);
                    if ($t !== false && $t <= $now) $live[] = $post;
                }
            }
            return ['ok' => true, 'posts' => $live, 'base' => $base];
        }
        if ($code === 0) return ['ok' => false, 'error' => 'could not connect to ' . $base . ($err !== '' ? ' (' . $err . ')' : ''), 'base' => $base];
        return ['ok' => false, 'error' => 'HTTP ' . $code . ' for ' . $base . '/data/posts.json' . ($code === 404 ? ' — is the source a Fourge site with a blog?' : ''), 'base' => $base];
    }
    return ['ok' => false, 'error' => 'the source redirected too many times', 'base' => $base];
}
// The older shape (the live post list, or null): kept for callers and tests.
function fourgeBlogSyncFetchRemotePosts($sourceUrl) {
    $r = fourgeBlogSyncFetchRemote($sourceUrl);
    return !empty($r['ok']) ? $r['posts'] : null;
}
// Media on the source is usually stored as a relative path (assets/blog/x.jpg —
// 40 of 44idigital's 49 posts). Copied to another domain that would 404, so every
// relative URL is rewritten against the source: the featured image, image/video
// block URLs, and src/href attributes inside HTML-carrying blocks.
function fourgeBlogSyncAbsUrl($u, $base) {
    $u = (string)$u; $base = rtrim((string)$base, '/');
    if ($u === '' || $base === '') return $u;
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $u)) return $u;   // absolute, protocol-relative, data:/mailto:/tel:, fragment
    return $base . (substr($u, 0, 1) === '/' ? '' : '/') . $u;
}
function fourgeBlogSyncAbsolutize($post, $base) {
    $base = rtrim((string)$base, '/');
    if (!is_array($post) || $base === '') return $post;
    if (!empty($post['featured']) && is_string($post['featured'])) $post['featured'] = fourgeBlogSyncAbsUrl($post['featured'], $base);
    if (!empty($post['blocks']) && is_array($post['blocks'])) {
        foreach ($post['blocks'] as $i => $b) {
            if (!is_array($b)) continue;
            $type = (string)($b['type'] ?? '');
            if (($type === 'image' || $type === 'video') && !empty($b['url']) && is_string($b['url'])) $b['url'] = fourgeBlogSyncAbsUrl($b['url'], $base);
            if (!empty($b['poster']) && is_string($b['poster'])) $b['poster'] = fourgeBlogSyncAbsUrl($b['poster'], $base);
            if (!empty($b['html']) && is_string($b['html']) && strpos($b['html'], '<') !== false) {
                $b['html'] = preg_replace_callback('~\b(src|href)=(["\'])([^"\']*)\2~i', function ($m) use ($base) {
                    return $m[1] . '=' . $m[2] . fourgeBlogSyncAbsUrl($m[3], $base) . $m[2];
                }, $b['html']);
            }
            $post['blocks'][$i] = $b;
        }
    }
    return $post;
}
// ── media localization ──────────────────────────────────────────────────────
// Resolve a post-media reference to the absolute source-site URL it should be
// downloaded from — or null when it is not ours to localize (empty, data:,
// or hosted somewhere other than the sync source). Pure; unit-testable.
// Percent-encode what the source left raw in a media path — spaces, quotes,
// non-ASCII ("/uploads/44iD Full/x.png" is refused by curl outright, so such a
// file never localized and stayed a hotlink) — without double-encoding
// sequences that are already encoded. Slashes and RFC 3986 path characters stay.
function fourgeBlogSyncEncodePath($path) {
    return preg_replace_callback('#%[0-9A-Fa-f]{2}|[^A-Za-z0-9\-._~!$&\'()*+,;=:@/]#', function ($m) {
        return strlen($m[0]) === 3 ? $m[0] : rawurlencode($m[0]);
    }, (string)$path);
}

function fourgeBlogSyncMediaSourceUrl($raw, $sourceUrl) {
    $raw = trim((string)$raw);
    $sourceUrl = rtrim(trim((string)$sourceUrl), '/');
    if ($raw === '' || $sourceUrl === '' || strpos($raw, 'data:') === 0) return null;
    $srcHost = strtolower((string)parse_url($sourceUrl, PHP_URL_HOST));
    if ($srcHost === '') return null;
    $bare = preg_replace('~^www\.~', '', $srcHost);
    if (preg_match('~^https?://~i', $raw) || strpos($raw, '//') === 0) {
        $abs  = (strpos($raw, '//') === 0) ? 'https:' . $raw : $raw;
        $host = strtolower((string)parse_url($abs, PHP_URL_HOST));
        // Same-site check tolerates the www/bare spelling difference, but the
        // DOWNLOAD always uses the operator-validated $sourceUrl origin — the
        // fetch below never follows redirects, and a bare-domain URL on a
        // www-canonical site (or vice versa) would 301 and yield nothing.
        if ($host === '' || preg_replace('~^www\.~', '', $host) !== $bare) return null;
        $path = (string)parse_url($abs, PHP_URL_PATH);
        if ($path === '' || $path === '/') return null;
        return $sourceUrl . fourgeBlogSyncEncodePath($path);
    }
    $rel = preg_replace('~[?#].*$~s', '', ltrim($raw, '/'));   // path only, like the absolute branch
    if ($rel === '') return null;
    return $sourceUrl . '/' . fourgeBlogSyncEncodePath($rel);
}
// Deterministic local filename for a source URL: re-syncing (or two posts
// sharing one image) reuses the already-downloaded file instead of stacking
// copies. Pure; unit-testable.
function fourgeBlogSyncMediaLocalPath($absUrl) {
    $path = (string)parse_url((string)$absUrl, PHP_URL_PATH);
    $base = strtolower(basename(rawurldecode($path)));   // "My%20Photo.jpg" → my-photo.jpg, not my-20photo.jpg
    if (!preg_match('~^(?<name>.+)\.(?<ext>jpe?g|png|webp|gif|svg|ico|avif|mp4|m4v|mov|webm|ogv|ogg|mp3|wav|m4a)$~', $base, $m)) return null;
    $name = preg_replace('~[^a-z0-9._-]+~', '-', $m['name']);
    $name = trim(preg_replace('~-{2,}~', '-', $name), '-.');
    if ($name === '') $name = 'media';
    return 'images/blog-sync/' . substr(sha1((string)$absUrl), 0, 10) . '-' . $name . '.' . $m['ext'];
}
// Network half: download one media file from the sync source into the local
// media library. Returns the root-relative local path ('/images/blog-sync/…')
// or null. Never follows redirects (same rule as the posts.json fetch), caps
// the size, and refuses bodies that are obviously not media (an HTML error
// page saved as .jpg would otherwise poison the library).
function fourgeBlogSyncDownloadMedia($absUrl) {
    $rel = fourgeBlogSyncMediaLocalPath($absUrl);
    if ($rel === null) return null;
    $dest = PUBLIC_HTML . '/' . $rel;
    if (is_file($dest) && filesize($dest) > 0) return '/' . $rel;   // already localized
    $ch = curl_init($absUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXFILESIZE    => 52428800,   // 50 MB — bigger than any sane blog asset
        CURLOPT_USERAGENT      => 'FourgeCMS Blog Sync',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || $body === '' || strlen($body) > 52428800) return null;
    $lead = ltrim(substr($body, 0, 64));
    $isSvg = substr($rel, -4) === '.svg';
    if (!$isSvg && ($lead === '' || $lead[0] === '<')) return null;              // HTML/XML masquerading as media
    if ($type !== '' && !preg_match('~^(image|video|audio)/|octet-stream~', $type) && !($isSvg && strpos($type, 'svg') !== false)) return null;
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    if (@file_put_contents($dest, $body) === false) return null;
    return '/' . $rel;
}
// Rewrite one post's media references through $localize(absoluteSourceUrl) →
// local path|null. Applied to the featured image and to image/video block
// files; embed blocks are external by nature and are never touched. When a
// download fails the reference is left as the ABSOLUTE source URL, so the
// post still renders (hotlinked) rather than breaking. Pure given $localize;
// unit-testable with a stub. Returns [post, localizedCount].
//
// $isBackfill flips how a root-relative path is read. During an initial sync
// the post just arrived from the remote feed, so '/media/x.jpg' is rooted on
// the SOURCE site and must be resolved+downloaded. During a backfill the post
// already lives in this site's posts.json, so a path under the local library
// prefix is already localized — re-resolving it against the source would
// re-download it under a new name, or worse, rewrite it back to a source
// hotlink when the source 404s it.
function fourgeBlogSyncLocalizePostMedia($post, $sourceUrl, $localize, $isBackfill = false) {
    $count = 0;
    $swap = function ($val) use ($sourceUrl, $localize, $isBackfill, &$count) {
        if ($isBackfill && strpos((string)$val, '/images/blog-sync/') === 0) return $val;
        $abs = fourgeBlogSyncMediaSourceUrl($val, $sourceUrl);
        if ($abs === null) return $val;                    // foreign/empty — leave alone
        $local = $localize($abs);
        if ($local !== null && $local !== '') { $count++; return $local; }
        return $abs;                                       // degrade to absolute hotlink
    };
    if (!empty($post['featured']) && is_string($post['featured'])) $post['featured'] = $swap($post['featured']);
    if (!empty($post['blocks']) && is_array($post['blocks'])) {
        foreach ($post['blocks'] as &$b) {
            if (!is_array($b) || ($b['type'] ?? '') === 'embed') continue;
            foreach (['url', 'poster'] as $k) {
                if (!empty($b[$k]) && is_string($b[$k])) $b[$k] = $swap($b[$k]);
            }
        }
        unset($b);
    }
    return [$post, $count];
}
// Pure half: given the site record and an already-fetched+filtered remote
// post list, decide what's new, copy it into local posts.json, and return
// updated blogSync bookkeeping for the caller to persist into site.json.
// No network access of its own — media downloads go through the injected
// $fetchMedia(absUrl) → local-path|null callable (null = don't localize, keep
// source URLs), so the merge/dedupe/rewrite logic stays fully unit-testable with
// a stubbed $remotePosts array and a stubbed downloader. $base is the origin the
// list was really served from (after redirects) — canonical links, absolute
// media and the downloads all use it; $error is the fetch's plain-words reason
// when $remotePosts is null, so "Check now" says what actually went wrong.
function fourgeBlogSyncApplyRemotePosts($site, $remotePosts, $base = '', $error = '', $fetchMedia = null) {
    $blogSync  = is_array($site['blogSync'] ?? null) ? $site['blogSync'] : [];
    $syncedIds = is_array($blogSync['syncedIds'] ?? null) ? $blogSync['syncedIds'] : [];
    $syncedSet = array_flip(array_map('strval', $syncedIds));
    $sourceUrl = rtrim(trim((string)($blogSync['sourceUrl'] ?? '')), '/');
    $srcBase   = rtrim(trim((string)$base), '/'); if ($srcBase === '') $srcBase = $sourceUrl;

    if (!is_array($remotePosts)) {
        $blogSync['lastCheckedAt'] = gmdate('c');
        $blogSync['lastResult']    = 'Could not reach the source blog — ' . (trim((string)$error) !== '' ? trim((string)$error) : 'check the source address.');
        return ['blogSync' => $blogSync, 'added' => 0];
    }
    if ($srcBase !== '') $blogSync['resolvedBase'] = $srcBase;

    $posts = cmsPkgReadJson('posts.json', []);
    if (!is_array($posts)) $posts = [];
    $existingSlugs = [];
    foreach ($posts as $p) { if (is_array($p) && !empty($p['slug'])) $existingSlugs[$p['slug']] = true; }

    $added = 0; $mediaLocalized = 0;
    foreach ($remotePosts as $rp) {
        if (!is_array($rp)) continue;
        $rid = (string)($rp['id'] ?? '');
        if ($rid === '' || isset($syncedSet[$rid])) continue;

        // Whole-object copy — see note above — with two passes over its media:
        // every relative URL is first made absolute on the RESOLVED source origin,
        // then the localizer downloads what it can from there. The resolved origin
        // is what makes that download work on a www-canonical source, since the
        // downloader itself never follows a redirect. Anything it can't fetch — or
        // inline <img src>/<a href> inside paragraph HTML, which it doesn't handle
        // — stays an absolute source URL and still renders.
        $post  = fourgeBlogSyncAbsolutize($rp, $srcBase);
        if (is_callable($fetchMedia) && $srcBase !== '') {
            list($post, $n) = fourgeBlogSyncLocalizePostMedia($post, $srcBase, $fetchMedia);
            $mediaLocalized += $n;
        }
        $newId = fourgeBlogSyncUid();
        $slug  = (string)($rp['slug'] ?? $newId);
        if (isset($existingSlugs[$slug])) $slug = $slug . '-' . substr($newId, 0, 4);

        $post['id']             = $newId;
        $post['slug']           = $slug;
        $post['canonicalUrl']   = $srcBase !== '' ? ($srcBase . '/posts.html?p=' . rawurlencode((string)($rp['slug'] ?? ''))) : null;
        $post['syncedFrom']     = $sourceUrl;
        $post['syncedSourceId'] = $rid;

        array_unshift($posts, $post);
        $existingSlugs[$slug] = true;
        $syncedSet[$rid] = true;
        $added++;
    }

    if ($added > 0) cmsPkgWriteJson('posts.json', $posts);

    $blogSync['syncedIds']     = array_values(array_keys($syncedSet));
    $blogSync['lastCheckedAt'] = gmdate('c');
    $blogSync['lastResult']    = $added > 0
        ? ($added . ' new post' . ($added === 1 ? '' : 's') . ' synced'
           . ($mediaLocalized > 0 ? ' (' . $mediaLocalized . ' media file' . ($mediaLocalized === 1 ? '' : 's') . ' copied to the media library)' : ''))
        : 'Up to date — no new posts';
    return ['blogSync' => $blogSync, 'added' => $added];
}
// Combines fetch+apply and persists site.json. Silent, cheap no-op when Blog
// Sync isn't enabled or has no source configured — safe to call from both the
// explicit tick endpoint and the opportunistic login rider below.
// One sync at a time. Two blog visitors arriving together right as the cooldown
// lapses would otherwise both fetch and both append the same new post.
// Returns a lock handle, false when another sync holds it, null if no lock file
// could be made (then the sync simply runs unlocked, as it always did).
function fourgeBlogSyncLock() {
    $dir = PUBLIC_HTML . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fh = @fopen($dir . '/.blog-sync.lock', 'c');
    if (!$fh) return null;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
    return $fh;
}
// Backfill: posts synced BEFORE media localization existed (1.14.120) sit in
// posts.json with their media still hotlinking the source domain, and the
// normal sync never revisits them (syncedIds skips anything already copied).
// Walk the local feed and localize any remaining source-host media in synced
// posts. Idempotent and cheap on every tick: already-local paths resolve to
// no-ops, and the deterministic filenames mean a re-run downloads nothing
// that's already on disk. Pure given $fetchMedia; unit-testable with a stub.
function fourgeBlogSyncBackfillLocalMedia($sourceUrl, $fetchMedia) {
    $sourceUrl = rtrim(trim((string)$sourceUrl), '/');
    if ($sourceUrl === '' || !is_callable($fetchMedia)) return 0;
    $posts = cmsPkgReadJson('posts.json', []);
    if (!is_array($posts) || !$posts) return 0;
    $srcBare = preg_replace('~^www\.~', '', strtolower((string)parse_url($sourceUrl, PHP_URL_HOST)));
    if ($srcBare === '') return 0;
    $localized = 0; $dirty = false;
    foreach ($posts as &$p) {
        if (!is_array($p) || empty($p['syncedFrom'])) continue;   // local posts are the operator's own business
        $pBare = preg_replace('~^www\.~', '', strtolower((string)parse_url((string)$p['syncedFrom'], PHP_URL_HOST)));
        if ($pBare !== $srcBare) continue;                        // synced from some other source — not this sync's media
        list($np, $n) = fourgeBlogSyncLocalizePostMedia($p, $sourceUrl, $fetchMedia, true);
        if ($n > 0) { $p = $np; $localized += $n; $dirty = true; }
    }
    unset($p);
    if ($dirty) cmsPkgWriteJson('posts.json', $posts);
    return $localized;
}
function fourgeBlogSyncDoSync($site) {
    $blogSync = is_array($site['blogSync'] ?? null) ? $site['blogSync'] : [];
    if (empty($blogSync['enabled'])) return ['ok' => true, 'added' => 0, 'skipped' => 'Blog Sync is not enabled.'];
    $sourceUrl = trim((string)($blogSync['sourceUrl'] ?? ''));
    if ($sourceUrl === '') return ['ok' => true, 'added' => 0, 'skipped' => 'No source URL configured.'];

    $lock = fourgeBlogSyncLock();
    if ($lock === false) return ['ok' => true, 'added' => 0, 'skipped' => 'A sync is already running.'];
    try {
        $remote = fourgeBlogSyncFetchRemote($sourceUrl);
        $base   = (string)($remote['base'] ?? '');
        $result = fourgeBlogSyncApplyRemotePosts($site, !empty($remote['ok']) ? $remote['posts'] : null, $base, (string)($remote['error'] ?? ''), 'fourgeBlogSyncDownloadMedia');
        $bs = $result['blogSync'];
        // Media in posts synced before localization existed (main line 1.14.120)
        // is still hotlinked: walk them while the source is known to be reachable.
        // Downloads run on the resolved origin, so a www-canonical source works.
        if (!empty($remote['ok']) && $base !== '') {
            $backfilled = fourgeBlogSyncBackfillLocalMedia($base, 'fourgeBlogSyncDownloadMedia');
            if ($backfilled > 0) {
                $bs['lastResult'] = rtrim((string)$bs['lastResult'], '.')
                    . ' — localized ' . $backfilled . ' media file' . ($backfilled === 1 ? '' : 's') . ' in previously synced posts';
            }
        }
        // Persist into a FRESH read of site.json: the record handed in was read a
        // moment ago, and another save (a settings change, a page's nav update)
        // may have landed since — only the blogSync bookkeeping is ours to write.
        $persist = cmsPkgReadJson('site.json', null);
        if (!is_array($persist)) $persist = is_array($site) ? $site : [];
        $persist['blogSync'] = $bs;
        cmsPkgWriteJson('site.json', $persist);
        return ['ok' => !empty($remote['ok']), 'added' => $result['added'], 'lastResult' => $bs['lastResult']];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
// The cooldown-gated check. Reached from the login self-heal chain
// (fourgeApiInstallCleanUrls) and from the public blog_sync_poke that the blog
// pages fire on every view — so a site stays within ~10 minutes of its source
// for as long as anyone at all reads its blog, with zero setup. A site nobody
// visits or logs into can still point an external scheduler at blog_sync_tick.
function fourgeBlogSyncTickIfDue() {
    $site = cmsPkgReadJson('site.json', []);
    if (!is_array($site)) return null;
    $blogSync = is_array($site['blogSync'] ?? null) ? $site['blogSync'] : [];
    if (empty($blogSync['enabled'])) return null;
    $last = strtotime((string)($blogSync['lastCheckedAt'] ?? ''));
    if ($last !== false && (time() - $last) < 600) return null;   // < 10 minutes — not due yet
    return fourgeBlogSyncDoSync($site);
}
function fourgeApiBlogSyncTick($me, $body) {
    if (!fourgeBlogSyncAuthorized($me, $body)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Unauthorized']); return; }
    $site = cmsPkgReadJson('site.json', []);
    if (!is_array($site)) $site = [];
    $result = fourgeBlogSyncDoSync($site);
    echo json_encode($result + ['checked_at' => date('c')]);
}
// Public, unauthenticated and deliberately boring: it can only run the sync the
// admin already configured, and only once TickIfDue's 10-minute cooldown has
// passed — so the blog pages firing it on every view keep a partner site current
// within ~10 minutes of a publish, with no cron, and nobody can make it do more
// than one source fetch per ten minutes. Says nothing but whether posts arrived
// (the page re-renders its list when they did).
function fourgeApiBlogSyncPoke() {
    $r = null;
    try { $r = fourgeBlogSyncTickIfDue(); } catch (Throwable $e) { $r = null; }
    echo json_encode(['ok' => true, 'checked' => $r !== null, 'added' => (int)($r['added'] ?? 0)]);
}
function fourgeApiBlogSyncAdmin($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    $op = strtolower(trim((string)($body['op'] ?? 'status')));
    $has = false;
    try { $has = trim((string)fourgeGetSecret(fourgeDb(), 'blog_sync_token')) !== ''; } catch (Throwable $e) { $has = false; }
    $scheme = fourgeIsHttps() ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $endpoint = $host !== '' ? ($scheme . '://' . $host . '/api/blog-sync/tick') : '/api/blog-sync/tick';
    if ($op === 'regenerate') {
        if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required to rotate the key']); return; }
        try {
            $tok = bin2hex(random_bytes(24));
            fourgeSetSecret(fourgeDb(), 'blog_sync_token', $tok, (string)($me['username'] ?? ''));
            echo json_encode(['ok' => true, 'token' => $tok, 'endpoint' => $endpoint, 'hasToken' => true,
                'note' => 'Copy this key now — it is stored encrypted and cannot be shown again.']);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => 'Could not store the key: ' . $e->getMessage()]);
        }
        return;
    }
    if ($op === 'revoke') {
        if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required']); return; }
        try { fourgeDb()->prepare("DELETE FROM secrets WHERE name=?")->execute(['blog_sync_token']); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'hasToken' => false, 'endpoint' => $endpoint]);
        return;
    }
    echo json_encode(['ok' => true, 'hasToken' => $has, 'endpoint' => $endpoint]);
}
// ── GOOGLE REVIEWS ──────────────────────────────────────────────────────────
// Reviews are fetched HERE, on the server, because the Places API key is
// billable and data/site.json is publicly readable. The key lives encrypted in
// the secrets table and is never returned to the browser.
//
// WHAT GOOGLE ACTUALLY GIVES YOU: the Places API returns at most FIVE reviews
// per place, and only the ones it considers most relevant. There is no
// pagination and no parameter to ask for more — the full review list needs the
// Business Profile API, which is OAuth against the owner's Google account, not
// an API key. So this endpoint MERGES: every fetch folds new reviews into
// data/reviews.json and leaves existing ones alone, which means the saved set
// grows past five over time as Google rotates which five it hands back. That
// merge is also what makes the per-review show/hide toggles durable.
function fourgeReviewsPath() { return PUBLIC_HTML . '/data/reviews.json'; }
function fourgeReviewsLoad() {
    $f = fourgeReviewsPath();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d)) $d = [];
    if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
    if (!isset($d['place']) || !is_array($d['place'])) $d['place'] = [];
    return $d;
}
function fourgeReviewsSave($data) {
    $dir = PUBLIC_HTML . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return @file_put_contents(fourgeReviewsPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
}
// A review has no stable id in the legacy Places response, so identity is a hash
// of the things that cannot change for a given review: who wrote it, when, and
// what it says. The New API's resource name is preferred when present.
function fourgeReviewId($r) {
    if (!empty($r['gid'])) return substr(sha1('gid:' . $r['gid']), 0, 16);
    return substr(sha1(strtolower(trim((string)($r['author'] ?? ''))) . '|' . (string)($r['time'] ?? '') . '|' . substr((string)($r['text'] ?? ''), 0, 120)), 0, 16);
}
function fourgeReviewsHttpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => (string)$body, 'code' => $code, 'err' => $err];
}
// Normalise both API shapes into one review record.
function fourgeReviewsNormNew($rev) {
    $txt = $rev['originalText']['text'] ?? ($rev['text']['text'] ?? '');
    $when = (string)($rev['publishTime'] ?? '');
    return [
        'gid'      => (string)($rev['name'] ?? ''),
        'author'   => (string)($rev['authorAttribution']['displayName'] ?? ''),
        'photo'    => (string)($rev['authorAttribution']['photoUri'] ?? ''),
        'url'      => (string)($rev['authorAttribution']['uri'] ?? ''),
        'rating'   => (int)($rev['rating'] ?? 0),
        'text'     => (string)$txt,
        'time'     => $when !== '' ? (int)strtotime($when) : 0,
        'date'     => $when !== '' ? gmdate('Y-m-d', (int)strtotime($when)) : '',
        'relative' => (string)($rev['relativePublishTimeDescription'] ?? ''),
        'source'   => 'places-v1',
    ];
}
function fourgeReviewsNormLegacy($rev) {
    $t = (int)($rev['time'] ?? 0);
    return [
        'gid'      => '',
        'author'   => (string)($rev['author_name'] ?? ''),
        'photo'    => (string)($rev['profile_photo_url'] ?? ''),
        'url'      => (string)($rev['author_url'] ?? ''),
        'rating'   => (int)($rev['rating'] ?? 0),
        'text'     => (string)($rev['text'] ?? ''),
        'time'     => $t,
        'date'     => $t ? gmdate('Y-m-d', $t) : '',
        'relative' => (string)($rev['relative_time_description'] ?? ''),
        'source'   => 'places-legacy',
    ];
}
// Try the current Places API first, then the legacy endpoint. Which one a client's
// Google Cloud project has enabled varies, and a 403 from one is not a reason to
// tell the operator their key is broken.
function fourgeReviewsFetchGoogle($key, $placeId) {
    $tried = [];
    $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($placeId);
    $r = fourgeReviewsHttpGet($url, [
        'X-Goog-Api-Key: ' . $key,
        'X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri,reviews',
    ]);
    $tried[] = 'places-v1 HTTP ' . $r['code'];
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        if (is_array($d)) {
            $items = [];
            foreach ((array)($d['reviews'] ?? []) as $rev) $items[] = fourgeReviewsNormNew($rev);
            return ['ok' => true, 'api' => 'places-v1', 'tried' => $tried, 'items' => $items, 'place' => [
                'name'   => (string)($d['displayName']['text'] ?? ''),
                'rating' => (float)($d['rating'] ?? 0),
                'total'  => (int)($d['userRatingCount'] ?? 0),
                'mapUrl' => (string)($d['googleMapsUri'] ?? ''),
            ]];
        }
    }
    $newErr = '';
    if ($r['code'] !== 200) {
        $e = json_decode($r['body'], true);
        $newErr = (string)($e['error']['message'] ?? $r['err']);
    }
    // Legacy Place Details
    $url2 = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . rawurlencode($placeId)
          . '&fields=name,rating,user_ratings_total,url,reviews&reviews_sort=newest&key=' . rawurlencode($key);
    $r2 = fourgeReviewsHttpGet($url2);
    $tried[] = 'places-legacy HTTP ' . $r2['code'];
    $d2 = json_decode($r2['body'], true);
    $status = (string)($d2['status'] ?? '');
    if ($r2['code'] === 200 && $status === 'OK' && isset($d2['result'])) {
        $res = $d2['result'];
        $items = [];
        foreach ((array)($res['reviews'] ?? []) as $rev) $items[] = fourgeReviewsNormLegacy($rev);
        return ['ok' => true, 'api' => 'places-legacy', 'tried' => $tried, 'items' => $items, 'place' => [
            'name'   => (string)($res['name'] ?? ''),
            'rating' => (float)($res['rating'] ?? 0),
            'total'  => (int)($res['user_ratings_total'] ?? 0),
            'mapUrl' => (string)($res['url'] ?? ''),
        ]];
    }
    // Both failed — say which, and what it usually means.
    $legacyErr = (string)($d2['error_message'] ?? ($status !== '' ? $status : $r2['err']));
    $hint = '';
    $blob = $newErr . ' ' . $legacyErr;
    if (stripos($blob, 'not authorized') !== false || stripos($blob, 'REQUEST_DENIED') !== false || stripos($blob, 'API_KEY') !== false)
        $hint = ' Enable "Places API (New)" (or the legacy "Places API") on this key\'s Google Cloud project, and check the key\'s API restrictions.';
    elseif (stripos($blob, 'NOT_FOUND') !== false || stripos($blob, 'INVALID_REQUEST') !== false)
        $hint = ' The Place ID looks wrong — use Find my Place ID below.';
    elseif (stripos($blob, 'OVER_QUERY_LIMIT') !== false || stripos($blob, 'billing') !== false)
        $hint = ' Billing is not enabled on the Google Cloud project, or the key is over its quota.';
    return ['ok' => false, 'tried' => $tried,
            'error' => 'Google refused the request. ' . trim($newErr . ($newErr && $legacyErr ? ' / ' : '') . $legacyErr) . $hint];
}
function fourgeApiReviewsFetch($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    $key = '';
    try { $key = (string)fourgeGetSecret(fourgeDb(), 'google_places_key'); } catch (Throwable $e) {}
    if (trim($key) === '') { http_response_code(400); echo json_encode(['error' => 'No Google API key saved yet — add one above and press Save key.']); return; }
    $placeId = trim((string)($body['placeId'] ?? ''));
    if ($placeId === '') {
        $site = json_decode((string)@file_get_contents(PUBLIC_HTML . '/data/site.json'), true);
        $placeId = trim((string)($site['reviews']['placeId'] ?? ''));
    }
    if ($placeId === '' || !preg_match('~^[A-Za-z0-9_\-]{10,}$~', $placeId)) {
        http_response_code(400); echo json_encode(['error' => 'A Place ID is required — press "Find my Place ID" if you do not have it.']); return;
    }

    $got = fourgeReviewsFetchGoogle($key, $placeId);
    if (empty($got['ok'])) { http_response_code(502); echo json_encode(['error' => $got['error'], 'tried' => $got['tried']]); return; }

    $store = fourgeReviewsLoad();
    $byId  = [];
    foreach ($store['items'] as $it) { if (!empty($it['id'])) $byId[$it['id']] = $it; }

    $added = 0; $updated = 0;
    foreach ($got['items'] as $rev) {
        $id = fourgeReviewId($rev);
        $rev['id'] = $id;
        if (isset($byId[$id])) {
            // Keep the operator's decisions; refresh only what Google owns.
            $old = $byId[$id];
            $rev['hidden'] = !empty($old['hidden']);
            $rev['added']  = (string)($old['added'] ?? gmdate('c'));
            if ($old['text'] !== $rev['text'] || (int)$old['rating'] !== (int)$rev['rating']) $updated++;
            $byId[$id] = array_merge($old, $rev);
        } else {
            $rev['hidden'] = false;          // new reviews are visible by default
            $rev['added']  = gmdate('c');
            $byId[$id] = $rev;
            $added++;
        }
    }
    $items = array_values($byId);
    // Newest first; a review with no date sorts last rather than to the top.
    usort($items, function ($a, $b) { return ((int)($b['time'] ?? 0)) <=> ((int)($a['time'] ?? 0)); });

    $store['items']   = $items;
    $store['place']   = $got['place'];
    $store['placeId'] = $placeId;
    $store['api']     = $got['api'];
    $store['updated'] = gmdate('c');
    if (!fourgeReviewsSave($store)) { http_response_code(500); echo json_encode(['error' => 'Could not write data/reviews.json (check that the data folder is writable)']); return; }

    echo json_encode([
        'ok' => true, 'api' => $got['api'], 'returned' => count($got['items']),
        'added' => $added, 'updated' => $updated, 'total' => count($items),
        'place' => $got['place'], 'tried' => $got['tried'],
    ]);
}
// Place ID discovery, so nobody has to go hunting in Google's ID finder tool.
function fourgeApiReviewsFindPlace($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    $key = '';
    try { $key = (string)fourgeGetSecret(fourgeDb(), 'google_places_key'); } catch (Throwable $e) {}
    if (trim($key) === '') { http_response_code(400); echo json_encode(['error' => 'Save a Google API key first.']); return; }
    $q = trim((string)($body['query'] ?? ''));
    if ($q === '') {
        // Default to this site's own business name + address.
        $site = json_decode((string)@file_get_contents(PUBLIC_HTML . '/data/site.json'), true);
        $a = $site['address'] ?? [];
        $q = trim(((string)($site['name'] ?? '')) . ' ' . implode(' ', array_filter([
            (string)($a['street'] ?? ''), (string)($a['city'] ?? ''), (string)($a['state'] ?? ''), (string)($a['zip'] ?? '')
        ])));
    }
    if ($q === '') { http_response_code(400); echo json_encode(['error' => 'Type the business name and city to search for.']); return; }

    // searchText is POST-only, so this one does not go through the GET helper.
    $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => json_encode(['textQuery' => $q, 'maxResultCount' => 5]),
        CURLOPT_HTTPHEADER => [
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount',
            'Content-Type: application/json',
        ],
    ]);
    $body2 = (string)curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);
    $d = json_decode($body2, true);
    if ($code === 200 && isset($d['places'])) {
        $out = [];
        foreach ((array)$d['places'] as $pl) {
            $out[] = [
                'placeId' => (string)($pl['id'] ?? ''),
                'name'    => (string)($pl['displayName']['text'] ?? ''),
                'address' => (string)($pl['formattedAddress'] ?? ''),
                'rating'  => (float)($pl['rating'] ?? 0),
                'total'   => (int)($pl['userRatingCount'] ?? 0),
            ];
        }
        echo json_encode(['ok' => true, 'query' => $q, 'results' => $out]);
        return;
    }
    // Fall back to the legacy Find Place endpoint for keys that only have it.
    $u = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=' . rawurlencode($q)
       . '&inputtype=textquery&fields=place_id,name,formatted_address,rating,user_ratings_total&key=' . rawurlencode($key);
    $r2 = fourgeReviewsHttpGet($u);
    $d2 = json_decode($r2['body'], true);
    if ($r2['code'] === 200 && (($d2['status'] ?? '') === 'OK')) {
        $out = [];
        foreach ((array)($d2['candidates'] ?? []) as $pl) {
            $out[] = [
                'placeId' => (string)($pl['place_id'] ?? ''),
                'name'    => (string)($pl['name'] ?? ''),
                'address' => (string)($pl['formatted_address'] ?? ''),
                'rating'  => (float)($pl['rating'] ?? 0),
                'total'   => (int)($pl['user_ratings_total'] ?? 0),
            ];
        }
        echo json_encode(['ok' => true, 'query' => $q, 'results' => $out, 'api' => 'legacy']);
        return;
    }
    $msg = (string)($d['error']['message'] ?? ($d2['error_message'] ?? ($err ?: 'Google returned HTTP ' . $code)));
    http_response_code(502);
    echo json_encode(['error' => 'Place search failed: ' . $msg . ' Make sure "Places API (New)" is enabled for this key.']);
}
// ── 44i TARGETED LANDING PAGE FEED ──────────────────────────────────────────
// A read-only pull from the 44i platform: which clients have landing-page data,
// and for one client the exact service + geo pairs an account manager entered.
//
// This is a PROXY rather than a browser fetch, for one reason: the feed's only
// protection is a shared key in the query string. Fetching it from the browser
// would put that key in the admin's page source and in every network log; the
// key lives in the encrypted store and is appended here, server-side, so it
// never reaches a client at all.
function fourgeTlpUrlOk($url) {
    $p = @parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $host = strtolower($p['host']);
    // The URL is operator-supplied, so it is semi-trusted at best — a typo or a
    // pasted internal address must not turn this endpoint into a way to make the
    // server fetch things on its own network.
    if ($host === 'localhost' || substr($host, -6) === '.local') return false;
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else { $r = @gethostbynamel($host); if (is_array($r)) $ips = $r; }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    // A host that resolves to nothing is left to curl to fail on, rather than
    // being rejected here — DNS can be slow or split-horizon on shared hosts.
    return true;
}
function fourgeApiTlpFeed($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    $pdo = fourgeDb();
    if (fourgeLevel($me) < fourgeSecretLevel('tlp_key')) {
        http_response_code(403); echo json_encode(['error' => 'You do not have access to the landing page feed']); return;
    }
    $base = ''; $key = '';
    try { $base = trim((string)fourgeGetSecret($pdo, 'tlp_url')); } catch (Throwable $e) {}
    try { $key  = trim((string)fourgeGetSecret($pdo, 'tlp_key')); } catch (Throwable $e) {}
    if ($base === '' || $key === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Add the feed address and access key first.', 'configured' => false]);
        return;
    }
    if (!fourgeTlpUrlOk($base)) {
        http_response_code(400);
        echo json_encode(['error' => 'The feed address must be an https:// URL on a public host.']);
        return;
    }
    $qs = ['key' => $key];
    $client = trim((string)($body['client'] ?? ''));
    $intake = trim((string)($body['intake'] ?? ''));
    if ($intake !== '')      $qs['intake'] = $intake;
    else if ($client !== '') $qs['client'] = $client;
    $url = $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($qs);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect could aim the key somewhere else
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'FourgeCMS TLP',
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not reach the feed: ' . ($err ?: 'no response')]);
        return;
    }
    if ($code === 401) {
        http_response_code(401);
        echo json_encode(['error' => 'The feed rejected the access key. Check it, or ask 44i to rotate it.']);
        return;
    }
    if ($code === 404) {
        http_response_code(404);
        echo json_encode(['error' => 'That client has no landing page data in the feed yet.']);
        return;
    }
    $d = json_decode($raw, true);
    if ($code !== 200 || !is_array($d)) {
        http_response_code(502);
        echo json_encode(['error' => 'The feed returned HTTP ' . $code . '.']);
        return;
    }
    echo json_encode(['ok' => true, 'feed' => $d]);
}
// ── FLEET DASHBOARD: report this site's status to 44i's cross-site board ───
// Built entirely from files already on disk (no dependency on an open admin
// session's JS state), so it rides the login self-heal exactly like the other
// riders below and still reports on a site nobody has opened in weeks. Silent
// no-op until an operator saves a dashboard address and key (same shape as
// tlp_url/tlp_key) — most sites will never configure this.
function fourgeFleetSnapshot() {
    $site  = cmsPkgReadJson('site.json', []);
    $pages = cmsPkgReadJson('pages.json', []);
    $seo   = cmsPkgReadJson('seo.json', []);
    if (!is_array($site))  $site  = [];
    if (!is_array($pages)) $pages = [];
    if (!is_array($seo))   $seo   = [];

    $engineVersion = '';
    $idxPath = PUBLIC_HTML . '/admin/index.html';
    if (is_file($idxPath)) {
        // Only the first slice — the constant sits near the top and this file
        // can be well over a megabyte.
        $head = @file_get_contents($idxPath, false, null, 0, 200000);
        if ($head !== false && preg_match("/const CMS_VERSION='([^']+)'/", $head, $m)) $engineVersion = $m[1];
    }

    $tlpCount = 0;
    foreach ($pages as $pg) { if (is_array($pg) && !empty($pg['tlp'])) $tlpCount++; }

    // "On-page SEO generated" and the SEO grade are both the SAME weak/missing
    // gate the daily AI-autofix job already uses (fourgeAiWeak / FOURGE_TITLE_*
    // / FOURGE_DESC_*) — reused rather than a second, independently-invented
    // definition of "done", so this number and "Generate New Content" always
    // agree on what still needs work.
    $total = count($pages);
    $complete = 0;
    foreach ($pages as $pid => $pg) {
        $s = is_array($seo[$pid] ?? null) ? $seo[$pid] : [];
        $needT = fourgeAiWeak($s['title'] ?? '',       FOURGE_TITLE_MIN, FOURGE_TITLE_MAX);
        $needD = fourgeAiWeak($s['description'] ?? '', FOURGE_DESC_MIN,  FOURGE_DESC_MAX);
        if (!$needT && !$needD) $complete++;
    }
    $pct   = $total > 0 ? (int)round($complete / $total * 100) : null;
    $grade = $pct === null ? null : ($pct >= 80 ? 'A' : ($pct >= 55 ? 'B' : 'C'));

    $sitemapPath = PUBLIC_HTML . '/sitemap.xml';
    $sitemapExists = is_file($sitemapPath);
    $sitemapUrls = $sitemapExists ? substr_count((string)@file_get_contents($sitemapPath), '<loc>') : 0;

    $plcRaw = cmsPkgReadJson('postlaunch.json', null);
    $plc = ['everRun' => false, 'lastRun' => null, 'clean' => null, 'counts' => null];
    if (is_array($plcRaw)) {
        $plc = ['everRun' => true, 'lastRun' => $plcRaw['lastRun'] ?? null,
                'clean' => $plcRaw['clean'] ?? null, 'counts' => $plcRaw['counts'] ?? null];
    }

    return [
        'name'          => (string)($site['name'] ?? ''),
        'url'           => trim((string)($site['website'] ?? '')),
        'engineVersion' => $engineVersion,
        'seo'           => ['grade' => $grade, 'generatedPages' => $complete, 'totalPages' => $total, 'pct' => $pct],
        'sitemap'       => ['exists' => $sitemapExists, 'urlCount' => $sitemapUrls],
        'tlpCount'      => $tlpCount,
        'postLaunch'    => $plc,
        'reportedAt'    => gmdate('c'),
    ];
}
function fourgeFleetReport() {
    $pdo = fourgeDb();
    $url = ''; $key = '';
    try { $url = trim((string)fourgeGetSecret($pdo, 'fleet_url')); } catch (Throwable $e) {}
    try { $key = trim((string)fourgeGetSecret($pdo, 'fleet_key')); } catch (Throwable $e) {}
    if ($url === '' || $key === '') return false;         // not configured — silent no-op
    if (!fourgeTlpUrlOk($url)) return false;              // same https/public-host guard the TLP feed uses — this URL is operator-supplied too

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect could aim the key at a different host
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        // 'action' rides in the body, not a query string appended to the
        // operator-entered URL — avoids double-'?' if that URL ever has its
        // own query string, and needs no change on the receiving dashboard,
        // which already falls back to $body['action'] when $_GET has none.
        CURLOPT_POSTFIELDS => json_encode(['action' => 'register'] + fourgeFleetSnapshot()),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300 && $res !== false;
}
// ── MAP: ADDRESS → COORDINATES ──────────────────────────────────────────────
// Server-side so no key ever reaches the browser, and so this works on a site
// that has no key at all. Google first when a key exists (the same encrypted
// Places key the reviews plugin uses, under the same API restriction), then
// OpenStreetMap's Nominatim, which needs nothing. Either way the browser gets
// back two numbers.
function fourgeMapGeocodeGoogle($key, $addr) {
    $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => json_encode(['textQuery' => $addr, 'maxResultCount' => 1]),
        CURLOPT_HTTPHEADER => [
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: places.location,places.formattedAddress,places.displayName',
            'Content-Type: application/json',
        ],
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $d  = json_decode($raw, true);
    $pl = $d['places'][0] ?? null;
    if (!$pl || !isset($pl['location']['latitude'])) return null;
    return [
        'lat'   => round((float)$pl['location']['latitude'], 6),
        'lng'   => round((float)$pl['location']['longitude'], 6),
        'label' => (string)($pl['formattedAddress'] ?? ($pl['displayName']['text'] ?? '')),
        'via'   => 'Google',
    ];
}
function fourgeMapGeocodeOsm($addr) {
    // Nominatim's usage policy requires an identifying User-Agent and does not
    // want to be hammered — a pin is geocoded once, by hand, so that is fine.
    $u = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($addr);
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'FourgeCMS/1.x (+' . (defined('PUBLIC_HTML') ? 'self-hosted' : 'cms') . ')',
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $d = json_decode($raw, true);
    $r = (is_array($d) && isset($d[0])) ? $d[0] : null;
    if (!$r || !isset($r['lat'])) return null;
    return [
        'lat'   => round((float)$r['lat'], 6),
        'lng'   => round((float)$r['lon'], 6),
        'label' => (string)($r['display_name'] ?? ''),
        'via'   => 'OpenStreetMap',
    ];
}
function fourgeApiMapGeocode($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    $addr = trim((string)($body['address'] ?? ''));
    if ($addr === '') { http_response_code(400); echo json_encode(['error' => 'Type an address first.']); return; }
    $key = '';
    try { $key = trim((string)fourgeGetSecret(fourgeDb(), 'google_places_key')); } catch (Throwable $e) {}
    $hit = null;
    if ($key !== '') { try { $hit = fourgeMapGeocodeGoogle($key, $addr); } catch (Throwable $e) { $hit = null; } }
    if (!$hit)       { try { $hit = fourgeMapGeocodeOsm($addr); }          catch (Throwable $e) { $hit = null; } }
    if (!$hit) {
        http_response_code(404);
        echo json_encode(['error' => 'No match for that address. Check the spelling, or paste the coordinates from Google Maps directly.']);
        return;
    }
    echo json_encode(['ok' => true] + $hit);
}
// ── INDEXING SCAFFOLD ───────────────────────────────────────────────────────
// Three server-level indexing controls, in one marker-spliced block placed
// ABOVE the clean-URL rewrite (order matters — that block's catch-all rewrite
// would otherwise swallow the redirect below).
//
// Safety rules this block obeys, because ~16 live client sites run it:
//  • Options -MultiViews lives INSIDE <IfModule mod_rewrite.c>. If mod_rewrite
//    were unavailable, MultiViews would be the only thing serving /page from
//    page.html — disabling it unconditionally would break every clean URL.
//  • The trailing-slash 301 only fires when the slashed form is neither a real
//    file nor a real directory, i.e. only for URLs that 404 today. It cannot
//    change any working URL.
//  • The non-production guard matches an EXPLICIT list of non-production host
//    shapes and then unconditionally exempts this site's own configured
//    production host (and its www/non-www twin). There is deliberately NO
//    "anything else is non-production" catch-all: a client with extra live
//    domain aliases must never be de-indexed by this. If the site's host can't
//    be determined, the guard is omitted entirely.
function fourgeWriteIndexingHtaccess() {
    // Production host comes from the site's configured Website URL only — never
    // from the request, which could be the dev host we're guarding against.
    $prodHost = '';
    try {
        $site = json_decode((string)@file_get_contents(PUBLIC_HTML . '/data/site.json'), true);
        $w = trim((string)($site['website'] ?? ''));
        if ($w !== '') {
            if (!preg_match('~^https?://~i', $w)) $w = 'https://' . $w;
            $h = (string)parse_url($w, PHP_URL_HOST);
            $h = strtolower(preg_replace('~^www\.~i', '', $h));
            if ($h !== '' && preg_match('~^[a-z0-9.\-]+$~', $h)) $prodHost = $h;
        }
    } catch (Throwable $e) { $prodHost = ''; }

    $L = [];
    $L[] = '<IfModule mod_rewrite.c>';
    $L[] = '  RewriteEngine On';
    $L[] = '  # Stop Apache/LiteSpeed content negotiation from resolving /page on its';
    $L[] = '  # own — it silently bypasses (and defeats) the rules below.';
    $L[] = '  Options -MultiViews';
    $L[] = '  # /page/ -> /page  (only for a slashed URL that is not a real file or';
    $L[] = '  # directory, so this can never touch a URL that already works)';
    $L[] = '  RewriteCond %{REQUEST_URI} !^/(admin|data)/ [NC]';
    $L[] = '  RewriteCond %{REQUEST_FILENAME} !-d';
    $L[] = '  RewriteCond %{REQUEST_FILENAME} !-f';
    $L[] = '  RewriteRule ^(.+)/$ /$1 [R=301,L]';
    $L[] = '</IfModule>';
    if ($prodHost !== '') {
        $esc = str_replace('.', '\\.', $prodHost);
        $L[] = '<IfModule mod_setenvif.c>';
        $L[] = '  # Non-production hosts: explicit shapes only, never a catch-all.';
        $L[] = '  SetEnvIf Host "^(dev|staging|stg|test|qa|uat|preview|beta|demo|sandbox)[.\-]" FOURGE_NONPROD=1';
        $L[] = '  SetEnvIf Host "\.fourge\.com$" FOURGE_NONPROD=1';
        $L[] = '  SetEnvIf Host "^localhost" FOURGE_NONPROD=1';
        $L[] = '  SetEnvIf Host "\.local$" FOURGE_NONPROD=1';
        $L[] = '  SetEnvIf Host "^[0-9.]+$" FOURGE_NONPROD=1';
        $L[] = '  # …and this site\'s own production host is ALWAYS production, last word.';
        $L[] = '  SetEnvIf Host "^(www\.)?' . $esc . '(:[0-9]+)?$" !FOURGE_NONPROD';
        $L[] = '</IfModule>';
        $L[] = '<IfModule mod_headers.c>';
        $L[] = '  Header always set X-Robots-Tag "noindex, nofollow" env=FOURGE_NONPROD';
        $L[] = '</IfModule>';
        $L[] = '<IfModule mod_rewrite.c>';
        $L[] = '  RewriteCond %{ENV:FOURGE_NONPROD} =1';
        $L[] = '  RewriteRule ^robots\.txt$ /_fourge_robots_nonprod.txt [L]';
        $L[] = '</IfModule>';
    }
    // The alternate robots file the rule above serves on non-production hosts.
    $nonProd = PUBLIC_HTML . '/_fourge_robots_nonprod.txt';
    if (!is_file($nonProd)) {
        @file_put_contents($nonProd, "# Served only on non-production hosts (see the Fourge Indexing block in .htaccess).\nUser-agent: *\nDisallow: /\n");
    }
    return cmsPkgSpliceHtaccess('# BEGIN Fourge Indexing', '# END Fourge Indexing', implode("\n", $L));
}
// ── SECRETS ARE NOT DOWNLOADABLE ────────────────────────────────────────────
// admin/.htaccess denies direct HTTP access to the SQLite database (password
// hashes + encrypted secrets), config.secret.php (the key those secrets are
// encrypted WITH), and the Google service-account files. That file ships in the
// repo, but it is not in the updater's fetch list and never was self-healed, so
// a site deployed before it existed has no protection at all — and nobody would
// know, because nothing ever checked. This writes it, marker-spliced, on every
// login, preserving whatever else the operator put in that file.
function fourgeWriteAdminHtaccess() {
    $htPath   = __DIR__ . '/.htaccess';
    $existing = is_file($htPath) ? (string)file_get_contents($htPath) : '';
    $begin = '# BEGIN Fourge Secret Files';
    $end   = '# END Fourge Secret Files';
    $rules = <<<'HT'
# Never serve these over HTTP. PHP source is not emitted by the interpreter, but
# the .db and .json files here are not PHP and WOULD be sent as downloads.
<FilesMatch "(\.secret\.php|service-account\.json|\.ga-token\.json|\.db|\.db-wal|\.db-shm|\.sqlite|\.sqlite3)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
HT;
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// Prove it, rather than assume it. A deny rule in .htaccess does NOTHING on a
// host configured with AllowOverride None, and that failure is completely
// silent. So ask the web server for the files over real HTTP, from the server
// itself, and report what it actually sends back.
function fourgeSecretExposure($baseUrl = '') {
    $base = rtrim((string)$baseUrl, '/');
    if ($base === '') {
        // Fall back to this site's configured domain, then the request host.
        $site = json_decode((string)@file_get_contents(PUBLIC_HTML . '/data/site.json'), true);
        $w = trim((string)($site['website'] ?? ''));
        if ($w !== '' && !preg_match('~^https?://~i', $w)) $w = 'https://' . $w;
        if ($w === '' && !empty($_SERVER['HTTP_HOST'])) {
            $w = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        }
        $base = rtrim($w, '/');
    }
    if ($base === '') return ['ok' => false, 'error' => 'No site URL to test against — set Design → Website URL.'];

    // The database, wherever it actually lives, plus the key file and the
    // service-account files. Only paths inside the web root can be requested.
    $targets = [];
    $dbAbs = '';
    try { $dbAbs = (string)fourgeDbPath(); } catch (Throwable $e) {}
    if ($dbAbs !== '') {
        $real = realpath($dbAbs) ?: $dbAbs;
        $rootReal = realpath(PUBLIC_HTML) ?: PUBLIC_HTML;
        if (strpos($real, $rootReal) === 0) {
            $targets['database'] = ltrim(str_replace('\\', '/', substr($real, strlen($rootReal))), '/');
        }
    }
    $adminRel = ltrim(str_replace(realpath(PUBLIC_HTML) ?: PUBLIC_HTML, '', realpath(__DIR__) ?: __DIR__), '/\\');
    $adminRel = $adminRel === '' ? 'admin' : str_replace('\\', '/', $adminRel);
    $targets['encryption key'] = $adminRel . '/config.secret.php';
    if (is_file(__DIR__ . '/service-account.json')) $targets['Google service account'] = $adminRel . '/service-account.json';
    if (is_file(__DIR__ . '/protect.secret.php'))   $targets['page passwords']          = $adminRel . '/protect.secret.php';

    $findings = []; $exposed = 0; $unknown = 0;
    foreach ($targets as $label => $rel) {
        if ($rel === '') continue;
        $url = $base . '/' . $rel;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_RANGE => '0-512',
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $row = ['label' => $label, 'path' => '/' . $rel, 'status' => $code];
        if ($code === 0) { $row['verdict'] = 'unknown'; $row['detail'] = 'Could not reach the site from the server itself' . ($err ? ' (' . $err . ')' : '') . '.'; $unknown++; }
        elseif ($code === 200 || $code === 206) {
            // A PHP file that executed to nothing is fine; real bytes are not.
            $looksSource = (strpos($body, '<?php') !== false);
            $isPhp = (substr($rel, -4) === '.php');
            if ($isPhp && !$looksSource && trim($body) === '') { $row['verdict'] = 'ok'; $row['detail'] = 'Served empty — PHP executed it instead of sending the source.'; }
            else { $row['verdict'] = 'exposed'; $row['detail'] = 'DOWNLOADABLE by anyone who knows the URL.'; $exposed++; }
        }
        elseif ($code === 403 || $code === 401) { $row['verdict'] = 'ok'; $row['detail'] = 'Blocked (HTTP ' . $code . ').'; }
        elseif ($code === 404)                  { $row['verdict'] = 'ok'; $row['detail'] = 'Not found (HTTP 404) — nothing to download.'; }
        else                                    { $row['verdict'] = 'ok'; $row['detail'] = 'Not served (HTTP ' . $code . ').'; }
        $findings[] = $row;
    }
    return ['ok' => true, 'base' => $base, 'exposed' => $exposed, 'unknown' => $unknown, 'findings' => $findings];
}
function fourgeApiSecretExposure($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required']); return; }
    $installed = false;
    try { $installed = fourgeWriteAdminHtaccess(); } catch (Throwable $e) {}
    $res = fourgeSecretExposure((string)($body['base'] ?? ''));
    $res['guardInstalled'] = $installed;
    // The server's own outbound address, so an API key can be pinned to it in
    // Google Cloud. A restricted key is worthless to anyone who copies it.
    $res['serverIp'] = fourgeOutboundIp();
    echo json_encode($res);
}
// Best-effort: what Google (or anyone) sees as this server's source address.
function fourgeOutboundIp() {
    $local = (string)($_SERVER['SERVER_ADDR'] ?? '');
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $ip = trim((string)curl_exec($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && filter_var($ip, FILTER_VALIDATE_IP)) return ['outbound' => $ip, 'local' => $local];
    return ['outbound' => '', 'local' => $local];
}
// ── SAFE SECURITY HEADERS, ON BY DEFAULT ────────────────────────────────────
// The audit fails sites without these and none of them can change how a page
// renders, so they no longer wait for a deploy package to arrive. Content-
// Security-Policy is deliberately NOT here: a wrong CSP blocks the site's own
// scripts and styles and takes the page down, so it stays package-and-review
// only. A package's own value for any of these still overrides the default,
// because that block is spliced separately and later in the file.
function fourgeWriteDefaultHeaders() {
    $L = [];
    $L[] = '<IfModule mod_headers.c>';
    $L[] = '  Header always set X-Content-Type-Options "nosniff"';
    $L[] = '  Header always set X-Frame-Options "SAMEORIGIN"';
    $L[] = '  Header always set Referrer-Policy "strict-origin-when-cross-origin"';
    // HSTS only over HTTPS. Uses env=HTTPS rather than an <If> block: <If> is
    // Apache 2.4-only, and an unknown directive inside a module that DOES exist
    // on 2.2 is a fatal config error — it would 500 the whole site. mod_ssl sets
    // the HTTPS env var, so env= is the portable form and is a no-op on plain
    // HTTP, where sending HSTS would be useless at best and would lock a
    // not-yet-certificated domain out of the browser at worst.
    $L[] = '  Header always set Strict-Transport-Security "max-age=31536000" env=HTTPS';
    $L[] = '</IfModule>';
    return cmsPkgSpliceHtaccess('# BEGIN Fourge Default Headers', '# END Fourge Default Headers', implode("\n", $L));
}
// ── llms.txt ────────────────────────────────────────────────────────────────
// A map of the site for AI crawlers. Built from real data only — the imported
// business facts plus this site's own published pages and posts — so a site gets
// one before any package ships a hand-written version. Never overwrites a file
// that already exists: a delivered or hand-edited llms.txt always wins.
function fourgeLlmsFallback() {
    $site = json_decode((string)@file_get_contents(PUBLIC_HTML . '/data/site.json'), true);
    if (!is_array($site)) $site = [];
    $b = is_array($site['business'] ?? null) ? $site['business'] : [];
    $name = trim((string)(($b['name'] ?? '') ?: ($site['name'] ?? '')));
    if ($name === '') return '';
    $base = trim((string)($site['website'] ?? ''));
    if ($base !== '' && !preg_match('~^https?://~i', $base)) $base = 'https://' . $base;
    $base = rtrim($base, '/');

    $out = '# ' . $name . "\n";
    $desc = trim((string)(($b['description'] ?? '') ?: ($site['tagline'] ?? '')));
    if ($desc !== '') $out .= "\n> " . $desc . "\n";
    if (!empty($b['services'])) $out .= "\nServices: " . implode(', ', (array)$b['services']) . "\n";
    $sa = (array)($b['service_area'] ?? []);
    $towns = array_filter(array_merge([(string)($sa['primary'] ?? '')], (array)($sa['secondary'] ?? [])));
    if ($towns) $out .= 'Service area: ' . implode(', ', $towns) . "\n";
    $contact = array_filter([(string)($b['phone'] ?? ($site['phone'] ?? '')), (string)($b['email'] ?? ($site['email'] ?? ''))]);
    if ($contact) $out .= 'Contact: ' . implode(' · ', $contact) . "\n";

    $pages = cmsPkgReadJson('pages.json', []);
    if (is_array($pages) && $pages) {
        $lines = [];
        foreach ($pages as $rec) {
            if (!is_array($rec) || !empty($rec['draft'])) continue;
            $file = (string)($rec['path'] ?? ($rec['file'] ?? ''));
            if ($file === '') continue;
            $t = trim((string)($rec['title'] ?? ''));
            if ($t === '') continue;
            $lines[] = '- [' . $t . '](' . ($base !== '' ? cmsPkgPageUrl($base, $file) : '/' . cmsPkgUrlPath($file)) . ')';
            if (count($lines) >= 25) break;
        }
        if ($lines) $out .= "\n## Key pages\n" . implode("\n", $lines) . "\n";
    }
    $posts = cmsPkgReadJson('posts.json', []);
    if (is_array($posts) && $posts) {
        $lines = [];
        foreach ($posts as $po) {
            if (!is_array($po) || empty($po['published'])) continue;
            $t = trim((string)($po['title'] ?? ''));
            if ($t === '') continue;
            $u = (string)($po['path'] ?? '');
            $href = $u !== '' ? ($base !== '' ? cmsPkgPageUrl($base, $u) : '/' . cmsPkgUrlPath($u))
                              : ($base . '/posts.html?p=' . rawurlencode((string)($po['slug'] ?? '')));
            $lines[] = '- [' . $t . '](' . $href . ')';
            if (count($lines) >= 10) break;
        }
        if ($lines) $out .= "\n## Recent articles\n" . implode("\n", $lines) . "\n";
    }
    return $out;
}
define('FOURGE_LLMS_MARK', '<!-- generated by Fourge from this site\'s own pages -->');
function fourgeEnsureLlms($force = false) {
    $f = PUBLIC_HTML . '/llms.txt';
    if (is_file($f)) {
        $cur = trim((string)@file_get_contents($f));
        // A delivered or hand-edited llms.txt always wins. Only a file Fourge
        // generated itself is ever rewritten — which is what lets newly imported
        // business facts and pages show up in it.
        $ours = $cur === '' || strpos($cur, FOURGE_LLMS_MARK) !== false;
        if (!$ours) return false;
        if ($cur !== '' && !$force) return false;
    }
    $body = fourgeLlmsFallback();
    if ($body === '') return false;
    return @file_put_contents($f, FOURGE_LLMS_MARK . "\n" . $body) !== false;
}
// ── AI AUTO-FIX ─────────────────────────────────────────────────────────────
// Fills the gaps an audit grades — SEO titles, meta descriptions, image alt
// text, internal links — using the Anthropic key this site already has.
//
// The absolute rule: IT ONLY EVER FILLS GAPS. A title or description a human
// wrote is never touched. A "weak" value (outside the length the audit grades)
// IS rewritten, because a 12-character title is a gap wearing a hat — but a
// value that reads fine and measures fine is left exactly alone.
//
// Every run is capped so it stays cheap and quick, and every item lands in the
// report as written or skipped-with-a-reason. Nothing is ever silently dropped.
define('FOURGE_AI_MODEL', 'claude-haiku-4-5-20251001');
define('FOURGE_AI_STATE', 'ai_autofix.json');
// The lengths the audit grades. Outside these a value is "weak" and gets redone.
define('FOURGE_TITLE_MIN', 20); define('FOURGE_TITLE_MAX', 65);
define('FOURGE_DESC_MIN', 70);  define('FOURGE_DESC_MAX', 165);

function fourgeAiKey() {
    $key = '';
    try { $key = (string)fourgeGetSecret(fourgeDb(), 'claude_key'); } catch (Throwable $e) { $key = ''; }
    if ($key === '') { $cfg = foundrySecret(); $key = trim((string)($cfg['anthropic_key'] ?? '')); }
    if ($key === '' || strpos($key, 'REPLACE') !== false) return '';
    return $key;
}
// A custom endpoint is used only when ALL THREE fields are set — a half-filled
// one (e.g. a key pasted before the model name) is treated as not configured
// at all rather than guessing a default, so a typo fails obviously (missing
// key error) instead of silently misrouting a real request.
function fourgeAiEndpointCfg() {
    $pdo = fourgeDb();
    $url = ''; $key = ''; $model = '';
    try { $url   = trim((string)fourgeGetSecret($pdo, 'ai_endpoint_url'));   } catch (Throwable $e) {}
    try { $key   = trim((string)fourgeGetSecret($pdo, 'ai_endpoint_key'));   } catch (Throwable $e) {}
    try { $model = trim((string)fourgeGetSecret($pdo, 'ai_endpoint_model')); } catch (Throwable $e) {}
    if ($url === '' || $key === '' || $model === '') return null;
    if (!fourgeTlpUrlOk($url)) return null;   // same https/public-host guard every other operator-supplied URL gets
    return ['url' => $url, 'key' => $key, 'model' => $model];
}
// One server-side LLM call that RETURNS text (claudeProxy echoes to the
// client, which is no use to a batch job). Returns a string, or ['error'=>…].
// A configured custom endpoint (Settings → Custom AI Endpoint) is preferred
// over Anthropic — same "more specific wins" precedence cmsSendForm already
// uses for SMTP over Mailgun — so an operator who has set one up never pays
// for both providers on the same generation.
function fourgeAiText($system, $user, $max = 400) {
    $custom = fourgeAiEndpointCfg();
    if ($custom) return fourgeAiTextCustomEndpoint($custom, $system, $user, $max);
    $key = fourgeAiKey();
    if ($key === '') return ['error' => 'No Anthropic API key is configured for this site.'];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode(['model' => FOURGE_AI_MODEL, 'max_tokens' => (int)$max,
            'system' => (string)$system, 'messages' => [['role' => 'user', 'content' => (string)$user]]]),
    ]);
    $res = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'Could not reach Anthropic: ' . $err];
    $d = json_decode($res, true);
    if ($code !== 200) {
        return ['error' => 'Anthropic ' . $code . ': ' . mb_substr((string)($d['error']['message'] ?? $res), 0, 200)];
    }
    $text = '';
    foreach ((array)($d['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') $text .= (string)($blk['text'] ?? '');
    return trim($text);
}
// "Test Connection" — exercises the CUSTOM endpoint specifically (not
// fourgeAiText(), which would silently fall back to Anthropic if the
// endpoint is half-configured, defeating the point of a connectivity test).
function fourgeApiAiEndpointTest($me, $body) {
    if (fourgeLevel($me) < 4) { http_response_code(403); echo json_encode(['error' => 'Architect access required']); return; }
    $custom = fourgeAiEndpointCfg();
    if (!$custom) { http_response_code(400); echo json_encode(['error' => 'Set the endpoint URL, key, and model first, then save.']); return; }
    $reply = fourgeAiTextCustomEndpoint($custom, 'You are a connectivity test. Reply with exactly one word.', 'Reply with the single word: ok', 10);
    if (is_array($reply)) { http_response_code(502); echo json_encode(['error' => $reply['error'] ?? 'Unknown error']); return; }
    echo json_encode(['ok' => true, 'reply' => $reply]);
}
// A generic OpenAI-compatible /chat/completions call — Open WebUI, Ollama, LM
// Studio and vLLM all speak this same request/response shape, unlike
// Anthropic's: the system prompt rides inside messages[] rather than a
// separate top-level field, and the reply is choices[0].message.content
// rather than a content-blocks array. A longer timeout than the Anthropic
// call: a self-hosted single-worker instance can queue behind other traffic.
function fourgeAiTextCustomEndpoint($cfg, $system, $user, $max) {
    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
        CURLOPT_POSTFIELDS => json_encode(['model' => $cfg['model'], 'max_tokens' => (int)$max,
            'messages' => [['role' => 'system', 'content' => (string)$system], ['role' => 'user', 'content' => (string)$user]]]),
    ]);
    $res = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'Could not reach the custom AI endpoint: ' . $err];
    $d = json_decode($res, true);
    if ($code !== 200) {
        return ['error' => 'AI endpoint ' . $code . ': ' . mb_substr((string)($d['error']['message'] ?? $res), 0, 200)];
    }
    $text = (string)($d['choices'][0]['message']['content'] ?? '');
    return trim($text);
}
// Models wrap JSON in ``` fences however you ask them not to.
function fourgeAiJson($text) {
    $t = trim(preg_replace('~^```(?:json)?|```$~m', '', (string)$text));
    $d = json_decode($t, true);
    if (is_array($d)) return $d;
    if (preg_match('~\{[\s\S]*\}~', $t, $m)) { $d = json_decode($m[0], true); if (is_array($d)) return $d; }
    return null;
}
function fourgeAiWeak($v, $min, $max) {
    $v = trim((string)$v);
    if ($v === '') return true;                       // missing
    $n = mb_strlen($v);
    return $n < $min || $n > $max;                    // weak
}
// "1. Some alt text" → indexed list, tolerant of 1) and stray blank lines.
function fourgeAiNumbered($text) {
    $out = [];
    foreach (preg_split('~\r?\n~', (string)$text) as $line) {
        if (preg_match('~^\s*(\d+)\s*[.)]\s*(.+)$~', $line, $m)) {
            $v = trim($m[2]);
            $v = trim(preg_replace('~^["\x27]|["\x27]$~', '', $v));
            if ($v !== '') $out[(int)$m[1] - 1] = mb_substr($v, 0, 125);
        }
    }
    return $out;
}
// Deterministic alt from a filename, used when the model skips an item:
// "kitchen-remodel_lubbock-2-300x200.jpg" → "Kitchen remodel lubbock".
function fourgeAiAltFromFile($file) {
    $s = preg_replace('~\.[a-z0-9]+$~i', '', basename((string)$file));
    $s = preg_replace('~-?\d+x\d+$|-scaled$|-copy(-\d+)?$|-e\d{10,}~i', '', $s);
    $s = trim(preg_replace('~[-_]+~', ' ', $s));
    $s = trim(preg_replace('~\s*\d+\s*$~', '', $s));
    if ($s === '' || preg_match('~^(img|image|dsc|photo|screenshot|untitled|final|new)[\s\d]*$~i', $s)) return '';
    return ucfirst(mb_strtolower($s));
}
// Link the FIRST plain-text mention of a keyword, walking the markup so it can
// never land inside an existing link, a heading, a button, or a tag attribute.
// Returns the new HTML, or null when there was nowhere safe to put one.
// Detection only — a PHP-side echo of the client's seoFaqFromDoc() heuristic,
// used to decide whether a page ALREADY reads as an FAQ before the daily job
// considers writing one. Deliberately regex-based like the rest of this file
// rather than DOMDocument, which is not guaranteed present on every host this
// runs on. One Q&A pair is not an FAQ; real content needs at least two.
function fourgeHasQaContent($html) {
    $html = (string)$html;
    if (strlen($html) > 400000) return false;   // pathological page size — skip detection, not correctness-critical
    if (!preg_match_all('~<h[23]\b[^>]*>(.*?)</h[23]>~is', $html, $heads, PREG_OFFSET_CAPTURE)) return false;
    $n = count($heads[0]); $qualifying = 0;
    for ($i = 0; $i < $n; $i++) {
        $q = trim(preg_replace('~\s+~', ' ', strip_tags($heads[1][$i][0])));
        if (!preg_match('~\?\s*$~', $q) || mb_strlen($q) < 8 || mb_strlen($q) > 200) continue;
        $start = $heads[0][$i][1] + strlen($heads[0][$i][0]);
        $end = ($i + 1 < $n) ? $heads[0][$i + 1][1] : strlen($html);
        $chunk = substr($html, $start, $end - $start);
        // Stop at the NEXT heading of any level — an h4 between two h2s still
        // ends the answer, same as the JS version walking nextElementSibling.
        if (preg_match('~<h[1-6]\b~i', $chunk, $hm, PREG_OFFSET_CAPTURE)) $chunk = substr($chunk, 0, $hm[0][1]);
        $a = trim(preg_replace('~\s+~', ' ', strip_tags($chunk)));
        if (mb_strlen($a) >= 40) $qualifying++;
        if ($qualifying >= 2) return true;
    }
    return false;
}
// A minimal, standalone FAQPage stamp — deliberately NOT folded into
// cmsPkgStampSeo's extraJsonld, which is reserved for schema a package
// delivered verbatim. Marked with its own attribute so it can be told apart
// from (and cleanly superseded by) the full graph the page editor builds from
// the SAME aeoFAQs data the next time a human actually saves this page.
function fourgeAeoStampFaq($html, $faqs) {
    $html = (string)$html;
    $html = preg_replace('~[ \t]*<script\b[^>]*data-fourge-aeo-faq[^>]*>[\s\S]*?</script>[ \t]*\r?\n?~i', '', $html);
    $faqs = array_values(array_filter((array)$faqs, function ($f) {
        return is_array($f) && trim((string)($f['question'] ?? '')) !== '' && trim((string)($f['answer'] ?? '')) !== '';
    }));
    if (!$faqs) return $html;
    $entities = array_map(function ($f) {
        return ['@type' => 'Question', 'name' => trim((string)$f['question']),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim((string)$f['answer'])]];
    }, $faqs);
    $json = json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities],
                         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = str_ireplace('</script', '<\\/script', (string)$json);
    $block = '<script type="application/ld+json" data-fourge-aeo-faq>' . $json . '</script>';
    if (preg_match('~</head>~i', $html)) return preg_replace('~</head>~i', $block . "\n</head>", $html, 1);
    if (preg_match('~<head\b[^>]*>~i', $html)) return preg_replace('~<head\b[^>]*>~i', '$0' . "\n" . $block, $html, 1);
    return $block . "\n" . $html;
}
function fourgeAiLinkKeyword($html, $kw, $url) {
    $html = (string)$html;
    if ($kw === '' || $url === '') return null;
    if (stripos($html, 'href="' . $url) !== false) return null;      // already links there
    $parts = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $inA = 0; $inH = 0; $inBtn = 0; $inSkip = 0;
    $rx = '~\b(' . preg_quote($kw, '~') . ')\b~i';
    foreach ($parts as $i => $seg) {
        if ($seg === '') continue;
        if ($seg[0] === '<') {
            if     (preg_match('~^<a\b~i', $seg))            $inA++;
            elseif (preg_match('~^</a>~i', $seg))            $inA = max(0, $inA - 1);
            elseif (preg_match('~^<h[1-6]\b~i', $seg))       $inH++;
            elseif (preg_match('~^</h[1-6]>~i', $seg))       $inH = max(0, $inH - 1);
            elseif (preg_match('~^<button\b~i', $seg))       $inBtn++;
            elseif (preg_match('~^</button>~i', $seg))       $inBtn = max(0, $inBtn - 1);
            // Never inside script/style/textarea — their text is not prose.
            elseif (preg_match('~^<(script|style|textarea)\b~i', $seg)) $inSkip++;
            elseif (preg_match('~^</(script|style|textarea)>~i', $seg)) $inSkip = max(0, $inSkip - 1);
            continue;
        }
        if ($inA || $inH || $inBtn || $inSkip) continue;
        if (preg_match($rx, $seg)) {
            $parts[$i] = preg_replace($rx, '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">$1</a>', $seg, 1);
            return implode('', $parts);
        }
    }
    return null;
}
function fourgeAiState() { $s = cmsPkgReadJson(FOURGE_AI_STATE, []); return is_array($s) ? $s : []; }
function fourgeAiSaveState($s) { cmsPkgWriteJson(FOURGE_AI_STATE, $s); }

// The run. Caps keep each pass cheap; the weekly trigger picks up what is left.
function fourgeAiAutofix($opts = []) {
    $metaCap = max(0, (int)($opts['metaCap'] ?? 10));
    $altCap  = max(0, (int)($opts['altCap']  ?? 8));
    $linkCap = max(0, (int)($opts['linkCap'] ?? 10));
    $dry     = !empty($opts['dry']);
    if (fourgeAiKey() === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key is configured. Add one in Settings → Secrets (claude_key) first.'];
    }
    $rep = ['ok' => true, 'ran_at' => gmdate('c'), 'dry_run' => $dry,
            'metas' => [], 'alts' => [], 'links' => [], 'aeo' => [], 'skipped' => []];
    $skip = function ($what, $why) use (&$rep) { $rep['skipped'][] = ['item' => $what, 'reason' => $why]; };

    $site  = cmsPkgReadJson('site.json', []);  if (!is_array($site))  $site  = [];
    $pages = cmsPkgReadJson('pages.json', []); if (!is_array($pages)) $pages = [];
    $seo   = cmsPkgReadJson('seo.json', []);   if (!is_array($seo))   $seo   = [];
    $siteName = trim((string)($site['name'] ?? ''));
    $baseUrl  = trim((string)($site['website'] ?? ''));
    if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;

    // ── 1. missing OR weak SEO titles and descriptions ──────────────────────
    $seoDirty = false; $done = 0;
    foreach ($pages as $pid => $rec) {
        if ($done >= $metaCap) break;
        if (!is_array($rec) || !empty($rec['draft'])) continue;
        $file = (string)($rec['path'] ?? ($rec['file'] ?? ''));
        if ($file === '') continue;
        $cur   = is_array($seo[$pid] ?? null) ? $seo[$pid] : [];
        $needT = fourgeAiWeak($cur['title'] ?? '',       FOURGE_TITLE_MIN, FOURGE_TITLE_MAX);
        $needD = fourgeAiWeak($cur['description'] ?? '', FOURGE_DESC_MIN,  FOURGE_DESC_MAX);
        if (!$needT && !$needD) continue;
        $abs = PUBLIC_HTML . '/' . ltrim($file, '/');
        $html = is_file($abs) ? (string)@file_get_contents($abs) : '';
        if (trim($html) === '') { $skip((string)($rec['title'] ?? $pid), 'The page file is missing or empty'); continue; }
        // Body text only — the nav and footer are the same on every page and
        // would make every description read the same.
        $body = $html;
        if (preg_match('~<main\b[^>]*>([\s\S]*?)</main>~i', $html, $m)) $body = $m[1];
        $text = trim(preg_replace('~\s+~', ' ', strip_tags(preg_replace('~<(script|style)\b[\s\S]*?</\1>~i', ' ', $body))));
        if (mb_strlen($text) < 60) { $skip((string)($rec['title'] ?? $pid), 'Not enough text on the page to describe it honestly'); continue; }
        $out = fourgeAiText(
            'You write SEO metadata. Reply with ONLY compact JSON, no markdown, no commentary: '
            . '{"title":"a ' . FOURGE_TITLE_MIN . '-' . FOURGE_TITLE_MAX . ' character page title","description":"a '
            . FOURGE_DESC_MIN . '-' . FOURGE_DESC_MAX . ' character meta description ending in a call to action"}. '
            . 'Describe only what the page actually says. Never invent services, locations, credentials, prices or guarantees.',
            'Site: ' . ($siteName ?: 'this business') . "\nPage title: " . (string)($rec['title'] ?? '')
            . "\nPage content:\n" . mb_substr($text, 0, 1200), 300);
        if (is_array($out)) { $skip((string)($rec['title'] ?? $pid), $out['error']); continue; }
        $j = fourgeAiJson($out);
        if (!$j) { $skip((string)($rec['title'] ?? $pid), 'The model did not return usable JSON'); continue; }
        $wrote = [];
        if ($needT && trim((string)($j['title'] ?? '')) !== '') {
            $cur['title'] = mb_substr(trim(preg_replace('~[\r\n\t]+~', ' ', (string)$j['title'])), 0, 120);
            $wrote[] = 'title';
        }
        if ($needD && trim((string)($j['description'] ?? '')) !== '') {
            $cur['description'] = mb_substr(trim(preg_replace('~[\r\n\t]+~', ' ', (string)$j['description'])), 0, 320);
            $wrote[] = 'description';
        }
        if (!$wrote) { $skip((string)($rec['title'] ?? $pid), 'The model returned nothing for the missing field'); continue; }
        if (!$dry) {
            $seo[$pid] = $cur; $seoDirty = true;
            // Write straight to the live page too. Without this, the fix sits in
            // seo.json until a human happens to open this exact page in the editor
            // and save it — which, for an UNATTENDED daily run, could be never.
            // Reuses the same stamp the deploy-package importer already trusts for
            // exactly this: title, description, canonical, robots, OG, Twitter.
            cmsPkgBackup($file);
            $canon = cmsPkgPageUrl($baseUrl, $file);
            @file_put_contents($abs, cmsPkgStampSeo($html, $cur, $canon, !empty($rec['draft'])));
        }
        $rep['metas'][] = ['page' => (string)($rec['title'] ?? $pid), 'wrote' => implode(' + ', $wrote),
                           'was' => ($needT && ($cur['title'] ?? '') !== '' ? 'weak/missing' : 'missing')];
        $done++;
    }
    if ($seoDirty && !$dry) cmsPkgWriteJson('seo.json', $seo);

    // ── 2. images with no alt text ──────────────────────────────────────────
    $state = fourgeAiState();
    $altDone = is_array($state['alts'] ?? null) ? $state['alts'] : [];
    $done = 0;
    foreach ($pages as $pid => $rec) {
        if ($done >= $altCap) break;
        if (!is_array($rec)) continue;
        $file = (string)($rec['path'] ?? ($rec['file'] ?? ''));
        if ($file === '') continue;
        $abs = PUBLIC_HTML . '/' . ltrim($file, '/');
        if (!is_file($abs)) continue;
        $html = (string)@file_get_contents($abs);
        $sig = md5($html);
        if (($altDone[$pid] ?? '') === $sig) continue;          // unchanged since we last looked
        // An <img> with no alt attribute at all, or one that is empty/whitespace.
        // The value must START with a real character: writing it as [^"]*\S lets
        // \S match the CLOSING QUOTE, so alt="" reads as "already described" and
        // every empty alt on the site gets silently skipped.
        $rx = '~<img\b(?![^>]*\balt\s*=\s*"\s*[^"\s])(?![^>]*\balt\s*=\s*\x27\s*[^\x27\s])[^>]*>~i';
        if (!preg_match_all($rx, $html, $mm) || !$mm[0]) { $altDone[$pid] = $sig; continue; }
        $tags = array_slice($mm[0], 0, 12);
        $names = [];
        foreach ($tags as $t) { preg_match('~\bsrc\s*=\s*["\x27]([^"\x27]+)["\x27]~i', $t, $m); $names[] = basename($m[1] ?? 'image'); }
        $lines = []; foreach ($names as $i => $n) $lines[] = ($i + 1) . '. ' . $n;
        $out = fourgeAiText(
            'You write image alt text: max 12 words, describing what the image shows. No quotes, no "image of", '
            . 'no marketing copy. Reply with a numbered list only, one line per input line, in the same order.',
            'Site: ' . ($siteName ?: 'this business') . "\nPage: " . (string)($rec['title'] ?? '')
            . "\nImage filenames:\n" . implode("\n", $lines), 500);
        $alts = [];
        if (is_array($out)) $skip('alt text on "' . (string)($rec['title'] ?? $pid) . '"', $out['error'] . ' — used filenames instead');
        else $alts = fourgeAiNumbered($out);
        $i = 0; $filled = 0;
        $next = preg_replace_callback($rx, function ($m) use (&$i, $alts, $names, &$filled) {
            $alt = trim((string)($alts[$i] ?? ''));
            if ($alt === '') $alt = fourgeAiAltFromFile($names[$i] ?? '');
            $i++;
            if ($alt === '') return $m[0];
            $filled++;
            // Replace an empty alt="" if present, otherwise insert one.
            if (preg_match('~\balt\s*=\s*("\s*"|\x27\s*\x27)~i', $m[0])) {
                return preg_replace('~\balt\s*=\s*("\s*"|\x27\s*\x27)~i', 'alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"', $m[0], 1);
            }
            return preg_replace('~^<img\b~i', '<img alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"', $m[0], 1);
        }, $html);
        if ($next !== null && $next !== $html && $filled > 0) {
            if (!$dry) { cmsPkgBackup($file); @file_put_contents($abs, $next); $altDone[$pid] = md5($next); }
            $rep['alts'][] = ['page' => (string)($rec['title'] ?? $pid), 'images' => $filled];
            $done++;
        } else {
            $altDone[$pid] = $sig;
        }
    }

    // ── 3. internal links to the pages the campaign is targeting ────────────
    $linked = 0;
    if ($linkCap > 0 && $baseUrl !== '') {
        $targets = [];
        foreach ($pages as $pid => $rec) {
            if (!is_array($rec) || !empty($rec['draft'])) continue;
            $kw = trim((string)(($seo[$pid]['focusKeyword'] ?? '')));
            if (mb_strlen($kw) < 6) continue;                   // too short to link safely
            $file = (string)($rec['path'] ?? ($rec['file'] ?? ''));
            if ($file === '') continue;
            $targets[$pid] = ['kw' => $kw, 'url' => cmsPkgPageUrl($baseUrl, $file), 'title' => (string)($rec['title'] ?? $pid)];
        }
        foreach ($targets as $tid => $t) {
            if ($linked >= $linkCap) break;
            foreach ($pages as $did => $drec) {
                if ($linked >= $linkCap) break;
                if ($did === $tid || !is_array($drec) || !empty($drec['draft'])) continue;
                $dfile = (string)($drec['path'] ?? ($drec['file'] ?? ''));
                if ($dfile === '') continue;
                $dabs = PUBLIC_HTML . '/' . ltrim($dfile, '/');
                if (!is_file($dabs)) continue;
                $dhtml = (string)@file_get_contents($dabs);
                // Only inside <main> — a keyword in the shared nav or footer
                // would link every page to the same target.
                if (!preg_match('~(<main\b[^>]*>)([\s\S]*?)(</main>)~i', $dhtml, $mm)) continue;
                $newInner = fourgeAiLinkKeyword($mm[2], $t['kw'], $t['url']);
                if ($newInner === null) continue;
                $next = str_replace($mm[0], $mm[1] . $newInner . $mm[3], $dhtml);
                if (!$dry) { cmsPkgBackup($dfile); @file_put_contents($dabs, $next); }
                $rep['links'][] = ['from' => (string)($drec['title'] ?? $did), 'to' => $t['title'], 'keyword' => $t['kw']];
                $linked++;
                break;                                          // one inbound link per target per run
            }
        }
    } elseif ($linkCap > 0) {
        $skip('internal links', 'Set Design → Website URL first — a link needs the site\'s real address');
    }

    // ── 4. AEO: FAQ content for pages that have none at all ─────────────────
    // "None at all" means BOTH halves are empty: no hand-entered/previously-
    // generated aeoFAQs, AND the page's own headings don't already read as an
    // FAQ (seoBuildSchema harvests those for free — writing AI ones on top
    // would just replace better, more specific copy with generic AI copy).
    $faqCap = max(0, (int)($opts['faqCap'] ?? 4));
    $aeoDone = 0; $aeoSeoDirty = false;
    foreach ($pages as $pid => $rec) {
        if ($aeoDone >= $faqCap) break;
        if (!is_array($rec) || !empty($rec['draft'])) continue;
        $file = (string)($rec['path'] ?? ($rec['file'] ?? ''));
        if ($file === '') continue;
        $cur = is_array($seo[$pid] ?? null) ? $seo[$pid] : [];
        $existing = array_values(array_filter((array)($cur['aeoFAQs'] ?? []), function ($f) {
            return is_array($f) && trim((string)($f['question'] ?? ($f['q'] ?? ''))) !== ''
                && trim((string)($f['answer'] ?? ($f['a'] ?? ''))) !== '';
        }));
        if ($existing) continue;   // real content already there — never touched
        $abs = PUBLIC_HTML . '/' . ltrim($file, '/');
        $html = is_file($abs) ? (string)@file_get_contents($abs) : '';
        if (trim($html) === '') { $skip((string)($rec['title'] ?? $pid) . ' (FAQs)', 'The page file is missing or empty'); continue; }
        if (fourgeHasQaContent($html)) continue;   // the page's own headings already read as an FAQ
        $body = $html;
        if (preg_match('~<main\b[^>]*>([\s\S]*?)</main>~i', $html, $m)) $body = $m[1];
        $text = trim(preg_replace('~\s+~', ' ', strip_tags(preg_replace('~<(script|style)\b[\s\S]*?</\1>~i', ' ', $body))));
        if (mb_strlen($text) < 150) { $skip((string)($rec['title'] ?? $pid) . ' (FAQs)', 'Not enough content to write honest FAQs from'); continue; }
        $out = fourgeAiText(
            'You write FAQ content for a real business webpage, useful for both search results and AI answer engines. '
            . 'Reply with ONLY compact JSON, no markdown, no commentary: {"faqs":[{"question":"...","answer":"..."}]}. '
            . 'Write 3 to 4 questions a real customer would ask about THIS page specifically, each with a 2-3 sentence '
            . 'answer grounded ONLY in the page content given. Never invent services, locations, prices, credentials, '
            . 'guarantees, or policies that are not stated. If the page cannot honestly support 3 questions, return fewer.',
            'Site: ' . ($siteName ?: 'this business') . "\nPage title: " . (string)($rec['title'] ?? '')
            . "\nPage content:\n" . mb_substr($text, 0, 1600), 700);
        if (is_array($out)) { $skip((string)($rec['title'] ?? $pid) . ' (FAQs)', $out['error']); continue; }
        $j = fourgeAiJson($out);
        $raw = (is_array($j) && is_array($j['faqs'] ?? null)) ? $j['faqs'] : [];
        $faqs = [];
        foreach ($raw as $f) {
            $q = trim((string)(is_array($f) ? ($f['question'] ?? '') : ''));
            $a = trim((string)(is_array($f) ? ($f['answer'] ?? '') : ''));
            if ($q === '' || $a === '' || mb_strlen($a) < 30) continue;
            $faqs[] = ['question' => mb_substr($q, 0, 200), 'answer' => mb_substr($a, 0, 500)];
            if (count($faqs) >= 5) break;
        }
        if (count($faqs) < 2) { $skip((string)($rec['title'] ?? $pid) . ' (FAQs)', 'The model could not produce enough genuine questions for this page'); continue; }
        if (!$dry) {
            $cur['aeoFAQs'] = $faqs;
            $seo[$pid] = $cur; $aeoSeoDirty = true;
            // Same reasoning as the metadata phase: put it on the live page NOW,
            // marked separately so a later human save (which rebuilds the whole
            // schema graph FROM this same aeoFAQs data) cleanly replaces it
            // instead of ending up with two FAQPage blocks.
            cmsPkgBackup($file);
            @file_put_contents($abs, fourgeAeoStampFaq($html, $faqs));
        }
        $rep['aeo'][] = ['page' => (string)($rec['title'] ?? $pid), 'faqs' => count($faqs)];
        $aeoDone++;
    }
    if ($aeoSeoDirty && !$dry) cmsPkgWriteJson('seo.json', $seo);

    if (!$dry) {
        $state['alts'] = $altDone;
        $state['last'] = $rep;
        $state['last_run'] = time();
        fourgeAiSaveState($state);
    }
    $rep['totals'] = ['metas' => count($rep['metas']), 'alts' => count($rep['alts']),
                      'links' => count($rep['links']), 'aeo' => count($rep['aeo']), 'skipped' => count($rep['skipped'])];
    return $rep;
}
function fourgeApiAiAutofix($me, $body) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    echo json_encode(fourgeAiAutofix([
        'dry'     => !empty($body['dry']),
        'metaCap' => isset($body['metaCap']) ? (int)$body['metaCap'] : 10,
        'altCap'  => isset($body['altCap'])  ? (int)$body['altCap']  : 8,
        'linkCap' => isset($body['linkCap']) ? (int)$body['linkCap'] : 10,
        'faqCap'  => isset($body['faqCap'])  ? (int)$body['faqCap']  : 4,
    ]));
}
// Whether the daily job should run at all. Distinguishes "an operator
// explicitly turned this off" (respected) from "nobody has ever touched this
// setting" (now ON — that silent default was the whole complaint: a fix that
// only runs when someone remembers to click something keeps getting missed).
// 'weekly' is the old key, kept readable so a site that had already opted in
// (or explicitly out) under the old name is not silently reset.
function fourgeAiAutoOn($state) {
    if (array_key_exists('auto', $state))   return !empty($state['auto']);
    if (array_key_exists('weekly', $state)) return !empty($state['weekly']);
    return true;
}
// Daily upkeep, so pages created since the last run get covered without anyone
// pressing a button. Fourge has no cron, so this rides two things: the login
// self-heal (best-effort, whenever anyone happens to sign in) AND the SEO
// platform's own scheduled ping (fourgeApiSeoPkgTick) — which fires on a real
// clock regardless of whether anyone logs into this particular site. Either
// way it is time-boxed and never blocks whatever it is riding inside.
function fourgeAiDailyTick() {
    $state = fourgeAiState();
    if (!fourgeAiAutoOn($state)) return false;
    $last = (int)($state['last_run'] ?? 0);
    if ($last && (time() - $last) < 86400) return false;
    if (fourgeAiKey() === '') return false;
    try { fourgeAiAutofix(['metaCap' => 5, 'altCap' => 4, 'linkCap' => 5, 'faqCap' => 3]); return true; }
    catch (Throwable $e) { return false; }
}

function fourgeApiInstallCleanUrls($me) {
    if (!$me) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); return; }
    if (!fourgeWriteCleanUrlHtaccess()) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write .htaccess (check that the site root is writable by PHP)']);
        return;
    }
    // Best-effort riders on the same login self-heal: open the public posts feed
    // to cross-site reads, install the SEO-platform endpoints, and publish any
    // scheduled deploy-package content that has come due. None of these can
    // fail the clean-URL install.
    $cors = false; $seoApi = false; $idx = false; $due = [];
    try { $cors   = fourgeWritePostsCorsHtaccess(); } catch (Throwable $e) { $cors = false; }
    try { $seoApi = fourgeWriteSeoApiHtaccess();    } catch (Throwable $e) { $seoApi = false; }
    try { $idx    = fourgeWriteIndexingHtaccess(); } catch (Throwable $e) { $idx = false; }
    // Make sure this site's secret files cannot be downloaded. Sites deployed
    // before admin/.htaccess existed have had no protection at all.
    $sec = false;
    try { $sec    = fourgeWriteAdminHtaccess();    } catch (Throwable $e) { $sec = false; }
    // Same reasoning as admin/.htaccess above: a site that had form entries or
    // uploads before these guards existed has had no protection at all.
    $entriesGuard = false; $uploadsGuard = false;
    try { $entriesGuard = fourgeWriteEntriesHtaccess(); } catch (Throwable $e) { $entriesGuard = false; }
    try { $uploadsGuard = fourgeWriteUploadsHtaccess(); } catch (Throwable $e) { $uploadsGuard = false; }
    // The four safe security headers, and an llms.txt if the site has none.
    $hdr = false; $llms = false;
    try { $hdr    = fourgeWriteDefaultHeaders();   } catch (Throwable $e) { $hdr = false; }
    try { $llms   = fourgeEnsureLlms();            } catch (Throwable $e) { $llms = false; }
    // Daily AI upkeep — on by default; see fourgeAiAutoOn().
    try { fourgeAiDailyTick(); } catch (Throwable $e) {}
    try { $due    = cmsPkgTick(false);              } catch (Throwable $e) { $due = []; }
    // Fleet Dashboard: silent no-op on every site that has not configured one.
    $fleet = false;
    try { $fleet  = fourgeFleetReport();            } catch (Throwable $e) { $fleet = false; }
    // Blog Sync: install the tick endpoint's rewrite rule, then opportunistically
    // check the source blog if it's been ≥10 minutes since the last check.
    // Silent no-op on every site that has not enabled it.
    $blogSyncApi = false; $blogSync = null;
    try { $blogSyncApi = fourgeWriteBlogSyncApiHtaccess(); } catch (Throwable $e) { $blogSyncApi = false; }
    try { $blogSync     = fourgeBlogSyncTickIfDue();       } catch (Throwable $e) { $blogSync = null; }
    echo json_encode(['ok' => true, 'postsCors' => $cors, 'seoApi' => $seoApi, 'indexing' => $idx,
        'secretGuard' => $sec, 'headers' => $hdr, 'llms' => $llms, 'published' => count($due), 'fleet' => $fleet,
        'blogSyncApi' => $blogSyncApi, 'blogSync' => $blogSync,
        'entriesGuard' => $entriesGuard, 'uploadsGuard' => $uploadsGuard]);
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
// 44i SEO PLATFORM — DEPLOY PACKAGE IMPORTER
// Consumes the same "44i-deploy-package" v1 file the WordPress connector eats,
// so one exported file deploys to either platform. The apply logic lives HERE
// (server side) and the admin screen calls it with mode=preview|apply — so the
// UI and the machine endpoint cannot drift apart. Nothing from the file is ever
// executed: HTML is sanitized, redirect targets are URL-validated, header
// name/value pairs are charset-restricted, and .htaccess writes are confined to
// marker-delimited managed blocks.
// ─────────────────────────────────────────────────────────────────────────────

function cmsPkgDataPath($name) { return PUBLIC_HTML . '/data/' . $name; }
function cmsPkgReadJson($name, $fallback) {
    $f = cmsPkgDataPath($name);
    if (!is_file($f)) return $fallback;
    $j = json_decode((string)@file_get_contents($f), true);
    return ($j === null) ? $fallback : $j;
}
function cmsPkgWriteJson($name, $data) {
    $dir = PUBLIC_HTML . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return @file_put_contents(cmsPkgDataPath($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}
// One-generation backup of any existing file we are about to overwrite.
function cmsPkgBackup($relPath) {
    $src = PUBLIC_HTML . '/' . ltrim($relPath, '/');
    if (!is_file($src)) return;
    $dir = PUBLIC_HTML . '/data/bak';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @copy($src, $dir . '/' . str_replace(['/', '\\'], '__', ltrim($relPath, '/')));
}
// ── PUBLIC URL PATHS, PHP SIDE ──────────────────────────────────────────────
// Two jobs that were previously (wrongly) one function:
//
//   cmsPkgUrlPath()  — the path that actually appears in a public URL. Must be
//                      byte-identical to fourgePagePath() in admin/index.html,
//                      because both write canonical tags for the same page.
//                      CASE-PRESERVING: on a case-sensitive filesystem a page
//                      file named About.html is served at /About, and a canonical
//                      of /about is a 404 pointing search engines at nothing.
//   cmsPkgNormPath() — a comparison KEY for matching a deploy-package target
//                      against pages.json. Case- and trailing-slash-folded on
//                      purpose, so /Services/ and services/index.html match.
//
// PHP cannot call the JS helper, so the two are locked together by a shared
// vector table asserted in both test suites (indexing.mjs / indexing_test.php).
//
// Shape: extensionless, no leading slash, home = '', a directory index folds to
// its directory ('services/index.html' -> 'services/', which is the URL Apache
// actually serves for that file via DirectoryIndex).
function cmsPkgUrlPath($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    // A host is only stripped when the input was genuinely absolute. Sniffing
    // for "a dot before the first slash" would eat bare page filenames — a
    // pages.json entry of "about.html" would normalize to '' and collide with
    // the homepage, so nothing would resolve.
    $hadHost = false;
    if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $u)) { $u = preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $u); $hadHost = true; }
    elseif (strpos($u, '//') === 0) { $u = substr($u, 2); $hadHost = true; }
    if ($hadHost) { $slash = strpos($u, '/'); $u = ($slash === false) ? '' : substr($u, $slash); }
    $u = preg_replace('~[?#].*$~', '', (string)$u);
    $u = ltrim((string)$u, '/');
    // The index match needs a start-or-slash boundary, or "myindex.html" folds
    // to "my" — a canonical pointing at a URL that does not exist.
    $u = preg_replace('~(^|/)index\.html?$~i', '$1', $u);   // dir index -> the dir
    return preg_replace('~\.html?$~i', '', (string)$u);
}
// Absolute public URL for a page file — the PHP mirror of fourgePageUrl().
// '' when no canonical origin is known; the home page keeps its single slash.
function cmsPkgPageUrl($base, $file) {
    $base = rtrim((string)$base, '/');
    if ($base === '') return '';
    $p = cmsPkgUrlPath($file);
    return $p !== '' ? ($base . '/' . $p) : ($base . '/');
}
// Comparable path key: scheme/host/query/extension/case/trailing-slash agnostic.
// The homepage normalizes to '' from every spelling. Note that services.html and
// services/index.html deliberately fold to the same key — they compete for the
// same public URL, so treating them as one target is the correct behaviour.
function cmsPkgNormPath($u) {
    return rtrim(strtolower(cmsPkgUrlPath($u)), '/');
}
function cmsPkgSlug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim((string)$s, '-');
}
// Strip every tag Fourge manages (its own data-fourge-seo nodes plus the
// standard set it takes over) so a re-stamp is idempotent — mirrors
// seoInjectIntoHtml() in the admin.
function cmsPkgStripManagedSeo($html) {
    $html = preg_replace('~[ \t]*<script\b[^>]*data-fourge-seo[^>]*>[\s\S]*?</script>[ \t]*\r?\n?~i', '', (string)$html);
    $html = preg_replace('~[ \t]*<(?:meta|link)\b[^>]*data-fourge-seo[^>]*>[ \t]*\r?\n?~i', '', (string)$html);
    $html = preg_replace('~[ \t]*<meta\b[^>]*name\s*=\s*["\'](?:description|robots)["\'][^>]*>[ \t]*\r?\n?~i', '', (string)$html);
    $html = preg_replace('~[ \t]*<link\b[^>]*rel\s*=\s*["\']canonical["\'][^>]*>[ \t]*\r?\n?~i', '', (string)$html);
    $html = preg_replace('~[ \t]*<meta\b[^>]*property\s*=\s*["\']og:[^"\']*["\'][^>]*>[ \t]*\r?\n?~i', '', (string)$html);
    $html = preg_replace('~[ \t]*<meta\b[^>]*name\s*=\s*["\']twitter:[^"\']*["\'][^>]*>[ \t]*\r?\n?~i', '', (string)$html);
    return (string)$html;
}
// og_tags blocks arrive as raw HTML and are reduced to <meta> tags ONLY.
// http-equiv / charset / anything that isn't a name|property+content pair is
// dropped, values are stripped of CR/LF.
function cmsPkgSanitizeMetaTags($html) {
    $out = [];
    if (!preg_match_all('~<meta\b[^>]*>~i', (string)$html, $m)) return $out;
    foreach ($m[0] as $tag) {
        if (preg_match('~http-equiv|charset~i', $tag)) continue;
        if (preg_match('~\b(property|name)\s*=\s*"([^"]+)"~i', $tag, $k)) { $attr = strtolower($k[1]); $key = trim($k[2]); }
        elseif (preg_match("~\b(property|name)\s*=\s*'([^']+)'~i", $tag, $k)) { $attr = strtolower($k[1]); $key = trim($k[2]); }
        else continue;
        if (preg_match('~\bcontent\s*=\s*"([^"]*)"~i', $tag, $c)) $val = $c[1];
        elseif (preg_match("~\bcontent\s*=\s*'([^']*)'~i", $tag, $c)) $val = $c[1];
        else continue;
        if (!preg_match('~^[A-Za-z0-9:_.\-]{1,64}$~', $key)) continue;
        $val = trim(str_replace(["\r", "\n"], ' ', html_entity_decode($val, ENT_QUOTES, 'UTF-8')));
        if ($val === '') continue;
        $out[] = ['attr' => $attr, 'key' => $key, 'content' => $val];
    }
    return $out;
}
// Content HTML: drop executable/structural elements outright, kill inline
// handlers and script-ish URLs, then allow-list the remaining tags.
function cmsPkgSanitizeHtml($html) {
    $html = (string)$html;
    $html = preg_replace('~<!--[\s\S]*?-->~', '', $html);
    $kill = 'script|style|iframe|object|embed|applet|form|input|button|select|textarea|link|meta|base|svg|math';
    $html = preg_replace('~<(' . $kill . ')\b[^>]*>[\s\S]*?</\1\s*>~i', '', $html);
    $html = preg_replace('~<(' . $kill . ')\b[^>]*/?>~i', '', $html);
    $html = preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);
    $html = preg_replace('~\s(?:href|src|srcset|action|formaction|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:[^"\']*\1~i', '', $html);
    $allow = ['p','br','strong','b','em','i','u','s','ul','ol','li','h1','h2','h3','h4','h5','h6',
              'blockquote','a','img','figure','figcaption','table','thead','tbody','tfoot','tr','th','td',
              'hr','span','div','section','article','small','sup','sub','code','pre','dl','dt','dd'];
    $html = strip_tags($html, '<' . implode('><', $allow) . '>');
    return trim((string)$html);
}
// Build the managed <head> block for a page from its seo.json record and stamp
// it in. Same marker + tag shape the admin uses, so the two converge instead of
// fighting: whichever runs last produces the same output from the same record.
function cmsPkgStampSeo($html, $rec, $canonical, $isDraft = false) {
    $html = cmsPkgStripManagedSeo($html);
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $L = [];
    $title = trim((string)($rec['title'] ?? ''));
    if ($title !== '') {
        if (preg_match('~<title\b[^>]*>~i', $html)) $html = preg_replace('~<title\b[^>]*>[\s\S]*?</title>~i', '<title>' . $esc($title) . '</title>', $html, 1);
        else $html = preg_replace('~<head\b[^>]*>~i', '$0' . "\n" . '<title>' . $esc($title) . '</title>', $html, 1);
    }
    $desc = trim((string)($rec['description'] ?? ''));
    if ($desc !== '') $L[] = '<meta name="description" content="' . $esc($desc) . '" data-fourge-seo>';
    $canon = trim((string)($rec['canonical'] ?? '')) ?: (string)$canonical;
    if ($canon !== '') $L[] = '<link rel="canonical" href="' . $esc($canon) . '" data-fourge-seo>';
    $noindex = !empty($rec['noindex']) || $isDraft;
    $L[] = '<meta name="robots" content="' . ($noindex ? 'noindex' : 'index') . ',' . (!empty($rec['nofollow']) ? 'nofollow' : 'follow') . '" data-fourge-seo>';
    $ogT = trim((string)($rec['ogTitle'] ?? $title));
    $ogD = trim((string)($rec['ogDescription'] ?? $desc));
    $ogI = trim((string)($rec['ogImage'] ?? ''));
    if ($ogT !== '') $L[] = '<meta property="og:title" content="' . $esc($ogT) . '" data-fourge-seo>';
    if ($ogD !== '') $L[] = '<meta property="og:description" content="' . $esc($ogD) . '" data-fourge-seo>';
    if ($ogI !== '') $L[] = '<meta property="og:image" content="' . $esc($ogI) . '" data-fourge-seo>';
    if ($canon !== '') $L[] = '<meta property="og:url" content="' . $esc($canon) . '" data-fourge-seo>';
    $L[] = '<meta name="twitter:card" content="' . $esc($rec['twitterCard'] ?? 'summary_large_image') . '" data-fourge-seo>';
    if ($ogT !== '') $L[] = '<meta name="twitter:title" content="' . $esc($ogT) . '" data-fourge-seo>';
    if ($ogD !== '') $L[] = '<meta name="twitter:description" content="' . $esc($ogD) . '" data-fourge-seo>';
    if ($ogI !== '') $L[] = '<meta name="twitter:image" content="' . $esc($ogI) . '" data-fourge-seo>';
    foreach ((array)($rec['extraMeta'] ?? []) as $mt) {
        $a = ($mt['attr'] ?? 'name') === 'property' ? 'property' : 'name';
        $k = (string)($mt['key'] ?? ''); $v = (string)($mt['content'] ?? '');
        if ($k === '' || $v === '') continue;
        if (preg_match('~^(?:og:(?:title|description|image|url)|twitter:(?:card|title|description|image)|description|robots)$~i', $k)) continue; // already managed
        $L[] = '<meta ' . $a . '="' . $esc($k) . '" content="' . $esc($v) . '" data-fourge-seo>';
    }
    foreach ((array)($rec['extraJsonld'] ?? []) as $blk) {
        $json = is_string($blk) ? $blk : json_encode($blk, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') continue;
        $json = str_ireplace('</script', '<\\/script', $json);   // can never break out of the block
        $L[] = '<script type="application/ld+json" data-fourge-seo>' . $json . '</script>';
    }
    $block = implode("\n", $L);
    if ($block === '') return $html;
    if (preg_match('~</head>~i', $html)) return preg_replace('~</head>~i', $block . "\n</head>", $html, 1);
    if (preg_match('~<head\b[^>]*>~i', $html)) return preg_replace('~<head\b[^>]*>~i', '$0' . "\n" . $block, $html, 1);
    return $block . "\n" . $html;
}
// Generic marker-splice for a managed .htaccess block.
function cmsPkgSpliceHtaccess($begin, $end, $rules) {
    $htPath   = PUBLIC_HTML . '/.htaccess';
    $existing = is_file($htPath) ? file_get_contents($htPath) : '';
    $block = $begin . "\n" . $rules . "\n" . $end;
    $s = strpos($existing, $begin);
    $e = strpos($existing, $end);
    if ($s !== false && $e !== false && $e >= $s) {
        $existing = substr($existing, 0, $s) . $block . substr($existing, $e + strlen($end));
    } else {
        // Redirects and headers must precede the clean-URL rewrite block.
        $cu = strpos($existing, '# BEGIN Fourge Clean URLs');
        if ($cu !== false) $existing = substr($existing, 0, $cu) . $block . "\n\n" . substr($existing, $cu);
        else $existing = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block . "\n";
    }
    return file_put_contents($htPath, $existing) !== false;
}
// A redirect rule is only accepted when both sides are safe to place in a
// server config: constrained source path charset, absolute-or-rooted target,
// no whitespace/newlines anywhere.
function cmsPkgValidRedirect($from, $to) {
    $from = '/' . ltrim(preg_replace('~[?#].*$~', '', (string)$from), '/');
    $p = trim($from, '/');
    if ($p === '' || strlen($p) > 300) return null;
    if (!preg_match('~^[A-Za-z0-9/_%.\-]+$~', $p)) return null;
    $to = trim((string)$to);
    if ($to === '' || strlen($to) > 600) return null;
    if (preg_match('~[\s"\'\\\\]~', $to)) return null;
    if (!preg_match('~^(?:https?://[A-Za-z0-9.\-]+(?::\d+)?(?:/[^\s]*)?|/[^\s]*)$~', $to)) return null;
    return ['from' => '/' . $p, 'to' => $to];
}
function cmsPkgWriteRedirects($rules) {
    $lines = ['<IfModule mod_rewrite.c>', '  RewriteEngine On'];
    foreach ($rules as $r) {
        $p = trim((string)$r['from'], '/');
        $code = (int)($r['code'] ?? 301);
        if ($code !== 301 && $code !== 302 && $code !== 307 && $code !== 308) $code = 301;
        $pat = str_replace(['.', ' '], ['\\.', '\\ '], $p);
        $lines[] = '  RewriteRule ^' . $pat . '/?$ ' . $r['to'] . ' [R=' . $code . ',L]';
    }
    $lines[] = '</IfModule>';
    return cmsPkgSpliceHtaccess('# BEGIN Fourge Package Redirects', '# END Fourge Package Redirects', implode("\n", $lines));
}
// Parse Apache `Header always set X "Y"` and nginx `add_header X "Y" always;`.
function cmsPkgParseHeaders($raw) {
    $pairs = [];
    foreach (preg_split('~\r?\n~', (string)$raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $m = null;
        if (preg_match('~^Header\s+(?:always\s+)?set\s+([A-Za-z0-9\-]+)\s+"([^"]*)"~i', $line, $m)) {}
        elseif (preg_match("~^Header\s+(?:always\s+)?set\s+([A-Za-z0-9\-]+)\s+'([^']*)'~i", $line, $m)) {}
        elseif (preg_match('~^add_header\s+([A-Za-z0-9\-]+)\s+"([^"]*)"~i', $line, $m)) {}
        elseif (preg_match("~^add_header\s+([A-Za-z0-9\-]+)\s+'([^']*)'~i", $line, $m)) {}
        else continue;
        $name = $m[1]; $val = str_replace(["\r", "\n"], '', $m[2]);
        if (!preg_match('~^[A-Za-z0-9\-]{1,64}$~', $name)) continue;
        // A quote or backslash means the source line was quoted in a way this
        // parser can't round-trip safely into .htaccess — drop it rather than
        // emit a broken directive.
        if (strpos($val, '"') !== false || strpos($val, '\\') !== false || strlen($val) > 1500) continue;
        // Response-shaping headers we must never let a data file set.
        if (preg_match('~^(?:set-cookie|location|status|content-length|transfer-encoding)$~i', $name)) continue;
        $pairs[$name] = $val;
    }
    return $pairs;
}
function cmsPkgWriteHeaders($pairs) {
    $lines = ['<IfModule mod_headers.c>'];
    foreach ($pairs as $n => $v) $lines[] = '  Header always set ' . $n . ' "' . $v . '"';
    $lines[] = '</IfModule>';
    return cmsPkgSpliceHtaccess('# BEGIN Fourge Package Headers', '# END Fourge Package Headers', implode("\n", $lines));
}
// ── APPROVED BUSINESS FACTS (package `business`) ────────────────────────────
// The platform's console holds the client-approved NAP, hours, socials, service
// area and (when it has real GBP data) a rating. Importing them lets Fourge emit
// a complete LocalBusiness/E-E-A-T graph on every page instead of the thin
// Organization node it can build from Site Info alone.
//
// They are written into data/site.json TWICE on purpose: as a `business` block
// (the delivered record, kept whole) and mapped onto the top-level name/phone/
// email/address/social fields the existing schema builder already reads. One
// import therefore improves the schema on every page without any other wiring,
// and without a second source of truth for the fields Site Info already owns.
//
// Nothing is invented. A field the package did not deliver is left exactly as it
// was — an import must never blank out something a human typed into Site Info.
function cmsPkgBusiness($biz, &$pages_unused = null) {
    $txt = function ($v) { return trim(preg_replace('~[\r\n\t]+~', ' ', (string)$v)); };
    $out = [];
    foreach (['name','url','type','categories','description','phone','email','street','city','state','zip','hours','gbp_url'] as $k) {
        $v = $txt($biz[$k] ?? '');
        if ($v !== '') $out[$k] = mb_substr($v, 0, 500);
    }
    $same = [];
    foreach ((array)($biz['sameas'] ?? []) as $u) {
        $u = $txt($u);
        if ($u !== '' && preg_match('~^https?://~i', $u)) $same[] = mb_substr($u, 0, 300);
    }
    if ($same) $out['sameas'] = array_values(array_unique($same));
    $sa = (array)($biz['service_area'] ?? []);
    $area = [];
    if ($txt($sa['primary'] ?? '') !== '') $area['primary'] = $txt($sa['primary']);
    $sec = [];
    foreach ((array)($sa['secondary'] ?? []) as $t) { $t = $txt($t); if ($t !== '') $sec[] = mb_substr($t, 0, 120); }
    if ($sec) $area['secondary'] = array_values(array_unique($sec));
    if ($area) $out['service_area'] = $area;
    $svc = [];
    foreach ((array)($biz['services'] ?? []) as $t) { $t = $txt($t); if ($t !== '') $svc[] = mb_substr($t, 0, 120); }
    if ($svc) $out['services'] = array_values(array_unique($svc));
    // A rating is only ever carried through when BOTH halves are real numbers in
    // range. An invented or half-populated AggregateRating is a Google structured-
    // data violation and an FTC problem, so a partial one is dropped entirely.
    $rv = $txt($biz['rating_value'] ?? '');
    $rc = $txt($biz['rating_count'] ?? '');
    if ($rv !== '' && $rc !== '' && is_numeric($rv) && is_numeric($rc)
        && (float)$rv > 0 && (float)$rv <= 5 && (int)$rc > 0) {
        $out['rating_value'] = (string)round((float)$rv, 1);
        $out['rating_count'] = (string)(int)$rc;
    }
    return $out;
}
// Merge the delivered facts onto the fields Site Info already owns, filling
// blanks only — a human's typed value always wins over the package.
function cmsPkgBusinessToSite(array $b, array $site) {
    $fill = function (&$target, $key, $val) {
        if ($val === '' || $val === null) return false;
        if (trim((string)($target[$key] ?? '')) !== '') return false;
        $target[$key] = $val; return true;
    };
    $filled = [];
    if ($fill($site, 'name',  $b['name'] ?? ''))  $filled[] = 'name';
    if ($fill($site, 'phone', $b['phone'] ?? '')) $filled[] = 'phone';
    if ($fill($site, 'email', $b['email'] ?? '')) $filled[] = 'email';
    $addr = is_array($site['address'] ?? null) ? $site['address'] : [];
    foreach (['street' => 'street', 'city' => 'city', 'state' => 'state', 'zip' => 'zip'] as $src => $dst) {
        if ($fill($addr, $dst, $b[$src] ?? '')) $filled[] = 'address.' . $dst;
    }
    if ($addr) $site['address'] = $addr;
    // Socials are a map in Site Info; add any delivered profile that is not
    // already listed, keyed by its network so the UI can label it.
    $social = is_array($site['social'] ?? null) ? $site['social'] : [];
    $known = array_map('strtolower', array_map('strval', array_values($social)));
    foreach ((array)($b['sameas'] ?? []) as $u) {
        if (in_array(strtolower($u), $known, true)) continue;
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        $net = 'website';
        foreach (['facebook' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin',
                  'youtube' => 'youtube', 'twitter' => 'twitter', 'x.com' => 'twitter',
                  'tiktok' => 'tiktok', 'yelp' => 'yelp', 'pinterest' => 'pinterest'] as $needle => $name) {
            if (strpos($host, $needle) !== false) { $net = $name; break; }
        }
        if (trim((string)($social[$net] ?? '')) === '') { $social[$net] = $u; $known[] = strtolower($u); $filled[] = 'social.' . $net; }
    }
    if ($social) $site['social'] = $social;
    return [$site, $filled];
}
// Clone the site's own homepage shell for a new page so imported content wears
// the site's header/footer/design (same donor approach as post→page).
function cmsPkgPageFromShell($title, $bodyHtml) {
    $esc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $inner = "\n" . '<section style="max-width:880px;margin:0 auto;padding:48px 24px">' . "\n"
           . '<h1>' . $esc . '</h1>' . "\n" . $bodyHtml . "\n" . '</section>' . "\n";
    $home = PUBLIC_HTML . '/index.html';
    $shell = is_file($home) ? (string)file_get_contents($home) : '';
    if ($shell !== '' && preg_match('~<main\b[^>]*>[\s\S]*?</main>~i', $shell)) {
        $out = preg_replace_callback('~(<main\b[^>]*>)([\s\S]*?)(</main>)~i',
            function ($m) use ($inner) { return $m[1] . $inner . $m[3]; }, $shell, 1);
        $out = preg_replace_callback('~<title\b[^>]*>[\s\S]*?</title>~i',
            function ($m) use ($esc) { return '<title>' . $esc . '</title>'; }, (string)$out, 1);
        $out = cmsPkgStripManagedSeo((string)$out);
        // Donor's page-scoped custom CSS/JS and its form markup don't belong here.
        $out = preg_replace('~[ \t]*<(style|script)\b[^>]*data-fourge-page[^>]*>[\s\S]*?</\1>[ \t]*\r?\n?~i', '', (string)$out);
        $out = preg_replace('~[ \t]*<link\b[^>]*data-fourge-page[^>]*>[ \t]*\r?\n?~i', '', (string)$out);
        return (string)$out;
    }
    return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n"
         . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
         . "<title>" . $esc . "</title>\n<link rel=\"stylesheet\" href=\"settings.css\">\n</head>\n"
         . "<body>\n<main>" . $inner . "</main>\n</body>\n</html>\n";
}
// Publish any scheduled deploy-package content whose time has arrived. Posts
// also self-publish in the public runtime (it treats publishAt<=now as live);
// this is what flips the stored record and handles page-type items.
function cmsPkgTick($dry = false) {
    $now = time(); $done = [];
    $posts = cmsPkgReadJson('posts.json', []);
    $postsDirty = false;
    if (is_array($posts)) {
        foreach ($posts as $i => $p) {
            if (!is_array($p) || !empty($p['published']) || empty($p['publishAt'])) continue;
            $t = strtotime((string)$p['publishAt']);
            if ($t === false || $t > $now) continue;
            $posts[$i]['published'] = true; unset($posts[$i]['publishAt']);
            $postsDirty = true;
            $done[] = ['type' => 'post', 'title' => (string)($p['title'] ?? '')];
        }
    }
    $pages = cmsPkgReadJson('pages.json', []);
    $pagesDirty = false;
    if (is_array($pages)) {
        foreach ($pages as $pid => $rec) {
            if (!is_array($rec) || empty($rec['publishAt'])) continue;
            $t = strtotime((string)$rec['publishAt']);
            if ($t === false || $t > $now) continue;
            $pages[$pid]['draft'] = false; unset($pages[$pid]['publishAt']);
            $pagesDirty = true;
            $done[] = ['type' => 'page', 'title' => (string)($rec['title'] ?? $pid)];
        }
    }
    if (!$dry) {
        if ($postsDirty) cmsPkgWriteJson('posts.json', $posts);
        if ($pagesDirty) cmsPkgWriteJson('pages.json', $pages);
    }
    return $done;
}
// Session (admin ≥2) OR constant-time Bearer match against the stored key.
function cmsPkgAuthorized($me, $body) {
    if ($me && fourgeLevel($me) >= 2) return true;
    $tok = '';
    $hdr = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($hdr !== '' && preg_match('~^Bearer\s+(.+)$~i', $hdr, $m)) $tok = trim($m[1]);
    if ($tok === '') $tok = trim((string)($_SERVER['HTTP_X_SEO_PKG_TOKEN'] ?? ''));   // hosts that strip Authorization
    if ($tok === '') $tok = trim((string)($body['pkg_token'] ?? ''));
    if ($tok === '') return false;
    $stored = '';
    try { $stored = (string)fourgeGetSecret(fourgeDb(), 'seo_pkg_token'); } catch (Throwable $e) { $stored = ''; }
    if ($stored === '') return false;
    return hash_equals($stored, $tok);
}

function cmsPkgApply($pkg, $dry) {
    $applied = []; $skipped = []; $manual = [];
    $add  = function ($type, $item, $note = '') use (&$applied) { $applied[] = ['type' => $type, 'item' => $item] + ($note !== '' ? ['note' => $note] : []); };
    $skip = function ($type, $item, $reason) use (&$skipped) { $skipped[] = ['type' => $type, 'item' => $item, 'reason' => $reason]; };
    $man  = function ($type, $item, $action = '') use (&$manual) { $manual[] = ['type' => $type, 'item' => $item] + ($action !== '' ? ['action' => $action] : []); };

    $site  = cmsPkgReadJson('site.json', []);
    $pages = cmsPkgReadJson('pages.json', []);
    $seo   = cmsPkgReadJson('seo.json', []);
    if (!is_array($pages)) $pages = [];
    if (!is_array($seo))   $seo = [];

    // Site sanity check (warn only — never refuse to import).
    $declared = trim((string)($pkg['site'] ?? ''));
    if ($declared !== '') {
        $dHost = strtolower(preg_replace('~^www\.~i', '', (string)parse_url($declared, PHP_URL_HOST)));
        $cHost = strtolower(preg_replace('~^www\.~i', '', (string)parse_url((string)($site['website'] ?? ''), PHP_URL_HOST)));
        if ($dHost !== '' && $cHost !== '' && $dHost !== $cHost) {
            $man('site_mismatch', $declared, 'This package was generated for ' . $dHost . ' but this site is configured as ' . $cHost . '. Confirm you are importing into the right site.');
        }
    }
    $baseUrl = rtrim((string)($site['website'] ?? ''), '/');
    if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;

    // target URL → page id
    $byPath = [];
    foreach ($pages as $pid => $rec) {
        if (!is_array($rec)) continue;
        $byPath[cmsPkgNormPath((string)($rec['path'] ?? $rec['file'] ?? ''))] = $pid;
    }
    $resolve = function ($target) use ($byPath) {
        $k = cmsPkgNormPath($target);
        return $byPath[$k] ?? null;
    };
    $touched = [];   // pid => true (pages needing a re-stamp)

    // 1 ── seo_meta
    foreach ((array)($pkg['seo_meta'] ?? []) as $row) {
        $t = (string)($row['target'] ?? '');
        $pid = $resolve($t);
        if (!$pid) { $skip('seo_meta', $t, 'No page on this site matches that URL path'); continue; }
        if (!isset($seo[$pid]) || !is_array($seo[$pid])) $seo[$pid] = [];
        $fields = [];
        foreach (['seo_title' => 'title', 'seo_description' => 'description', 'canonical' => 'canonical'] as $src => $dst) {
            $v = trim((string)($row[$src] ?? ''));
            if ($v !== '') { $seo[$pid][$dst] = $v; $fields[] = $dst; }
        }
        if (!$fields) { $skip('seo_meta', $t, 'No title, description, or canonical value in the entry'); continue; }
        $touched[$pid] = true;
        $add('seo_meta', $t, implode(' + ', $fields));
    }

    // 2 ── schema (JSON-LD). Replace semantics per target: the package owns the
    // imported set, so re-importing cannot stack duplicates.
    $schemaByPid = [];
    foreach ((array)($pkg['schema'] ?? []) as $row) {
        $t = (string)($row['target'] ?? '');
        $pid = $resolve($t);
        if (!$pid) { $skip('schema', $t, 'No page on this site matches that URL path'); continue; }
        $ld = $row['jsonld'] ?? null;
        if (!is_array($ld) || !$ld) { $skip('schema', $t, 'jsonld is missing or not an object'); continue; }
        $type = is_array($ld['@type'] ?? null) ? implode('/', $ld['@type']) : (string)($ld['@type'] ?? 'JSON-LD');
        $schemaByPid[$pid][] = $ld;
        $add('schema', $t, $type);
    }
    foreach ($schemaByPid as $pid => $blocks) {
        if (!isset($seo[$pid]) || !is_array($seo[$pid])) $seo[$pid] = [];
        $seo[$pid]['extraJsonld'] = $blocks;
        $touched[$pid] = true;
    }

    // 3 ── og_tags → <meta> only. Known OG/Twitter values are folded into
    // Fourge's own fields so the page never carries two og:title tags.
    $metaByPid = [];
    foreach ((array)($pkg['og_tags'] ?? []) as $row) {
        $t = (string)($row['target'] ?? '');
        $pid = $resolve($t);
        if (!$pid) { $skip('og_tags', $t, 'No page on this site matches that URL path'); continue; }
        $tags = cmsPkgSanitizeMetaTags((string)($row['html'] ?? ''));
        if (!$tags) { $skip('og_tags', $t, 'No usable <meta> tags after sanitizing (scripts and http-equiv are always dropped)'); continue; }
        if (!isset($seo[$pid]) || !is_array($seo[$pid])) $seo[$pid] = [];
        $extras = [];
        foreach ($tags as $tg) {
            $k = strtolower($tg['key']);
            if ($k === 'og:title')            $seo[$pid]['ogTitle'] = $tg['content'];
            elseif ($k === 'og:description')  $seo[$pid]['ogDescription'] = $tg['content'];
            elseif ($k === 'og:image')        $seo[$pid]['ogImage'] = $tg['content'];
            elseif ($k === 'twitter:card')    $seo[$pid]['twitterCard'] = $tg['content'];
            elseif (in_array($k, ['og:url', 'twitter:title', 'twitter:description', 'twitter:image'], true)) { /* derived */ }
            else $extras[] = $tg;
        }
        $metaByPid[$pid] = $extras;
        $touched[$pid] = true;
        $add('og_tags', $t, count($tags) . ' meta tag(s)');
    }
    foreach ($metaByPid as $pid => $extras) { $seo[$pid]['extraMeta'] = $extras; }

    // 4 ── site files
    $sf = (array)($pkg['site_files'] ?? []);
    $robots = trim((string)($sf['robots_txt'] ?? ''));
    if ($robots !== '') {
        $g = (isset($seo['__site']) && is_array($seo['__site'])) ? $seo['__site'] : [];
        $existing = trim((string)($g['robotsTxt'] ?? ''));
        if ($existing !== '' && $existing !== $robots) {
            $skip('robots_txt', '/robots.txt', 'This site already has custom robots.txt content in the SEO panel — left alone so the import cannot overwrite it. Paste the package version in by hand if you want it.');
        } else {
            $g['robotsTxt'] = $robots; $seo['__site'] = $g;
            $out = $robots;
            if ($baseUrl !== '' && !preg_match('~^sitemap:~im', $out)) $out .= "\nSitemap: " . $baseUrl . '/sitemap.xml';
            if (!$dry) { cmsPkgBackup('robots.txt'); @file_put_contents(PUBLIC_HTML . '/robots.txt', $out . "\n"); }
            $add('robots_txt', '/robots.txt', 'served, Sitemap line preserved');
        }
    }
    $llms = trim((string)($sf['llms_txt'] ?? ''));
    if ($llms !== '') {
        if (!$dry) { cmsPkgBackup('llms.txt'); @file_put_contents(PUBLIC_HTML . '/llms.txt', $llms . "\n"); }
        $add('llms_txt', '/llms.txt', strlen($llms) . ' bytes');
    }
    if (trim((string)($sf['sitemap_xml'] ?? '')) !== '') {
        $skip('sitemap_xml', '/sitemap.xml', 'Fourge generates its own sitemap from live pages and posts — the package copy would go stale. Rebuild it from the SEO panel instead.');
    }

    // 5 ── redirects (merge + dedupe by source path; raw is a manual item)
    $rd = (array)($pkg['redirects'] ?? []);
    $store = cmsPkgReadJson('redirects.json', []);
    if (!is_array($store)) $store = [];
    $bySrc = [];
    foreach ($store as $r) { if (is_array($r) && !empty($r['from'])) $bySrc[strtolower(trim((string)$r['from'], '/'))] = $r; }
    $newCount = 0;
    foreach ((array)($rd['rules'] ?? []) as $r) {
        $v = cmsPkgValidRedirect($r['from'] ?? '', $r['to'] ?? '');
        if (!$v) { $skip('redirect', (string)($r['from'] ?? '?') . ' → ' . (string)($r['to'] ?? '?'), 'Source path or target URL failed validation (unsafe characters, or the target is not an absolute/rooted URL)'); continue; }
        $code = (int)($r['code'] ?? 301);
        $key = strtolower(trim($v['from'], '/'));
        $bySrc[$key] = ['from' => $v['from'], 'to' => $v['to'], 'code' => $code];
        $newCount++;
    }
    if ($newCount) {
        $rules = array_values($bySrc);
        if (!$dry) { cmsPkgBackup('.htaccess'); cmsPkgWriteJson('redirects.json', $rules); cmsPkgWriteRedirects($rules); }
        $add('redirects', $newCount . ' rule(s)', count($rules) . ' total after merge/dedupe');
    }
    if (trim((string)($rd['raw'] ?? '')) !== '') {
        $man('redirects_raw', 'Server-level redirect block', 'The package includes raw .htaccess/nginx redirect text. It is never executed — review it and apply anything the rule list above does not cover by hand.');
    }

    // 6 ── security headers
    $hdrs = cmsPkgParseHeaders((string)(($pkg['security_headers']['raw'] ?? '')));
    // A Content-Security-Policy is never applied automatically. A wrong one
    // blocks the site's OWN scripts and stylesheets — the page renders blank or
    // unstyled and PageSpeed collapses — and a package cannot know what a given
    // site loads. It goes on the manual list to be tested on staging instead.
    if (isset($hdrs['Content-Security-Policy']) || isset($hdrs['Content-Security-Policy-Report-Only'])) {
        unset($hdrs['Content-Security-Policy'], $hdrs['Content-Security-Policy-Report-Only']);
        $man('security_headers', 'Content-Security-Policy',
            'The package included a CSP. Fourge does not apply one automatically — a CSP that does not match what this site loads will block its own scripts and styles, and the page renders blank or unstyled. Test it on staging, then add it at the server level.');
    }
    if ($hdrs) {
        if (!$dry) { cmsPkgBackup('.htaccess'); cmsPkgWriteHeaders($hdrs); }
        $add('security_headers', implode(', ', array_keys($hdrs)), count($hdrs) . ' header(s) sent on every response');
    } elseif (trim((string)($pkg['security_headers']['raw'] ?? '')) !== '') {
        $skip('security_headers', 'raw block', 'No valid `Header set` / `add_header` name-value pairs found in the block');
    }

    // 6b ── approved business facts → richer site-wide schema on every page
    if (!empty($pkg['business']) && is_array($pkg['business'])) {
        $biz = cmsPkgBusiness($pkg['business']);
        if (!$biz) {
            $skip('business', 'business profile', 'The block carried no usable fields');
        } else {
            $site = cmsPkgReadJson('site.json', []);
            if (!is_array($site)) $site = [];
            $site['business'] = $biz;                       // the delivered record, kept whole
            list($site, $filled) = cmsPkgBusinessToSite($biz, $site);
            if (!$dry) cmsPkgWriteJson('site.json', $site);
            $bits = [];
            if (!empty($biz['city']))          $bits[] = 'NAP';
            if (!empty($biz['hours']))         $bits[] = 'hours';
            if (!empty($biz['sameas']))        $bits[] = count($biz['sameas']) . ' profile link(s)';
            if (!empty($biz['service_area']))  $bits[] = 'service area';
            if (!empty($biz['rating_value']))  $bits[] = 'rating ' . $biz['rating_value'] . ' (' . $biz['rating_count'] . ')';
            $note = ($bits ? implode(', ', $bits) : 'business profile')
                  . ' — every page now carries a complete LocalBusiness graph'
                  . ($filled ? '; filled empty Site Info fields: ' . implode(', ', $filled) : '');
            $add('business', (string)($biz['name'] ?? 'business profile'), $note);
            // The facts belong in the AI-crawler map too — but only if that file
            // is one Fourge generated; a delivered llms.txt is left alone.
            if (!$dry) { try { fourgeEnsureLlms(true); } catch (Throwable $e) {} }
            if (isset($pkg['business']['rating_value']) && empty($biz['rating_value'])) {
                $skip('business.rating', 'aggregate rating',
                    'The rating was incomplete or out of range, so it was dropped — a made-up AggregateRating is a structured-data violation');
            }
        }
    }

    // 6c ── site-level things a package cannot fix, surfaced so they are not
    // silently missing. Checked against what this site actually has.
    $siteNow = cmsPkgReadJson('site.json', []);
    if (!is_array($siteNow)) $siteNow = [];
    $hasFavicon = trim((string)($siteNow['favicon'] ?? '')) !== '' || is_file(PUBLIC_HTML . '/favicon.ico');
    if (!$hasFavicon) {
        $man('site_task', 'Favicon', 'This site has no favicon. Add one in Design → Favicon — browsers show a blank page icon without it, and the audit counts it.');
    }
    $an = is_array($siteNow['analytics'] ?? null) ? $siteNow['analytics'] : [];
    if (trim((string)($an['ga4'] ?? '')) === '' && trim((string)($an['custom'] ?? '')) === '') {
        $man('site_task', 'Analytics', 'No analytics tag is installed. Add the GA4 measurement ID in the Analytics tab, or nothing this package does can be measured.');
    }
    $hasPrivacy = false;
    foreach ((array)$pages as $rec) {
        if (!is_array($rec)) continue;
        $hay = strtolower((string)($rec['title'] ?? '') . ' ' . (string)($rec['path'] ?? ($rec['file'] ?? '')));
        if (strpos($hay, 'privacy') !== false) { $hasPrivacy = true; break; }
    }
    if (!$hasPrivacy) {
        $man('site_task', 'Privacy policy', 'This site has no privacy policy page. The audit\'s trust pillar needs one, linked from the footer — create it in Pages.');
    }
    // Whether www or non-www is canonical cannot be inferred, and guessing 301s
    // an indexed domain at a hostname that may have no certificate. It is a
    // human decision, so it is listed rather than applied.
    $wsite = trim((string)($siteNow['website'] ?? ''));
    if ($wsite !== '') {
        $wHost = (string)parse_url(preg_match('~^https?://~i', $wsite) ? $wsite : 'https://' . $wsite, PHP_URL_HOST);
        if ($wHost !== '') {
            $other = stripos($wHost, 'www.') === 0 ? substr($wHost, 4) : 'www.' . $wHost;
            $man('site_task', 'Canonical host',
                'Confirm that https://' . $other . ' redirects to https://' . $wHost . ' (your configured domain). Fourge does not add that redirect itself — a wrong guess sends an indexed domain to a hostname that may have no certificate. Set it at the host or in DNS.');
        }
    }

    // 7 ── content (external_id is the idempotency key)
    $posts = cmsPkgReadJson('posts.json', []);
    if (!is_array($posts)) $posts = [];
    $nowTs = time();
    foreach ((array)($pkg['content'] ?? []) as $c) {
        $title = trim((string)($c['title'] ?? ''));
        $ext   = trim((string)($c['external_id'] ?? ''));
        if ($title === '') { $skip('content', $ext ?: '(untitled)', 'Entry has no title'); continue; }
        if (isset($c['approved']) && $c['approved'] === false) { $skip('content', $title, 'Marked not approved in the package'); continue; }
        $bodyRaw = (string)($c['body_html'] ?? '');
        // cmsPkgPageFromShell() renders the title as the page's H1. An H1 inside
        // the body makes two, which fails the single-H1 check Fourge's own
        // Post-Launch Check reports — demote body H1s so an import cannot
        // regress the on-page score of the page it just created.
        $bodyRaw = preg_replace('~<(/?)h1\b~i', '<$1h2', $bodyRaw);
        $body = cmsPkgSanitizeHtml($bodyRaw);
        if ($body === '') { $skip('content', $title, 'Body was empty (or contained nothing that survived sanitizing)'); continue; }
        $status = strtolower(trim((string)($c['status'] ?? 'draft')));
        // UNRESOLVED QA HOLDS PUBLICATION. The platform leaves markers like
        // "[CLIENT TO CONFIRM] …" in qa[] for anything a human still has to
        // answer. Publishing copy with an open question — a made-up statistic,
        // an unconfirmed licence number, a placeholder price — is worse than
        // publishing nothing, so the item is created as a draft for review no
        // matter what the package's status field says.
        $qa = array_values(array_filter(array_map(function ($q) {
            return trim(is_array($q) ? (string)($q['note'] ?? ($q['text'] ?? '')) : (string)$q);
        }, (array)($c['qa'] ?? []))));
        $qaHold = count($qa) > 0;
        if ($qaHold) $status = 'draft';
        $schedRaw = trim((string)($c['schedule'] ?? ''));
        $schedTs = $schedRaw !== '' ? strtotime($schedRaw) : false;
        $isPage = strtolower(trim((string)($c['post_type'] ?? 'post'))) === 'page';
        $publishNow = false; $publishAt = '';
        if ($status === 'publish') $publishNow = true;
        elseif ($status === 'schedule') {
            if ($schedTs === false) { $publishNow = true; }
            elseif ($schedTs <= $nowTs) { $publishNow = true; }
            else { $publishAt = gmdate('c', $schedTs); }
        }
        $noteBits = [];
        if ($publishNow && $status === 'schedule' && $schedTs !== false && $schedTs <= $nowTs) $noteBits[] = 'schedule date already passed — published now';
        elseif ($publishAt !== '') $noteBits[] = 'scheduled for ' . $publishAt;
        elseif (!$publishNow) $noteBits[] = 'draft, awaiting review';
        if ($qaHold) {
            $noteBits[] = 'HELD as a draft: ' . count($qa) . ' unresolved question' . (count($qa) === 1 ? '' : 's')
                        . ' — ' . mb_substr(implode('; ', $qa), 0, 220);
        }

        if ($isPage) {
            $slug = cmsPkgSlug($c['slug'] ?? $title);
            if ($slug === '') { $skip('content', $title, 'Could not derive a filename from the title'); continue; }
            $pid = null;
            foreach ($pages as $k => $rec) { if (is_array($rec) && $ext !== '' && (string)($rec['extId'] ?? '') === $ext) { $pid = $k; break; } }
            $isNew = ($pid === null);
            if ($isNew) {
                $pid = $slug;
                $n = 2; while (isset($pages[$pid])) { $pid = $slug . '-' . $n; $n++; }
            }
            $file = (string)($pages[$pid]['file'] ?? ($pid . '.html'));
            $html = cmsPkgPageFromShell($title, $body);
            $rec = is_array($pages[$pid] ?? null) ? $pages[$pid] : [];
            $rec['title'] = $title; $rec['file'] = $file; $rec['path'] = $file;
            if ($ext !== '') $rec['extId'] = $ext;
            // NEVER demote something a human already published. A re-import
            // whose console copy is still unapproved must not take a live page
            // off the site, and a page already scheduled must not be reset to a
            // draft — it keeps the schedule it has.
            $wasLive      = !$isNew && empty($rec['draft']);
            $wasScheduled = !$isNew && !empty($rec['draft']) && trim((string)($rec['publishAt'] ?? '')) !== '';
            // Live page + unresolved questions in the new copy: do NOTHING.
            // Un-publishing takes a working page off the site; overwriting puts
            // unreviewed copy live. Leaving it exactly as it is, and saying so,
            // is the only move that is not a regression.
            if ($wasLive && $qaHold) {
                $skip('content', $title, 'This page is live and the new copy still has ' . count($qa)
                    . ' unresolved question(s) — left untouched rather than publishing unreviewed copy. Resolve in the platform and re-import: ' . mb_substr(implode('; ', $qa), 0, 200));
                continue;
            }
            if ($wasLive && !$publishNow) {
                $publishNow = true; $publishAt = '';
                $noteBits = ['already live — updated in place, not un-published'];
            } elseif ($wasScheduled && !$publishNow && $publishAt === '') {
                $publishAt = (string)$rec['publishAt'];
                $noteBits = ['already scheduled for ' . $publishAt . ' — schedule kept'];
            }
            $rec['draft'] = !$publishNow;
            if ($publishAt !== '') $rec['publishAt'] = $publishAt; else unset($rec['publishAt']);
            // Remember WHY this was held. The reason otherwise lives only in the
            // import report, and "publish everything now" would then be a blind
            // override of the one gate that exists to stop unreviewed copy going
            // live. Recorded here so that button can name the open questions.
            if ($qaHold && !$publishNow) $rec['pkgQa'] = $qa; else unset($rec['pkgQa']);
            if (!isset($seo[$pid]) || !is_array($seo[$pid])) $seo[$pid] = [];
            if (trim((string)($seo[$pid]['title'] ?? '')) === '') $seo[$pid]['title'] = $title;
            $fk = trim((string)($c['focus_keyword'] ?? ''));
            if ($fk !== '' && trim((string)($seo[$pid]['focusKeyword'] ?? '')) === '') $seo[$pid]['focusKeyword'] = $fk;
            if (!$dry) {
                cmsPkgBackup($file);
                $canon = cmsPkgPageUrl($baseUrl, $file);   // URL emitter, not the match key
                @file_put_contents(PUBLIC_HTML . '/' . $file, cmsPkgStampSeo($html, $seo[$pid], $canon, $rec['draft']));
                $pages[$pid] = $rec;
            } else { $pages[$pid] = $rec; }
            $add('content_page', $title, ($isNew ? 'created ' : 'updated ') . $file . ($noteBits ? ' — ' . implode('; ', $noteBits) : ''));
        } else {
            $idx = null;
            foreach ($posts as $i => $p) { if (is_array($p) && $ext !== '' && (string)($p['extId'] ?? '') === $ext) { $idx = $i; break; } }
            $isNew = ($idx === null);
            $slug = cmsPkgSlug($c['slug'] ?? $title);
            if ($slug === '') $slug = 'post-' . substr(md5($title), 0, 6);
            foreach ($posts as $i => $p) {   // keep slugs unique across other posts
                if ($i === $idx || !is_array($p)) continue;
                if ((string)($p['slug'] ?? '') === $slug) { $slug .= '-' . substr(md5($ext . $title), 0, 4); break; }
            }
            $rec = $isNew ? [] : $posts[$idx];
            $rec['id']    = (string)($rec['id'] ?? ('pkg' . substr(md5($ext . $title . microtime(true)), 0, 9)));
            $rec['title'] = $title;
            $rec['slug']  = $slug;
            $rec['blocks'] = [['id' => substr(md5($rec['id'] . 'b'), 0, 7), 'type' => 'html', 'html' => $body]];
            if (trim((string)($rec['excerpt'] ?? '')) === '') {
                $plain = trim(preg_replace('~\s+~', ' ', strip_tags($body)));
                $rec['excerpt'] = mb_substr($plain, 0, 155);
            }
            // Same protection as pages: a re-import never un-publishes a post
            // that is already live, and never drops a schedule already set.
            $wasLive      = !$isNew && !empty($rec['published']);
            $wasScheduled = !$isNew && empty($rec['published']) && trim((string)($rec['publishAt'] ?? '')) !== '';
            if ($wasLive && $qaHold) {
                $skip('content', $title, 'This post is live and the new copy still has ' . count($qa)
                    . ' unresolved question(s) — left untouched rather than publishing unreviewed copy. Resolve in the platform and re-import: ' . mb_substr(implode('; ', $qa), 0, 200));
                continue;
            }
            if ($wasLive && !$publishNow) {
                $publishNow = true; $publishAt = '';
                $noteBits = ['already live — updated in place, not un-published'];
            } elseif ($wasScheduled && !$publishNow && $publishAt === '') {
                $publishAt = (string)$rec['publishAt'];
                $noteBits = ['already scheduled for ' . $publishAt . ' — schedule kept'];
            }
            $rec['date'] = $publishAt !== '' ? substr($publishAt, 0, 10)
                        : (trim((string)($rec['date'] ?? '')) !== '' ? $rec['date'] : gmdate('Y-m-d'));
            $rec['published'] = $publishNow;
            if ($publishAt !== '') $rec['publishAt'] = $publishAt; else unset($rec['publishAt']);
            if ($qaHold && !$publishNow) $rec['pkgQa'] = $qa; else unset($rec['pkgQa']);
            if ($ext !== '') $rec['extId'] = $ext;
            $fk = trim((string)($c['focus_keyword'] ?? ''));
            if ($fk !== '') $rec['focusKeyword'] = $fk;
            $kind = trim((string)($c['kind'] ?? ''));
            if ($kind !== '') $rec['pkgKind'] = $kind;
            if ($isNew) array_unshift($posts, $rec); else $posts[$idx] = $rec;
            $add('content_post', $title, ($isNew ? 'created' : 'updated') . ' /posts.html?p=' . $slug . ($noteBits ? ' — ' . implode('; ', $noteBits) : ''));
        }
    }

    // 8 ── manual tasks
    foreach ((array)($pkg['manual_tasks'] ?? []) as $t) {
        $man((string)($t['kind'] ?? 'task'), (string)($t['title'] ?? 'Task'), (string)($t['action'] ?? ''));
    }

    // Re-stamp every page whose SEO record changed.
    foreach (array_keys($touched) as $pid) {
        $rec = is_array($pages[$pid] ?? null) ? $pages[$pid] : null;
        $file = $rec ? (string)($rec['path'] ?? $rec['file'] ?? '') : '';
        if ($file === '') { $skip('stamp', (string)$pid, 'Page has no file on disk'); continue; }
        $abs = PUBLIC_HTML . '/' . ltrim($file, '/');
        if (!is_file($abs)) { $skip('stamp', $file, 'Page file not found on the server'); continue; }
        $html = (string)@file_get_contents($abs);
        if (trim($html) === '' || (stripos($html, '</html>') === false && stripos($html, '</body>') === false)) {
            $skip('stamp', $file, 'Page file looked empty or truncated — refused to rewrite it');
            continue;
        }
        $canon = cmsPkgPageUrl($baseUrl, $file);   // URL emitter, not the match key
        $next = cmsPkgStampSeo($html, $seo[$pid], $canon, !empty($rec['draft']));
        if ($next === $html) continue;
        if (!$dry) { cmsPkgBackup($file); @file_put_contents($abs, $next); }
    }

    if (!$dry) {
        cmsPkgWriteJson('seo.json', $seo);
        cmsPkgWriteJson('pages.json', $pages);
        cmsPkgWriteJson('posts.json', $posts);
    }

    return [
        'ok' => true,
        'imported_at' => date('c'),
        'dry_run' => (bool)$dry,
        'source' => [
            'site' => (string)($pkg['site'] ?? ''),
            'generated_at' => (string)($pkg['generated_at'] ?? ''),
            'package_id' => (string)(($pkg['source']['package_id'] ?? '')),
            'audit_score' => ($pkg['source']['audit_score'] ?? null),
            'client' => (string)(($pkg['client']['name'] ?? '')),
            'tier' => (string)(($pkg['client']['tier'] ?? '')),
        ],
        'applied' => $applied,
        'skipped' => $skipped,
        'manual'  => $manual,
        'counts'  => ['applied' => count($applied), 'skipped' => count($skipped), 'manual' => count($manual)],
    ];
}

function fourgeApiSeoPackage($me, $body) {
    if (!cmsPkgAuthorized($me, $body)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized — sign in to the CMS or present the site\'s deploy-package Bearer token.']);
        return;
    }
    // The pretty endpoint posts the package as the whole body; the admin screen
    // nests it under "package" alongside action/mode.
    $pkg = (is_array($body['package'] ?? null)) ? $body['package'] : $body;
    $mode = strtolower(trim((string)($body['mode'] ?? ($_GET['mode'] ?? 'apply'))));
    if (!is_array($pkg)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Request body was not valid JSON']); return; }
    if (strlen((string)json_encode($pkg)) > 8 * 1024 * 1024) {
        http_response_code(413); echo json_encode(['ok' => false, 'error' => 'Package is larger than 8 MB']); return;
    }
    if ((string)($pkg['format'] ?? '') !== '44i-deploy-package') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Not a 44i deploy package (expected format "44i-deploy-package"). Nothing was applied.']);
        return;
    }
    $fv = (int)($pkg['format_version'] ?? 0);
    if ($fv > 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'This package is format_version ' . $fv . '; this site understands version 1. Update Fourge, then re-import. Nothing was applied.']);
        return;
    }
    $dry = ($mode === 'preview' || $mode === 'dry-run' || $mode === 'dry_run');
    $report = cmsPkgApply($pkg, $dry);
    if (!$dry) {
        try { $report['published_now'] = cmsPkgTick(false); } catch (Throwable $e) {}
        cmsPkgWriteJson('seo-package-report.json', $report);
    }
    echo json_encode($report);
}

// "Publish everything the package brought in, now." The tick above only
// releases content whose scheduled time has arrived; this releases the lot —
// drafts awaiting review and future schedules alike.
//
// Scope is deliberately package content ONLY (records carrying an extId). A
// blanket publish of every draft on the site would sweep up half-written pages
// a client left alone, which is not what anyone means by this button.
//
// The subtle part is the HTML. A draft page is stamped noindex by
// cmsPkgStampSeo, so flipping draft=false in pages.json without re-stamping the
// file would "publish" a page that still tells search engines to ignore it.
// Each page is therefore re-stamped (and backed up first) as it goes live.
function cmsPkgPublishAll($dry = false) {
    $site    = cmsPkgReadJson('site.json', []);
    $baseUrl = trim((string)($site['website'] ?? ''));
    if ($baseUrl !== '' && !preg_match('~^https?://~i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;
    $seo     = cmsPkgReadJson('seo.json', []);
    $items   = [];

    $pages = cmsPkgReadJson('pages.json', []);
    $pagesDirty = false;
    if (is_array($pages)) {
        foreach ($pages as $pid => $rec) {
            if (!is_array($rec) || trim((string)($rec['extId'] ?? '')) === '') continue;
            $sched = trim((string)($rec['publishAt'] ?? ''));
            if (empty($rec['draft']) && $sched === '') continue;   // already live
            $qa = array_values(array_filter(array_map('strval', (array)($rec['pkgQa'] ?? []))));
            $items[] = [
                'type'  => 'page',
                'id'    => (string)$pid,
                'title' => (string)($rec['title'] ?? $pid),
                'was'   => $sched !== '' ? ('scheduled for ' . $sched) : 'draft',
                'qa'    => $qa,
            ];
            if ($dry) continue;
            $rec['draft'] = false;
            unset($rec['publishAt'], $rec['pkgQa']);
            $pages[$pid] = $rec; $pagesDirty = true;
            $file = (string)($rec['file'] ?? ($pid . '.html'));
            $full = PUBLIC_HTML . '/' . $file;
            if (is_file($full)) {
                $html = (string)@file_get_contents($full);
                if (trim($html) !== '') {
                    cmsPkgBackup($file);
                    $canon = cmsPkgPageUrl($baseUrl, $file);
                    $srec  = is_array($seo[$pid] ?? null) ? $seo[$pid] : [];
                    @file_put_contents($full, cmsPkgStampSeo($html, $srec, $canon, false));
                }
            }
        }
    }

    $posts = cmsPkgReadJson('posts.json', []);
    $postsDirty = false;
    if (is_array($posts)) {
        foreach ($posts as $i => $p) {
            if (!is_array($p) || trim((string)($p['extId'] ?? '')) === '') continue;
            $sched = trim((string)($p['publishAt'] ?? ''));
            if (!empty($p['published']) && $sched === '') continue;
            $qa = array_values(array_filter(array_map('strval', (array)($p['pkgQa'] ?? []))));
            $items[] = [
                'type'  => 'post',
                'id'    => (string)($p['id'] ?? $i),
                'title' => (string)($p['title'] ?? ''),
                'was'   => $sched !== '' ? ('scheduled for ' . $sched) : 'draft',
                'qa'    => $qa,
            ];
            if ($dry) continue;
            $posts[$i]['published'] = true;
            unset($posts[$i]['publishAt'], $posts[$i]['pkgQa']);
            // A post released early should not still advertise a future date.
            $d = trim((string)($posts[$i]['date'] ?? ''));
            if ($d === '' || strtotime($d) > time()) $posts[$i]['date'] = gmdate('Y-m-d');
            $postsDirty = true;
        }
    }

    if (!$dry) {
        if ($pagesDirty) cmsPkgWriteJson('pages.json', $pages);
        if ($postsDirty) cmsPkgWriteJson('posts.json', $posts);
    }
    $held = array_values(array_filter($items, function ($x) { return !empty($x['qa']); }));
    return ['items' => $items, 'count' => count($items), 'questioned' => $held];
}
function fourgeApiSeoPkgPublishAll($me, $body) {
    if (!$me || fourgeLevel($me) < 2) {
        http_response_code(403); echo json_encode(['error' => 'Admin access required']); return;
    }
    $dry = !empty($body['dry']);
    $r = cmsPkgPublishAll($dry);
    echo json_encode(['ok' => true, 'dry' => $dry] + $r);
}

function fourgeApiSeoPkgTick($me, $body) {
    if (!cmsPkgAuthorized($me, $body)) {
        http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Unauthorized']); return;
    }
    // Riding the platform's own scheduled ping is what makes "daily" actually
    // mean daily, independent of whether anyone happens to sign into THIS
    // site's admin — which is exactly the human step this is meant to remove.
    // Never allowed to affect the response this endpoint exists for.
    try { fourgeAiDailyTick(); } catch (Throwable $e) {}
    $done = cmsPkgTick(false);
    echo json_encode(['ok' => true, 'published' => $done, 'count' => count($done), 'checked_at' => date('c')]);
}

// Admin-side status + token rotation. The token is shown ONCE at generation.
function fourgeApiSeoPkgAdmin($me, $body) {
    if (fourgeLevel($me) < 2) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); return; }
    $op = strtolower(trim((string)($body['op'] ?? 'status')));
    $has = false;
    try { $has = trim((string)fourgeGetSecret(fourgeDb(), 'seo_pkg_token')) !== ''; } catch (Throwable $e) { $has = false; }
    $scheme = fourgeIsHttps() ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $endpoint = $host !== '' ? ($scheme . '://' . $host . '/api/seo-platform/package') : '/api/seo-platform/package';
    if ($op === 'regenerate') {
        if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required to rotate the key']); return; }
        try {
            $tok = bin2hex(random_bytes(24));
            fourgeSetSecret(fourgeDb(), 'seo_pkg_token', $tok, (string)($me['username'] ?? ''));
            echo json_encode(['ok' => true, 'token' => $tok, 'endpoint' => $endpoint, 'hasToken' => true,
                'note' => 'Copy this key now — it is stored encrypted and cannot be shown again.']);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => 'Could not store the key: ' . $e->getMessage()]);
        }
        return;
    }
    if ($op === 'revoke') {
        if (fourgeLevel($me) < 3) { http_response_code(403); echo json_encode(['error' => 'Super Admin access required']); return; }
        try { fourgeDb()->prepare("DELETE FROM secrets WHERE name=?")->execute(['seo_pkg_token']); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'hasToken' => false, 'endpoint' => $endpoint]);
        return;
    }
    echo json_encode(['ok' => true, 'hasToken' => $has, 'endpoint' => $endpoint,
        'tickEndpoint' => str_replace('/package', '/tick', $endpoint),
        'lastReport' => cmsPkgReadJson('seo-package-report.json', null)]);
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
