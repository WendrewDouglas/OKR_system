<?php
declare(strict_types=1);

/**
 * POST /usuarios
 * Cria um novo usuário na mesma empresa (admin only).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem criar usuários.', 403);
}

$in = api_input();
api_require_fields($in, ['primeiro_nome', 'email', 'password']);

$nome     = api_str($in['primeiro_nome']);
$sobrenome = api_str($in['ultimo_nome'] ?? '');
$email    = strtolower(api_str($in['email']));
$pass     = (string)($in['password'] ?? '');
$roleKey  = api_str($in['role_key'] ?? 'user_colab');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  api_error('E_INPUT', 'E-mail inválido.', 422);
}
if (strlen($pass) < 8) {
  api_error('E_INPUT', 'Senha deve ter no mínimo 8 caracteres.', 422);
}

// Check duplicate
$st = $pdo->prepare("SELECT id_user FROM usuarios WHERE email_corporativo = ?");
$st->execute([$email]);
if ($st->fetch()) {
  api_error('E_CONFLICT', 'E-mail já cadastrado.', 409);
}

$pdo->beginTransaction();
try {
  $stU = $pdo->prepare("
    INSERT INTO usuarios (primeiro_nome, ultimo_nome, email_corporativo, id_company,
                          id_user_criador, dt_cadastro, ip_criacao)
    VALUES (?, ?, ?, ?, ?, NOW(), ?)
  ");
  $stU->execute([$nome, $sobrenome, $email, $cid, $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
  $newId = (int)$pdo->lastInsertId();

  $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
  $pdo->prepare("INSERT INTO usuarios_credenciais (id_user, senha_hash) VALUES (?, ?)")->execute([$newId, $hash]);

  // Assign role
  $stR = $pdo->prepare("
    INSERT INTO rbac_user_role (user_id, role_id, valid_from)
    SELECT ?, role_id, NOW() FROM rbac_roles WHERE role_key = ? LIMIT 1
  ");
  $stR->execute([$newId, $roleKey]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json([
  'ok'      => true,
  'id_user' => $newId,
  'message' => 'Usuário criado com sucesso.',
], 201);
