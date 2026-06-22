<?php
declare(strict_types=1);

/**
 * GET /objetivos/:id/relatorio
 * Gera o PDF (A4) de um objetivo. Diferente do web, aplica auth + isolamento de
 * tenant + RBAC (R:relatorio@ORG). Retorna application/pdf (não usa envelope JSON).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

// Tenant: objetivo precisa ser da empresa do usuário (admin_master pode qualquer).
$stC = $pdo->prepare("SELECT id_company FROM objetivos WHERE id_objetivo = ?");
$stC->execute([$id]);
$co = $stC->fetchColumn();
if ($co === false) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}
if ((int)$co !== $cid && !api_is_admin_master($pdo, $uid)) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

// RBAC: leitura de relatório (recurso administrativo reservado).
if (!api_has_cap($pdo, $uid, $cid, 'R:relatorio@ORG')) {
  api_error('E_FORBIDDEN', 'Sem permissão para gerar relatórios.', 403);
}

// ----- Helpers de dados (prefixados p/ evitar colisão) -----
function rel_fmtDate(?string $s, string $fmt = 'd/m/Y'): string {
  if (!$s) return '—';
  $ts = strtotime($s);
  return $ts ? date($fmt, $ts) : $s;
}
function rel_nomeUsuario(PDO $pdo, int $id): string {
  if ($id <= 0) return '—';
  $st = $pdo->prepare("SELECT primeiro_nome, ultimo_nome FROM usuarios WHERE id_user = :id LIMIT 1");
  $st->execute(['id' => $id]);
  $r = $st->fetch();
  $pn = trim((string)($r['primeiro_nome'] ?? ''));
  $ln = trim((string)($r['ultimo_nome'] ?? ''));
  return trim("$pn $ln") ?: ($pn ?: '—');
}
function rel_progresso(PDO $pdo, int $id): float {
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
  ");
  $st->execute(['id' => $id]);
  return max(0, min(100, (float)($st->fetchColumn() ?: 0)));
}
function rel_kpis(PDO $pdo, int $id): array {
  $q = function (string $sql) use ($pdo, $id) {
    $st = $pdo->prepare($sql);
    $st->execute(['id' => $id]);
    return $st->fetchColumn();
  };
  $tIni   = (int)($q("SELECT COUNT(*) FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo=:id") ?: 0);
  $comOrc = (int)($q("SELECT COUNT(DISTINCT i.id_iniciativa) FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr INNER JOIN orcamentos o ON o.id_iniciativa=i.id_iniciativa WHERE kr.id_objetivo=:id") ?: 0);
  $aprov  = (float)($q("SELECT COALESCE(SUM(o.valor),0) FROM orcamentos o INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo=:id") ?: 0);
  $real   = (float)($q("SELECT COALESCE(SUM(od.valor),0) FROM orcamentos_detalhes od INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo=:id") ?: 0);
  return ['tIni' => $tIni, 'comOrc' => $comOrc, 'aprov' => $aprov, 'real' => $real, 'saldo' => max(0, $aprov - $real)];
}
function rel_krs(PDO $pdo, int $id): array {
  $st = $pdo->prepare("SELECT * FROM key_results WHERE id_objetivo=:id");
  $st->execute(['id' => $id]);
  $L = $st->fetchAll() ?: [];
  foreach ($L as &$kr) {
    $base = (float)($kr['baseline'] ?? 0);
    $meta = (float)($kr['meta'] ?? 0);
    $p = 0.0;
    if ($meta != $base) {
      $st2 = $pdo->prepare("SELECT valor_real FROM milestones_kr WHERE id_kr=:id AND valor_real IS NOT NULL ORDER BY COALESCE(dt_apontamento,dt_evidencia,data_ref) DESC, num_ordem DESC LIMIT 1");
      $st2->execute(['id' => $kr['id_kr']]);
      $cur = $st2->fetchColumn();
      $cur = ($cur !== false && $cur !== null) ? (float)$cur : $base;
      $p = round((($cur - $base) / ($meta - $base)) * 100, 1);
    }
    $kr['p'] = max(0, min(100, (float)$p));
  }
  return $L;
}
function rel_aponts(PDO $pdo, int $id): array {
  $st = $pdo->prepare("
    SELECT kr.descricao kr_desc, m.*
    FROM milestones_kr m
    INNER JOIN key_results kr ON kr.id_kr=m.id_kr
    WHERE kr.id_objetivo=:id AND m.valor_real IS NOT NULL
    ORDER BY COALESCE(m.dt_apontamento,m.dt_evidencia,m.data_ref) DESC, m.num_ordem DESC
    LIMIT 8
  ");
  $st->execute(['id' => $id]);
  return $st->fetchAll() ?: [];
}

// ----- Dados -----
$st = $pdo->prepare("SELECT * FROM objetivos WHERE id_objetivo=:id");
$st->execute(['id' => $id]);
$obj = $st->fetch();
if (!$obj) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

$prog = rel_progresso($pdo, $id);
$kpi  = rel_kpis($pdo, $id);
$krs  = rel_krs($pdo, $id);
$dono = rel_nomeUsuario($pdo, (int)($obj['dono'] ?? 0));
$mes  = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'][(int)date('n')] ?? '';
$ano  = date('Y');

ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  *{ box-sizing:border-box; } body{ font-family:Arial, Helvetica, sans-serif; color:#111; font-size:12px; }
  h1,h2,h3{ margin:0 0 6px; }
  @page{ size:A4 portrait; margin:15mm 12mm; }
  .title{ font-weight:900; color:#0b3b75; font-size:16px; }
  .sub{ color:#444; }
  .kpi{ display:inline-block; border:1px solid #d9dee7; border-radius:8px; padding:8px 10px; margin:4px 6px 0 0; }
  .bar{ width:220px; background:#e6edf6; border-radius:8px; overflow:hidden; height:18px; display:inline-flex; align-items:center; }
  .in{ background:#38b36b; color:#fff; height:100%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:11px; }
  table{ width:100%; border-collapse:collapse; margin-top:8px; }
  th,td{ border:1px solid #dcdcdc; padding:6px 8px; text-align:left; vertical-align:middle; }
  th{ background:#f2f6fb; }
  .badge{ display:inline-block; border:1px solid #c69a00; color:#c69a00; border-radius:999px; font-size:10px; padding:2px 8px; }
  .section{ margin-top:10px; }
</style>
</head><body>

<h2 class="title">Relatório de Objetivo <?= htmlspecialchars((string)$id) ?></h2>
<div class="sub"><strong><?= htmlspecialchars((string)$obj['descricao']) ?></strong><br>Responsável: <?= htmlspecialchars($dono) ?><br>Resultados até <?= htmlspecialchars($mes) ?>/<?= htmlspecialchars((string)$ano) ?></div>

<div class="section">
  <div class="kpi"><strong>Iniciativas:</strong> <?= (int)$kpi['tIni'] ?> (com orçamento: <?= (int)$kpi['comOrc'] ?>)</div>
  <div class="kpi"><strong>Aprovado:</strong> R$ <?= number_format($kpi['aprov'], 2, ',', '.') ?></div>
  <div class="kpi"><strong>Realizado:</strong> R$ <?= number_format($kpi['real'], 2, ',', '.') ?></div>
  <div class="kpi"><strong>Saldo:</strong> R$ <?= number_format($kpi['saldo'], 2, ',', '.') ?></div>
  <div style="margin-top:8px"><div class="bar"><div class="in" style="width: <?= max(0, min(100, $prog)) ?>%"><?= number_format($prog, 1, ',', '.') ?>%</div></div></div>
</div>

<div class="section">
  <h3>Key Results <span class="badge"><?= count($krs) ?></span></h3>
  <table><thead><tr><th>KR</th><th>Dono</th><th>Progresso</th><th>Prazo</th></tr></thead><tbody>
  <?php foreach ($krs as $kr):
    $don = rel_nomeUsuario($pdo, (int)($kr['responsavel'] ?? $kr['id_user_responsavel'] ?? 0));
    $prazo = $kr['dt_novo_prazo'] ?? $kr['data_fim'] ?? $kr['dt_prazo'] ?? null;
    ?>
    <tr>
      <td><?= htmlspecialchars((string)$kr['descricao']) ?></td>
      <td><?= htmlspecialchars($don ?: '—') ?></td>
      <td><?= number_format((float)$kr['p'], 1, ',', '.') ?>%</td>
      <td><?= htmlspecialchars($prazo ? rel_fmtDate((string)$prazo) : '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>

<div class="section">
  <h3>Apontamentos recentes</h3>
  <?php $A = rel_aponts($pdo, $id); if (!$A) { echo "<div>Sem apontamentos com valor_real.</div>"; } else { ?>
    <table><thead><tr><th>KR</th><th>Marco</th><th>Esperado</th><th>Real</th><th>Evidência</th><th>Apontamento</th></tr></thead><tbody>
      <?php foreach ($A as $a): ?>
        <tr>
          <td><?= htmlspecialchars((string)$a['kr_desc']) ?></td>
          <td>#<?= (int)$a['num_ordem'] ?></td>
          <td><?= is_null($a['valor_esperado']) ? '—' : number_format((float)$a['valor_esperado'], 2, ',', '.') ?></td>
          <td><?= is_null($a['valor_real']) ? '—' : number_format((float)$a['valor_real'], 2, ',', '.') ?></td>
          <td><?= htmlspecialchars($a['dt_evidencia'] ? rel_fmtDate((string)$a['dt_evidencia'], 'd/m/Y H:i') : '—') ?></td>
          <td><?= htmlspecialchars($a['dt_apontamento'] ? rel_fmtDate((string)$a['dt_apontamento'], 'd/m/Y H:i') : '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php } ?>
</div>

</body></html>
<?php
$html = ob_get_clean();

// Dompdf (vendor autoload — não carregado pelo _core)
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
if (!class_exists(\Dompdf\Dompdf::class)) {
  api_error('E_SERVER', "Dompdf não encontrado. Rode 'composer install'.", 500);
}

$opts = new \Dompdf\Options();
$opts->set('isHtml5ParserEnabled', true);
$opts->set('isRemoteEnabled', false); // segurança: não buscar recursos remotos
$pdf = new \Dompdf\Dompdf($opts);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();

api_cors_headers();
$pdf->stream('relatorio_objetivo_' . $id . '.pdf', ['Attachment' => true]);
exit;
