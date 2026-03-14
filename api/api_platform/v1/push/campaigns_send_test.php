<?php
// POST /push/campaigns/:id/send-test
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

require_once dirname(__DIR__, 3) . '/../auth/push_helpers.php';

$id = api_int(api_param('id'), 'id');
$camp = $pdo->prepare("SELECT * FROM push_campaigns WHERE id_campaign=?");
$camp->execute([$id]);
$campaign = $camp->fetch();
if (!$campaign) api_error('E_NOT_FOUND', 'Campanha nao encontrada.', 404);

// Busca device do admin
$dev = $pdo->prepare("SELECT * FROM push_devices WHERE id_user=? AND is_active=1 ORDER BY last_seen_at DESC LIMIT 1");
$dev->execute([$uid]);
$device = $dev->fetch();

$imageUrl = null;
if ($campaign['image_asset_id']) {
  $ast = $pdo->prepare("SELECT public_url FROM push_assets WHERE id_asset=?");
  $ast->execute([$campaign['image_asset_id']]);
  $imageUrl = $ast->fetchColumn() ?: null;
  if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
    $imageUrl = 'https://planningbi.com.br' . $imageUrl;
  }
}

$result = ['push_sent' => false, 'inbox_sent' => false];

if ($device && in_array($campaign['canal'], ['push', 'push_inbox'])) {
  $r = push_send_fcm($device['token'], [
    'title' => '[TESTE] ' . $campaign['titulo'],
    'body'  => $campaign['descricao'],
    'image' => $imageUrl,
    'data'  => ['campaign_id' => (string)$id, 'route' => $campaign['route'] ?? '', 'test' => '1'],
  ]);
  $result['push_sent'] = $r['success'];
  $result['push_error'] = $r['error'];
}

if (in_array($campaign['canal'], ['inbox', 'push_inbox'])) {
  push_mirror_to_inbox($pdo, $uid, $campaign);
  $result['inbox_sent'] = true;
}

// Run log
$pdo->prepare("INSERT INTO push_campaign_runs (id_campaign, run_type, status, total_target, total_sent, started_at, finished_at)
  VALUES (?, 'test', 'completed', 1, ?, NOW(), NOW())")
  ->execute([$id, $result['push_sent'] || $result['inbox_sent'] ? 1 : 0]);

api_json(['ok' => true, 'result' => $result]);
