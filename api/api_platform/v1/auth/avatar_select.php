<?php
declare(strict_types=1);

/**
 * POST /auth/avatar/select   { "avatar_id": N }
 * Escolhe um avatar do catálogo (galeria padrão ou um custom do próprio usuário).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

$in       = api_input();
$avatarId = (int)($in['avatar_id'] ?? 0);
if ($avatarId <= 0) {
  api_error('E_INPUT', 'avatar_id é obrigatório.', 400);
}

$pdo = api_db();

// Valida: existe, ativo e (é da galeria padrão OU pertence ao próprio usuário).
$st = $pdo->prepare(
  "SELECT id FROM avatars
    WHERE id = ? AND active = 1 AND (kind = 'default' OR owner_user_id = ?)
    LIMIT 1"
);
$st->execute([$avatarId, $uid]);
if (!$st->fetchColumn()) {
  api_error('E_INPUT', 'Avatar inválido ou indisponível.', 422);
}

$pdo->prepare("UPDATE usuarios SET avatar_id = ? WHERE id_user = ?")->execute([$avatarId, $uid]);

if (function_exists('avatar_cache_flush')) { @avatar_cache_flush(); }

api_json([
  'ok'               => true,
  'avatar_id'        => $avatarId,
  'avatar_url'       => api_avatar_url_for($uid),
  'avatar_url_thumb' => api_avatar_thumb_for($uid),
]);
