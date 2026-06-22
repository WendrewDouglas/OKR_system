<?php
declare(strict_types=1);

/**
 * GET /companies/:id
 * Detalhe completo de uma empresa (admin_master).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode gerenciar empresas.', 403);
}

$st = $pdo->prepare("SELECT * FROM company WHERE id_company = ?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) {
  api_error('E_NOT_FOUND', 'Empresa não encontrada.', 404);
}

api_ok([
  'id_company'   => (int)$c['id_company'],
  'organizacao'  => $c['organizacao'],
  'cnpj'         => $c['cnpj'],
  'razao_social' => $c['razao_social'],
  'logradouro'   => $c['logradouro'],
  'numero'       => $c['numero'],
  'complemento'  => $c['complemento'],
  'cep'          => $c['cep'],
  'bairro'       => $c['bairro'],
  'municipio'    => $c['municipio'],
  'uf'           => $c['uf'],
  'email'        => $c['email'],
  'telefone'     => $c['telefone'],
  'missao'       => $c['missao'],
  'visao'        => $c['visao'],
  'created_at'   => $c['created_at'],
  'updated_at'   => $c['updated_at'],
]);
