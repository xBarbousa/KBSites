<?php
// Unified account portal for KB Sites — one login for everyone; the menu adapts to the role.
require __DIR__ . '/../db.php';
@include __DIR__ . '/../config.php';
kb_session_start();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ $n=(float)$n; if($n<=0) return '$0'; return '$'.($n==floor($n)?number_format($n,0):number_format($n,2)); }

$SITE = isset($KB_SITE) && $KB_SITE ? rtrim($KB_SITE,'/') : ('https://' . preg_replace('/^www\./','',$_SERVER['HTTP_HOST'] ?? 'kbsites.com.br'));
$PCT  = kb_commission_pct();
$DISCORD = $KB_DISCORD_WEBHOOK ?? '';
$action = $_POST['action'] ?? '';
$view   = preg_replace('/[^a-z]/','',$_GET['view'] ?? '');
$err = ''; $notice = '';

if (isset($_GET['logout'])) { kb_forget(); session_destroy(); header('Location: index.php'); exit; }

// ---------- where to land after logging in (?next=/path — same-site paths only) ----------
// Kept in the session so it survives switching between sign up / log in / Google / the code step.
$nextGiven = false;
if (isset($_GET['next'])) {
  $nx = (is_string($_GET['next']) && function_exists('kb_safe_next')) ? kb_safe_next($_GET['next']) : '';
  if ($nx !== '') { $_SESSION['kb_next'] = $nx; $nextGiven = true; } else { unset($_SESSION['kb_next']); }
}
// Every successful login ends here: go back to ?next once, else the account home.
function acct_login_redirect() {
  $to = '/account/';
  if (!empty($_SESSION['kb_next']) && function_exists('kb_safe_next')) {
    $n = kb_safe_next((string) $_SESSION['kb_next']);
    if ($n !== '') $to = $n;
  }
  unset($_SESSION['kb_next']);
  header('Location: ' . $to); exit;
}
// Branded emails (mailer.php). Each kind has its own on/off switch in the admin; never let mail break a login.
function acct_mail($to, $subject, $kind, $vars) {
  if (!function_exists('kb_mail_html')) return;
  try { kb_mail_html($to, $subject, $kind, $vars); } catch (Throwable $e) {}
}

// ---------- register (email + password) ----------
if ($action === 'register') {
  $name  = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = $_POST['password'] ?? '';
  if ($name==='' || $email==='' || $pass==='') { $err='Please fill in your name, email and password.'; }
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err='Please enter a valid email address.'; }
  elseif (strlen($pass) < 6) { $err='Password must be at least 6 characters.'; }
  elseif (kb_user_by_email($email)) { $err='An account with this email already exists. Try logging in.'; }
  else {
    $vcode = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    kb_db()->prepare("INSERT INTO affiliates(name,email,pass_hash,verified,verify_code,is_affiliate) VALUES(?,?,?,0,?,0)")
           ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$vcode]);
    $u = kb_user_by_email($email);
    $_SESSION['kb_pending'] = $u['id'];
    $_SESSION['vcode_at'] = time(); $_SESSION['vtries'] = 0; unset($_SESSION['vblock']);
    kb_mail($email, 'Your KB Sites verification code', "Welcome to KB Sites!\n\nYour verification code is: $vcode\n\nEnter it to activate your account (valid for 15 minutes).\n\n— KB Sites");
    header('Location: /account/'); exit;
  }
}
// ---------- verify (rate-limited + 15-min expiry) ----------
if ($action === 'verify' && !empty($_SESSION['kb_pending'])) {
  $now = time();
  if (($_SESSION['vblock'] ?? 0) > $now) {
    $err = 'Too many attempts. Please wait a few minutes, then request a new code.';
  } elseif ($now - ($_SESSION['vcode_at'] ?? 0) > 900) {
    $err = 'Your code expired. Click "Resend code" to get a new one.';
  } else {
    $u = kb_user_by_id($_SESSION['kb_pending']);
    $code = preg_replace('/\D/','',$_POST['code'] ?? '');
    if ($u && $code!=='' && hash_equals((string)$u['verify_code'],$code)) {
      $firstTime = empty($u['verified']); // only a never-verified account counts as new (welcome email)
      kb_db()->prepare("UPDATE affiliates SET verified=1, verify_code=NULL WHERE id=?")->execute([$u['id']]);
      unset($_SESSION['kb_pending'], $_SESSION['vtries'], $_SESSION['vblock']); $_SESSION['kb_user']=$u['id'];
      kb_remember_login($u['id']);
      if ($firstTime) acct_mail($u['email'], null, 'welcome', ['name'=>$u['name'], 'pct'=>$PCT]);
      acct_login_redirect();
    } else {
      $_SESSION['vtries'] = ($_SESSION['vtries'] ?? 0) + 1;
      if ($_SESSION['vtries'] >= 5) { $_SESSION['vblock'] = $now + 900; $err = 'Too many wrong codes. Please wait 15 minutes, then request a new code.'; }
      else { $err = 'Wrong code. Check your email and try again.'; }
    }
  }
}
if ($action === 'resend' && !empty($_SESSION['kb_pending'])) {
  $u = kb_user_by_id($_SESSION['kb_pending']);
  if (!kb_rate_ok('resend_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 5, 900)) {
    $err = 'Too many code requests. Please wait a few minutes.';
  } elseif ($u) {
    $vcode=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    kb_db()->prepare("UPDATE affiliates SET verify_code=? WHERE id=?")->execute([$vcode,$u['id']]);
    $_SESSION['vcode_at'] = time(); $_SESSION['vtries'] = 0; unset($_SESSION['vblock']);
    kb_mail($u['email'],'Your KB Sites verification code',"Your new verification code is: $vcode (valid for 15 minutes)\n\n— KB Sites");
    $notice='A new code was sent to your email.';
  }
}
// ---------- login ----------
if ($action === 'login') {
  $u = kb_user_by_email($_POST['email'] ?? '');
  if ($u && empty($u['pass_hash']) && !empty($u['google_sub'])) { $err='This account uses "Continue with Google". Use the Google button.'; }
  elseif ($u && !empty($u['pass_hash']) && password_verify($_POST['password'] ?? '', $u['pass_hash'])) {
    if (($u['status'] ?? '')==='suspended') { $err='This account is suspended. Contact us.'; }
    elseif (!$u['verified']) { $_SESSION['kb_pending']=$u['id']; $_SESSION['vcode_at']=time(); $_SESSION['vtries']=0; header('Location: /account/'); exit; }
    else { $_SESSION['kb_user']=$u['id']; kb_remember_login($u['id']); acct_login_redirect(); }
  } else { $err='Wrong email or password.'; }
}
$gmsg = ['notcfg'=>'Google sign-in isn\'t set up yet.','denied'=>'Google sign-in was cancelled.','state'=>'Session expired, please try again.','token'=>'Google sign-in failed, please try again.','profile'=>'Could not read your Google profile.','suspended'=>'This account is suspended.'];
if (!empty($_GET['gerr']) && isset($gmsg[$_GET['gerr']])) $err = $gmsg[$_GET['gerr']];

$me = kb_auth_user();
if ($me) kb_link_tickets($me); // attach tickets sent with this email to the account

// ---------- live chat API (JSON; the messages view polls this every ~2s) ----------
// Handled early and exits, so the poll never renders the whole page. Client side only.
if ($me && (($_GET['chat'] ?? '') !== '' || in_array($action, ['csend','ctyping','cread','crate'], true))) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  kb_flush_notify_queue(); // an active browser drives the "anti-perdido" escalations
  $tkn  = preg_replace('/[^A-Za-z0-9]/', '', (string)($_REQUEST['t'] ?? ''));
  $tk   = $tkn !== '' ? kb_ticket_by_token($tkn) : null;
  $owns = $tk && ((($tk['user_id'] ?? null) == $me['id']) || strtolower((string)$tk['email']) === strtolower((string)$me['email']));
  if (!$owns) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }
  $db = kb_db();

  if ($action === 'csend') {                       // client sends a message
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    if (mb_strlen($body) > 8000) $body = mb_substr($body, 0, 8000);
    if (!kb_rate_ok('csend_' . $me['id'], 40, 120)) { echo json_encode(['ok'=>false,'error'=>'slow']); exit; }
    $db->prepare("INSERT INTO replies(ticket_id,body,who) VALUES(?,?,'client')")->execute([$tk['id'], $body]);
    $rid = (int)$db->lastInsertId();
    $db->prepare("UPDATE tickets SET status=CASE WHEN status='closed' THEN 'replied' ELSE status END WHERE id=?")->execute([$tk['id']]);
    $db->prepare("DELETE FROM chat_typing WHERE ticket_id=? AND side='client'")->execute([$tk['id']]);
    kb_respond_early(['ok'=>true, 'id'=>$rid, 'at'=>substr(date('Y-m-d H:i:s'), 0, 16)]);
    $adm = kb_admin_user(); if ($adm) kb_notify($adm['id'], $tk['id'], 'client_reply', $me['name'] . ' replied on their ticket', '/ticket/' . $tk['token']);
    kb_enqueue_notify($tk['id'], $rid, 'admin');  // email + Discord fire only if the admin doesn't read it in ~30s
    exit;
  }

  if ($action === 'ctyping') {                      // "client is typing…"
    if (!empty($_POST['on'])) {
      $db->prepare("INSERT INTO chat_typing(ticket_id,side,name,user_id,expires_at) VALUES(?,'client',?,?,datetime('now','localtime','+6 seconds'))
                    ON CONFLICT(ticket_id,side) DO UPDATE SET name=excluded.name,user_id=excluded.user_id,expires_at=excluded.expires_at")
         ->execute([$tk['id'], (string)$me['name'], $me['id']]);
    } else {
      $db->prepare("DELETE FROM chat_typing WHERE ticket_id=? AND side='client'")->execute([$tk['id']]);
    }
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'cread') {                         // client has read up to reply #upto
    $upto = (int)($_POST['upto'] ?? 0);
    $db->prepare("INSERT INTO chat_reads(ticket_id,side,last_read_id,updated_at) VALUES(?,'client',?,datetime('now','localtime'))
                  ON CONFLICT(ticket_id,side) DO UPDATE SET last_read_id=MAX(last_read_id,excluded.last_read_id),updated_at=excluded.updated_at")
       ->execute([$tk['id'], $upto]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'crate') {                         // rate the moderator who handled this ticket
    $stars   = max(1, min(5, (int)($_POST['stars'] ?? 0)));
    $comment = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 500);
    $has = $db->prepare("SELECT 1 FROM replies WHERE ticket_id=? AND who='admin' LIMIT 1"); $has->execute([$tk['id']]);
    if (!$has->fetch()) { echo json_encode(['ok'=>false,'error'=>'noservice']); exit; }
    $modId = kb_ticket_mod_id($tk['id']);
    $db->prepare("INSERT INTO mod_ratings(ticket_id,mod_id,client_id,stars,comment) VALUES(?,?,?,?,?)
                  ON CONFLICT(ticket_id,client_id) DO UPDATE SET mod_id=excluded.mod_id,stars=excluded.stars,comment=excluded.comment,updated_at=datetime('now','localtime')")
       ->execute([$tk['id'], $modId, $me['id'], $stars, $comment]);
    echo json_encode(['ok'=>true, 'stars'=>$stars]); exit;
  }

  // ---- default: feed (new messages since ?after, plus typing + read state) ----
  $after = (int)($_GET['after'] ?? 0);
  $q = $db->prepare("SELECT id,who,body,created_at FROM replies WHERE ticket_id=? AND id>? ORDER BY id ASC");
  $q->execute([$tk['id'], $after]);
  $msgs = [];
  foreach ($q->fetchAll() as $r) {
    $msgs[] = ['id'=>(int)$r['id'], 'who'=>(($r['who'] ?? 'admin')==='client'?'you':'them'), 'body'=>(string)$r['body'], 'at'=>substr((string)$r['created_at'], 0, 16)];
  }
  $tp = $db->prepare("SELECT 1 FROM chat_typing WHERE ticket_id=? AND side='admin' AND expires_at>datetime('now','localtime')"); $tp->execute([$tk['id']]);
  $rr = $db->prepare("SELECT last_read_id FROM chat_reads WHERE ticket_id=? AND side='admin'"); $rr->execute([$tk['id']]);
  $rrow = $rr->fetch();
  echo json_encode(['ok'=>true, 'msgs'=>$msgs, 'typing'=>(bool)$tp->fetch(), 'read_id'=>$rrow ? (int)$rrow['last_read_id'] : 0]);
  exit;
}

// already signed in and sent here with ?next (e.g. from the request form)? go straight back.
if ($me && $nextGiven && $action === '') acct_login_redirect();

// ---------- logged-in actions ----------
$meAdmin = $me && kb_is_admin($me); // the owner/admins never join the Partner Program
if ($me && !$meAdmin && $action === 'join') { // become an affiliate (Brazil only, paid by Pix)
  $agree   = !empty($_POST['agree']);
  $br      = !empty($_POST['br']);
  $pixType = preg_replace('/[^a-z]/', '', strtolower((string)($_POST['pix_type'] ?? '')));
  $pixKey  = trim((string)($_POST['pix_key'] ?? ''));
  $cpf     = trim((string)($_POST['cpf'] ?? ''));
  if (!$agree)                          { $err = 'Please accept the Affiliate Agreement to join.'; }
  elseif (!$br)                         { $err = 'The Partner Program is for residents of Brazil only — please confirm you live in Brazil.'; }
  elseif (!in_array($pixType, ['cpf','email','phone','random'], true) || $pixKey === '') { $err = 'Please add a valid Pix key — that\'s the only way commissions are paid.'; }
  elseif ($cpf === '')                  { $err = 'Please add your CPF (needed for the Pix payout).'; }
  else {
    $code = $me['code'] ?: kb_aff_code();
    kb_db()->prepare("UPDATE affiliates SET is_affiliate=1, code=?, country='BR', pix_type=?, pix_key=?, cpf=?, payout=?, agreed_at=datetime('now','localtime') WHERE id=?")
           ->execute([$code, $pixType, $pixKey, $cpf, kb_pix_summary($pixType, $pixKey, $cpf), $me['id']]);
    acct_mail($me['email'], null, 'partner_welcome', ['name'=>$me['name'], 'code'=>$code, 'link'=>$SITE.'/?ref='.$code, 'pct'=>kb_tier_pct('bronze')]);
    header('Location: /account/partner?ok=joined'); exit;
  }
}
if ($me && $action === 'rename') {
  $nm=trim($_POST['name'] ?? ''); if($nm!=='') kb_db()->prepare("UPDATE affiliates SET name=? WHERE id=?")->execute([$nm,$me['id']]);
  header('Location: /account/settings?ok=name'); exit;
}
if ($me && !$meAdmin && $action === 'payout') {
  $pixType = preg_replace('/[^a-z]/', '', strtolower((string)($_POST['pix_type'] ?? '')));
  $pixKey  = trim((string)($_POST['pix_key'] ?? ''));
  $cpf     = trim((string)($_POST['cpf'] ?? ''));
  if (!in_array($pixType, ['cpf','email','phone','random'], true) || $pixKey === '') { $err = 'Please choose your Pix key type and enter the key.'; }
  elseif ($cpf === '') { $err = 'Please add your CPF.'; }
  else {
    kb_db()->prepare("UPDATE affiliates SET pix_type=?, pix_key=?, cpf=?, payout=?, country='BR' WHERE id=?")
           ->execute([$pixType, $pixKey, $cpf, kb_pix_summary($pixType, $pixKey, $cpf), $me['id']]);
    header('Location: /account/partner?ok=payout'); exit;
  }
}
if ($me && $action === 'password') {
  if (!empty($me['pass_hash']) && !password_verify($_POST['current'] ?? '', $me['pass_hash'])) { $err='Current password is wrong.'; }
  elseif (strlen($_POST['newpass'] ?? '')<6) { $err='New password must be at least 6 characters.'; }
  else { kb_db()->prepare("UPDATE affiliates SET pass_hash=? WHERE id=?")->execute([password_hash($_POST['newpass'],PASSWORD_DEFAULT),$me['id']]);
         acct_mail($me['email'], null, 'password_changed', ['name'=>$me['name'], 'email'=>$me['email']]);
         header('Location: /account/settings?ok=pass'); exit; }
}
if ($me && $action === 'avatar') {
  $dir = __DIR__ . '/avatars';
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  $f = $_FILES['avatar'] ?? null;
  if ($f && $f['error']===UPLOAD_ERR_OK && $f['size']>0 && $f['size']<=3*1024*1024) {
    $info = @getimagesize($f['tmp_name']);
    $map  = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
    if ($info && isset($map[$info[2]])) {
      $ext = $map[$info[2]];
      foreach (['jpg','png','gif','webp'] as $e) { @unlink("$dir/{$me['id']}.$e"); }
      if (@move_uploaded_file($f['tmp_name'], "$dir/{$me['id']}.$ext")) {
        kb_db()->prepare("UPDATE affiliates SET photo=? WHERE id=?")->execute(['/account/avatars/'.$me['id'].'.'.$ext.'?v='.time(), $me['id']]);
        header('Location: /account/settings?ok=photo'); exit;
      }
    }
    $err = 'Please upload a JPG, PNG, GIF or WebP image (max 3MB).';
  } else { $err = 'Could not read that image (max 3MB).'; }
}
if ($me && $action === 'creply') { // client replies on their own ticket
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  $body = trim($_POST['body'] ?? '');
  $owns = $tk && (($tk['user_id']??null)==$me['id'] || strtolower($tk['email'])===strtolower($me['email']));
  if ($owns && $body!=='') {
    kb_db()->prepare("INSERT INTO replies(ticket_id,body,who) VALUES(?,?,'client')")->execute([$tk['id'],$body]);
    $rid = (int) kb_db()->lastInsertId();
    kb_db()->prepare("UPDATE tickets SET status=CASE WHEN status='closed' THEN 'replied' ELSE status END WHERE id=?")->execute([$tk['id']]);
    $adm = kb_admin_user(); if ($adm) kb_notify($adm['id'], $tk['id'], 'client_reply', $me['name'].' replied on their ticket', '/ticket/'.$tk['token']);
    kb_enqueue_notify($tk['id'], $rid, 'admin'); // email + Discord fire only if the admin doesn't read it in ~30s
    header('Location: /account/messages/'.$tk['token'].'?ok=sent'); exit;
  } else { $err='Could not post your reply.'; }
}
if ($me && isset($_GET['seen_all'])) { kb_db()->prepare("UPDATE notifications SET seen=1 WHERE user_id=?")->execute([$me['id']]); header('Location: /account/'); exit; }
if ($me) $me = kb_user_by_id($me['id']);
if (($_GET['ok'] ?? '')==='name')   $notice='Name updated.';
if (($_GET['ok'] ?? '')==='payout') $notice='Payout details saved.';
if (($_GET['ok'] ?? '')==='pass')   $notice='Password changed.';
if (($_GET['ok'] ?? '')==='joined') $notice='You\'re in! Here\'s your referral link.';
if (($_GET['ok'] ?? '')==='sent')   $notice='Your reply was sent.';
if (($_GET['ok'] ?? '')==='photo')  $notice='Photo updated.';

$isAdmin  = kb_is_admin($me);
$pending  = (!$me && !empty($_SESSION['kb_pending'])) ? kb_user_by_id($_SESSION['kb_pending']) : null;
$googleOn = kb_google_client_id() && kb_google_client_secret();
$GBTN = $googleOn
  ? '<a class="gbtn" href="/account/google.php?go=1"><svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> Continue with Google</a><div class="orline">or</div>'
  : '';

// shown on the login / sign-up cards when we'll send them back somewhere (e.g. their unsent request)
$NEXTNOTE = (!$me && !empty($_SESSION['kb_next']))
  ? '<p class="meta" style="margin:-4px 0 14px">After signing in you\'ll go right back to where you were.</p>' : '';
// plan chosen on the request form (tickets.plan) -> label
$PLANS = function_exists('kb_plans') ? kb_plans() : [];
$STAGES = kb_stages();

// which tickets belong to this logged-in user (their "chat with the site")
$myTickets = [];
if ($me) { $q=kb_db()->prepare("SELECT * FROM tickets WHERE user_id=? OR lower(email)=lower(?) ORDER BY id DESC"); $q->execute([$me['id'],$me['email']]); $myTickets=$q->fetchAll(); }
$openTicket = null;
if ($me && !empty($_GET['t'])) { $tt=kb_ticket_by_token(preg_replace('/[^A-Za-z0-9]/','',$_GET['t'])); if($tt && (($tt['user_id']??null)==$me['id'] || strtolower($tt['email'])===strtolower($me['email']))) $openTicket=$tt; }
// notifications (the bell)
$notifs = []; $unseen = 0;
if ($me) {
  $nq = kb_db()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 12"); $nq->execute([$me['id']]); $notifs = $nq->fetchAll();
  $unseen = kb_unseen_count($me['id']);
  if ($openTicket) kb_db()->prepare("UPDATE notifications SET seen=1 WHERE user_id=? AND ticket_id=?")->execute([$me['id'],$openTicket['id']]);
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KB Sites — Account</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" href="/favicon.ico?v=2" sizes="32x32">
<link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png?v=2">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#080706">
<style>
  :root{--bg:#080706;--card:#141109;--line:#2c2417;--ink:#f5efe3;--muted:#a89c86;--gold:#d9b45a;--gold-lt:#f4dc93;--grad:linear-gradient(115deg,#f4dc93,#d9b45a 45%,#b5872f)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--gold-lt);text-decoration:none}a:hover{text-decoration:underline}
  .wrap{width:min(880px,93vw);margin:0 auto;padding:22px 0 90px}
  .top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
  .brand{display:block;line-height:0;text-decoration:none}.brand:hover{text-decoration:none}
  .brand img{height:50px;width:auto;display:block}
  @media(max-width:560px){.brand img{height:42px}}
  .btn{display:inline-flex;align-items:center;gap:7px;background:var(--grad);color:#1c1405;font-weight:700;border:0;border-radius:100px;padding:12px 22px;font-size:.92rem;cursor:pointer;font-family:inherit}
  .btn.ghost{background:transparent;color:var(--gold-lt);border:1px solid rgba(217,180,90,.4)}
  .btn.sm{padding:8px 15px;font-size:.83rem}
  .btn.block{width:100%;justify-content:center}
  .gbtn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;background:#fff;color:#1f1f1f;border:0;border-radius:100px;padding:12px 20px;font-size:.92rem;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
  .gbtn:hover{text-decoration:none;opacity:.92}.gbtn svg{width:18px;height:18px}
  .orline{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:.78rem;margin:16px 0;text-transform:uppercase;letter-spacing:.08em}
  .orline:before,.orline:after{content:"";flex:1;height:1px;background:var(--line)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:16px}
  label{display:block;font-size:.8rem;font-weight:600;margin:14px 0 6px;color:var(--muted)}
  input,textarea{width:100%;background:var(--bg);border:1px solid #5a4c33;border-radius:10px;padding:12px 14px;color:var(--ink);font-family:inherit;font-size:.95rem}
  input:focus,textarea:focus{outline:0;border-color:var(--gold)}
  textarea{min-height:90px;resize:vertical}
  .err{color:#e0906a;font-size:.88rem;margin:10px 0;background:rgba(224,144,106,.08);border:1px solid rgba(224,144,106,.25);border-radius:9px;padding:9px 12px}
  .ok{color:#8fd6a6;font-size:.88rem;margin:10px 0;background:rgba(143,214,166,.08);border:1px solid rgba(143,214,166,.25);border-radius:9px;padding:9px 12px}
  h1{font-family:'Playfair Display',serif;font-size:1.9rem;line-height:1.15;margin-bottom:8px}
  h2{font-family:'Playfair Display',serif;font-size:1.2rem;margin-bottom:12px;color:var(--gold-lt)}
  .lead{color:var(--muted);font-size:1rem;margin-bottom:16px}
  .meta{color:var(--muted);font-size:.85rem}
  .nav{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;margin-bottom:18px;-webkit-overflow-scrolling:touch}
  .nav a{white-space:nowrap;padding:9px 16px;border-radius:100px;border:1px solid var(--line);color:var(--muted);font-weight:600;font-size:.86rem;transition:color .2s,background .2s,border-color .2s,transform .12s}
  .nav a:hover{color:var(--gold-lt);border-color:rgba(217,180,90,.5)}
  .nav a:active{transform:scale(.95)}
  .nav a.on{background:var(--grad);color:#1c1405;border-color:transparent;box-shadow:0 6px 18px rgba(217,180,90,.22)}
  .nav a.on:hover{color:#1c1405}
  .nav a.adm{border-color:rgba(217,180,90,.5);color:var(--gold-lt)}
  @keyframes kbSwapIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  #app.kb-swap{animation:kbSwapIn .3s cubic-bezier(.22,1,.36,1)}
  @media(prefers-reduced-motion:reduce){#app.kb-swap{animation:none}}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px}
  .stat .n{font-size:1.6rem;font-weight:700;color:var(--gold-lt);line-height:1.1}
  .stat.green .n{color:#8fd6a6}.stat.amber .n{color:#e6c06a}
  .stat .l{color:var(--muted);font-size:.76rem;margin-top:4px;font-weight:600}
  .copy{display:flex;gap:8px}.copy input{flex:1;font-weight:600}
  .agree{display:flex;gap:9px;align-items:flex-start;margin-top:14px;font-size:.86rem;color:var(--muted)}.agree input{width:auto;margin-top:4px}
  .switch{margin-top:16px;font-size:.9rem;color:var(--muted);text-align:center}
  .tk{display:flex;justify-content:space-between;gap:12px;align-items:center;background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:13px 15px;margin-bottom:10px}
  .tk:hover{border-color:rgba(217,180,90,.4);text-decoration:none}
  .bubble{border-radius:12px;padding:12px 14px;margin:8px 0;max-width:85%;white-space:pre-wrap;font-size:.92rem}
  .bubble.them{background:var(--bg);border:1px solid var(--line)}
  .bubble.you{background:rgba(217,180,90,.12);border:1px solid rgba(217,180,90,.3);margin-left:auto}
  .bubble .lab{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:3px}
  .pl{display:inline-block;font-size:.68rem;font-weight:600;padding:2px 9px;border-radius:100px;border:1px solid rgba(217,180,90,.38);color:var(--gold-lt);vertical-align:2px;white-space:nowrap}
  .badge{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:100px;background:rgba(217,180,90,.14);color:var(--gold-lt)}
  .doc{font-size:.9rem;color:#d8d0c0;max-height:300px;overflow-y:auto;border:1px solid var(--line);border-radius:12px;padding:16px;margin:12px 0}
  .doc h3{font-family:'Playfair Display',serif;color:var(--gold-lt);margin:14px 0 5px;font-size:1rem}.doc p{margin:7px 0}
  .warn{background:rgba(230,180,90,.08);border:1px solid rgba(230,180,90,.28);border-radius:10px;padding:12px 14px;font-size:.86rem;color:#e6c06a;margin-bottom:14px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.grid2{grid-template-columns:1fr}}
  .kb-bell{position:relative}
  .bell-btn{position:relative;background:var(--card);border:1px solid var(--line);color:var(--gold-lt);width:40px;height:40px;border-radius:50%;cursor:pointer;font-size:1.05rem;display:inline-flex;align-items:center;justify-content:center}
  .bell-badge{position:absolute;top:-3px;right:-3px;background:#e0563a;color:#fff;font-size:.66rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--bg)}
  .bell-menu{position:absolute;right:0;top:calc(100% + 10px);width:min(300px,86vw);background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 22px 55px rgba(0,0,0,.6);padding:8px;display:none;z-index:120}
  .bell-menu.open{display:block}
  .bell-head{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;padding:8px 10px;display:flex;justify-content:space-between;align-items:center}
  .bell-head a{font-size:.72rem;text-transform:none;letter-spacing:0}
  .bell-empty{color:var(--muted);font-size:.85rem;padding:16px 10px;text-align:center}
  .bell-item{display:block;padding:11px 12px;border-radius:10px;color:var(--ink);text-decoration:none;font-size:.86rem;border-left:3px solid transparent}
  .bell-item:hover{background:var(--bg);text-decoration:none}
  .bell-item.un{border-left-color:var(--gold);background:rgba(217,180,90,.06)}
  .bell-time{display:block;color:var(--muted);font-size:.72rem;margin-top:3px}
  /* ---------- live chat (WhatsApp-style) ---------- */
  .chat-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .chat-av{width:42px;height:42px;border-radius:50%;background:var(--grad);color:#1c1405;display:flex;align-items:center;justify-content:center;font-weight:700;font-family:'Playfair Display',serif;font-size:1.2rem;flex:0 0 auto}
  .chat-head .who{font-weight:700;font-size:1rem}
  .chat-head .pres{font-size:.76rem;color:#8fd6a6}
  .chat-head .pres.off{color:var(--muted)}
  .chatlog{display:flex;flex-direction:column;gap:2px;max-height:min(56vh,520px);overflow-y:auto;padding:8px 4px 4px;margin:6px 0;scroll-behavior:smooth}
  .chatlog::-webkit-scrollbar{width:7px}.chatlog::-webkit-scrollbar-thumb{background:var(--line);border-radius:7px}
  .b{max-width:82%;border-radius:14px;padding:9px 13px 7px;white-space:pre-wrap;word-wrap:break-word;font-size:.93rem;line-height:1.45;position:relative;animation:bIn .18s ease}
  @keyframes bIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  @media(prefers-reduced-motion:reduce){.b{animation:none}}
  .b.them{background:var(--card);border:1px solid var(--line);align-self:flex-start;border-bottom-left-radius:5px}
  .b.you{background:linear-gradient(135deg,rgba(217,180,90,.22),rgba(217,180,90,.13));border:1px solid rgba(217,180,90,.32);align-self:flex-end;border-bottom-right-radius:5px}
  .b .meta2{font-size:.66rem;color:var(--muted);margin-top:3px;text-align:right;display:flex;gap:5px;justify-content:flex-end;align-items:center}
  .b.them .meta2{text-align:left;justify-content:flex-start}
  .b .tick{font-size:.72rem;letter-spacing:-2px;color:var(--muted)}
  .b .tick.read{color:#4db6f0}
  .b.day{align-self:center;background:rgba(255,255,255,.05);border:0;color:var(--muted);font-size:.7rem;padding:3px 12px;border-radius:100px;max-width:none}
  .typing-row{align-self:flex-start;display:none;padding:2px}
  .typing-row.show{display:flex}
  .typing{display:inline-flex;gap:4px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:14px;border-bottom-left-radius:5px;padding:11px 14px}
  .typing i{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:td 1.1s infinite ease-in-out}
  .typing i:nth-child(2){animation-delay:.18s}.typing i:nth-child(3){animation-delay:.36s}
  @keyframes td{0%,80%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-5px);opacity:1}}
  .composer{display:flex;gap:9px;align-items:flex-end;margin-top:12px;position:sticky;bottom:0}
  .composer textarea{min-height:46px;max-height:160px;resize:none;border-radius:22px;padding:12px 16px}
  .composer .send{flex:0 0 auto;width:46px;height:46px;padding:0;border-radius:50%;justify-content:center;font-size:1.1rem}
  .cerr{color:#e0906a;font-size:.8rem;margin-top:6px;display:none}.cerr.show{display:block}
  /* rating */
  .rate-card{text-align:center}
  .stars{display:inline-flex;gap:6px;font-size:1.9rem;line-height:1;cursor:pointer;margin:6px 0 4px;direction:rtl;justify-content:center}
  .stars span{color:#5a4c33;transition:color .12s,transform .12s}
  .stars span:hover,.stars span:hover ~ span,.stars span.on,.stars span.on ~ span{color:var(--gold-lt)}
  .stars span:hover{transform:scale(1.15)}
  .rated{color:#8fd6a6;font-weight:600;font-size:.9rem}
  /* patente (affiliate rank) */
  .patente{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:100px;font-weight:700;font-size:1rem;border:1px solid}
  .pt-bronze{background:rgba(176,120,70,.14);border-color:rgba(205,140,85,.5);color:#e0a878}
  .pt-prata{background:rgba(180,188,198,.12);border-color:rgba(200,208,218,.45);color:#d3dae4}
  .pt-gold{background:rgba(217,180,90,.16);border-color:rgba(244,220,147,.55);color:var(--gold-lt)}
  .pt-platina{background:rgba(120,200,220,.12);border-color:rgba(150,220,240,.5);color:#a7e6f2}
  .pt-em{font-size:1.2rem}
  .prog{height:9px;border-radius:9px;background:var(--bg);border:1px solid var(--line);overflow:hidden;margin:10px 0 6px}
  .prog i{display:block;height:100%;background:var(--grad);border-radius:9px;transition:width .5s}
  .tier-tab{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:14px 0 4px}
  .tier-cell{text-align:center;padding:11px 6px;border-radius:12px;border:1px solid var(--line);background:var(--bg);font-size:.78rem}
  .tier-cell.on{border-color:var(--gold);background:rgba(217,180,90,.08)}
  .tier-cell .te{font-size:1.3rem;display:block}
  .tier-cell b{display:block;font-size:.86rem;margin:3px 0 1px}
  .tier-cell .tp{color:var(--gold-lt);font-weight:700}
  @media(max-width:520px){.tier-tab{grid-template-columns:repeat(2,1fr)}}
  .pixgrid{display:grid;grid-template-columns:170px 1fr;gap:10px}@media(max-width:520px){.pixgrid{grid-template-columns:1fr}}
</style></head><body>
<div class="wrap">
  <div class="top">
    <a href="<?=h($SITE)?>" class="brand" aria-label="KB Sites — home"><img src="/fotos/logo-account.webp" alt="KB Sites Account" width="137" height="50"></a>
    <?php if ($me): ?>
      <div style="display:flex;align-items:center;gap:10px">
        <div class="kb-bell">
          <button type="button" id="bellBtn" class="bell-btn" aria-label="Notifications">🔔<?php if($unseen): ?><span class="bell-badge"><?=$unseen>9?'9+':$unseen?></span><?php endif; ?></button>
          <div class="bell-menu" id="bellMenu">
            <div class="bell-head"><span>Notifications</span><?php if($unseen): ?><a href="/account/?seen_all=1">Mark all read</a><?php endif; ?></div>
            <?php if(!$notifs): ?><div class="bell-empty">No notifications yet.</div>
            <?php else: foreach($notifs as $n): ?>
              <a class="bell-item<?=$n['seen']?'':' un'?>" href="<?=h($n['link']?:'/account/messages')?>"><?=h($n['body'])?><span class="bell-time"><?=h(substr($n['created_at'],0,16))?></span></a>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <a class="btn ghost sm" href="/account/?logout=1">Log out</a>
      </div>
    <?php elseif (!$pending): ?><a class="btn ghost sm" href="/account/login">Log in</a><?php endif; ?>
  </div>
  <?php if ($err): ?><p class="err"><?=h($err)?></p><?php endif; ?>
  <?php if ($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?>

<?php if ($me): // ===================== LOGGED IN =====================
  if (empty($me['pass_hash'])): // Google account without a password → must create one before using the account
?>
  <div id="app">
    <div class="card" style="max-width:460px;margin:0 auto">
      <h1 style="font-size:1.5rem">Crie uma senha para continuar</h1>
      <p class="lead" style="font-size:.95rem">Você entrou com o Google. Para usar sua conta, defina uma senha — assim você também consegue entrar pelo e-mail em qualquer dispositivo.</p>
      <form method="post"><input type="hidden" name="action" value="password">
        <label>Nova senha (mínimo 6 caracteres)</label>
        <input type="password" name="newpass" required minlength="6" autofocus>
        <div style="margin-top:16px"><button class="btn block" type="submit">Salvar e continuar →</button></div>
      </form>
      <p class="switch" style="margin-top:14px"><a href="/account/?logout=1">Sair da conta</a></p>
    </div>
  </div>
<?php else:
  // The owner/admins don't have a client "My messages" area — they use the admin panel.
  if ($isAdmin && in_array($view, ['', 'messages', 'partner'], true)) $view = 'settings';
  elseif ($view === '') $view = 'messages';
  $unread = 0;
?>
  <div id="app">
  <div class="nav">
    <?php if (!$isAdmin): ?><a class="<?=$view==='messages'?'on':''?>" href="/account/messages">My messages<?= $myTickets?' ('.count($myTickets).')':'' ?></a><?php endif; ?>
    <a class="<?=in_array($view,['settings','account'],true)?'on':''?>" href="/account/settings">Account</a>
    <?php if (!$isAdmin): ?><a class="<?=$view==='partner'?'on':''?>" href="/account/partner">Partner Program</a><?php endif; ?>
    <?php if ($isAdmin): ?><a class="adm" href="<?=h($SITE)?>/ticket/">🛡️ Admin panel</a><?php endif; ?>
    <a href="<?=h($SITE)?>">← Site</a>
  </div>

<?php if ($view==='messages'): // ---- client chat ----
  if ($openTicket):
    $reps = kb_db()->prepare("SELECT * FROM replies WHERE ticket_id=? ORDER BY id ASC"); $reps->execute([$openTicket['id']]); $reps=$reps->fetchAll();
    $lastId = 0; foreach($reps as $r){ if((int)$r['id']>$lastId) $lastId=(int)$r['id']; }
    $arow = kb_db()->prepare("SELECT last_read_id FROM chat_reads WHERE ticket_id=? AND side='admin'"); $arow->execute([$openTicket['id']]); $arow=$arow->fetch();
    $adminRead = $arow ? (int)$arow['last_read_id'] : 0;
    $hasAdmin = false; foreach($reps as $r){ if(($r['who']??'admin')==='admin'){ $hasAdmin=true; break; } }
    $mr = kb_db()->prepare("SELECT stars FROM mod_ratings WHERE ticket_id=? AND client_id=?"); $mr->execute([$openTicket['id'],$me['id']]); $mr=$mr->fetch();
    $myStars = $mr ? (int)$mr['stars'] : 0;
    $opl = $PLANS[$openTicket['plan'] ?? '']['label'] ?? '';
    function tick_html($mine,$id,$adminRead){ if(!$mine) return ''; $read = ($id<=$adminRead); return '<span class="tick'.($read?' read':'').'">'.($read?'✓✓':'✓').'</span>'; }
  ?>
    <p class="meta"><a href="/account/messages">← All messages</a></p>
    <div class="card" id="chat" data-token="<?=h($openTicket['token'])?>" data-last="<?=$lastId?>" data-read="<?=$adminRead?>">
      <div class="chat-head">
        <span class="chat-av">KB</span>
        <div>
          <div class="who">KB Sites</div>
          <div class="pres" id="chatPres">Support · request #<?=h($openTicket['id'])?><?= $opl!=='' ? ' · '.h($opl) : '' ?></div>
        </div>
      </div>
      <div class="chatlog" id="chatlog">
        <div class="b you">
<?=h($openTicket['message'])?>
          <div class="meta2"><?=h(substr($openTicket['created_at'],0,16))?> <span class="tick read">✓✓</span></div>
        </div>
        <?php foreach($reps as $r): $mine=(($r['who']??'admin')==='client'); ?>
          <div class="b <?=$mine?'you':'them'?>" data-id="<?=h($r['id'])?>">
<?=h($r['body'])?>
            <div class="meta2"><?=h(substr($r['created_at'],0,16))?> <?=tick_html($mine,(int)$r['id'],$adminRead)?></div>
          </div>
        <?php endforeach; ?>
        <div class="typing-row" id="typingRow"><div class="typing"><i></i><i></i><i></i></div></div>
      </div>
      <div class="composer">
        <textarea id="chatInput" rows="1" placeholder="Write a message…" maxlength="8000"></textarea>
        <button class="btn send" type="button" id="chatSend" aria-label="Send">➤</button>
      </div>
      <p class="cerr" id="chatErr"></p>
      <noscript>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="action" value="creply"><input type="hidden" name="token" value="<?=h($openTicket['token'])?>">
          <textarea name="body" placeholder="Write a message…" required></textarea>
          <div style="margin-top:10px"><button class="btn" type="submit">Send →</button></div>
        </form>
      </noscript>
    </div>
    <div class="card rate-card" id="rateCard" style="<?= $hasAdmin ? '' : 'display:none' ?>" data-rated="<?=$myStars?>">
      <h2 style="margin-bottom:2px">Rate your support</h2>
      <p class="meta" id="rateSub"><?= $myStars ? 'Thanks for your feedback — tap to change.' : 'How was our service on this conversation? (only we see this)' ?></p>
      <div class="stars" id="stars">
        <?php for($s=5;$s>=1;$s--): ?><span data-s="<?=$s?>" class="<?=($myStars && $s<=$myStars)?'on':''?>">★</span><?php endfor; ?>
      </div>
      <p class="rated" id="rateDone" style="<?= $myStars ? '' : 'display:none' ?>">You rated <?=$myStars?:0?>/5 ★</p>
    </div>
  <?php else: ?>
    <h1>My messages</h1>
    <p class="lead">Your conversations with KB Sites.</p>
    <?php if(!$myTickets): ?>
      <div class="card"><p class="meta">You haven't messaged us yet. <a href="<?=h($SITE)?>/#contact">Start a project →</a></p></div>
    <?php else: foreach($myTickets as $t): $pl = $PLANS[$t['plan'] ?? '']['label'] ?? ''; ?>
      <a class="tk" href="/account/messages/<?=h($t['token'])?>">
        <span><b>#<?=h($t['id'])?><?= $t['business']?' · '.h($t['business']):'' ?></b><?php if($pl!==''): ?> <span class="pl"><?=h($pl)?></span><?php endif; ?><div class="meta"><?=h(mb_substr($t['message'],0,60))?>…</div></span>
        <span class="badge"><?=h($STAGES[(string)($t['status'] ?? '')] ?? ($t['status'] ?? ''))?></span>
      </a>
    <?php endforeach; endif; ?>
  <?php endif; ?>

<?php elseif ($view==='partner'): // ---- affiliate ----
  $pf = fn($x) => rtrim(rtrim(number_format((float)$x, 1), '0'), '.');
  $TIERS = kb_tiers();
  ?>
  <?php if (!$me['is_affiliate']): ?>
    <h1>Partner Program</h1>
    <p class="lead">Refer a business to KB Sites and earn a commission on every website that pays — from <b style="color:var(--gold-lt)">~<?=$pf(kb_tier_pct('bronze'))?>%</b> up to <b style="color:var(--gold-lt)">~<?=$pf(kb_tier_pct('platina'))?>%</b> as you rank up. 🇧🇷 <b>Brazil only</b> · paid by <b>Pix</b>.</p>

    <div class="card">
      <h2>Ranks (patentes)</h2>
      <p class="meta" style="margin-bottom:6px">You earn <b>1 point</b> each time a client you referred pays for a website. More points, higher rank, higher commission.</p>
      <div class="tier-tab">
        <?php foreach($TIERS as $k=>$t): ?>
          <div class="tier-cell"><span class="te"><?=$t[1]?></span><b><?=h($t[0])?></b>
            <div class="tp">~<?=$pf($t[3])?>%</div>
            <div class="meta"><?=$t[4][0]?>–<?=$t[4][1]?>% · <?=(int)$t[2]?>+ pts</div></div>
        <?php endforeach; ?>
      </div>
      <p class="meta" style="margin-top:8px">Commissions are paid in <b>reais (R$) via Pix</b>. Because the site price is in US dollars, the amount is <b>approximate (~)</b> — it varies with the USD→BRL exchange rate on the day we pay. The monthly hosting/care fee never earns commission.</p>
    </div>

    <div class="card">
      <h2>Join — it's free</h2>
      <p class="meta">You already have an account. Confirm your details and accept the agreement to get your referral link.</p>
      <div class="doc"><?php include __DIR__ . '/../partners/_agreement.php'; ?></div>
      <form method="post">
        <input type="hidden" name="action" value="join">
        <label class="agree" style="margin-top:16px"><input type="checkbox" name="br" value="1"> I confirm I <b style="color:var(--ink)">&nbsp;live in Brazil</b>&nbsp;(the Partner Program and Pix payouts are for Brazil only).</label>
        <div class="pixgrid" style="margin-top:6px">
          <div><label style="margin-top:0">Pix key type</label>
            <select name="pix_type" style="width:100%;background:var(--bg);border:1px solid #5a4c33;border-radius:10px;padding:12px 14px;color:var(--ink);font-family:inherit;font-size:.95rem">
              <option value="cpf">CPF</option><option value="email">E-mail</option><option value="phone">Telefone</option><option value="random">Chave aleatória</option>
            </select></div>
          <div><label style="margin-top:0">Pix key</label><input name="pix_key" placeholder="your Pix key" required></div>
        </div>
        <label>CPF</label><input name="cpf" placeholder="000.000.000-00" required>
        <p class="meta" style="margin-top:6px">Your CPF is used only for the Pix payout. You're an independent partner and pay your own taxes on what you earn.</p>
        <label class="agree" style="margin-top:12px"><input type="checkbox" name="agree" value="1"> I have read and agree to the <a href="/account/partner">Affiliate Agreement</a>.</label>
        <div style="margin-top:16px"><button class="btn block" type="submit">Join the Partner Program →</button></div>
      </form>
    </div>
  <?php else:
    $refs = kb_db()->prepare("SELECT * FROM tickets WHERE ref_code=? ORDER BY id DESC"); $refs->execute([$me['code']]); $refs=$refs->fetchAll();
    $sales=array_filter($refs,fn($t)=>$t['paid'] && empty($t['comm_void']));
    $comOf=fn($t)=>kb_ticket_commission($t);                                  // tier-aware; voided = 0
    $earned=array_sum(array_map(fn($t)=>$t['paid']?$comOf($t):0,$refs));
    $paidOut=array_sum(array_map(fn($t)=>($t['paid']&&$t['commission_paid'])?$comOf($t):0,$refs));
    $link=$SITE.'/?ref='.$me['code'];
    $myTier=kb_aff_tier($me); $ti=kb_tier_info($myTier); $myPts=kb_aff_points($me['id']); $myPct=kb_tier_pct($myTier); $next=kb_tier_next($me);
    $needPix = trim((string)($me['pix_key'] ?? ''))==='';
  ?>
    <h1>Partner dashboard</h1>
    <?php if($needPix): ?><div class="warn">⚠ Add your Pix key below so we can pay your commissions.</div><?php endif; ?>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div><h2 style="margin-bottom:8px">Your rank</h2>
          <span class="patente pt-<?=$myTier?>"><span class="pt-em"><?=$ti[1]?></span> <?=h($ti[0])?></span>
          <span class="meta" style="margin-left:8px">~<?=$pf($myPct)?>% commission · <b style="color:var(--gold-lt)"><?=$myPts?> pt<?=$myPts===1?'':'s'?></b></span>
        </div>
      </div>
      <?php if($next):
        $prevMin = (int)$ti[2]; $span = max(1, $next['min']-$prevMin); $done = min($span, $myPts-$prevMin); $pctBar = max(6, round($done/$span*100)); ?>
        <div class="prog"><i style="width:<?=$pctBar?>%"></i></div>
        <p class="meta"><b style="color:var(--gold-lt)"><?=$next['need']?></b> more paid client<?=$next['need']===1?'':'s'?> to reach <?=kb_tier_info($next['key'])[1]?> <b><?=h(kb_tier_info($next['key'])[0])?></b> (~<?=$pf(kb_tier_pct($next['key']))?>%).</p>
      <?php else: ?>
        <div class="prog"><i style="width:100%"></i></div>
        <p class="meta">You've reached the top rank. 💎</p>
      <?php endif; ?>
      <div class="tier-tab">
        <?php foreach($TIERS as $k=>$t): ?>
          <div class="tier-cell <?=$k===$myTier?'on':''?>"><span class="te"><?=$t[1]?></span><b><?=h($t[0])?></b><div class="tp">~<?=$pf($t[3])?>%</div><div class="meta"><?=(int)$t[2]?>+ pts</div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card"><h2>Your referral link</h2>
      <div class="copy"><input id="reflink" readonly value="<?=h($link)?>"><button class="btn sm" type="button" onclick="cp('reflink')">Copy</button></div>
      <p class="meta" style="margin-top:8px">Code: <b style="color:var(--gold-lt)"><?=h($me['code'])?></b> · valid for 30 days after someone clicks.</p>
    </div>
    <div class="stats">
      <div class="stat"><div class="n"><?=count($refs)?></div><div class="l">Referred leads</div></div>
      <div class="stat"><div class="n"><?=count($sales)?></div><div class="l">Paying clients</div></div>
      <div class="stat green"><div class="n"><?=money($earned)?></div><div class="l">Total earned (~USD)</div></div>
      <div class="stat amber"><div class="n"><?=money($earned-$paidOut)?></div><div class="l">Pending payout</div></div>
    </div>
    <div class="card"><h2>Pix payout details 🇧🇷</h2>
      <p class="meta" style="margin-bottom:8px">Commissions are paid only by Pix, in reais. The amount is approximate — it depends on the USD→BRL rate on payout day.</p>
      <form method="post"><input type="hidden" name="action" value="payout">
        <div class="pixgrid">
          <div><label style="margin-top:0">Pix key type</label>
            <select name="pix_type" style="width:100%;background:var(--bg);border:1px solid #5a4c33;border-radius:10px;padding:12px 14px;color:var(--ink);font-family:inherit;font-size:.95rem">
              <?php foreach(['cpf'=>'CPF','email'=>'E-mail','phone'=>'Telefone','random'=>'Chave aleatória'] as $vk=>$vl): ?>
                <option value="<?=$vk?>" <?=($me['pix_type']??'')===$vk?'selected':''?>><?=$vl?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label style="margin-top:0">Pix key</label><input name="pix_key" value="<?=h($me['pix_key'] ?? '')?>" placeholder="your Pix key" required></div>
        </div>
        <label>CPF</label><input name="cpf" value="<?=h($me['cpf'] ?? '')?>" placeholder="000.000.000-00" required>
        <div style="margin-top:12px"><button class="btn" type="submit">Save Pix details</button></div>
      </form>
    </div>
    <?php if($refs): ?><div class="card"><h2>Your referrals</h2>
      <?php foreach($refs as $t): $com=$comOf($t); ?>
        <div class="tk"><span><b><?=h($t['business']?:$t['name'])?></b><div class="meta"><?=h(substr($t['created_at'],0,10))?></div></span>
          <span><?= !empty($t['comm_void']) ? '<span class="meta">Not eligible</span>' : ($t['paid']?'<b style="color:#8fd6a6">~'.money($com).'</b>':'<span class="meta">~'.money($com).' if they buy</span>') ?></span></div>
      <?php endforeach; ?></div><?php endif; ?>
  <?php endif; ?>

<?php else: // ---- account settings ---- ?>
  <h1>Account</h1>
  <div class="card"><h2>Profile photo</h2>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <?php $ph = trim($me['photo'] ?? ''); ?>
      <?php if($ph): ?>
        <img src="<?=h($ph)?>" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--line)">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;background:var(--grad);color:#1c1405;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.7rem;font-family:'Playfair Display',serif"><?=h(strtoupper(mb_substr($me['name'],0,1)))?></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="action" value="avatar">
        <input type="file" name="avatar" accept="image/*" required style="max-width:230px">
        <button class="btn sm" type="submit">Upload</button>
      </form>
    </div>
    <p class="meta" style="margin-top:8px">JPG, PNG, GIF or WebP · up to 3MB<?= !empty($me['google_sub'])?' · your Google photo is used by default':'' ?></p>
  </div>
  <div class="card"><h2>Your details</h2>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="action" value="rename">
      <div style="flex:1;min-width:200px"><label style="margin-top:0">Name</label><input name="name" value="<?=h($me['name'])?>" required></div>
      <button class="btn sm" type="submit">Save</button>
    </form>
    <p class="meta" style="margin-top:10px"><?=h($me['email'])?><?= !empty($me['google_sub'])?' · Google account':'' ?><?= $isAdmin?' · <b style="color:var(--gold-lt)">admin</b>':'' ?></p>
  </div>
  <div class="card"><h2><?= !empty($me['pass_hash']) ? 'Change password' : 'Set a password' ?></h2>
    <?php if (empty($me['pass_hash'])): ?><p class="meta" style="margin-bottom:6px">You signed up with Google. Set a password so you can also log in with your email on any device.</p><?php endif; ?>
    <form method="post"><input type="hidden" name="action" value="password">
      <div class="grid2">
        <?php if(!empty($me['pass_hash'])): ?><div><label style="margin-top:0">Current password</label><input type="password" name="current" required></div><?php endif; ?>
        <div><label style="margin-top:0">New password (min 6 chars)</label><input type="password" name="newpass" required minlength="6"></div>
      </div>
      <div style="margin-top:12px"><button class="btn" type="submit"><?= !empty($me['pass_hash']) ? 'Update password' : 'Set password' ?></button></div>
    </form>
  </div>
  </div><!-- #app -->
<?php endif; // end logged views ?>
<?php endif; // end password gate ?>

<?php elseif ($pending): // ===================== VERIFY ===================== ?>
  <div class="card" style="max-width:440px;margin:0 auto">
    <h1 style="font-size:1.5rem">Check your email</h1>
    <p class="lead" style="font-size:.95rem">We sent a 6-digit code to <b style="color:var(--ink)"><?=h($pending['email'])?></b>.</p>
    <form method="post"><input type="hidden" name="action" value="verify">
      <label>Verification code</label>
      <input name="code" inputmode="numeric" maxlength="6" placeholder="123456" required autofocus style="font-size:1.4rem;letter-spacing:.3em;text-align:center">
      <div style="margin-top:16px"><button class="btn block" type="submit">Verify & enter →</button></div>
    </form>
    <p class="switch">Didn't get it? <a href="/account/" onclick="event.preventDefault();document.getElementById('rs').submit()">Resend code</a> · <a href="/account/?logout=1">start over</a></p>
    <form id="rs" method="post" style="display:none"><input type="hidden" name="action" value="resend"></form>
  </div>

<?php elseif ($view==='login'): // ===================== LOGIN ===================== ?>
  <div class="card" style="max-width:440px;margin:0 auto">
    <h1 style="font-size:1.6rem">Log in</h1>
    <?=$NEXTNOTE?>
    <?=$GBTN?>
    <form method="post"><input type="hidden" name="action" value="login">
      <label>Email</label><input type="email" name="email" required autofocus>
      <label>Password</label><input type="password" name="password" required>
      <div style="margin-top:18px"><button class="btn block" type="submit">Log in →</button></div>
    </form>
    <p class="switch">No account yet? <a href="/account/">Create one</a></p>
  </div>

<?php else: // ===================== SIGN UP ===================== ?>
  <div class="card" style="max-width:460px;margin:0 auto">
    <h1 style="font-size:1.6rem">Create your account</h1>
    <p class="lead" style="font-size:.92rem">One account for everything — message us about a project, or join the Partner Program later.</p>
    <?=$NEXTNOTE?>
    <?=$GBTN?>
    <form method="post"><input type="hidden" name="action" value="register">
      <label>Full name</label><input name="name" required value="<?=h($_POST['name'] ?? '')?>">
      <label>Email</label><input type="email" name="email" required value="<?=h($_POST['email'] ?? '')?>">
      <label>Create a password</label><input type="password" name="password" required minlength="6">
      <div style="margin-top:18px"><button class="btn block" type="submit">Create account →</button></div>
    </form>
    <p class="switch">Already have an account? <a href="/account/login">Log in</a></p>
  </div>
<?php endif; ?>
</div>
<script>
function cp(id){var el=document.getElementById(id);el.select();el.setSelectionRange(0,99999);try{document.execCommand('copy');event.target.textContent='Copied ✓';setTimeout(function(){event.target.textContent='Copy'},1500)}catch(e){}}
(function(){var b=document.getElementById('bellBtn'),m=document.getElementById('bellMenu');if(b&&m){b.addEventListener('click',function(e){e.stopPropagation();m.classList.toggle('open');});m.addEventListener('click',function(e){e.stopPropagation();});document.addEventListener('click',function(){m.classList.remove('open');});}})();
</script>
<script>
/* Live chat: polling feed, typing indicator, read ticks, AJAX send, moderator rating.
   Re-inits after a panel.js swap via the 'kb:swapped' event. */
(function(){
  function initChat(){
    var chat=document.getElementById('chat');
    if(!chat||chat.dataset.kbInit) return;
    chat.dataset.kbInit='1';
    var API='/account/?chat=1', FEED='/account/?chat=feed';
    var token=chat.getAttribute('data-token');
    var log=document.getElementById('chatlog'), typingRow=document.getElementById('typingRow');
    var input=document.getElementById('chatInput'), sendBtn=document.getElementById('chatSend'), errEl=document.getElementById('chatErr');
    var last=parseInt(chat.getAttribute('data-last')||'0',10);
    var adminRead=parseInt(chat.getAttribute('data-read')||'0',10);
    var rateCard=document.getElementById('rateCard');
    function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
    function atBottom(){return log.scrollHeight-log.scrollTop-log.clientHeight<90;}
    function toBottom(){log.scrollTop=log.scrollHeight;}
    function tickHtml(read){return '<span class="tick'+(read?' read':'')+'">'+(read?'✓✓':'✓')+'</span>';}
    function addMsg(m){
      if(m.id&&log.querySelector('.b[data-id="'+m.id+'"]')) return;
      var b=document.createElement('div');
      b.className='b '+(m.who==='you'?'you':'them');
      if(m.id) b.setAttribute('data-id',m.id);
      b.innerHTML=esc(m.body)+'<div class="meta2">'+esc(m.at||'')+' '+(m.who==='you'?tickHtml(m.id<=adminRead):'')+'</div>';
      log.insertBefore(b,typingRow);
      if(m.id&&m.id>last) last=m.id;
    }
    function updateTicks(){
      var bs=log.querySelectorAll('.b.you[data-id]');
      for(var i=0;i<bs.length;i++){var id=parseInt(bs[i].getAttribute('data-id'),10);var t=bs[i].querySelector('.tick');
        if(t){var read=id<=adminRead;t.className='tick'+(read?' read':'');t.textContent=read?'✓✓':'✓';}}
    }
    function autoGrow(){input.style.height='auto';input.style.height=Math.min(160,input.scrollHeight)+'px';}
    function post(action,extra){var fd=new FormData();fd.append('action',action);fd.append('t',token);if(extra)for(var k in extra)fd.append(k,extra[k]);
      return fetch(API,{method:'POST',body:fd,credentials:'same-origin'});}
    // ---- send ----
    var sending=false;
    function send(){
      var body=(input.value||'').trim(); if(!body||sending) return;
      sending=true; errEl.classList.remove('show');
      post('csend',{body:body}).then(function(r){return r.json();}).then(function(j){
        sending=false;
        if(j&&j.ok){addMsg({id:j.id,who:'you',body:body,at:j.at});input.value='';autoGrow();toBottom();stopTyping();if(rateCard)rateCard.style.display='';}
        else{errEl.textContent=(j&&j.error==='slow')?'Slow down a moment…':'Could not send — try again.';errEl.classList.add('show');}
      }).catch(function(){sending=false;errEl.textContent='Network error — try again.';errEl.classList.add('show');});
    }
    sendBtn.addEventListener('click',send);
    input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
    // ---- typing ----
    var typingOn=false,lastPing=0,stopTimer=null;
    function typing(){var now=Date.now();if(now-lastPing>2500){lastPing=now;typingOn=true;post('ctyping',{on:'1'});}clearTimeout(stopTimer);stopTimer=setTimeout(stopTyping,4000);}
    function stopTyping(){clearTimeout(stopTimer);if(typingOn){typingOn=false;lastPing=0;post('ctyping',{});}}
    input.addEventListener('input',function(){autoGrow();typing();});
    input.addEventListener('blur',stopTyping);
    // ---- read ----
    function markRead(){post('cread',{upto:last});}
    // ---- poll ----
    var polling=false, timer=setInterval(poll,2000);
    function poll(){
      if(!document.body.contains(chat)){clearInterval(timer);return;}
      if(polling||document.hidden) return; polling=true;
      fetch(FEED+'&t='+encodeURIComponent(token)+'&after='+last,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        polling=false; if(!j||!j.ok) return;
        var stick=atBottom(),newThem=false;
        (j.msgs||[]).forEach(function(m){var had=m.id&&log.querySelector('.b[data-id="'+m.id+'"]');addMsg(m);if(!had&&m.who==='them')newThem=true;});
        if(typeof j.read_id==='number'&&j.read_id>adminRead){adminRead=j.read_id;updateTicks();}
        if(typingRow) typingRow.classList.toggle('show',!!j.typing);
        if(newThem){if(rateCard)rateCard.style.display='';markRead();if(stick)toBottom();}
        else if(j.typing&&stick){toBottom();}
      }).catch(function(){polling=false;});
    }
    document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
    // ---- rating ----
    if(rateCard){
      var stars=document.getElementById('stars'),done=document.getElementById('rateDone'),sub=document.getElementById('rateSub');
      if(stars){var sp=stars.querySelectorAll('span');for(var i=0;i<sp.length;i++){(function(el){el.addEventListener('click',function(){
        var val=parseInt(el.getAttribute('data-s'),10);
        post('crate',{stars:val}).then(function(r){return r.json();}).then(function(j){
          if(j&&j.ok){for(var k=0;k<sp.length;k++){sp[k].classList.toggle('on',parseInt(sp[k].getAttribute('data-s'),10)<=val);}
            if(done){done.textContent='You rated '+val+'/5 ★';done.style.display='';}if(sub)sub.textContent='Thanks for your feedback — tap to change.';}
        }).catch(function(){});
      });})(sp[i]);}}
    }
    autoGrow();toBottom();markRead();poll();
  }
  if(document.readyState!=='loading') initChat(); else document.addEventListener('DOMContentLoaded',initChat);
  document.addEventListener('kb:swapped',initChat);
})();
</script>
<script src="/panel.js"></script>
</body></html>
