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

define('API_TOKEN',    (string)($__secret['api_token'] ?? 'CHANGE_ME')); // optional now (login uses sessions); kept for legacy/external callers
define('PUBLIC_HTML',  realpath(dirname(__DIR__)));

// Mailgun (forms)
define('MG_DOMAIN',    (string)($__secret['mg_domain']    ?? 'mg.example.com'));
define('MG_API_KEY',   (string)($__secret['mg_api_key']   ?? ''));
define('MG_FROM',      (string)($__secret['mg_from']      ?? 'Fourge CMS <postmaster@mg.example.com>'));
define('MG_NOTIFY_TO', (string)($__secret['mg_notify_to'] ?? ''));

// Folders to always exclude from scan (relative paths from public_html root)
// Add any site-specific paths you want hidden from import
define('SKIP_PATHS', [
    'uploads/html-site-boilerplate',
    'boilerplate',
    'backup',
    'backups',
    '_archive',
]);

// Directories to never recurse into
define('SKIP_DIRS', [
    'admin', '.git', '.github', 'node_modules', 'vendor',
    'cgi-bin', 'wp-admin', 'wp-includes',
]);

// Pattern that uniquely identifies a Fourge CMS shell.
// IMPORTANT: must be specific enough to never match a regular HTML file.
// Only the generated makeShell() output contains this exact string.
define('CMS_PATTERN', 'src="../block-renderer.jsx"');

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
//  • Account + secret actions require a valid session token (from login).
//  • Legacy file / GA / mail / AI actions accept the shared API_TOKEN OR a
//    session token, so existing publishing and public form posts keep working.
$PUBLIC_ACTIONS  = ['login'];
$SESSION_ACTIONS = ['logout','session','list_users','save_user','delete_user','change_password','get_secrets','set_secret','repo_fetch'];

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

function cmsVerifyRecaptcha($secret, $token) {
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
    return !empty($data['success']);
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
        if (!$rcToken || !cmsVerifyRecaptcha($rcSecret, $rcToken)) {
            http_response_code(400);
            echo json_encode(['error' => 'reCAPTCHA verification failed. Please try again.']); return;
        }
    }

    // Store the submission in data/entries.json (best-effort, non-fatal)
    cmsStoreEntry($formId, $fields, $siteUrl);

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
        http_response_code(401); echo json_encode(['error' => 'Invalid login or password']); return;
    }
    $token = fourgeCreateSession($pdo, $user['username']);
    $user  = fourgeGetUser($pdo, $user['username']); // reload — verify may have upgraded the hash
    echo json_encode(['ok' => true, 'token' => $token, 'user' => fourgePublicUser($user)]);
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
