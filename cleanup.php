<?php
// One-time test-data wipe. Upload to the web root, open it while logged in as the
// admin, click the button, then DELETE this file. Keeps only the owner account.
require __DIR__ . '/db.php';
@include __DIR__ . '/config.php';
kb_session_start();

$me = kb_auth_user();
if (!kb_is_admin($me)) { http_response_code(403); exit('Admin only — faça login como admin primeiro.'); }

$owner = kb_admin_email();
$done = false; $counts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'WIPE') {
  $db = kb_db();
  // owner account id(s) to keep
  $st = $db->prepare("SELECT id FROM affiliates WHERE lower(email)=?"); $st->execute([$owner]);
  $keepIds = array_map(fn($r) => (int)$r['id'], $st->fetchAll());
  $keepSet = $keepIds ? implode(',', $keepIds) : '0';

  // wipe conversations + all related data
  foreach (['tickets','replies','notifications','mod_ratings','chat_typing','chat_reads','notify_queue','email_log','user_devices','remember_tokens'] as $t) {
    try { $db->exec("DELETE FROM $t"); } catch (Exception $e) {}
  }
  // delete every account except the owner
  try { $db->exec("DELETE FROM affiliates WHERE id NOT IN ($keepSet)"); } catch (Exception $e) {}

  $counts['contas restantes'] = (int)$db->query("SELECT COUNT(*) c FROM affiliates")->fetch()['c'];
  $counts['tickets restantes'] = (int)$db->query("SELECT COUNT(*) c FROM tickets")->fetch()['c'];
  $done = true;
}
?><!doctype html><html lang="pt-br"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<body style="font-family:system-ui,sans-serif;max-width:540px;margin:48px auto;padding:0 16px;line-height:1.6">
<h2>KB Sites — limpar dados de teste</h2>
<?php if ($done): ?>
  <p style="color:#1a7f37;font-weight:600">✅ Pronto! Apaguei todos os tickets, mensagens, avaliações e contas — só sobrou <b><?=htmlspecialchars($owner)?></b>.</p>
  <p><?php foreach ($counts as $k=>$v) echo htmlspecialchars($k).': <b>'.$v.'</b><br>'; ?></p>
  <p style="background:#fff3cd;border:1px solid #e0c060;border-radius:8px;padding:12px 14px">⚠️ <b>Apague este arquivo <code>cleanup.php</code> do servidor agora</b> — por segurança, ele não pode ficar no ar. Se você foi deslogado, é só entrar de novo.</p>
<?php else: ?>
  <p>Isto vai <b>apagar TUDO</b>: todos os tickets, mensagens, avaliações, notificações e contas — <b>menos a sua</b> (<code><?=htmlspecialchars($owner)?></code>). <b style="color:#c0392b">Não dá pra desfazer.</b></p>
  <form method="post">
    <input type="hidden" name="confirm" value="WIPE">
    <button type="submit" style="background:#c0392b;color:#fff;border:0;padding:13px 22px;border-radius:9px;font-size:1rem;font-weight:600;cursor:pointer">Apagar tudo menos a minha conta</button>
  </form>
  <p style="color:#888;font-size:.9rem;margin-top:14px">Depois de rodar, apague este arquivo do servidor.</p>
<?php endif; ?>
</body></html>
