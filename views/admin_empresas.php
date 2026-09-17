<?php
/**
 * Gestão de Empresas — exclusivo de admin_master (menu "Acesso Admin").
 * Une o antigo "Empresas & Usuários" (admin_companies.php, hoje só redireciona)
 * com o Painel de Empresas: indicadores de uso, dados cadastrais, usuários de
 * cada empresa e a chave `company.ativo` (migração 013).
 * "Ativa" hoje governa só os avisos de pendência (tools/notificacoes_okr.php);
 * não bloqueia acesso ao sistema. Gravação: auth/admin_empresas_api.php.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/helpers/kr_status.php';
require_once __DIR__ . '/../auth/helpers/num_format.php';
require_once __DIR__ . '/../auth/helpers/nome_format.php';
require_once __DIR__ . '/../auth/avatar_helpers.php';

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
$temAtivo = $colExiste('company', 'ativo');
$temPrefs = $tabExiste('usuarios_notif_pref');
$hoje     = date('Y-m-d');

/* ---------- empresas ---------- */
$empresas = [];
$sql = "SELECT id_company, organizacao, razao_social, cnpj, municipio, uf, email, telefone, missao, visao, created_at"
     . ($temAtivo ? ", ativo, ativo_alterado_em" : ", 0 AS ativo, NULL AS ativo_alterado_em")
     . " FROM company";
foreach ($pdo->query($sql) as $r) {
  $empresas[(int)$r['id_company']] = $r + [
    'usuarios' => [], 'usr_total' => 0, 'usr_ativos' => 0, 'usr_avisos' => 0,
    'objetivos' => 0, 'krs' => 0, 'krs_andamento' => 0, 'iniciativas' => 0,
    'atraso_marcos' => 0, 'atraso_inis' => 0, 'ult_apont' => null,
  ];
}

/* ---------- usuários (todos, para a lista dentro de cada empresa) ---------- */
$avisoCampos = ['lembrete_marco', 'lembrete_iniciativa', 'relatorio_atrasos', 'resumo_semanal'];
$semEmpresa = [];
$q = $pdo->query("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo, u.id_company, u.ativo, u.dt_cadastro,
         a.path AS avatar_path,
         (SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ',')
            FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
           WHERE ur.user_id = u.id_user) AS roles"
  . ($temPrefs ? ", p.lembrete_marco, p.lembrete_iniciativa, p.relatorio_atrasos, p.resumo_semanal" : "") . "
    FROM usuarios u
    LEFT JOIN avatars a ON a.id = u.avatar_id "
  . ($temPrefs ? "LEFT JOIN usuarios_notif_pref p ON p.id_user = u.id_user" : "") . "
   ORDER BY u.primeiro_nome, u.ultimo_nome
");
foreach ($q as $u) {
  $u['avisos'] = [];
  foreach ($avisoCampos as $k) if ((int)($u[$k] ?? 0) === 1) $u['avisos'][] = $k;
  $u['avatar'] = null;
  try {
    if (!avatar_is_default(['path' => $u['avatar_path']])) $u['avatar'] = avatar_url_from_row(['path' => $u['avatar_path']]);
  } catch (Throwable $e) { /* iniciais */ }

  $cid = (int)$u['id_company'];
  if ($cid > 0 && isset($empresas[$cid])) {
    $e = &$empresas[$cid];
    $e['usuarios'][] = $u;
    $e['usr_total']++;
    if ((int)$u['ativo'] === 1) {
      $e['usr_ativos']++;
      if ($u['avisos']) $e['usr_avisos']++;
    }
    unset($e);
  } else {
    $semEmpresa[] = $u;
  }
}

/* ---------- OKR ---------- */
foreach ($pdo->query("SELECT id_company, COUNT(*) n FROM objetivos GROUP BY id_company") as $r) {
  if (isset($empresas[(int)$r['id_company']])) $empresas[(int)$r['id_company']]['objetivos'] = (int)$r['n'];
}
// status é texto livre: normaliza em PHP
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
  if (isset($empresas[(int)$r['id_company']])) $empresas[(int)$r['id_company']]['iniciativas'] = (int)$r['n'];
}

/* ---------- atrasos (mesma regra dos avisos e da agenda) ---------- */
$fechado = ['concluido', 'cancelado', 'pausado'];
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
  if (isset($empresas[$cid]) && !in_array(krs_normalize_status($r['status']), $fechado, true)) {
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
  if (isset($empresas[$cid]) && !in_array(krs_normalize_status($r['status']), $fechado, true)) {
    $empresas[$cid]['atraso_inis'] += (int)$r['n'];
  }
}
foreach ($pdo->query("
  SELECT o.id_company, MAX(a.dt_apontamento) ult
    FROM apontamentos_kr a JOIN key_results k ON k.id_kr = a.id_kr JOIN objetivos o ON o.id_objetivo = k.id_objetivo
   GROUP BY o.id_company
") as $r) {
  if (isset($empresas[(int)$r['id_company']])) $empresas[(int)$r['id_company']]['ult_apont'] = $r['ult'];
}

// Ativas primeiro, depois por nome.
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

/* ---------- helpers de exibição ---------- */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ge_cnpj(?string $c): string {
  $d = preg_replace('/\D/', '', (string)$c);
  return strlen($d) === 14
    ? substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2)
    : (string)$c;
}
function ge_rel(?string $dt): string {
  if (!$dt) return 'nunca';
  $dias = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($dt)))) / 86400);
  return $dias <= 0 ? 'hoje' : ($dias === 1 ? 'ontem' : "há $dias dias");
}
function ge_data(?string $dt): string {
  return $dt ? date('d/m/Y', strtotime($dt)) : '—';
}
function ge_iniciais(string $a, ?string $b): string {
  return mb_strtoupper(mb_substr($a, 0, 1) . mb_substr((string)$b, 0, 1));
}
const GE_PAPEIS = [
  'admin_master'  => ['Admin master', 'r-red'],
  'gestor_master' => ['Gestor master', 'r-amber'],
  'user_admin'   => ['Administrador', 'r-purple'],
  'user_gestor'  => ['Gestor', 'r-blue'],
  'gestor_user'  => ['Gestor', 'r-blue'],
  'user_colab'   => ['Colaborador', 'r-green'],
  'user_guest'   => ['Convidado', 'r-gray'],
];
const GE_AVISOS = [
  'lembrete_marco'      => ['fa-regular fa-bell', 'Lembrete de marco'],
  'lembrete_iniciativa' => ['fa-solid fa-bullseye', 'Lembrete de iniciativa'],
  'relatorio_atrasos'   => ['fa-regular fa-clock', 'Relatório de atrasos'],
  'resumo_semanal'      => ['fa-solid fa-chart-column', 'Resumo geral'],
];
function ge_usuario(array $u): string {
  $nome = nome_exibicao((string)$u['primeiro_nome'], (string)$u['ultimo_nome']);
  $ini  = h(ge_iniciais((string)$u['primeiro_nome'], $u['ultimo_nome']));
  $av   = $u['avatar']
    ? '<img src="' . h($u['avatar']) . '" alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode(\'' . $ini . '\'))">'
    : $ini;
  $papeis = '';
  foreach (array_filter(explode(',', (string)$u['roles'])) as $r) {
    [$txt, $cls] = GE_PAPEIS[$r] ?? [$r, 'r-gray'];
    $papeis .= '<span class="ge-role ' . $cls . '">' . h($txt) . '</span>';
  }
  if ($papeis === '') $papeis = '<span class="ge-role r-gray">sem papel</span>';
  $avisos = '';
  foreach ($u['avisos'] as $k) {
    [$ico, $txt] = GE_AVISOS[$k];
    $avisos .= '<i class="' . $ico . '" title="' . h($txt) . '" aria-label="' . h($txt) . '"></i>';
  }
  $inativo = (int)$u['ativo'] !== 1;
  $busca = mb_strtolower($nome . ' ' . $u['primeiro_nome'] . ' ' . $u['ultimo_nome'] . ' ' . $u['email_corporativo']);

  return '<li class="ge-user' . ($inativo ? ' is-off' : '') . '" data-busca="' . h($busca) . '">'
    . '<span class="ge-av">' . $av . '</span>'
    . '<span class="ge-uinfo"><span class="ge-uname">' . h($nome)
    . ($inativo ? ' <span class="ge-tag">inativo</span>' : '') . '</span>'
    . '<span class="ge-umail">' . h($u['email_corporativo']) . '</span></span>'
    . '<span class="ge-roles">' . $papeis . '</span>'
    . '<span class="ge-uavisos" title="Avisos ligados">' . ($avisos ?: '<span class="ge-dim">sem avisos</span>') . '</span>'
    . '<span class="ge-udate" title="Cadastro">' . ge_data($u['dt_cadastro']) . '</span>'
    . '</li>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestão de Empresas – Acesso Admin – OKR System</title>
<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
<style>
.ge{ --ge-card:var(--card,#1a1f2b); --ge-line:var(--border,#2a2f3b); --ge-text:var(--text,#eee); --ge-mut:var(--text-secondary,#aaa); --ge-gold:var(--gold,#F1C40F);
  padding:2rem 2rem 2.5rem 1.5rem; margin-right:var(--chat-w,0); transition:margin-right .25s ease; color:var(--ge-text); }
@media (max-width:991px){ .ge{ padding:1rem; } }
.ge-eyebrow{ font-size:.7rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--ge-mut); }
.ge-title{ font-size:1.5rem; font-weight:800; color:var(--ge-gold); margin:.2rem 0 .3rem; }
.ge-sub{ font-size:.85rem; color:var(--ge-mut); margin:0 0 1.5rem; max-width:720px; line-height:1.5; }

/* KPIs */
.ge-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:.75rem; margin-bottom:1.25rem; }
.ge-kpi{ background:var(--ge-card); border:1px solid var(--ge-line); border-radius:12px; padding:1rem 1.1rem; display:flex; align-items:center; gap:.75rem; }
.ge-kpi > i{ width:40px; height:40px; border-radius:10px; display:grid; place-items:center; flex-shrink:0; }
.k-blue{ background:rgba(59,130,246,.15); color:#60a5fa; } .k-green{ background:rgba(34,197,94,.15); color:#4ade80; }
.k-amber{ background:rgba(245,158,11,.15); color:#fbbf24; } .k-red{ background:rgba(239,68,68,.15); color:#f87171; }
.ge-kpi b{ display:block; font-size:1.45rem; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.ge-kpi span{ font-size:.75rem; color:var(--ge-mut); }

/* Barra */
.ge-bar{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
.ge-search{ flex:1; min-width:220px; position:relative; }
.ge-search i{ position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--ge-mut); font-size:.8rem; }
.ge-search input{ width:100%; background:var(--ge-card); border:1px solid var(--ge-line); border-radius:10px; color:var(--ge-text); padding:.6rem 1rem .6rem 2.2rem; font-size:.85rem; outline:none; }
.ge-search input:focus{ border-color:var(--ge-gold); }
.ge-seg{ display:inline-flex; background:var(--ge-card); border:1px solid var(--ge-line); border-radius:10px; padding:3px; }
.ge-seg button{ background:none; border:0; color:var(--ge-mut); font-weight:700; font-size:.8rem; padding:.45rem .8rem; border-radius:7px; cursor:pointer; }
.ge-seg button[aria-pressed="true"]{ background:rgba(241,196,15,.15); color:var(--ge-gold); }
.ge-btn{ background:var(--ge-card); border:1px solid var(--ge-line); border-radius:10px; color:var(--ge-text); padding:.6rem .9rem; font-size:.8rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; }
.ge-btn:hover{ border-color:var(--ge-gold); color:var(--ge-gold); }
.ge-count{ font-size:.8rem; color:var(--ge-mut); margin:0 0 .6rem; }

/* Cartão da empresa */
.ge-co{ background:var(--ge-card); border:1px solid var(--ge-line); border-radius:14px; margin-bottom:.75rem; overflow:hidden; transition:border-color .2s; }
.ge-co:hover{ border-color:rgba(255,255,255,.14); }
.ge-co.is-on{ border-left:3px solid #16a34a; }
.ge-co.is-off .ge-head-main, .ge-co.is-off .ge-stats{ opacity:.62; }
.ge-head{ display:grid; grid-template-columns:auto minmax(0,1fr) auto auto auto; align-items:center; gap:1rem; padding:1rem 1.1rem; cursor:pointer; }
.ge-head:hover{ background:rgba(255,255,255,.025); }
.ge-head:focus-visible{ outline:2px solid #60a5fa; outline-offset:-2px; }
.ge-logo{ width:40px; height:40px; border-radius:10px; display:grid; place-items:center; font-weight:800; font-size:.85rem; color:var(--ge-gold);
  background:linear-gradient(135deg,rgba(241,196,15,.16),rgba(59,130,246,.10)); border:1px solid rgba(241,196,15,.22); }
.ge-name{ font-weight:800; font-size:.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ge-name small{ font-weight:500; color:var(--ge-mut); font-size:.72rem; margin-left:.25rem; }
.ge-meta{ display:flex; gap:.35rem .9rem; flex-wrap:wrap; font-size:.74rem; color:var(--ge-mut); margin-top:3px; }
.ge-meta span{ white-space:nowrap; } .ge-meta i{ font-size:.65rem; margin-right:.25rem; }
.ge-stats{ display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end; }
.ge-stat{ display:flex; flex-direction:column; align-items:flex-end; min-width:62px; padding:.3rem .55rem; border-radius:8px; background:rgba(255,255,255,.035); }
.ge-stat b{ font-size:.95rem; font-weight:800; font-variant-numeric:tabular-nums; line-height:1.15; }
.ge-stat b small{ font-weight:500; color:var(--ge-mut); font-size:.72rem; }
.ge-stat span{ font-size:.64rem; color:var(--ge-mut); text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
.ge-stat.late b{ color:#f87171; } .ge-stat.ok b{ color:#4ade80; }
.ge-chev{ color:var(--ge-mut); font-size:.75rem; transition:transform .2s; }
.ge-co.open .ge-chev{ transform:rotate(180deg); }

/* Chave */
.ge-sw{ display:inline-flex; align-items:center; gap:.5rem; cursor:pointer; user-select:none; font-weight:700; font-size:.78rem; min-width:88px; }
.ge-sw input{ position:absolute; opacity:0; width:1px; height:1px; }
.ge-sw .trk{ position:relative; width:38px; height:21px; border-radius:999px; background:#334155; transition:background .15s; flex-shrink:0; }
.ge-sw .trk::after{ content:""; position:absolute; top:3px; left:3px; width:15px; height:15px; border-radius:50%; background:#cbd5e1; transition:transform .15s; }
.ge-sw input:checked + .trk{ background:#16a34a; }
.ge-sw input:checked + .trk::after{ transform:translateX(17px); background:#fff; }
.ge-sw input:focus-visible + .trk{ outline:2px solid #60a5fa; outline-offset:2px; }
.ge-sw .on, .ge-sw input:checked ~ .off{ display:none; }
.ge-sw input:checked ~ .on{ display:inline; color:#4ade80; }
.ge-sw .off{ color:var(--ge-mut); }
.ge-sw.is-busy{ opacity:.5; pointer-events:none; }
.ge-sw.is-locked{ cursor:not-allowed; opacity:.5; }

/* Corpo */
.ge-body{ display:none; border-top:1px solid var(--ge-line); }
.ge-co.open .ge-body{ display:block; }
.ge-details{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:.75rem 1.25rem; padding:1rem 1.1rem; margin:0; background:rgba(255,255,255,.02); border-bottom:1px solid var(--ge-line); }
.ge-d dt{ font-size:.66rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ge-mut); margin:0 0 2px; }
.ge-d dd{ margin:0; font-size:.82rem; font-weight:600; overflow-wrap:anywhere; }
.ge-d.wide{ grid-column:1 / -1; }
.ge-d.wide dd{ font-weight:400; line-height:1.45; color:#d1d5db; }
.ge-ulist-h{ display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:.75rem 1.1rem .25rem; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ge-mut); font-weight:700; }
.ge-ulist-h a{ text-transform:none; letter-spacing:0; color:var(--ge-gold); font-weight:700; text-decoration:none; font-size:.78rem; }
.ge-ulist{ list-style:none; margin:0; padding:0 0 .4rem; }
.ge-user{ display:grid; grid-template-columns:32px minmax(0,1.6fr) minmax(0,1.2fr) 110px 86px; align-items:center; gap:.75rem; padding:.55rem 1.1rem; font-size:.82rem; border-top:1px solid rgba(255,255,255,.04); }
.ge-user:hover{ background:rgba(255,255,255,.03); }
.ge-user.is-off{ opacity:.55; }
.ge-av{ width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.08); display:grid; place-items:center; color:var(--ge-gold); font-size:.7rem; font-weight:800; overflow:hidden; }
.ge-av img{ width:100%; height:100%; object-fit:cover; }
.ge-uinfo{ min-width:0; display:flex; flex-direction:column; }
.ge-uname{ font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ge-umail{ font-size:.72rem; color:var(--ge-mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ge-tag{ font-size:.62rem; font-weight:700; color:#fbbf24; border:1px solid rgba(245,158,11,.4); border-radius:4px; padding:0 .3rem; margin-left:.25rem; vertical-align:1px; }
.ge-roles{ display:flex; gap:.3rem; flex-wrap:wrap; }
.ge-role{ font-size:.66rem; font-weight:700; padding:.12rem .45rem; border-radius:5px; border:1px solid; white-space:nowrap; }
.r-red{ background:rgba(239,68,68,.14); color:#fca5a5; border-color:rgba(239,68,68,.3); }
.r-purple{ background:rgba(168,85,247,.14); color:#d8b4fe; border-color:rgba(168,85,247,.3); }
.r-amber{ background:rgba(245,158,11,.14); color:#fcd34d; border-color:rgba(245,158,11,.3); }
.r-blue{ background:rgba(59,130,246,.14); color:#93c5fd; border-color:rgba(59,130,246,.3); }
.r-green{ background:rgba(34,197,94,.14); color:#86efac; border-color:rgba(34,197,94,.3); }
.r-gray{ background:rgba(255,255,255,.05); color:var(--ge-mut); border-color:var(--ge-line); }
.ge-uavisos{ display:flex; gap:.45rem; color:var(--ge-gold); font-size:.8rem; }
.ge-dim{ color:var(--ge-mut); font-size:.72rem; }
.ge-udate{ font-size:.72rem; color:var(--ge-mut); text-align:right; font-variant-numeric:tabular-nums; }
.ge-empty{ padding:1rem 1.1rem; color:var(--ge-mut); font-size:.82rem; font-style:italic; }
.ge-none{ padding:2rem 1rem; text-align:center; color:var(--ge-mut); background:var(--ge-card); border:1px dashed var(--ge-line); border-radius:14px; }

/* Sem empresa */
.ge-orphans{ margin-top:1.75rem; background:var(--ge-card); border:1px solid rgba(245,158,11,.25); border-radius:14px; overflow:hidden; }
.ge-orphans h2{ margin:0; padding:.9rem 1.1rem; font-size:.9rem; color:#fbbf24; display:flex; align-items:center; gap:.5rem; }
.ge-orphans p{ margin:-.4rem 0 .3rem; padding:0 1.1rem; font-size:.78rem; color:var(--ge-mut); }

.ge-alert{ background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.35); color:#fbbf24; border-radius:10px; padding:.75rem 1rem; margin-bottom:1rem; font-size:.85rem; }
.ge-toast{ position:fixed; bottom:20px; right:20px; z-index:3000; padding:12px 18px; border-radius:10px; font-weight:700; color:#eafff5; background:#0b7a44; box-shadow:0 10px 30px rgba(0,0,0,.25); max-width:420px; transition:opacity .4s; }
.ge-toast.warn{ background:#92400e; color:#fef3c7; }

/* Telas médias: números descem para a linha de baixo */
@media (max-width:1180px){
  .ge-head{ grid-template-columns:auto minmax(0,1fr) auto auto; }
  .ge-stats{ grid-column:2 / -1; grid-row:2; justify-content:flex-start; }
  .ge-user{ grid-template-columns:32px minmax(0,1fr) auto; }
  .ge-roles{ grid-column:2; } .ge-uavisos{ grid-column:3; grid-row:1; } .ge-udate{ display:none; }
}
@media (max-width:640px){
  .ge-head{ grid-template-columns:auto minmax(0,1fr) auto; gap:.6rem .75rem; }
  .ge-head .ge-sw{ grid-column:2; grid-row:2; }
  .ge-stats{ grid-column:1 / -1; grid-row:3; display:grid; grid-template-columns:repeat(3,1fr); }
  .ge-stat{ align-items:flex-start; min-width:0; }
  .ge-stat span{ white-space:normal; line-height:1.2; }
  .ge-chev{ grid-column:3; grid-row:1; }
  .ge-seg{ width:100%; } .ge-seg button{ flex:1; }
  .ge-user{ grid-template-columns:32px minmax(0,1fr); }
  .ge-roles, .ge-uavisos{ grid-column:2; grid-row:auto; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="ge">
    <div class="ge-eyebrow"><i class="fa-solid fa-user-shield"></i> Acesso Admin</div>
    <h1 class="ge-title">Gestão de Empresas</h1>
    <p class="ge-sub">Empresas clientes, seus usuários e o uso do OKR. Só empresas <b>ativas</b> recebem lembretes, relatórios de atraso e o resumo semanal. Desativar não bloqueia o acesso ao sistema.</p>

    <?php if (!$temAtivo): ?>
      <div class="ge-alert"><i class="fa-solid fa-triangle-exclamation"></i> A marcação de empresa ativa ainda não foi instalada (migração 013). A chave fica desabilitada até lá.</div>
    <?php endif; ?>

    <section class="ge-kpis" aria-label="Resumo">
      <div class="ge-kpi"><i class="fa-solid fa-building k-blue"></i><div><b><?= num_br($kpi['empresas']) ?></b><span>Empresas</span></div></div>
      <div class="ge-kpi"><i class="fa-solid fa-circle-check k-green"></i><div><b id="kpiAtivas"><?= num_br($kpi['ativas']) ?></b><span>Ativas</span></div></div>
      <div class="ge-kpi"><i class="fa-solid fa-users k-amber"></i><div><b><?= num_br($kpi['colab']) ?></b><span>Colaboradores ativos</span></div></div>
      <div class="ge-kpi"><i class="fa-solid fa-clock k-red"></i><div><b id="kpiAtraso"><?= num_br($kpi['atraso']) ?></b><span>Atrasos em empresas ativas</span></div></div>
      <?php if ($semEmpresa): ?>
      <div class="ge-kpi"><i class="fa-solid fa-user-slash k-amber"></i><div><b><?= num_br(count($semEmpresa)) ?></b><span>Usuários sem empresa</span></div></div>
      <?php endif; ?>
    </section>

    <div class="ge-bar">
      <label class="ge-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" id="geBusca" placeholder="Buscar empresa, CNPJ, cidade ou usuário" aria-label="Buscar empresa ou usuário" autocomplete="off">
      </label>
      <div class="ge-seg" role="group" aria-label="Filtrar por situação">
        <button type="button" data-f="todas" aria-pressed="true">Todas</button>
        <button type="button" data-f="ativas" aria-pressed="false">Ativas</button>
        <button type="button" data-f="inativas" aria-pressed="false">Inativas</button>
      </div>
      <button type="button" class="ge-btn" id="geExpandir"><i class="fa-solid fa-angles-down"></i><span>Expandir todas</span></button>
    </div>
    <p class="ge-count" id="geCount" aria-live="polite"></p>

    <div id="geLista">
    <?php foreach ($empresas as $cid => $e):
      $ativa  = (int)$e['ativo'] === 1;
      $nome   = $e['organizacao'] ?: ($e['razao_social'] ?: "Empresa #$cid");
      $local  = trim(($e['municipio'] ?? '') . ($e['uf'] ? '/' . $e['uf'] : ''), '/');
      $atraso = $e['atraso_marcos'] + $e['atraso_inis'];
      $sigla  = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $nome), 0, 2));
      $busca  = mb_strtolower($nome . ' ' . $e['razao_social'] . ' ' . preg_replace('/\D/', '', (string)$e['cnpj']) . ' ' . $local . ' #' . $cid);
      $bodyId = 'ge-body-' . $cid;
    ?>
      <article class="ge-co <?= $ativa ? 'is-on' : 'is-off' ?>" data-busca="<?= h($busca) ?>" data-ativa="<?= $ativa ? '1' : '0' ?>" data-atraso="<?= $atraso ?>">
        <div class="ge-head" role="button" tabindex="0" aria-expanded="false" aria-controls="<?= $bodyId ?>">
          <span class="ge-logo" aria-hidden="true"><?= h($sigla) ?></span>
          <div class="ge-head-main">
            <div class="ge-name"><?= h($nome) ?><small>#<?= $cid ?></small></div>
            <div class="ge-meta">
              <?php if ($e['cnpj']): ?><span><i class="fa-regular fa-id-card"></i><?= h(ge_cnpj($e['cnpj'])) ?></span><?php endif; ?>
              <?php if ($local): ?><span><i class="fa-solid fa-location-dot"></i><?= h($local) ?></span><?php endif; ?>
              <span><i class="fa-regular fa-calendar"></i>desde <?= ge_data($e['created_at']) ?></span>
            </div>
          </div>
          <label class="ge-sw<?= $temAtivo ? '' : ' is-locked' ?>" title="<?= $e['ativo_alterado_em'] ? 'Alterado em ' . h(date('d/m/Y H:i', strtotime($e['ativo_alterado_em']))) : 'Ativa = recebe avisos de pendência' ?>">
            <input type="checkbox" class="ge-toggle" data-id="<?= $cid ?>" data-nome="<?= h($nome) ?>"
              <?= $ativa ? 'checked' : '' ?> <?= $temAtivo ? '' : 'disabled' ?> aria-label="Empresa ativa: <?= h($nome) ?>">
            <span class="trk" aria-hidden="true"></span><span class="on">Ativa</span><span class="off">Inativa</span>
          </label>
          <div class="ge-stats">
            <div class="ge-stat" title="Colaboradores ativos / cadastrados"><b><?= num_br($e['usr_ativos']) ?><small>/<?= num_br($e['usr_total']) ?></small></b><span>Pessoas</span></div>
            <div class="ge-stat" title="<?= num_br($e['objetivos']) ?> objetivos; <?= num_br($e['krs']) ?> KRs no total"><b><?= num_br($e['krs_andamento']) ?><small>/<?= num_br($e['krs']) ?></small></b><span>KRs ativos</span></div>
            <div class="ge-stat"><b><?= num_br($e['iniciativas']) ?></b><span>Iniciativas</span></div>
            <div class="ge-stat <?= $atraso > 0 ? 'late' : 'ok' ?>" title="<?= num_br($e['atraso_marcos']) ?> marcos sem apontamento + <?= num_br($e['atraso_inis']) ?> iniciativas vencidas"><b><?= num_br($atraso) ?></b><span>Em atraso</span></div>
            <div class="ge-stat" title="Colaboradores ativos com algum aviso ligado"><b><?= num_br($e['usr_avisos']) ?></b><span>Com avisos</span></div>
            <div class="ge-stat" title="<?= $e['ult_apont'] ? 'Último apontamento em ' . h(date('d/m/Y H:i', strtotime($e['ult_apont']))) : 'Nenhum apontamento' ?>"><b style="font-size:.8rem"><?= ge_rel($e['ult_apont']) ?></b><span>Últ. apontamento</span></div>
          </div>
          <i class="fa-solid fa-chevron-down ge-chev" aria-hidden="true"></i>
        </div>

        <div class="ge-body" id="<?= $bodyId ?>">
          <dl class="ge-details">
            <div class="ge-d"><dt>Razão social</dt><dd><?= h($e['razao_social'] ?: '—') ?></dd></div>
            <div class="ge-d"><dt>E-mail</dt><dd><?= h($e['email'] ?: '—') ?></dd></div>
            <div class="ge-d"><dt>Telefone</dt><dd><?= h($e['telefone'] ?: '—') ?></dd></div>
            <div class="ge-d"><dt>Objetivos</dt><dd><?= num_br($e['objetivos']) ?></dd></div>
            <div class="ge-d"><dt>Situação alterada em</dt><dd><?= $e['ativo_alterado_em'] ? h(date('d/m/Y H:i', strtotime($e['ativo_alterado_em']))) : '—' ?></dd></div>
            <?php if ($e['missao']): ?><div class="ge-d wide"><dt>Missão</dt><dd><?= h($e['missao']) ?></dd></div><?php endif; ?>
            <?php if ($e['visao']): ?><div class="ge-d wide"><dt>Visão</dt><dd><?= h($e['visao']) ?></dd></div><?php endif; ?>
          </dl>
          <div class="ge-ulist-h">
            <span>Usuários (<?= num_br($e['usr_total']) ?>)</span>
            <a href="/OKR_system/views/usuarios.php">Gerenciar usuários <i class="fa-solid fa-arrow-right"></i></a>
          </div>
          <?php if (!$e['usuarios']): ?>
            <div class="ge-empty">Nenhum usuário vinculado a esta empresa.</div>
          <?php else: ?>
            <ul class="ge-ulist"><?php foreach ($e['usuarios'] as $u) echo ge_usuario($u); ?></ul>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <div class="ge-none" id="geVazio" hidden><i class="fa-regular fa-folder-open"></i> Nenhuma empresa ou usuário com esses filtros.</div>

    <?php if ($semEmpresa): ?>
    <section class="ge-orphans" id="geOrfaos">
      <h2><i class="fa-solid fa-triangle-exclamation"></i> Usuários sem empresa (<?= num_br(count($semEmpresa)) ?>)</h2>
      <p>Estes usuários não estão vinculados a nenhuma empresa e não aparecem nos relatórios.</p>
      <ul class="ge-ulist"><?php foreach ($semEmpresa as $u) echo ge_usuario($u); ?></ul>
    </section>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </main>
</div>

<script>
(() => {
  const CSRF = <?= json_encode($csrf) ?>;
  const lista = document.getElementById('geLista');
  const cards = [...lista.querySelectorAll('.ge-co')];
  const busca = document.getElementById('geBusca');
  const count = document.getElementById('geCount');
  const vazio = document.getElementById('geVazio');
  const orfaos = document.getElementById('geOrfaos');
  const btnExp = document.getElementById('geExpandir');
  let filtro = 'todas';

  const fmt = n => Number(n).toLocaleString('pt-BR');

  function toast(msg, warn){
    const d = document.createElement('div');
    d.className = 'ge-toast' + (warn ? ' warn' : '');
    d.setAttribute('role', 'status');
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; }, warn ? 4000 : 2200);
    setTimeout(() => d.remove(), warn ? 4600 : 2800);
  }

  function abrir(card, sim){
    card.classList.toggle('open', sim);
    card.querySelector('.ge-head').setAttribute('aria-expanded', sim ? 'true' : 'false');
  }

  function aplicar(){
    const q = busca.value.trim().toLowerCase();
    const qd = q.replace(/\D/g, '');
    let vis = 0;
    cards.forEach(card => {
      const ativa = card.dataset.ativa === '1';
      const okFiltro = filtro === 'todas' || (filtro === 'ativas' ? ativa : !ativa);
      const okEmpresa = !q || card.dataset.busca.includes(q) || (qd.length >= 3 && card.dataset.busca.includes(qd));
      let achouUser = false;
      card.querySelectorAll('.ge-user').forEach(li => {
        const m = !q || okEmpresa || li.dataset.busca.includes(q);
        li.hidden = !m;
        if (q && li.dataset.busca.includes(q)) achouUser = true;
      });
      const mostra = okFiltro && (okEmpresa || achouUser);
      card.hidden = !mostra;
      if (mostra) vis++;
      if (q && achouUser && !okEmpresa) abrir(card, true);
    });
    if (orfaos) {
      let n = 0;
      orfaos.querySelectorAll('.ge-user').forEach(li => { li.hidden = !!q && !li.dataset.busca.includes(q); if (!li.hidden) n++; });
      orfaos.hidden = filtro !== 'todas' || n === 0;
    }
    vazio.hidden = vis > 0;
    count.textContent = `${vis} de ${cards.length} empresa${cards.length === 1 ? '' : 's'}`;
  }

  let t;
  busca.addEventListener('input', () => { clearTimeout(t); t = setTimeout(aplicar, 150); });
  document.querySelectorAll('.ge-seg button').forEach(b => b.addEventListener('click', () => {
    filtro = b.dataset.f;
    document.querySelectorAll('.ge-seg button').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
    aplicar();
  }));

  btnExp.addEventListener('click', () => {
    const abrirTodas = !cards.filter(c => !c.hidden).every(c => c.classList.contains('open'));
    cards.forEach(c => { if (!c.hidden) abrir(c, abrirTodas); });
    btnExp.querySelector('i').className = abrirTodas ? 'fa-solid fa-angles-up' : 'fa-solid fa-angles-down';
    btnExp.querySelector('span').textContent = abrirTodas ? 'Recolher todas' : 'Expandir todas';
  });

  lista.addEventListener('click', ev => {
    if (ev.target.closest('.ge-sw')) return; // a chave não abre/fecha o cartão
    const head = ev.target.closest('.ge-head');
    if (head) abrir(head.parentElement, !head.parentElement.classList.contains('open'));
  });
  lista.addEventListener('keydown', ev => {
    const head = ev.target.closest('.ge-head');
    if (!head || ev.target !== head || (ev.key !== 'Enter' && ev.key !== ' ')) return;
    ev.preventDefault();
    abrir(head.parentElement, !head.parentElement.classList.contains('open'));
  });

  lista.addEventListener('change', async ev => {
    const inp = ev.target.closest('.ge-toggle');
    if (!inp) return;
    const valor = inp.checked;
    const card = inp.closest('.ge-co');
    const lbl = inp.closest('.ge-sw');
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
      card.dataset.ativa = valor ? '1' : '0';
      card.classList.toggle('is-on', valor);
      card.classList.toggle('is-off', !valor);
      document.getElementById('kpiAtivas').textContent = fmt(j.total_ativas);
      const atraso = cards.filter(c => c.dataset.ativa === '1').reduce((s, c) => s + Number(c.dataset.atraso), 0);
      document.getElementById('kpiAtraso').textContent = fmt(atraso);
      toast(`${inp.dataset.nome} ${valor ? 'ativada: passa a receber avisos' : 'desativada: não recebe mais avisos'}`);
      aplicar();
    } catch (e) {
      inp.checked = !valor;
      toast(e.message || 'Não foi possível salvar', true);
    } finally {
      lbl.classList.remove('is-busy');
    }
  });

  aplicar();
})();
</script>
</body>
</html>
