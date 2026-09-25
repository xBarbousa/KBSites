<?php
// Support widget submission -> saves to DB, notifies by email + Discord.
// Signed-in accounts only; the conversation continues in the client's account chat.
require __DIR__ . '/db.php';
@include __DIR__ . '/config.php';
kb_session_start();
header('Content-Type: application/json; charset=utf-8');

$DISCORD = isset($KB_DISCORD_WEBHOOK) ? $KB_DISCORD_WEBHOOK : '';
$NOTIFY  = !empty($KB_NOTIFY_EMAIL) ? $KB_NOTIFY_EMAIL : kb_admin_email();
$host    = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'kbsites.com.br');
$SITE    = isset($KB_SITE) && $KB_SITE ? $KB_SITE : ('https://' . $host);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false]); exit; }
if (!empty($_POST['botcheck'])) { echo json_encode(['success'=>true]); exit; }

$me = kb_auth_user();
if (!$me) {
  http_response_code(401);
  echo json_encode(['success'=>false,'login_required'=>true,'message'=>'Please sign in to send your request.']); exit;
}
if (!kb_rate_ok('submit_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 12, 600)) { http_response_code(429); echo json_encode(['success'=>false,'message'=>'Too many messages. Please try again in a few minutes.']); exit; }

$name     = kb_post_str('name', 120);
$business = kb_post_str('business', 160);
$message  = kb_post_str('message', 8000);
$email    = trim((string)$me['email']);                 // always the (verified) account email
if ($name === '') $name = trim((string)($me['name'] ?? ''));
if ($name === '') $name = (string)strstr($email, '@', true);

if ($message === '') {
  echo json_encode(['success'=>false,'message'=>'Please write your message.']); exit;
}

// optional plan (same keys as the quote form); anything else is ignored here
$plan = kb_post_str('plan', 20);
$plan = kb_plan($plan) ? $plan : null;

// referral attribution: code from the form (hidden field) or the kb_ref cookie.
// Never credit a partner for their own request (silently ignored).
$refIn = kb_post_str('ref', 40);
if ($refIn === '') $refIn = is_string($_COOKIE['kb_ref'] ?? null) ? $_COOKIE['kb_ref'] : '';
$ref  = preg_replace('/[^A-Za-z0-9]/', '', $refIn);
$refA = null;
if ($ref !== '') {
  $a = kb_aff_by_code($ref);
  if ($a && (int)$a['id'] !== (int)$me['id'] && strtolower(trim((string)$a['email'])) !== strtolower($email)) $refA = $a;
}
$refCode = $refA ? $refA['code'] : null;
$refName = $refA ? $refA['name'] : '';

$token = kb_token();
try {
  $st = kb_db()->prepare("INSERT INTO tickets(token,name,email,business,message,ref_code,user_id,plan,ip,dev) VALUES(?,?,?,?,?,?,?,?,?,?)");
  $st->execute([$token, $name, $email, $business, $message, $refCode, $me['id'], $plan, kb_client_ip(), kb_device_id()]);
  $id = (int) kb_db()->lastInsertId();
} catch (Exception $e) { $id = 0; }
if (!$id) { echo json_encode(['success'=>false,'message'=>'Something went wrong on our side. Please try again in a minute.']); exit; }
$url = rtrim($SITE, '/') . '/ticket/' . $token;

// in-app notification to the studio
$adm = kb_admin_user();
if ($adm) kb_notify($adm['id'], $id, 'new_ticket', 'New message from ' . ($business ?: $name), '/ticket/' . $token);

// Saved: answer the browser now, send the notifications after.
kb_respond_early(['success'=>true, 'chat'=>'/account/messages/' . $token]);

// ---- email notification to the studio ----
$subject = kb_hdr('New ticket #' . $id . ' - ' . ($business !== '' ? $business : $name));
$body  = "New ticket #$id\n\n";
$body .= "Name: $name\nEmail: $email (account #" . (int)$me['id'] . ")\nBusiness: " . ($business !== '' ? $business : '-') . "\n";
if ($plan) $body .= "Plan: " . kb_plan($plan)['label'] . ' — ' . kb_plan_price($plan) . "\n";
if ($refCode) $body .= "Referred by: $refName ($refCode)\n";
$body .= "\nMessage:\n$message\n\nOpen: $url\n";
$headers  = "From: KB Sites <noreply@$host>\r\n";
$headers .= "Reply-To: " . kb_hdr($name) . " <" . kb_hdr($email) . ">\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
@mail(kb_hdr($NOTIFY), $subject, $body, $headers);

// ---- client confirmation + partner heads-up (branded; on/off switches in the admin) ----
kb_mail_html($email, null, 'support_received', ['name'=>$name, 'business'=>$business, 'token'=>$token, 'ticket_id'=>$id]);
if ($refA) kb_mail_html($refA['email'], null, 'referral_received', ['name'=>$refA['name'], 'business'=>$business, 'ticket_id'=>$id]);

// ---- Discord notification ----
if ($DISCORD) {
  $payload = json_encode([
    'username' => 'KB Sites',
    'content'  => 'New ticket **#' . $id . '**',
    'embeds' => [[
      'title'       => 'Ticket #' . $id . ' — ' . ($business !== '' ? $business : $name),
      'url'         => $url,
      'description' => mb_substr($message, 0, 1500),
      'color'       => 14268786,
      'fields'      => array_values(array_filter([
        ['name'=>'Name',  'value'=>($name ?: '-'),  'inline'=>true],
        ['name'=>'Email', 'value'=>($email ?: '-'), 'inline'=>true],
        $refCode ? ['name'=>'Referred by', 'value'=>$refName.' ('.$refCode.')', 'inline'=>false] : null,
        ['name'=>'Open ticket', 'value'=>$url, 'inline'=>false],
      ])),
    ]],
  ], JSON_UNESCAPED_UNICODE);
  kb_post_json($DISCORD, $payload);
}
