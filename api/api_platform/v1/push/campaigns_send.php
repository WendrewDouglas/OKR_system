<?php
declare(strict_types=1);

/**
 * POST /push/campaigns/:id/send
 * Dispara a campanha de verdade (resolve audiência + FCM + inbox). admin_master.
 *
 * NOTA: envio síncrono via push_process_campaign(). Para audiências grandes,
 * considerar fila/worker no futuro (o helper processa em lotes de 100).
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

if (!in_array($status, ['draft', 'scheduled'], true)) {
  api_error('E_CONFLICT', "Campanha não pode ser enviada (status atual: $status).", 409);
}

// push_process_campaign só processa status scheduled/sending → promove o rascunho.
$pdo->prepare("UPDATE push_campaigns SET status = 'scheduled', approved_by = ?, updated_at = NOW() WHERE id_campaign = ?")
    ->execute([$uid, $id]);

$result = push_process_campaign($pdo, $id);

if (isset($result['error'])) {
  api_error('E_SERVER', (string)$result['error'], 500);
}

api_ok([
  'total_target' => (int)($result['total_target'] ?? 0),
  'sent'         => (int)($result['sent'] ?? 0),
  'failed'       => (int)($result['failed'] ?? 0),
  'run_id'       => (int)($result['run_id'] ?? 0),
]);
