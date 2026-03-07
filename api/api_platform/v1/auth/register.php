<?php
declare(strict_types=1);

/**
 * POST /auth/register
 * Cria conta + empresa (onboarding).
 */

$in = api_input();
api_require_fields($in, ['primeiro_nome', 'email', 'password', 'organizacao']);

$nome     = api_str($in['primeiro_nome']);
$sobrenome = api_str($in['ultimo_nome'] ?? '');
$email    = strtolower(api_str($in['email']));
$pass     = (string)($in['password'] ?? '');
$org      = api_str($in['organizacao']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  api_error('E_INPUT', 'E-mail inválido.', 422);
}
if (strlen($pass) < 8) {
  api_error('E_INPUT', 'Senha deve ter no mínimo 8 caracteres.', 422);
}

$pdo = api_db();

// Check duplicate email
$st = $pdo->prepare("SELECT id_user FROM usuarios WHERE email_corporativo = ? LIMIT 1");
$st->execute([$email]);
if ($st->fetch()) {
  api_error('E_CONFLICT', 'E-mail já cadastrado.', 409);
}

$pdo->beginTransaction();
try {
  // Create company
  $stC = $pdo->prepare("
    INSERT INTO company (organizacao, created_at)
    VALUES (?, NOW())
  ");
  $stC->execute([$org]);
  $companyId = (int)$pdo->lastInsertId();

  // Create user
  $stU = $pdo->prepare("
    INSERT INTO usuarios (primeiro_nome, ultimo_nome, email_corporativo, id_company, dt_cadastro)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $stU->execute([$nome, $sobrenome, $email, $companyId]);
  $userId = (int)$pdo->lastInsertId();

  // Create credentials
  $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
  $stP = $pdo->prepare("INSERT INTO usuarios_credenciais (id_user, senha_hash) VALUES (?, ?)");
  $stP->execute([$userId, $hash]);

  // Assign user_admin role
  $stR = $pdo->prepare("
    INSERT INTO rbac_user_role (user_id, role_id, valid_from)
    SELECT ?, role_id, NOW() FROM rbac_roles WHERE role_key = 'user_admin' LIMIT 1
  ");
  $stR->execute([$userId]);

  // Update company created_by
  $pdo->prepare("UPDATE company SET created_by = ? WHERE id_company = ?")->execute([$userId, $companyId]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

$token = api_issue_token([
  'sub' => $userId,
  'cid' => $companyId,
]);

api_json([
  'ok'    => true,
  'token' => $token,
  'user'  => [
    'id_user'       => $userId,
    'primeiro_nome' => $nome,
    'ultimo_nome'   => $sobrenome,
    'email'         => $email,
    'id_company'    => $companyId,
  ],
], 201);
