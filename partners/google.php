<?php
// Sign in with Google for the Partner Program (server-side OAuth 2.0, no SDK).
require __DIR__ . '/../db.php';
@include __DIR__ . '/../config.php';
kb_session_start();

$host     = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'kbsites.com.br');
$REDIRECT = 'https://' . $host . '/partners/google.php';
$CID      = kb_google_client_id();
$SECRET   = kb_google_client_secret();

function gp_fail($code){ header('Location: index.php?gerr=' . $code); exit; }

if (!$CID || !$SECRET) gp_fail('notcfg');
if (isset($_GET['error']))  gp_fail('denied');

// ---------- callback: Google returned with a code ----------
if (isset($_GET['code'])) {
  if (empty($_GET['state']) || empty($_SESSION['g_state']) || !hash_equals($_SESSION['g_state'], $_GET['state'])) gp_fail('state');
  unset($_SESSION['g_state']);

  // exchange the code for tokens
  $post = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => $CID,
    'client_secret' => $SECRET,
    'redirect_uri'  => $REDIRECT,
    'grant_type'    => 'authorization_code',
  ]);
  $tok = gp_http_post('https://oauth2.googleapis.com/token', $post);
  $tokJson = json_decode($tok, true);
  if (empty($tokJson['access_token'])) gp_fail('token');

  // fetch the user's basic profile
  $ui = gp_http_get('https://openidconnect.googleapis.com/v1/userinfo', $tokJson['access_token']);
  $u  = json_decode($ui, true);
  $email = strtolower(trim($u['email'] ?? ''));
  $sub   = $u['sub'] ?? '';
  $name  = trim($u['name'] ?? '') ?: ($email ? explode('@', $email)[0] : 'Partner');
  if ($email === '' || $sub === '') gp_fail('profile');

  // find or create the affiliate
  $aff = kb_aff_by_email($email);
  if ($aff) {
    if (empty($aff['google_sub'])) kb_db()->prepare("UPDATE affiliates SET google_sub=?, verified=1 WHERE id=?")->execute([$sub, $aff['id']]);
    if ($aff['status'] === 'suspended') gp_fail('suspended');
    $_SESSION['kb_aff'] = $aff['id'];
  } else {
    $code = kb_aff_code();
    kb_db()->prepare("INSERT INTO affiliates(code,name,email,google_sub,verified,status) VALUES(?,?,?,?,1,'active')")
           ->execute([$code, $name, $email, $sub]);
    $_SESSION['kb_aff'] = (int) kb_db()->lastInsertId();
  }
  header('Location: index.php'); exit;
}

// ---------- start: send the user to Google ----------
$state = bin2hex(random_bytes(16));
$_SESSION['g_state'] = $state;
$auth = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
  'client_id'     => $CID,
  'redirect_uri'  => $REDIRECT,
  'response_type' => 'code',
  'scope'         => 'openid email profile',
  'state'         => $state,
  'access_type'   => 'online',
  'prompt'        => 'select_account',
]);
header('Location: ' . $auth); exit;

// ---------- tiny HTTP helpers ----------
function gp_http_post($url, $body) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
      CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $r = curl_exec($ch); curl_close($ch); return $r;
  }
  return @file_get_contents($url, false, stream_context_create(['http'=>['method'=>'POST',
    'header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$body,'timeout'=>12,'ignore_errors'=>true]]));
}
function gp_http_get($url, $bearer) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
      CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $bearer]]);
    $r = curl_exec($ch); curl_close($ch); return $r;
  }
  return @file_get_contents($url, false, stream_context_create(['http'=>['method'=>'GET',
    'header'=>'Authorization: Bearer ' . $bearer,'timeout'=>12,'ignore_errors'=>true]]));
}
