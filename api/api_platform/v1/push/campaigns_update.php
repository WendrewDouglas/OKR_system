<?php
declare(strict_types=1);

/**
 * PUT /push/campaigns/:id
 * Edita uma campanha — somente enquanto status = 'draft'. admin_master.
 */

$auth = api_require_auth();
$pdo  = api_db();
$uid  = (int)($auth['sub'] ?? 0);
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

require_once dirname(__DIR__, 3) . '/../auth/push_helpers.php';

$id = api_int(api_param('id'), 'id');

$st = $pdo->prepare("SELECT status FROM push_campaigns WHERE id_campaign = ?");
$st->execute([$id]);
$status = $st->fetchColumn();
if ($status === false) api_error('E_NOT_FOUND', 'Campanha não encontrada.', 404);
if ($status !== 'draft') {
  api_error('E_CONFLICT', "Somente campanhas em rascunho podem ser editadas (status atual: $status).", 409);
}

$in = api_input();
$sets = [];
$params = [];

// Campos texto simples
foreach (['nome_interno', 'categoria', 'titulo', 'descricao', 'route', 'url_web', 'recurrence_rule', 'prompt_ia', 'scheduled_at'] as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]) ?: null;
  }
}

// Enums
if (array_key_exists('canal', $in)) {
  $sets[] = "canal = ?";
  $params[] = api_enum(api_str($in['canal']), ['push', 'inbox', 'push_inbox'], 'canal');
}
if (array_key_exists('priority', $in)) {
  $sets[] = "priority = ?";
  $params[] = api_enum(api_str($in['priority']), ['normal', 'high'], 'priority');
}

// Numéricos / flags
if (array_key_exists('image_asset_id', $in)) { $sets[] = "image_asset_id = ?"; $params[] = api_int_or_null($in['image_asset_id']); }
if (array_key_exists('id_segment', $in))     { $sets[] = "id_segment = ?";     $params[] = api_int_or_null($in['id_segment']); }
if (array_key_exists('is_recurring', $in))   { $sets[] = "is_recurring = ?";   $params[] = !empty($in['is_recurring']) ? 1 : 0; }

// Filtros de audiência → recalcula estimativa
if (array_key_exists('filters', $in)) {
  $filters = is_array($in['filters']) ? $in['filters'] : (json_decode((string)$in['filters'], true) ?: []);
  $sets[] = "filters_json = ?";
  $params[] = $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null;
  $sets[] = "audience_estimate = ?";
  $params[] = $filters ? push_count_audience($filters, $pdo) : null;
}

if (empty($sets)) api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);

$sets[] = "updated_by = ?";
$params[] = $uid;
$sets[] = "updated_at = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE push_campaigns SET " . implode(', ', $sets) . " WHERE id_campaign = ?")->execute($params);

api_ok(['id_campaign' => $id]);
