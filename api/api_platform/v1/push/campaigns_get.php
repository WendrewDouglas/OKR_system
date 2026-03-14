<?php
// GET /push/campaigns/:id
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
if (!api_is_admin_master($pdo, (int)$auth['sub'])) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

$id = api_int(api_param('id'), 'id');
$st = $pdo->prepare("SELECT pc.*, pa.public_url AS image_url FROM push_campaigns pc LEFT JOIN push_assets pa ON pa.id_asset=pc.image_asset_id WHERE pc.id_campaign=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) api_error('E_NOT_FOUND', 'Campanha nao encontrada.', 404);

// Stats
$stats = $pdo->prepare("SELECT status_envio, COUNT(*) AS cnt FROM push_campaign_recipients WHERE id_campaign=? GROUP BY status_envio");
$stats->execute([$id]);
$row['stats'] = [];
foreach ($stats as $s) $row['stats'][$s['status_envio']] = (int)$s['cnt'];

api_json(['ok' => true, 'campaign' => $row]);
