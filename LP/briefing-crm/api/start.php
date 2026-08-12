<?php
declare(strict_types=1);

// =============================================================
// POST /api/start.php
//
// Dois modos:
//  1) resume  — recebe session_token e devolve a sessão + respostas
//               já gravadas (é o que permite fechar a aba e voltar).
//  2) novo    — valida identificação + consentimento, cria a sessão.
//
// Responde: { ok:true, data:{ session_token, current_block, nome,
//                             answers, completed_blocks, status } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bc_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = bc_input();

if (!bc_csrf_check($input['csrf'] ?? null)) {
    bc_fail('csrf_invalid', 419, 'A página ficou muito tempo aberta. Recarrega e tenta de novo.');
}

$pdo = bc_db();

/* ---------------------------------------------------------------- */
/* Modo 1 — retomar sessão existente                                */
/* ---------------------------------------------------------------- */

$resumeToken = bc_str($input, 'session_token', 80);
if ($resumeToken !== '') {
    $sess = bc_session_by_token($pdo, $resumeToken);
    if ($sess === null) {
        // Token inválido/expirado: não é erro, o front cai para o início.
        bc_ok(['session_token' => null]);
    }

    $answers = bc_answers_for_session($pdo, (int) $sess['id']);
    bc_ok([
        'session_token'    => $sess['session_token'],
        'current_block'    => $sess['current_block'] ?: bc_block_order()[0],
        'nome'             => $sess['nome_informado'],
        'status'           => $sess['status'],
        'answers'          => $answers,
        'completed_blocks' => bc_completed_blocks($answers),
    ]);
}

/* ---------------------------------------------------------------- */
/* Modo 2 — nova sessão                                             */
/* ---------------------------------------------------------------- */

// Honeypot: responde 200 fingindo sucesso para não ensinar o bot.
if (bc_honeypot_tripped($input)) {
    bc_ok(['session_token' => bc_generate_token(), 'current_block' => bc_block_order()[0], 'answers' => [], 'completed_blocks' => []]);
}

$ip = bc_client_ip();
if ($ip !== '' && !bc_rate_limit('start:' . $ip, 12, 3600)) {
    bc_fail('rate_limited', 429, 'Muitas tentativas. Espera alguns minutos e tenta de novo.');
}

$nome       = bc_normalize_name(bc_str($input, 'nome', 150));
$email      = mb_strtolower(bc_str($input, 'email', 150));
$whatsRaw   = bc_str($input, 'whatsapp', 40);
$escritorio = bc_str($input, 'escritorio', 150);
$papel      = bc_str($input, 'papel', 60);
$consent    = !empty($input['consent']);

$fieldErrors = [];
if ($nome === '' || mb_strlen($nome) < 2) {
    $fieldErrors['nome'] = 'Como você quer que eu te chame?';
}
if (!bc_valid_email($email)) {
    $fieldErrors['email'] = 'Confere esse e-mail — é para lá que vai sua cópia.';
}
$whatsapp = $whatsRaw === '' ? '' : bc_normalize_whatsapp($whatsRaw);
if ($whatsRaw !== '' && $whatsapp === '') {
    $fieldErrors['whatsapp'] = 'Número incompleto. Com DDD.';
}
if (!$consent) {
    $fieldErrors['consent'] = 'Preciso desse aceite para guardar suas respostas.';
}
if ($fieldErrors !== []) {
    bc_fail('validation_error', 422, 'Falta pouco — revisa os campos destacados.', $fieldErrors);
}

$papeisValidos = ['Sócia', 'Sócio', 'Assessor(a)', 'Gestor(a)', 'Outro'];
if ($papel !== '' && !in_array($papel, $papeisValidos, true)) {
    $papel = 'Outro';
}

$token       = bc_generate_token();
$firstBlock  = bc_block_order()[0];
$userAgent   = bc_user_agent();

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO bc_sessions
            (session_token, form_slug, form_version, nome_informado, email_informado,
             whatsapp_informado, escritorio, papel, status, current_block,
             consent, consent_version, started_at, ip_address, user_agent, created_at)
         VALUES
            (:token, :slug, :ver, :nome, :email,
             :wpp, :esc, :papel, "started", :block,
             1, :cver, NOW(), :ip, :ua, NOW())'
    )->execute([
        ':token' => $token,
        ':slug'  => BC_FORM_SLUG,
        ':ver'   => BC_FORM_VERSION,
        ':nome'  => $nome,
        ':email' => $email,
        ':wpp'   => $whatsapp !== '' ? $whatsapp : null,
        ':esc'   => $escritorio !== '' ? $escritorio : null,
        ':papel' => $papel !== '' ? $papel : null,
        ':block' => $firstBlock,
        ':cver'  => BC_CONSENT_VERSION,
        ':ip'    => $ip !== '' ? $ip : null,
        ':ua'    => $userAgent !== '' ? $userAgent : null,
    ]);

    $sessionId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO bc_consents
            (session_id, email, consent_text, consent_version, ip_address, user_agent, created_at)
         VALUES (:sid, :email, :text, :ver, :ip, :ua, NOW())'
    )->execute([
        ':sid'   => $sessionId,
        ':email' => $email,
        ':text'  => bc_consent_text(),
        ':ver'   => BC_CONSENT_VERSION,
        ':ip'    => $ip !== '' ? $ip : null,
        ':ua'    => $userAgent !== '' ? $userAgent : null,
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[BC] start falhou: ' . $e->getMessage());
    bc_fail('server_error', 500, 'Não consegui abrir o briefing agora. Tenta de novo em instantes.');
}

bc_ok([
    'session_token'    => $token,
    'current_block'    => $firstBlock,
    'nome'             => $nome,
    'status'           => 'started',
    'answers'          => [],
    'completed_blocks' => [],
]);
