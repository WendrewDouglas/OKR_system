<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_core.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
  api_error('E_METHOD', 'Método não permitido.', 405, ['method' => $method]);
}

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);

if ($uid <= 0 || $cid <= 0) {
  api_error('E_AUTH', 'Token inválido.', 401);
}

$db = api_db();

$stmt = $db->prepare("
  SELECT
    c.id_company,
    c.organizacao,
    c.razao_social,
    c.cnpj,
    c.missao,
    c.visao
  FROM usuarios u
  INNER JOIN company c ON c.id_company = u.id_company
  WHERE u.id_user = :uid
    AND u.id_company = :cid
  LIMIT 1
");
$stmt->execute([':uid' => $uid, ':cid' => $cid]);
$row = $stmt->fetch();

if (!$row) {
  api_error('E_NOT_FOUND', 'Empresa não encontrada para este usuário.', 404);
}

api_json([
  'ok' => true,
  'company' => [
    'id_company'   => (int)$row['id_company'],
    'organizacao'  => $row['organizacao'],
    'razao_social' => $row['razao_social'],
    'cnpj'         => $row['cnpj'],
    'missao'       => $row['missao'],
    'visao'        => $row['visao'],
    'has_cnpj'     => !empty($row['cnpj']),
  ]
]);
