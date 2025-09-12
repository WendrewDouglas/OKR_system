<?php
// admin/sync_avatars.php
declare(strict_types=1);

// DEV (remova em produção)
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Ajuste este caminho se necessário
require_once __DIR__ . '/../auth/config.php';

try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  die("Erro conexão: " . $e->getMessage());
}

// Caminho físico da pasta de avatares
$defaultsDir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar');
if (!$defaultsDir) {
  http_response_code(500);
  die("Pasta de avatares não encontrada.");
}

// Garante índice único em filename (se ainda não existir)
try {
  $pdo->exec("ALTER TABLE avatars ADD UNIQUE KEY (filename)");
} catch (Throwable $e) {
  // ok se já existir
}

$files = glob($defaultsDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];

$ins = $pdo->prepare("
  INSERT INTO avatars (filename, gender, active)
  VALUES (:f, :g, 1)
  ON DUPLICATE KEY UPDATE
    gender = VALUES(gender),
    active = VALUES(active)
");

$tot = 0; $novos = 0; $upd = 0; $erros = 0;
foreach ($files as $path) {
  $bn = basename($path);

  // Se quiser NÃO listar o default.png no modal, pule-o aqui:
  if (strcasecmp($bn, 'default.png') === 0) {
    // ainda assim garantimos que ele existe e está ativo, caso você use em outros lugares
    // comente o 'continue' se quiser incluí-lo na tabela também
    // continue;
  }

  // Heurística de gênero pelo nome do arquivo
  $gender = 'todos';
  if (stripos($bn, 'fem') === 0)      $gender = 'feminino';
  elseif (stripos($bn, 'user') === 0) $gender = 'masculino';

  try {
    $ok = $ins->execute([':f' => $bn, ':g' => $gender]);
    if ($ok) {
      // RowCount: 1 = insert novo; 2 = update por duplicate (varia por driver, mas é um bom indicativo)
      $rc = $ins->rowCount();
      if ($rc === 1) $novos++;
      elseif ($rc === 2) $upd++;
      $tot++;
    }
  } catch (Throwable $e) {
    $erros++;
  }
}

header('Content-Type: text/plain; charset=utf-8');
echo "Arquivos encontrados: " . count($files) . PHP_EOL;
echo "Inseridos novos: $novos" . PHP_EOL;
echo "Atualizados: $upd" . PHP_EOL;
echo "Erros: $erros" . PHP_EOL;
echo "Ok.\n";
