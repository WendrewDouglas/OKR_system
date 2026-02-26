<?php
declare(strict_types=1);

/**
 * PUT /notificacoes/:id/lida
 * Marca uma notificação como lida.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

$st = $pdo->prepare("UPDATE notificacoes SET lida = 1, dt_lida = NOW() WHERE id_notificacao = ? AND id_user = ?");
$st->execute([$id, $uid]);

if ($st->rowCount() === 0) {
  api_error('E_NOT_FOUND', 'Notificação não encontrada.', 404);
}

api_json(['ok' => true]);
