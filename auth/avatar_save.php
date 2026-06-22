<?php
declare(strict_types=1);

/**
 * auth/avatar_save.php
 * Endpoint ÚNICO de upload/recorte de avatar do usuário.
 * Recebe a imagem JÁ RECORTADA (quadrada) do front (Cropper.js) e a persiste
 * via auth/avatar_image.php (WebP 256/64 + linha custom + repontar avatar_id).
 *
 * POST (multipart/form-data ou x-www-form-urlencoded):
 *   csrf_token  : token CSRF da sessão (obrigatório)
 *   image_data  : data URL ("data:image/png;base64,...")  -> caminho preferido
 *   avatar      : arquivo ($_FILES) ............................ alternativa
 *
 * Resposta JSON: { ok, avatar_id, url, url_thumb }  | { ok:false, error }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/avatar_image.php';

/* ---- auth ---- */
$userId = (int) ($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

/* ---- método ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST']);
    exit;
}

/* ---- CSRF ---- */
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

/* ---- obtém o binário da imagem ---- */
$bin = '';

$dataUrl = (string) ($_POST['image_data'] ?? '');
if ($dataUrl !== '') {
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $dataUrl)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'image_data inválido']);
        exit;
    }
    $b64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bin = (string) base64_decode($b64, true);
} elseif (isset($_FILES['avatar']) && is_array($_FILES['avatar'])) {
    if (($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Falha no upload do arquivo']);
        exit;
    }
    $tmp = (string) ($_FILES['avatar']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Upload inválido']);
        exit;
    }
    $bin = (string) file_get_contents($tmp);
}

if ($bin === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Nenhuma imagem recebida']);
    exit;
}

/* ---- processa e persiste ---- */
try {
    $res = avatar_store_custom($userId, $bin);
    if (!($res['ok'] ?? false)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Falha ao salvar avatar']);
        exit;
    }
    echo json_encode([
        'ok'        => true,
        'avatar_id' => $res['avatar_id'],
        'url'       => $res['url'],
        'url_thumb' => $res['url_thumb'],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('avatar_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao processar a imagem']);
}
