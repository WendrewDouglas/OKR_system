<?php
declare(strict_types=1);

/**
 * PUT /company/me
 * Atualiza dados da empresa do usuário autenticado.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);

if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();

// Only admin can update company
if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem alterar a empresa.', 403);
}

$in = api_input();
$sets   = [];
$params = [];

$allowed = ['organizacao', 'razao_social', 'cnpj', 'missao', 'visao',
            'logradouro', 'numero', 'complemento', 'cep', 'bairro', 'municipio', 'uf',
            'email', 'telefone'];

foreach ($allowed as $field) {
  if (array_key_exists($field, $in)) {
    $sets[]   = "$field = ?";
    $params[] = api_str($in[$field]);
  }
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[] = "updated_at = NOW()";
$sets[] = "updated_by = ?";
$params[] = $uid;
$params[] = $cid;

$pdo->prepare("UPDATE company SET " . implode(', ', $sets) . " WHERE id_company = ?")->execute($params);

// Return updated
$st = $pdo->prepare("
  SELECT id_company, organizacao, razao_social, cnpj, missao, visao,
         logradouro, numero, complemento, cep, bairro, municipio, uf,
         email, telefone
    FROM company WHERE id_company = ?
");
$st->execute([$cid]);

api_json(['ok' => true, 'company' => $st->fetch()]);
