<?php
declare(strict_types=1);

/**
 * DELETE /auth/avatar
 * Remove o avatar do usuário → repointa para o padrão (avatar_id = 1) e
 * desativa a linha custom (arquivos ficam para limpeza posterior).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

$pdo = api_db();

// Repointar para o avatar padrão (id 1 = default_avatar/default.png).
$pdo->prepare("UPDATE usuarios SET avatar_id = 1 WHERE id_user = ?")->execute([$uid]);

// Desativa a linha custom do usuário (re-upload reativa a mesma linha).
$pdo->prepare("UPDATE avatars SET active = 0 WHERE kind = 'custom' AND owner_user_id = ?")->execute([$uid]);

// Invalida cache de sessão (no-op fora do contexto web).
if (function_exists('avatar_cache_flush')) { @avatar_cache_flush(); }

api_json([
  'ok'               => true,
  'avatar_url'       => api_avatar_url_for($uid),
  'avatar_url_thumb' => api_avatar_thumb_for($uid),
  'message'          => 'Avatar removido.',
]);
