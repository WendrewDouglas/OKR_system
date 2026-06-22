<?php
declare(strict_types=1);

/**
 * DELETE /company/style
 * Restaura o estilo da empresa para o padrão (cores default; logo nulo → o
 * front usa o logo padrão). Admin da própria empresa (api_is_admin).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();
if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem redefinir o estilo.', 403);
}

$bg1 = '#222222';
$bg2 = '#f1c40f';

$st = $pdo->prepare("SELECT id_style FROM company_style WHERE id_company = ? LIMIT 1");
$st->execute([$cid]);

if ($st->fetchColumn()) {
  $pdo->prepare("
    UPDATE company_style
       SET bg1_hex = ?, bg2_hex = ?, logo_base64 = NULL, updated_at = NOW()
     WHERE id_company = ?
  ")->execute([$bg1, $bg2, $cid]);
} else {
  $pdo->prepare("
    INSERT INTO company_style (id_company, bg1_hex, bg2_hex, logo_base64, created_by, created_at, updated_at)
    VALUES (?, ?, ?, NULL, ?, NOW(), NOW())
  ")->execute([$cid, $bg1, $bg2, $uid]);
}

api_ok([
  'bg1_hex'     => $bg1,
  'bg2_hex'     => $bg2,
  'logo_base64' => null,
]);
