<?php
declare(strict_types=1);

/**
 * PUT /auth/me
 * Atualiza perfil do usuário autenticado.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

$in = api_input();
$pdo = api_db();

$sets   = [];
$params = [];

$allowed = [
  'primeiro_nome' => 'string',
  'ultimo_nome'   => 'string',
  'telefone'      => 'string',
];

foreach ($allowed as $field => $type) {
  if (array_key_exists($field, $in)) {
    $sets[]   = "$field = ?";
    $params[] = api_str($in[$field]);
  }
}

// Password change
if (!empty($in['password_atual']) && !empty($in['password_nova'])) {
  $stP = $pdo->prepare("SELECT senha_hash FROM usuarios_credenciais WHERE id_user = ?");
  $stP->execute([$uid]);
  $hash = $stP->fetchColumn();
  if (!$hash || !password_verify((string)$in['password_atual'], (string)$hash)) {
    api_error('E_AUTH', 'Senha atual incorreta.', 401);
  }
  $newPass = (string)$in['password_nova'];
  if (strlen($newPass) < 8) {
    api_error('E_INPUT', 'Nova senha deve ter no mínimo 8 caracteres.', 422);
  }
  $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
  $pdo->prepare("UPDATE usuarios_credenciais SET senha_hash = ? WHERE id_user = ?")->execute([$newHash, $uid]);
}

if (!empty($sets)) {
  $sets[] = "dt_alteracao = NOW()";
  $params[] = $uid;
  $pdo->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id_user = ?")->execute($params);
}

// Return updated user
$st = $pdo->prepare("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo AS email,
         u.telefone, u.id_company, u.imagem_url AS avatar_url, c.organizacao AS empresa
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
   WHERE u.id_user = ?
");
$st->execute([$uid]);
$user = $st->fetch();

api_json(['ok' => true, 'user' => $user]);
