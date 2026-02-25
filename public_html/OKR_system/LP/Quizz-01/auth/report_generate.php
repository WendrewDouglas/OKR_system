<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();
$token = (string)($in['session_token'] ?? '');
if (!$token) fail('Token ausente');

$pdo = pdo();
$S = $pdo->prepare("SELECT s.id_sessao, s.id_lead, l.nome, l.email
                    FROM lp001_quiz_sessoes s
                    JOIN lp001_quiz_leads l ON l.id_lead=s.id_lead
                    WHERE s.session_token=? LIMIT 1");
$S->execute([$token]);
$ses = $S->fetch();
if (!$ses) fail('Sessão não encontrada', 404);

$Q = $pdo->prepare("SELECT score_total, classificacao_global, score_por_dominio, pdf_path, pdf_hash
                    FROM lp001_quiz_scores WHERE id_sessao=? LIMIT 1");
$Q->execute([(int)$ses['id_sessao']]);
$sc = $Q->fetch();
if (!$sc) fail('Finalize o quiz antes de gerar o PDF', 400);

$scoreTotal = (int)$sc['score_total'];
$scoreDom   = json_decode($sc['score_por_dominio'] ?? '{}', true) ?: [];

$hash = $sc['pdf_hash'] ?: md5($token . microtime(true));
$pdfDir = __DIR__ . '/../pdf';
@mkdir($pdfDir, 0775, true);
$fname = "report_{$hash}.pdf";
$fpath = $pdfDir . '/' . $fname;

// HTML do relatório simples
$rows = '';
foreach($scoreDom as $nm=>$v){ $rows .= "<tr><td>{$nm}</td><td style='text-align:right'>{$v}%</td></tr>"; }
$html = "
<html><head><meta charset='UTF-8'>
<style>
 body{font-family:Arial,Helvetica,sans-serif;color:#111;}
 h1{margin:0 0 6px 0;} .sem{padding:6px 10px;border-radius:8px;display:inline-block;color:#fff;}
 .verde{background:#1dd1a1;} .amarelo{background:#feca57;} .vermelho{background:#ff6b6b;}
 table{width:100%;border-collapse:collapse;margin-top:10px} td,th{border-bottom:1px solid #eee;padding:8px}
 footer{margin-top:16px;font-size:12px;color:#666}
</style></head><body>
<h1>Diagnóstico Executivo & OKRs</h1>
<div>Participante: <b>".htmlspecialchars($ses['nome']?:$ses['email'], ENT_QUOTES)."</b></div>
<div>Score global: <span class='sem {$sc['classificacao_global']}'>{$scoreTotal}%</span></div>
<h3>Score por domínio</h3>
<table><tbody>{$rows}</tbody></table>
<footer>Gerado em ".date('d/m/Y H:i')." – PlanningBI</footer>
</body></html>";

// Gera com Dompdf se existir
$ok = false;
if (class_exists(\Dompdf\Dompdf::class)) {
  try {
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    file_put_contents($fpath, $dompdf->output());
    $ok = is_file($fpath);
  } catch (\Throwable $e) { $ok = false; }
}
if (!$ok) fail('Dompdf não disponível no host. Instale dompdf/dompdf no OKR_system para gerar o PDF.', 500);

// grava caminho no BD (relativo à webroot)
$rel = '/LP/Quizz-01/pdf/' . $fname;
$upd = $pdo->prepare("UPDATE lp001_quiz_scores SET pdf_path=?, pdf_hash=?, pdf_gerado_dt=NOW() WHERE id_sessao=?");
$upd->execute([$rel, $hash, (int)$ses['id_sessao']]);

ok(['pdf_url_segura' => $rel, 'hash'=>$hash]);
