<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();
$token = (string)($in['session_token'] ?? '');
if (!$token) fail('Token ausente');

$pdo = pdo();

// 1) Sessao + Lead
$S = $pdo->prepare("SELECT s.id_sessao, s.id_versao, s.id_lead, l.nome, l.email
                    FROM lp001_quiz_sessoes s
                    JOIN lp001_quiz_leads l ON l.id_lead=s.id_lead
                    WHERE s.session_token=? LIMIT 1");
$S->execute([$token]);
$ses = $S->fetch();
if (!$ses) fail('Sessao nao encontrada', 404);

$idSessao = (int)$ses['id_sessao'];
$idVersao = (int)$ses['id_versao'];

// 2) Scores
$Q = $pdo->prepare("SELECT score_total, classificacao_global, score_por_dominio, pdf_path, pdf_hash
                    FROM lp001_quiz_scores WHERE id_sessao=? LIMIT 1");
$Q->execute([$idSessao]);
$sc = $Q->fetch();
if (!$sc) fail('Finalize o quiz antes de gerar o PDF', 400);

$scoreTotal = (int)$sc['score_total'];
$scoreDom   = json_decode($sc['score_por_dominio'] ?? '{}', true) ?: [];

// 3) Todas as respostas do usuario com detalhes
$answersStmt = $pdo->prepare("
    SELECT
        r.id_pergunta, r.id_opcao, r.score_opcao,
        p.texto AS pergunta_texto, p.ordem AS pergunta_ordem,
        d.nome AS dominio_nome, d.id_dominio, d.ordem AS dominio_ordem,
        o.texto AS opcao_escolhida, o.explicacao, o.categoria_resposta
    FROM lp001_quiz_respostas r
    JOIN lp001_quiz_perguntas p ON p.id_pergunta = r.id_pergunta
    JOIN lp001_quiz_dominios d ON d.id_dominio = p.id_dominio
    JOIN lp001_quiz_opcoes o ON o.id_opcao = r.id_opcao
    WHERE r.id_sessao = ?
    ORDER BY d.ordem, p.ordem
");
$answersStmt->execute([$idSessao]);
$answers = $answersStmt->fetchAll();

// 4) Para respostas nao-corretas, buscar a opcao correta de cada pergunta
$correctOptions = [];
if ($answers) {
    $perguntaIds = array_unique(array_column($answers, 'id_pergunta'));
    $placeholders = implode(',', array_fill(0, count($perguntaIds), '?'));
    $corStmt = $pdo->prepare("
        SELECT id_pergunta, texto, explicacao
        FROM lp001_quiz_opcoes
        WHERE id_pergunta IN ($placeholders) AND categoria_resposta = 'correta'
    ");
    $corStmt->execute(array_values($perguntaIds));
    foreach ($corStmt->fetchAll() as $row) {
        $correctOptions[(int)$row['id_pergunta']] = $row;
    }
}

// 5) Agrupar respostas por dominio
$byDomain = [];
foreach ($answers as $a) {
    $domKey = $a['dominio_nome'];
    if (!isset($byDomain[$domKey])) {
        $byDomain[$domKey] = ['ordem' => $a['dominio_ordem'], 'items' => []];
    }
    $byDomain[$domKey]['items'][] = $a;
}
uasort($byDomain, fn($a, $b) => $a['ordem'] <=> $b['ordem']);

// 6) Hash e path do PDF
$hash = $sc['pdf_hash'] ?: md5($token . microtime(true));
$pdfDir = __DIR__ . '/../pdf';
@mkdir($pdfDir, 0775, true);
$fname = "report_{$hash}.pdf";
$fpath = $pdfDir . '/' . $fname;

// 7) Gerar HTML do relatorio
$participante = htmlspecialchars($ses['nome'] ?: $ses['email'], ENT_QUOTES, 'UTF-8');
$dataGeracao = date('d/m/Y H:i');

// Classificacao visual
$clsColor = '#ff6b6b';
$clsLabel = 'Vermelho (Risco Alto)';
if ($scoreTotal >= 70) { $clsColor = '#1dd1a1'; $clsLabel = 'Verde (Saudavel)'; }
elseif ($scoreTotal >= 40) { $clsColor = '#feca57'; $clsLabel = 'Amarelo (Moderado)'; }

// Score por dominio rows
$domRows = '';
foreach ($scoreDom as $nm => $v) {
    $barW = max(2, (int)$v);
    $barColor = $v >= 70 ? '#1dd1a1' : ($v >= 40 ? '#feca57' : '#ff6b6b');
    $domRows .= "<tr>
        <td style='padding:6px 8px;font-size:13px;'>".htmlspecialchars($nm, ENT_QUOTES)."</td>
        <td style='padding:6px 8px;text-align:right;font-weight:700;font-size:13px;'>{$v}%</td>
        <td style='padding:6px 8px;width:120px;'>
            <div style='background:#2a2a2a;border-radius:4px;height:10px;'>
                <div style='background:{$barColor};border-radius:4px;height:10px;width:{$barW}%;'></div>
            </div>
        </td>
    </tr>";
}

// Gabarito completo por dominio
$gabaritoHtml = '';
foreach ($byDomain as $domNome => $domData) {
    $domScore = $scoreDom[$domNome] ?? '—';
    $domScoreColor = (is_numeric($domScore) && $domScore >= 70) ? '#1dd1a1' : ((is_numeric($domScore) && $domScore >= 40) ? '#feca57' : '#ff6b6b');

    $gabaritoHtml .= "
    <div style='margin-top:20px;page-break-inside:avoid;'>
        <div style='background:#1a1f2e;padding:10px 14px;border-radius:8px 8px 0 0;border-left:4px solid {$domScoreColor};'>
            <span style='font-weight:800;font-size:15px;color:#e6edf3;'>".htmlspecialchars($domNome, ENT_QUOTES)."</span>
            <span style='float:right;font-weight:700;color:{$domScoreColor};font-size:14px;'>{$domScore}%</span>
        </div>";

    foreach ($domData['items'] as $idx => $a) {
        $num = $idx + 1;
        $cat = $a['categoria_resposta'];
        $isCorrect = ($cat === 'correta');
        $isQuase   = ($cat === 'quase_certa');

        // Cores e icones por categoria
        if ($isCorrect) {
            $bgColor = '#0d2818'; $borderColor = '#1dd1a1'; $icon = '&#10003;'; $catLabel = 'Correta';
        } elseif ($isQuase) {
            $bgColor = '#1a1a00'; $borderColor = '#feca57'; $icon = '&#9679;'; $catLabel = 'Quase certa';
        } elseif ($cat === 'razoavel') {
            $bgColor = '#1a1000'; $borderColor = '#f0932b'; $icon = '&#9679;'; $catLabel = 'Razoavel';
        } else {
            $bgColor = '#2a0a0a'; $borderColor = '#ff6b6b'; $icon = '&#10007;'; $catLabel = 'Menos correta';
        }

        $perguntaText = htmlspecialchars($a['pergunta_texto'], ENT_QUOTES, 'UTF-8');
        $escolhidaText = htmlspecialchars($a['opcao_escolhida'], ENT_QUOTES, 'UTF-8');
        $explicacaoText = htmlspecialchars($a['explicacao'] ?? '', ENT_QUOTES, 'UTF-8');

        $gabaritoHtml .= "
        <div style='background:{$bgColor};border-left:4px solid {$borderColor};padding:12px 14px;margin-top:1px;'>
            <div style='font-size:13px;color:#9aa4b2;margin-bottom:6px;'>
                <b style='color:#e6edf3;'>Pergunta {$num}</b>
                <span style='float:right;color:{$borderColor};font-weight:700;font-size:12px;'>{$icon} {$catLabel} ({$a['score_opcao']}/10)</span>
            </div>
            <div style='font-size:13px;color:#cbd5e1;margin-bottom:8px;'>{$perguntaText}</div>
            <div style='font-size:12px;padding:8px 10px;background:rgba(255,255,255,0.04);border-radius:6px;margin-bottom:6px;'>
                <span style='color:#9aa4b2;'>Sua resposta:</span><br>
                <span style='color:#e6edf3;'>{$escolhidaText}</span>
            </div>";

        // Explicacao da opcao escolhida
        if ($explicacaoText) {
            $gabaritoHtml .= "
            <div style='font-size:12px;color:#9aa4b2;padding:6px 10px;background:rgba(255,255,255,0.02);border-radius:6px;margin-bottom:6px;font-style:italic;'>
                {$explicacaoText}
            </div>";
        }

        // Se nao acertou, mostrar a resposta correta
        if (!$isCorrect) {
            $correct = $correctOptions[(int)$a['id_pergunta']] ?? null;
            if ($correct) {
                $correctText = htmlspecialchars($correct['texto'], ENT_QUOTES, 'UTF-8');
                $correctExpl = htmlspecialchars($correct['explicacao'] ?? '', ENT_QUOTES, 'UTF-8');
                $gabaritoHtml .= "
                <div style='font-size:12px;padding:8px 10px;background:#0d2818;border:1px solid rgba(29,209,161,0.3);border-radius:6px;'>
                    <span style='color:#1dd1a1;font-weight:700;'>&#10003; Resposta correta:</span><br>
                    <span style='color:#e6edf3;'>{$correctText}</span>";
                if ($correctExpl) {
                    $gabaritoHtml .= "<br><span style='color:#7ee8c7;font-style:italic;margin-top:4px;display:inline-block;'>{$correctExpl}</span>";
                }
                $gabaritoHtml .= "</div>";
            }
        }

        $gabaritoHtml .= "</div>";
    }

    $gabaritoHtml .= "</div>";
}

// Contadores de acertos
$totalQ = count($answers);
$acertos = count(array_filter($answers, fn($a) => $a['categoria_resposta'] === 'correta'));
$quase = count(array_filter($answers, fn($a) => $a['categoria_resposta'] === 'quase_certa'));
$erros = $totalQ - $acertos - $quase;

$html = <<<HTML
<html><head><meta charset="UTF-8">
<style>
  @page { margin: 20mm 15mm; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    color: #e6edf3;
    background: #0b0d10;
    font-size: 14px;
    line-height: 1.5;
  }
  h1 { margin: 0 0 4px 0; font-size: 22px; color: #d4af37; }
  h2 { margin: 20px 0 8px 0; font-size: 17px; color: #d4af37; border-bottom: 1px solid #222; padding-bottom: 6px; }
  .header-box {
    background: #141820;
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
  }
  .score-big {
    font-size: 42px;
    font-weight: 900;
    color: {$clsColor};
    line-height: 1;
  }
  .score-label {
    font-size: 14px;
    color: #9aa4b2;
    margin-left: 8px;
  }
  .stats-row {
    display: flex;
    gap: 12px;
    margin-top: 10px;
  }
  .stat-box {
    background: #1a1f2e;
    border-radius: 8px;
    padding: 8px 14px;
    text-align: center;
    flex: 1;
  }
  .stat-num { font-size: 20px; font-weight: 800; }
  .stat-lbl { font-size: 11px; color: #9aa4b2; }
  table { width: 100%; border-collapse: collapse; }
  tr { border-bottom: 1px solid #1a1f2e; }
  footer {
    margin-top: 24px;
    font-size: 11px;
    color: #555;
    text-align: center;
    border-top: 1px solid #222;
    padding-top: 8px;
  }
</style>
</head><body>

<div class="header-box">
  <h1>Diagnostico Executivo & OKRs</h1>
  <div style="color:#9aa4b2;font-size:13px;margin-bottom:10px;">
    Participante: <b style="color:#e6edf3;">{$participante}</b>
    &nbsp;&middot;&nbsp; Gerado em {$dataGeracao}
  </div>
  <div>
    <span class="score-big">{$scoreTotal}%</span>
    <span class="score-label">{$clsLabel}</span>
  </div>
  <div style="margin-top:12px;display:table;width:100%;">
    <div style="display:table-cell;width:33%;text-align:center;background:#0d2818;border-radius:8px;padding:8px;">
      <div style="font-size:20px;font-weight:800;color:#1dd1a1;">{$acertos}</div>
      <div style="font-size:11px;color:#9aa4b2;">Corretas</div>
    </div>
    <div style="display:table-cell;width:33%;text-align:center;background:#1a1a00;border-radius:8px;padding:8px;">
      <div style="font-size:20px;font-weight:800;color:#feca57;">{$quase}</div>
      <div style="font-size:11px;color:#9aa4b2;">Quase certas</div>
    </div>
    <div style="display:table-cell;width:33%;text-align:center;background:#2a0a0a;border-radius:8px;padding:8px;">
      <div style="font-size:20px;font-weight:800;color:#ff6b6b;">{$erros}</div>
      <div style="font-size:11px;color:#9aa4b2;">Erradas</div>
    </div>
  </div>
</div>

<h2>Score por Dominio</h2>
<table>{$domRows}</table>

<h2>Gabarito Completo</h2>
<div style="font-size:12px;color:#9aa4b2;margin-bottom:10px;">
  Todas as {$totalQ} perguntas com suas respostas, explicacoes e correcoes.
</div>
{$gabaritoHtml}

<footer>
  PlanningBI &middot; Diagnostico Executivo &amp; OKRs &middot; {$dataGeracao}
</footer>

</body></html>
HTML;

// 8) Gera PDF com Dompdf
$ok = false;
// Tenta autoload do composer
$autoloads = [
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloads as $a) {
    if (is_file($a)) { require_once $a; break; }
}

if (class_exists(\Dompdf\Dompdf::class)) {
    try {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($fpath, $dompdf->output());
        $ok = is_file($fpath);
    } catch (\Throwable $e) {
        error_log('[report_generate] Dompdf error: ' . $e->getMessage());
        $ok = false;
    }
}
if (!$ok) fail('Dompdf nao disponivel no host. Instale dompdf/dompdf para gerar o PDF.', 500);

// 9) Grava caminho no BD
$rel = '/OKR_system/LP/Quizz-01/pdf/' . $fname;
$upd = $pdo->prepare("UPDATE lp001_quiz_scores SET pdf_path=?, pdf_hash=?, pdf_gerado_dt=NOW() WHERE id_sessao=?");
$upd->execute([$rel, $hash, $idSessao]);

ok(['pdf_url_segura' => $rel, 'hash' => $hash]);
