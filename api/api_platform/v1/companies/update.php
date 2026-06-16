<?php
declare(strict_types=1);

/**
 * PUT /companies/:id
 * Atualiza uma empresa (admin_master — pode editar qualquer empresa).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode editar empresas.', 403);
}

// Confirma existência
$st = $pdo->prepare("SELECT id_company FROM company WHERE id_company = ?");
$st->execute([$id]);
if (!$st->fetch()) {
  api_error('E_NOT_FOUND', 'Empresa não encontrada.', 404);
}

$in = api_input();

if (array_key_exists('email', $in)) {
  $email = api_str($in['email']);
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('E_INPUT', 'E-mail inválido.', 422);
  }
}

$allowed = [
  'organizacao', 'cnpj', 'razao_social', 'logradouro', 'numero', 'complemento',
  'cep', 'bairro', 'municipio', 'uf', 'email', 'telefone', 'missao', 'visao',
];
$sets   = [];
$params = [];
foreach ($allowed as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]) ?: null;
  }
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[]   = 'updated_by = ?';
$params[] = $uid;
$sets[]   = 'updated_at = NOW()';
$params[] = $id;

$pdo->prepare("UPDATE company SET " . implode(', ', $sets) . " WHERE id_company = ?")->execute($params);

api_ok(['id_company' => $id], [], null);
