<?php
// Copy this file to config.php on the server and fill in the real values.
// config.php is git-ignored — it holds secrets and must never be committed.

$KB_DISCORD_WEBHOOK = '';                       // Discord webhook URL for new-lead notifications (optional)
$KB_NOTIFY_EMAIL    = '';                       // where owner notifications are sent (falls back to the admin email)
$KB_SITE            = 'https://kbsites.com.br'; // canonical site URL (used to build absolute links in emails)

// Note: the Google OAuth client id/secret and the commission %, PayPal link, extra admins, etc.
// are NOT stored here — they live in the SQLite `settings` table and are edited from the admin panel
// (/ticket/ → Settings). Only the three values above belong in this file.
