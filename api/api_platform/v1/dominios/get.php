<?php
declare(strict_types=1);

/**
 * GET /dominios/:tabela
 * Retorna items de uma tabela de domínio (lookup).
 * Tabelas permitidas (whitelist): dom_status_kr, dom_pilar_bsc, dom_tipo_objetivo,
 * dom_tipo_kr, dom_natureza_kr, dom_ciclos, dom_tipo_frequencia_milestone,
 * dom_status_aprovacao, dom_status_financeiro, dom_departamentos, dom_cargos,
 * dom_niveis_cargo, dom_qualidade_objetivo, dom_modulo_aprovacao, dom_permissoes
 */

api_require_auth();

$tabela = api_param('tabela');

$allowed = [
  'dom_status_kr', 'dom_pilar_bsc', 'dom_tipo_objetivo', 'dom_tipo_kr',
  'dom_natureza_kr', 'dom_ciclos', 'dom_tipo_frequencia_milestone',
  'dom_status_aprovacao', 'dom_status_financeiro', 'dom_departamentos',
  'dom_cargos', 'dom_niveis_cargo', 'dom_qualidade_objetivo',
  'dom_modulo_aprovacao', 'dom_permissoes',
];

if (!in_array($tabela, $allowed, true)) {
  api_error('E_INPUT', "Tabela '$tabela' não permitida.", 400);
}

$pdo = api_db();

// Discover columns dynamically
$cols = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll();
$colNames = array_column($cols, 'Field');

// Try to find id and label columns
$idCol = null;
$labelCol = null;
foreach ($colNames as $c) {
  if ($idCol === null && preg_match('/^(id|id_\w+|codigo)$/i', $c)) $idCol = $c;
  if ($labelCol === null && preg_match('/^(descricao|descricao_exibicao|label|nome|role_name|slug)$/i', $c)) $labelCol = $c;
}
if (!$idCol) $idCol = $colNames[0];
if (!$labelCol) $labelCol = $colNames[1] ?? $colNames[0];

// Build query with all columns
$allCols = implode(', ', array_map(fn($c) => "`$c`", $colNames));
$orderCol = in_array('ordem_pilar', $colNames) ? 'ordem_pilar' : (in_array('ordem', $colNames) ? 'ordem' : $idCol);

$rows = $pdo->query("SELECT $allCols FROM `$tabela` ORDER BY `$orderCol`")->fetchAll();

api_json([
  'ok'       => true,
  'tabela'   => $tabela,
  'id_col'   => $idCol,
  'label_col' => $labelCol,
  'items'    => $rows,
]);
