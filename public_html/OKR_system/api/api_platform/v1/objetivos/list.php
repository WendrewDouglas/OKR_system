<?php
declare(strict_types=1);

/**
 * GET /objetivos
 * Lista objetivos da empresa com paginação e filtros.
 * Query: ?page=1&per_page=20&pilar_bsc=X&status=Y&dono=Z&scope=company|meus
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();
[$page, $perPage] = api_pagination_params();

$where  = ["o.id_company = ?"];
$params = [$cid];

// Filters
$scope = api_str($_GET['scope'] ?? 'company');
if ($scope === 'meus') {
  $where[]  = "o.dono = ?";
  $params[] = $uid;
}

$pilar = api_str($_GET['pilar_bsc'] ?? '');
if ($pilar !== '') {
  $where[]  = "o.pilar_bsc = ?";
  $params[] = $pilar;
}

$status = api_str($_GET['status'] ?? '');
if ($status !== '') {
  $where[]  = "o.status = ?";
  $params[] = $status;
}

$statusAprov = api_str($_GET['status_aprovacao'] ?? '');
if ($statusAprov !== '') {
  $where[]  = "o.status_aprovacao = ?";
  $params[] = $statusAprov;
}

$search = api_str($_GET['q'] ?? '');
if ($search !== '') {
  $where[]  = "o.descricao LIKE ?";
  $params[] = "%$search%";
}

$wSQL = implode(' AND ', $where);

$dataSql = "
  SELECT o.id_objetivo, o.descricao, o.status, o.status_aprovacao,
         o.pilar_bsc, o.tipo, o.qualidade, o.dono,
         o.dt_criacao, o.dt_prazo, o.dt_conclusao,
         u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome,
         (SELECT COUNT(*) FROM key_results kr WHERE kr.id_objetivo = o.id_objetivo) AS qtd_krs
    FROM objetivos o
    LEFT JOIN usuarios u ON u.id_user = o.dono
   WHERE $wSQL
   ORDER BY o.dt_criacao DESC
";

$countSql = "SELECT COUNT(*) FROM objetivos o WHERE $wSQL";

$result = api_paginated($pdo, $dataSql, $countSql, $params, $page, $perPage);

$result['items'] = array_map(fn($r) => [
  'id_objetivo'      => $r['id_objetivo'],
  'descricao'        => $r['descricao'],
  'status'           => $r['status'],
  'status_aprovacao' => $r['status_aprovacao'],
  'pilar_bsc'        => $r['pilar_bsc'],
  'tipo'             => $r['tipo'],
  'qualidade'        => $r['qualidade'],
  'dt_criacao'       => $r['dt_criacao'],
  'dt_prazo'         => $r['dt_prazo'],
  'dt_conclusao'     => $r['dt_conclusao'],
  'qtd_krs'          => (int)$r['qtd_krs'],
  'dono' => [
    'id_user' => (int)$r['dono'],
    'nome'    => trim(($r['dono_nome'] ?? '') . ' ' . ($r['dono_sobrenome'] ?? '')),
  ],
], $result['items']);

api_json(array_merge(['ok' => true], $result));
