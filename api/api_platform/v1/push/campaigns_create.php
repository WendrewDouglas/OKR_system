<?php
declare(strict_types=1);

/**
 * POST /push/campaigns
 * Cria uma campanha de push (status 'draft'). admin_master.
 */

$auth = api_require_auth();
$pdo  = api_db();
$uid  = (int)($auth['sub'] ?? 0);
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

require_once dirname(__DIR__, 3) . '/../auth/push_helpers.php';

$in = api_input();
api_require_fields($in, ['nome_interno', 'titulo', 'descricao']);

$canal    = api_enum(api_str($in['canal'] ?? 'push'), ['push', 'inbox', 'push_inbox'], 'canal');
$priority = api_enum(api_str($in['priority'] ?? 'normal'), ['normal', 'high'], 'priority');

// Filtros de audiência → JSON + estimativa
$filters = [];
if (isset($in['filters'])) {
  $filters = is_array($in['filters']) ? $in['filters'] : (json_decode((string)$in['filters'], true) ?: []);
}
$filtersJson = $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null;
$audienceEstimate = $filters ? push_count_audience($filters, $pdo) : null;

$pdo->prepare("
  INSERT INTO push_campaigns
    (nome_interno, canal, categoria, titulo, descricao, image_asset_id, route, url_web,
     priority, status, scheduled_at, is_recurring, recurrence_rule, prompt_ia,
     audience_estimate, filters_json, id_segment, created_by, created_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
")->execute([
  api_str($in['nome_interno']),
  $canal,
  api_str($in['categoria'] ?? '') ?: null,
  api_str($in['titulo']),
  api_str($in['descricao']),
  api_int_or_null($in['image_asset_id'] ?? null),
  api_str($in['route'] ?? '') ?: null,
  api_str($in['url_web'] ?? '') ?: null,
  $priority,
  api_str($in['scheduled_at'] ?? '') ?: null,
  !empty($in['is_recurring']) ? 1 : 0,
  api_str($in['recurrence_rule'] ?? '') ?: null,
  api_str($in['prompt_ia'] ?? '') ?: null,
  $audienceEstimate,
  $filtersJson,
  api_int_or_null($in['id_segment'] ?? null),
  $uid,
]);

$id = (int)$pdo->lastInsertId();
api_json([
  'ok'      => true,
  'data'    => ['id_campaign' => $id, 'audience_estimate' => $audienceEstimate],
  'message' => 'Campanha criada.',
], 201);
