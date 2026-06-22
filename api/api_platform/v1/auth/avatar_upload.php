<?php
declare(strict_types=1);

/**
 * POST /auth/avatar
 * Upload de avatar do usuário autenticado.
 * Grava no CATÁLOGO (auth/avatar_image.php::avatar_store_custom): gera WebP 256/64,
 * cria/atualiza a linha kind='custom' e repointa usuarios.avatar_id.
 * (Não usa mais uploads/avatars/ nem usuarios.imagem_url.)
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);

if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  api_error('E_INPUT', 'Nenhum arquivo enviado. Envie um campo "avatar".', 400);
}

$tmp = $_FILES['avatar']['tmp_name'] ?? '';
$bin = ($tmp !== '' && is_uploaded_file($tmp)) ? file_get_contents($tmp) : false;
if ($bin === false || $bin === '') {
  api_error('E_INPUT', 'Falha ao ler o arquivo enviado.', 400);
}

// Pipeline único do web (valida MIME/tamanho/dimensão, gera WebP, grava no catálogo).
require_once dirname(__DIR__, 4) . '/auth/avatar_image.php';

$res = avatar_store_custom($uid, $bin, api_db());
if (empty($res['ok'])) {
  api_error('E_INPUT', $res['error'] ?? 'Falha ao salvar avatar.', 400);
}

api_json([
  'ok'               => true,
  'avatar_id'        => (int)($res['avatar_id'] ?? 0),
  'avatar_url'       => api_avatar_abs($res['url'] ?? null),
  'avatar_url_thumb' => api_avatar_abs($res['url_thumb'] ?? null),
  'message'          => 'Avatar atualizado com sucesso.',
]);
