<?php
declare(strict_types=1);

/**
 * PUT /company/style
 * Atualiza o estilo visual da empresa.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();
if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem alterar o estilo.', 403);
}

$in = api_input();

$bg1  = api_str($in['bg1_hex'] ?? '');
$bg2  = api_str($in['bg2_hex'] ?? '');
$logo = $in['logo_base64'] ?? null;

// Validate hex colors
if ($bg1 !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $bg1)) {
  api_error('E_INPUT', 'bg1_hex deve ser uma cor hex válida (#RRGGBB).', 422);
}
if ($bg2 !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $bg2)) {
  api_error('E_INPUT', 'bg2_hex deve ser uma cor hex válida (#RRGGBB).', 422);
}

// Upsert
$st = $pdo->prepare("SELECT id_style FROM company_style WHERE id_company = ? LIMIT 1");
$st->execute([$cid]);

if ($st->fetchColumn()) {
  $sets = [];
  $params = [];
  if ($bg1 !== '') { $sets[] = "bg1_hex = ?"; $params[] = $bg1; }
  if ($bg2 !== '') { $sets[] = "bg2_hex = ?"; $params[] = $bg2; }
  if ($logo !== null) { $sets[] = "logo_base64 = ?"; $params[] = $logo; }
  $sets[] = "updated_at = NOW()";
  $params[] = $cid;
  if (!empty($sets)) {
    $pdo->prepare("UPDATE company_style SET " . implode(', ', $sets) . " WHERE id_company = ?")->execute($params);
  }
} else {
  $pdo->prepare("
    INSERT INTO company_style (id_company, bg1_hex, bg2_hex, logo_base64, created_by, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
  ")->execute([$cid, $bg1 ?: null, $bg2 ?: null, $logo, $uid]);
}

api_json(['ok' => true, 'message' => 'Estilo atualizado.']);
