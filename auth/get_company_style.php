<?php
// auth/get_company_style.php
// Retorna estilo da organização (se existir).

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__.'/../auth/acl.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}
$id_company = (int)($_GET['id_company'] ?? 0);
if ($id_company <= 0) {
  http_response_code(422);
  echo json_encode(['success'=>false,'error'=>'Parâmetro inválido']); exit;
}
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
  $st = $pdo->prepare("SELECT * FROM company_style WHERE id_company=:c");
  $st->execute([':c'=>$id_company]);
  $rec = $st->fetch();
  echo json_encode(['success'=>true, 'record'=>$rec ?: null]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success'=>false, 'error'=>'Erro: '.$e->getMessage()]);
}
