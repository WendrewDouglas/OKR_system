<?php
declare(strict_types=1);

/**
 * GET /usuarios
 * Lista usuários da empresa (admin: todos; non-admin: mesma empresa).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$isMaster = api_is_admin_master($pdo, $uid);
[$page, $perPage] = api_pagination_params();

$where  = [];
$params = [];

if (!$isMaster) {
  $where[]  = "u.id_company = ?";
  $params[] = $cid;
}

$search = api_str($_GET['q'] ?? '');
if ($search !== '') {
  $where[]  = "(u.primeiro_nome LIKE ? OR u.ultimo_nome LIKE ? OR u.email_corporativo LIKE ?)";
  $params[] = "%$search%";
  $params[] = "%$search%";
  $params[] = "%$search%";
}

$wSQL = empty($where) ? '1=1' : implode(' AND ', $where);

$dataSql = "
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo,
         u.id_company, u.telefone, u.dt_cadastro,
         c.organizacao AS empresa,
         r.role_key, r.role_name
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
    LEFT JOIN rbac_roles r ON r.role_id = ur.role_id
   WHERE $wSQL
   ORDER BY u.primeiro_nome, u.ultimo_nome
";

$countSql = "SELECT COUNT(*) FROM usuarios u WHERE $wSQL";

$result = api_paginated($pdo, $dataSql, $countSql, $params, $page, $perPage);

$result['items'] = array_map(fn($r) => [
  'id_user'       => (int)$r['id_user'],
  'primeiro_nome' => $r['primeiro_nome'],
  'ultimo_nome'   => $r['ultimo_nome'] ?? '',
  'email'         => $r['email_corporativo'],
  'telefone'      => $r['telefone'] ?? '',
  'id_company'    => $r['id_company'] ? (int)$r['id_company'] : null,
  'empresa'       => $r['empresa'] ?? '',
  'role_key'      => $r['role_key'] ?? '',
  'role_name'     => $r['role_name'] ?? '',
  'dt_cadastro'   => $r['dt_cadastro'],
], $result['items']);

api_json(array_merge(['ok' => true], $result));
