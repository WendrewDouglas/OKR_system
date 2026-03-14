<?php
// POST /push/events/open, /push/events/click, /push/events/delivered
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];

$in = api_input();
$eventType = api_param('event_type') ?? 'open';
if (!in_array($eventType, ['open', 'click', 'delivered'])) api_error('E_INPUT', 'Tipo de evento invalido.', 422);

$campaignId = (int)($in['campaign_id'] ?? 0);
if ($campaignId <= 0) api_error('E_INPUT', 'campaign_id obrigatorio.', 422);

// Busca recipient
$st = $pdo->prepare("SELECT id_recipient FROM push_campaign_recipients WHERE id_campaign=? AND id_user=? LIMIT 1");
$st->execute([$campaignId, $uid]);
$recipientId = $st->fetchColumn() ?: null;

// Registra evento
$pdo->prepare("INSERT INTO push_delivery_events (id_campaign, id_recipient, event_type, event_payload_json) VALUES (?,?,?,?)")
  ->execute([$campaignId, $recipientId, $eventType, json_encode($in['payload'] ?? null)]);

// Atualiza recipient
if ($recipientId) {
  $colMap = ['delivered' => 'delivered_at', 'open' => 'opened_at', 'click' => 'clicked_at'];
  $col = $colMap[$eventType] ?? null;
  if ($col) {
    $statusMap = ['delivered' => 'delivered', 'open' => 'opened', 'click' => 'clicked'];
    $pdo->prepare("UPDATE push_campaign_recipients SET {$col}=NOW(), status_envio=? WHERE id_recipient=? AND {$col} IS NULL")
      ->execute([$statusMap[$eventType], $recipientId]);
  }
}

api_json(['ok' => true]);
