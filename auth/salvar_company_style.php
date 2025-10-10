<?php
// auth/salvar_company_style.php
// Salva/atualiza cores, logo (base64) e okr_master_user_id (inativo) para uma organização.
// Regra: somente a empresa vinculada ao usuário logado pode ser personalizada.
// Somente permite salvar se a empresa possuir CNPJ válido.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__.'/../auth/acl.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Não autorizado']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Método não permitido']); exit;
}
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'CSRF inválido']); exit;
}

// ---- Helpers ----
function only_digits_local($s){ return preg_replace('/\D+/', '', (string)$s); }
function validaCNPJlocal($cnpj) {
  $cnpj = only_digits_local($cnpj);
  if (strlen($cnpj) !== 14) return false;
  if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
  $b = array_map('intval', str_split($cnpj));
  $p1=[5,4,3,2,9,8,7,6,5,4,3,2]; $p2=[6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s=0; for($i=0;$i<12;$i++) $s += $b[$i]*$p1[$i]; $d1 = ($s%11<2)?0:11-$s%11;
  if ($b[12] !== $d1) return false;
  $s=0; for($i=0;$i<13;$i++) $s += $b[$i]*$p2[$i]; $d2 = ($s%11<2)?0:11-$s%11;
  return $b[13] === $d2;
}
function is_hex_color($c){
  return is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
}

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  $id_company = (int)($_POST['id_company'] ?? 0);
  $bg1_hex    = trim($_POST['bg1_hex'] ?? '');
  $bg2_hex    = trim($_POST['bg2_hex'] ?? '');
  $okr_master = null; // reservado (inativo)
  $userId     = (int)$_SESSION['user_id'];

  if ($id_company <= 0) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Selecione uma organização.']); exit;
  }
  if (!is_hex_color($bg1_hex) || !is_hex_color($bg2_hex)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Cores inválidas.']); exit;
  }

  // -------- Permissão: usuário só pode salvar na própria empresa (admin pode em qualquer uma, opcional) --------
  $isAdmin = false;
  try {
    $stAdm = $pdo->prepare("
      SELECT 1 FROM usuarios_permissoes WHERE id_user=:u
        AND id_permissao IN ('admin','user_admin','sys_admin','super_admin') LIMIT 1
    ");
    $stAdm->execute([':u'=>$userId]);
    $isAdmin = (bool)$stAdm->fetchColumn();
  } catch (PDOException $e) { /* silencioso */ }

  $stUserComp = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
  $stUserComp->execute([':u'=>$userId]);
  $userCompanyId = (int)($stUserComp->fetchColumn() ?: 0);

  if (!$isAdmin && $userCompanyId !== $id_company) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Sem permissão para personalizar esta organização.']); exit;
  }

  // -------- Empresa deve existir e ter CNPJ válido --------
  $stComp = $pdo->prepare("SELECT id_company, organizacao, cnpj FROM company WHERE id_company=:c LIMIT 1");
  $stComp->execute([':c'=>$id_company]);
  $company = $stComp->fetch();
  if (!$company) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Organização não encontrada.']); exit;
  }
  $cnpjDigits = only_digits_local($company['cnpj'] ?? '');
  if (!$cnpjDigits || !validaCNPJlocal($cnpjDigits)) {
    http_response_code(422);
    echo json_encode([
      'success'=>false,
      'error'=>'Para salvar o estilo é obrigatório que a organização possua um CNPJ válido.'
    ]); exit;
  }

  // -------- Logo: aceita png/jpg/jpeg/svg, tamanho máx ~ 1.5MB --------
  $logo_data_uri = null;
  if (!empty($_FILES['logo_file']['tmp_name']) && is_uploaded_file($_FILES['logo_file']['tmp_name'])) {
    $tmp  = $_FILES['logo_file']['tmp_name'];
    $size = (int)$_FILES['logo_file']['size'];
    if ($size > 1572864) { // 1.5MB
      http_response_code(413);
      echo json_encode(['success'=>false,'error'=>'Logo muito grande (máx. 1.5MB).']); exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    $ok = in_array($mime, ['image/png','image/jpeg','image/jpg','image/svg+xml'], true);
    if (!$ok) {
      http_response_code(422);
      echo json_encode(['success'=>false,'error'=>'Formato de logo inválido. Use PNG/JPG/SVG.']); exit;
    }
    $raw   = file_get_contents($tmp);
    $b64   = base64_encode($raw);
    $logo_data_uri = 'data:'.$mime.';base64,'.$b64;
  }

  // -------- Upsert em company_style --------
  $exists = $pdo->prepare("SELECT id_style FROM company_style WHERE id_company = :c");
  $exists->execute([':c'=>$id_company]);
  $row = $exists->fetch();

  if ($row) {
    $params = [
      ':bg1'=>$bg1_hex, ':bg2'=>$bg2_hex, ':okr'=>$okr_master,
      ':upd_by'=>$userId, ':id'=>$row['id_style']
    ];
    $sql = "UPDATE company_style
              SET bg1_hex=:bg1, bg2_hex=:bg2, okr_master_user_id=:okr, updated_by=:upd_by".
            ($logo_data_uri!==null ? ", logo_base64=:logo" : "") . "
            WHERE id_style=:id";
    if ($logo_data_uri!==null) $params[':logo'] = $logo_data_uri;
    $pdo->prepare($sql)->execute($params);
    $id_style = (int)$row['id_style'];
  } else {
    $sql = "INSERT INTO company_style
              (id_company, bg1_hex, bg2_hex, logo_base64, okr_master_user_id, created_by)
            VALUES
              (:c, :bg1, :bg2, :logo, :okr, :cb)";
    $pdo->prepare($sql)->execute([
      ':c'=>$id_company, ':bg1'=>$bg1_hex, ':bg2'=>$bg2_hex,
      ':logo'=>$logo_data_uri, ':okr'=>$okr_master, ':cb'=>$userId
    ]);
    $id_style = (int)$pdo->lastInsertId();
  }

  // -------- Retorno consolidado --------
  $st = $pdo->prepare("SELECT * FROM company_style WHERE id_style=:id");
  $st->execute([':id'=>$id_style]);
  $record = $st->fetch();

  echo json_encode(['success'=>true, 'record'=>$record]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro ao salvar: '.$e->getMessage()]);
}
