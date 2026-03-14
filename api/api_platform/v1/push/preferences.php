<?php
// GET/PUT /push/preferences
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
$method = api_method();

if ($method === 'GET') {
  $st = $pdo->prepare("SELECT notifications_enabled, platform, locale, timezone FROM push_devices WHERE id_user=? AND is_active=1 ORDER BY last_seen_at DESC LIMIT 1");
  $st->execute([$uid]);
  $device = $st->fetch() ?: ['notifications_enabled' => 1];
  api_json(['ok' => true, 'preferences' => $device]);
}

if ($method === 'PUT') {
  $in = api_input();
  $enabled = isset($in['notifications_enabled']) ? (int)$in['notifications_enabled'] : null;
  if ($enabled !== null) {
    $pdo->prepare("UPDATE push_devices SET notifications_enabled=?, updated_at=NOW() WHERE id_user=? AND is_active=1")
      ->execute([$enabled, $uid]);
  }
  api_json(['ok' => true]);
}

api_error('E_METHOD', 'Metodo nao suportado.', 405);
