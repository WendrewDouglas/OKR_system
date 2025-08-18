<?php
// auth/salvar_missao_visao.php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'error'=>'Método não permitido']); exit;
}
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'CSRF inválido']); exit;
}

$id_company = (int)($_POST['id_company'] ?? 0);
$missao     = trim((string)($_POST['missao'] ?? ''));
$visao      = trim((string)($_POST['visao'] ?? ''));

// limites de segurança (evita payloads absurdos)
if (strlen($missao) > 10000) $missao = substr($missao,0,10000);
if (strlen($visao)  > 10000) $visao  = substr($visao,0,10000);

if ($id_company <= 0) {
  http_response_code(422);
  echo json_encode(['success'=>false,'error'=>'Selecione uma organização.']); exit;
}

try {
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // garante que existe
  $ck = $pdo->prepare("SELECT id_company FROM company WHERE id_company=:id");
  $ck->execute([':id'=>$id_company]);
  if (!$ck->fetch()) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Organização não encontrada.']); exit;
  }

  $st = $pdo->prepare("UPDATE company SET missao=:m, visao=:v WHERE id_company=:id");
  $st->execute([':m'=>$missao, ':v'=>$visao, ':id'=>$id_company]);

  echo json_encode(['success'=>true]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro ao salvar: '.$e->getMessage()]);
}
