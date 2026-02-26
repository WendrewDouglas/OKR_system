<?php
declare(strict_types=1);

/**
 * GET /company/style
 * Retorna o estilo visual (tema) da empresa.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();
$st = $pdo->prepare("
  SELECT id_style, id_company, bg1_hex, bg2_hex, logo_base64, created_at, updated_at
    FROM company_style
   WHERE id_company = ?
   LIMIT 1
");
$st->execute([$cid]);
$style = $st->fetch();

if (!$style) {
  api_json(['ok' => true, 'style' => null]);
}

api_json([
  'ok'    => true,
  'style' => [
    'id_style'    => (int)$style['id_style'],
    'bg1_hex'     => $style['bg1_hex'],
    'bg2_hex'     => $style['bg2_hex'],
    'has_logo'    => !empty($style['logo_base64']),
    'logo_base64' => $style['logo_base64'] ?: null,
    'updated_at'  => $style['updated_at'],
  ],
]);
