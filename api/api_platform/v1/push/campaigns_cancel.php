<?php
// POST /push/campaigns/:id/cancel
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
if (!api_is_admin_master($pdo, (int)$auth['sub'])) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

$id = api_int(api_param('id'), 'id');
$st = $pdo->prepare("UPDATE push_campaigns SET status='cancelled', cancelled_at=NOW(), updated_by=?, updated_at=NOW() WHERE id_campaign=? AND status IN ('draft','scheduled')");
$st->execute([(int)$auth['sub'], $id]);
if ($st->rowCount() === 0) api_error('E_CONFLICT', 'Campanha nao pode ser cancelada.', 409);

api_json(['ok' => true, 'message' => 'Campanha cancelada.']);
