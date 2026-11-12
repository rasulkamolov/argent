<?php
/**
 * ARGENT — private settings (LC-UP login, secrets).
 *
 * Put this folder OUTSIDE public_html and call it "argent_private", e.g.
 *     /home/uXXXXXXXX/domains/oxfordlc.uz/argent_private/config.php
 *     /home/uXXXXXXXX/domains/oxfordlc.uz/public_html/argent/      <- the application
 * Never e-mail or commit this file. The admin panel never shows these values.
 */
return [
    // ---- LC-UP (read-only access: only the login call + GET requests are ever made) ----
    'LCUP_BASE_URL' => 'https://oxfordlc.lc-up.com',
    'LCUP_PHONE'    => '',            // e.g. '+998901234567' (the number you log in with)
    'LCUP_PASSWORD' => '',
    'LCUP_PHONE_FORMAT' => 'mask',    // 'mask' = +(998)-90-123-45-67 (as the login box types it), 'digits' = 998901234567, 'raw' = exactly as written above

    // ---- Secrets for the two entry points ----
    'CRON_TOKEN'          => '',      // long random string; used as  cron.php?token=THIS   (generate: openssl rand -hex 24)
    'LCUP_WEBHOOK_SECRET' => '',      // shared secret for webhook.php (X-LC-UP-Signature = HMAC-SHA256 of the body)

    // ---- Telegram (optional here: you can also set these in Admin → LC-UP) ----
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_CHAT_ID'   => '',
];
