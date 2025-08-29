<?php
// auth/notificacoes_api.php
declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Não autenticado']); exit; }
$MEU_ID = (int)$_SESSION['user_id'];

try {
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

if ($method==='GET' && $action==='count') {
  $st = $pdo->prepare("SELECT COUNT(*) c FROM notificacoes WHERE id_user=? AND lida=0");
  $st->execute([$MEU_ID]);
  echo json_encode(['count'=>(int)($st->fetch()['c']??0)]);
  exit;
}

if ($method==='GET' && $action==='list') {
  $only_unread = isset($_GET['only_unread']) ? (int)$_GET['only_unread'] : 0;
  $sql = "SELECT id_notificacao, tipo, titulo, mensagem, url, lida,
                 DATE_FORMAT(dt_criado,'%d/%m/%Y %H:%i') AS dt_criado_fmt
          FROM notificacoes
          WHERE id_user=?
          ".($only_unread ? "AND lida=0" : "")."
          ORDER BY dt_criado DESC LIMIT 200";
  $st = $pdo->prepare($sql);
  $st->execute([$MEU_ID]);
  echo json_encode(['items'=>$st->fetchAll() ?: []]);
  exit;
}

if ($method==='POST') {
  // CSRF
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'CSRF inválido']); exit;
  }
  if ($action==='mark_read') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id==='all') {
      $st = $pdo->prepare("UPDATE notificacoes SET lida=1, dt_lida=NOW() WHERE id_user=? AND lida=0");
      $st->execute([$MEU_ID]);
    } else {
      if (!ctype_digit($id)) { echo json_encode(['success'=>false,'error'=>'ID inválido']); exit; }
      $st = $pdo->prepare("UPDATE notificacoes SET lida=1, dt_lida=NOW() WHERE id_user=? AND id_notificacao=?");
      $st->execute([$MEU_ID, (int)$id]);
    }
    echo json_encode(['success'=>true]); exit;
  }
}

http_response_code(400);
echo json_encode(['error'=>'Ação inválida']);
