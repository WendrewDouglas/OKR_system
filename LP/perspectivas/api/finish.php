<?php
declare(strict_types=1);

// =============================================================
// POST /api/finish.php
// Conclui o formulário:
//  - valida CSRF / sessão
//  - rate limit por e-mail (evita flood de tentativas de conclusão)
//  - verifica NO SERVIDOR se TODAS as 20 perguntas obrigatórias existem
//    e estão gravadas para a sessão
//  - marca status=completed, completed_at=NOW()
// Responde: { ok:true, data:{ completed:true } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pg_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = pg_input();

if (!pg_csrf_check($input['csrf'] ?? null)) {
    pg_fail('csrf_invalid', 419, 'Sessão expirada. Recarregue a página e tente novamente.');
}

$token = pg_str($input, 'session_token', 80);
if ($token === '') {
    pg_fail('session_invalid', 400, 'Sessão inválida. Recarregue a página.');
}

$pdo  = pg_db();
$sess = pg_session_by_token($pdo, $token);
if ($sess === null) {
    pg_fail('session_invalid', 404, 'Sessão não encontrada. Recarregue a página.');
}

// Rate limit por e-mail (defensivo — barra tentativas repetidas de finish).
if (!pg_rate_limit('finish:' . strtolower((string) $sess['email_informado']), 15, 600)) {
    pg_fail('rate_limited', 429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
}

// Idempotência: se já concluído, retorna sucesso sem regravar.
if ($sess['status'] === 'completed') {
    pg_ok(['completed' => true]);
}

// Verificação server-side de completude: TODAS as perguntas obrigatórias
// precisam ter resposta gravada para esta sessão.
$required = [];
foreach (pg_questions() as $qkey => $q) {
    if (!empty($q['required'])) {
        $required[] = $qkey;
    }
}

$stmt = $pdo->prepare('SELECT question_key FROM pg_form_answers WHERE session_id = :sid');
$stmt->execute([':sid' => (int) $sess['id']]);
$answered = array_column($stmt->fetchAll(), 'question_key');

$missing = array_values(array_diff($required, $answered));
if (!empty($missing)) {
    // Agrupa faltantes por bloco para a UX conseguir levar o usuário de volta.
    $byBlock = [];
    $all = pg_questions();
    foreach ($missing as $qkey) {
        $blk = $all[$qkey]['block_key'] ?? 'desconhecido';
        $byBlock[$blk][] = $qkey;
    }
    pg_fail('incomplete', 422, 'Ainda faltam perguntas obrigatórias.', [
        'missing'          => $missing,
        'missing_by_block' => $byBlock,
    ]);
}

try {
    $pdo->prepare(
        'UPDATE pg_form_sessions
            SET status = "completed", completed_at = NOW(), updated_at = NOW()
          WHERE id = :id AND status <> "completed"'
    )->execute([':id' => (int) $sess['id']]);
} catch (\Throwable $e) {
    error_log('[PG] finish falhou: ' . $e->getMessage());
    pg_fail('server_error', 500, 'Não foi possível concluir agora. Tente novamente.');
}

pg_ok(['completed' => true]);
