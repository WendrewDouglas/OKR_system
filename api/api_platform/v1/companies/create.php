<?php
declare(strict_types=1);

/**
 * POST /companies
 * Cria uma empresa (admin_master).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

if (!api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode criar empresas.', 403);
}

$in = api_input();
api_require_fields($in, ['organizacao']);

$email = api_str($in['email'] ?? '');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  api_error('E_INPUT', 'E-mail inválido.', 422);
}

$pdo->prepare("
  INSERT INTO company
    (organizacao, cnpj, razao_social, email, telefone, missao, visao, created_by, created_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
")->execute([
  api_str($in['organizacao']),
  api_str($in['cnpj'] ?? '') ?: null,
  api_str($in['razao_social'] ?? '') ?: null,
  $email ?: null,
  api_str($in['telefone'] ?? '') ?: null,
  api_str($in['missao'] ?? '') ?: null,
  api_str($in['visao'] ?? '') ?: null,
  $uid,
]);

$id = (int)$pdo->lastInsertId();

api_json(['ok' => true, 'data' => ['id_company' => $id], 'message' => 'Empresa criada.'], 201);
