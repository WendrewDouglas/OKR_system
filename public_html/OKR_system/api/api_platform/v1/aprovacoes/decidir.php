<?php
declare(strict_types=1);

/**
 * POST /aprovacoes/decidir
 * Aprova ou rejeita um item.
 * Body: { modulo, id_ref, decisao: "aprovado"|"reprovado", comentarios? }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['modulo', 'id_ref', 'decisao']);

$modulo   = api_enum(api_str($in['modulo']), ['objetivo', 'kr', 'orcamento'], 'modulo');
$idRef    = api_str($in['id_ref']);
$decisao  = api_enum(api_str($in['decisao']), ['aprovado', 'reprovado'], 'decisao');
$comentarios = api_str($in['comentarios'] ?? '');

if ($decisao === 'reprovado' && $comentarios === '') {
  api_error('E_INPUT', 'Comentário obrigatório para rejeição.', 422);
}

// Verify approver
$stAp = $pdo->prepare("SELECT habilitado FROM aprovadores WHERE id_user = ?");
$stAp->execute([$uid]);
$ap = $stAp->fetch();
if (!$ap || (int)$ap['habilitado'] !== 1) {
  if (!api_is_admin_master($pdo, $uid)) {
    api_error('E_FORBIDDEN', 'Você não é aprovador.', 403);
  }
}

$pdo->beginTransaction();
try {
  switch ($modulo) {
    case 'objetivo':
      $pdo->prepare("
        UPDATE objetivos
           SET status_aprovacao = ?, id_user_aprovador = ?,
               dt_aprovacao = NOW(), comentarios_aprovacao = ?
         WHERE id_objetivo = ?
      ")->execute([$decisao, $uid, $comentarios ?: null, $idRef]);
      break;

    case 'kr':
      $pdo->prepare("
        UPDATE key_results
           SET status_aprovacao = ?, id_user_aprovador = ?,
               dt_aprovacao = NOW(), comentarios_aprovacao = ?
         WHERE id_kr = ?
      ")->execute([$decisao, $uid, $comentarios ?: null, $idRef]);
      break;

    case 'orcamento':
      $pdo->prepare("
        UPDATE orcamentos
           SET status_aprovacao = ?, id_user_aprovador = ?,
               dt_aprovacao = NOW(), comentarios_aprovacao = ?
         WHERE id_orcamento = ?
      ")->execute([$decisao, $uid, $comentarios ?: null, $idRef]);
      break;
  }

  // Audit trail
  $pdo->prepare("
    INSERT INTO fluxo_aprovacoes
      (tipo_estrutura, id_referencia, tipo_operacao, status,
       id_user_solicitante, id_user_aprovador, justificativa,
       data_solicitacao, data_aprovacao, ip, user_agent)
    VALUES (?, ?, 'alteracao', ?, NULL, ?, ?, NOW(), NOW(), ?, ?)
  ")->execute([
    $modulo, $idRef, $decisao, $uid, $comentarios ?: null,
    $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
  ]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => "Item $decisao com sucesso."]);
