<?php
/**
 * auth/push_save_campaign.php
 * Salva/atualiza campanha de push (form POST com upload).
 * Acesso restrito a admin_master.
 */
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/push_helpers.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Nao autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Metodo nao permitido']); exit; }
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  http_response_code(403); echo json_encode(['success'=>false,'error'=>'CSRF invalido']); exit;
}

$pdo = pdo_conn();
$uid = (int)$_SESSION['user_id'];

// Verifica admin_master
$st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1 WHERE ur.user_id=? AND r.role_key='admin_master' LIMIT 1");
$st->execute([$uid]);
if (!$st->fetchColumn()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Acesso restrito']); exit; }

try {
  $action       = trim($_POST['action'] ?? 'draft');
  $idCampaign   = (int)($_POST['id_campaign'] ?? 0);
  $nomeInterno  = trim($_POST['nome_interno'] ?? '');
  $canal        = trim($_POST['canal'] ?? 'push');
  $categoria    = trim($_POST['categoria'] ?? '');
  $titulo       = trim($_POST['titulo'] ?? '');
  $descricao    = trim($_POST['descricao'] ?? '');
  $route        = trim($_POST['route'] ?? '');
  $urlWeb       = trim($_POST['url_web'] ?? '');
  $priority     = trim($_POST['priority'] ?? 'normal');
  $scheduledAt  = trim($_POST['scheduled_at'] ?? '');
  $recurrence   = trim($_POST['recurrence_rule'] ?? '');
  $filtersJson  = trim($_POST['filters_json'] ?? '{}');
  $promptIa     = trim($_POST['prompt_ia'] ?? '');

  if (!$nomeInterno) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Nome interno obrigatorio']); exit; }
  if (!$titulo) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Titulo obrigatorio']); exit; }
  if (!$descricao) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Descricao obrigatoria']); exit; }
  if (!in_array($canal, ['push','inbox','push_inbox'])) $canal = 'push';
  if (!in_array($priority, ['normal','high'])) $priority = 'normal';

  // Upload imagem
  $assetId = null;
  if (!empty($_FILES['image_file']['tmp_name']) && is_uploaded_file($_FILES['image_file']['tmp_name'])) {
    $tmp  = $_FILES['image_file']['tmp_name'];
    $size = (int)$_FILES['image_file']['size'];
    if ($size > 2097152) { http_response_code(413); echo json_encode(['success'=>false,'error'=>'Imagem muito grande (max 2MB)']); exit; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    if (!in_array($mime, ['image/png','image/jpeg','image/webp'])) {
      http_response_code(422); echo json_encode(['success'=>false,'error'=>'Formato invalido. Use PNG/JPEG/WebP']); exit;
    }

    // Redimensionar para 500x500 max
    $img = null;
    switch ($mime) {
      case 'image/png':  $img = imagecreatefrompng($tmp); break;
      case 'image/jpeg': $img = imagecreatefromjpeg($tmp); break;
      case 'image/webp': $img = imagecreatefromwebp($tmp); break;
    }
    if (!$img) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Imagem corrompida']); exit; }

    $w = imagesx($img); $h = imagesy($img);
    if ($w > 500 || $h > 500) {
      $newImg = imagecreatetruecolor(500, 500);
      imagecopyresampled($newImg, $img, 0, 0, 0, 0, 500, 500, $w, $h);
      imagedestroy($img);
      $img = $newImg;
    }

    // Salvar
    $uploadDir = dirname(__DIR__) . '/uploads/push/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $fname = bin2hex(random_bytes(16)) . '.jpg';
    $path  = $uploadDir . $fname;
    imagejpeg($img, $path, 85);
    imagedestroy($img);

    $hash = hash_file('sha256', $path);
    $finalSize = filesize($path);
    $publicUrl = '/OKR_system/uploads/push/' . $fname;
    $origName  = $_FILES['image_file']['name'] ?: 'push_image.jpg';

    $pdo->prepare("INSERT INTO push_assets (original_name, mime_type, ext, path, public_url, width, height, size_bytes, sha256_hash, created_by)
      VALUES (?, 'image/jpeg', 'jpg', ?, ?, 500, 500, ?, ?, ?)")
      ->execute([$origName, 'uploads/push/'.$fname, $publicUrl, $finalSize, $hash, $uid]);
    $assetId = (int)$pdo->lastInsertId();
  }

  // Estima audiencia
  $filters = json_decode($filtersJson, true) ?: [];
  $estimate = push_count_audience($filters, $pdo);

  // Status
  $status = 'draft';
  $sentAt = null;
  if ($action === 'schedule' && $scheduledAt) $status = 'scheduled';
  if ($action === 'send') $status = 'scheduled'; // Sera processado imediatamente pelo cron/trigger

  $isRecurring = ($recurrence && $scheduledAt) ? 1 : 0;

  if ($idCampaign > 0) {
    // Update
    $sql = "UPDATE push_campaigns SET
      nome_interno=?, canal=?, categoria=?, titulo=?, descricao=?, route=?, url_web=?,
      priority=?, status=?, scheduled_at=?, timezone=?, is_recurring=?, recurrence_rule=?,
      prompt_ia=?, audience_estimate=?, filters_json=?, updated_by=?, updated_at=NOW()" .
      ($assetId ? ", image_asset_id=?" : "") .
      " WHERE id_campaign=?";
    $params = [$nomeInterno, $canal, $categoria, $titulo, $descricao, $route, $urlWeb,
      $priority, $status, $scheduledAt ?: null, 'America/Sao_Paulo', $isRecurring, $recurrence ?: null,
      $promptIa, $estimate, $filtersJson, $uid];
    if ($assetId) $params[] = $assetId;
    $params[] = $idCampaign;
    $pdo->prepare($sql)->execute($params);
  } else {
    // Insert
    $pdo->prepare("INSERT INTO push_campaigns
      (nome_interno, canal, categoria, titulo, descricao, image_asset_id, route, url_web,
       priority, status, scheduled_at, timezone, is_recurring, recurrence_rule,
       prompt_ia, audience_estimate, filters_json, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$nomeInterno, $canal, $categoria, $titulo, $descricao, $assetId, $route, $urlWeb,
        $priority, $status, $scheduledAt ?: null, 'America/Sao_Paulo', $isRecurring, $recurrence ?: null,
        $promptIa, $estimate, $filtersJson, $uid]);
    $idCampaign = (int)$pdo->lastInsertId();
  }

  // Enviar imediatamente
  if ($action === 'send') {
    $pdo->prepare("UPDATE push_campaigns SET status='scheduled', scheduled_at=NOW() WHERE id_campaign=?")->execute([$idCampaign]);
    $result = push_process_campaign($pdo, $idCampaign);
    echo json_encode(['success'=>true, 'id_campaign'=>$idCampaign, 'message'=>"Enviado! {$result['sent']} de {$result['total_target']}", 'stats'=>$result]);
    exit;
  }

  $msg = $status === 'scheduled' ? 'Campanha agendada!' : 'Rascunho salvo!';
  echo json_encode(['success'=>true, 'id_campaign'=>$idCampaign, 'message'=>$msg]);

} catch (Throwable $e) {
  error_log('push_save_campaign: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false, 'error'=>'Erro interno. Tente novamente.']);
}
