<?php
declare(strict_types=1);

/**
 * POST /auth/avatar
 * Upload de avatar do usuário autenticado.
 * Aceita multipart/form-data com campo "avatar" (max 5MB, PNG/JPEG/WebP).
 * Redimensiona para 250x250px e armazena como arquivo.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

if (empty($_FILES['avatar'])) {
  api_error('E_INPUT', 'Nenhum arquivo enviado. Envie um campo "avatar".', 400);
}

$file = $_FILES['avatar'];

// Validate upload
if ($file['error'] !== UPLOAD_ERR_OK) {
  api_error('E_INPUT', 'Erro no upload do arquivo.', 400);
}

// Max 5MB
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
  api_error('E_INPUT', 'Arquivo muito grande. Máximo 5MB.', 400);
}

// Validate MIME type
$allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMimes, true)) {
  api_error('E_INPUT', 'Tipo de arquivo não permitido. Use PNG, JPEG ou WebP.', 400);
}

// Create upload directory
$ROOT = dirname(__DIR__, 4); // .../OKR_system
$uploadDir = $ROOT . '/uploads/avatars/' . $uid;
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0775, true);
}

// Generate unique filename
$ext = 'png';
$filename = bin2hex(random_bytes(8)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

// Resize to 250x250 using GD (available on most PHP hosts)
$srcImg = null;
switch ($mime) {
  case 'image/jpeg': $srcImg = imagecreatefromjpeg($file['tmp_name']); break;
  case 'image/png':  $srcImg = imagecreatefrompng($file['tmp_name']); break;
  case 'image/webp': $srcImg = imagecreatefromwebp($file['tmp_name']); break;
}

if (!$srcImg) {
  api_error('E_INPUT', 'Não foi possível processar a imagem.', 400);
}

$srcW = imagesx($srcImg);
$srcH = imagesy($srcImg);
$size = 250;

// Crop to square (center) then resize
$cropSize = min($srcW, $srcH);
$cropX = (int)(($srcW - $cropSize) / 2);
$cropY = (int)(($srcH - $cropSize) / 2);

$dstImg = imagecreatetruecolor($size, $size);
imagealphablending($dstImg, false);
imagesavealpha($dstImg, true);
imagecopyresampled($dstImg, $srcImg, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);
imagepng($dstImg, $destPath, 6);
imagedestroy($srcImg);
imagedestroy($dstImg);

if (!is_file($destPath)) {
  api_error('E_SERVER', 'Falha ao salvar imagem.', 500);
}

// Delete previous avatar files (keep only the new one)
$files = glob($uploadDir . '/*');
foreach ($files as $f) {
  if ($f !== $destPath && is_file($f)) {
    @unlink($f);
  }
}

// Store relative URL in database
$avatarUrl = '/uploads/avatars/' . $uid . '/' . $filename;

$pdo = api_db();
$pdo->prepare("UPDATE usuarios SET imagem_url = ?, dt_alteracao = NOW() WHERE id_user = ?")
    ->execute([$avatarUrl, $uid]);

api_json([
  'ok' => true,
  'avatar_url' => $avatarUrl,
  'message' => 'Avatar atualizado com sucesso.',
]);
