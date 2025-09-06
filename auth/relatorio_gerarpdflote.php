<?php declare(strict_types=1);

if (isset($_GET['debug'])) { ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  http_response_code(500); die('Erro de conexão: '.$e->getMessage());
}

function fmtDate(?string $s, string $fmt='d/m/Y'): string {
  if (!$s) return '—'; $ts=strtotime($s); return $ts?date($fmt,$ts):$s;
}
function nomeUsuario(PDO $pdo, int $id): string {
  if ($id<=0) return '—';
  $st=$pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user=:id LIMIT 1");
  $st->execute(['id'=>$id]); $r=$st->fetch();
  $pn=trim((string)($r['primeiro_nome']??'')); $ln=trim((string)($r['ultimo_nome']??'')); return trim($pn.' '.$ln) ?: ($pn ?: '—');
}
function progressoObjetivo(PDO $pdo, int $id): float {
  $st = $pdo->prepare("
    SELECT AVG(p.progresso) FROM (
      SELECT CASE WHEN (COALESCE(kr.meta,0)-COALESCE(kr.baseline,0))<>0
        THEN ROUND(((COALESCE(
                (SELECT mk.valor_real FROM milestones_kr mk WHERE mk.id_kr=kr.id_kr AND mk.valor_real IS NOT NULL
                 ORDER BY COALESCE(mk.dt_apontamento, mk.dt_evidencia, mk.data_ref) DESC, mk.num_ordem DESC LIMIT 1),
                kr.baseline
              ) - kr.baseline)/(kr.meta-kr.baseline))*100,1)
        ELSE 0 END AS progresso
      FROM key_results kr WHERE kr.id_objetivo=:id
    ) p
  "); $st->execute(['id'=>$id]); $v=(float)($st->fetchColumn() ?: 0);
  return max(0,min(100,$v));
}
function kpis(PDO $pdo, int $id): array {
  $tIni=(int)($pdo->query("SELECT COUNT(*) FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo={$id}")->fetchColumn() ?: 0);
  $comOrc=(int)($pdo->query("SELECT COUNT(DISTINCT i.id_iniciativa) FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr INNER JOIN orcamentos o ON o.id_iniciativa=i.id_iniciativa WHERE kr.id_objetivo={$id}")->fetchColumn() ?: 0);
  $aprov=(float)($pdo->query("SELECT COALESCE(SUM(o.valor),0) FROM orcamentos o INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo={$id}")->fetchColumn() ?: 0);
  $real =(float)($pdo->query("SELECT COALESCE(SUM(od.valor),0) FROM orcamentos_detalhes od INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo={$id}")->fetchColumn() ?: 0);
  return ['tIni'=>$tIni,'comOrc'=>$comOrc,'aprov'=>$aprov,'real'=>$real,'saldo'=>max(0,$aprov-$real)];
}

/* ===== Entrada ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); echo 'Requisição inválida.'; exit; }

$raw = $_POST['objetivos'] ?? [];
if (!is_array($raw)) $raw = array_filter(array_map('trim', explode(',', (string)$raw)));
$ids = array_values(array_unique(array_map('intval', $raw)));
if (!$ids) { http_response_code(400); echo 'Nenhum objetivo informado.'; exit; }

$meses = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$mes = $meses[(int)date('n')] ?? ''; $ano = date('Y');

$css = '<style>@page{ size:A4 portrait; margin:14mm 12mm; } body{ font-family:Arial, Helvetica, sans-serif; color:#111; font-size:12px; } h1,h2,h3{ margin:0 0 6px; } .title{ font-weight:900; color:#0b3b75; font-size:16px; } .sub{ color:#444; } .kpi{ display:inline-block; border:1px solid #d9dee7; border-radius:8px; padding:8px 10px; margin:4px 6px 0 0; } .bar{ width:220px; background:#e6edf6; border-radius:8px; overflow:hidden; height:18px; display:inline-flex; align-items:center; } .in{ background:#38b36b; color:#fff; height:100%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:11px; } .section{ margin-top:10px; } .page-break{ page-break-after:always; }</style>';

$sections = [];
foreach ($ids as $id){
  $st = $pdo->prepare('SELECT * FROM objetivos WHERE id_objetivo=:id'); $st->execute(['id'=>$id]); $o = $st->fetch();
  if (!$o) { $sections[] = "<div><h2>Objetivo ".htmlspecialchars((string)$id)."</h2><div>Não encontrado.</div></div><div class='page-break'></div>"; continue; }
  $prog = progressoObjetivo($pdo,$id);
  $kpi  = kpis($pdo,$id);
  $dono = nomeUsuario($pdo, (int)($o['dono'] ?? 0));

  ob_start(); ?>
  <h2 class="title">Relatório de Objetivo <?= htmlspecialchars((string)$id) ?></h2>
  <div class="sub"><strong><?= htmlspecialchars((string)$o['descricao']) ?></strong><br>Responsável: <?= htmlspecialchars($dono) ?><br>Resultados até <?= htmlspecialchars($mes) ?>/<?= htmlspecialchars($ano) ?></div>
  <div class="section">
    <div class="kpi"><strong>Iniciativas:</strong> <?= (int)$kpi['tIni'] ?> (com orçamento: <?= (int)$kpi['comOrc'] ?>)</div>
    <div class="kpi"><strong>Aprovado:</strong> R$ <?= number_format($kpi['aprov'],2,',','.') ?></div>
    <div class="kpi"><strong>Realizado:</strong> R$ <?= number_format($kpi['real'],2,',','.') ?></div>
    <div class="kpi"><strong>Saldo:</strong> R$ <?= number_format($kpi['saldo'],2,',','.') ?></div>
    <div style="margin-top:8px"><div class="bar"><div class="in" style="width: <?= max(0,min(100,$prog)) ?>%"><?= number_format($prog,1,',','.') ?>%</div></div></div>
  </div>
  <?php
  $sections[] = ob_get_clean();
  $sections[] = '<div class="page-break"></div>';
}

$html = $css . implode('', $sections);

if (!class_exists(Dompdf::class)) { header('Content-Type:text/plain; charset=utf-8'); echo "Dompdf não encontrado. Rode 'composer install'."; exit; }

$opts = new Options(); $opts->set('isHtml5ParserEnabled', true); $opts->set('isRemoteEnabled', true);
$pdf  = new Dompdf($opts);
$pdf->loadHtml($html);
$pdf->setPaper('A4','portrait');
$pdf->render();
$pdf->stream('relatorios_objetivos_' . date('Ymd_His') . '.pdf', ['Attachment'=>true]);
