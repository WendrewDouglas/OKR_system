<?php
declare(strict_types=1);

/**
 * GET /system/health
 * Health checks do sistema (admin_master). Visão system-wide (sem filtro de tenant).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

if (!api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode acessar a saúde do sistema.', 403);
}

$checks = [];

// 1) Conexão com BD
try {
  $pdo->query('SELECT 1');
  $checks[] = ['key' => 'db_alive', 'label' => 'Conexão com BD', 'status' => 'PASS'];
} catch (\Throwable $e) {
  $checks[] = ['key' => 'db_alive', 'label' => 'Conexão com BD', 'status' => 'FAIL'];
}

// 2) Tabelas core
try {
  $core = [
    'company', 'usuarios', 'objetivos', 'key_results', 'iniciativas',
    'milestones_kr', 'rbac_roles', 'rbac_user_role', 'dom_status_kr',
  ];
  $existing = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
  $missing  = array_values(array_diff($core, $existing));
  $checks[] = empty($missing)
    ? ['key' => 'core_tables', 'label' => 'Tabelas core', 'status' => 'PASS', 'detail' => count($core) . ' OK']
    : ['key' => 'core_tables', 'label' => 'Tabelas core', 'status' => 'FAIL', 'detail' => 'Faltando: ' . implode(', ', $missing)];
} catch (\Throwable $e) {
  $checks[] = ['key' => 'core_tables', 'label' => 'Tabelas core', 'status' => 'FAIL'];
}

// 3) Registros órfãos (integridade referencial)
$orphans = [
  ['key' => 'orphan_krs', 'label' => 'KRs órfãos',
   'sql' => 'SELECT COUNT(*) FROM key_results k LEFT JOIN objetivos o ON o.id_objetivo = k.id_objetivo WHERE o.id_objetivo IS NULL'],
  ['key' => 'orphan_iniciativas', 'label' => 'Iniciativas órfãs',
   'sql' => 'SELECT COUNT(*) FROM iniciativas i LEFT JOIN key_results k ON k.id_kr = i.id_kr WHERE k.id_kr IS NULL'],
  ['key' => 'orphan_milestones', 'label' => 'Milestones órfãos',
   'sql' => 'SELECT COUNT(*) FROM milestones_kr m LEFT JOIN key_results k ON k.id_kr = m.id_kr WHERE k.id_kr IS NULL'],
];
foreach ($orphans as $oc) {
  try {
    $n = (int)$pdo->query($oc['sql'])->fetchColumn();
    $checks[] = $n === 0
      ? ['key' => $oc['key'], 'label' => $oc['label'], 'status' => 'PASS']
      : ['key' => $oc['key'], 'label' => $oc['label'], 'status' => 'WARN', 'detail' => "$n encontrado(s)"];
  } catch (\Throwable $e) {
    $checks[] = ['key' => $oc['key'], 'label' => $oc['label'], 'status' => 'FAIL'];
  }
}

// 4) Totais
$totals = [];
foreach (['company' => 'empresas', 'usuarios' => 'usuarios', 'objetivos' => 'objetivos', 'key_results' => 'key_results'] as $tbl => $alias) {
  try {
    $totals[$alias] = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
  } catch (\Throwable $e) {
    $totals[$alias] = null;
  }
}

// Status geral
$overall = 'PASS';
foreach ($checks as $c) {
  if ($c['status'] === 'FAIL') { $overall = 'FAIL'; break; }
  if ($c['status'] === 'WARN') { $overall = 'WARN'; }
}

api_ok([
  'overall' => $overall,
  'checks'  => $checks,
  'totals'  => $totals,
]);
