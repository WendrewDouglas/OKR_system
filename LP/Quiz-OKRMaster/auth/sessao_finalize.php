<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$in = json_input();
$token = (string)($in['session_token'] ?? '');

$pdo = pdo();
// idempotente: aceita reprocessar sessao ja finalizada sem erro
$S = sessao_por_token($pdo, $token, false);
$idSessao = (int)$S['id_sessao'];
$idVersao = (int)$S['id_versao'];

// total de questoes da versao
$tot = $pdo->prepare("SELECT COUNT(*) FROM okrm_questoes WHERE id_versao=?");
$tot->execute([$idVersao]);
$total = (int)$tot->fetchColumn();

// respostas + bloco de cada questao
$r = $pdo->prepare("
  SELECT r.acertou, r.tempo_ms, q.id_bloco
    FROM okrm_respostas r
    JOIN okrm_questoes q ON q.id_questao = r.id_questao
   WHERE r.id_sessao=?
");
$r->execute([$idSessao]);
$resps = $r->fetchAll();

$acertos = 0; $tempoTotal = 0; $rapidas = 0;
$blkCnt = []; $blkOk = [];
foreach ($resps as $x) {
    $ok = (int)$x['acertou'];
    $acertos += $ok;
    $tempoTotal += (int)$x['tempo_ms'];
    if ((int)$x['tempo_ms'] < 8000) $rapidas++; // < 8s = respondida sem leitura provavel
    $b = (int)$x['id_bloco'];
    $blkCnt[$b] = ($blkCnt[$b] ?? 0) + 1;
    $blkOk[$b]  = ($blkOk[$b] ?? 0) + $ok;
}
$respondidas = count($resps);
$pct = $total > 0 ? (int)round($acertos / $total * 100) : 0;
$tempoMedio = $respondidas > 0 ? (int)round($tempoTotal / $respondidas) : 0;

// score por bloco (nome curto => %)
$bs = $pdo->prepare("SELECT id_bloco, nome_curto FROM okrm_blocos WHERE id_versao=? ORDER BY ordem");
$bs->execute([$idVersao]);
$scoreBloco = [];
foreach ($bs->fetchAll() as $b) {
    $id = (int)$b['id_bloco'];
    $cnt = $blkCnt[$id] ?? 0;
    $scoreBloco[$b['nome_curto']] = $cnt > 0 ? (int)round(($blkOk[$id] ?? 0) / $cnt * 100) : 0;
}

// faixa de resultado
$f = $pdo->prepare("SELECT id_faixa, rotulo, leitura, cor FROM okrm_faixas WHERE id_versao=? AND ? BETWEEN pct_min AND pct_max ORDER BY pct_min DESC LIMIT 1");
$f->execute([$idVersao, $pct]);
$faixa = $f->fetch();

$scoreJson = json_encode($scoreBloco, JSON_UNESCAPED_UNICODE);
$pdo->prepare("
  INSERT INTO okrm_resultados (id_sessao, acertos, total, percentual, id_faixa, score_por_bloco, tempo_total_ms, tempo_medio_ms, qtd_rapidas, dt_calculo)
  VALUES (?,?,?,?,?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE acertos=VALUES(acertos), total=VALUES(total), percentual=VALUES(percentual),
                          id_faixa=VALUES(id_faixa), score_por_bloco=VALUES(score_por_bloco),
                          tempo_total_ms=VALUES(tempo_total_ms), tempo_medio_ms=VALUES(tempo_medio_ms),
                          qtd_rapidas=VALUES(qtd_rapidas), dt_calculo=NOW()
")->execute([
    $idSessao, $acertos, $total, $pct, $faixa['id_faixa'] ?? null,
    $scoreJson, $tempoTotal, $tempoMedio, $rapidas
]);

// marca sessao finalizada (so na primeira vez) e dispara e-mail ao instrutor
$primeiraVez = false;
if ($S['status'] !== 'finalizada') {
    $pdo->prepare("UPDATE okrm_sessoes SET status='finalizada', dt_fim=NOW() WHERE id_sessao=?")->execute([$idSessao]);
    $primeiraVez = true;
}

if ($primeiraVez) {
    // notificacao ao instrutor (nao bloqueia a resposta se falhar)
    try {
        $mailer = dirname(__DIR__, 3) . '/auth/mailer.php';
        if (is_file($mailer)) {
            require_once $mailer;
            if (function_exists('send_email')) {
                $destino = defined('OKRM_INSTRUTOR_EMAIL') ? OKRM_INSTRUTOR_EMAIL
                         : (defined('SMTP_FROM') ? SMTP_FROM : '');
                if ($destino) {
                    $nome  = htmlspecialchars($S['aluno_nome']);
                    $email = htmlspecialchars($S['aluno_email']);
                    $mm = ($tempoMedio/1000);
                    $subj = "OKR Master · {$S['aluno_nome']} concluiu a avaliação do Módulo 1 ({$acertos}/{$total})";
                    $html = "<div style='font-family:system-ui,Arial,sans-serif;color:#1a1a1a'>"
                          . "<h2 style='margin:0 0 8px'>Avaliação concluída — Módulo 1 (BSC)</h2>"
                          . "<table style='border-collapse:collapse;font-size:14px'>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>Aluno</td><td><b>{$nome}</b></td></tr>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>E-mail</td><td>{$email}</td></tr>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>Data da aula</td><td>" . htmlspecialchars((string)$S['data_aula']) . "</td></tr>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>Resultado</td><td><b>{$acertos}/{$total}</b> ({$pct}%) — " . htmlspecialchars((string)($faixa['rotulo'] ?? '')) . "</td></tr>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>Tempo médio/questão</td><td>" . number_format($mm,1,',','.') . "s</td></tr>"
                          . "<tr><td style='padding:4px 12px 4px 0;color:#666'>Respostas &lt; 8s</td><td>{$rapidas}</td></tr>"
                          . "</table>"
                          . "<p style='margin:14px 0 0;font-size:13px;color:#888'>Dar continuidade ao processo de formação OKR Master.</p></div>";
                    @send_email($destino, $subj, $html);
                }
            }
        }
    } catch (Throwable $e) { /* silencioso: nunca quebrar a finalizacao */ }
}

ok(['ok' => true]);
