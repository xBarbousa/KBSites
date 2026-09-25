<?php
require __DIR__ . '/../db.php';
@include __DIR__ . '/../config.php';
kb_session_start();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ $n=(float)$n; if($n<=0) return '—'; return '$'.($n==floor($n)?number_format($n,0):number_format($n,2)); }
function stage_class($s){ return ['new'=>'b-new','replied'=>'b-replied','proposal'=>'b-proposal','paid'=>'b-paid','delivered'=>'b-delivered','closed'=>'b-closed','lost'=>'b-lost'][$s] ?? 'b-new'; }
function plan_badge($key){ $p = kb_plan($key); return $p ? '<span class="badge b-plan" title="'.h(kb_plan_price($key)).'">'.h($p['label']).'</span>' : ''; }
function pct_txt($p){ return rtrim(rtrim(number_format((float)$p,1),'0'),'.'); }

// The client's address: their account email (verified) when the ticket is linked, else the ticket email.
function tk_client_email($tk){ $o = kb_ticket_owner($tk); return ($o && !empty($o['email'])) ? $o['email'] : ($tk['email'] ?? ''); }
// Branded "status update" to the client — only on a real stage change, never for 'new'.
function tk_status_mail($tk, $stage, $siteUrl = null){
  if ($stage === 'new' || $stage === ($tk['status'] ?? '')) return;
  kb_mail_html(tk_client_email($tk), null, 'status_update', [
    'name'=>$tk['name'], 'business'=>$tk['business'], 'plan'=>$tk['plan'] ?? null, 'token'=>$tk['token'], 'ticket_id'=>$tk['id'],
    'stage'=>$stage, 'site_url'=>($siteUrl !== null ? $siteUrl : ($tk['site_url'] ?? '')),
  ]);
}
// Partner email when their referred ticket becomes paid ($tk = the row after the update).
// Skipped for voided / $0 commissions and for referrals flagged as possible self-referrals.
function tk_commission_earned_mail($tk){
  if (!$tk || empty($tk['ref_code']) || !empty($tk['comm_void'])) return;
  $a = kb_aff_by_code($tk['ref_code']);
  $amt = kb_ticket_commission($tk);
  if (!$a || $amt <= 0 || kb_selfref_flags($tk)) return;
  kb_mail_html($a['email'], null, 'commission_earned', ['name'=>$a['name'], 'business'=>$tk['business'], 'amount'=>$amt, 'pct'=>kb_ticket_pct($tk), 'ticket_id'=>$tk['id']]);
}
// Where commission actions return to (the ticket page or the Affiliates view).
function tk_back($tk){ return (($_POST['back'] ?? '') === 'ticket' && $tk) ? 'index.php?t=' . $tk['token'] : 'index.php?view=affiliates'; }

$STAGES    = kb_stages();
$PCT       = kb_commission_pct();
$token     = isset($_GET['t']) ? preg_replace('/[^A-Za-z0-9]/', '', $_GET['t']) : '';
$view      = preg_replace('/[^a-z]/', '', $_GET['view'] ?? 'overview');
$action    = $_POST['action'] ?? '';
$err       = ''; $ok = '';

// ---- auth: unified login; admin = the account whose email matches admin_email ----
if (isset($_GET['logout'])) { kb_forget(); session_destroy(); header('Location: ' . ((isset($KB_SITE)&&$KB_SITE)?rtrim($KB_SITE,'/'):'') . '/account/'); exit; }
$adminUser = kb_auth_user();
$logged    = kb_is_admin($adminUser);

// ---- live chat API (JSON) for the ticket view — mirrors the client side ----
if ($logged && (($_GET['chat'] ?? '') !== '' || in_array($action, ['asend','atyping','aread'], true))) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  $tkn = preg_replace('/[^A-Za-z0-9]/', '', (string)($_REQUEST['t'] ?? ''));
  $tk  = $tkn !== '' ? kb_ticket_by_token($tkn) : null;
  if (!$tk) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }
  $db = kb_db();

  if ($action === 'asend') {                       // moderator sends a reply
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    if (mb_strlen($body) > 8000) $body = mb_substr($body, 0, 8000);
    $db->prepare("INSERT INTO replies(ticket_id,body,who,mod_id) VALUES(?,?,'admin',?)")->execute([$tk['id'], $body, $adminUser['id']]);
    $rid = (int)$db->lastInsertId();
    if (($tk['status'] ?? '') === 'new') $db->prepare("UPDATE tickets SET status='replied' WHERE id=?")->execute([$tk['id']]);
    $db->prepare("DELETE FROM chat_typing WHERE ticket_id=? AND side='admin'")->execute([$tk['id']]);
    kb_respond_early(['ok'=>true, 'id'=>$rid, 'at'=>substr(date('Y-m-d H:i:s'), 0, 16)]);
    kb_mail_html(tk_client_email($tk), null, 'studio_reply', ['name'=>$tk['name'], 'body'=>$body, 'token'=>$tk['token'], 'ticket_id'=>$tk['id']]);
    $owner = kb_ticket_owner($tk);
    if ($owner) kb_notify($owner['id'], $tk['id'], 'reply', 'KB Sites replied to your message', '/account/messages/' . $tk['token']);
    exit;
  }
  if ($action === 'atyping') {                      // "moderator is typing…"
    if (!empty($_POST['on'])) {
      $db->prepare("INSERT INTO chat_typing(ticket_id,side,name,user_id,expires_at) VALUES(?,'admin',?,?,datetime('now','localtime','+6 seconds'))
                    ON CONFLICT(ticket_id,side) DO UPDATE SET name=excluded.name,user_id=excluded.user_id,expires_at=excluded.expires_at")
         ->execute([$tk['id'], (string)$adminUser['name'], $adminUser['id']]);
    } else {
      $db->prepare("DELETE FROM chat_typing WHERE ticket_id=? AND side='admin'")->execute([$tk['id']]);
    }
    echo json_encode(['ok'=>true]); exit;
  }
  if ($action === 'aread') {                         // moderator read up to reply #upto
    $upto = (int)($_POST['upto'] ?? 0);
    $db->prepare("INSERT INTO chat_reads(ticket_id,side,last_read_id,updated_at) VALUES(?,'admin',?,datetime('now','localtime'))
                  ON CONFLICT(ticket_id,side) DO UPDATE SET last_read_id=MAX(last_read_id,excluded.last_read_id),updated_at=excluded.updated_at")
       ->execute([$tk['id'], $upto]);
    echo json_encode(['ok'=>true]); exit;
  }
  // feed: for the moderator, 'you' = admin replies, 'them' = client messages
  $after = (int)($_GET['after'] ?? 0);
  $q = $db->prepare("SELECT id,who,body,created_at FROM replies WHERE ticket_id=? AND id>? ORDER BY id ASC");
  $q->execute([$tk['id'], $after]);
  $msgs = [];
  foreach ($q->fetchAll() as $r) {
    $msgs[] = ['id'=>(int)$r['id'], 'who'=>(($r['who'] ?? 'admin')==='admin'?'you':'them'), 'body'=>(string)$r['body'], 'at'=>substr((string)$r['created_at'], 0, 16)];
  }
  $tp = $db->prepare("SELECT 1 FROM chat_typing WHERE ticket_id=? AND side='client' AND expires_at>datetime('now','localtime')"); $tp->execute([$tk['id']]);
  $rr = $db->prepare("SELECT last_read_id FROM chat_reads WHERE ticket_id=? AND side='client'"); $rr->execute([$tk['id']]); $rrow = $rr->fetch();
  echo json_encode(['ok'=>true, 'msgs'=>$msgs, 'typing'=>(bool)$tp->fetch(), 'read_id'=>$rrow ? (int)$rrow['last_read_id'] : 0]); exit;
}

// ---- authed mutations ----
if ($logged && $action === 'reply') {
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  $rb = trim($_POST['body'] ?? '');
  if ($tk && $rb !== '') {
    kb_db()->prepare("INSERT INTO replies(ticket_id,body,who,mod_id) VALUES(?,?,'admin',?)")->execute([$tk['id'], $rb, $adminUser['id']]);
    if (($tk['status'] ?? '') === 'new') kb_db()->prepare("UPDATE tickets SET status='replied' WHERE id=?")->execute([$tk['id']]);
    // branded email with the reply + a link back to the chat (no separate status email for new→replied)
    kb_mail_html(tk_client_email($tk), null, 'studio_reply', ['name'=>$tk['name'], 'body'=>$rb, 'token'=>$tk['token'], 'ticket_id'=>$tk['id']]);
    $owner = kb_ticket_owner($tk);
    if ($owner) kb_notify($owner['id'], $tk['id'], 'reply', 'KB Sites replied to your message', '/account/messages/' . $tk['token']);
    header('Location: index.php?t=' . $tk['token']); exit;
  }
}
if ($logged && $action === 'deal') {
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  if ($tk) {
    $stage = array_key_exists($_POST['stage'] ?? '', $STAGES) ? $_POST['stage'] : $tk['status'];
    $val   = ($_POST['value'] ?? '') === '' ? null : (float)$_POST['value'];
    $mnt   = ($_POST['maintenance'] ?? '') === '' ? null : (float)$_POST['maintenance'];
    $paid  = !empty($_POST['paid']) ? 1 : 0;
    if ($stage === 'paid') $paid = 1; // choosing the Paid stage always marks it paid (keeps money + emails in sync)
    $justPaid = ($paid && !$tk['paid']);
    $paidAt = $tk['paid_at'];
    if ($justPaid) $paidAt = date('Y-m-d');
    if (!$paid) $paidAt = null;
    $commPct = $tk['comm_pct'] ?? null; // preserve a rate already locked; lock the referrer's rank rate at payment time
    if ($justPaid && ($commPct === null || $commPct === '')) $commPct = kb_ticket_pct($tk);
    $site  = trim($_POST['site_url'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    kb_db()->prepare("UPDATE tickets SET status=?, value=?, maintenance=?, paid=?, paid_at=?, comm_pct=?, site_url=?, notes=? WHERE id=?")
           ->execute([$stage, $val, $mnt, $paid, $paidAt, $commPct, $site, $notes, $tk['id']]);
    if ($justPaid) { $owner = kb_ticket_owner($tk); if ($owner) kb_notify($owner['id'], $tk['id'], 'paid', 'Payment confirmed — your project is starting! 🎉', '/account/messages/' . $tk['token']); }
    // emails to the client: the paid confirmation always fires on the 0->1 flip; otherwise only on a real stage change
    if (!empty($_POST['notify_client'])) {
      if ($justPaid) tk_status_mail($tk, 'paid', $site);
      elseif ($stage !== $tk['status']) tk_status_mail($tk, $stage, $site);
    }
    if ($justPaid) tk_commission_earned_mail(kb_ticket_by_token($tk['token']));
    header('Location: index.php?t=' . $tk['token']); exit;
  }
}
if ($logged && $action === 'stage') { // quick move from kanban
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  $ns = array_key_exists($_POST['status'] ?? '', $STAGES) ? $_POST['status'] : null;
  if ($tk && $ns) {
    $paid = $tk['paid']; $paidAt = $tk['paid_at'];
    $justPaid = ($ns === 'paid' && !$paid);
    if ($justPaid) { $paid = 1; $paidAt = date('Y-m-d'); }
    kb_db()->prepare("UPDATE tickets SET status=?, paid=?, paid_at=? WHERE id=?")->execute([$ns, $paid, $paidAt, $tk['id']]);
    // lock the commission rate at payment time, once (never reduced by a later rate change)
    if ($justPaid && (($tk['comm_pct'] ?? null) === null || $tk['comm_pct'] === '')) kb_db()->prepare("UPDATE tickets SET comm_pct=? WHERE id=?")->execute([kb_ticket_pct($tk), $tk['id']]);
    // just paid with no price set yet: take it from the plan the client chose
    if ($justPaid && ($pl = kb_plan($tk['plan'] ?? null))) {
      if ($tk['value'] === null || $tk['value'] === '') kb_db()->prepare("UPDATE tickets SET value=? WHERE id=?")->execute([$pl['setup'], $tk['id']]);
      if (($tk['maintenance'] === null || $tk['maintenance'] === '') && $pl['monthly'] > 0) kb_db()->prepare("UPDATE tickets SET maintenance=? WHERE id=?")->execute([$pl['monthly'], $tk['id']]);
    }
    if ($justPaid) { $owner = kb_ticket_owner($tk); if ($owner) kb_notify($owner['id'], $tk['id'], 'paid', 'Payment confirmed — your project is starting! 🎉', '/account/messages/' . $tk['token']); }
    tk_status_mail($tk, $ns); // pipeline moves always email the client (real changes only)
    if ($justPaid) tk_commission_earned_mail(kb_ticket_by_token($tk['token']));
    header('Location: index.php?view=pipeline'); exit;
  }
}
if ($logged && $action === 'paypal') {
  kb_setting_set('paypal_link', trim($_POST['paypal_link'] ?? ''));
  header('Location: index.php?view=settings&ok=paypal'); exit;
}
if ($logged && $action === 'mkadmin') {
  $e = strtolower(trim($_POST['email'] ?? ''));
  if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) { header('Location: index.php?view=members&err=bademail'); exit; }
  if ($e === kb_admin_email())      { header('Location: index.php?view=members&ok=admin');   exit; }
  if (!kb_aff_by_email($e))         { header('Location: index.php?view=members&err=noacct');  exit; } // no account → nobody is shown
  kb_add_admin_email($e);
  header('Location: index.php?view=members&ok=admin'); exit;
}
if ($logged && $action === 'rmadmin') {
  $e = strtolower(trim($_POST['email'] ?? ''));
  kb_setting_set('extra_admins', implode(',', array_values(array_filter(kb_extra_admins(), fn($x)=>$x!==$e))));
  header('Location: index.php?view=members'); exit;
}
if ($logged && $action === 'deluser') {
  $u = ($id = (int)($_POST['uid'] ?? 0)) ? kb_user_by_id($id) : null;
  if ($u && strtolower(trim($u['email'])) !== kb_admin_email()) {
    kb_db()->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$u['id']]);
    kb_db()->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$u['id']]);
    kb_db()->prepare("DELETE FROM user_devices WHERE user_id=?")->execute([$u['id']]);
    kb_setting_set('extra_admins', implode(',', array_values(array_filter(kb_extra_admins(), fn($x)=>$x!==strtolower(trim($u['email']))))));
    kb_db()->prepare("DELETE FROM affiliates WHERE id=?")->execute([$u['id']]);
  }
  header('Location: index.php?view=members'); exit;
}
if ($logged && $action === 'google_cfg') {
  kb_setting_set('google_client_id', trim($_POST['google_client_id'] ?? ''));
  $sec = trim($_POST['google_client_secret'] ?? '');
  if ($sec !== '') kb_setting_set('google_client_secret', $sec); // blank keeps the saved one
  header('Location: index.php?view=settings&ok=google'); exit;
}
if ($logged && $action === 'commission_pct') {
  $old = kb_commission_pct();
  $p = (float)($_POST['commission_pct'] ?? 20);
  if ($p < 0) $p = 0; if ($p > 100) $p = 100;
  kb_setting_set('commission_pct', $p);
  // optional announcement — only when the rate actually went up and the box was ticked
  if ($p > $old && !empty($_POST['announce'])) {
    $sent = 0;
    foreach (kb_db()->query("SELECT * FROM affiliates WHERE is_affiliate=1 AND (status IS NULL OR status='active')")->fetchAll() as $a) {
      if (kb_mail_html($a['email'], null, 'commission_rate_up', ['name'=>$a['name'], 'old_pct'=>$old, 'new_pct'=>$p])) $sent++;
    }
    header('Location: index.php?view=settings&ok=pctann&n=' . $sent); exit;
  }
  header('Location: index.php?view=settings&ok=pct'); exit;
}
if ($logged && $action === 'tiers_cfg') { // rank thresholds + each rank's approximate %
  foreach (kb_tier_keys() as $k) {
    $mn = $_POST['min_' . $k] ?? ''; if ($mn !== '') kb_setting_set('tier_' . $k . '_min', max(0, (int)$mn));
    $pc = $_POST['pct_' . $k] ?? ''; if ($pc !== '') kb_setting_set('tier_' . $k . '_pct', max(0, min(100, (float)$pc)));
  }
  header('Location: index.php?view=settings&ok=tiers'); exit;
}
if ($logged && $action === 'commpaid') {
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  if ($tk && empty($tk['comm_void']) && !$tk['commission_paid']) {
    kb_db()->prepare("UPDATE tickets SET commission_paid=1, commission_paid_at=? WHERE id=?")->execute([date('Y-m-d'), $tk['id']]);
    $a = kb_aff_by_code($tk['ref_code'] ?? '');
    $amt = kb_ticket_commission($tk);
    if ($a && $amt > 0) kb_mail_html($a['email'], null, 'commission_paid', ['name'=>$a['name'], 'business'=>$tk['business'], 'amount'=>$amt, 'date'=>date('Y-m-d'), 'ticket_id'=>$tk['id']]);
  }
  header('Location: ' . tk_back($tk)); exit;
}
if ($logged && $action === 'commupdate') { // undo mark-paid, from affiliates view
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  if ($tk) { kb_db()->prepare("UPDATE tickets SET commission_paid=0, commission_paid_at=NULL WHERE id=?")->execute([$tk['id']]); }
  header('Location: ' . tk_back($tk)); exit;
}
if ($logged && ($action === 'commvoid' || $action === 'communvoid')) { // void = no commission owed (e.g. self-referral)
  $tk = kb_ticket_by_token($_POST['token'] ?? '');
  if ($tk) { kb_db()->prepare("UPDATE tickets SET comm_void=? WHERE id=?")->execute([$action === 'commvoid' ? 1 : 0, $tk['id']]); }
  header('Location: ' . tk_back($tk)); exit;
}
if ($logged && $action === 'mailtoggle') { // switch an email kind on/off
  $k = preg_replace('/[^a-z_]/', '', (string)($_POST['kind'] ?? ''));
  $cat = kb_mail_catalogue();
  if (isset($cat[$k]) && empty($cat[$k]['same_as'])) kb_setting_set('mail_on_' . $k, !empty($_POST['on']) ? '1' : '0');
  header('Location: index.php?view=emails'); exit;
}
if ($logged && $action === 'chpass') { // the admin's own account password
  if (!password_verify((string)($_POST['current'] ?? ''), (string)($adminUser['pass_hash'] ?? ''))) { $err = 'Current password is wrong.'; }
  elseif (strlen((string)($_POST['newpass'] ?? '')) < 6) { $err = 'New password must be at least 6 characters.'; }
  else {
    kb_db()->prepare("UPDATE affiliates SET pass_hash=? WHERE id=?")->execute([password_hash((string)$_POST['newpass'], PASSWORD_DEFAULT), $adminUser['id']]);
    kb_mail_html($adminUser['email'], null, 'password_changed', ['name'=>$adminUser['name'], 'email'=>$adminUser['email']]);
    header('Location: index.php?view=settings&ok=pass'); exit;
  }
}
if (($_GET['ok'] ?? '') === 'paypal') $ok = 'PayPal link saved.';
if (($_GET['ok'] ?? '') === 'pass')   $ok = 'Password changed.';
if (($_GET['ok'] ?? '') === 'pct')    $ok = 'Commission rate saved.';
if (($_GET['ok'] ?? '') === 'pctann') {
  $n = (int)($_GET['n'] ?? 0);
  $ok = $n > 0 ? 'Commission rate saved and announced to ' . $n . ' partner' . ($n === 1 ? '' : 's') . '.'
               : 'Commission rate saved. No announcement was sent' . (kb_mail_enabled('commission_rate_up') ? '.' : ' — turn on "Commission rate raised" in Emails first.');
}
if (($_GET['ok'] ?? '') === 'google') $ok = 'Google sign-in settings saved.';
if (($_GET['ok'] ?? '') === 'admin')  $ok = 'Moderator added.';
if (($_GET['ok'] ?? '') === 'tiers')  $ok = 'Ranks saved.';
if (($_GET['err'] ?? '') === 'noacct')   $err = 'No account found with that email — the person must sign up first, then you can make them a moderator.';
if (($_GET['err'] ?? '') === 'bademail') $err = 'Please enter a valid email address.';

$paypal = kb_setting_get('paypal_link');
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>KB · Dashboard</title>
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
  .wrap{width:min(940px,94vw);margin:0 auto;padding:26px 0 90px}
  .top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
  .brand{display:block;line-height:0}
  .brand img{height:50px;width:auto;display:block}
  @media(max-width:560px){.brand img{height:42px}}
  .btn{display:inline-flex;align-items:center;gap:7px;background:var(--grad);color:#1c1405;font-weight:700;border:0;border-radius:100px;padding:11px 20px;font-size:.9rem;cursor:pointer;font-family:inherit}
  .btn.ghost{background:transparent;color:var(--gold-lt);border:1px solid rgba(217,180,90,.4)}
  .btn.sm{padding:7px 14px;font-size:.82rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:14px}
  label{display:block;font-size:.8rem;font-weight:600;margin:0 0 6px;color:var(--muted)}
  input,textarea,select{width:100%;background:var(--bg);border:1px solid #5a4c33;border-radius:10px;padding:11px 13px;color:var(--ink);font-family:inherit;font-size:.95rem}
  select{cursor:pointer}
  textarea{min-height:100px;resize:vertical}
  .err{color:#e0906a;font-size:.88rem;margin:10px 0}
  .ok{color:#8fd6a6;font-size:.88rem;margin:10px 0}
  h1{font-size:1.5rem;margin-bottom:4px}h2{font-size:1.05rem;margin-bottom:12px;color:var(--gold-lt);font-weight:700}
  .meta{color:var(--muted);font-size:.85rem;margin:2px 0}
  /* nav */
  .nav{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;margin-bottom:20px;-webkit-overflow-scrolling:touch}
  .nav a{white-space:nowrap;padding:9px 16px;border-radius:100px;border:1px solid var(--line);color:var(--muted);font-weight:600;font-size:.88rem;transition:color .2s,background .2s,border-color .2s,transform .12s}
  .nav a:hover{color:var(--gold-lt);border-color:rgba(217,180,90,.5)}
  .nav a:active{transform:scale(.95)}
  .nav a.on{background:var(--grad);color:#1c1405;border-color:transparent;box-shadow:0 6px 18px rgba(217,180,90,.22)}
  .nav a.on:hover{color:#1c1405}
  .nav a .q{font-size:.72rem;opacity:.8;margin-left:5px}
  @keyframes kbSwapIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  #app.kb-swap{animation:kbSwapIn .3s cubic-bezier(.22,1,.36,1)}
  @media(prefers-reduced-motion:reduce){#app.kb-swap{animation:none}}
  /* stats */
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px}
  .stat .n{font-size:1.7rem;font-weight:700;line-height:1.1;color:var(--gold-lt)}
  .stat .l{color:var(--muted);font-size:.78rem;margin-top:4px;font-weight:600}
  .stat.green .n{color:#8fd6a6}.stat.amber .n{color:#e6c06a}
  /* funnel */
  .fun .row{display:grid;grid-template-columns:110px 1fr 30px;align-items:center;gap:10px;margin:8px 0;font-size:.82rem}
  .fun .bar{height:20px;border-radius:6px;background:var(--bg);overflow:hidden}
  .fun .fill{height:100%;border-radius:6px;min-width:3px;background:var(--grad)}
  /* badges */
  .badge{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:100px}
  .b-new{background:rgba(217,180,90,.16);color:var(--gold-lt)}
  .b-replied{background:rgba(120,160,210,.16);color:#9db9e6}
  .b-proposal{background:rgba(230,180,90,.16);color:#e6c06a}
  .b-paid{background:rgba(90,190,120,.18);color:#8fd6a6}
  .b-delivered{background:rgba(90,180,170,.16);color:#7fd3c6}
  .b-closed{background:rgba(150,150,150,.16);color:#b8b0a2}
  .b-lost{background:rgba(200,110,90,.16);color:#e0906a}
  .b-plan{background:transparent;color:var(--gold-lt);border:1px solid rgba(217,180,90,.35);text-transform:none;letter-spacing:0;font-weight:600}
  .b-on{background:rgba(90,190,120,.18);color:#8fd6a6}.b-off{background:rgba(150,150,150,.16);color:#b8b0a2}
  /* self-referral warning + voided commissions */
  .warn{background:rgba(200,80,60,.1);border:1px solid rgba(224,120,100,.55);border-radius:12px;padding:13px 15px;margin:12px 0;color:#f2c1b4;font-size:.88rem}
  .warn b{color:#ff9c85}.warn ul{margin:6px 0 0 18px}
  .flag{color:#ff9c85;font-weight:700;cursor:help}
  .void{text-decoration:line-through;opacity:.6}
  .chk{display:flex;align-items:center;gap:8px;font-size:.86rem;color:var(--ink);font-weight:500;margin:12px 0 0;cursor:pointer}
  .chk input{width:auto;accent-color:#d9b45a}
  .mailprev{width:100%;height:720px;border:1px solid var(--line);border-radius:12px;background:#080706;margin-top:10px}
  pre.txt{white-space:pre-wrap;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:14px;margin-top:10px;font-size:.82rem;color:var(--muted)}
  details summary{cursor:pointer;color:var(--gold-lt);font-size:.86rem;margin-top:12px}
  /* list rows */
  .row-a{display:flex;gap:12px;align-items:center;justify-content:space-between;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:13px 15px;margin-bottom:9px}
  .row-a:hover{border-color:rgba(217,180,90,.4);text-decoration:none}
  .row-a .who{color:var(--ink);font-weight:600}
  .row-a .snip{color:var(--muted);font-size:.85rem;margin-top:2px}
  /* kanban */
  .board{display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;-webkit-overflow-scrolling:touch}
  .col{flex:0 0 240px;width:240px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:11px 9px}
  .col h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);display:flex;justify-content:space-between;margin:2px 4px 10px}
  .kc{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:11px;margin-bottom:9px}
  .kc .nm{font-weight:600;font-size:.9rem}
  .kc .sub{color:var(--muted);font-size:.78rem;margin:2px 0 8px}
  .kc select{padding:6px 8px;font-size:.78rem}
  .kc .val{color:#8fd6a6;font-weight:700;font-size:.85rem;margin-bottom:6px}
  .msg{white-space:pre-wrap;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:14px;margin:10px 0}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  th{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);text-align:left;padding:9px 10px;border-bottom:2px solid var(--line)}
  td{padding:10px;border-bottom:1px solid var(--line)}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .copy{display:flex;gap:8px;align-items:stretch}.copy input{flex:1}
  .hint{color:var(--muted);font-size:.82rem}
  @media(max-width:560px){.grid2{grid-template-columns:1fr}}
  /* live chat (moderator side) */
  .chatlog{display:flex;flex-direction:column;gap:2px;max-height:min(52vh,480px);overflow-y:auto;padding:8px 4px 4px;margin:8px 0;scroll-behavior:smooth}
  .chatlog::-webkit-scrollbar{width:7px}.chatlog::-webkit-scrollbar-thumb{background:var(--line);border-radius:7px}
  .b{max-width:82%;border-radius:14px;padding:9px 13px 7px;white-space:pre-wrap;word-wrap:break-word;font-size:.92rem;line-height:1.45;position:relative;animation:bIn .18s ease}
  @keyframes bIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .b.them{background:var(--bg);border:1px solid var(--line);align-self:flex-start;border-bottom-left-radius:5px}
  .b.you{background:linear-gradient(135deg,rgba(217,180,90,.2),rgba(217,180,90,.11));border:1px solid rgba(217,180,90,.3);align-self:flex-end;border-bottom-right-radius:5px}
  .b .meta2{font-size:.66rem;color:var(--muted);margin-top:3px;display:flex;gap:5px;justify-content:flex-end;align-items:center}
  .b.them .meta2{justify-content:flex-start}
  .b .tick{font-size:.72rem;letter-spacing:-2px;color:var(--muted)}.b .tick.read{color:#4db6f0}
  .typing-row{align-self:flex-start;display:none;padding:2px}.typing-row.show{display:flex}
  .typing{display:inline-flex;gap:4px;align-items:center;background:var(--bg);border:1px solid var(--line);border-radius:14px;border-bottom-left-radius:5px;padding:11px 14px}
  .typing i{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:td 1.1s infinite ease-in-out}
  .typing i:nth-child(2){animation-delay:.18s}.typing i:nth-child(3){animation-delay:.36s}
  @keyframes td{0%,80%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-5px);opacity:1}}
  .composer{display:flex;gap:9px;align-items:flex-end;margin-top:10px}
  .composer textarea{min-height:46px;max-height:150px;resize:none;border-radius:22px;padding:12px 16px}
  .composer .send{flex:0 0 auto;width:46px;height:46px;padding:0;justify-content:center;border-radius:50%;font-size:1.05rem}
  .cerr{color:#e0906a;font-size:.8rem;margin-top:6px;display:none}.cerr.show{display:block}
  .stars-sm{color:var(--gold-lt);letter-spacing:1px}
</style></head><body>
<div class="wrap">
  <div class="top">
    <a href="/ticket/" class="brand" aria-label="KB Sites Dashboard"><img src="/fotos/logo-dashboard.webp" alt="KB Sites Dashboard" width="137" height="50"></a>
    <?php if ($logged): ?><a class="btn ghost sm" href="?logout=1">Log out</a><?php endif; ?>
  </div>

<?php if (!$logged): ?>
  <div class="card">
    <h1>Admin only</h1>
    <?php if ($adminUser): ?>
      <p class="meta">You're logged in as <b style="color:var(--ink)"><?=h($adminUser['email'])?></b>, which isn't the admin account. Log in with the studio account to see the dashboard.</p>
    <?php else: ?>
      <p class="meta">Please log in with the KB Sites admin account to access the dashboard.</p>
    <?php endif; ?>
    <div style="margin-top:16px"><a class="btn" href="<?=h((isset($KB_SITE)&&$KB_SITE)?rtrim($KB_SITE,'/'):'')?>/account/?view=login">Go to login →</a></div>
  </div>

<?php
else:
  // ---------- logged in ----------
  $all = kb_db()->query("SELECT * FROM tickets ORDER BY id DESC")->fetchAll();
  $active = array_filter($all, fn($t)=>!in_array($t['status'],['closed','lost'],true));
  $cNew   = count(array_filter($all, fn($t)=>$t['status']==='new'));
  $cProp  = count(array_filter($all, fn($t)=>$t['status']==='proposal'));
  $won    = array_filter($all, fn($t)=>in_array($t['status'],['paid','delivered','closed'],true));
  $revenue= array_sum(array_map(fn($t)=>$t['paid']?(float)$t['value']:0, $all));
  // monthly recurring = hosting/care fees of clients who paid (and weren't lost afterwards)
  $mrr    = array_sum(array_map(fn($t)=>($t['paid'] && $t['status']!=='lost')?(float)$t['maintenance']:0, $all));

  // commissions owed now (voided ones never count)
  $affOwed = array_filter($all, fn($t)=>!empty($t['ref_code']) && $t['paid'] && !$t['commission_paid'] && empty($t['comm_void']));

  // ticket detail?
  $tk = $token ? kb_ticket_by_token($token) : null;
  $navItems = ['overview'=>['Overview',null],'pipeline'=>['Pipeline',count($active)],'inbox'=>['Inbox',$cNew?:null],'money'=>['Money',null],'affiliates'=>['Affiliates',count($affOwed)?:null],'members'=>['Members',null],'emails'=>['Emails',null],'settings'=>['Settings',null]];
  if ($tk) $view = 'inbox';

  // possible self-referrals: ticket id => reasons (only the views that show commissions)
  $FLAGS = [];
  if (!$tk && in_array($view, ['money','affiliates'], true)) {
    foreach ($all as $t) { if (!empty($t['ref_code'])) { $f = kb_selfref_flags($t); if ($f) $FLAGS[$t['id']] = $f; } }
  }
?>
  <div id="app">
  <div class="nav">
    <?php foreach($navItems as $k=>$v): ?>
      <a class="<?=$view===$k?'on':''?>" href="?view=<?=$k?>"><?=$v[0]?><?= $v[1]!==null?'<span class="q">'.$v[1].'</span>':'' ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($ok): ?><p class="ok"><?=h($ok)?></p><?php endif; ?>
  <?php if ($err): ?><p class="err"><?=h($err)?></p><?php endif; ?>

<?php if ($tk): // ===== TICKET DETAIL ===== ?>
  <?php
    $reps = kb_db()->prepare("SELECT * FROM replies WHERE ticket_id=? ORDER BY id ASC"); $reps->execute([$tk['id']]); $reps=$reps->fetchAll();
    $payAmt = ($paypal && stripos($paypal,'paypal.me')!==false && (float)$tk['value']>0) ? rtrim($paypal,'/').'/'.(int)$tk['value'] : $paypal;
    $tPlan  = kb_plan($tk['plan'] ?? null);
    $flags  = kb_selfref_flags($tk);
    $isVoid = !empty($tk['comm_void']);
    // deal form defaults from the plan — only when the field is still empty
    $defVal = ($tk['value'] === null || $tk['value'] === '') ? ($tPlan ? $tPlan['setup'] : '') : $tk['value'];
    $defMnt = ($tk['maintenance'] === null || $tk['maintenance'] === '') ? (($tPlan && $tPlan['monthly'] > 0) ? $tPlan['monthly'] : '') : $tk['maintenance'];
  ?>
  <p class="meta"><a href="?view=inbox">← All tickets</a></p>
  <div class="card">
    <h1>Ticket #<?=h($tk['id'])?> <span class="badge <?=stage_class($tk['status'])?>"><?=h($STAGES[$tk['status']]??$tk['status'])?></span></h1>
    <p class="meta"><b style="color:var(--ink)"><?=h($tk['name'])?></b> · <a href="mailto:<?=h($tk['email'])?>"><?=h($tk['email'])?></a><?= $tk['business'] ? ' · '.h($tk['business']) : '' ?></p>
    <p class="meta">Plan: <?php if($tPlan): ?><b style="color:var(--gold-lt)"><?=h($tPlan['label'])?></b> · <?=h(kb_plan_price($tk['plan']))?><?php else: ?><span>not chosen</span><?php endif; ?></p>
    <p class="meta"><?=h($tk['created_at'])?><?php if(!empty($tk['ip']) || !empty($tk['dev'])): ?> · <span title="Sender IP / browser id (anti self-referral)">IP <?=h($tk['ip'] ?: '—')?> · device <?=h(substr((string)$tk['dev'],0,8) ?: '—')?></span><?php endif; ?></p>
    <?php if(!empty($tk['ref_code'])): $refA = kb_aff_by_code($tk['ref_code']); ?>
      <p class="meta">Referred by <b style="color:var(--gold-lt)"><?=h($refA['name'] ?? $tk['ref_code'])?></b> · <?=h($tk['ref_code'])?><?= $isVoid ? ' · <span style="color:#e0906a">commission voided</span>' : '' ?></p>
    <?php endif; ?>
    <?php if($flags): ?>
      <div class="warn">
        <b>⚠ Possible self-referral</b> — the referring partner may be the buyer:
        <ul><?php foreach($flags as $f): ?><li><?=h($f)?></li><?php endforeach; ?></ul>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="action" value="<?=$isVoid?'communvoid':'commvoid'?>"><input type="hidden" name="token" value="<?=h($tk['token'])?>"><input type="hidden" name="back" value="ticket">
          <button class="btn ghost sm" type="submit" style="color:#ff9c85;border-color:rgba(224,120,100,.55)"><?=$isVoid?'Commission voided — undo':'Void this commission'?></button>
        </form>
      </div>
    <?php endif; ?>
    <?php
      $lastId=0; foreach($reps as $r){ if((int)$r['id']>$lastId) $lastId=(int)$r['id']; }
      $crow=kb_db()->prepare("SELECT last_read_id FROM chat_reads WHERE ticket_id=? AND side='client'"); $crow->execute([$tk['id']]); $crow=$crow->fetch();
      $clientRead=$crow?(int)$crow['last_read_id']:0;
      $tkTick=function($mine,$id,$cr){ if(!$mine) return ''; $read=($id<=$cr); return '<span class="tick'.($read?' read':'').'">'.($read?'✓✓':'✓').'</span>'; };
    ?>
    <p class="hint" style="margin:6px 0 2px"><?= kb_mail_enabled('studio_reply') ? 'Live chat — replies show instantly in the client\'s chat and are emailed to them.' : 'Live chat — replies show instantly in the client\'s chat (reply emails are off in Emails).' ?></p>
    <div id="chat" data-token="<?=h($tk['token'])?>" data-last="<?=$lastId?>" data-read="<?=$clientRead?>">
      <div class="chatlog" id="chatlog">
        <div class="b them" data-id="0">
<?=h($tk['message'])?>
          <div class="meta2"><?=h(substr((string)$tk['created_at'],0,16))?></div>
        </div>
        <?php foreach($reps as $r): $mine=(($r['who']??'admin')==='admin'); ?>
          <div class="b <?=$mine?'you':'them'?>" data-id="<?=h($r['id'])?>">
<?=h($r['body'])?>
            <div class="meta2"><?=h(substr((string)$r['created_at'],0,16))?> <?=$tkTick($mine,(int)$r['id'],$clientRead)?></div>
          </div>
        <?php endforeach; ?>
        <div class="typing-row" id="typingRow"><div class="typing"><i></i><i></i><i></i></div></div>
      </div>
      <div class="composer">
        <textarea id="chatInput" rows="1" placeholder="Write your reply…" maxlength="8000"></textarea>
        <button class="btn send" type="button" id="chatSend" aria-label="Send reply">➤</button>
      </div>
      <p class="cerr" id="chatErr"></p>
      <noscript>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="action" value="reply"><input type="hidden" name="token" value="<?=h($tk['token'])?>">
          <textarea name="body" placeholder="Write your reply…" required></textarea>
          <div style="margin-top:10px"><button class="btn" type="submit">Send reply →</button></div>
        </form>
      </noscript>
    </div>
  </div>

  <!-- deal / lead panel -->
  <div class="card">
    <h2>Deal</h2>
    <form method="post">
      <input type="hidden" name="action" value="deal">
      <input type="hidden" name="token" value="<?=h($tk['token'])?>">
      <div class="grid2">
        <div><label>Stage</label>
          <select name="stage"><?php foreach($STAGES as $sk=>$sl): ?><option value="<?=$sk?>" <?=$tk['status']===$sk?'selected':''?>><?=$sl?></option><?php endforeach; ?></select>
        </div>
        <div><label>Paid?</label>
          <select name="paid"><option value="0" <?=!$tk['paid']?'selected':''?>>No</option><option value="1" <?=$tk['paid']?'selected':''?>>Yes</option></select>
        </div>
        <div><label>Project price ($)</label><input type="number" step="any" min="0" name="value" value="<?=h($defVal)?>" placeholder="150"></div>
        <div><label>Monthly fee ($/mo)</label><input type="number" step="any" min="0" name="maintenance" value="<?=h($defMnt)?>" placeholder="<?=$tPlan ? h($tPlan['monthly']) : '10 or 20'?>"></div>
      </div>
      <?php if($tPlan && ($tk['value'] === null || $tk['value'] === '')): ?><p class="hint" style="margin-top:6px">Prefilled from the plan the client chose (<?=h($tPlan['label'])?>) — saved when you click Save deal.</p><?php endif; ?>
      <label style="margin-top:12px">Delivered site URL</label><input name="site_url" value="<?=h($tk['site_url'])?>" placeholder="https://…">
      <label style="margin-top:12px">Private notes</label><textarea name="notes" style="min-height:70px"><?=h($tk['notes'])?></textarea>
      <label class="chk"><input type="checkbox" name="notify_client" value="1" checked> Email the client about this change</label>
      <p class="hint" style="margin-top:2px">Sent only when the stage changes (not for "New").<?= kb_mail_enabled('status_update') ? '' : ' <span style="color:#e0906a">Status emails are off — turn them on in <a href="?view=emails">Emails</a>.</span>' ?></p>
      <div style="margin-top:12px"><button class="btn" type="submit">Save deal</button></div>
    </form>
  </div>

  <!-- payment link to send -->
  <div class="card">
    <h2>Payment link</h2>
    <?php if ($paypal): ?>
      <p class="hint" style="margin-bottom:8px">Send this to the client<?= (float)$tk['value']>0 ? ' — already set to '.money($tk['value']) : '' ?>:</p>
      <div class="copy"><input id="payl" readonly value="<?=h($payAmt)?>"><button class="btn sm" type="button" onclick="cp('payl')">Copy</button></div>
    <?php else: ?>
      <p class="hint">Add your PayPal.Me link in <a href="?view=settings">Settings</a> and it shows here, ready to send with the price filled in.</p>
    <?php endif; ?>
  </div>

  <?php if(!empty($tk['ref_code'])): $rpct=kb_ticket_pct($tk); $com=(float)$tk['value']*$rpct/100; $rAff=kb_aff_by_code($tk['ref_code']); $rTier=$rAff?kb_aff_tier($rAff):'bronze'; $rTi=kb_tier_info($rTier); ?>
  <div class="card">
    <h2>Affiliate commission<?php if($flags): ?> <span class="flag" title="<?=h(implode(' ', $flags))?>">⚠</span><?php endif; ?></h2>
    <p class="meta">Referred by <b style="color:var(--gold-lt)"><?=h($rAff['name'] ?? $tk['ref_code'])?></b> · <?=$rTi[1]?> <?=h($rTi[0])?> · commission ~<?=pct_txt($rpct)?>% of the site price = <b class="<?=$isVoid?'void':''?>" style="color:#8fd6a6">~<?=money($com)?></b> <span class="hint">(paid in R$ via Pix, approx.)</span></p>
    <?php if($isVoid): ?>
      <p class="err" style="margin-top:8px">Voided — no commission is owed and it's left out of every total.</p>
    <?php elseif(!$tk['paid']): ?>
      <p class="hint" style="margin-top:8px">Owed only after the client pays. Mark this ticket <b>Paid</b> above first.</p>
    <?php elseif(!$tk['commission_paid']): ?>
      <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="commpaid"><input type="hidden" name="token" value="<?=h($tk['token'])?>"><input type="hidden" name="back" value="ticket">
        <button class="btn sm" type="submit">Mark <?=money($com)?> commission paid</button></form>
    <?php else: ?>
      <p class="ok" style="margin-top:8px">Commission of <?=money($com)?> paid on <?=h($tk['commission_paid_at'])?>.</p>
    <?php endif; ?>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="action" value="<?=$isVoid?'communvoid':'commvoid'?>"><input type="hidden" name="token" value="<?=h($tk['token'])?>"><input type="hidden" name="back" value="ticket">
      <button class="btn ghost sm" type="submit"><?=$isVoid?'Un-void commission':'Void commission'?></button>
    </form>
  </div>
  <?php endif; ?>

<?php elseif ($view==='pipeline'): // ===== PIPELINE ===== ?>
  <h1 style="margin-bottom:6px">Pipeline</h1>
  <p class="hint" style="margin-bottom:14px">Tap a card's dropdown to move it. Moving to <b>Paid</b> marks it paid automatically (price taken from the client's plan if empty). <?= kb_mail_enabled('status_update') ? 'Each move emails the client a status update.' : 'Status emails are off (see Emails).' ?></p>
  <div class="board">
    <?php foreach($STAGES as $sk=>$sl): $col=array_filter($all, fn($t)=>$t['status']===$sk); ?>
      <div class="col">
        <h3><?=$sl?><span><?=count($col)?></span></h3>
        <?php if(!$col): ?><p class="meta" style="padding:4px">—</p><?php endif; ?>
        <?php foreach($col as $t): ?>
          <div class="kc">
            <div class="nm"><a href="?t=<?=h($t['token'])?>">#<?=h($t['id'])?> <?=h($t['name'])?></a></div>
            <div class="sub"><?=h($t['business']?:$t['email'])?></div>
            <?php if(kb_plan($t['plan'] ?? null)): ?><div style="margin:-4px 0 8px"><?=plan_badge($t['plan'])?></div><?php endif; ?>
            <?php if((float)$t['value']>0): ?><div class="val"><?=money($t['value'])?><?= (float)$t['maintenance']>0?' <span style="color:var(--muted);font-weight:600">+'.money($t['maintenance']).'/mo</span>':'' ?></div><?php endif; ?>
            <form method="post" onchange="this.submit()">
              <input type="hidden" name="action" value="stage">
              <input type="hidden" name="token" value="<?=h($t['token'])?>">
              <select name="status"><?php foreach($STAGES as $k2=>$l2): ?><option value="<?=$k2?>" <?=$t['status']===$k2?'selected':''?>><?=$l2?></option><?php endforeach; ?></select>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($view==='inbox'): // ===== INBOX ===== ?>
  <h1 style="margin-bottom:14px">Inbox</h1>
  <?php if(!$all): ?>
    <div class="card"><p class="meta">No tickets yet. When someone messages you from the site, it shows up here.</p></div>
  <?php else: foreach($all as $t): ?>
    <a class="row-a" href="?t=<?=h($t['token'])?>">
      <span>
        <span class="who">#<?=h($t['id'])?> · <?=h($t['name'])?><?= $t['business']?' <span class="meta">· '.h($t['business']).'</span>':'' ?></span>
        <span class="snip"><?=h(mb_substr($t['message'],0,80))?><?= mb_strlen($t['message'])>80?'…':'' ?></span>
      </span>
      <span style="text-align:right">
        <?=plan_badge($t['plan'] ?? null)?> <span class="badge <?=stage_class($t['status'])?>"><?=h($STAGES[$t['status']]??$t['status'])?></span>
        <?php if((float)$t['value']>0): ?><div class="meta" style="color:#8fd6a6;font-weight:700;margin-top:4px"><?=money($t['value'])?></div><?php endif; ?>
      </span>
    </a>
  <?php endforeach; endif; ?>

<?php elseif ($view==='money'): // ===== MONEY ===== ?>
  <h1 style="margin-bottom:14px">Money</h1>
  <?php
    $received = $revenue;
    $pending  = array_sum(array_map(fn($t)=>(!$t['paid'] && in_array($t['status'],['proposal','delivered'],true))?(float)$t['value']:0, $all));
    $deals    = array_filter($all, fn($t)=>(float)$t['value']>0 || $t['paid']);
    $recurring= array_filter($all, fn($t)=>$t['paid'] && $t['status']!=='lost' && (float)$t['maintenance']>0);
    $commOwed = array_sum(array_map(fn($t)=>kb_ticket_commission($t), $affOwed));
  ?>
  <div class="stats">
    <div class="stat green"><div class="n"><?=money($received)?></div><div class="l">Received</div></div>
    <div class="stat amber"><div class="n"><?=money($pending)?></div><div class="l">Awaiting payment</div></div>
    <div class="stat"><div class="n"><?=money($mrr)?><span style="font-size:.9rem;color:var(--muted)">/mo</span></div><div class="l">Monthly recurring (<?=count($recurring)?> paid client<?=count($recurring)===1?'':'s'?>)</div></div>
    <div class="stat green"><div class="n"><?=money($received + $mrr*12)?></div><div class="l">12-month projection</div></div>
    <div class="stat amber"><div class="n"><?=money($commOwed)?></div><div class="l">Commissions owed</div></div>
  </div>
  <div class="card">
    <h2>Monthly recurring</h2>
    <?php if(!$recurring): ?><p class="meta">No monthly clients yet. Paid tickets with a monthly fee (Website + Hosting $10 / Website + Care $20) add up here.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Client</th><th>Plan</th><th>Monthly</th><th>Paid since</th></tr></thead>
      <tbody>
      <?php foreach($recurring as $t): ?>
        <tr>
          <td><a href="?t=<?=h($t['token'])?>"><?=h($t['business']?:$t['name'])?></a></td>
          <td><?=plan_badge($t['plan'] ?? null) ?: '<span class="meta">—</span>'?></td>
          <td style="color:#8fd6a6;font-weight:700"><?=money($t['maintenance'])?>/mo</td>
          <td class="meta"><?=h($t['paid_at'] ?: '—')?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Deals</h2>
    <?php if(!$deals): ?><p class="meta">No priced deals yet. Set a price on a ticket in the Inbox and it appears here.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Client</th><th>Plan</th><th>Price</th><th>Monthly</th><th>Paid</th><th>Stage</th><th>Commission</th></tr></thead>
      <tbody>
      <?php foreach($deals as $t): $fl = $FLAGS[$t['id']] ?? []; ?>
        <tr>
          <td><a href="?t=<?=h($t['token'])?>"><?=h($t['name'])?></a></td>
          <td><?=plan_badge($t['plan'] ?? null) ?: '<span class="meta">—</span>'?></td>
          <td><?=money($t['value'])?></td>
          <td><?= (float)$t['maintenance']>0?money($t['maintenance']).'/mo':'—' ?></td>
          <td><?= $t['paid']?'<span class="badge b-paid">Yes</span>':'<span class="meta">No</span>' ?></td>
          <td><span class="badge <?=stage_class($t['status'])?>"><?=h($STAGES[$t['status']]??$t['status'])?></span></td>
          <td><?php if(empty($t['ref_code'])): ?><span class="meta">—</span><?php else: ?><span class="<?=!empty($t['comm_void'])?'void':''?>">~<?=money((float)$t['value']*kb_ticket_pct($t)/100)?></span><?php if($fl): ?> <span class="flag" title="Possible self-referral: <?=h(implode(' ', $fl))?>">⚠</span><?php endif; ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view==='affiliates'): // ===== AFFILIATES ===== ?>
  <h1 style="margin-bottom:6px">Affiliates</h1>
  <p class="hint" style="margin-bottom:14px">Partners rank up by referrals and earn ~<?=pct_txt(kb_tier_pct('bronze'))?>%–~<?=pct_txt(kb_tier_pct('platina'))?>% of the site price, paid in R$ via <b>Pix</b> after the client pays (amount approximate — USD→BRL conversion). Mark each commission paid once you've sent it. Configure ranks in <a href="?view=settings">Settings</a>.</p>

  <div class="card">
    <h2>Commissions to pay now</h2>
    <?php if(!$affOwed): ?>
      <p class="meta">Nothing owed right now. When a referred client pays, the commission shows up here.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Client</th><th>Affiliate</th><th>Pay to</th><th>Commission</th><th></th></tr></thead>
      <tbody>
      <?php foreach($affOwed as $t): $a=kb_aff_by_code($t['ref_code']); $com=kb_ticket_commission($t); $fl=$FLAGS[$t['id']] ?? []; ?>
        <tr>
          <td><a href="?t=<?=h($t['token'])?>"><?=h($t['business']?:$t['name'])?></a></td>
          <td><?=h($a['name'] ?? $t['ref_code'])?><?php if($a): $tt=kb_tier_info(kb_aff_tier($a)); ?> <span class="meta"><?=$tt[1]?></span><?php endif; ?></td>
          <td class="meta" style="max-width:200px;white-space:pre-wrap"><?=h(($a['payout'] ?? '') ?: '—')?></td>
          <td style="color:#8fd6a6;font-weight:700;white-space:nowrap">~<?=money($com)?><?php if($fl): ?> <span class="flag" title="Possible self-referral: <?=h(implode(' ', $fl))?>">⚠</span><?php endif; ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline"><input type="hidden" name="action" value="commpaid"><input type="hidden" name="token" value="<?=h($t['token'])?>"><button class="btn sm" type="submit">Mark paid</button></form>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="commvoid"><input type="hidden" name="token" value="<?=h($t['token'])?>"><button class="btn ghost sm" type="submit" title="No commission owed (e.g. self-referral)">Void</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <?php $review = array_filter($all, fn($t)=>!empty($t['ref_code']) && (isset($FLAGS[$t['id']]) || !empty($t['comm_void']))); if($review): ?>
  <div class="card">
    <h2>⚠ Referrals to review</h2>
    <p class="hint" style="margin-bottom:10px">Referred requests where the partner may be the buyer, and commissions you voided. Voided commissions are left out of every total.</p>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Client</th><th>Affiliate</th><th>Why</th><th>Commission</th><th></th></tr></thead>
      <tbody>
      <?php foreach($review as $t): $a=kb_aff_by_code($t['ref_code']); $fl=$FLAGS[$t['id']] ?? []; $vd=!empty($t['comm_void']); ?>
        <tr>
          <td><a href="?t=<?=h($t['token'])?>"><?=h($t['business']?:$t['name'])?></a><div class="meta"><?=h($STAGES[$t['status']]??$t['status'])?><?= $t['paid']?' · paid':'' ?></div></td>
          <td><?=h($a['name'] ?? $t['ref_code'])?></td>
          <td class="meta" style="max-width:280px"><?php if($fl): ?><span class="flag">⚠</span> <?=h(implode(' ', $fl))?><?php else: ?>—<?php endif; ?></td>
          <td><span class="<?=$vd?'void':''?>">~<?=money((float)$t['value']*kb_ticket_pct($t)/100)?></span><?= $vd ? '<div class="meta" style="color:#e0906a">voided</div>' : '' ?></td>
          <td><form method="post"><input type="hidden" name="action" value="<?=$vd?'communvoid':'commvoid'?>"><input type="hidden" name="token" value="<?=h($t['token'])?>"><button class="btn ghost sm" type="submit"><?=$vd?'Un-void':'Void'?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>All partners</h2>
    <?php $affs = kb_db()->query("SELECT * FROM affiliates WHERE is_affiliate=1 ORDER BY id DESC")->fetchAll(); if(!$affs): ?>
      <p class="meta">No partners yet. People who sign up become affiliates only after opting in at <b><?=h(($KB_SITE ?? 'https://kbsites.com.br'))?>/account/partner</b>.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Partner</th><th>Code</th><th>Rank</th><th>Leads</th><th>Sales</th><th>Earned</th><th>Owed</th></tr></thead>
      <tbody>
      <?php foreach($affs as $a):
        $mine = array_filter($all, fn($t)=>$t['ref_code']===$a['code']);
        $sales = array_filter($mine, fn($t)=>$t['paid'] && empty($t['comm_void']));
        $earned = array_sum(array_map(fn($t)=>$t['paid']?kb_ticket_commission($t):0, $mine));   // voided = 0
        $owed = array_sum(array_map(fn($t)=>($t['paid']&&!$t['commission_paid'])?kb_ticket_commission($t):0, $mine));
        $nFlag = count(array_filter($mine, fn($t)=>isset($FLAGS[$t['id']])));
        $aTier=kb_tier_info(kb_aff_tier($a)); $aPts=kb_aff_points($a['id']);
      ?>
        <tr>
          <td><b><?=h($a['name'])?></b><?= $nFlag ? ' <span class="flag" title="'.$nFlag.' possible self-referral'.($nFlag===1?'':'s').'">⚠</span>' : '' ?><div class="meta"><?=h($a['email'])?><?= $a['verified']?'':' · <span style="color:#e6c06a">unverified</span>' ?><?= !empty($a['pix_key'])?'':' · <span style="color:#e6c06a">no Pix</span>' ?></div></td>
          <td><?=h($a['code'])?></td>
          <td style="white-space:nowrap"><span title="<?=$aPts?> point<?=$aPts===1?'':'s'?>"><?=$aTier[1]?> <?=h($aTier[0])?></span></td>
          <td><?=count($mine)?></td>
          <td><?=count($sales)?></td>
          <td>~<?=money($earned)?></td>
          <td><?= $owed>0?'<b style="color:#e6c06a">~'.money($owed).'</b>':'—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view==='members'): // ===== MEMBERS ===== ?>
  <h1 style="margin-bottom:6px">Members</h1>
  <p class="hint" style="margin-bottom:14px">Everyone who signed up. Add a <b>moderator</b> by email (the email must already have an account), see how clients rate each moderator's service, and manage accounts.</p>
  <?php
    $users = kb_db()->query("SELECT * FROM affiliates ORDER BY id DESC")->fetchAll();
    $extra = kb_extra_admins(); $ownerEmail = kb_admin_email();
  ?>
  <div class="card">
    <h2>Moderators &amp; service ratings</h2>
    <p class="hint" style="margin-bottom:10px">Moderators can open this dashboard and answer clients. Add one by email — if no account has that email, nobody is added (ask them to sign up first). Clients rate each conversation, and the average per moderator shows here.</p>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
      <input type="hidden" name="action" value="mkadmin">
      <div style="flex:1;min-width:220px"><label>Add moderator by email</label><input type="email" name="email" placeholder="person@email.com" required></div>
      <button class="btn" type="submit">Add moderator</button>
    </form>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Moderator</th><th>Avg rating</th><th>Ratings</th><th></th></tr></thead>
      <tbody>
      <?php foreach(kb_mods() as $mod):
        $stat = !empty($mod['id']) ? kb_mod_rating_stats($mod['id']) : [0, 0.0];
        $cnt = $stat[0]; $avg = $stat[1]; $rnd = (int)round($avg);
        $starsTxt = $cnt ? str_repeat('★', $rnd) . str_repeat('☆', 5 - $rnd) : '—';
      ?>
        <tr>
          <td><b><?=h($mod['name'])?></b><?= !empty($mod['is_owner']) ? ' <span class="badge b-paid">owner</span>' : '' ?><?= !empty($mod['no_account']) ? ' <span class="badge b-lost">no account yet</span>' : '' ?><div class="meta"><?=h($mod['email'])?></div></td>
          <td><span class="stars-sm"><?=$starsTxt?></span> <?= $cnt ? '<b>'.number_format($avg,1).'</b>' : '' ?></td>
          <td class="meta"><?=$cnt?></td>
          <td><?php if(empty($mod['is_owner'])): ?><form method="post"><input type="hidden" name="action" value="rmadmin"><input type="hidden" name="email" value="<?=h($mod['email'])?>"><button class="btn ghost sm" type="submit">Remove</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php $recent = kb_db()->query("SELECT r.*, t.token ttoken, t.business tbiz, t.name tname, a.name modname FROM mod_ratings r LEFT JOIN tickets t ON t.id=r.ticket_id LEFT JOIN affiliates a ON a.id=r.mod_id ORDER BY r.id DESC LIMIT 12")->fetchAll(); if($recent): ?>
      <h2 style="margin-top:18px">Recent ratings</h2>
      <?php foreach($recent as $rr2): $sc=(int)$rr2['stars']; ?>
        <div class="row-a">
          <span>
            <span class="who"><span class="stars-sm"><?=str_repeat('★',$sc).str_repeat('☆',5-$sc)?></span> · <?=h($rr2['modname'] ?: 'moderator')?></span>
            <span class="snip"><?= $rr2['comment'] ? h($rr2['comment']) : '<i>no comment</i>' ?> — <?=h($rr2['tbiz'] ?: $rr2['tname'] ?: 'client')?><?php if(!empty($rr2['ttoken'])): ?> · <a href="?t=<?=h($rr2['ttoken'])?>">open</a><?php endif; ?></span>
          </span>
          <span class="meta" style="white-space:nowrap"><?=h(substr((string)$rr2['created_at'],0,10))?></span>
        </div>
      <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>All accounts</h2>
    <?php if(!$users): ?><p class="meta">No accounts yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Member</th><th>Role</th><th>Joined</th><th>Admin</th><th></th></tr></thead>
      <tbody>
      <?php foreach($users as $u):
        $em = strtolower(trim($u['email']));
        $isOwner = ($em === $ownerEmail);
        $isAdm = $isOwner || in_array($em,$extra,true);
        $role = $isOwner ? 'owner' : ($u['is_affiliate'] ? 'affiliate' : 'buyer');
        $rc = ['owner'=>'b-paid','affiliate'=>'b-proposal','buyer'=>'b-new'][$role];
      ?>
        <tr>
          <td><b><?=h($u['name'])?></b><div class="meta"><?=h($u['email'])?></div></td>
          <td><span class="badge <?=$rc?>"><?=$role?></span><?= $isAdm && !$isOwner ? ' <span class="badge b-delivered">admin</span>':'' ?></td>
          <td class="meta"><?=h(substr($u['created_at']??'',0,10))?></td>
          <td>
            <?php if($isOwner): ?><span class="meta">owner</span>
            <?php elseif(in_array($em,$extra,true)): ?>
              <form method="post"><input type="hidden" name="action" value="rmadmin"><input type="hidden" name="email" value="<?=h($em)?>"><button class="btn ghost sm" type="submit">Remove admin</button></form>
            <?php else: ?>
              <form method="post"><input type="hidden" name="action" value="mkadmin"><input type="hidden" name="email" value="<?=h($em)?>"><button class="btn ghost sm" type="submit">Make admin</button></form>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!$isOwner): ?>
              <form method="post" onsubmit="return confirm('Delete this account permanently?')"><input type="hidden" name="action" value="deluser"><input type="hidden" name="uid" value="<?=h($u['id'])?>"><button class="btn ghost sm" style="color:#e0906a;border-color:rgba(224,144,106,.4)" type="submit">Delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view==='emails'): // ===== EMAILS ===== ?>
  <?php
    $CAT     = kb_mail_catalogue();
    $pv      = preg_replace('/[^a-z_]/', '', (string)($_GET['preview'] ?? ''));
    $pvStage = preg_replace('/[^a-z]/', '', (string)($_GET['stage'] ?? 'proposal'));
    if (!isset($STAGES[$pvStage]) || $pvStage === 'new') $pvStage = 'proposal';
    $logRows = kb_db()->query("SELECT l.*, t.token FROM email_log l LEFT JOIN tickets t ON t.id=l.ticket_id ORDER BY l.id DESC LIMIT 50")->fetchAll();
  ?>
  <h1 style="margin-bottom:6px">Emails</h1>
  <p class="hint" style="margin-bottom:14px">Branded emails the site sends by itself. Switch each one on or off, and preview it with sample data. When someone replies to one, it goes to your inbox.</p>

  <?php if ($pv !== '' && isset($CAT[$pv])): $R = kb_mail_render($pv, kb_mail_sample($pv, $pvStage)); ?>
  <div class="card">
    <p class="meta" style="margin-bottom:6px"><a href="?view=emails">← All emails</a></p>
    <h2>Preview · <?=h($CAT[$pv]['label'])?></h2>
    <?php if ($pv === 'status_update'): ?>
      <p class="meta" style="margin-bottom:8px">Stage:
        <?php foreach($STAGES as $sk=>$sl): if($sk==='new') continue; ?>
          <a class="badge <?=$sk===$pvStage?stage_class($sk):'b-off'?>" href="<?=h('?view=emails&preview=status_update&stage='.$sk)?>"><?=h($sl)?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if ($R): ?>
      <p class="meta">Subject: <b style="color:var(--ink)"><?=h($R['subject'])?></b> · sample data</p>
      <iframe class="mailprev" sandbox="" title="Email preview" srcdoc="<?=h($R['html'])?>"></iframe>
      <details><summary>Plain-text version</summary><pre class="txt"><?=h($R['text'])?></pre></details>
    <?php else: ?>
      <p class="meta">Nothing is sent in this case.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>All emails</h2>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>Email</th><th>To</th><th>When it's sent</th><th>Subject</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($CAT as $ck=>$c): $on = kb_mail_enabled($ck); ?>
        <tr>
          <td><b><?=h($c['label'])?></b><div class="meta"><code><?=h($ck)?></code> · <a href="<?=h('?view=emails&preview='.$ck)?>">Preview</a></div></td>
          <td class="meta"><?=h($c['audience'])?></td>
          <td class="meta" style="min-width:200px"><?=h($c['trigger'])?></td>
          <td class="meta" style="min-width:150px"><?=h($c['subject'])?></td>
          <td style="white-space:nowrap">
            <span class="badge <?=$on?'b-on':'b-off'?>"><?=$on?'ON':'OFF'?></span>
            <?php if(empty($c['same_as'])): ?>
              <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="mailtoggle"><input type="hidden" name="kind" value="<?=h($ck)?>"><input type="hidden" name="on" value="<?=$on?'0':'1'?>">
                <button class="btn <?=$on?'ghost ':''?>sm" type="submit"><?=$on?'Turn off':'Turn on'?></button></form>
            <?php else: ?>
              <div class="meta" style="margin-top:6px">follows "<?=h($CAT[$c['same_as']]['label'] ?? $c['same_as'])?>"</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card">
    <h2>Recent sends</h2>
    <?php if(!$logRows): ?><p class="meta">Nothing sent yet. Every email the site tries to send is listed here (last 50).</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>When</th><th>Email</th><th>To</th><th>Ticket</th><th>Subject</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach($logRows as $l): ?>
        <tr>
          <td class="meta" style="white-space:nowrap"><?=h(substr((string)$l['created_at'],0,16))?></td>
          <td class="meta"><?=h($CAT[$l['kind']]['label'] ?? $l['kind'])?></td>
          <td class="meta"><?=h($l['to_email'])?></td>
          <td><?php if(!empty($l['token'])): ?><a href="?t=<?=h($l['token'])?>">#<?=h($l['ticket_id'])?></a><?php elseif(!empty($l['ticket_id'])): ?><span class="meta">#<?=h($l['ticket_id'])?></span><?php else: ?><span class="meta">—</span><?php endif; ?></td>
          <td class="meta"><?=h($l['subject'])?></td>
          <td><?= $l['ok'] ? '<span class="badge b-on">sent</span>' : '<span class="badge b-lost">failed</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view==='settings'): // ===== SETTINGS ===== ?>
  <h1 style="margin-bottom:14px">Settings</h1>
  <div class="card">
    <h2>PayPal payment link</h2>
    <p class="hint" style="margin-bottom:10px">Your PayPal.Me link (e.g. <code>https://paypal.me/yourname</code>). On each ticket it shows ready to send, with the price filled in automatically.</p>
    <form method="post">
      <input type="hidden" name="action" value="paypal">
      <input name="paypal_link" value="<?=h($paypal)?>" placeholder="https://paypal.me/yourname">
      <div style="margin-top:12px"><button class="btn" type="submit">Save link</button></div>
    </form>
  </div>
  <div class="card">
    <h2>Ranks (patentes) &amp; commission</h2>
    <p class="hint" style="margin-bottom:10px">Partners earn <b>1 point</b> for each referred client you mark <b>Paid</b>, and rank up automatically. Set each rank's points needed and its <b>approximate %</b> of the site build price. Commissions are paid in reais via Pix — the amount is approximate because of USD→BRL conversion. Monthly fees are never commissioned.</p>
    <form method="post">
      <input type="hidden" name="action" value="tiers_cfg">
      <div style="overflow-x:auto"><table>
        <thead><tr><th>Rank</th><th>Points needed</th><th>~ Commission %</th><th>Disclosed band</th></tr></thead>
        <tbody>
        <?php foreach(kb_tiers() as $tk2=>$t): ?>
          <tr>
            <td style="white-space:nowrap"><b><?=$t[1]?> <?=h($t[0])?></b></td>
            <td style="max-width:120px"><input type="number" step="1" min="0" name="min_<?=$tk2?>" value="<?=h((int)$t[2])?>"></td>
            <td style="max-width:120px"><input type="number" step="0.5" min="0" max="100" name="pct_<?=$tk2?>" value="<?=h($t[3])?>"></td>
            <td class="hint" style="white-space:nowrap"><?=$t[4][0]?>–<?=$t[4][1]?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div style="margin-top:12px"><button class="btn" type="submit">Save ranks</button></div>
      <p class="hint" style="margin-top:6px">The band (e.g. 3–7%) is what the Affiliate Agreement discloses to partners — keep each rank's % inside its band.</p>
    </form>
  </div>
  <div class="card">
    <h2>Sign in with Google</h2>
    <?php $gcid = kb_setting_get('google_client_id'); $gsec = kb_setting_get('google_client_secret'); ?>
    <p class="hint" style="margin-bottom:10px">In Google Cloud Console, create an OAuth Client ID (type: Web application). Add this exact URL as an <b>Authorized redirect URI</b>, then paste the Client ID and Secret below.</p>
    <label>Authorized redirect URI (copy into Google)</label>
    <div class="copy"><input id="gredir" readonly value="https://kbsites.com.br/account/google.php"><button class="btn sm" type="button" onclick="cp('gredir')">Copy</button></div>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="action" value="google_cfg">
      <label>Client ID</label><input name="google_client_id" value="<?=h($gcid)?>" placeholder="xxxxx.apps.googleusercontent.com">
      <label style="margin-top:10px">Client Secret <?= $gsec ? '<span style="color:#8fd6a6">· saved</span>' : '' ?></label>
      <input name="google_client_secret" type="password" placeholder="<?= $gsec ? 'leave blank to keep saved secret' : 'paste the client secret' ?>">
      <div style="margin-top:12px"><button class="btn" type="submit">Save Google settings</button>
        <span class="hint" style="margin-left:10px"><?= ($gcid && $gsec) ? '✓ Google sign-in is active' : 'not active yet' ?></span></div>
    </form>
  </div>
  <div class="card">
    <h2>Change password</h2>
    <form method="post">
      <input type="hidden" name="action" value="chpass">
      <div class="grid2">
        <div><label>Current password</label><input type="password" name="current" required></div>
        <div><label>New password</label><input type="password" name="newpass" required></div>
      </div>
      <div style="margin-top:12px"><button class="btn" type="submit">Update password</button></div>
    </form>
  </div>

<?php else: // ===== OVERVIEW ===== ?>
  <h1 style="margin-bottom:14px">Overview</h1>
  <div class="stats">
    <div class="stat"><div class="n"><?=$cNew?></div><div class="l">New tickets</div></div>
    <div class="stat"><div class="n"><?=count($active)?></div><div class="l">Open deals</div></div>
    <div class="stat amber"><div class="n"><?=$cProp?></div><div class="l">Proposals out</div></div>
    <div class="stat green"><div class="n"><?=count($won)?></div><div class="l">Won</div></div>
    <div class="stat green"><div class="n"><?=money($revenue)?></div><div class="l">Revenue</div></div>
    <div class="stat"><div class="n"><?=money($mrr)?><span style="font-size:.9rem;color:var(--muted)">/mo</span></div><div class="l">Monthly recurring (MRR)</div></div>
  </div>
  <div class="card fun">
    <h2>Funnel</h2>
    <?php
      $order = ['new','replied','proposal','paid','delivered','closed'];
      $counts = []; foreach($order as $s){ $counts[$s]=count(array_filter($all, fn($t)=>$t['status']===$s)); }
      $max = max(1, max($counts ?: [1]));
      foreach($order as $s): ?>
      <div class="row"><span><?=$STAGES[$s]?></span><div class="bar"><div class="fill" style="width:<?=($counts[$s]/$max*100)?>%"></div></div><b><?=$counts[$s]?></b></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h2>Latest tickets</h2>
    <?php $latest = array_slice($all,0,5); if(!$latest): ?>
      <p class="meta">Nothing yet — your site's contact widget feeds this.</p>
    <?php else: foreach($latest as $t): ?>
      <a class="row-a" href="?t=<?=h($t['token'])?>">
        <span><span class="who">#<?=h($t['id'])?> · <?=h($t['name'])?></span><span class="snip"><?=h(mb_substr($t['message'],0,70))?>…</span></span>
        <span class="badge <?=stage_class($t['status'])?>"><?=h($STAGES[$t['status']]??$t['status'])?></span>
      </a>
    <?php endforeach; endif; ?>
  </div>
<?php endif; // end views ?>
  </div><!-- #app -->
<?php endif; // end logged ?>
</div>
<script>
function cp(id){var el=document.getElementById(id);el.select();el.setSelectionRange(0,99999);try{document.execCommand('copy');event.target.textContent='Copied ✓';setTimeout(function(){event.target.textContent='Copy'},1500)}catch(e){}}
</script>
<script>
/* Moderator live chat: feed poll, typing, read ticks, AJAX send. Re-inits after a panel.js swap. */
(function(){
  function initChat(){
    var chat=document.getElementById('chat'); if(!chat||chat.dataset.kbInit) return; chat.dataset.kbInit='1';
    var API='/ticket/?chat=1', FEED='/ticket/?chat=feed';
    var token=chat.getAttribute('data-token');
    var log=document.getElementById('chatlog'), typingRow=document.getElementById('typingRow');
    var input=document.getElementById('chatInput'), sendBtn=document.getElementById('chatSend'), errEl=document.getElementById('chatErr');
    var last=parseInt(chat.getAttribute('data-last')||'0',10), clientRead=parseInt(chat.getAttribute('data-read')||'0',10);
    function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
    function atBottom(){return log.scrollHeight-log.scrollTop-log.clientHeight<90;}
    function toBottom(){log.scrollTop=log.scrollHeight;}
    function tickHtml(read){return '<span class="tick'+(read?' read':'')+'">'+(read?'✓✓':'✓')+'</span>';}
    function addMsg(m){
      if(m.id&&log.querySelector('.b[data-id="'+m.id+'"]')) return;
      var b=document.createElement('div');b.className='b '+(m.who==='you'?'you':'them');if(m.id!=null)b.setAttribute('data-id',m.id);
      b.innerHTML=esc(m.body)+'<div class="meta2">'+esc(m.at||'')+' '+(m.who==='you'?tickHtml(m.id<=clientRead):'')+'</div>';
      log.insertBefore(b,typingRow); if(m.id&&m.id>last) last=m.id;
    }
    function updateTicks(){var bs=log.querySelectorAll('.b.you[data-id]');for(var i=0;i<bs.length;i++){var id=parseInt(bs[i].getAttribute('data-id'),10);var t=bs[i].querySelector('.tick');if(t){var read=id<=clientRead;t.className='tick'+(read?' read':'');t.textContent=read?'✓✓':'✓';}}}
    function autoGrow(){input.style.height='auto';input.style.height=Math.min(150,input.scrollHeight)+'px';}
    function post(action,extra){var fd=new FormData();fd.append('action',action);fd.append('t',token);if(extra)for(var k in extra)fd.append(k,extra[k]);return fetch(API,{method:'POST',body:fd,credentials:'same-origin'});}
    var sending=false;
    function send(){var body=(input.value||'').trim();if(!body||sending)return;sending=true;errEl.classList.remove('show');
      post('asend',{body:body}).then(function(r){return r.json();}).then(function(j){sending=false;
        if(j&&j.ok){addMsg({id:j.id,who:'you',body:body,at:j.at});input.value='';autoGrow();toBottom();stopTyping();}
        else{errEl.textContent='Could not send — try again.';errEl.classList.add('show');}
      }).catch(function(){sending=false;errEl.textContent='Network error — try again.';errEl.classList.add('show');});}
    sendBtn.addEventListener('click',send);
    input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
    var typingOn=false,lastPing=0,stopTimer=null;
    function typing(){var now=Date.now();if(now-lastPing>2500){lastPing=now;typingOn=true;post('atyping',{on:'1'});}clearTimeout(stopTimer);stopTimer=setTimeout(stopTyping,4000);}
    function stopTyping(){clearTimeout(stopTimer);if(typingOn){typingOn=false;lastPing=0;post('atyping',{});}}
    input.addEventListener('input',function(){autoGrow();typing();});
    input.addEventListener('blur',stopTyping);
    function markRead(){post('aread',{upto:last});}
    var polling=false,timer=setInterval(poll,2000);
    function poll(){
      if(!document.body.contains(chat)){clearInterval(timer);return;}
      if(polling||document.hidden)return; polling=true;
      fetch(FEED+'&t='+encodeURIComponent(token)+'&after='+last,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        polling=false; if(!j||!j.ok)return; var stick=atBottom(),newThem=false;
        (j.msgs||[]).forEach(function(m){var had=m.id&&log.querySelector('.b[data-id="'+m.id+'"]');addMsg(m);if(!had&&m.who==='them')newThem=true;});
        if(typeof j.read_id==='number'&&j.read_id>clientRead){clientRead=j.read_id;updateTicks();}
        if(typingRow)typingRow.classList.toggle('show',!!j.typing);
        if(newThem){markRead();if(stick)toBottom();} else if(j.typing&&stick){toBottom();}
      }).catch(function(){polling=false;});
    }
    document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
    autoGrow();toBottom();markRead();poll();
  }
  if(document.readyState!=='loading') initChat(); else document.addEventListener('DOMContentLoaded',initChat);
  document.addEventListener('kb:swapped',initChat);
})();
</script>
<script src="/panel.js"></script>
</body></html>
