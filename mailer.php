<?php
// Branded transactional emails for KB Sites (loaded by db.php).
// kb_mail_html() renders a black/gold, table-based, inline-CSS email + a plain-text twin,
// sends it as multipart/alternative, honours the per-kind on/off switch
// (settings key mail_on_<kind>) and logs every attempt to email_log.

// Every email kind: who gets it, what triggers it, default subject, default on/off.
// 'same_as' = shares that kind's on/off switch (the support-widget confirmation rides
// on request_received, which the owner asked to be ON for both forms).
function kb_mail_catalogue() {
  return [
    'request_received'   => ['label'=>'Request received',      'audience'=>'Client',          'on'=>true,
                             'trigger'=>'A signed-in visitor sends the quote form on the homepage (contact.php).',
                             'subject'=>'We got your request — KB Sites'],
    'support_received'   => ['label'=>'Message received',      'audience'=>'Client',          'on'=>true, 'same_as'=>'request_received',
                             'trigger'=>'A signed-in visitor sends the support widget (submit.php). Lighter variant of "Request received" — uses the same on/off switch.',
                             'subject'=>'We got your message — KB Sites'],
    'status_update'      => ['label'=>'Status update',         'audience'=>'Client',          'on'=>true,
                             'trigger'=>'You change a ticket\'s stage (deal form with "Email the client" ticked, or a Pipeline move). Never for "New".',
                             'subject'=>'Per stage, e.g. "Your proposal is ready"'],
    'studio_reply'       => ['label'=>'Studio reply',          'audience'=>'Client',          'on'=>true,
                             'trigger'=>'You post a reply on a ticket.',
                             'subject'=>'New reply from KB Sites (request #…)'],
    'welcome'            => ['label'=>'Welcome',               'audience'=>'New account',     'on'=>false,
                             'trigger'=>'An account is created (signup code verified, or first Google sign-in).',
                             'subject'=>'Welcome to KB Sites'],
    'password_changed'   => ['label'=>'Password changed',      'audience'=>'Account owner',   'on'=>false,
                             'trigger'=>'A password is set or changed in account settings (security notice).',
                             'subject'=>'Your KB Sites password was changed'],
    'partner_welcome'    => ['label'=>'Partner welcome',       'audience'=>'New partner',     'on'=>false,
                             'trigger'=>'A user joins the Partner Program.',
                             'subject'=>'Welcome to the KB Sites Partner Program'],
    'referral_received'  => ['label'=>'Referral received',     'audience'=>'Partner',         'on'=>false,
                             'trigger'=>'A request arrives with the partner\'s referral code (only the business name is shared).',
                             'subject'=>'New referral: <business>'],
    'commission_earned'  => ['label'=>'Commission earned',     'audience'=>'Partner',         'on'=>false,
                             'trigger'=>'A referred ticket is marked Paid (deal form or Pipeline). Not sent when the commission is voided or $0.',
                             'subject'=>'You earned a $<amount> commission'],
    'commission_paid'    => ['label'=>'Commission paid',       'audience'=>'Partner',         'on'=>false,
                             'trigger'=>'You mark a commission as paid out.',
                             'subject'=>'Your $<amount> commission was paid'],
    'commission_rate_up' => ['label'=>'Commission rate raised','audience'=>'All partners',    'on'=>false,
                             'trigger'=>'You raise the commission % in Settings AND tick "Announce to partners".',
                             'subject'=>'Your commission just went up to <new>%'],
  ];
}

// Is this kind switched on? (admin toggle, else the catalogue default)
function kb_mail_enabled($kind) {
  $cat = kb_mail_catalogue();
  if (!isset($cat[$kind])) return false;
  $k = $cat[$kind]['same_as'] ?? $kind;
  $v = kb_setting_get('mail_on_' . $k);
  if ($v === null || $v === '') return !empty($cat[$k]['on']);
  return $v === '1';
}

// Absolute site base for links in emails ($KB_SITE from config.php when set).
function kb_mail_site() {
  $s = (isset($GLOBALS['KB_SITE']) && $GLOBALS['KB_SITE']) ? (string)$GLOBALS['KB_SITE'] : 'https://kbsites.com.br';
  return rtrim($s, '/');
}
// Logo shown at the top of branded emails. Empty by default → a clean gold text wordmark
// that renders identically in every email client. Set the 'mail_logo_url' setting to a
// PNG (transparent background, NOT WebP — Gmail/Outlook render WebP poorly) to use an image.
function kb_mail_logo() {
  $v = kb_setting_get('mail_logo_url');
  if ($v !== null && trim((string)$v) !== '') return trim((string)$v);
  return '';
}
// Sending domain, derived from the request host like kb_mail() (sanitised).
function kb_mail_host() {
  $h = strtolower(preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
  $h = preg_replace('/:\d+$/', '', $h);
  if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $h)) $h = 'kbsites.com.br';
  return $h;
}
function kb_mail_chat_url($token) {
  $t = preg_replace('/[^A-Za-z0-9]/', '', (string)$token);
  return kb_mail_site() . '/account/messages' . ($t !== '' ? '/' . $t : '');
}
function kb_mail_first($name) {
  $n = trim((string)$name);
  if ($n === '') return 'there';
  $p = preg_split('/\s+/u', $n);
  return mb_substr($p[0] ?? $n, 0, 40);
}
function kb_mail_money($n) {
  $n = (float)$n;
  return '$' . ($n == floor($n) ? number_format($n, 0) : number_format($n, 2));
}
function kb_mail_pct($p) { return rtrim(rtrim(number_format((float)$p, 1), '0'), '.') . '%'; }
function kb_mail_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function kb_mail_url_ok($u) { return (bool)preg_match('#^https?://[^\s<>"\']+$#i', (string)$u); }

// Content of each kind as a list of blocks (escaped later, once, by the layout).
// Blocks: ['p',text] ['small',text] ['quote',text] ['btn',label,url] ['kv',[[label,value(,url)],…]]
function kb_mail_content($kind, $v) {
  $site    = kb_mail_site();
  $first   = kb_mail_first($v['name'] ?? '');
  $biz     = trim((string)($v['business'] ?? ''));
  $tid     = (int)($v['ticket_id'] ?? 0);
  $chat    = kb_mail_chat_url($v['token'] ?? '');
  $partner = $site . '/account/partner';
  $pct     = isset($v['pct']) ? (float)$v['pct'] : kb_commission_pct();
  $planKey = $v['plan'] ?? null;
  $plan    = kb_plan($planKey);
  $hi      = ['p', "Hi $first,"];

  switch ($kind) {
    case 'request_received':
      $kv = [];
      if ($tid) $kv[] = ['Request', '#' . $tid];
      if ($biz !== '') $kv[] = ['Business', $biz];
      $kv[] = ['Plan', $plan ? $plan['label'] . ' — ' . kb_plan_price($planKey) : 'Not chosen yet — we\'ll help you pick'];
      return [
        'subject' => 'We got your request — KB Sites',
        'pre'     => 'Your request is in. We\'ll review it personally and reply in your chat.',
        'title'   => 'We got your request',
        'blocks'  => [
          $hi,
          ['p', 'Thanks for reaching out' . ($biz !== '' ? " about a website for $biz" : '') . '. Your request is in — we\'ll review it personally and reply in your chat, and you\'ll get an email when we do.'],
          ['kv', $kv],
          ['p', 'Anything to add? Photos, your logo, websites you like or questions — send them in the chat anytime.'],
          ['btn', 'Open your chat', $chat],
          ['small', 'No payment is needed now: the $150 build fee is only due once you approve your website. Every plan includes 2 revision rounds.'],
        ],
      ];

    case 'support_received':
      return [
        'subject' => 'We got your message — KB Sites',
        'pre'     => 'Thanks for writing. We\'ll reply in your chat soon.',
        'title'   => 'We got your message',
        'blocks'  => array_values(array_filter([
          $hi,
          ['p', 'Thanks for writing to KB Sites. Your message is in, and we\'ll reply in your chat soon — you\'ll get an email when we do.'],
          $tid ? ['kv', [['Message', '#' . $tid]]] : null,
          ['btn', 'Open your chat', $chat],
        ])),
      ];

    case 'status_update':
      $stage = (string)($v['stage'] ?? '');
      $for   = $biz !== '' ? " for $biz" : '';
      $site_url = trim((string)($v['site_url'] ?? ''));
      $btn   = ['btn', 'Open your chat', $chat];
      switch ($stage) {
        case 'replied':
          return ['subject'=>'We\'ve reviewed your request', 'pre'=>'We left you a message in your chat.', 'title'=>'We\'ve reviewed your request',
            'blocks'=>[$hi,
              ['p', "Good news: we've reviewed your request$for and left you a message in your chat. Take a look when you have a minute."],
              $btn]];
        case 'proposal':
          return ['subject'=>'Your proposal is ready', 'pre'=>'Everything you need to decide is in your chat.', 'title'=>'Your proposal is ready',
            'blocks'=>[$hi,
              ['p', "Your website proposal$for is ready. Open your chat to see what's included, the price and the next steps."],
              ['p', 'Questions or changes? Just reply in the chat — we\'re happy to adjust.'],
              $btn]];
        case 'paid':
          $b = [$hi, ['p', 'Thank you — your payment is confirmed and we\'re on it. We\'ll keep you posted in your chat.']];
          if ($plan) $b[] = ['kv', [['Plan', $plan['label'] . ' — ' . kb_plan_price($planKey)]]];
          $b[] = $btn;
          return ['subject'=>'Payment confirmed — we\'re starting', 'pre'=>'Thank you! Your payment is confirmed.', 'title'=>'Payment confirmed — we\'re starting', 'blocks'=>$b];
        case 'delivered':
          $live = ($site_url !== '' || $planKey !== 'build');
          $b = [$hi];
          $b[] = ['p', $live ? "Great news: your new website$for is finished and online."
                             : "Great news: your new website$for is finished. Your ready-to-upload files are waiting for you in your chat."];
          if ($site_url !== '') $b[] = ['kv', [['Your website', $site_url, kb_mail_url_ok($site_url) ? $site_url : '']]];
          $b[] = ['p', 'Your plan includes 2 revision rounds ("change requests"). If you\'d like anything adjusted — text, photos, colors — send your list of changes in the chat.'];
          if ($planKey === 'care') $b[] = ['small', 'You\'re on Website + Care: small edits (text, photos, hours, prices) are included whenever you need them.'];
          elseif ($planKey === 'hosting') $b[] = ['small', 'You\'re on Website + Hosting: we keep your site online on KB Sites hosting, with SSL included.'];
          $b[] = $btn;
          $t = $live ? 'Your website is live!' : 'Your website is ready!';
          return ['subject'=>$t, 'pre'=>'Your new website is finished.', 'title'=>$t, 'blocks'=>$b];
        case 'closed':
          return ['subject'=>'Project complete — thank you', 'pre'=>'Thank you for choosing KB Sites.', 'title'=>'Project complete — thank you',
            'blocks'=>[$hi,
              ['p', "Your project$for is complete. Thank you for choosing KB Sites — it was a pleasure building your website."],
              ['p', 'If you ever need a hand with anything, just message us in your chat.'],
              $btn]];
        case 'lost':
          return ['subject'=>'We\'ve closed your request', 'pre'=>'Reply anytime to reopen it.', 'title'=>'We\'ve closed this request',
            'blocks'=>[$hi,
              ['p', "We've closed your website request$for for now."],
              ['p', 'If you\'d still like a website — now or later — just reply in your chat anytime and we\'ll reopen it.'],
              $btn,
              ['small', 'Thanks for considering KB Sites.']]];
      }
      return null; // 'new' / unknown stage: no email

    case 'studio_reply':
      $body = trim((string)($v['body'] ?? ''));
      return [
        'subject' => 'New reply from KB Sites' . ($tid ? " (request #$tid)" : ''),
        'pre'     => mb_substr((string)preg_replace('/\s+/u', ' ', $body), 0, 110),
        'title'   => 'You have a new reply',
        'blocks'  => [
          $hi,
          ['p', 'KB Sites replied to your ' . ($tid ? "request #$tid" : 'request') . ':'],
          ['quote', $body],
          ['btn', 'Reply in your chat', $chat],
          ['small', 'Answering in the chat keeps the whole conversation — and your files — in one place.'],
        ],
      ];

    case 'welcome':
      return [
        'subject' => 'Welcome to KB Sites',
        'pre'     => 'Your account is ready.',
        'title'   => $first !== 'there' ? "Welcome, $first!" : 'Welcome to KB Sites!',
        'blocks'  => [
          ['p', 'Your KB Sites account is ready. Use it to request your website, chat with us about your project and follow every step until it\'s live.'],
          ['btn', 'Go to your account', $site . '/account/'],
          ['small', 'Based in Brazil? Join our Partner Program and earn from ~' . kb_mail_pct(kb_tier_pct('bronze')) . ' up to ~' . kb_mail_pct(kb_tier_pct('platina')) . ' of the site price — paid by Pix — for every client you refer who pays.'],
        ],
      ];

    case 'password_changed':
      $em = trim((string)($v['email'] ?? ($v['_to'] ?? '')));
      return [
        'subject' => 'Your KB Sites password was changed',
        'pre'     => 'Security notice for your KB Sites account.',
        'title'   => 'Your password was changed',
        'blocks'  => [
          $hi,
          ['p', 'The password for your KB Sites account' . ($em !== '' ? " ($em)" : '') . ' was just set or changed.'],
          ['p', 'If this was you, you\'re all set — no action needed.'],
          ['p', 'If it wasn\'t you, reply to this email right away so we can help you secure your account.'],
          ['btn', 'Open your account', $site . '/account/settings'],
        ],
      ];

    case 'partner_welcome':
      $code = preg_replace('/[^A-Za-z0-9]/', '', (string)($v['code'] ?? ''));
      $link = trim((string)($v['link'] ?? ''));
      if ($link === '' && $code !== '') $link = $site . '/?ref=' . $code;
      $kv = [];
      if ($code !== '') $kv[] = ['Your code', $code];
      if ($link !== '') $kv[] = ['Your link', $link, kb_mail_url_ok($link) ? $link : ''];
      return [
        'subject' => 'Welcome to the KB Sites Partner Program',
        'pre'     => 'Your referral link is ready.',
        'title'   => $first !== 'there' ? "You're in, $first!" : 'You\'re in!',
        'blocks'  => array_values(array_filter([
          ['p', 'Welcome to the KB Sites Partner Program. Share your referral link with local businesses that need a website.'],
          $kv ? ['kv', $kv] : null,
          ['p', 'You start at ~' . kb_mail_pct(kb_tier_pct('bronze')) . ' of the site price and rank up as your referred clients pay — up to ~' . kb_mail_pct(kb_tier_pct('platina')) . ' at the top rank. Commissions are paid in Brazilian reais, by Pix.'],
          ['small', 'The exact amount is approximate — it depends on the USD→BRL exchange rate on payout day. Commissions are paid only after the client pays, and never include hosting or maintenance fees.'],
          ['btn', 'Open your partner area', $partner],
        ])),
      ];

    case 'referral_received':
      return [
        'subject' => 'New referral' . ($biz !== '' ? ": $biz" : ''),
        'pre'     => 'A business you referred just sent us a request.',
        'title'   => 'A referral just came in',
        'blocks'  => [
          $hi,
          ['p', ($biz !== '' ? "$biz, a business you referred," : 'A business you referred') . ' just sent us a website request.'],
          ['p', 'If they become a paying client, you\'ll earn a commission on the site price — paid in reais by Pix. You can follow it in your partner area.'],
          ['btn', 'Open your partner area', $partner],
        ],
      ];

    case 'commission_earned':
      $amt = kb_mail_money($v['amount'] ?? 0);
      return [
        'subject' => "You earned a $amt commission",
        'pre'     => 'A client you referred just paid.',
        'title'   => 'You earned a commission',
        'blocks'  => [
          $hi,
          ['p', 'Great news: ' . ($biz !== '' ? "$biz, a business you referred," : 'a business you referred') . ' just paid for their website.'],
          ['kv', [['Your commission', '~' . $amt], ['Rate', '~' . kb_mail_pct($pct) . ' of the site price']]],
          ['p', 'We\'ll send it by Pix, in reais, to the details in your partner area and let you know when it\'s paid. The amount is approximate — it follows the USD→BRL rate on payout day.'],
          ['btn', 'View your earnings', $partner],
          ['small', 'Please keep your payout details up to date in your partner area.'],
        ],
      ];

    case 'commission_paid':
      $amt  = kb_mail_money($v['amount'] ?? 0);
      $kv   = [['Amount', $amt]];
      if ($biz !== '') $kv[] = ['Referral', $biz];
      if (!empty($v['date'])) $kv[] = ['Paid on', (string)$v['date']];
      return [
        'subject' => "Your $amt commission was paid",
        'pre'     => 'Your commission is on its way.',
        'title'   => 'Commission paid',
        'blocks'  => [
          $hi,
          ['p', 'We\'ve sent your commission by Pix. Thanks for partnering with KB Sites!'],
          ['kv', $kv],
          ['small', 'Depending on your payout method, it may take a few days to show up.'],
          ['btn', 'View your earnings', $partner],
        ],
      ];

    case 'commission_rate_up':
      $new = (float)($v['new_pct'] ?? $pct);
      $old = (float)($v['old_pct'] ?? 0);
      return [
        'subject' => 'Your commission just went up to ' . kb_mail_pct($new),
        'pre'     => 'Higher commissions for every client you refer.',
        'title'   => 'Higher commissions, starting now',
        'blocks'  => [
          $hi,
          ['p', 'Good news: as a KB Sites partner you now earn ' . kb_mail_pct($new) . ' of the site price' . ($old > 0 ? ' (up from ' . kb_mail_pct($old) . ')' : '') . ' for every client you refer who pays.'],
          ['p', 'On a $150 website, that\'s ' . kb_mail_money(150 * $new / 100) . ' per client.'],
          ['btn', 'Get your referral link', $partner],
          ['small', 'As always, commissions are paid after the client pays and never include monthly fees.'],
        ],
      ];
  }
  return null;
}

// Render a kind to ['subject','html','text'] (null = nothing to send for these vars).
function kb_mail_render($kind, $vars) {
  $m = kb_mail_content($kind, is_array($vars) ? $vars : []);
  if (!$m) return null;
  $e = 'kb_mail_e';
  $F = "font-family:Arial,Helvetica,sans-serif;";
  $html = ''; $text = '';
  foreach ($m['blocks'] as $b) {
    switch ($b[0]) {
      case 'p':
        $html .= '<p style="margin:0 0 14px;' . $F . 'font-size:15px;line-height:1.65;color:#454545;">' . nl2br($e($b[1])) . "</p>\n";
        $text .= $b[1] . "\n\n";
        break;
      case 'small':
        $html .= '<p style="margin:16px 0 0;' . $F . 'font-size:13px;line-height:1.6;color:#8a8a8a;">' . nl2br($e($b[1])) . "</p>\n";
        $text .= $b[1] . "\n\n";
        break;
      case 'quote':
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px;"><tr>'
               . '<td style="background:#f6f4ef;border-left:3px solid #d9b45a;border-radius:6px;padding:14px 16px;' . $F . 'font-size:15px;line-height:1.65;color:#454545;">'
               . nl2br($e($b[1])) . "</td></tr></table>\n";
        foreach (preg_split('/\r\n|\r|\n/', (string)$b[1]) as $ln) $text .= '> ' . $ln . "\n";
        $text .= "\n";
        break;
      case 'kv':
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px;background:#faf8f4;border:1px solid #eeeae2;border-radius:10px;">';
        foreach ($b[1] as $i => $row) {
          $bt  = $i ? 'border-top:1px solid #eeeae2;' : '';
          $val = (!empty($row[2]) && kb_mail_url_ok($row[2]))
               ? '<a href="' . $e($row[2]) . '" style="color:#a9791f;text-decoration:underline;word-break:break-all;">' . $e($row[1]) . '</a>'
               : $e($row[1]);
          $html .= '<tr><td style="' . $bt . 'padding:11px 14px;' . $F . 'font-size:13px;color:#8a8a8a;width:34%;vertical-align:top;">' . $e($row[0]) . '</td>'
                 . '<td style="' . $bt . 'padding:11px 14px;' . $F . 'font-size:14px;color:#222222;font-weight:bold;vertical-align:top;">' . $val . '</td></tr>';
          $text .= $row[0] . ': ' . $row[1] . "\n";
        }
        $html .= "</table>\n";
        $text .= "\n";
        break;
      case 'btn':
        if (!kb_mail_url_ok($b[2])) break;
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 20px;"><tr>'
               . '<td align="center" bgcolor="#d9b45a" style="border-radius:100px;background:#d9b45a;background-image:linear-gradient(115deg,#f4dc93,#d9b45a 45%,#b5872f);">'
               . '<a href="' . $e($b[2]) . '" style="display:inline-block;padding:13px 28px;' . $F . 'font-size:15px;font-weight:bold;color:#1c1405;text-decoration:none;border-radius:100px;">' . $e($b[1]) . ' &rarr;</a>'
               . "</td></tr></table>\n";
        $text .= $b[1] . ': ' . $b[2] . "\n\n";
        break;
    }
  }
  $site = kb_mail_site();
  $logo = kb_mail_logo();
  $foot1 = 'KB Sites · Sites personalizados para empresas locais · kbsites.com.br';
  $foot2 = 'Você está recebendo esta mensagem porque possui uma conta na KB Sites.';

  $header = ($logo !== '')
    ? '<a href="' . $e($site) . '" style="text-decoration:none;"><img src="' . $e($logo) . '" alt="KB Sites" height="34" style="height:34px;width:auto;border:0;outline:none;text-decoration:none;display:inline-block;"></a>'
    : '<a href="' . $e($site) . '" style="font-family:Georgia,\'Times New Roman\',serif;font-size:24px;font-weight:bold;letter-spacing:6px;color:#f4dc93;text-decoration:none;">KB SITES</a>';

  $out  = "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
        . "<meta name=\"color-scheme\" content=\"light\"><meta name=\"supported-color-schemes\" content=\"light\"><title>" . $e($m['subject']) . "</title></head>\n"
        . '<body style="margin:0;padding:0;background:#70757a;">' . "\n"
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#70757a;font-size:1px;line-height:1px;">' . $e($m['pre'] ?? '') . "</div>\n"
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#70757a" style="background:#70757a;"><tr><td align="center" style="padding:30px 12px;">' . "\n"
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">' . "\n"
        . '<tr><td align="center" style="padding:0 6px 20px;">' . $header . "</td></tr>\n"
        . '<tr><td bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e7e3db;border-radius:14px;padding:32px 30px;box-shadow:0 1px 3px rgba(0,0,0,.04);">' . "\n"
        . '<div style="width:56px;height:3px;border-radius:3px;background:#d9b45a;background-image:linear-gradient(115deg,#f4dc93,#d9b45a 45%,#b5872f);margin:0 0 20px;line-height:3px;font-size:1px;">&nbsp;</div>' . "\n"
        . '<h1 style="margin:0 0 18px;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:1.3;font-weight:bold;color:#222222;">' . $e($m['title']) . "</h1>\n"
        . $html
        . "</td></tr>\n"
        . '<tr><td align="center" style="padding:22px 10px 0;' . $F . 'font-size:12px;line-height:1.7;color:#ffffff;">'
        . 'KB Sites · Sites personalizados para empresas locais · <a href="' . $e($site) . '" style="color:#ffffff;text-decoration:underline;">kbsites.com.br</a><br>'
        . $e($foot2) . "</td></tr>\n"
        . "</table>\n</td></tr></table>\n</body></html>";

  $plain = "KB SITES\n\n" . $m['title'] . "\n\n" . rtrim($text) . "\n\n--\n" . $foot1 . "\n" . $foot2 . "\n";
  return ['subject' => $m['subject'], 'html' => $out, 'text' => $plain];
}

// Send a branded email. $subject = null uses the kind's own subject.
// $vars['ticket_id'] (if any) is stored in email_log. Returns true when mail() accepted it.
// Never throws: an email problem must not break the page that triggered it.
function kb_mail_html($to, $subject, $kind, $vars = []) {
  try { return kb_mail_html_send($to, $subject, $kind, $vars); } catch (Throwable $e) { return false; }
}
function kb_mail_html_send($to, $subject, $kind, $vars) {
  if (!kb_mail_enabled($kind)) return false;                  // switched off: not sent, not logged
  $vars = is_array($vars) ? $vars : [];
  $to   = kb_hdr($to);
  $vars['_to'] = $to;
  $r = kb_mail_render($kind, $vars);
  if (!$r) return false;
  if ($subject !== null && trim((string)$subject) !== '') $r['subject'] = (string)$subject;
  $subj = kb_hdr($r['subject']);

  $ok = false;
  if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $from = function_exists('kb_support_email') ? kb_support_email() : ('support@' . kb_mail_host());
    $b    = 'kbalt_' . bin2hex(random_bytes(12));
    $crlf = function($s){ return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", (string)$s)); };
    $hd  = "From: KB Sites <$from>\r\n";
    $rt  = kb_hdr($from); // replies go to support@ → your inbox via the catch-all
    if ($rt !== '' && filter_var($rt, FILTER_VALIDATE_EMAIL)) $hd .= "Reply-To: KB Sites <$rt>\r\n";
    $hd .= "MIME-Version: 1.0\r\n";
    $hd .= "Content-Type: multipart/alternative; boundary=\"$b\"\r\n";
    $body  = "This is a multi-part message in MIME format.\r\n\r\n";
    $body .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
           . quoted_printable_encode($crlf($r['text'])) . "\r\n\r\n";
    $body .= "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
           . quoted_printable_encode($crlf($r['html'])) . "\r\n\r\n";
    $body .= "--$b--\r\n";
    $encSubj = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subj, 'UTF-8', 'B', "\r\n") : $subj;
    $ok = @mail($to, $encSubj, $body, $hd, '-f' . $from);
    if (!$ok) $ok = @mail($to, $encSubj, $body, $hd); // some hosts refuse -f: retry without it
  }

  try {
    kb_db()->prepare("INSERT INTO email_log(kind,to_email,ticket_id,subject,ok) VALUES(?,?,?,?,?)")
           ->execute([$kind, mb_substr($to, 0, 200), !empty($vars['ticket_id']) ? (int)$vars['ticket_id'] : null, mb_substr($subj, 0, 300), $ok ? 1 : 0]);
    if (mt_rand(1, 50) === 1) kb_db()->exec("DELETE FROM email_log WHERE id < (SELECT MAX(id) FROM email_log) - 5000");
  } catch (Exception $e) {}
  return (bool)$ok;
}

// Realistic sample data for the admin preview (?view=emails&preview=<kind>).
function kb_mail_sample($kind, $stage = 'proposal') {
  $pct = kb_commission_pct();
  $base = ['name'=>'Jordan Miller', 'business'=>'Miller Plumbing Co.', 'token'=>'SAMPLE0000TOKEN', 'ticket_id'=>42, 'plan'=>'hosting'];
  switch ($kind) {
    case 'status_update':      return $base + ['stage'=>$stage, 'site_url'=>($stage === 'delivered' ? 'https://millerplumbing.com' : '')];
    case 'studio_reply':       return $base + ['body'=>"Hi Jordan! Thanks for the details.\n\nCould you send us your logo and 3–5 photos of your team or past jobs? Then we'll prepare your proposal."];
    case 'welcome':            return ['name'=>'Jordan Miller', 'pct'=>$pct];
    case 'password_changed':   return ['name'=>'Jordan Miller', 'email'=>'jordan@example.com'];
    case 'partner_welcome':    return ['name'=>'Alex Rivera', 'code'=>'KB7F3A9C', 'pct'=>$pct];
    case 'referral_received':  return ['name'=>'Alex Rivera', 'business'=>'Miller Plumbing Co.', 'pct'=>$pct];
    case 'commission_earned':  return ['name'=>'Alex Rivera', 'business'=>'Miller Plumbing Co.', 'amount'=>150 * $pct / 100, 'pct'=>$pct];
    case 'commission_paid':    return ['name'=>'Alex Rivera', 'business'=>'Miller Plumbing Co.', 'amount'=>150 * $pct / 100, 'date'=>date('Y-m-d')];
    case 'commission_rate_up': return ['name'=>'Alex Rivera', 'old_pct'=>$pct, 'new_pct'=>$pct + 5];
  }
  return $base; // request_received, support_received
}
