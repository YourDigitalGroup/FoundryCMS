<?php
/**
 * Fourge CMS — SQLite data layer (users, sessions, encrypted secrets)
 *
 * Required by api.php. This file is PHP (never emitted as text) and the
 * database file itself is denied over HTTP by admin/.htaccess. For maximum
 * safety set 'db_path' in config.secret.php to a location OUTSIDE the web root.
 *
 * Passwords: Argon2id (per-user salt). Legacy SHA-256 hashes migrated from the
 * old data/users.json are still accepted on login and transparently upgraded.
 * Secrets: encrypted at rest with libsodium secretbox; the key is derived from
 * 'db_secret_key' in config.secret.php (gitignored) and never stored in the DB.
 */

// ── CONFIG ───────────────────────────────────────────────────────────────────
function fourgeConfig() {
    static $cfg = null;
    if ($cfg === null) {
        $f = __DIR__ . '/config.secret.php';
        $cfg = file_exists($f) ? (include $f) : [];
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}

function fourgeDbPath() {
    $cfg = fourgeConfig();
    return $cfg['db_path'] ?? (__DIR__ . '/fourge.db');
}

// ── CONNECTION + SCHEMA ────────────────────────────────────────────────────────
function fourgeDb() {
    static $pdo = null;
    if ($pdo === null) {
        $path = fourgeDbPath();
        $fresh = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        if ($fresh) @chmod($path, 0600);
        fourgeInitSchema($pdo);
        fourgeMigrate($pdo);
        fourgeSeedIfEmpty($pdo);
    }
    return $pdo;
}

// Add columns introduced after the initial release to already-existing DBs.
// CREATE TABLE IF NOT EXISTS won't alter an existing table, so add them here.
function fourgeMigrate($pdo) {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(users)") as $r) { $cols[] = $r['name']; }
    if (!in_array('first_name', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN first_name TEXT");
    if (!in_array('last_name',  $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN last_name TEXT");
}

function fourgeInitSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        display_name TEXT,
        email TEXT,
        first_name TEXT,
        last_name TEXT,
        role TEXT NOT NULL DEFAULT 'editor',
        is_architect INTEGER NOT NULL DEFAULT 0,
        password_hash TEXT NOT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        permissions TEXT,
        created_at TEXT,
        updated_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS secrets (
        name TEXT PRIMARY KEY,
        value_enc TEXT NOT NULL,
        updated_at TEXT,
        updated_by TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        token TEXT PRIMARY KEY,
        username TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )");
}

// One-time migration: import the existing public users.json (if present) into
// the DB, preserving each account's hash/role/flags. Legacy SHA-256 hashes are
// upgraded to Argon2id the first time each user logs in successfully.
function fourgeSeedIfEmpty($pdo) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($n > 0) return;
    $f = __DIR__ . '/../data/users.json';
    if (!file_exists($f)) return;
    $users = json_decode(file_get_contents($f), true);
    if (!is_array($users)) return;
    $now = date('c');
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users
        (username, display_name, email, first_name, last_name, role, is_architect, password_hash, must_change_password, permissions, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($users as $u) {
        if (!is_array($u) || empty($u['username'])) continue;
        $stmt->execute([
            strtolower(trim($u['username'])),
            $u['displayName'] ?? null,
            $u['email'] ?? $u['username'],
            $u['firstName'] ?? null,
            $u['lastName'] ?? null,
            $u['role'] ?? 'editor',
            !empty($u['architect']) ? 1 : 0,
            $u['hash'] ?? '',
            !empty($u['mustChangePassword']) ? 1 : 0,
            isset($u['permissions']) ? json_encode($u['permissions']) : null,
            $u['createdAt'] ?? $now,
            $now,
        ]);
    }
}

// ── USERS ──────────────────────────────────────────────────────────────────────
function fourgeGetUser($pdo, $username) {
    $st = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $st->execute([strtolower(trim((string)$username))]);
    $u = $st->fetch();
    return $u ?: null;
}

// Resolve a login identifier that may be EITHER a username or an email address.
// Username is matched first so it takes precedence over a colliding email.
function fourgeGetUserByLogin($pdo, $identifier) {
    $id = strtolower(trim((string)$identifier));
    if ($id === '') return null;
    $st = $pdo->prepare("SELECT * FROM users WHERE lower(username)=? LIMIT 1");
    $st->execute([$id]);
    if ($u = $st->fetch()) return $u;
    $st = $pdo->prepare("SELECT * FROM users WHERE lower(email)=? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// True if the username or email would collide with a DIFFERENT account. Also
// blocks a username equal to another account's email (login ambiguity).
function fourgeLoginTaken($pdo, $username, $email, $exceptId) {
    $username = strtolower(trim((string)$username));
    $email    = strtolower(trim((string)$email));
    $st = $pdo->prepare("SELECT id FROM users WHERE id<>? AND (lower(username)=? OR lower(email)=?)");
    $st->execute([$exceptId, $username, $username]);
    if ($st->fetch()) return true;
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE id<>? AND lower(email)=?");
        $st->execute([$exceptId, $email]);
        if ($st->fetch()) return true;
    }
    return false;
}

function fourgeLevel($user) {
    if (!empty($user['is_architect'])) return 4;
    $r = $user['role'] ?? 'editor';
    return $r === 'superadmin' ? 3 : ($r === 'admin' ? 2 : 1);
}

// Shape a user row for the client session (never includes the hash).
function fourgePublicUser($u) {
    return [
        'id'                 => isset($u['id']) ? (int)$u['id'] : null,
        'username'           => $u['username'],
        'displayName'        => $u['display_name'] ?? null,
        'email'              => $u['email'] ?? null,
        'firstName'          => $u['first_name'] ?? null,
        'lastName'           => $u['last_name'] ?? null,
        'role'               => $u['role'] ?? 'editor',
        'architect'          => !empty($u['is_architect']),
        'mustChangePassword' => !empty($u['must_change_password']),
        'permissions'        => !empty($u['permissions']) ? (json_decode($u['permissions'], true) ?: (object)[]) : (object)[],
    ];
}

function fourgePwAlgo() {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function fourgeSetPassword($pdo, $username, $plain) {
    $h = password_hash($plain, fourgePwAlgo());
    $pdo->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE username=?")
        ->execute([$h, date('c'), strtolower(trim((string)$username))]);
    return $h;
}

// Verify a password against either an Argon2id/bcrypt hash or a legacy
// sha256('cd2024:'.$pw) hash. Legacy hashes are upgraded to Argon2id on success.
function fourgeVerifyPassword($pdo, $user, $plain) {
    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || $plain === '') return false;
    if ($hash[0] === '$') { // modern hash
        if (password_verify($plain, $hash)) {
            if (password_needs_rehash($hash, fourgePwAlgo())) {
                fourgeSetPassword($pdo, $user['username'], $plain);
            }
            return true;
        }
        return false;
    }
    // Legacy SHA-256 (static 'cd2024:' salt) — accept once, then upgrade
    $legacy = hash('sha256', 'cd2024:' . $plain);
    if (hash_equals(strtolower($hash), $legacy)) {
        fourgeSetPassword($pdo, $user['username'], $plain);
        return true;
    }
    return false;
}

// ── SESSIONS ─────────────────────────────────────────────────────────────────
function fourgeCreateSession($pdo, $username, $days = 30) {
    $token = bin2hex(random_bytes(32));
    $now = time();
    $pdo->prepare("INSERT INTO sessions (token, username, created_at, expires_at) VALUES (?,?,?,?)")
        ->execute([$token, strtolower(trim((string)$username)), $now, $now + $days * 86400]);
    $pdo->prepare("DELETE FROM sessions WHERE expires_at < ?")->execute([$now]); // prune
    return $token;
}

// Resolve a session token to its user row, or null if missing/expired.
function fourgeSessionUser($pdo, $token) {
    if (!$token) return null;
    $st = $pdo->prepare("SELECT u.* FROM sessions s JOIN users u ON u.username = s.username WHERE s.token = ? AND s.expires_at >= ?");
    $st->execute([$token, time()]);
    return $st->fetch() ?: null;
}

function fourgeDeleteSession($pdo, $token) {
    if ($token) $pdo->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
}

// ── SECRET ENCRYPTION (libsodium) ────────────────────────────────────────────
function fourgeKey() {
    $cfg = fourgeConfig();
    $k = (string)($cfg['db_secret_key'] ?? '');
    if (strlen($k) < 16) {
        throw new Exception('db_secret_key is missing or too short in admin/config.secret.php (use a long random value).');
    }
    // Derive a fixed 32-byte key from the configured passphrase.
    return sodium_crypto_generichash($k, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function fourgeEncrypt($plain) {
    $key = fourgeKey();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox((string)$plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

function fourgeDecrypt($enc) {
    $raw = base64_decode((string)$enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, fourgeKey());
    return $plain === false ? null : $plain;
}

// Minimum access level required to read/write each secret.
// Levels: editor=1, admin=2, superadmin=3, architect=4.
// These are the credentials that used to live per-browser in localStorage.
// (Mailgun + reCAPTCHA already live server-side and stay on their existing
// mechanism, surfaced to Super Admin+ in the UI.)
function fourgeSecretPolicy() {
    return [
        'github_pat'    => 4,  // Architect only — used by the browser to publish
        'repo_override' => 4,  // Architect only — target repo (owner/repo)
        'claude_key'    => 4,  // Architect only — server-side AI proxy key
        // Mailgun (forms) — Super Admin and above. Stored encrypted in the DB so
        // it lives server-side (not per-browser) and survives a cache clear.
        'mg_domain'     => 3,
        'mg_api_key'    => 3,
        'mg_from'       => 3,
        'mg_notify_to'  => 3,
    ];
}

function fourgeSecretLevel($name) {
    $p = fourgeSecretPolicy();
    return $p[$name] ?? 4; // unknown secrets default to Architect-only
}

function fourgeGetSecret($pdo, $name) {
    $st = $pdo->prepare("SELECT value_enc FROM secrets WHERE name=?");
    $st->execute([$name]);
    $row = $st->fetch();
    if (!$row) return null;
    return fourgeDecrypt($row['value_enc']);
}

function fourgeSetSecret($pdo, $name, $plain, $by = '') {
    $enc = fourgeEncrypt($plain);
    $pdo->prepare("INSERT INTO secrets (name, value_enc, updated_at, updated_by) VALUES (?,?,?,?)
                   ON CONFLICT(name) DO UPDATE SET value_enc=excluded.value_enc, updated_at=excluded.updated_at, updated_by=excluded.updated_by")
        ->execute([$name, $enc, date('c'), $by]);
}
