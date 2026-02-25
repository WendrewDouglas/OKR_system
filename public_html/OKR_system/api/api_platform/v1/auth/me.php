<?php
declare(strict_types=1);

require_once __DIR__ . '/../_core.php';

$payload = api_require_auth();

$idUser = (int)($payload['sub'] ?? 0);
$idCompanyToken = (int)($payload['cid'] ?? 0);

if ($idUser <= 0) {
  api_error('E_AUTH', 'Token inválido (sub ausente).', 401);
}

$pdo = api_db();

$sql = "
  SELECT
    u.id_user,
    u.primeiro_nome,
    u.ultimo_nome,
    u.email_corporativo AS email,
    u.id_company,
    c.organizacao AS empresa
  FROM usuarios u
  LEFT JOIN company c ON c.id_company = u.id_company
  WHERE u.id_user = ?
  LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$idUser]);
$user = $stmt->fetch();

if (!$user) {
  api_error('E_AUTH', 'Usuário do token não encontrado.', 401);
}

// segurança: garante que o cid do token bate com a company do usuário
$idCompanyUser = (int)($user['id_company'] ?? 0);
if ($idCompanyToken > 0 && $idCompanyUser > 0 && $idCompanyToken !== $idCompanyUser) {
  api_error('E_AUTH', 'Token não pertence a esta organização.', 401);
}

api_json([
  'ok' => true,
  'user' => $user,
  'token' => [
    'sub' => $payload['sub'] ?? null,
    'cid' => $payload['cid'] ?? null,
    'iat' => $payload['iat'] ?? null,
    'exp' => $payload['exp'] ?? null,
    'iss' => $payload['iss'] ?? null,
    'ver' => $payload['ver'] ?? null,
  ],
]);
