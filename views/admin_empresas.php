<?php
/**
 * Painel de Empresas — exclusivo de admin_master.
 * Lista as empresas com indicadores de uso e liga/desliga `company.ativo`
 * (migração 013). Hoje "ativa" governa só os avisos de pendência
 * (tools/notificacoes_okr.php); não bloqueia acesso ao sistema.
 * Gravação: auth/admin_empresas_api.php.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/helpers/kr_status.php';
require_once __DIR__ . '/../auth/helpers/num_format.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

$stRole = pdo_conn()->prepare("
  SELECT 1 FROM rbac_user_role ur
    JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
   WHERE ur.user_id = :uid AND r.role_key = 'admin_master'
   LIMIT 1
");
$stRole->execute([':uid' => (int)$_SESSION['user_id']]);
if (!$stRole->fetchColumn()) {
  deny_with_modal('Acesso restrito a administradores do sistema.');
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Conexão própria: não altera o modo de fetch da conexão compartilhada (sidebar/header).
$pdo = new PDO(
  'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$colExiste = static function (string $t, string $c) use ($pdo): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$t, $c]);
  return (bool)$st->fetchColumn();
};
$tabExiste = static function (string $t) use ($pdo): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$t]);
  return (bool)$st->fetchColumn();
};
$temAtivo  = $colExiste('company', 'ativo');
$temPrefs  = $tabExiste('usuarios_notif_pref');
$hoje      = date('Y-m-d');

/* ---------- empresas ---------- */
$empresas = [];
$sql = "SELECT id_company, organizacao, razao_social, cnpj, municipio, uf, created_at"
     . ($temAtivo ? ", ativo, ativo_alterado_em" : ", 0 AS ativo, NULL AS ativo_alterado_em")
     . " FROM company ORDER BY organizacao";
foreach ($pdo->query($sql) as $r) {
  $empresas[(int)$r['id_company']] = $r + [
    'usr_total' => 0, 'usr_ativos' => 0, 'usr_avisos' => 0,
    'objetivos' => 0, 'krs' => 0, 'krs_andamento' => 0, 'iniciativas' => 0,
    'atraso_marcos' => 0, 'atraso_inis' => 0, 'ult_apont' => null,
  ];
}
$soma = static function (array &$emp, int $cid, string $campo, $valor): void {
  if (isset($emp[$cid])) $emp[$cid][$campo] = $valor;
};

/* ---------- colaboradores ---------- */
$q = $pdo->query("
  SELECT u.id_company, COUNT(*) total, SUM(u.ativo = 1) ativos"
  . ($temPrefs ? ", SUM(u.ativo = 1 AND (p.lembrete_marco = 1 OR p.lembrete_iniciativa = 1 OR p.relatorio_atrasos = 1 OR p.resumo_semanal = 1)) avisos" : ", 0 avisos") . "
    FROM usuarios u " . ($temPrefs ? "LEFT JOIN usuarios_notif_pref p ON p.id_user = u.id_user" : "") . "
   WHERE u.id_company IS NOT NULL
   GROUP BY u.id_company
");
foreach ($q as $r) {
  $cid = (int)$r['id_company'];
  $soma($empresas, $cid, 'usr_total', (int)$r['total']);
  $soma($empresas, $cid, 'usr_ativos', (int)$r['ativos']);
  $soma($empresas, $cid, 'usr_avisos', (int)$r['avisos']);
}

/* ---------- OKR ---------- */
foreach ($pdo->query("SELECT id_company, COUNT(*) n FROM objetivos GROUP BY id_company") as $r) {
  $soma($empresas, (int)$r['id_company'], 'objetivos', (int)$r['n']);
}
// status do KR é texto livre: normaliza em PHP
foreach ($pdo->query("
  SELECT o.id_company, k.status, COUNT(*) n
    FROM key_results k JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   GROUP BY o.id_company, k.status
") as $r) {
  $cid = (int)$r['id_company'];
  if (!isset($empresas[$cid])) continue;
  $empresas[$cid]['krs'] += (int)$r['n'];
  $s = krs_normalize_status($r['status']);
  if ($s !== 'concluido' && $s !== 'cancelado') $empresas[$cid]['krs_andamento'] += (int)$r['n'];
}
foreach ($pdo->query("
  SELECT o.id_company, COUNT(*) n
    FROM iniciativas i JOIN key_results k ON k.id_kr = i.id_kr JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   GROUP BY o.id_company
") as $r) {
  $soma($empresas, (int)$r['id_company'], 'iniciativas', (int)$r['n']);
}

/* ---------- pendências em atraso (mesma regra dos avisos) ---------- */
$st = $pdo->prepare("
  SELECT o.id_company, k.status, COUNT(*) n
    FROM milestones_kr m
    JOIN key_results k ON k.id_kr = m.id_kr
    JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   WHERE m.data_ref < ? AND m.qtde_apontamentos = 0
   GROUP BY o.id_company, k.status
");
$st->execute([$hoje]);
foreach ($st as $r) {
  $cid = (int)$r['id_company'];
  $s = krs_normalize_status($r['status']);
  if (isset($empresas[$cid]) && !in_array($s, ['concluido', 'cancelado', 'pausado'], true)) {
    $empresas[$cid]['atraso_marcos'] += (int)$r['n'];
  }
}
$st = $pdo->prepare("
  SELECT o.id_company, i.status, COUNT(*) n
    FROM iniciativas i
    JOIN key_results k ON k.id_kr = i.id_kr
    JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   WHERE i.dt_prazo < ?
   GROUP BY o.id_company, i.status
");
$st->execute([$hoje]);
foreach ($st as $r) {
  $cid = (int)$r['id_company'];
  $s = krs_normalize_status($r['status']);
  if (isset($empresas[$cid]) && !in_array($s, ['concluido', 'cancelado', 'pausado'], true)) {
    $empresas[$cid]['atraso_inis'] += (int)$r['n'];
  }
}
foreach ($pdo->query("
  SELECT o.id_company, MAX(a.dt_apontamento) ult
    FROM apontamentos_kr a JOIN key_results k ON k.id_kr = a.id_kr JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   GROUP BY o.id_company
") as $r) {
  $soma($empresas, (int)$r['id_company'], 'ult_apont', $r['ult']);
}

uasort($empresas, static fn($a, $b) =>
  [(int)$b['ativo'], mb_strtolower((string)$a['organizacao'])] <=> [(int)$a['ativo'], mb_strtolower((string)$b['organizacao'])]);

/* ---------- KPIs ---------- */
$kpi = ['empresas' => count($empresas), 'ativas' => 0, 'colab' => 0, 'atraso' => 0];
foreach ($empresas as $e) {
  $kpi['colab'] += $e['usr_ativos'];
  if ((int)$e['ativo'] === 1) {
    $kpi['ativas']++;
    $kpi['atraso'] += $e['atraso_marcos'] + $e['atraso_inis'];
  }
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cnpj_fmt(?string $c): string {
  $d = preg_replace('/\D/', '', (string)$c);
  return strlen($d) === 14
    ? substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2)
    : ($c ?: '');
}
function data_rel(?string $dt): string {
  if (!$dt) return '<span class="pe-muted">nunca</span>';
  $t = strtotime($dt);
  $dias = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $t))) / 86400);
  $txt = $dias <= 0 ? 'hoje' : ($dias === 1 ? 'ontem' : "há $dias dias");
  $cls = $dias > 30 ? 'pe-warn' : '';
  return '<span class="' . $cls . '" title="' . date('d/m/Y H:i', $t) . '">' . $txt . '</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel de Empresas – Admin – OKR System</title>
<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
<style>
.pe-wrap{ padding:2rem 2rem 2rem 1.5rem; margin-right:var(--chat-w,0); transition:margin-right .25s ease; }
@media (max-width:991px){ .pe-wrap{ padding:1rem; } }
.pe-title{ font-size:1.5rem; font-weight:800; color:var(--gold,#F1C40F); margin:0 0 .25rem; }
.pe-sub{ font-size:.85rem; color:var(--text-secondary,#aaa); margin:0 0 1.5rem; max-width:760px; line-height:1.5; }

.pe-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:.75rem; margin-bottom:1.25rem; }
.pe-kpi{ background:var(--card,#1a1f2b); border:1px solid var(--border,#2a2f3b); border-radius:12px; padding:1rem 1.25rem; display:flex; align-items:center; gap:.75rem; }
.pe-kpi i{ width:40px; height:40px; border-radius:10px; display:grid; place-items:center; flex-shrink:0; }
.pe-kpi .k-blue{ background:rgba(59,130,246,.15); color:#60a5fa; }
.pe-kpi .k-green{ background:rgba(34,197,94,.15); color:#4ade80; }
.pe-kpi .k-amber{ background:rgba(245,158,11,.15); color:#fbbf24; }
.pe-kpi .k-red{ background:rgba(239,68,68,.15); color:#f87171; }
.pe-kpi b{ display:block; font-size:1.5rem; font-weight:800; color:var(--text,#eee); line-height:1; font-variant-numeric:tabular-nums; }
.pe-kpi span{ font-size:.75rem; color:var(--text-secondary,#aaa); }

.pe-bar{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.pe-bar input, .pe-bar select{ background:var(--card,#1a1f2b); border:1px solid var(--border,#2a2f3b); border-radius:10px; color:var(--text,#eee); padding:.6rem 1rem; font-size:.85rem; outline:none; }
.pe-bar input{ flex:1; min-width:200px; }
.pe-bar input:focus, .pe-bar select:focus{ border-color:var(--gold,#F1C40F); }

.pe-table-wrap{ background:var(--card,#1a1f2b); border:1px solid var(--border,#2a2f3b); border-radius:12px; overflow-x:auto; }
table.pe{ width:100%; border-collapse:collapse; font-size:.85rem; color:var(--text,#eee); }
.pe th{ text-align:left; font-size:.7rem; letter-spacing:.05em; text-transform:uppercase; color:var(--text-secondary,#aaa); padding:.75rem 1rem; border-bottom:1px solid var(--border,#2a2f3b); white-space:nowrap; }
.pe td{ padding:.8rem 1rem; border-bottom:1px solid rgba(255,255,255,.05); vertical-align:middle; }
.pe tr:last-child td{ border-bottom:0; }
.pe tr.is-off td:not(.pe-col-toggle){ opacity:.55; }
.pe .num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.pe-name{ font-weight:700; }
.pe td.pe-col-name{ min-width:230px; }
.pe-meta span{ white-space:nowrap; }
.pe-id{ color:var(--text-secondary,#aaa); font-weight:400; font-size:.75rem; }
.pe-meta{ font-size:.75rem; color:var(--text-secondary,#aaa); margin-top:2px; }
.pe-muted{ color:var(--text-secondary,#aaa); }
.pe-warn{ color:#fbbf24; }
.pe-late{ color:#f87171; font-weight:700; }
.pe-ok{ color:#4ade80; }

.pe-sw{ display:inline-flex; align-items:center; gap:.5rem; cursor:pointer; user-select:none; font-weight:700; font-size:.8rem; }
.pe-sw input{ position:absolute; opacity:0; width:1px; height:1px; }
.pe-sw .trk{ position:relative; width:38px; height:21px; border-radius:999px; background:#334155; transition:background .15s; flex-shrink:0; }
.pe-sw .trk::after{ content:""; position:absolute; top:3px; left:3px; width:15px; height:15px; border-radius:50%; background:#cbd5e1; transition:transform .15s; }
.pe-sw input:checked + .trk{ background:#16a34a; }
.pe-sw input:checked + .trk::after{ transform:translateX(17px); background:#fff; }
.pe-sw input:focus-visible + .trk{ outline:2px solid #60a5fa; outline-offset:2px; }
.pe-sw .lbl-on, .pe-sw input:checked ~ .lbl-off{ display:none; }
.pe-sw input:checked ~ .lbl-on{ display:inline; color:#4ade80; }
.pe-sw .lbl-off{ color:var(--text-secondary,#aaa); }
.pe-sw.is-busy{ opacity:.5; pointer-events:none; }
.pe-empty{ padding:1.5rem; text-align:center; color:var(--text-secondary,#aaa); }
.pe-alert{ background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.35); color:#fbbf24; border-radius:10px; padding:.75rem 1rem; margin-bottom:1rem; font-size:.85rem; }

/* celular: tabela vira lista de cartões */
@media (max-width:760px){
  table.pe thead{ display:none; }
  table.pe, .pe tbody, .pe tr, .pe td{ display:block; width:100%; }
  .pe tr{ padding:.75rem 1rem; border-bottom:1px solid var(--border,#2a2f3b); }
  .pe td{ padding:.25rem 0; border:0; display:flex; justify-content:space-between; gap:1rem; text-align:right; }
  .pe td::before{ content:attr(data-lbl); color:var(--text-secondary,#aaa); font-size:.75rem; text-align:left; }
  .pe td.pe-col-name{ display:block; text-align:left; }
  .pe td.pe-col-name::before{ content:none; }
}

.pe-toast{ position:fixed; bottom:20px; right:20px; z-index:3000; padding:12px 18px; border-radius:10px; font-weight:700; color:#eafff5; background:#0b7a44; box-shadow:0 10px 30px rgba(0,0,0,.25); max-width:420px; transition:opacity .4s; }
.pe-toast.warn{ background:#92400e; color:#fef3c7; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="pe-wrap">
    <h1 class="pe-title"><i class="fa-solid fa-city"></i> Painel de Empresas</h1>
    <p class="pe-sub">Visão geral das empresas clientes. Só empresas <b>ativas</b> recebem lembretes, relatórios de atraso e o resumo semanal. Desativar não bloqueia o acesso ao sistema.</p>

    <?php if (!$temAtivo): ?>
      <div class="pe-alert"><i class="fa-solid fa-triangle-exclamation"></i> A marcação de empresa ativa ainda não foi instalada (migração 013). A chave fica desabilitada até lá.</div>
    <?php endif; ?>

    <div class="pe-kpis">
      <div class="pe-kpi"><i class="fa-solid fa-building k-blue"></i><div><b><?= num_br($kpi['empresas']) ?></b><span>Empresas</span></div></div>
      <div class="pe-kpi"><i class="fa-solid fa-circle-check k-green"></i><div><b id="kpiAtivas"><?= num_br($kpi['ativas']) ?></b><span>Ativas</span></div></div>
      <div class="pe-kpi"><i class="fa-solid fa-users k-amber"></i><div><b><?= num_br($kpi['colab']) ?></b><span>Colaboradores ativos</span></div></div>
      <div class="pe-kpi"><i class="fa-solid fa-clock k-red"></i><div><b><?= num_br($kpi['atraso']) ?></b><span>Atrasos em empresas ativas</span></div></div>
    </div>

    <div class="pe-bar">
      <input type="search" id="peBusca" placeholder="Buscar por nome, CNPJ ou cidade" aria-label="Buscar empresa">
      <select id="peFiltro" aria-label="Filtrar por situação">
        <option value="todas">Todas</option>
        <option value="ativas">Só ativas</option>
        <option value="inativas">Só inativas</option>
      </select>
    </div>

    <div class="pe-table-wrap">
      <table class="pe">
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Situação</th>
            <th class="num">Colaboradores</th>
            <th class="num">Objetivos</th>
            <th class="num">KRs em andamento</th>
            <th class="num">Iniciativas</th>
            <th class="num">Em atraso</th>
            <th class="num">Com avisos</th>
            <th>Último apontamento</th>
          </tr>
        </thead>
        <tbody id="peBody">
        <?php foreach ($empresas as $cid => $e):
          $ativa  = (int)$e['ativo'] === 1;
          $nome   = $e['organizacao'] ?: ($e['razao_social'] ?: "Empresa #$cid");
          $local  = trim(($e['municipio'] ?? '') . ($e['uf'] ? '/' . $e['uf'] : ''), '/');
          $atraso = $e['atraso_marcos'] + $e['atraso_inis'];
          $busca  = mb_strtolower($nome . ' ' . $e['razao_social'] . ' ' . preg_replace('/\D/', '', (string)$e['cnpj']) . ' ' . $local);
        ?>
          <tr data-busca="<?= h($busca) ?>" data-ativa="<?= $ativa ? '1' : '0' ?>" class="<?= $ativa ? '' : 'is-off' ?>">
            <td class="pe-col-name">
              <div class="pe-name"><?= h($nome) ?> <span class="pe-id">#<?= $cid ?></span></div>
              <div class="pe-meta"><?= implode(' · ', array_map(static fn($x) => '<span>' . h($x) . '</span>', array_filter([cnpj_fmt($e['cnpj']), $local]))) ?: '&nbsp;' ?></div>
            </td>            <td class="pe-col-toggle" data-lbl="Situação">
              <label class="pe-sw" title="<?= $e['ativo_alterado_em'] ? 'Alterado em ' . h(date('d/m/Y H:i', strtotime($e['ativo_alterado_em']))) : 'Ativa = recebe avisos de pendência' ?>">
                <input type="checkbox" class="pe-toggle" data-id="<?= $cid ?>" data-nome="<?= h($nome) ?>"
                  <?= $ativa ? 'checked' : '' ?> <?= $temAtivo ? '' : 'disabled' ?> aria-label="Empresa ativa: <?= h($nome) ?>">
                <span class="trk" aria-hidden="true"></span>
                <span class="lbl-on">Ativa</span><span class="lbl-off">Inativa</span>
              </label>
            </td>
            <td class="num" data-lbl="Colaboradores" title="ativos / cadastrados"><span><?= num_br($e['usr_ativos']) ?> <span class="pe-muted">/ <?= num_br($e['usr_total']) ?></span></span></td>
            <td class="num" data-lbl="Objetivos"><?= num_br($e['objetivos']) ?></td>
            <td class="num" data-lbl="KRs em andamento" title="<?= num_br($e['krs']) ?> KRs no total"><span><?= num_br($e['krs_andamento']) ?> <span class="pe-muted">/ <?= num_br($e['krs']) ?></span></span></td>
            <td class="num" data-lbl="Iniciativas"><?= num_br($e['iniciativas']) ?></td>
            <td class="num" data-lbl="Em atraso" title="<?= num_br($e['atraso_marcos']) ?> marcos sem apontamento + <?= num_br($e['atraso_inis']) ?> iniciativas vencidas">
              <?= $atraso > 0 ? '<span class="pe-late">' . num_br($atraso) . '</span>' : '<span class="pe-ok">0</span>' ?>
            </td>
            <td class="num" data-lbl="Com avisos" title="colaboradores ativos com ao menos um aviso ligado"><?= num_br($e['usr_avisos']) ?></td>
            <td data-lbl="Último apontamento"><?= data_rel($e['ult_apont']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="pe-empty" id="peVazio" hidden>Nenhuma empresa com esses filtros.</div>
    </div>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </main>
</div>

<script>
(() => {
  const CSRF = <?= json_encode($csrf) ?>;
  const body = document.getElementById('peBody');
  const busca = document.getElementById('peBusca');
  const filtro = document.getElementById('peFiltro');
  const vazio = document.getElementById('peVazio');

  function toast(msg, warn){
    const d = document.createElement('div');
    d.className = 'pe-toast' + (warn ? ' warn' : '');
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; }, warn ? 4000 : 2200);
    setTimeout(() => d.remove(), warn ? 4600 : 2800);
  }

  function aplicarFiltro(){
    const q = busca.value.trim().toLowerCase();
    const qd = q.replace(/\D/g, '');
    const f = filtro.value;
    let vis = 0;
    body.querySelectorAll('tr').forEach(tr => {
      const txt = tr.dataset.busca;
      const okBusca = !q || txt.includes(q) || (qd.length >= 3 && txt.includes(qd));
      const ativa = tr.dataset.ativa === '1';
      const okFiltro = f === 'todas' || (f === 'ativas' ? ativa : !ativa);
      tr.hidden = !(okBusca && okFiltro);
      if (!tr.hidden) vis++;
    });
    vazio.hidden = vis > 0;
  }
  busca.addEventListener('input', aplicarFiltro);
  filtro.addEventListener('change', aplicarFiltro);

  body.addEventListener('change', async (ev) => {
    const inp = ev.target.closest('.pe-toggle');
    if (!inp) return;
    const valor = inp.checked;
    const tr = inp.closest('tr');
    const lbl = inp.closest('.pe-sw');
    lbl.classList.add('is-busy');
    try {
      const fd = new FormData();
      fd.append('action', 'set_ativo');
      fd.append('csrf_token', CSRF);
      fd.append('id_company', inp.dataset.id);
      fd.append('ativo', valor ? '1' : '0');
      const r = await fetch('/OKR_system/auth/admin_empresas_api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.success) throw new Error(j.error || 'Falha ao salvar');
      tr.dataset.ativa = valor ? '1' : '0';
      tr.classList.toggle('is-off', !valor);
      document.getElementById('kpiAtivas').textContent = j.total_ativas;
      toast(`${inp.dataset.nome} ${valor ? 'ativada' : 'desativada'}`);
      aplicarFiltro();
    } catch (e) {
      inp.checked = !valor;
      toast(e.message || 'Não foi possível salvar', true);
    } finally {
      lbl.classList.remove('is-busy');
    }
  });
})();
</script>
</body>
</html>
