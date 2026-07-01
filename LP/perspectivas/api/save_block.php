<?php
declare(strict_types=1);

// =============================================================
// POST /api/save_block.php
// Salva (upsert) as respostas de UM bloco.
//  - valida CSRF / sessão / block_key / question_key (whitelist)
//  - exige TODAS as perguntas obrigatórias do bloco, válidas
//  - grava via UNIQUE(session_id, question_key) -> reenvio = update
//  - avança status para in_progress e current_block para o próximo bloco
// Responde: { ok:true, data:{ saved, current_block } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pg_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = pg_input();

if (!pg_csrf_check($input['csrf'] ?? null)) {
    pg_fail('csrf_invalid', 419, 'Sessão expirada. Recarregue a página e tente novamente.');
}

$token    = pg_str($input, 'session_token', 80);
$blockKey = pg_str($input, 'block_key', 80);
$answers  = $input['answers'] ?? [];

if ($token === '') {
    pg_fail('session_invalid', 400, 'Sessão inválida. Recarregue a página.');
}
if (!in_array($blockKey, pg_block_order(), true)) {
    pg_fail('block_invalid', 400, 'Bloco inválido.');
}
if (!is_array($answers)) {
    pg_fail('validation_error', 422, 'Formato de respostas inválido.');
}

$pdo = pg_db();

// Carrega a sessão pelo token.
$sess = pg_session_by_token($pdo, $token);
if ($sess === null) {
    pg_fail('session_invalid', 404, 'Sessão não encontrada. Recarregue a página.');
}
if ($sess['status'] === 'completed') {
    pg_fail('already_completed', 409, 'Este formulário já foi concluído.');
}

// Indexa as respostas recebidas por question_key.
$received = [];
foreach ($answers as $a) {
    if (is_array($a) && isset($a['question_key'])) {
        $received[(string) $a['question_key']] = $a;
    }
}

$allQuestions = pg_questions();
$blockKeys    = pg_block_question_keys($blockKey);

// Valida cada pergunta OBRIGATÓRIA do bloco (whitelist server-side).
$toStore    = [];
$fieldErrors = [];
foreach ($blockKeys as $qkey) {
    $question = $allQuestions[$qkey];
    if (!isset($received[$qkey])) {
        $fieldErrors[$qkey] = 'Resposta obrigatória.';
        continue;
    }
    $value = $received[$qkey]['value'] ?? null;
    $res = pg_validate_answer($question, $value);
    if (!$res['ok']) {
        $fieldErrors[$qkey] = $res['error'] ?? 'Resposta inválida.';
        continue;
    }
    $toStore[$qkey] = ['question' => $question, 'store' => $res['store']];
}

// Ignora silenciosamente question_keys desconhecidas/estranhas ao bloco
// (não são gravadas — apenas as da whitelist do bloco entram em $toStore).

if (!empty($fieldErrors)) {
    pg_fail('validation_error', 422, 'Revise as respostas destacadas.', $fieldErrors);
}

try {
    $pdo->beginTransaction();

    $upsert = $pdo->prepare(
        'INSERT INTO pg_form_answers
            (session_id, id_company, id_user, block_key, question_key, question_text,
             answer_type, answer_text, answer_number, answer_json, form_version, created_at)
         VALUES
            (:sid, :company, :uid, :block, :qkey, :qtext,
             :atype, :atext, :anum, :ajson, :ver, NOW())
         ON DUPLICATE KEY UPDATE
            block_key     = VALUES(block_key),
            question_text = VALUES(question_text),
            answer_type   = VALUES(answer_type),
            answer_text   = VALUES(answer_text),
            answer_number = VALUES(answer_number),
            answer_json   = VALUES(answer_json),
            form_version  = VALUES(form_version),
            updated_at    = NOW()'
    );

    $saved = 0;
    foreach ($toStore as $qkey => $item) {
        $q = $item['question'];
        $s = $item['store'];
        $upsert->execute([
            ':sid'     => (int) $sess['id'],
            ':company' => (int) $sess['id_company'],
            ':uid'     => $sess['id_user'] !== null ? (int) $sess['id_user'] : null,
            ':block'   => $blockKey,
            ':qkey'    => $qkey,
            ':qtext'   => $q['question_text'],
            ':atype'   => $q['answer_type'],
            ':atext'   => $s['answer_text'],
            ':anum'    => $s['answer_number'],
            ':ajson'   => $s['answer_json'],
            ':ver'     => PG_FORM_VERSION,
        ]);
        $saved++;
    }

    // Avança o ponteiro: current_block = próximo bloco (ou mantém se for o último).
    $next = pg_next_block($blockKey) ?? $blockKey;
    $pdo->prepare(
        'UPDATE pg_form_sessions
            SET status = IF(status = "completed", status, "in_progress"),
                current_block = :block,
                updated_at = NOW()
          WHERE id = :id'
    )->execute([':block' => $next, ':id' => (int) $sess['id']]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PG] save_block falhou: ' . $e->getMessage());
    pg_fail('server_error', 500, 'Não foi possível salvar agora. Tente novamente.');
}

pg_ok([
    'saved'         => $saved,
    'current_block' => pg_next_block($blockKey) ?? $blockKey,
]);
