<?php
/**
 * Fourge CMS — config.secret.php   (TEMPLATE / EXAMPLE)
 * ============================================================================
 *  WHAT THIS IS
 *    The one private settings file for a site: database key, contact-form email
 *    credentials, the AI key, and team onboarding. It lives beside api.php in
 *    the /admin/ folder.
 *
 *  HOW TO USE IT (copy → rename → fill in)
 *    1. Copy this file and rename the copy to  config.secret.php
 *       (same folder, just drop the ".example").
 *    2. Fill in the values you need below. Everything is OPTIONAL except
 *       'db_secret_key'. Leave the rest commented out (//) if you don't use it.
 *    3. Save. api.php reads this automatically, and CMS auto-updates will NEVER
 *       overwrite it. Never commit config.secret.php — it's gitignored.
 *
 *  FORMAT: this file just returns a PHP array. Keep the  'key' => 'value',
 *  shape — mind the quotes and the trailing comma. Lines starting with // are
 *  ignored, so uncomment a line to turn that setting on.
 * ============================================================================
 */

return [

    // ── REQUIRED — local database encryption key ────────────────────────────
    //   Protects anything the CMS stores encrypted (GitHub token, AI key).
    //   Use a long random string and NEVER change it once the site is live.
    //   Generate one by running this on the server:
    //       php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
    'db_secret_key' => 'PASTE_A_LONG_RANDOM_STRING_HERE',


    // ════════════════════════════════════════════════════════════════════════
    //  CONTACT-FORM EMAIL
    //  Pick ONE way to send (A or B), then set the From + recipient at the end.
    //  SMTP (A) is easiest when moving a site that already had a working form —
    //  reuse the exact same credentials.
    //
    //  ALREADY HAVE AN OLD send.php ON THIS SITE? You can skip this whole
    //  section — the CMS automatically reads the SMTP host/user/password and
    //  from/to out of an existing send.php (it's parsed as text, never run).
    //  Only fill the keys below if you WANT to override what send.php uses.
    // ════════════════════════════════════════════════════════════════════════

    // ── OPTION A · SMTP (Mailgun / SendGrid / any SMTP relay) ───────────────
    //
    //   MOVING AN OLD PHPMailer "send.php"? Copy the values straight across:
    //
    //       OLD send.php  $CONFIG[...]        ->  THIS FILE
    //       ---------------------------------------------------------
    //       'smtp_host'                       ->  'smtp_host'
    //       'smtp_port'                       ->  'smtp_port'
    //       'smtp_username'                   ->  'smtp_username'
    //       'smtp_password'                   ->  'smtp_password'
    //       'from_name' + 'from_email'        ->  'mg_from'       (combined, see below)
    //       'to_email'                        ->  'mg_notify_to'  (where mail lands)
    //
    // 'smtp_host'     => 'smtp.mailgun.org',        // EU region: 'smtp.eu.mailgun.org'
    // 'smtp_port'     => 587,                        // 587 = STARTTLS (usual), 465 = SSL
    // 'smtp_username' => 'postmaster@mg.YOURDOMAIN.com',
    // 'smtp_password' => 'your-smtp-password',
    // 'smtp_secure'   => 'auto',                     // auto | tls | ssl | none  ('auto' is right for 587/465)

    // ── OPTION B · Mailgun HTTP API (uses the API KEY, not the SMTP password) ──
    // 'mg_domain'     => 'mg.YOURDOMAIN.com',
    // 'mg_api_key'    => 'key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // ── From + recipient — SET THESE (used by both A and B) ─────────────────
    //   IMPORTANT: 'mg_from' MUST be an address on your verified mail domain, or
    //   the mail service will REJECT the message. Format: Display Name <address>
    // 'mg_from'       => 'Your Business <info@YOURDOMAIN.com>',
    // 'mg_notify_to'  => 'you@YOURDOMAIN.com',       // inbox that receives submissions


    // ── OPTIONAL — shared server API token (legacy / external callers) ──────
    //   The CMS signs in with your account, so most sites can leave this off.
    // 'api_token'     => 'a-long-random-shared-secret',


    // ── OPTIONAL — AI features (SEO copy, content assistance) ───────────────
    //   Anthropic API key (starts with sk-ant-). Stays server-side; never sent
    //   to the browser.
    // 'anthropic_key'    => 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    // 'ai_rate_per_hour' => 40,                       // max AI calls per user per hour


    // ── OPTIONAL — team onboarding across your client sites ─────────────────
    //   A NEW email on this domain that signs in with this shared password gets
    //   an editor account (forced to set its own password on first login). It
    //   never overrides an existing login and only ever grants "editor".
    // 'onboard_domain'   => 'youragency.com',
    // 'onboard_password' => 'a-shared-onboarding-secret',


    // ── OPTIONAL — allow plain HTTP (NOT recommended) ───────────────────────
    //   Sign-in and credential changes require HTTPS by default. Only set this
    //   to false for a deliberate local/no-TLS setup.
    // 'require_https'    => true,

];
