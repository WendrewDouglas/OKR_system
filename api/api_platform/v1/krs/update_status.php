<?php
declare(strict_types=1);

/**
 * PUT /krs/:id/status
 * Altera o status do KR aplicando as regras compartilhadas (kr_status.php).
 * Body: { status, justificativa? }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();
api_load_helper('auth/helpers/kr_status.php');

$in = api_input();
api_require_fields($in, ['status']);
$novo          = krs_normalize_status(api_str($in['status']));
$justificativa = trim(api_str($in['justificativa'] ?? ''));

// Valida contra o domínio (422 limpo em vez do 500 do FK)
api_assert_domain($pdo, 'dom_status_kr', 'id_status', $novo, 'status');

// Tenant
$st = $pdo->prepare("
  SELECT kr.id_kr, o.id_company
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$kr = $st->fetch();
if (!$kr || (int)$kr['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

// Permissão
if (!api_has_cap($pdo, $uid, $cid, 'W:kr@ORG', ['id_kr' => $idKr])) {
  api_error('E_FORBIDDEN', 'Sem permissão para alterar o status deste KR.', 403);
}

// Regra: não pode "nao iniciado" se o 1º check-in já chegou
if ($novo === 'nao iniciado' && !krs_pode_nao_iniciado($pdo, $idKr)) {
  $fc = krs_primeiro_check($pdo, $idKr);
  api_error('E_INPUT', 'KR já iniciado (1º check-in em ' . $fc . '): não pode voltar para "Não Iniciado".', 422);
}

// Justificativa obrigatória p/ cancelar/pausar/concluir
if (krs_requer_justificativa($novo) && $justificativa === '') {
  api_error('E_INPUT', 'Justificativa obrigatória para este status.', 422);
}

if ($justificativa !== '') {
  $pdo->prepare("
    UPDATE key_results
       SET status = ?,
           observacoes = CONCAT(COALESCE(observacoes,''), '\n[', NOW(), '] Status → ', ?, ': ', ?),
           dt_ultima_atualizacao = NOW()
     WHERE id_kr = ?
  ")->execute([$novo, $novo, $justificativa, $idKr]);
} else {
  $pdo->prepare("
    UPDATE key_results
       SET status = ?, dt_ultima_atualizacao = NOW()
     WHERE id_kr = ?
  ")->execute([$novo, $idKr]);
}

api_json(['ok' => true, 'status' => $novo, 'message' => 'Status atualizado.']);
