<?php
declare(strict_types=1);

// =============================================================
// POST /api/start.php
// Inicia (ou recupera) a sessão do formulário para o respondente:
//  - valida CSRF / honeypot / rate limit / identificação + consentimento
//  - faz upsert do usuário em `usuarios` (id_company=1, empresa=FMX)
//  - cria ou recupera uma sessão aberta (started/in_progress) do mesmo e-mail
//  - grava prova de consentimento em pg_consents
// Responde: { ok:true, data:{ session_token, current_block } }
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pg_fail('method_not_allowed', 405, 'Método não permitido.');
}

$input = pg_input();

/* --- Anti-abuso ------------------------------------------------------ */
if (!pg_csrf_check($input['csrf'] ?? null)) {
    pg_fail('csrf_invalid', 419, 'Sessão expirada. Recarregue a página e tente novamente.');
}
if (pg_honeypot_tripped($input)) {
    // Resposta de sucesso silenciosa: não dá pistas ao bot, mas não persiste nada.
    pg_ok(['session_token' => null, 'current_block' => null, 'silent' => true]);
}
if (!pg_rate_limit('start:' . pg_client_ip(), 8, 600)) {
    pg_fail('rate_limited', 429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
}

/* --- Validação da identificação ------------------------------------- */
$nome     = pg_normalize_name((string) ($input['nome'] ?? ''));
$email    = strtolower(pg_str($input, 'email', 150));
$wpRaw    = pg_str($input, 'whatsapp', 40);
$consent  = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

$fields = [];
if (mb_strlen($nome) < 2) {
    $fields['nome'] = 'Informe seu nome completo.';
}
if (!pg_valid_email($email)) {
    $fields['email'] = 'Informe um e-mail válido.';
}
$whatsapp = pg_normalize_whatsapp($wpRaw);
if ($whatsapp === '') {
    $fields['whatsapp'] = 'Informe um WhatsApp válido com DDD.';
}
if (!$consent) {
    $fields['consent'] = 'É necessário aceitar o termo de consentimento para continuar.';
}

if (!empty($fields)) {
    pg_fail('validation_error', 422, 'Revise os campos destacados.', $fields);
}

/* --- Persistência ---------------------------------------------------- */
$pdo = pg_db();

try {
    $pdo->beginTransaction();

    // Upsert do usuário (conservador; nunca cria senha/RBAC/permissão).
    $idUser = pg_upsert_user($pdo, ['nome' => $nome, 'email' => $email, 'whatsapp' => $whatsapp]);

    // Recupera sessão aberta (não concluída) do mesmo e-mail + formulário/versão,
    // para permitir retomar sem duplicar. Só reaproveita as não concluídas.
    $find = $pdo->prepare(
        'SELECT id, session_token, current_block
           FROM pg_form_sessions
          WHERE email_informado = :email
            AND form_slug = :slug
            AND form_version = :ver
            AND status IN ("started","in_progress")
          ORDER BY id DESC
          LIMIT 1'
    );
    $find->execute([':email' => $email, ':slug' => PG_FORM_SLUG, ':ver' => PG_FORM_VERSION]);
    $open = $find->fetch();

    $firstBlock = pg_block_order()[0];

    if ($open !== false) {
        $sessionToken = (string) $open['session_token'];
        $currentBlock = $open['current_block'] ?: $firstBlock;
        // Mantém vínculo id_user atualizado caso tenha acabado de ser criado.
        $pdo->prepare('UPDATE pg_form_sessions SET id_user = :uid, updated_at = NOW() WHERE id = :id')
            ->execute([':uid' => $idUser, ':id' => (int) $open['id']]);
        $sessionId = (int) $open['id'];
    } else {
        $sessionToken = pg_generate_token();
        $currentBlock = $firstBlock;
        $ins = $pdo->prepare(
            'INSERT INTO pg_form_sessions
                (session_token, id_company, id_user, respondent_role, nome_informado,
                 email_informado, whatsapp_informado, form_slug, form_version, status,
                 current_block, consent, consent_version, started_at, ip_address, user_agent)
             VALUES
                (:token, :company, :uid, :role, :nome,
                 :email, :whats, :slug, :ver, "started",
                 :block, 1, :cver, NOW(), :ip, :ua)'
        );
        $ins->execute([
            ':token'   => $sessionToken,
            ':company' => PG_FMX_COMPANY_ID,
            ':uid'     => $idUser,
            ':role'    => 'gestor',
            ':nome'    => $nome,
            ':email'   => $email,
            ':whats'   => $whatsapp,
            ':slug'    => PG_FORM_SLUG,
            ':ver'     => PG_FORM_VERSION,
            ':block'   => $currentBlock,
            ':cver'    => PG_CONSENT_VERSION,
            ':ip'      => pg_client_ip(),
            ':ua'      => pg_user_agent(),
        ]);
        $sessionId = (int) $pdo->lastInsertId();
    }

    // Prova de consentimento (append-only): registra este aceite.
    $pdo->prepare(
        'INSERT INTO pg_consents
            (session_id, id_company, id_user, email, consent_text, consent_version, ip_address, user_agent)
         VALUES (:sid, :company, :uid, :email, :ctext, :cver, :ip, :ua)'
    )->execute([
        ':sid'     => $sessionId,
        ':company' => PG_FMX_COMPANY_ID,
        ':uid'     => $idUser,
        ':email'   => $email,
        ':ctext'   => pg_consent_text(),
        ':cver'    => PG_CONSENT_VERSION,
        ':ip'      => pg_client_ip(),
        ':ua'      => pg_user_agent(),
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PG] start falhou: ' . $e->getMessage());
    pg_fail('server_error', 500, 'Não foi possível iniciar agora. Tente novamente em instantes.');
}

pg_ok([
    'session_token' => $sessionToken,
    'current_block' => $currentBlock,
]);
