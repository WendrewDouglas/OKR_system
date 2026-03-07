<?php
/**
 * chat_context_builder.php
 * Builds a rich system prompt with real database data for the AI chat.
 * Called by chat_api.php to provide contextual awareness.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/chat_system_docs.php';

/**
 * Build a context-rich system prompt with real user/company data.
 * Each section is size-limited to keep total context ~2000-3000 tokens.
 *
 * @param int $userId    The authenticated user's ID
 * @param int $companyId The user's company ID
 * @return string        The assembled system prompt
 */
function chat_build_context(int $userId, int $companyId): string {
    $pdo = db();
    $sections = [];

    // --- 0. Documentação do sistema (estática) ---
    $sections[] = chat_get_system_docs();

    // --- 1. Company info ---
    try {
        $st = $pdo->prepare("
            SELECT organizacao, missao, visao
            FROM company
            WHERE id_company = :c
            LIMIT 1
        ");
        $st->execute([':c' => $companyId]);
        $company = $st->fetch();
        if ($company) {
            $s = "## Empresa\n";
            $s .= "- Nome: " . ($company['organizacao'] ?? '—') . "\n";
            if (!empty($company['missao'])) $s .= "- Missão: " . mb_substr($company['missao'], 0, 200) . "\n";
            if (!empty($company['visao']))  $s .= "- Visão: " . mb_substr($company['visao'], 0, 200) . "\n";
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 2. Active Objectives ---
    try {
        $st = $pdo->prepare("
            SELECT o.id_objetivo, o.descricao, o.pilar_bsc, o.status_aprovacao,
                   o.dt_prazo, o.tipo_ciclo, o.ciclo
            FROM objetivos o
            WHERE o.id_company = :c
            ORDER BY o.dt_prazo ASC
            LIMIT 20
        ");
        $st->execute([':c' => $companyId]);
        $objs = $st->fetchAll();
        if ($objs) {
            $s = "## Objetivos ativos (" . count($objs) . ")\n";
            foreach ($objs as $o) {
                $status = $o['status_aprovacao'] ?? '—';
                $prazo  = $o['dt_prazo'] ?? '—';
                $pilar  = $o['pilar_bsc'] ?? '';
                $s .= "- [#{$o['id_objetivo']}] " . mb_substr($o['descricao'], 0, 120)
                     . " | Pilar: {$pilar} | Status: {$status} | Prazo: {$prazo}\n";
            }
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 3. Key Results with progress ---
    try {
        $st = $pdo->prepare("
            SELECT kr.id_kr, kr.descricao, kr.baseline, kr.meta, kr.status,
                   kr.id_objetivo,
                   (SELECT ROUND(
                       CASE
                         WHEN kr.meta = kr.baseline THEN 0
                         ELSE LEAST(100, GREATEST(0,
                           ((COALESCE(m.valor_real_consolidado, kr.baseline) - kr.baseline)
                            / (kr.meta - kr.baseline)) * 100
                         ))
                       END
                   ) FROM milestones_kr m
                    WHERE m.id_kr = kr.id_kr
                    ORDER BY m.data_ref DESC LIMIT 1) AS pct_progresso
            FROM key_results kr
            JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
            WHERE o.id_company = :c
            ORDER BY kr.id_kr DESC
            LIMIT 30
        ");
        $st->execute([':c' => $companyId]);
        $krs = $st->fetchAll();
        if ($krs) {
            $s = "## Key Results (" . count($krs) . ")\n";
            foreach (array_slice($krs, 0, 15) as $kr) {
                $pct = $kr['pct_progresso'] !== null ? round((float)$kr['pct_progresso']) . '%' : '—';
                $s .= "- [KR#{$kr['id_kr']}] " . mb_substr($kr['descricao'], 0, 100)
                     . " | Progresso: {$pct} | Meta: {$kr['meta']} | Baseline: {$kr['baseline']}"
                     . " | Status: " . ($kr['status'] ?? '—') . "\n";
            }
            if (count($krs) > 15) {
                $s .= "- ... e mais " . (count($krs) - 15) . " KRs\n";
            }
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 4. Pending approvals ---
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM aprovacao_movimentos
            WHERE status = 'pendente'
              AND id_user_aprovador = :u
        ");
        $st->execute([':u' => $userId]);
        $pending = (int)($st->fetchColumn() ?: 0);

        $st2 = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM aprovacao_movimentos
            WHERE status = 'pendente'
              AND id_user_criador = :u
        ");
        $st2->execute([':u' => $userId]);
        $myPending = (int)($st2->fetchColumn() ?: 0);

        if ($pending > 0 || $myPending > 0) {
            $s = "## Aprovações\n";
            if ($pending > 0)   $s .= "- {$pending} item(ns) aguardando SUA aprovação\n";
            if ($myPending > 0) $s .= "- {$myPending} item(ns) que VOCÊ submeteu estão pendentes\n";
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 5. Budget summary ---
    try {
        $st = $pdo->prepare("
            SELECT
              COALESCE(SUM(orc.valor_orcado), 0) AS total_orcado,
              COALESCE(SUM(CASE WHEN orc.status_aprovacao = 'aprovado' THEN orc.valor_orcado ELSE 0 END), 0) AS total_aprovado
            FROM orcamentos orc
            JOIN iniciativas ini ON ini.id_iniciativa = orc.id_iniciativa
            JOIN key_results kr ON kr.id_kr = ini.id_kr
            JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
            WHERE o.id_company = :c
        ");
        $st->execute([':c' => $companyId]);
        $budget = $st->fetch();
        if ($budget && ((float)$budget['total_orcado'] > 0)) {
            $fmt = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
            $s = "## Orçamento\n";
            $s .= "- Total orçado: " . $fmt($budget['total_orcado']) . "\n";
            $s .= "- Total aprovado: " . $fmt($budget['total_aprovado']) . "\n";
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 6. Recent unread notifications ---
    try {
        $st = $pdo->prepare("
            SELECT titulo, mensagem, created_at
            FROM notificacoes
            WHERE id_user = :u AND lida = 0
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $st->execute([':u' => $userId]);
        $notifs = $st->fetchAll();
        if ($notifs) {
            $s = "## Notificações não lidas (" . count($notifs) . ")\n";
            foreach ($notifs as $n) {
                $s .= "- " . mb_substr($n['titulo'] ?? $n['mensagem'] ?? '—', 0, 100)
                     . " (" . ($n['created_at'] ?? '') . ")\n";
            }
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- 7. Team members ---
    try {
        $st = $pdo->prepare("
            SELECT primeiro_nome, ultimo_nome
            FROM usuarios
            WHERE id_company = :c
            ORDER BY primeiro_nome
            LIMIT 20
        ");
        $st->execute([':c' => $companyId]);
        $team = $st->fetchAll();
        if ($team) {
            $names = array_map(fn($u) => trim(($u['primeiro_nome'] ?? '') . ' ' . ($u['ultimo_nome'] ?? '')), $team);
            $s = "## Equipe (" . count($team) . " membros)\n";
            $s .= implode(', ', $names) . "\n";
            $sections[] = $s;
        }
    } catch (\Throwable $e) {
        // silently skip
    }

    // --- Get current user name ---
    $userName = 'Usuário';
    try {
        $st = $pdo->prepare("SELECT primeiro_nome FROM usuarios WHERE id_user = :u LIMIT 1");
        $st->execute([':u' => $userId]);
        $userName = $st->fetchColumn() ?: 'Usuário';
    } catch (\Throwable $e) {
        // keep default
    }

    // --- Assemble system prompt ---
    $base = <<<PROMPT
Você é o **OKR Master**, assistente de IA especialista em OKRs e gestão estratégica.
Você está integrado ao sistema OKR da empresa e tem acesso aos dados reais abaixo.
O usuário que está conversando com você se chama **{$userName}**.

Diretrizes:
- Responda em português do Brasil, de forma clara e objetiva.
- Quando o usuário perguntar sobre objetivos, KRs, aprovações ou dados da empresa, USE os dados reais abaixo.
- Quando o usuário perguntar como usar o sistema, onde encontrar uma função ou como executar uma ação, USE a seção "Como usar o sistema OKR" abaixo.
- Cite IDs e nomes específicos dos objetivos/KRs quando relevante.
- Se não souber algo que não está nos dados, diga que não tem essa informação no momento.
- Ajude com boas práticas de OKR, análise de progresso, sugestões de melhoria.
- Seja conciso mas completo. Use bullet points quando apropriado.
- Não invente dados que não estão listados abaixo.

PROMPT;

    $context = implode("\n", $sections);

    // Trim context if too large (~4500 tokens ≈ 15000 chars)
    if (mb_strlen($context) > 15000) {
        $context = mb_substr($context, 0, 15000) . "\n... (dados truncados por limite de contexto)";
    }

    return $base . "\n# Dados atuais do sistema\n\n" . $context;
}
