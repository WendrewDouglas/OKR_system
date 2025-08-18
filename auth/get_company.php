<?php
// auth/get_company.php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

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
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  $st = $pdo->prepare("SELECT id_company, organizacao, cnpj, missao, visao FROM company WHERE id_company=:id");
  $st->execute([':id'=>$id_company]);
  $rec = $st->fetch();

  echo json_encode(['success'=>true, 'record'=>$rec ?: null]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro: '.$e->getMessage()]);
}
