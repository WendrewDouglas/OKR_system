<?php
declare(strict_types=1);

/**
 * PUT /usuarios/:id/permissions
 * Salva overrides de capabilities do usuário.
 * Body: { overrides: [ { capability_id, effect: "ALLOW"|"DENY" }, ... ] }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();
$id   = api_int(api_param('id'), 'id');

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$in = api_input();
$overrides = $in['overrides'] ?? [];
if (!is_array($overrides)) {
  api_error('E_INPUT', 'overrides deve ser um array.', 422);
}

$pdo->beginTransaction();
try {
  // Clear existing overrides
  $pdo->prepare("DELETE FROM rbac_user_capability WHERE user_id = ?")->execute([$id]);

  // Insert new overrides
  $stIns = $pdo->prepare("
    INSERT INTO rbac_user_capability (user_id, capability_id, effect, note)
    VALUES (?, ?, ?, ?)
  ");
  $count = 0;
  foreach ($overrides as $o) {
    $capId  = (int)($o['capability_id'] ?? 0);
    $effect = strtoupper(api_str($o['effect'] ?? 'ALLOW'));
    $note   = api_str($o['note'] ?? '');
    if ($capId <= 0 || !in_array($effect, ['ALLOW', 'DENY'])) continue;
    $stIns->execute([$id, $capId, $effect, $note ?: null]);
    $count++;
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'saved' => $count, 'message' => 'Permissões atualizadas.']);
