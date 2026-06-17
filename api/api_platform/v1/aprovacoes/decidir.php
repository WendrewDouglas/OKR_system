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

// Nome do aprovador (gravado em objetivos/key_results.aprovador, igual ao fluxo web).
$stNome = $pdo->prepare("SELECT TRIM(CONCAT(primeiro_nome, ' ', COALESCE(ultimo_nome, ''))) FROM usuarios WHERE id_user = ?");
$stNome->execute([$uid]);
$nomeAprovador = (string)($stNome->fetchColumn() ?: '');

// Isolamento multi-tenant: o item decidido deve pertencer à empresa do aprovador
// (admin_master decide cross-empresa por design, consistente com aprovacoes/list).
$ctxMap = [
  'objetivo'  => ['id_objetivo'  => $idRef],
  'kr'        => ['id_kr'        => $idRef],
  'orcamento' => ['id_orcamento' => $idRef],
];
$itemCompany = api_resolve_resource_company($pdo, $modulo, $ctxMap[$modulo]);
if ($itemCompany === null) {
  api_error('E_NOT_FOUND', 'Item de aprovação não encontrado.', 404);
}
if (!api_is_admin_master($pdo, $uid) && $itemCompany !== $cid) {
  api_error('E_FORBIDDEN', 'Item pertence a outra empresa.', 403);
}

$pdo->beginTransaction();
try {
  switch ($modulo) {
    case 'objetivo':
      $pdo->prepare("
        UPDATE objetivos
           SET status_aprovacao = ?, id_user_aprovador = ?, aprovador = ?,
               dt_aprovacao = NOW(), comentarios_aprovacao = ?
         WHERE id_objetivo = ?
      ")->execute([$decisao, $uid, $nomeAprovador, $comentarios ?: null, $idRef]);
      break;

    case 'kr':
      $pdo->prepare("
        UPDATE key_results
           SET status_aprovacao = ?, id_user_aprovador = ?, aprovador = ?,
               dt_aprovacao = NOW(), comentarios_aprovacao = ?
         WHERE id_kr = ?
      ")->execute([$decisao, $uid, $nomeAprovador, $comentarios ?: null, $idRef]);
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

  // Trilha de auditoria. Espelha o INSERT do fluxo web (auth/aprovacao_api.php):
  // dados_solicitados e id_user_solicitante são NOT NULL no schema — omiti-los
  // causava erro 500. tipo_operacao usa o verbo (approve/reject) como na web.
  $pdo->prepare("
    INSERT INTO fluxo_aprovacoes
      (tipo_estrutura, id_referencia, id_entidade, tipo_operacao, motivo_solicitacao,
       dados_solicitados, id_user_solicitante, status, id_user_aprovador, justificativa,
       contexto_origem, data_solicitacao, data_aprovacao, ip, user_agent)
    VALUES (?, ?, NULL, ?, NULL, '', ?, ?, ?, ?, 'aprovacao_api', NOW(), NOW(), ?, ?)
  ")->execute([
    $modulo,
    $idRef,
    $decisao === 'aprovado' ? 'approve' : 'reject',
    (string)$uid,                 // id_user_solicitante (NOT NULL)
    $decisao,                     // status
    (string)$uid,                 // id_user_aprovador
    $comentarios ?: null,         // justificativa
    $_SERVER['REMOTE_ADDR'] ?? '',
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
  ]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => "Item $decisao com sucesso."]);
