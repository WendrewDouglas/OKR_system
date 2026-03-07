<?php
declare(strict_types=1);

/**
 * GET /notificacoes
 * Lista notificações do usuário com paginação.
 * Query: ?page=1&per_page=20&only_unread=1
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

[$page, $perPage] = api_pagination_params();
$onlyUnread = (int)($_GET['only_unread'] ?? 0);

$where  = ["id_user = ?"];
$params = [$uid];

if ($onlyUnread) {
  $where[] = "lida = 0";
}

$wSQL = implode(' AND ', $where);

$dataSql  = "SELECT id_notificacao, tipo, titulo, mensagem, url, lida, dt_criado, dt_lida, meta_json
               FROM notificacoes WHERE $wSQL ORDER BY dt_criado DESC";
$countSql = "SELECT COUNT(*) FROM notificacoes WHERE $wSQL";

$result = api_paginated($pdo, $dataSql, $countSql, $params, $page, $perPage);

$result['items'] = array_map(fn($r) => [
  'id_notificacao' => (int)$r['id_notificacao'],
  'tipo'           => $r['tipo'],
  'titulo'         => $r['titulo'],
  'mensagem'       => $r['mensagem'],
  'url'            => $r['url'],
  'lida'           => (bool)$r['lida'],
  'dt_criado'      => $r['dt_criado'],
  'dt_lida'        => $r['dt_lida'],
  'meta'           => $r['meta_json'] ? json_decode($r['meta_json'], true) : null,
], $result['items']);

api_json(array_merge(['ok' => true], $result));
