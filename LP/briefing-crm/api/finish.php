<?php
declare(strict_types=1);

// =============================================================
// POST /api/finish.php
// Fecha o briefing:
//  - exige que todos os blocos estejam com as obrigatórias respondidas
//  - marca a sessão como completed (idempotente)
//  - dispara os dois e-mails: aviso para o dono + cópia para quem respondeu
//
// Os e-mails rodam DEPOIS do commit e nunca derrubam o fluxo: se o SMTP
// falhar, a resposta já está gravada e owner_notified_at fica NULL —
// dá para reenviar depois sem perder nada.
//
// Responde: { ok:true, data:{ status, nome, email_enviado } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bc_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = bc_input();

if (!bc_csrf_check($input['csrf'] ?? null)) {
    bc_fail('csrf_invalid', 419, 'A página ficou muito tempo aberta. Recarrega e tenta de novo.');
}

$token = bc_str($input, 'session_token', 80);
if ($token === '') {
    bc_fail('session_invalid', 400, 'Sessão inválida. Recarrega a página.');
}

$pdo  = bc_db();
$sess = bc_session_by_token($pdo, $token);

if ($sess === null) {
    bc_fail('session_invalid', 404, 'Não encontrei sua sessão. Recarrega a página.');
}

// Defensivo: barra flood de tentativas de conclusão.
if (!bc_rate_limit('finish:' . mb_strtolower((string) $sess['email_informado']), 15, 600)) {
    bc_fail('rate_limited', 429, 'Muitas tentativas seguidas. Espera um pouco.');
}

$answers = bc_answers_for_session($pdo, (int) $sess['id']);

// Já concluído: responde ok sem reenviar e-mail (idempotente — protege
// contra duplo clique e contra reload da página de conclusão).
if ($sess['status'] === 'completed') {
    bc_ok([
        'status'        => 'completed',
        'nome'          => $sess['nome_informado'],
        'email_enviado' => true,
        'ja_concluido'  => true,
    ]);
}

// Todos os blocos precisam ter as obrigatórias respondidas.
$done     = bc_completed_blocks($answers);
$faltando = array_values(array_diff(bc_block_order(), $done));

if ($faltando !== []) {
    $meta   = bc_block_meta();
    $titulo = $meta[$faltando[0]]['title'] ?? $faltando[0];
    bc_fail(
        'incomplete',
        422,
        'Ainda falta responder o bloco "' . $titulo . '".',
        ['_block' => $faltando[0]]
    );
}

try {
    $pdo->prepare(
        'UPDATE bc_sessions
            SET status = "completed", completed_at = NOW(), updated_at = NOW()
          WHERE id = :id AND status <> "completed"'
    )->execute([':id' => (int) $sess['id']]);
} catch (\Throwable $e) {
    error_log('[BC] finish falhou: ' . $e->getMessage());
    bc_fail('server_error', 500, 'Não consegui fechar o briefing agora. Tenta de novo.');
}

/* ---------------------------------------------------------------- */
/* E-mails — fora da transação, tolerantes a falha                   */
/* ---------------------------------------------------------------- */

$ownerOk = false;
try {
    $ownerOk = bc_send_owner_notification($sess, $answers);
    if ($ownerOk) {
        $pdo->prepare('UPDATE bc_sessions SET owner_notified_at = NOW() WHERE id = ?')
            ->execute([(int) $sess['id']]);
    }
} catch (\Throwable $e) {
    error_log('[BC] e-mail p/ dono falhou: ' . $e->getMessage());
}

try {
    if (bc_send_respondent_copy($sess, $answers)) {
        $pdo->prepare('UPDATE bc_sessions SET copy_sent_at = NOW() WHERE id = ?')
            ->execute([(int) $sess['id']]);
    }
} catch (\Throwable $e) {
    error_log('[BC] cópia p/ respondente falhou: ' . $e->getMessage());
}

if (!$ownerOk) {
    // As respostas estão salvas — só o aviso não saiu. Não alarma quem respondeu.
    error_log('[BC] ATENÇÃO: briefing ' . (int) $sess['id'] . ' concluído sem aviso por e-mail.');
}

bc_ok([
    'status'        => 'completed',
    'nome'          => $sess['nome_informado'],
    'email_enviado' => $ownerOk,
]);
