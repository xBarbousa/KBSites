<?php
// Tiny endpoint so the static homepage can tell if the visitor is logged in.
// The account email is only ever returned to that same logged-in visitor (it prefills + locks the request form).
require __DIR__ . '/../db.php';
@include __DIR__ . '/../config.php';
kb_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$me = kb_auth_user();
echo json_encode([
  'logged'    => (bool) $me,
  'name'      => $me ? ($me['name'] ?? '') : '',
  'email'     => $me ? ($me['email'] ?? '') : '',
  'photo'     => $me ? ($me['photo'] ?? '') : '',
  'admin'     => kb_is_admin($me),
  'affiliate' => $me ? (bool) ($me['is_affiliate'] ?? 0) : false,
  'unseen'    => $me ? kb_unseen_count($me['id']) : 0,
  'google'    => (bool) (kb_google_client_id() && kb_google_client_secret()), // show "Continue with Google" only when set up
]);
