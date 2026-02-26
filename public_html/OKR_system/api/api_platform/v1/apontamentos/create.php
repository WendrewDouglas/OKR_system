<?php
declare(strict_types=1);

/**
 * POST /krs/:id_kr/apontamentos
 * Salva apontamentos (batch: múltiplos milestones de uma vez).
 * Body: { items: [ { id_milestone, valor_real, dt_evidencia?, observacao? }, ... ] }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id_kr');
$pdo  = api_db();

// RBAC
if (!api_has_cap($pdo, $uid, $cid, 'W:apontamento@ORG', ['id_kr' => $idKr])) {
  api_error('E_FORBIDDEN', 'Sem permissão para registrar apontamentos neste KR.', 403);
}

$in    = api_input();
$items = $in['items'] ?? [];
if (!is_array($items) || empty($items)) {
  api_error('E_INPUT', 'Campo items é obrigatório (array de apontamentos).', 422);
}

$pdo->beginTransaction();
try {
  $saved = [];
  foreach ($items as $item) {
    $idMs       = $item['id_milestone'] ?? null;
    $valorReal  = $item['valor_real'] ?? null;
    $dtEvidencia = api_str($item['dt_evidencia'] ?? date('Y-m-d'));
    $observacao  = api_str($item['observacao'] ?? '');

    if ($valorReal === null || $valorReal === '') continue;
    $valorReal = (float)$valorReal;

    // Check if this is an overwrite
    $overwrite = false;
    if ($idMs) {
      $stPrev = $pdo->prepare("SELECT valor_real_consolidado FROM milestones_kr WHERE id_milestone = ? AND id_kr = ?");
      $stPrev->execute([$idMs, $idKr]);
      $prev = $stPrev->fetchColumn();
      $overwrite = ($prev !== null && $prev !== false);

      // Update milestone
      $pdo->prepare("
        UPDATE milestones_kr
           SET valor_real_consolidado = ?,
               dt_ultimo_apontamento = NOW(),
               qtde_apontamentos = qtde_apontamentos + 1,
               editado_manual = 1
         WHERE id_milestone = ? AND id_kr = ?
      ")->execute([$valorReal, $idMs, $idKr]);
    }

    // Insert apontamento record
    $pdo->prepare("
      INSERT INTO apontamentos_kr
        (id_kr, id_milestone, valor_real, dt_evidencia, observacao,
         usuario_id, dt_apontamento, origem)
      VALUES (?, ?, ?, ?, ?, ?, NOW(), 'manual')
    ")->execute([$idKr, $idMs, $valorReal, $dtEvidencia, $observacao ?: null, $uid]);

    $saved[] = [
      'id_milestone' => $idMs ? (int)$idMs : null,
      'valor_real'   => $valorReal,
      'dt_evidencia' => $dtEvidencia,
      'overwrite'    => $overwrite,
    ];
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'salvos' => count($saved), 'items' => $saved], 201);
