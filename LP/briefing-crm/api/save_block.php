<?php
declare(strict_types=1);

// =============================================================
// POST /api/save_block.php
// Salva (upsert) as respostas de UM bloco.
//  - valida CSRF / sessão / block_key / question_key (whitelist)
//  - obrigatórias precisam estar válidas; opcionais podem ir vazias
//    (gravam NULL, registrando que foram vistas)
//  - UNIQUE(session_id, question_key) => reenviar o bloco é UPDATE
//
// $draft = true salva sem exigir as obrigatórias (autosave).
//
// Responde: { ok:true, data:{ saved, current_block, completed_blocks } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bc_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = bc_input();

if (!bc_csrf_check($input['csrf'] ?? null)) {
    bc_fail('csrf_invalid', 419, 'A página ficou muito tempo aberta. Recarrega e tenta de novo.');
}

$token    = bc_str($input, 'session_token', 80);
$blockKey = bc_str($input, 'block_key', 80);
$answers  = $input['answers'] ?? [];
$draft    = !empty($input['draft']);

if ($token === '') {
    bc_fail('session_invalid', 400, 'Sessão inválida. Recarrega a página.');
}
if (!in_array($blockKey, bc_block_order(), true)) {
    bc_fail('block_invalid', 400, 'Bloco inválido.');
}
if (!is_array($answers)) {
    bc_fail('validation_error', 422, 'Formato de respostas inválido.');
}

$pdo  = bc_db();
$sess = bc_session_by_token($pdo, $token);

if ($sess === null) {
    bc_fail('session_invalid', 404, 'Não encontrei sua sessão. Recarrega a página.');
}
if ($sess['status'] === 'completed') {
    bc_fail('already_completed', 409, 'Esse briefing já foi enviado.');
}

// Indexa o recebido por question_key.
$received = [];
foreach ($answers as $a) {
    if (is_array($a) && isset($a['question_key'])) {
        $received[(string) $a['question_key']] = $a;
    }
}

$allQuestions = bc_questions();
$blockKeys    = bc_block_question_keys($blockKey);

$toStore     = [];
$fieldErrors = [];

foreach ($blockKeys as $qkey) {
    $question = $allQuestions[$qkey];
    $required = (bool) ($question['required'] ?? false);

    // Pergunta ausente no payload: erro se obrigatória (fora de rascunho).
    if (!array_key_exists($qkey, $received)) {
        if ($required && !$draft) {
            $fieldErrors[$qkey] = 'Essa não pode ficar em branco.';
        }
        continue;
    }

    $value = $received[$qkey]['value'] ?? null;

    // Em rascunho, obrigatória vazia não bloqueia — só não grava.
    if ($draft && $required) {
        $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
        if ($isEmpty) {
            continue;
        }
    }

    $res = bc_validate_answer($question, $value);
    if (!$res['ok']) {
        if (!$draft) {
            $fieldErrors[$qkey] = $res['error'] ?? 'Resposta inválida.';
        }
        continue;
    }
    $toStore[$qkey] = ['question' => $question, 'store' => $res['store']];
}

// question_keys fora da whitelist do bloco são ignoradas em silêncio.

if ($fieldErrors !== []) {
    bc_fail('validation_error', 422, 'Revisa o que ficou destacado.', $fieldErrors);
}

try {
    $pdo->beginTransaction();

    $upsert = $pdo->prepare(
        'INSERT INTO bc_answers
            (session_id, block_key, question_key, question_text,
             answer_type, answer_text, answer_number, answer_json, form_version, created_at)
         VALUES
            (:sid, :block, :qkey, :qtext,
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
            ':sid'   => (int) $sess['id'],
            ':block' => $blockKey,
            ':qkey'  => $qkey,
            ':qtext' => $q['question_text'],
            ':atype' => $q['answer_type'],
            ':atext' => $s['answer_text'],
            ':anum'  => $s['answer_number'],
            ':ajson' => $s['answer_json'],
            ':ver'   => BC_FORM_VERSION,
        ]);
        $saved++;
    }

    // Rascunho não move o ponteiro do bloco atual.
    if ($draft) {
        $pdo->prepare(
            'UPDATE bc_sessions
                SET status = IF(status = "started", "in_progress", status), updated_at = NOW()
              WHERE id = :id'
        )->execute([':id' => (int) $sess['id']]);
    } else {
        $next = bc_next_block($blockKey) ?? $blockKey;
        $pdo->prepare(
            'UPDATE bc_sessions
                SET status = IF(status = "completed", status, "in_progress"),
                    current_block = :block,
                    updated_at = NOW()
              WHERE id = :id'
        )->execute([':block' => $next, ':id' => (int) $sess['id']]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[BC] save_block falhou: ' . $e->getMessage());
    bc_fail('server_error', 500, 'Não consegui salvar agora. Tenta de novo — nada do que você escreveu foi perdido.');
}

$allAnswers = bc_answers_for_session($pdo, (int) $sess['id']);

bc_ok([
    'saved'            => $saved,
    'current_block'    => $draft ? $blockKey : (bc_next_block($blockKey) ?? $blockKey),
    'completed_blocks' => bc_completed_blocks($allAnswers),
    'is_last'          => bc_is_last_block($blockKey),
]);
