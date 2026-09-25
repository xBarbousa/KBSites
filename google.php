<?php
// Sign in with Google using the OpenID Connect id_token (implicit) flow.
// response_type=id_token returns the result in the URL fragment (#...), which the
// browser never sends to the server — so HostGator's ModSecurity never sees the
// `iss=https://...` it blocks with a 406 (it forces query mode for response_type=code).
// A tiny JS bridge reads the fragment and POSTs back the id_token (a base64url JWT,
// no literal https:// in it) which the WAF allows. We verify the token with Google.
require __DIR__ . '/../db.php';
@include __DIR__ . '/../config.php';
kb_session_start();

$host     = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'kbsites.com.br');
$REDIRECT = 'https://' . $host . '/account/google.php';
$CID      = kb_google_client_id();

function gp_fail($code){ header('Location: /account/?gerr=' . $code); exit; }

// where to land after login (?go=1&next=/path — same-site paths only); kept even if we bail out below,
// so the email sign-up the visitor falls back to still returns them there.
if (isset($_GET['go'], $_GET['next'])) {
  $nx = (is_string($_GET['next']) && function_exists('kb_safe_next')) ? kb_safe_next($_GET['next']) : '';
  if ($nx !== '') $_SESSION['kb_next'] = $nx; else unset($_SESSION['kb_next']);
}
if (!$CID) gp_fail('notcfg');

// ---- EXCHANGE: JS bridge POSTs back id_token + state (same-origin, clean) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_token'])) {
  $wantState = $_SESSION['g_state'] ?? ''; $wantNonce = $_SESSION['g_nonce'] ?? '';
  unset($_SESSION['g_state'], $_SESSION['g_nonce']);
  if ($wantState === '' || empty($_POST['state']) || !hash_equals($wantState, $_POST['state'])) gp_fail('state');

  $idt = $_POST['id_token'];
  if ($idt === '' || strlen($idt) > 8192) gp_fail('token');
  // Google validates the JWT signature + expiry for us and returns the claims.
  $info = json_decode(gp_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idt)), true);
  if (!$info || empty($info['sub']) || empty($info['email'])) gp_fail('token');
  if (($info['aud'] ?? '') !== $CID) gp_fail('token');
  $iss = $info['iss'] ?? '';
  if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') gp_fail('token');
  if ($wantNonce !== '' && ($info['nonce'] ?? '') !== $wantNonce) gp_fail('state');
  $ev = $info['email_verified'] ?? 'true';
  if ($ev !== true && $ev !== 'true') gp_fail('profile');

  $email = strtolower(trim($info['email']));
  $sub   = $info['sub'];
  $name  = trim($info['name'] ?? '') ?: explode('@', $email)[0];

  $picture = $info['picture'] ?? '';
  $isNew = false;
  $acc = kb_user_by_email($email);
  if ($acc) {
    if (empty($acc['google_sub'])) kb_db()->prepare("UPDATE affiliates SET google_sub=?, verified=1 WHERE id=?")->execute([$sub,$acc['id']]);
    if (empty($acc['photo']) && $picture) kb_db()->prepare("UPDATE affiliates SET photo=? WHERE id=?")->execute([$picture,$acc['id']]);
    if (($acc['status'] ?? '')==='suspended') gp_fail('suspended');
    $_SESSION['kb_user'] = $acc['id'];
  } else {
    kb_db()->prepare("INSERT INTO affiliates(name,email,google_sub,photo,verified,is_affiliate,status) VALUES(?,?,?,?,1,0,'active')")
           ->execute([$name,$email,$sub,$picture]);
    $_SESSION['kb_user'] = (int) kb_db()->lastInsertId();
    $isNew = true;
  }
  kb_remember_login($_SESSION['kb_user']);
  // first Google login = a brand-new account (the email itself is on/off in the admin)
  if ($isNew && function_exists('kb_mail_html')) {
    try { kb_mail_html($email, null, 'welcome', ['name'=>$name]); } catch (Throwable $e) {}
  }
  // back to where they started (?next on the Google button or the account page), once
  $to = '/account/';
  if (!empty($_SESSION['kb_next']) && function_exists('kb_safe_next')) {
    $n = kb_safe_next((string) $_SESSION['kb_next']);
    if ($n !== '') $to = $n;
  }
  unset($_SESSION['kb_next']);
  header('Location: ' . $to); exit;
}

// ---- START: button links here with ?go=1 (optional &next=/path, stored above) ----
if (isset($_GET['go'])) {
  $state = bin2hex(random_bytes(16));
  $nonce = bin2hex(random_bytes(16));
  $_SESSION['g_state'] = $state;
  $_SESSION['g_nonce'] = $nonce;
  header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'=>$CID, 'redirect_uri'=>$REDIRECT, 'response_type'=>'id_token',
    'scope'=>'openid email profile', 'state'=>$state, 'nonce'=>$nonce,
    'prompt'=>'select_account',
  ])); exit;
}

// ---- LANDING: Google redirected here with #id_token=...&state=... in the fragment ----
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Signing you in…</title>
<link rel="icon" href="/favicon.ico?v=2" sizes="32x32"></head>
<body style="background:#080706;color:#f5efe3;font-family:system-ui,Arial,sans-serif;text-align:center;padding-top:70px">
  <p style="color:#d9b45a;font-size:1.1rem">Signing you in…</p>
  <noscript><p>Please enable JavaScript to finish signing in.</p></noscript>
  <form id="f" method="post" action="google.php" style="display:none">
    <input type="hidden" name="id_token" id="t"><input type="hidden" name="state" id="s">
  </form>
  <script>
  (function(){
    var p = new URLSearchParams(location.hash.slice(1));
    var idt = p.get('id_token'), state = p.get('state'), err = p.get('error');
    if (err) { location.replace('/account/?gerr=denied'); return; }
    if (!idt) { location.replace('/account/'); return; }
    document.getElementById('t').value = idt;
    document.getElementById('s').value = state || '';
    document.getElementById('f').submit();
  })();
  </script>
</body></html>
<?php
function gp_get($url){
  if (function_exists('curl_init')){ $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);
    $r=curl_exec($ch);curl_close($ch);return $r; }
  return @file_get_contents($url,false,stream_context_create(['http'=>['timeout'=>12,'ignore_errors'=>true]]));
}
