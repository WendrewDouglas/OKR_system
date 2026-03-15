<?php
/**
 * auth/push_api.php
 * Endpoint unificado para operacoes push via sessao web.
 * Acoes: send-test, cancel, duplicate, segments-list, segments-save, segments-delete
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helpers.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Nao autorizado']); exit;
}

$uid = (int)$_SESSION['user_id'];
$pdo = pdo_conn();

// Verifica admin_master
$st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1 WHERE ur.user_id=? AND r.role_key='admin_master' LIMIT 1");
$st->execute([$uid]);
if (!$st->fetchColumn()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Acesso restrito']); exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$action = trim($_GET['action'] ?? $in['action'] ?? '');

switch ($action) {

  case 'send-test':
    $campId = (int)($in['id_campaign'] ?? 0);
    if ($campId <= 0) { echo json_encode(['ok'=>false,'message'=>'ID invalido']); exit; }
    $camp = $pdo->prepare("SELECT * FROM push_campaigns WHERE id_campaign=?");
    $camp->execute([$campId]);
    $campaign = $camp->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) { echo json_encode(['ok'=>false,'message'=>'Campanha nao encontrada']); exit; }

    $dev = $pdo->prepare("SELECT * FROM push_devices WHERE id_user=? AND is_active=1 ORDER BY last_seen_at DESC LIMIT 1");
    $dev->execute([$uid]);
    $device = $dev->fetch(PDO::FETCH_ASSOC);

    $imageUrl = null;
    if ($campaign['image_asset_id']) {
      $ast = $pdo->prepare("SELECT public_url FROM push_assets WHERE id_asset=?");
      $ast->execute([$campaign['image_asset_id']]);
      $imageUrl = $ast->fetchColumn() ?: null;
      if ($imageUrl && strpos($imageUrl, 'http') !== 0) $imageUrl = 'https://planningbi.com.br' . $imageUrl;
    }

    $result = ['push_sent'=>false,'inbox_sent'=>false];
    if ($device && in_array($campaign['canal'], ['push','push_inbox'])) {
      $r = push_send_fcm($device['token'], [
        'title'=>'[TESTE] '.$campaign['titulo'], 'body'=>$campaign['descricao'],
        'image'=>$imageUrl, 'data'=>['campaign_id'=>(string)$campId,'route'=>$campaign['route']??'','test'=>'1'],
      ]);
      $result['push_sent'] = $r['success'];
      $result['push_error'] = $r['error'];
    }
    if (in_array($campaign['canal'], ['inbox','push_inbox'])) {
      push_mirror_to_inbox($pdo, $uid, $campaign);
      $result['inbox_sent'] = true;
    }
    echo json_encode(['ok'=>true,'result'=>$result]);
    break;

  case 'cancel':
    $campId = (int)($in['id_campaign'] ?? 0);
    $st = $pdo->prepare("UPDATE push_campaigns SET status='cancelled', cancelled_at=NOW(), updated_by=?, updated_at=NOW() WHERE id_campaign=? AND status IN ('draft','scheduled')");
    $st->execute([$uid, $campId]);
    echo json_encode(['ok'=>$st->rowCount()>0,'message'=>$st->rowCount()>0?'Cancelada':'Nao foi possivel cancelar']);
    break;

  case 'duplicate':
    $campId = (int)($in['id_campaign'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM push_campaigns WHERE id_campaign=?");
    $st->execute([$campId]);
    $orig = $st->fetch(PDO::FETCH_ASSOC);
    if (!$orig) { echo json_encode(['ok'=>false,'message'=>'Nao encontrada']); exit; }
    $pdo->prepare("INSERT INTO push_campaigns (nome_interno,canal,categoria,titulo,descricao,image_asset_id,route,url_web,priority,status,timezone,filters_json,prompt_ia,created_by) VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?,?,?)")
      ->execute(['[Copia] '.$orig['nome_interno'],$orig['canal'],$orig['categoria'],$orig['titulo'],$orig['descricao'],$orig['image_asset_id'],$orig['route'],$orig['url_web'],$orig['priority'],$orig['timezone'],$orig['filters_json'],$orig['prompt_ia'],$uid]);
    echo json_encode(['ok'=>true,'id_campaign'=>(int)$pdo->lastInsertId()]);
    break;

  case 'segments-save':
    $nome = trim($in['nome'] ?? '');
    $filtersJson = $in['filters_json'] ?? '{}';
    if (!$nome) { echo json_encode(['ok'=>false,'message'=>'Nome obrigatorio']); exit; }
    $pdo->prepare("INSERT INTO push_segments (nome, descricao, filters_json, created_by) VALUES (?,?,?,?)")
      ->execute([$nome, trim($in['descricao'] ?? ''), $filtersJson, $uid]);
    echo json_encode(['ok'=>true,'id_segment'=>(int)$pdo->lastInsertId()]);
    break;

  case 'segments-delete':
    $segId = (int)($in['id_segment'] ?? 0);
    $pdo->prepare("DELETE FROM push_segments WHERE id_segment=?")->execute([$segId]);
    echo json_encode(['ok'=>true]);
    break;

  default:
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>'Acao invalida: '.$action]);
}
