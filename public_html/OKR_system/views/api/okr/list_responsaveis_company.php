<?php
// /views/api/okr/list_responsaveis_company.php
declare(strict_types=1);

/**
 * Retorna lista de responsáveis (usuários) da empresa.
 * Resposta:
 * { success:true, items:[ {id, label, email?}, ... ] }
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
  json_fail(500, 'PDO não inicializado.');
}

// Opcional: CSRF se POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_fail($_POST['_csrf'] ?? null);
}

/**
 * Encontra a primeira tabela "tipo users" existente.
 */
function find_users_table(PDO $pdo): ?string {
  $candidates = [
    'users',
    'usuarios',
    'user',
    'tb_users',
    'tb_usuarios',
    'forecast_users',    // se você integrava ForecastDB antes
    'user_accounts'
  ];

  foreach ($candidates as $t) {
    $stmt = $pdo->prepare("
      SELECT 1
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = ?
      LIMIT 1
    ");
    $stmt->execute([$t]);
    if ($stmt->fetchColumn()) return $t;
  }

  return null;
}

/**
 * Descobre colunas para ID, nome e email de forma robusta.
 */
function pick_user_cols(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = ?
  ");
  $stmt->execute([$table]);
  $all = array_map(fn($r) => $r['column_name'], $stmt->fetchAll());

  $idCandidates = ['id', 'id_user', 'user_id', 'idusuario', 'id_usuario'];
  $nameCandidates = ['nome', 'name', 'full_name', 'nome_completo', 'username', 'usuario'];
  $emailCandidates = ['email', 'mail', 'e_mail'];

  $idCol = null; foreach ($idCandidates as $c) if (in_array($c, $all, true)) { $idCol = $c; break; }
  $nameCol = null; foreach ($nameCandidates as $c) if (in_array($c, $all, true)) { $nameCol = $c; break; }
  $emailCol = null; foreach ($emailCandidates as $c) if (in_array($c, $all, true)) { $emailCol = $c; break; }

  return [$idCol, $nameCol, $emailCol, $all];
}

$table = find_users_table($pdo);

if (!$table) {
  json_ok([
    'items' => [],
    'source' => 'not_found'
  ]);
}

[$idCol, $nameCol, $emailCol] = pick_user_cols($pdo, $table);

if (!$idCol || !$nameCol) {
  json_fail(500, "Tabela '{$table}' encontrada, mas colunas de ID/NOME não foram identificadas.");
}

$selectEmail = $emailCol ? ", {$emailCol} AS email" : "";
$sql = "SELECT {$idCol} AS id, {$nameCol} AS label {$selectEmail} FROM {$table} ORDER BY {$nameCol} ASC";

$items = $pdo->query($sql)->fetchAll();

json_ok(['items' => $items, 'source' => $table]);