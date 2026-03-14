<?php
/**
 * Empresas & Usuarios — Painel Administrativo
 * Acesso restrito a gestor_master.
 * Consulta de companies com usuarios vinculados.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// Verifica gestor_master
$_pdo_check = pdo_conn();
$_stRole = $_pdo_check->prepare("
  SELECT 1 FROM rbac_user_role ur
    JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
   WHERE ur.user_id = :uid AND r.role_key = 'gestor_master'
   LIMIT 1
");
$_stRole->execute([':uid' => (int)$_SESSION['user_id']]);
if (!$_stRole->fetchColumn()) {
  deny_with_modal('Acesso restrito a administradores do sistema.');
}

$pdo = new PDO(
  "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Busca companies com contagem de usuarios e OKRs
$companies = $pdo->query("
  SELECT c.id_company, c.organizacao, c.cnpj, c.municipio, c.uf,
         c.email, c.telefone, c.missao, c.visao, c.created_at,
         COUNT(DISTINCT u.id_user) AS total_usuarios,
         COUNT(DISTINCT o.id_objetivo) AS total_objetivos
    FROM company c
    LEFT JOIN usuarios u ON u.id_company = c.id_company
    LEFT JOIN objetivos o ON o.id_company = c.id_company
   GROUP BY c.id_company
   ORDER BY c.organizacao ASC
")->fetchAll();

// Busca todos os usuarios com role e company
$users = $pdo->query("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo,
         u.id_company, u.dt_cadastro,
         GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ', ') AS roles
    FROM usuarios u
    LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
    LEFT JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
   GROUP BY u.id_user
   ORDER BY u.primeiro_nome ASC
")->fetchAll();

// Agrupa usuarios por company
$usersByCompany = [];
$orphanUsers = [];
foreach ($users as $u) {
  $cid = (int)($u['id_company'] ?? 0);
  if ($cid > 0) {
    $usersByCompany[$cid][] = $u;
  } else {
    $orphanUsers[] = $u;
  }
}

$totalCompanies = count($companies);
$totalUsers = count($users);
$totalOrphans = count($orphanUsers);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatCnpj(?string $cnpj): string {
  if (!$cnpj) return '—';
  $d = preg_replace('/\D/', '', $cnpj);
  if (strlen($d) !== 14) return h($cnpj);
  return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
}
function roleBadge(string $role): string {
  $colors = [
    'admin_master' => 'background:rgba(239,68,68,.15);color:#f87171;border-color:rgba(239,68,68,.3)',
    'user_admin'   => 'background:rgba(168,85,247,.15);color:#c084fc;border-color:rgba(168,85,247,.3)',
    'user_gestor'  => 'background:rgba(59,130,246,.15);color:#93c5fd;border-color:rgba(59,130,246,.3)',
    'user_colab'   => 'background:rgba(34,197,94,.15);color:#86efac;border-color:rgba(34,197,94,.3)',
  ];
  $style = $colors[trim($role)] ?? 'background:rgba(255,255,255,.08);color:var(--muted);border-color:var(--border)';
  return '<span class="ac-role-badge" style="'.$style.'">'.h(trim($role)).'</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Empresas & Usuarios – Admin – OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

<style>
.main-wrapper {
  padding: 2rem 2rem 2rem 1.5rem;
  margin-right: var(--chat-w, 0);
  transition: margin-right .25s ease;
}
@media (max-width: 991px) { .main-wrapper { padding: 1rem; } }

/* Page header */
.ac-page-title {
  font-size: 1.5rem; font-weight: 800;
  color: var(--gold, #F1C40F);
  margin-bottom: .25rem;
}
.ac-page-subtitle {
  font-size: .85rem; color: var(--text-secondary, #aaa);
  margin-bottom: 1.5rem;
}

/* KPI cards */
.ac-kpis {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: .75rem; margin-bottom: 1.5rem;
}
.ac-kpi {
  background: var(--card, #1a1f2b); border: 1px solid var(--border, #2a2f3b);
  border-radius: 12px; padding: 1rem 1.25rem;
  display: flex; align-items: center; gap: .75rem;
}
.ac-kpi-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.ac-kpi-icon.blue { background: rgba(59,130,246,.15); color: #60a5fa; }
.ac-kpi-icon.green { background: rgba(34,197,94,.15); color: #4ade80; }
.ac-kpi-icon.amber { background: rgba(245,158,11,.15); color: #fbbf24; }
.ac-kpi-icon.red { background: rgba(239,68,68,.15); color: #f87171; }
.ac-kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text, #eee); line-height: 1; }
.ac-kpi-label { font-size: .75rem; color: var(--text-secondary, #aaa); margin-top: 2px; }

/* Search */
.ac-search-bar {
  display: flex; gap: .5rem; margin-bottom: 1.25rem; flex-wrap: wrap;
}
.ac-search {
  flex: 1; min-width: 200px;
  background: var(--card, #1a1f2b); border: 1px solid var(--border, #2a2f3b);
  border-radius: 10px; color: var(--text, #eee); padding: .6rem 1rem;
  font-size: .85rem; outline: none;
}
.ac-search:focus { border-color: var(--gold, #F1C40F); }
.ac-search::placeholder { color: var(--text-secondary, #aaa); }
.ac-btn-expand {
  background: var(--card, #1a1f2b); border: 1px solid var(--border, #2a2f3b);
  border-radius: 10px; color: var(--text, #eee); padding: .6rem 1rem;
  font-size: .8rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: .4rem;
}
.ac-btn-expand:hover { border-color: var(--gold, #F1C40F); color: var(--gold, #F1C40F); }

/* Section */
.ac-section {
  font-size: 1rem; font-weight: 700; color: var(--text, #eee);
  margin: 1.25rem 0 .75rem; display: flex; align-items: center; gap: .5rem;
}
.ac-section i { color: var(--gold, #F1C40F); font-size: .85rem; }
.ac-section .ac-count {
  font-size: .75rem; font-weight: 400; color: var(--text-secondary, #aaa);
  margin-left: .25rem;
}

/* Company card (accordion) */
.ac-company {
  background: var(--card, #1a1f2b); border: 1px solid var(--border, #2a2f3b);
  border-radius: 12px; margin-bottom: .75rem; overflow: hidden;
  transition: border-color .2s;
}
.ac-company:hover { border-color: rgba(255,255,255,.15); }
.ac-company-header {
  display: flex; align-items: center; gap: .75rem;
  padding: 1rem 1.25rem; cursor: pointer; user-select: none;
}
.ac-company-header:hover { background: rgba(255,255,255,.03); }
.ac-company-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(246,195,67,.15), rgba(59,130,246,.10));
  border: 1px solid rgba(246,195,67,.2); color: var(--gold, #F1C40F);
  font-size: .85rem; flex-shrink: 0;
}
.ac-company-info { flex: 1; min-width: 0; }
.ac-company-name {
  font-weight: 700; font-size: .9rem; color: var(--text, #eee);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ac-company-meta {
  display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 2px;
  font-size: .75rem; color: var(--text-secondary, #aaa);
}
.ac-company-meta span { display: flex; align-items: center; gap: .3rem; }
.ac-company-meta i { font-size: .65rem; }
.ac-company-stats {
  display: flex; gap: .75rem; flex-shrink: 0;
}
.ac-stat {
  display: flex; align-items: center; gap: .3rem;
  font-size: .75rem; color: var(--text-secondary, #aaa);
  background: rgba(255,255,255,.04); border-radius: 6px; padding: .25rem .5rem;
}
.ac-stat i { font-size: .65rem; }
.ac-stat strong { color: var(--text, #eee); font-weight: 700; }
.ac-chevron {
  color: var(--text-secondary, #aaa); font-size: .7rem;
  transition: transform .2s; flex-shrink: 0;
}
.ac-company.open .ac-chevron { transform: rotate(180deg); }

/* Company body (users list) */
.ac-company-body {
  display: none; border-top: 1px solid var(--border, #2a2f3b);
  padding: 0;
}
.ac-company.open .ac-company-body { display: block; }

/* Company detail row */
.ac-company-detail {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: .5rem; padding: .75rem 1.25rem;
  background: rgba(255,255,255,.02); border-bottom: 1px solid var(--border, #2a2f3b);
}
.ac-detail-item { font-size: .78rem; }
.ac-detail-label { color: var(--text-secondary, #aaa); font-size: .7rem; display: block; }
.ac-detail-value { color: var(--text, #eee); font-weight: 600; }

/* User rows */
.ac-user-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .6rem 1.25rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: .82rem;
}
.ac-user-row:last-child { border-bottom: none; }
.ac-user-row:hover { background: rgba(255,255,255,.03); }
.ac-user-avatar {
  width: 28px; height: 28px; border-radius: 50%;
  background: rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center;
  color: var(--gold, #F1C40F); font-size: .7rem; font-weight: 700; flex-shrink: 0;
}
.ac-user-info { flex: 1; min-width: 0; }
.ac-user-name {
  font-weight: 600; color: var(--text, #eee);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ac-user-email {
  font-size: .72rem; color: var(--text-secondary, #aaa);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ac-user-roles { display: flex; gap: .3rem; flex-wrap: wrap; flex-shrink: 0; }
.ac-role-badge {
  font-size: .65rem; font-weight: 600; padding: .15rem .45rem;
  border-radius: 4px; border: 1px solid; white-space: nowrap;
}
.ac-user-date {
  font-size: .7rem; color: var(--text-secondary, #aaa); flex-shrink: 0;
  white-space: nowrap;
}
.ac-no-users {
  padding: .75rem 1.25rem; font-size: .8rem;
  color: var(--text-secondary, #aaa); font-style: italic;
}

/* Orphan section */
.ac-orphan-card {
  background: var(--card, #1a1f2b); border: 1px solid rgba(245,158,11,.2);
  border-radius: 12px; padding: 1rem 1.25rem;
}
.ac-orphan-title {
  font-weight: 700; font-size: .9rem; color: #fbbf24;
  display: flex; align-items: center; gap: .5rem; margin-bottom: .75rem;
}

/* Responsive */
@media (max-width: 768px) {
  .ac-company-header { flex-wrap: wrap; }
  .ac-company-stats { width: 100%; justify-content: flex-start; }
  .ac-user-row { flex-wrap: wrap; gap: .4rem; }
  .ac-user-roles { width: 100%; }
  .ac-user-date { width: 100%; }
  .ac-company-detail { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
  .ac-company-detail { grid-template-columns: 1fr; }
  .ac-kpis { grid-template-columns: 1fr 1fr; }
}

/* Empty state */
.ac-empty {
  text-align: center; padding: 3rem 1rem;
  color: var(--text-secondary, #aaa); font-size: .9rem;
}
.ac-empty i { font-size: 2rem; color: var(--border, #2a2f3b); margin-bottom: .5rem; display: block; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="main-wrapper">
    <h1 class="ac-page-title">Empresas & Usuarios</h1>
    <p class="ac-page-subtitle">Visao consolidada de todas as organizacoes e seus usuarios vinculados</p>

    <!-- KPIs -->
    <div class="ac-kpis">
      <div class="ac-kpi">
        <div class="ac-kpi-icon blue"><i class="fas fa-building"></i></div>
        <div>
          <div class="ac-kpi-value"><?= $totalCompanies ?></div>
          <div class="ac-kpi-label">Empresas</div>
        </div>
      </div>
      <div class="ac-kpi">
        <div class="ac-kpi-icon green"><i class="fas fa-users"></i></div>
        <div>
          <div class="ac-kpi-value"><?= $totalUsers ?></div>
          <div class="ac-kpi-label">Usuarios</div>
        </div>
      </div>
      <div class="ac-kpi">
        <div class="ac-kpi-icon amber"><i class="fas fa-crosshairs"></i></div>
        <div>
          <div class="ac-kpi-value"><?= array_sum(array_column($companies, 'total_objetivos')) ?></div>
          <div class="ac-kpi-label">Objetivos (total)</div>
        </div>
      </div>
      <?php if ($totalOrphans > 0): ?>
      <div class="ac-kpi">
        <div class="ac-kpi-icon red"><i class="fas fa-user-slash"></i></div>
        <div>
          <div class="ac-kpi-value"><?= $totalOrphans ?></div>
          <div class="ac-kpi-label">Sem empresa</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Search & actions -->
    <div class="ac-search-bar">
      <input type="text" class="ac-search" id="searchInput"
             placeholder="Buscar empresa ou usuario..." autocomplete="off">
      <button class="ac-btn-expand" id="btnToggleAll" onclick="toggleAll()">
        <i class="fas fa-angles-down"></i> <span>Expandir todos</span>
      </button>
    </div>

    <!-- Companies section -->
    <div class="ac-section">
      <i class="fas fa-building"></i> Organizacoes
      <span class="ac-count">(<?= $totalCompanies ?>)</span>
    </div>

    <div id="companiesList">
    <?php if (empty($companies)): ?>
      <div class="ac-empty">
        <i class="fas fa-inbox"></i>
        Nenhuma empresa cadastrada.
      </div>
    <?php else: ?>
      <?php foreach ($companies as $c):
        $cid = (int)$c['id_company'];
        $cUsers = $usersByCompany[$cid] ?? [];
      ?>
      <div class="ac-company" data-search="<?= h(strtolower($c['organizacao'].' '.$c['cnpj'].' '.$c['municipio'].' '.$c['uf'])) ?>">
        <div class="ac-company-header" onclick="this.parentElement.classList.toggle('open')">
          <div class="ac-company-icon"><i class="fas fa-building"></i></div>
          <div class="ac-company-info">
            <div class="ac-company-name"><?= h($c['organizacao']) ?></div>
            <div class="ac-company-meta">
              <?php if ($c['cnpj']): ?>
              <span><i class="fas fa-id-card"></i> <?= formatCnpj($c['cnpj']) ?></span>
              <?php endif; ?>
              <?php if ($c['municipio'] || $c['uf']): ?>
              <span><i class="fas fa-map-marker-alt"></i> <?= h(trim(($c['municipio'] ?? '').' / '.($c['uf'] ?? ''), ' /')) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-calendar"></i> <?= $c['created_at'] ? date('d/m/Y', strtotime($c['created_at'])) : '—' ?></span>
            </div>
          </div>
          <div class="ac-company-stats">
            <div class="ac-stat"><i class="fas fa-users"></i> <strong><?= (int)$c['total_usuarios'] ?></strong> usuarios</div>
            <div class="ac-stat"><i class="fas fa-crosshairs"></i> <strong><?= (int)$c['total_objetivos'] ?></strong> OKRs</div>
          </div>
          <i class="fas fa-chevron-down ac-chevron"></i>
        </div>
        <div class="ac-company-body">
          <!-- Company details -->
          <div class="ac-company-detail">
            <div class="ac-detail-item">
              <span class="ac-detail-label">ID</span>
              <span class="ac-detail-value">#<?= $cid ?></span>
            </div>
            <div class="ac-detail-item">
              <span class="ac-detail-label">E-mail</span>
              <span class="ac-detail-value"><?= h($c['email'] ?: '—') ?></span>
            </div>
            <div class="ac-detail-item">
              <span class="ac-detail-label">Telefone</span>
              <span class="ac-detail-value"><?= h($c['telefone'] ?: '—') ?></span>
            </div>
            <div class="ac-detail-item">
              <span class="ac-detail-label">Missao</span>
              <span class="ac-detail-value"><?= h($c['missao'] ? mb_substr($c['missao'], 0, 80).(mb_strlen($c['missao']) > 80 ? '...' : '') : '—') ?></span>
            </div>
            <div class="ac-detail-item">
              <span class="ac-detail-label">Visao</span>
              <span class="ac-detail-value"><?= h($c['visao'] ? mb_substr($c['visao'], 0, 80).(mb_strlen($c['visao']) > 80 ? '...' : '') : '—') ?></span>
            </div>
          </div>
          <!-- Users -->
          <?php if (empty($cUsers)): ?>
            <div class="ac-no-users">Nenhum usuario vinculado a esta empresa.</div>
          <?php else: ?>
            <?php foreach ($cUsers as $u):
              $initials = strtoupper(mb_substr($u['primeiro_nome'],0,1).mb_substr($u['ultimo_nome'] ?? '',0,1));
              $roles = $u['roles'] ? explode(', ', $u['roles']) : [];
            ?>
            <div class="ac-user-row" data-search="<?= h(strtolower($u['primeiro_nome'].' '.$u['ultimo_nome'].' '.$u['email_corporativo'])) ?>">
              <div class="ac-user-avatar"><?= h($initials) ?></div>
              <div class="ac-user-info">
                <div class="ac-user-name"><?= h($u['primeiro_nome'].' '.($u['ultimo_nome'] ?? '')) ?></div>
                <div class="ac-user-email"><?= h($u['email_corporativo']) ?></div>
              </div>
              <div class="ac-user-roles">
                <?php foreach ($roles as $r): echo roleBadge($r); endforeach; ?>
                <?php if (empty($roles)): ?>
                  <span class="ac-role-badge" style="background:rgba(255,255,255,.05);color:var(--text-secondary,#aaa);border-color:var(--border)">sem role</span>
                <?php endif; ?>
              </div>
              <div class="ac-user-date"><?= $u['dt_cadastro'] ? date('d/m/Y', strtotime($u['dt_cadastro'])) : '—' ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- Orphan users -->
    <?php if (!empty($orphanUsers)): ?>
    <div class="ac-section" style="margin-top:1.5rem">
      <i class="fas fa-user-slash"></i> Usuarios sem empresa
      <span class="ac-count">(<?= $totalOrphans ?>)</span>
    </div>
    <div class="ac-orphan-card">
      <div class="ac-orphan-title">
        <i class="fas fa-exclamation-triangle"></i> Estes usuarios nao estao vinculados a nenhuma empresa
      </div>
      <?php foreach ($orphanUsers as $u):
        $initials = strtoupper(mb_substr($u['primeiro_nome'],0,1).mb_substr($u['ultimo_nome'] ?? '',0,1));
        $roles = $u['roles'] ? explode(', ', $u['roles']) : [];
      ?>
      <div class="ac-user-row" data-search="<?= h(strtolower($u['primeiro_nome'].' '.$u['ultimo_nome'].' '.$u['email_corporativo'])) ?>">
        <div class="ac-user-avatar"><?= h($initials) ?></div>
        <div class="ac-user-info">
          <div class="ac-user-name"><?= h($u['primeiro_nome'].' '.($u['ultimo_nome'] ?? '')) ?></div>
          <div class="ac-user-email"><?= h($u['email_corporativo']) ?></div>
        </div>
        <div class="ac-user-roles">
          <?php foreach ($roles as $r): echo roleBadge($r); endforeach; ?>
          <?php if (empty($roles)): ?>
            <span class="ac-role-badge" style="background:rgba(255,255,255,.05);color:var(--text-secondary,#aaa);border-color:var(--border)">sem role</span>
          <?php endif; ?>
        </div>
        <div class="ac-user-date"><?= $u['dt_cadastro'] ? date('d/m/Y', strtotime($u['dt_cadastro'])) : '—' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/chat.php'; ?>
  </main>
</div>

<script>
// Search filter
const searchInput = document.getElementById('searchInput');
let debounceTimer;

searchInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(filterItems, 200);
});

function filterItems() {
  const q = searchInput.value.trim().toLowerCase();
  // Filter companies
  document.querySelectorAll('.ac-company').forEach(card => {
    const companyMatch = !q || card.dataset.search.includes(q);
    const userRows = card.querySelectorAll('.ac-user-row');
    let anyUserMatch = false;

    userRows.forEach(row => {
      const match = !q || row.dataset.search.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) anyUserMatch = true;
    });

    if (companyMatch || anyUserMatch) {
      card.style.display = '';
      // Auto-expand if searching and has user matches
      if (q && anyUserMatch) card.classList.add('open');
    } else {
      card.style.display = 'none';
    }
  });

  // Filter orphan users
  document.querySelectorAll('.ac-orphan-card .ac-user-row').forEach(row => {
    const match = !q || row.dataset.search.includes(q);
    row.style.display = match ? '' : 'none';
  });
}

// Toggle all
let allExpanded = false;
function toggleAll() {
  allExpanded = !allExpanded;
  document.querySelectorAll('.ac-company').forEach(c => {
    c.classList.toggle('open', allExpanded);
  });
  const btn = document.getElementById('btnToggleAll');
  btn.querySelector('i').className = allExpanded ? 'fas fa-angles-up' : 'fas fa-angles-down';
  btn.querySelector('span').textContent = allExpanded ? 'Recolher todos' : 'Expandir todos';
}
</script>
</body>
</html>
