<?php
declare(strict_types=1);

// =============================================================
// Utilitário CLI do Briefing CRM.
//
//   php tools/briefing_crm_admin.php list
//        lista as sessões (quem abriu, status, progresso)
//
//   php tools/briefing_crm_admin.php show <id|token>
//        imprime o briefing completo de uma sessão
//
//   php tools/briefing_crm_admin.php delete <id|token>
//        apaga a sessão e suas respostas (ON DELETE CASCADE)
//
//   php tools/briefing_crm_admin.php resend <id|token>
//        reenvia o aviso por e-mail (útil se o SMTP falhou na hora)
//
//   php tools/briefing_crm_admin.php mailtest
//        mostra o roteamento de cada destino e manda uma mensagem de
//        teste para todos — sem precisar preencher o formulário
//
// Runner PHP/PDO de propósito: o cliente `mysql` da CLI não tem grant.
// =============================================================

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/LP/briefing-crm/includes/bootstrap.php';

$cmd = $argv[1] ?? 'list';
$ref = $argv[2] ?? '';
$pdo = bc_db();

/** Resolve id numérico OU session_token para a linha da sessão. */
function bc_admin_find(PDO $pdo, string $ref): ?array
{
    if ($ref === '') {
        return null;
    }
    $sql = ctype_digit($ref)
        ? 'SELECT * FROM bc_sessions WHERE id = ? LIMIT 1'
        : 'SELECT * FROM bc_sessions WHERE session_token = ? LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$ref]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

switch ($cmd) {

    case 'list':
        $rows = $pdo->query(
            'SELECT s.id, s.nome_informado, s.email_informado, s.escritorio, s.status,
                    s.created_at, s.completed_at, s.owner_notified_at,
                    (SELECT COUNT(*) FROM bc_answers a WHERE a.session_id = s.id) AS respostas
               FROM bc_sessions s
              ORDER BY s.id DESC
              LIMIT 50'
        )->fetchAll();

        if ($rows === []) {
            echo "Nenhuma sessão ainda.\n";
            break;
        }
        printf("%-4s %-24s %-12s %-5s %-17s %s\n", 'ID', 'NOME', 'STATUS', 'RESP', 'CRIADO', 'AVISADO');
        foreach ($rows as $r) {
            printf(
                "%-4d %-24s %-12s %-5d %-17s %s\n",
                $r['id'],
                mb_strimwidth((string) $r['nome_informado'], 0, 24, '…'),
                $r['status'],
                $r['respostas'],
                date('d/m/Y H:i', strtotime((string) $r['created_at'])),
                $r['owner_notified_at'] ? 'sim' : '—'
            );
        }
        break;

    case 'show':
        $sess = bc_admin_find($pdo, $ref);
        if ($sess === null) {
            fwrite(STDERR, "Sessão não encontrada: {$ref}\n");
            exit(1);
        }
        $answers = bc_answers_for_session($pdo, (int) $sess['id']);

        echo str_repeat('=', 64) . "\n";
        echo "{$sess['nome_informado']}  <{$sess['email_informado']}>\n";
        if ($sess['escritorio']) {
            echo "Escritório: {$sess['escritorio']}";
            echo $sess['papel'] ? "  ({$sess['papel']})\n" : "\n";
        }
        if ($sess['whatsapp_informado']) {
            echo "WhatsApp: {$sess['whatsapp_informado']}\n";
        }
        echo "Status: {$sess['status']}";
        echo $sess['completed_at'] ? "  concluído em " . date('d/m/Y H:i', strtotime((string) $sess['completed_at'])) . "\n" : "\n";
        echo str_repeat('=', 64) . "\n";

        foreach (bc_block_order() as $block) {
            $meta = bc_block_meta()[$block]['title'] ?? $block;
            echo "\n### " . mb_strtoupper($meta) . "\n";
            foreach (bc_block_question_keys($block) as $qkey) {
                $q = bc_questions()[$qkey];
                $v = $answers[$qkey] ?? null;
                if (is_array($v)) {
                    $v = implode(' · ', $v);
                }
                $v = is_string($v) ? trim($v) : '';
                echo "\n" . $q['question_text'] . "\n";
                echo '  ' . ($v === '' ? '(em branco)' : str_replace("\n", "\n  ", $v)) . "\n";
            }
        }
        echo "\n";
        break;

    case 'delete':
        $sess = bc_admin_find($pdo, $ref);
        if ($sess === null) {
            fwrite(STDERR, "Sessão não encontrada: {$ref}\n");
            exit(1);
        }
        // bc_answers tem FK ON DELETE CASCADE; bc_consents não tem FK.
        $pdo->prepare('DELETE FROM bc_consents WHERE session_id = ?')->execute([(int) $sess['id']]);
        $pdo->prepare('DELETE FROM bc_sessions WHERE id = ?')->execute([(int) $sess['id']]);
        echo "Apagada a sessão {$sess['id']} ({$sess['nome_informado']}).\n";
        break;

    case 'resend':
        $sess = bc_admin_find($pdo, $ref);
        if ($sess === null) {
            fwrite(STDERR, "Sessão não encontrada: {$ref}\n");
            exit(1);
        }
        $answers = bc_answers_for_session($pdo, (int) $sess['id']);
        $ok = bc_send_owner_notification($sess, $answers);
        if ($ok) {
            $pdo->prepare('UPDATE bc_sessions SET owner_notified_at = NOW() WHERE id = ?')
                ->execute([(int) $sess['id']]);
        }
        echo $ok ? "Aviso reenviado.\n" : "Falhou o reenvio — ver ERROR_LOG_PATH.\n";
        break;

    case 'mailtest':
        $recipients = bc_owner_recipients();
        echo "Destinos configurados: " . implode(', ', $recipients) . "\n\n";

        // Como o exim local roteia cada um. `deliver_local*` -> 127.0.0.1
        // significa que a mensagem é entregue no próprio servidor e nunca
        // chega ao provedor real do domínio.
        foreach ($recipients as $to) {
            $out = [];
            @exec('exim -bt ' . escapeshellarg($to) . ' 2>&1', $out);
            $rota = '';
            foreach ($out as $line) {
                if (stripos($line, 'router') !== false || stripos($line, 'undeliverable') !== false) {
                    $rota = trim($line);
                    break;
                }
            }
            $local = stripos($rota, 'deliver_local') !== false;
            printf("  %-38s %s\n", $to, $rota === '' ? '(sem info)' : $rota);
            if ($local) {
                echo '  ' . str_repeat(' ', 38) . "^^ ENTREGA LOCAL: não sai do servidor\n";
            } elseif (stripos($rota, 'undeliverable') !== false) {
                // `exim -bt` sem root não resolve destino remoto; para domínio
                // externo esse "undeliverable" é esperado e não significa nada.
                echo '  ' . str_repeat(' ', 38) . "^^ domínio externo: teste inconclusivo aqui, confira a caixa\n";
            }
        }

        echo "\nEnviando teste...\n";
        $html = bc_email_shell('Teste de entrega do Briefing CRM',
            '<h1 style="margin:0 0 10px;font:650 22px/1.2 Segoe UI,Arial,sans-serif;color:#101F28;">'
            . 'Teste de entrega</h1>'
            . '<p style="margin:0;font:400 15px/1.6 Georgia,serif;color:#344A54;">'
            . 'Se esta mensagem chegou, este endereço recebe o relatório do briefing. '
            . 'Enviada em ' . date('d/m/Y H:i:s') . '.</p>');

        foreach ($recipients as $to) {
            $ok = sendTransactionalMail($to, 'Teste de entrega — Briefing CRM', $html,
                BC_CONTACT_EMAIL, BC_OWNER_NAME);
            printf("  %-38s %s\n", $to, $ok ? 'aceito' : 'FALHOU');
        }
        echo "\nConfira as caixas (inclusive spam). 'aceito' só quer dizer que\n"
           . "o servidor recebeu a mensagem — não que ela foi entregue.\n";
        break;

    default:
        fwrite(STDERR, "Comando desconhecido: {$cmd}\nUse: list | show <ref> | delete <ref> | resend <ref> | mailtest\n");
        exit(1);
}
