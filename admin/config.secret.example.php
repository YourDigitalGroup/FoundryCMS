<?php
/**
 * Fourge — Secret configuration (EXAMPLE / TEMPLATE)
 * --------------------------------------------------------------------------
 * Copy this file to `config.secret.php` in the same folder and fill in your
 * keys. `config.secret.php` is gitignored and must NEVER be committed.
 *
 * This file holds the shared Anthropic API key used by the server-side AI
 * proxy (action=claude_proxy in api.php). It lives INSIDE the admin folder so
 * the whole folder stays portable (drop into any public_html and it works).
 *
 * WHY THIS IS SAFE TO KEEP IN THE WEB ROOT:
 *  1. This is a .php file. A web server EXECUTES .php files; it never serves
 *     their source. Because this file only `return`s an array and echoes
 *     nothing, a direct browser request to it produces a BLANK page — the key
 *     is never sent to the browser.
 *  2. The accompanying admin/.htaccess denies direct HTTP access to this file
 *     outright (Apache / LiteSpeed) as a second, independent layer.
 *  3. The browser never makes the Anthropic call — api.php does, server-side —
 *     so the key never appears in any network response a user could inspect.
 *
 * SET YOUR KEY BELOW. Treat config.secret.php like a password.
 */

return [
    // Your Anthropic API key (starts with sk-ant-...). Optional once the
    // Architect saves a Claude key in Settings — that DB value takes priority.
    'anthropic_key'    => 'sk-ant-REPLACE_WITH_YOUR_KEY',

    // Abuse guard: max AI calls allowed PER USER PER HOUR through the proxy.
    // Protects your bill if a session is shared or a client mashes the button.
    'ai_rate_per_hour' => 40,

    // ── SQLite backend (accounts + sessions + encrypted secrets) ──
    // REQUIRED. Master key used to encrypt stored secrets (GitHub PAT, Claude
    // key, etc.) with libsodium. Use a long random value and NEVER change it
    // once secrets are saved, or they become unreadable.
    // Generate one with:  php -r "echo bin2hex(random_bytes(32));"
    'db_secret_key'    => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // Optional. Where the SQLite file lives. Defaults to admin/fourge.db, which
    // is gitignored and denied over HTTP by admin/.htaccess. For best security,
    // point this OUTSIDE the web root, e.g. '/home/youruser/private/fourge.db'.
    // 'db_path'       => __DIR__ . '/fourge.db',

    // ── Server API token (OPTIONAL) ──
    // The CMS authenticates with your login session, so this is only needed for
    // legacy/external callers. Setting it here keeps it out of api.php so
    // deploying/updating api.php never overwrites it.
    // 'api_token'     => 'a-long-random-shared-secret',

    // ── Mailgun (forms) ──
    // Put your Mailgun values here so deploying/updating api.php never clobbers
    // them. All optional — only needed if you use the contact forms.
    // 'mg_domain'     => 'mg.yoursite.com',
    // 'mg_api_key'    => 'key-...',
    // 'mg_from'       => 'Your Site <postmaster@mg.yoursite.com>',
    // 'mg_notify_to'  => 'you@yoursite.com',
];
