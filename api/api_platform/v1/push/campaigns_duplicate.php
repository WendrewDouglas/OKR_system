<?php
// POST /push/campaigns/:id/duplicate
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

$id = api_int(api_param('id'), 'id');
$st = $pdo->prepare("SELECT * FROM push_campaigns WHERE id_campaign=?");
$st->execute([$id]);
$orig = $st->fetch();
if (!$orig) api_error('E_NOT_FOUND', 'Campanha nao encontrada.', 404);

$pdo->prepare("INSERT INTO push_campaigns
  (nome_interno, canal, categoria, titulo, descricao, image_asset_id, route, url_web,
   priority, status, timezone, is_recurring, recurrence_rule, prompt_ia, filters_json, created_by)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute([
    '[Copia] ' . $orig['nome_interno'], $orig['canal'], $orig['categoria'],
    $orig['titulo'], $orig['descricao'], $orig['image_asset_id'],
    $orig['route'], $orig['url_web'], $orig['priority'], 'draft',
    $orig['timezone'], 0, null, $orig['prompt_ia'], $orig['filters_json'], $uid
  ]);

api_json(['ok' => true, 'id_campaign' => (int)$pdo->lastInsertId(), 'message' => 'Campanha duplicada.']);
