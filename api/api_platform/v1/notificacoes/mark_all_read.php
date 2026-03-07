<?php
declare(strict_types=1);

/**
 * PUT /notificacoes/todas/lida
 * Marca todas as notificações como lidas.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

$st = $pdo->prepare("UPDATE notificacoes SET lida = 1, dt_lida = NOW() WHERE id_user = ? AND lida = 0");
$st->execute([$uid]);

api_json(['ok' => true, 'marcadas' => $st->rowCount()]);
