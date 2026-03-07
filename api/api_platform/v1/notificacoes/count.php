<?php
declare(strict_types=1);

/**
 * GET /notificacoes/count
 * Retorna contagem de notificações não lidas.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

$st = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE id_user = ? AND lida = 0");
$st->execute([$uid]);

api_json(['ok' => true, 'count' => (int)$st->fetchColumn()]);
