<?php
// POST /push/devices/register
// POST /push/devices/refresh-token
// POST /push/devices/unregister
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
$cid = (int)($auth['cid'] ?? 0);

$in = api_input();
// Extrai action do path: push/devices/register -> register
$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (strpos($uri, 'unregister') !== false) $action = 'unregister';
elseif (strpos($uri, 'refresh-token') !== false) $action = 'refresh-token';
else $action = 'register';

if ($action === 'register' || $action === 'refresh-token') {
  $token = api_str($in['token'] ?? '');
  if (!$token) api_error('E_INPUT', 'Token obrigatorio.', 422);

  $tokenHash    = hash('sha256', $token);
  $platform     = api_str($in['platform'] ?? 'android');
  $appVersion   = api_str($in['app_version'] ?? '');
  $osVersion    = api_str($in['os_version'] ?? '');
  $deviceModel  = api_str($in['device_model'] ?? '');
  $locale       = api_str($in['locale'] ?? '');
  $timezone     = api_str($in['timezone'] ?? '');
  $pushEnabled  = (int)($in['notifications_enabled'] ?? 1);

  if (!in_array($platform, ['android', 'ios', 'web'])) $platform = 'android';

  // Upsert by token_hash
  $existing = $pdo->prepare("SELECT id_device FROM push_devices WHERE token_hash = ?");
  $existing->execute([$tokenHash]);
  $row = $existing->fetch();

  if ($row) {
    $pdo->prepare("UPDATE push_devices SET
      id_user=?, id_company=?, token=?, platform=?, app_version=?, os_version=?,
      device_model=?, locale=?, timezone=?, notifications_enabled=?,
      last_token_refresh_at=NOW(), last_seen_at=NOW(), is_active=1, updated_at=NOW()
      WHERE id_device=?")
      ->execute([$uid, $cid ?: null, $token, $platform, $appVersion, $osVersion,
        $deviceModel, $locale, $timezone, $pushEnabled, $row['id_device']]);
    $deviceId = (int)$row['id_device'];
  } else {
    $pdo->prepare("INSERT INTO push_devices
      (id_user, id_company, platform, push_provider, token, token_hash, app_version, os_version,
       device_model, locale, timezone, notifications_enabled, last_seen_at, last_token_refresh_at)
      VALUES (?,?,?,'fcm',?,?,?,?,?,?,?,?,NOW(),NOW())")
      ->execute([$uid, $cid ?: null, $platform, $token, $tokenHash, $appVersion, $osVersion,
        $deviceModel, $locale, $timezone, $pushEnabled]);
    $deviceId = (int)$pdo->lastInsertId();
  }

  api_json(['ok' => true, 'id_device' => $deviceId]);
}

if ($action === 'unregister') {
  $token = api_str($in['token'] ?? '');
  if ($token) {
    $tokenHash = hash('sha256', $token);
    $pdo->prepare("UPDATE push_devices SET is_active=0, updated_at=NOW() WHERE token_hash=? AND id_user=?")
      ->execute([$tokenHash, $uid]);
  } else {
    // Desativa todos os devices do usuario
    $pdo->prepare("UPDATE push_devices SET is_active=0, updated_at=NOW() WHERE id_user=?")->execute([$uid]);
  }
  api_json(['ok' => true]);
}

api_error('E_INPUT', 'Acao invalida.', 422);
