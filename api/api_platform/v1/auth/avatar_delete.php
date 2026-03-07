<?php
declare(strict_types=1);

/**
 * DELETE /auth/avatar
 * Remove o avatar do usuário autenticado.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

$pdo = api_db();

// Get current avatar path
$st = $pdo->prepare("SELECT imagem_url FROM usuarios WHERE id_user = ?");
$st->execute([$uid]);
$currentUrl = $st->fetchColumn();

// Delete file from disk
if ($currentUrl && str_starts_with($currentUrl, '/uploads/avatars/')) {
  $ROOT = dirname(__DIR__, 4);
  $filePath = $ROOT . $currentUrl;
  if (is_file($filePath)) {
    @unlink($filePath);
  }
}

// Clear in database
$pdo->prepare("UPDATE usuarios SET imagem_url = NULL, dt_alteracao = NOW() WHERE id_user = ?")
    ->execute([$uid]);

api_json(['ok' => true, 'message' => 'Avatar removido.']);
