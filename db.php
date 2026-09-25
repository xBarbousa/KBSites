<?php
// Shared SQLite connection + schema + helpers for the KB Sites ticket system.

// Branded HTML emails (kb_mail_html + catalogue of email kinds).
require_once __DIR__ . '/mailer.php';

// Strip CR/LF so user input can't inject extra email headers (header injection).
function kb_hdr($s){ return trim(str_replace(["\r","\n","\0"], ' ', (string)$s)); }

// Start the PHP session with hardened cookie flags. Call instead of session_start().
function kb_session_start(){
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  @session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
  @session_start();
}

function kb_db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = __DIR__ . '/kbdata';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($dir . '/index.html', '');
  }
  $pdo = new PDO('sqlite:' . $dir . '/kb.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec("CREATE TABLE IF NOT EXISTS tickets(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE,
    name TEXT, email TEXT, business TEXT, message TEXT,
    status TEXT DEFAULT 'new',
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS replies(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER, body TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT)");

  // --- migration: extra columns that turn a ticket into a sales lead ---
  $cols = [];
  foreach ($pdo->query("PRAGMA table_info(tickets)") as $c) { $cols[$c['name']] = true; }
  $add = [
    'value'       => "ALTER TABLE tickets ADD COLUMN value REAL",
    'maintenance' => "ALTER TABLE tickets ADD COLUMN maintenance REAL",
    'paid'        => "ALTER TABLE tickets ADD COLUMN paid INTEGER DEFAULT 0",
    'paid_at'     => "ALTER TABLE tickets ADD COLUMN paid_at TEXT",
    'site_url'    => "ALTER TABLE tickets ADD COLUMN site_url TEXT",
    'notes'       => "ALTER TABLE tickets ADD COLUMN notes TEXT",
  ];
  foreach ($add as $name => $sql) { if (empty($cols[$name])) { try { $pdo->exec($sql); } catch (Exception $e) {} } }

  // --- affiliate columns on tickets (who referred + commission payout state) ---
  $addT = [
    'ref_code'           => "ALTER TABLE tickets ADD COLUMN ref_code TEXT",
    'commission_paid'    => "ALTER TABLE tickets ADD COLUMN commission_paid INTEGER DEFAULT 0",
    'commission_paid_at' => "ALTER TABLE tickets ADD COLUMN commission_paid_at TEXT",
    'user_id'            => "ALTER TABLE tickets ADD COLUMN user_id INTEGER",
  ];
  foreach ($addT as $name => $sql) { if (empty($cols[$name])) { try { $pdo->exec($sql); } catch (Exception $e) {} } }

  // --- plan chosen on the quote form + where the request came from (anti self-referral) ---
  $addP = [
    'plan'      => "ALTER TABLE tickets ADD COLUMN plan TEXT",
    'ip'        => "ALTER TABLE tickets ADD COLUMN ip TEXT",
    'dev'       => "ALTER TABLE tickets ADD COLUMN dev TEXT",
    'comm_void' => "ALTER TABLE tickets ADD COLUMN comm_void INTEGER DEFAULT 0",
    'comm_pct'  => "ALTER TABLE tickets ADD COLUMN comm_pct REAL",
  ];
  foreach ($addP as $name => $sql) { if (empty($cols[$name])) { try { $pdo->exec($sql); } catch (Exception $e) {} } }

  // --- affiliates (partner accounts) ---
  $pdo->exec("CREATE TABLE IF NOT EXISTS affiliates(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE,
    name TEXT, email TEXT UNIQUE,
    pass_hash TEXT,
    payout TEXT,
    verified INTEGER DEFAULT 0,
    verify_code TEXT,
    status TEXT DEFAULT 'active',
    agreed_at TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");
  // The `affiliates` table doubles as the accounts table for everyone.
  // migrations: google login + is_affiliate flag (role opt-in).
  $acols = [];
  foreach ($pdo->query("PRAGMA table_info(affiliates)") as $c) { $acols[$c['name']] = true; }
  if (empty($acols['google_sub']))   { try { $pdo->exec("ALTER TABLE affiliates ADD COLUMN google_sub TEXT"); } catch (Exception $e) {} }
  if (empty($acols['is_affiliate'])) {
    try { $pdo->exec("ALTER TABLE affiliates ADD COLUMN is_affiliate INTEGER DEFAULT 0"); } catch (Exception $e) {}
    // anyone who already has a referral code was a real affiliate
    try { $pdo->exec("UPDATE affiliates SET is_affiliate=1 WHERE code IS NOT NULL AND code<>''"); } catch (Exception $e) {}
  }
  if (empty($acols['photo'])) { try { $pdo->exec("ALTER TABLE affiliates ADD COLUMN photo TEXT"); } catch (Exception $e) {} }
  // --- Brazil-only affiliate payout (Pix) + partner rank (patente) override ---
  $affAdd = [
    'country'       => "ALTER TABLE affiliates ADD COLUMN country TEXT",          // 'BR' once they declare Brazil residence
    'pix_key'       => "ALTER TABLE affiliates ADD COLUMN pix_key TEXT",          // the Pix key we pay to
    'pix_type'      => "ALTER TABLE affiliates ADD COLUMN pix_type TEXT",         // cpf / email / phone / random
    'cpf'           => "ALTER TABLE affiliates ADD COLUMN cpf TEXT",              // CPF (tax id) — the partner pays their own IR
    'tier_override' => "ALTER TABLE affiliates ADD COLUMN tier_override TEXT",    // admin locks a rank, else it's earned by points
  ];
  foreach ($affAdd as $name => $sql) { if (empty($acols[$name])) { try { $pdo->exec($sql); } catch (Exception $e) {} } }

  // "remember me" tokens for persistent login (survives browser restarts)
  $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER, token_hash TEXT, expires_at TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");

  // in-app notifications (the bell)
  $pdo->exec("CREATE TABLE IF NOT EXISTS notifications(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER, ticket_id INTEGER,
    kind TEXT, body TEXT, link TEXT,
    seen INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");

  // who wrote a reply: 'admin' (studio) or 'client' (the ticket's owner)
  $rcols = [];
  foreach ($pdo->query("PRAGMA table_info(replies)") as $c) { $rcols[$c['name']] = true; }
  if (empty($rcols['who'])) { try { $pdo->exec("ALTER TABLE replies ADD COLUMN who TEXT DEFAULT 'admin'"); } catch (Exception $e) {} }
  // which moderator/admin account posted an 'admin' reply (so their service can be rated)
  if (empty($rcols['mod_id'])) { try { $pdo->exec("ALTER TABLE replies ADD COLUMN mod_id INTEGER"); } catch (Exception $e) {} }

  // browsers (kb_dev cookie) + IPs each account has used — flags self-referrals
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_devices(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER, dev TEXT, ip TEXT,
    first_seen TEXT DEFAULT (datetime('now','localtime')),
    last_seen TEXT DEFAULT (datetime('now','localtime'))
  )");
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_user_devices_user ON user_devices(user_id)"); } catch (Exception $e) {}
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS ix_user_devices_dev ON user_devices(dev)"); } catch (Exception $e) {}

  // every transactional email we tried to send (admin → Emails view)
  $pdo->exec("CREATE TABLE IF NOT EXISTS email_log(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT, to_email TEXT, ticket_id INTEGER, subject TEXT,
    ok INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime'))
  )");

  // internal service rating: a client rates the moderator who handled their ticket (1–5 stars).
  $pdo->exec("CREATE TABLE IF NOT EXISTS mod_ratings(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER, mod_id INTEGER, client_id INTEGER,
    stars INTEGER, comment TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    updated_at TEXT DEFAULT (datetime('now','localtime')),
    UNIQUE(ticket_id, client_id)
  )");

  // live chat: 'X is typing…' state, one row per (ticket, side). side = 'client' | 'admin'.
  $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing(
    ticket_id INTEGER, side TEXT, name TEXT, user_id INTEGER,
    expires_at TEXT,
    PRIMARY KEY(ticket_id, side)
  )");

  // live chat: read receipts (WhatsApp-style ✓✓). last reply id each side has seen.
  $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reads(
    ticket_id INTEGER, side TEXT, last_read_id INTEGER,
    updated_at TEXT DEFAULT (datetime('now','localtime')),
    PRIMARY KEY(ticket_id, side)
  )");

  return $pdo;
}

// The account whose email matches this is the admin/mod.
function kb_admin_email() {
  $v = kb_setting_get('admin_email');
  if ($v) return strtolower(trim($v));
  if (isset($GLOBALS['KB_NOTIFY_EMAIL']) && $GLOBALS['KB_NOTIFY_EMAIL']) return strtolower(trim($GLOBALS['KB_NOTIFY_EMAIL']));
  return 'kaua12131415a@gmail.com';
}
// Extra admin emails (comma-separated setting), e.g. a temporary tester.
function kb_extra_admins() {
  $v = kb_setting_get('extra_admins');
  if (!$v) return [];
  $out = [];
  foreach (explode(',', $v) as $e) { $e = strtolower(trim($e)); if ($e !== '') $out[] = $e; }
  return $out;
}
function kb_is_admin($user) {
  if (!$user || empty($user['email'])) return false;
  $e = strtolower(trim($user['email']));
  return $e === kb_admin_email() || in_array($e, kb_extra_admins(), true);
}
// account helpers (the affiliates table holds all accounts)
function kb_user_by_email($e){ return kb_aff_by_email($e); }
function kb_user_by_id($id){ return kb_aff_by_id($id); }

// ---- persistent login ("remember me") ----
// Returns the logged-in user: from the session, or re-established from the
// long-lived remember cookie (so login survives closing the browser).
function kb_auth_user(){
  $u = null;
  if (!empty($_SESSION['kb_user'])) $u = kb_user_by_id($_SESSION['kb_user']);
  if (!$u) { $u = kb_try_remember(); if ($u) $_SESSION['kb_user'] = $u['id']; }
  if (!$u) return null;
  kb_track_device($u['id']);
  return $u;
}

function kb_try_remember(){
  $c = $_COOKIE['kb_remember'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $c)) return null;
  $st = kb_db()->prepare("SELECT user_id FROM remember_tokens WHERE token_hash=? AND expires_at > datetime('now','localtime')");
  $st->execute([hash('sha256', $c)]);
  $row = $st->fetch();
  return $row ? kb_user_by_id($row['user_id']) : null;
}
function kb_remember_login($userId){
  $tok = bin2hex(random_bytes(32));
  kb_db()->prepare("INSERT INTO remember_tokens(user_id,token_hash,expires_at) VALUES(?,?,datetime('now','localtime','+60 days'))")
         ->execute([$userId, hash('sha256', $tok)]);
  setcookie('kb_remember', $tok, ['expires'=>time()+60*86400, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
}
function kb_forget(){
  $c = $_COOKIE['kb_remember'] ?? '';
  if ($c !== '') { try { kb_db()->prepare("DELETE FROM remember_tokens WHERE token_hash=?")->execute([hash('sha256',$c)]); } catch (Exception $e) {} }
  setcookie('kb_remember', '', ['expires'=>time()-3600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
}

// ---- device + IP (anti self-referral) ----
// Long-lived random browser id in the kb_dev cookie (32 hex chars). Created on first use.
function kb_device_id(){
  static $dev = null;
  if ($dev !== null) return $dev;
  $c = strtolower((string)($_COOKIE['kb_dev'] ?? ''));
  if (preg_match('/^[a-f0-9]{32}$/', $c)) return $dev = $c;
  $dev = bin2hex(random_bytes(16));
  if (!headers_sent()) setcookie('kb_dev', $dev, ['expires'=>time()+365*86400, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
  $_COOKIE['kb_dev'] = $dev;
  return $dev;
}
// The connecting IP (REMOTE_ADDR only — forwarded headers are client-controlled).
function kb_client_ip(){
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}
// Remember which device/IP an account uses — at most one write per hour per (user,dev,ip).
function kb_track_device($userId){
  if (!$userId) return;
  try {
    $dev = kb_device_id(); $ip = kb_client_ip();
    $key = substr(sha1($userId . '|' . $dev . '|' . $ip), 0, 16);
    $act = (session_status() === PHP_SESSION_ACTIVE);
    if ($act && !empty($_SESSION['kb_dv']) && is_array($_SESSION['kb_dv'])
        && ($_SESSION['kb_dv'][0] ?? '') === $key && (int)($_SESSION['kb_dv'][1] ?? 0) > time() - 3600) return;
    $db = kb_db();
    $st = $db->prepare("SELECT id FROM user_devices WHERE user_id=? AND dev=? AND ip=? LIMIT 1");
    $st->execute([$userId, $dev, $ip]);
    $row = $st->fetch();
    if ($row) {
      $db->prepare("UPDATE user_devices SET last_seen=datetime('now','localtime') WHERE id=? AND last_seen < datetime('now','localtime','-1 hour')")
         ->execute([$row['id']]);
    } else {
      $db->prepare("INSERT INTO user_devices(user_id,dev,ip) VALUES(?,?,?)")->execute([$userId, $dev, $ip]);
    }
    if ($act) $_SESSION['kb_dv'] = [$key, time()];
  } catch (Exception $e) {}
}

// Where to go after login: only a same-site path ("/account/", "/#contact"), never a URL.
function kb_safe_next($n){
  if (!is_string($n) || $n === '' || strlen($n) > 200) return '';
  if (preg_match('/[\x00-\x1f\x7f]/', $n)) return '';          // CR/LF/NUL/tab (browsers drop tabs → "//")
  if ($n[0] !== '/') return '';                                  // must be a path
  if (isset($n[1]) && ($n[1] === '/' || $n[1] === '\\')) return ''; // "//evil" or "/\evil" = other host
  $p = parse_url($n);
  if ($p === false || isset($p['scheme']) || isset($p['host'])) return ''; // no scheme / host
  return $n;
}

// ---- tickets ↔ accounts + notifications ----
// The admin's own account row (for admin-facing notifications).
function kb_admin_user(){
  $st = kb_db()->prepare("SELECT * FROM affiliates WHERE lower(email)=? LIMIT 1");
  $st->execute([kb_admin_email()]);
  return $st->fetch();
}
// Attach any un-owned tickets that share this user's email to their account,
// so their conversation shows up even if they contacted us before signing up.
function kb_link_tickets($user){
  if (empty($user['id']) || empty($user['email'])) return;
  try { kb_db()->prepare("UPDATE tickets SET user_id=? WHERE (user_id IS NULL OR user_id=0) AND lower(email)=lower(?)")
               ->execute([$user['id'], $user['email']]); } catch (Exception $e) {}
}
function kb_notify($userId, $ticketId, $kind, $body, $link=''){
  if (!$userId) return;
  try { kb_db()->prepare("INSERT INTO notifications(user_id,ticket_id,kind,body,link) VALUES(?,?,?,?,?)")
               ->execute([$userId, $ticketId, $kind, $body, $link]); } catch (Exception $e) {}
}
function kb_unseen_count($userId){
  if (!$userId) return 0;
  $st = kb_db()->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND seen=0");
  $st->execute([$userId]);
  $r = $st->fetch();
  return (int) ($r['c'] ?? 0);
}
// The account that owns a ticket (linked user_id, else matched by email).
function kb_ticket_owner($ticket){
  if (!empty($ticket['user_id'])) return kb_user_by_id($ticket['user_id']);
  if (!empty($ticket['email'])) return kb_user_by_email($ticket['email']);
  return null;
}

// Google OAuth credentials — from admin settings first, then optional config.php constants.
function kb_google_client_id() {
  $v = kb_setting_get('google_client_id'); if ($v) return $v;
  return isset($GLOBALS['KB_GOOGLE_CLIENT_ID']) ? $GLOBALS['KB_GOOGLE_CLIENT_ID'] : '';
}
function kb_google_client_secret() {
  $v = kb_setting_get('google_client_secret'); if ($v) return $v;
  return isset($GLOBALS['KB_GOOGLE_CLIENT_SECRET']) ? $GLOBALS['KB_GOOGLE_CLIENT_SECRET'] : '';
}

// ===================== Affiliate ranks (patentes) =====================
// Bronze → Prata → Gold → Platina. A partner earns 1 point per referred client the
// admin marks Paid; the rank follows the points. Each rank pays an APPROXIMATE % of the
// site build price, quoted as "~X%" inside a band, because payouts are converted USD→BRL
// (Pix) at the day's rate. Rank thresholds (min points) and each rank's % are editable in
// the admin Settings (tier_<key>_min / tier_<key>_pct); the ± band below is fixed and is
// what the Affiliate Agreement discloses.
function kb_tiers() {
  // key => [label, emoji, default min points, default ~%, [band low, band high]]
  $def = [
    'bronze'  => ['Bronze',  '🥉', 0,   5.0, [3, 7]],
    'prata'   => ['Prata',   '🥈', 5,  10.0, [8, 12]],
    'gold'    => ['Gold',    '🥇', 10, 15.0, [13, 17]],
    'platina' => ['Platina', '💎', 25, 20.0, [18, 22]],
  ];
  foreach ($def as $k => &$t) {
    $mp = kb_setting_get('tier_' . $k . '_min'); if ($mp !== null && $mp !== '') $t[2] = max(0, (int)$mp);
    $pc = kb_setting_get('tier_' . $k . '_pct'); if ($pc !== null && $pc !== '') $t[3] = (float)$pc;
  }
  unset($t);
  return $def;
}
function kb_tier_keys() { return array_keys(kb_tiers()); }
function kb_tier_info($key) { $t = kb_tiers(); return $t[$key] ?? $t['bronze']; }
function kb_tier_label($key) { $t = kb_tier_info($key); return $t[1] . ' ' . $t[0]; }
function kb_tier_pct($key) { $t = kb_tier_info($key); return (float)$t[3]; }
// The rank a given number of points reaches (highest threshold satisfied).
function kb_tier_for_points($points) {
  $best = 'bronze';
  foreach (kb_tiers() as $k => $t) { if ((int)$points >= (int)$t[2]) $best = $k; }
  return $best;
}
// Points = referred clients marked Paid (voided referrals never count).
function kb_aff_points($affId) {
  if (!$affId) return 0;
  try {
    $st = kb_db()->prepare("SELECT COUNT(*) c FROM tickets t JOIN affiliates a ON a.code=t.ref_code
                            WHERE a.id=? AND t.paid=1 AND (t.comm_void IS NULL OR t.comm_void=0)");
    $st->execute([$affId]);
    $r = $st->fetch();
    return (int)($r['c'] ?? 0);
  } catch (Exception $e) { return 0; }
}
// A partner's current rank: an admin override wins, otherwise it's earned by points.
function kb_aff_tier($aff) {
  if (!$aff) return 'bronze';
  $ov = $aff['tier_override'] ?? '';
  if ($ov !== '' && in_array($ov, kb_tier_keys(), true)) return $ov;
  return kb_tier_for_points(kb_aff_points((int)$aff['id']));
}
function kb_aff_pct($aff) { return kb_tier_pct(kb_aff_tier($aff)); }
// Next rank up (key, points still needed), or null at the top.
function kb_tier_next($aff) {
  $cur = kb_aff_tier($aff);
  $pts = kb_aff_points((int)($aff['id'] ?? 0));
  $keys = kb_tier_keys();
  $i = array_search($cur, $keys, true);
  if ($i === false || $i >= count($keys) - 1) return null;
  $nk = $keys[$i + 1]; $ni = kb_tier_info($nk);
  return ['key' => $nk, 'need' => max(0, (int)$ni[2] - (int)$pts), 'min' => (int)$ni[2]];
}

// Human-readable payout line for the admin "Pay to" column, from the Pix fields.
function kb_pix_summary($type, $key, $cpf) {
  $labels = ['cpf'=>'CPF', 'email'=>'E-mail', 'phone'=>'Telefone', 'random'=>'Chave aleatória'];
  $t = $labels[$type] ?? 'Pix';
  $out = 'Pix (' . $t . '): ' . trim((string)$key);
  if (trim((string)$cpf) !== '') $out .= "\nCPF: " . trim((string)$cpf);
  return $out;
}

// Legacy single-rate accessor (kept as the default fallback; the tiers above are the real source).
function kb_commission_pct() {
  $v = kb_setting_get('commission_pct');
  return ($v === null || $v === '') ? 20 : (float)$v;
}
// The % that applies to one referred ticket: a rate locked at payment wins; else the
// referring partner's live rank %; else the legacy setting.
function kb_ticket_pct($t) {
  if (isset($t['comm_pct']) && $t['comm_pct'] !== null && $t['comm_pct'] !== '') return (float)$t['comm_pct'];
  if (!empty($t['ref_code'])) { $a = kb_aff_by_code($t['ref_code']); if ($a) return kb_aff_pct($a); }
  return kb_commission_pct();
}
// Commission a referred ticket earns: pct × site price (never monthly fees). 0 when voided / no referrer.
// Commissions already earned are never reduced by a later rate change (locked comm_pct — Agreement §8).
function kb_ticket_commission($t, $pct = null) {
  if (empty($t['ref_code']) || !empty($t['comm_void'])) return 0.0;
  if (isset($t['comm_pct']) && $t['comm_pct'] !== null && $t['comm_pct'] !== '') $pct = (float)$t['comm_pct'];
  elseif ($pct === null) $pct = kb_ticket_pct($t);
  return round((float)($t['value'] ?? 0) * $pct / 100, 2);
}

// ===================== Moderators (admins) + ratings =====================
// The moderators are the owner + any extra-admin emails. kb_mods() returns them as account
// rows when the email has an account (so it can be rated), flagged 'no_account' otherwise.
function kb_mods() {
  $emails = array_merge([kb_admin_email()], kb_extra_admins());
  $out = []; $seen = [];
  foreach ($emails as $e) {
    $e = strtolower(trim($e));
    if ($e === '' || isset($seen[$e])) continue;
    $seen[$e] = 1;
    $u = kb_aff_by_email($e);
    if ($u) { $u['is_owner'] = ($e === kb_admin_email()); $out[] = $u; }
    else    { $out[] = ['id'=>0, 'name'=>$e, 'email'=>$e, 'is_owner'=>($e === kb_admin_email()), 'no_account'=>true]; }
  }
  return $out;
}
// Add / remove an extra admin (moderator) by email. Stored even without an account yet,
// so the grant takes effect the moment they sign up with that email.
function kb_add_admin_email($email) {
  $e = strtolower(trim($email));
  if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) return false;
  if ($e === kb_admin_email()) return true;
  $list = kb_extra_admins();
  if (!in_array($e, $list, true)) { $list[] = $e; kb_setting_set('extra_admins', implode(',', $list)); }
  return true;
}
function kb_remove_admin_email($email) {
  $e = strtolower(trim($email));
  kb_setting_set('extra_admins', implode(',', array_values(array_filter(kb_extra_admins(), fn($x) => $x !== $e))));
}
// The moderator credited for a ticket: whoever posted the most recent admin reply, else the owner.
function kb_ticket_mod_id($ticketId) {
  try {
    $st = kb_db()->prepare("SELECT mod_id FROM replies WHERE ticket_id=? AND who='admin' AND mod_id IS NOT NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$ticketId]);
    $r = $st->fetch();
    if ($r && !empty($r['mod_id'])) return (int)$r['mod_id'];
  } catch (Exception $e) {}
  $adm = kb_admin_user();
  return $adm ? (int)$adm['id'] : 0;
}
// [count, average] of a moderator's ratings.
function kb_mod_rating_stats($modId) {
  if (!$modId) return [0, 0.0];
  try {
    $st = kb_db()->prepare("SELECT COUNT(*) c, AVG(stars) a FROM mod_ratings WHERE mod_id=?");
    $st->execute([$modId]);
    $r = $st->fetch();
    return [(int)($r['c'] ?? 0), round((float)($r['a'] ?? 0), 2)];
  } catch (Exception $e) { return [0, 0.0]; }
}

// Plans offered on the site. Form field "plan"; tickets.plan holds the key.
function kb_plans() {
  return [
    'build'   => ['label'=>'Website',           'setup'=>150, 'monthly'=>0],
    'hosting' => ['label'=>'Website + Hosting', 'setup'=>150, 'monthly'=>10],
    'care'    => ['label'=>'Website + Care',    'setup'=>150, 'monthly'=>20],
  ];
}
function kb_plan($key) { $p = kb_plans(); return ($key !== null && isset($p[$key])) ? $p[$key] : null; }
// "$150 one-time" / "$150 + $10/month"
function kb_plan_price($key) {
  $p = kb_plan($key);
  if (!$p) return '';
  return '$' . $p['setup'] . ($p['monthly'] > 0 ? ' + $' . $p['monthly'] . '/month' : ' one-time');
}

// Reasons a ticket's referring partner looks like the buyer themself ([] = nothing suspicious).
function kb_selfref_flags($t) {
  if (empty($t['ref_code'])) return [];
  $a = kb_aff_by_code($t['ref_code']);
  if (!$a) return [];
  $db = kb_db(); $out = [];
  $te = strtolower(trim((string)($t['email'] ?? '')));
  try {
    if (!empty($t['user_id']) && (int)$t['user_id'] === (int)$a['id']) $out[] = 'The request was sent from the partner\'s own account.';
    elseif ($te !== '' && $te === strtolower(trim((string)$a['email']))) $out[] = 'The client email is the partner\'s own email.';
    if (!empty($t['dev'])) {
      $st = $db->prepare("SELECT 1 FROM user_devices WHERE user_id=? AND dev=? LIMIT 1");
      $st->execute([$a['id'], $t['dev']]);
      if ($st->fetch()) $out[] = 'The request came from a browser/device the partner has logged in with.';
    }
    if (!empty($t['ip'])) {
      $st = $db->prepare("SELECT MAX(last_seen) m FROM user_devices WHERE user_id=? AND ip=? AND last_seen >= datetime('now','localtime','-180 days')");
      $st->execute([$a['id'], $t['ip']]);
      $r = $st->fetch();
      if ($r && $r['m']) $out[] = 'The request came from an IP address (' . $t['ip'] . ') the partner used (last seen ' . substr($r['m'], 0, 10) . ').';
    }
    if (!empty($t['user_id']) && (int)$t['user_id'] !== (int)$a['id']) {
      $st = $db->prepare("SELECT 1 FROM user_devices c JOIN user_devices p ON p.dev=c.dev WHERE c.user_id=? AND p.user_id=? LIMIT 1");
      $st->execute([$t['user_id'], $a['id']]);
      if ($st->fetch()) $out[] = 'The client\'s account and the partner\'s account have logged in from the same browser/device.';
    }
    if ($te !== '' && stripos((string)($a['payout'] ?? ''), $te) !== false) $out[] = 'The partner\'s payout details contain the client\'s email.';
  } catch (Exception $e) {}
  return $out;
}

// Short, unshoulder-surfable referral code, e.g. "KB7F3A9C".
function kb_aff_code() {
  do {
    $c = 'KB' . strtoupper(bin2hex(random_bytes(3))); // KB + 6 hex
    $st = kb_db()->prepare("SELECT 1 FROM affiliates WHERE code=?");
    $st->execute([$c]);
  } while ($st->fetch());
  return $c;
}
function kb_aff_by_email($e) {
  $st = kb_db()->prepare("SELECT * FROM affiliates WHERE email=?");
  $st->execute([strtolower(trim($e))]);
  return $st->fetch();
}
function kb_aff_by_code($c) {
  $st = kb_db()->prepare("SELECT * FROM affiliates WHERE code=?");
  $st->execute([$c]);
  return $st->fetch();
}
function kb_aff_by_id($id) {
  $st = kb_db()->prepare("SELECT * FROM affiliates WHERE id=?");
  $st->execute([$id]);
  return $st->fetch();
}

// Pipeline stages, in order, with labels. 'status' column holds the key.
function kb_stages() {
  return [
    'new'       => 'New',
    'replied'   => 'Replied',
    'proposal'  => 'Proposal sent',
    'paid'      => 'Paid',
    'delivered' => 'Delivered',
    'closed'    => 'Closed',
    'lost'      => 'Lost',
  ];
}

function kb_setting_get($k) {
  $st = kb_db()->prepare("SELECT v FROM settings WHERE k=?");
  $st->execute([$k]);
  $r = $st->fetch();
  return $r ? $r['v'] : null;
}
function kb_setting_set($k, $v) {
  $st = kb_db()->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
  $st->execute([$k, $v]);
}

function kb_token() {
  return strtoupper(bin2hex(random_bytes(7))); // 14 hex chars, e.g. JK432F0943J23F
}

function kb_ticket_by_token($t) {
  $st = kb_db()->prepare("SELECT * FROM tickets WHERE token=?");
  $st->execute([$t]);
  return $st->fetch();
}

// POST a JSON string to a URL (Discord webhook). Best-effort, never throws.
function kb_post_json($url, $json) {
  if (!$url) return;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch); curl_close($ch);
  } else {
    @file_get_contents($url, false, stream_context_create([
      'http' => ['method'=>'POST','header'=>'Content-Type: application/json','content'=>$json,'timeout'=>8,'ignore_errors'=>true],
    ]));
  }
}

// A trimmed POST string ('' for missing or array values), capped at $max characters.
function kb_post_str($k, $max = 0) {
  $v = $_POST[$k] ?? '';
  $v = is_string($v) ? trim($v) : '';
  return $max > 0 ? mb_substr($v, 0, $max) : $v;
}

// Send the JSON answer now and keep running (slow mail/Discord calls happen after the
// browser already has its reply). Releases the session lock first.
function kb_respond_early($payload) {
  echo json_encode($payload);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  ignore_user_abort(true);
  if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
  elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
}

// Simple file-based rate limit. Returns false when $key exceeded $max hits in $win seconds.
function kb_rate_ok($key, $max, $win) {
  $dir = __DIR__ . '/kbdata/rl';
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  $f = $dir . '/' . substr(hash('sha256', $key), 0, 32) . '.json';
  $now = time(); $hits = [];
  if (is_file($f)) { $d = json_decode(@file_get_contents($f), true); if (is_array($d)) $hits = $d; }
  $hits = array_values(array_filter($hits, function($t) use($now,$win){ return $t > $now - $win; }));
  if (count($hits) >= $max) return false;
  $hits[] = $now;
  @file_put_contents($f, json_encode($hits));
  return true;
}

// Plain-text email from the studio address. Returns mail() result.
function kb_mail($to, $subject, $body, $replyTo = 'kaua12131415a@gmail.com') {
  $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'kbsites.com.br');
  $hd  = "From: KB Sites <noreply@$host>\r\n";
  if ($replyTo) $hd .= "Reply-To: " . kb_hdr($replyTo) . "\r\n";
  $hd .= "Content-Type: text/plain; charset=UTF-8\r\n";
  return @mail(kb_hdr($to), kb_hdr($subject), $body, $hd);
}
