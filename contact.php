<?php
// Homepage quote form -> ticket in the dashboard + chat in the client's account.
// Signed-in accounts only; notifies the studio (email + Discord + bell) and the client.
require_once __DIR__ . '/db.php';
@include __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit;
}

// Honeypot: if filled, silently accept (bot)
if (!empty($_POST['botcheck'])) { echo json_encode(['success' => true]); exit; }

// Requests need an account: the reply lands in the client's account chat.
kb_session_start();
$me = kb_auth_user();
if (!$me) {
  http_response_code(401);
  echo json_encode(['success' => false, 'login_required' => true, 'message' => 'Please sign in to send your request.']);
  exit;
}

// Rate limit per IP
if (!kb_rate_ok('contact_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 12, 600)) {
  http_response_code(429);
  echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again in a few minutes.']); exit;
}

// Terms must be accepted
if (empty($_POST['agree_terms'])) {
  echo json_encode(['success' => false, 'message' => 'Please accept the Terms and Privacy Policy.']);
  exit;
}

$name     = kb_post_str('name', 120);
$business = kb_post_str('business', 160);
$message  = kb_post_str('message', 8000);
$current  = kb_post_str('current_site', 500);
$gprofile = kb_post_str('google_profile', 500);
$plink    = kb_post_str('photos', 500);
$email    = trim((string)$me['email']);                 // always the (verified) account email
if ($name === '') $name = trim((string)($me['name'] ?? ''));
if ($name === '') $name = (string)strstr($email, '@', true);

// Plan: one of kb_plans() keys; empty = not chosen yet
$plan   = null;
$planIn = kb_post_str('plan', 20);
if ($planIn !== '') {
  if (!kb_plan($planIn)) { echo json_encode(['success' => false, 'message' => 'Please choose one of the plans.']); exit; }
  $plan = $planIn;
}
$planTxt = $plan ? kb_plan($plan)['label'] . ' — ' . kb_plan_price($plan) : 'Not chosen';

// ---- Collect up to 10 image attachments ----
$MAX_FILES = 10;
$MAX_EACH  = 8 * 1024 * 1024; // 8 MB per file
$files = [];
$tooMany = false;

if (!empty($_FILES['attachment']) && is_array($_FILES['attachment']['name'])) {
  $f = $_FILES['attachment'];
  $count = count($f['name']);
  if ($count > $MAX_FILES) { $tooMany = true; }
  for ($i = 0; $i < $count && count($files) < $MAX_FILES; $i++) {
    if ($f['error'][$i] === UPLOAD_ERR_OK && $f['size'][$i] > 0 && $f['size'][$i] <= $MAX_EACH) {
      // Validate it's a REAL image by decoding its header, not by the sent MIME/extension.
      $info = @getimagesize($f['tmp_name'][$i]);
      $map  = [IMAGETYPE_JPEG=>['image/jpeg','jpg'], IMAGETYPE_PNG=>['image/png','png'],
               IMAGETYPE_GIF=>['image/gif','gif'], IMAGETYPE_WEBP=>['image/webp','webp']];
      if ($info && isset($map[$info[2]])) {
        [$mime, $ext] = $map[$info[2]];
        $files[] = [
          'name' => 'photo-' . ($i + 1) . '.' . $ext,   // safe, forced extension — never the uploaded name
          'type' => $mime,
          'data' => file_get_contents($f['tmp_name'][$i]),
        ];
      }
    }
  }
}

if ($tooMany) {
  echo json_encode(['success' => false, 'message' => 'Please attach up to 10 photos. For more, paste a link (Drive / Google Photos) instead.']);
  exit;
}

// ---- Referral attribution (form field, else the kb_ref cookie) ----
// Never credit a partner for their own request: silently ignored.
$refIn = kb_post_str('ref', 40);
if ($refIn === '') $refIn = is_string($_COOKIE['kb_ref'] ?? null) ? $_COOKIE['kb_ref'] : '';
$ref  = preg_replace('/[^A-Za-z0-9]/', '', $refIn);
$refA = null;
if ($ref !== '') {
  $ra = kb_aff_by_code($ref);
  if ($ra && (int)$ra['id'] !== (int)$me['id'] && strtolower(trim((string)$ra['email'])) !== strtolower($email)) $refA = $ra;
}
$refCode = $refA ? $refA['code'] : null;
$refName = $refA ? $refA['name'] : '';

// ---- Save as a lead/ticket in the dashboard ----
$ticketMsg  = ($message !== '' ? $message : '(no message)');
$ticketMsg .= "\n\nPlan: " . $planTxt;
$ticketMsg .= "\nCurrent site: " . ($current !== '' ? $current : '-');
$ticketMsg .= "\nGoogle profile: " . ($gprofile !== '' ? $gprofile : '-');
$ticketMsg .= "\nPhotos link: " . ($plink !== '' ? $plink : '-');
$ticketMsg .= "\nAttached photos: " . count($files);
$ticketToken = kb_token();
try {
  kb_db()->prepare("INSERT INTO tickets(token,name,email,business,message,ref_code,user_id,plan,ip,dev) VALUES(?,?,?,?,?,?,?,?,?,?)")
         ->execute([$ticketToken, $name, $email, $business, $ticketMsg, $refCode, $me['id'], $plan, kb_client_ip(), kb_device_id()]);
  $ticketId = (int) kb_db()->lastInsertId();
  $adm = kb_admin_user();
  if ($adm) kb_notify($adm['id'], $ticketId, 'new_ticket', 'New website request from ' . ($business ?: $name), '/ticket/' . $ticketToken);
} catch (Exception $e) { $ticketId = 0; }

// Ticket saved = success: answer now, send the notifications after.
if ($ticketId) kb_respond_early(['success' => true, 'chat' => '/account/messages/' . $ticketToken]);

// ---- Email to the studio (with the photos attached) ----
$to      = !empty($KB_NOTIFY_EMAIL) ? $KB_NOTIFY_EMAIL : kb_admin_email();
$subject = kb_hdr('New website request - ' . ($business !== '' ? $business : $name));

$bodyText  = "New request from the KB Sites website\n\n";
$bodyText .= "Name: $name\n";
$bodyText .= "Business: $business\n";
$bodyText .= "Email: $email (account #" . (int)$me['id'] . ")\n";
$bodyText .= "Plan: $planTxt\n";
$bodyText .= "Current website: " . ($current !== '' ? $current : '-') . "\n";
$bodyText .= "Google Business profile: " . ($gprofile !== '' ? $gprofile : '-') . "\n";
$bodyText .= "Photos link: " . ($plink !== '' ? $plink : '-') . "\n";
$bodyText .= "Attached photos: " . count($files) . "\n";
if ($refCode) $bodyText .= "Referred by: $refName ($refCode)\n";
$bodyText .= "\nMessage:\n" . ($message !== '' ? $message : '-') . "\n";

$host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'kbsites.com.br');
$from = 'noreply@' . $host;
$boundary = 'kb_' . md5(uniqid((string)time(), true));

$headers  = "From: KB Sites <$from>\r\n";
$headers .= "Reply-To: " . kb_hdr($name !== '' ? $name : 'Website') . " <" . kb_hdr($email) . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

$body  = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $bodyText . "\r\n";

foreach ($files as $file) {
  $body .= "--$boundary\r\n";
  $body .= "Content-Type: " . $file['type'] . "; name=\"" . $file['name'] . "\"\r\n";
  $body .= "Content-Transfer-Encoding: base64\r\n";
  $body .= "Content-Disposition: attachment; filename=\"" . $file['name'] . "\"\r\n\r\n";
  $body .= chunk_split(base64_encode($file['data'])) . "\r\n";
}
$body .= "--$boundary--";

$ok = @mail(kb_hdr($to), $subject, $body, $headers);

if (!$ticketId) {
  // Could not save the ticket: the studio email is all we have.
  if ($ok) echo json_encode(['success' => true, 'chat' => '/account/messages']);
  else     echo json_encode(['success' => false, 'message' => 'Something went wrong on our side. Please try again in a minute.']);
  exit;
}

// ---- Client confirmation + partner heads-up (branded; each kind has an on/off switch) ----
kb_mail_html($email, null, 'request_received', [
  'name' => $name, 'business' => $business, 'plan' => $plan, 'token' => $ticketToken, 'ticket_id' => $ticketId,
]);
if ($refA) kb_mail_html($refA['email'], null, 'referral_received', [
  'name' => $refA['name'], 'business' => $business, 'ticket_id' => $ticketId,   // business name only — no client personal data
]);

// ---- Discord notification ----
if (!empty($KB_DISCORD_WEBHOOK)) {
  $site = isset($KB_SITE) && $KB_SITE ? rtrim($KB_SITE, '/') : ('https://' . $host);
  $url  = $site . '/ticket/' . $ticketToken;
  $payload = json_encode([
    'username' => 'KB Sites',
    'content'  => 'New website request **#' . $ticketId . '**',
    'embeds' => [[
      'title'       => 'Request #' . $ticketId . ' — ' . ($business !== '' ? $business : $name),
      'url'         => $url,
      'description' => mb_substr($message !== '' ? $message : '(no message)', 0, 1500),
      'color'       => 14268786,
      'fields'      => array_values(array_filter([
        ['name'=>'Name',  'value'=>($name ?: '-'),  'inline'=>true],
        ['name'=>'Email', 'value'=>($email ?: '-'), 'inline'=>true],
        ['name'=>'Plan',  'value'=>$planTxt,        'inline'=>true],
        $refCode ? ['name'=>'Referred by', 'value'=>$refName.' ('.$refCode.')', 'inline'=>false] : null,
        ['name'=>'Open', 'value'=>$url, 'inline'=>false],
      ])),
    ]],
  ], JSON_UNESCAPED_UNICODE);
  kb_post_json($KB_DISCORD_WEBHOOK, $payload);
}
