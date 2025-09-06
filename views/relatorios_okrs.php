<?php declare(strict_types=1);
/* views/relatorios_okrs.php
 * Relatórios de OKRs (A4) — Preview + Exportação (individual e lote)
 * Banco: MySQL (PDO) — segue o padrão de views/detalhe_okr.php
 */

if (isset($_GET['debug'])) { ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ---------------- Conexão PDO (MySQL) ---------------- */
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die("<div style='padding:16px;background:#7a1020;color:#ffe4e6;border-radius:10px'>".
      "<strong>Falha ao conectar no MySQL.</strong> ".
      (isset($_GET['debug'])? "Erro: ".htmlspecialchars($e->getMessage()):"") .
      "</div>");
}

/* ---------------- Helpers ---------------- */
function nomeMesPt(int $n): string {
  static $m=[1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
  return $m[$n] ?? '';
}
function fmtDate(?string $s, string $fmt='d/m/Y'): string {
  if (!$s) return '—';
  $ts = strtotime($s);
  return $ts ? date($fmt, $ts) : $s;
}
function nomeUsuario(PDO $pdo, $id): string {
  $id = (int)$id; if ($id<=0) return '—';
  $st = $pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user=:id LIMIT 1");
  $st->execute(['id'=>$id]);
  $r = $st->fetch();
  if (!$r) return '—';
  $pn = trim((string)($r['primeiro_nome'] ?? ''));
  $ln = trim((string)($r['ultimo_nome'] ?? ''));
  return trim($pn . ' ' . $ln) ?: ($pn ?: '—');
}

/* ---- Progresso (média dos KRs): usa baseline/meta do KR e último valor_real de milestones ---- */
function progressoObjetivo(PDO $pdo, int $idObj): float {
  $sql = "
    SELECT AVG(p.progresso) AS media
    FROM (
      SELECT
        CASE WHEN (COALESCE(kr.meta,0) - COALESCE(kr.baseline,0)) <> 0
          THEN ROUND(((COALESCE(
                  (SELECT mk.valor_real
                   FROM milestones_kr mk
                   WHERE mk.id_kr = kr.id_kr AND mk.valor_real IS NOT NULL
                   ORDER BY COALESCE(mk.dt_apontamento, mk.dt_evidencia, mk.data_ref) DESC, mk.num_ordem DESC
                   LIMIT 1
                  ),
                  kr.baseline
                ) - kr.baseline) / (kr.meta - kr.baseline)) * 100, 1)
          ELSE 0
        END AS progresso
      FROM key_results kr
      WHERE kr.id_objetivo = :id
    ) p
  ";
  $st = $pdo->prepare($sql); $st->execute(['id'=>$idObj]);
  $v = (float)($st->fetchColumn() ?: 0);
  return max(0.0, min(100.0, $v));
}

/* ---- Lista KRs do objetivo (com progresso) ---- */
function krsDoObjetivo(PDO $pdo, int $idObj): array {
  $st = $pdo->prepare("SELECT * FROM key_results WHERE id_objetivo=:id");
  $st->execute(['id'=>$idObj]);
  $lista = $st->fetchAll() ?: [];
  foreach ($lista as &$kr) {
    $p = 0.0;
    $base = (float)($kr['baseline'] ?? 0);
    $meta = (float)($kr['meta'] ?? 0);
    if ($meta != $base) {
      $st2 = $pdo->prepare("
        SELECT mk.valor_real
        FROM milestones_kr mk
        WHERE mk.id_kr = :id AND mk.valor_real IS NOT NULL
        ORDER BY COALESCE(mk.dt_apontamento, mk.dt_evidencia, mk.data_ref) DESC, mk.num_ordem DESC
        LIMIT 1
      "); $st2->execute(['id'=>$kr['id_kr']]); $cur = $st2->fetchColumn();
      $cur = ($cur !== false && $cur !== null) ? (float)$cur : $base;
      $p = round((($cur - $base) / ($meta - $base)) * 100.0, 1);
    }
    $kr['progresso_calc'] = max(0.0, min(100.0, (float)$p));
  }
  return $lista;
}

/* ---- KPIs Orçamento/Iniciativas ---- */
function kpisOrcamentoObjetivo(PDO $pdo, int $idObj): array {
  $tIni = (int)($pdo->query("
    SELECT COUNT(*) FROM iniciativas i
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo={$idObj}
  ")->fetchColumn() ?: 0);

  $comOrc = (int)($pdo->query("
    SELECT COUNT(DISTINCT i.id_iniciativa) FROM iniciativas i
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    INNER JOIN orcamentos o ON o.id_iniciativa=i.id_iniciativa
    WHERE kr.id_objetivo={$idObj}
  ")->fetchColumn() ?: 0);

  $aprov = (float)($pdo->query("
    SELECT COALESCE(SUM(o.valor),0) FROM orcamentos o
    INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo={$idObj}
  ")->fetchColumn() ?: 0);

  $real = (float)($pdo->query("
    SELECT COALESCE(SUM(od.valor),0) FROM orcamentos_detalhes od
    INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
    INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo={$idObj}
  ")->fetchColumn() ?: 0);

  return ['tIni'=>$tIni,'comOrc'=>$comOrc,'aprov'=>$aprov,'real'=>$real,'saldo'=>max(0,$aprov-$real)];
}

/* ---- Apontamentos recentes ---- */
function apontamentosRecentes(PDO $pdo, int $idObj, int $limit=6): array {
  $st = $pdo->prepare("
    SELECT kr.id_kr, kr.descricao AS kr_desc, m.num_ordem, m.valor_esperado, m.valor_real,
           m.dt_evidencia, m.dt_apontamento
    FROM milestones_kr m
    INNER JOIN key_results kr ON kr.id_kr = m.id_kr
    WHERE kr.id_objetivo = :id AND m.valor_real IS NOT NULL
    ORDER BY COALESCE(m.dt_apontamento, m.dt_evidencia, m.data_ref) DESC, m.num_ordem DESC
    LIMIT {$limit}
  ");
  $st->execute(['id'=>$idObj]);
  return $st->fetchAll() ?: [];
}

/* ---- Próximo milestone em aberto ---- */
function proximoMilestoneAberto(PDO $pdo, int $idObj): ?array {
  $st = $pdo->prepare("
    SELECT m.*, kr.descricao AS kr_desc
    FROM milestones_kr m
    INNER JOIN key_results kr ON kr.id_kr=m.id_kr
    WHERE kr.id_objetivo=:id AND m.valor_real IS NULL AND m.data_ref IS NOT NULL
    ORDER BY m.data_ref ASC, m.num_ordem ASC
    LIMIT 1
  ");
  $st->execute(['id'=>$idObj]);
  $r = $st->fetch();
  return $r ?: null;
}

/* ---- Pontos de atenção (regras simples) ---- */
function pontosAtencaoIA(PDO $pdo, int $idObj): array {
  $pontos = [];

  // 1) KR abaixo do esperado (>15pp) no último milestone com real
  $st = $pdo->prepare("
    SELECT kr.id_kr, kr.descricao,
           m.num_ordem, m.valor_real, m.valor_esperado
    FROM key_results kr
    LEFT JOIN (
      SELECT x.*
      FROM milestones_kr x
      WHERE x.valor_real IS NOT NULL
      ORDER BY COALESCE(x.dt_apontamento, x.dt_evidencia, x.data_ref) DESC, x.num_ordem DESC
    ) m ON m.id_kr=kr.id_kr
    WHERE kr.id_objetivo=:id
    GROUP BY kr.id_kr, kr.descricao, m.num_ordem, m.valor_real, m.valor_esperado
  "); $st->execute(['id'=>$idObj]);
  foreach ($st as $r) {
    if ($r['valor_real'] !== null && $r['valor_esperado'] !== null) {
      $gap = (float)$r['valor_real'] - (float)$r['valor_esperado'];
      if ($gap < -15) {
        $pontos[] = "KR \"".htmlspecialchars((string)$r['descricao'])."\" está <strong>".number_format(abs($gap),1,',','.')."pp</strong> abaixo do esperado no marco ".(int)$r['num_ordem'].".";
      }
    }
  }

  // 2) Milestones vencidos sem real
  $st = $pdo->prepare("
    SELECT kr.descricao, m.data_ref
    FROM milestones_kr m
    INNER JOIN key_results kr ON kr.id_kr=m.id_kr
    WHERE kr.id_objetivo=:id AND m.valor_real IS NULL AND m.data_ref < CURDATE()
    ORDER BY m.data_ref ASC
    LIMIT 3
  "); $st->execute(['id'=>$idObj]);
  foreach ($st as $r) {
    $pontos[] = "Milestone vencido para KR \"".htmlspecialchars((string)$r['descricao'])."\" em <strong>".fmtDate($r['data_ref'])."</strong> sem apontamento.";
  }

  // 3) Desvio financeiro >10% até hoje
  $plan = (float)($pdo->prepare("
    SELECT COALESCE(SUM(o.valor),0) FROM orcamentos o
    INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo=:id AND o.data_desembolso <= CURDATE()
  ")->execute(['id'=>$idObj]) ?: 0);
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(od.valor),0) FROM orcamentos_detalhes od
    INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
    INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo=:id AND od.data_pagamento <= CURDATE()
  "); $stmt->execute(['id'=>$idObj]); $real = (float)($stmt->fetchColumn() ?: 0);
  if ($plan > 0 && $real > $plan*1.10){
    $pontos[] = "Realizado financeiro ultrapassou o planejado até hoje em <strong>".
      number_format((($real/$plan)-1)*100,1,',','.')."%</strong>.";
  }

  // 4) Sem apontamentos nos últimos 30 dias
  $stmt = $pdo->prepare("
    SELECT MAX(COALESCE(m.dt_apontamento, m.dt_evidencia, m.data_ref)) AS ult
    FROM milestones_kr m
    INNER JOIN key_results kr ON kr.id_kr=m.id_kr
    WHERE kr.id_objetivo=:id
  "); $stmt->execute(['id'=>$idObj]); $ult = $stmt->fetchColumn();
  if ($ult) {
    $days = (int)floor((time()-strtotime($ult))/(60*60*24));
    if ($days > 30) $pontos[] = "Sem apontamentos há <strong>{$days} dias</strong>.";
  }

  // 5) Pendências de aprovação com data passada
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM orcamentos o
    INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
    INNER JOIN key_results kr ON kr.id_kr=i.id_kr
    WHERE kr.id_objetivo=:id AND COALESCE(o.status_aprovacao,'pendente')='pendente'
      AND o.data_desembolso < CURDATE()
  "); $stmt->execute(['id'=>$idObj]); $pend = (int)($stmt->fetchColumn() ?: 0);
  if ($pend>0) $pontos[] = "Existem <strong>{$pend}</strong> parcelas de orçamento pendentes de aprovação com data passada.";

  return $pontos ?: ["Sem alertas críticos pelas regras atuais."];
}

/* ---------------- Entrada (POST seleção) ---------------- */
$idsSelecionados = isset($_POST['objetivos']) && is_array($_POST['objetivos']) ? array_map('intval', $_POST['objetivos']) : [];
$mesLabel = nomeMesPt((int)date('n'));
$anoAtual = date('Y');

/* ---------------- Layout ---------------- */
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Relatórios de OKRs (A4)</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <style>
    :root{ --card:#222; --gold:#F1C40F; --border:#283142; --muted:#a6adbb; --text:#eaeef6; }
    body{ background:#0b0f14; color:var(--text); margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,'Noto Sans','Apple Color Emoji','Segoe UI Emoji',sans-serif; }
    .content{ padding:16px; }
    .rel-h1{ display:flex; align-items:center; gap:8px; font-weight:900; }
    .a4 { background:linear-gradient(180deg, var(--card), #0d1117); color:var(--text); border:1px solid var(--border); border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.25); padding:18px; margin-bottom:18px; }
    .hdr{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .title{ font-size:1.1rem; font-weight:900; color:var(--gold); }
    .sub{ color:#cbd5e1; opacity:.9; }
    .kpi-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-top:10px; }
    @media(max-width:1000px){ .kpi-grid{ grid-template-columns:repeat(2,1fr);} }
    @media(max-width:560px){ .kpi-grid{ grid-template-columns:1fr;} }
    .kpi{ background:#0e131a; border:1px solid var(--border); border-radius:12px; padding:10px; }
    .kpi .v{ font-weight:900; font-size:1.4rem; }
    .table{ width:100%; border-collapse:collapse; margin-top:10px; }
    .table th,.table td{ border-bottom:1px dashed #1f2635; padding:8px 6px; color:#d1d5db; text-align:left; }
    .badge{ display:inline-block; border:1px solid #705e14; color:#ffec99; background:#3b320a; border-radius:999px; font-size:.72rem; padding:2px 8px; }
    .pill{ display:inline-flex; align-items:center; gap:6px; background:#0c1118; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-weight:700; }
    .info{ display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-top:8px; }
    @media(max-width:700px){ .info{ grid-template-columns:1fr; } }
    .callout{ background:#0b1018; border:1px solid #1f2a3a; border-radius:12px; padding:12px; }
    .progressbar{ background:#1b2434; height:20px; border-radius:8px; overflow:hidden; }
    .progressbar .in{ background:#22c55e; height:100%; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:800; color:#05211b; }
    .actions{ display:flex; gap:8px; margin-top:8px; }
    .btn{ border:1px solid var(--border); background:#0c1118; color:#e5e7eb; padding:8px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); }
    @media print{ .no-print{ display:none !important; } .a4{ page-break-inside:avoid; } @page{ size: A4 portrait; margin: 12mm 10mm; } }
    select, button{ font: inherit; }
  </style>
</head>
<body>
  <div class="content">
    <h1 class="rel-h1"><i class="fa-regular fa-file-lines"></i> Relatórios de OKRs</h1>

    <!-- Filtros -->
    <form method="POST" class="no-print" style="margin:10px 0 16px">
      <label class="pill"><i class="fa-solid fa-bullseye"></i> Selecione os Objetivos</label>
      <select name="objetivos[]" id="selObjetivos" multiple style="width:100%; min-height:120px; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:8px; padding:6px;">
        <?php
          $res = $pdo->query("
            SELECT id_objetivo, CAST(descricao AS CHAR) AS descricao
            FROM objetivos
            WHERE COALESCE(CAST(status AS CHAR),'') <> 'cancelado'
            ORDER BY descricao
          ");
          $current = array_flip($idsSelecionados);
          foreach ($res as $row) {
            $sel = isset($current[(int)$row['id_objetivo']]) ? 'selected' : '';
            echo "<option value='".(int)$row['id_objetivo']."' {$sel}>".htmlspecialchars((string)$row['descricao'])."</option>";
          }
        ?>
      </select>
      <div class="actions" style="margin-top:10px">
        <button class="btn" type="submit"><i class="fa-regular fa-eye"></i> Gerar preview</button>
        <?php if (!empty($idsSelecionados)): ?>
          <button class="btn" type="button" id="btnExportarTodos"><i class="fa-regular fa-file-pdf"></i> Exportar PDFs (lote)</button>
        <?php endif; ?>
      </div>
    </form>

    <?php
      if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($idsSelecionados)) {
        foreach ($idsSelecionados as $id) {
          $st = $pdo->prepare("SELECT * FROM objetivos WHERE id_objetivo=:id");
          $st->execute(['id'=>$id]);
          $obj = $st->fetch();
          if (!$obj) {
            echo "<div class='a4'><div class='callout'>Erro ao carregar objetivo <strong>".htmlspecialchars((string)$id)."</strong>.</div></div>";
            continue;
          }
          $donoNome = nomeUsuario($pdo, (int)($obj['dono'] ?? 0));
          $progObj  = progressoObjetivo($pdo, (int)$id);
          $kpis     = kpisOrcamentoObjetivo($pdo, (int)$id);
          $krs      = krsDoObjetivo($pdo, (int)$id);
          $aponts   = apontamentosRecentes($pdo, (int)$id, 6);
          $proxMs   = proximoMilestoneAberto($pdo, (int)$id);
          $ia       = pontosAtencaoIA($pdo, (int)$id);
          ?>
          <section class="a4">
            <div class="hdr">
              <div>
                <div class="title"><i class="fa-solid fa-bullseye"></i> OBJETIVO <?= (int)$id ?></div>
                <div class="sub" style="margin-top:4px">
                  <strong><?= htmlspecialchars((string)$obj['descricao']) ?></strong><br>
                  Responsável: <?= htmlspecialchars($donoNome) ?>
                </div>
                <div class="info">
                  <span class="pill"><i class="fa-solid fa-layer-group"></i> Pilar: <?= htmlspecialchars((string)($obj['pilar_bsc'] ?? '')) ?></span>
                  <span class="pill"><i class="fa-solid fa-tag"></i> Tipo: <?= htmlspecialchars((string)($obj['tipo'] ?? '')) ?></span>
                </div>
              </div>
              <div style="min-width:220px">
                <div class="pill"><i class="fa-regular fa-calendar"></i> Resultados até <?= $mesLabel ?>/<?= $anoAtual ?></div>
                <div class="progressbar" style="margin-top:8px"><div class="in" style="width: <?= max(0,min(100,$progObj)) ?>%"><?= number_format($progObj,1,',','.') ?>%</div></div>
              </div>
            </div>

            <!-- KPIs -->
            <div class="kpi-grid">
              <div class="kpi"><div>Iniciativas</div><div class="v"><?= (int)$kpis['tIni'] ?></div><div style="opacity:.75">Com orçamento: <strong><?= (int)$kpis['comOrc'] ?></strong></div></div>
              <div class="kpi"><div>Orçamento aprovado</div><div class="v">R$ <?= number_format($kpis['aprov'],2,',','.') ?></div></div>
              <div class="kpi"><div>Realizado</div><div class="v">R$ <?= number_format($kpis['real'],2,',','.') ?></div></div>
              <div class="kpi"><div>Saldo</div><div class="v">R$ <?= number_format($kpis['saldo'],2,',','.') ?></div></div>
            </div>

            <!-- KRs -->
            <h3 style="margin:14px 0 6px">Key Results <span class="badge"><?= count($krs) ?></span></h3>
            <table class="table">
              <thead><tr>
                <th>KR</th><th>Dono</th><th>Progresso</th><th>Prazo</th>
              </tr></thead>
              <tbody>
              <?php foreach($krs as $kr):
                $donokr = nomeUsuario($pdo, (int)($kr['responsavel'] ?? $kr['id_user_responsavel'] ?? 0));
                // tenta detectar a coluna de prazo
                $prazo = $kr['dt_novo_prazo'] ?? $kr['data_fim'] ?? $kr['dt_prazo'] ?? $kr['data_limite'] ?? $kr['dt_limite'] ?? null;
                $pc = (float)($kr['progresso_calc'] ?? 0);
              ?>
              <tr>
                <td><?= htmlspecialchars((string)$kr['descricao']) ?></td>
                <td><?= htmlspecialchars($donokr ?: '—') ?></td>
                <td>
                  <div class="progressbar" style="max-width:220px"><div class="in" style="width: <?= max(0,min(100,$pc)) ?>%"><?= number_format($pc,1,',','.') ?>%</div></div>
                </td>
                <td><?= htmlspecialchars($prazo ? fmtDate((string)$prazo) : '—') ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Próximo marco -->
            <?php if ($proxMs): ?>
              <div class="callout" style="margin-top:10px">
                <strong><i class="fa-regular fa-hourglass-half"></i> Próximo milestone em aberto:</strong>
                KR “<?= htmlspecialchars((string)$proxMs['kr_desc']) ?>”
                · Marco #<?= (int)($proxMs['num_ordem'] ?? 0) ?>
                · Previsto: <?= htmlspecialchars(fmtDate((string)($proxMs['data_ref'] ?? ''))) ?>
              </div>
            <?php endif; ?>

            <!-- Apontamentos recentes -->
            <h3 style="margin:14px 0 6px">Apontamentos recentes</h3>
            <table class="table">
              <thead><tr><th>KR</th><th>Marco</th><th>Esperado</th><th>Real</th><th>Evidência</th><th>Apontamento</th></tr></thead>
              <tbody>
              <?php if(!$aponts): ?>
                <tr><td colspan="6" style="opacity:.7">Sem apontamentos com valor_real.</td></tr>
              <?php else: foreach($aponts as $a): ?>
                <tr>
                  <td><?= htmlspecialchars((string)$a['kr_desc']) ?></td>
                  <td>#<?= (int)$a['num_ordem'] ?></td>
                  <td><?= is_null($a['valor_esperado'])?'—':number_format((float)$a['valor_esperado'],2,',','.') ?></td>
                  <td><?= is_null($a['valor_real'])?'—':number_format((float)$a['valor_real'],2,',','.') ?></td>
                  <td><?= htmlspecialchars($a['dt_evidencia'] ? fmtDate((string)$a['dt_evidencia'],'d/m/Y H:i') : '—') ?></td>
                  <td><?= htmlspecialchars($a['dt_apontamento'] ? fmtDate((string)$a['dt_apontamento'],'d/m/Y H:i') : '—') ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>

            <!-- IA: Pontos de atenção -->
            <h3 style="margin:14px 0 6px">Pontos de atenção (IA)</h3>
            <div class="callout">
              <ul style="margin:0 0 0 18px; padding:0">
                <?php foreach($ia as $p): ?>
                  <li style="margin:4px 0"><?= $p ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <!-- Exportação -->
            <div class="actions no-print">
              <form method="POST" action="/OKR_system/auth/relatorio_gerarpdf.php" target="_blank">
                <input type="hidden" name="id_objetivo" value="<?= (int)$id ?>">
                <button class="btn" type="submit"><i class="fa-regular fa-file-pdf"></i> Baixar PDF deste objetivo</button>
              </form>
            </div>
          </section>
          <?php
        }
      } else {
        echo "<div class='a4'><div class='callout'>Selecione um ou mais objetivos e clique em <strong>Gerar preview</strong>.</div></div>";
      }
    ?>
  </div>

  <script>
    document.getElementById('btnExportarTodos')?.addEventListener('click', function(){
      const sel = document.getElementById('selObjetivos');
      const ids = Array.from(sel?.selectedOptions || []).map(o=>o.value).filter(Boolean);
      if (!ids.length){ alert('Selecione ao menos um objetivo.'); return; }
      const f=document.createElement('form');
      f.method='POST'; f.action='/OKR_system/auth/relatorio_gerarpdflote.php'; f.target='_blank';
      ids.forEach(id=>{ const i=document.createElement('input'); i.type='hidden'; i.name='objetivos[]'; i.value=id; f.appendChild(i); });
      document.body.appendChild(f); f.submit(); f.remove();
    });
  </script>
</body>
</html>
