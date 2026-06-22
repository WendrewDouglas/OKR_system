<?php
declare(strict_types=1);

/**
 * GET /companies
 * Lista todas as empresas (gestão multi-empresa — admin_master).
 * Query: ?page=1&per_page=20&q=texto
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

if (!api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode gerenciar empresas.', 403);
}

[$page, $perPage] = api_pagination_params();

$where  = '';
$params = [];
$search = api_str($_GET['q'] ?? '');
if ($search !== '') {
  $where  = 'WHERE c.organizacao LIKE ? OR c.cnpj LIKE ?';
  $params = ["%$search%", "%$search%"];
}

// Contagens por subquery (mantém o COUNT(*) da paginação simples).
$dataSql = "
  SELECT c.id_company, c.organizacao, c.cnpj, c.municipio, c.uf,
         c.email, c.telefone, c.created_at,
         (SELECT COUNT(*) FROM usuarios u WHERE u.id_company = c.id_company) AS total_usuarios,
         (SELECT COUNT(*) FROM objetivos o WHERE o.id_company = c.id_company) AS total_objetivos
    FROM company c
    $where
   ORDER BY c.organizacao ASC
";
$countSql = "SELECT COUNT(*) FROM company c $where";

$result = api_paginated($pdo, $dataSql, $countSql, $params, $page, $perPage);

$result['items'] = array_map(fn($r) => [
  'id_company'      => (int)$r['id_company'],
  'organizacao'     => $r['organizacao'],
  'cnpj'            => $r['cnpj'],
  'municipio'       => $r['municipio'],
  'uf'              => $r['uf'],
  'email'           => $r['email'],
  'telefone'        => $r['telefone'],
  'total_usuarios'  => (int)$r['total_usuarios'],
  'total_objetivos' => (int)$r['total_objetivos'],
  'created_at'      => $r['created_at'],
], $result['items']);

api_ok_paginated($result);
