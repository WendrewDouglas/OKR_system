<?php
// partials/sidebar.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ============ INJETAR O TEMA (uma vez por página) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  // Se quiser forçar recarregar em testes, acrescente ?nocache=1
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

/* ===================== ROTAS ATIVAS ===================== */
$currentPath        = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isDashboard        = ($currentPath === '/OKR_system/dashboard');
$isMapaEstrategico  = in_array($currentPath, ['/OKR_system/mapa_estrategico','/OKR_system/views/mapa_estrategico.php']);
$isMyOKRs           = ($currentPath === '/OKR_system/meus_okrs');
$isNewObjective     = ($currentPath === '/OKR_system/novo_objetivo');
$isNewKR            = ($currentPath === '/OKR_system/novo_key_result');
$isMatrizPrioridade = ($currentPath === '/OKR_system/matriz_prioridade');
$isOrcamento        = ($currentPath === '/OKR_system/orcamento');
$isAprovacao        = ($currentPath === '/OKR_system/aprovacao');
$isOKRGroup         = ($isMyOKRs || $isNewObjective || $isNewKR);
$isReports          = in_array($currentPath, ['/OKR_system/views/rel_vendas.php','/OKR_system/views/rel_desempenho.php']);
$isConfigStyle      = in_array($currentPath, ['/OKR_system/views/config_style.php','/OKR_system/config_style']);
$isOrgConfig        = in_array($currentPath, ['/OKR_system/views/organizacao.php','/OKR_system/organizacao','/OKR_system/views/configuracoes.php']);
$isUsersMgmt        = in_array($currentPath, ['/OKR_system/views/usuarios.php','/OKR_system/usuarios']);
$isSettings         = ($isConfigStyle || $isOrgConfig || $isUsersMgmt);
$newMessages        = $_SESSION['new_messages'] ?? 0;

/* ===================== DADOS DE USUÁRIO/ORG ===================== */
$firstName = trim((string)($_SESSION['primeiro_nome'] ?? $_SESSION['first_name'] ?? ''));
$lastName  = trim((string)($_SESSION['ultimo_nome']   ?? $_SESSION['last_name']  ?? ''));
$fullName  = trim((string)($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? ''));

$userId    = $_SESSION['id_user'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$companyId = $_SESSION['company_id'] ?? $_SESSION['id_company'] ?? null;
$orgName   = $_SESSION['company_name'] ?? $_SESSION['organizacao'] ?? $_SESSION['org_name'] ?? null;

/* === carrega config.php de forma resiliente (sem fatal se não encontrar) === */
$cfgLoaded = false;
$cfgCandidates = [
  __DIR__.'/../../auth/config.php',
  __DIR__.'/../auth/config.php',
  __DIR__.'/../../../auth/config.php',
];
foreach ($cfgCandidates as $cfg) {
  if ($cfg && file_exists($cfg)) { require_once $cfg; $cfgLoaded = true; break; }
}

/* === abre conexão só se config carregou e precisarmos do DB === */
$pdo = null;
if ($cfgLoaded) {
  try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
  } catch (Throwable $e) { $pdo = null; }
}

/* --- completa nome e company via DB quando faltar --- */
if ($pdo && ($firstName === '' || $lastName === '' || !$companyId)) {
  $userTables = [
    ['table'=>'users',    'id'=>'id_user'],
    ['table'=>'usuarios', 'id'=>'id_user'],
    ['table'=>'usuario',  'id'=>'id_user'],
    ['table'=>'tb_users', 'id'=>'id_user'],
  ];
  foreach ($userTables as $t) {
    try {
      if ($userId) {
        $st = $pdo->prepare("SELECT primeiro_nome, ultimo_nome, id_company FROM {$t['table']} WHERE {$t['id']} = :id LIMIT 1");
        $st->execute([':id'=>$userId]);
        if ($row = $st->fetch()) {
          if ($firstName === '' && !empty($row['primeiro_nome'])) $firstName = trim((string)$row['primeiro_nome']);
          if ($lastName  === '' && !empty($row['ultimo_nome']))   $lastName  = trim((string)$row['ultimo_nome']);
          if (!$companyId && !empty($row['id_company']))          $companyId = (int)$row['id_company'];
          break;
        }
      }
    } catch (Throwable $e) { /* tenta próxima */ }
  }
}

/* --- monta nome curto sem duplicar --- */
if ($firstName === '' && $fullName !== '') {
  $parts = preg_split('/\s+/', $fullName);
  $firstName = $parts[0] ?? 'Usuário';
  $lastName  = (count($parts) > 1) ? $parts[count($parts)-1] : '';
}
$userShort = trim($firstName . ($lastName !== '' ? ' '.$lastName : ''));
if ($userShort === '') { $userShort = 'Usuário'; }

/* --- busca nome da empresa (company.organizacao) se faltar --- */
if ($pdo && $companyId && !$orgName) {
  try {
    $st = $pdo->prepare("SELECT organizacao FROM company WHERE id_company = :cid LIMIT 1");
    $st->execute([':cid'=>$companyId]);
    $orgName = $st->fetchColumn() ?: null;
  } catch (Throwable $e) { /* ignora */ }
}
$companyIdText = ($companyId !== null && $companyId !== '') ? (string)$companyId : '–';
$orgText       = ($orgName !== null && $orgName !== '') ? (string)$orgName : '–';
?>
<!-- ===================== SIDEBAR ===================== -->
<style>
:root {
  --sidebar-width: 250px;
  --sidebar-collapsed: 60px;
  --transition-speed: 0.3s;
}

/* Cores: bg1 (fundo/hover), bg2 (texto padrão/realce), ativo invertido
   >>> AGORA as vars vêm do company_theme.php via <link> injetado acima <<< */
.sidebar {
  width: var(--sidebar-width);
  background: linear-gradient(180deg, var(--card), #0e1319);
  color: var(--bg2, #F1C40F); /* textos/ícones padrão em bg2 */
  transition: width var(--transition-speed);
  position: fixed; top: 0; bottom: 0; left: 0;
  display: flex; flex-direction: column;
  z-index: 1000;
  overflow-x: hidden;
}
body.collapsed .sidebar { width: var(--sidebar-collapsed); }

.sidebar-header {
  width: 100%; display: flex; align-items: center; justify-content: center; height: 60px;
}
.sidebar-header .menu-toggle { font-size: 1.5rem; color: var(--bg2, #F1C40F); cursor: pointer; }
.sidebar-header .toggle-text {
  margin-left: 0.5rem; color: var(--bg2, #F1C40F); font-size: 1rem; font-weight: 600;
  text-transform: uppercase; user-select: none; cursor: pointer;
}
body.collapsed .sidebar-header .toggle-text { display: none; }

.sidebar ul { list-style: none; margin: 0; padding: 0; flex: 1; }
.sidebar li { display: block; }

.sidebar .menu-item {
  display: flex; align-items: center; padding: 0.75rem 1rem; cursor: pointer;
  transition: background var(--transition-speed), color var(--transition-speed);
  white-space: nowrap;
  color: var(--bg2, #F1C40F);
}
.sidebar .menu-item:hover { background: var(--bg1-hover, #2B2B2B); }
.sidebar .menu-item i.icon-main { min-width: 24px; text-align: center; margin-right: 1rem; color: var(--bg2, #F1C40F); }
.sidebar .menu-item span { flex: 1; }
.sidebar .menu-item .icon-chevron { margin-left: 0.25rem; color: var(--bg2, #F1C40F); }

.sidebar .menu-item.active {
  background: var(--bg2, #F1C40F);
  color: var(--bg2-contrast, #111111);
}
.sidebar .menu-item.active i,
.sidebar .menu-item.active span,
.sidebar .menu-item.active .icon-chevron { color: var(--bg2-contrast, #111111); }

body.collapsed .sidebar .menu-item span,
body.collapsed .sidebar .menu-item .icon-chevron { display: none; }
body.collapsed .sidebar .menu-item { justify-content: center; }
body.collapsed .sidebar .menu-item i.icon-main { margin-right: 0; }

.submenu {
  display: none; list-style: none; padding-left: 0; margin: 0; font-size: 0.85rem;
}
.submenu li {
  display: flex; align-items: center; padding: 0.25rem 1rem; cursor: pointer;
  transition: background var(--transition-speed), color var(--transition-speed);
  color: #f5f5f5ff;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.submenu li:hover { background: var(--bg1-hover, #2B2B2B); }
.submenu li i { min-width: 24px; text-align: center; margin-right: 1rem; font-size: 0.75rem; color: #f5f5f5ff; }
.submenu li span { color: inherit; }

.submenu li.active {
  background: var(--bg2, #F1C40F);
  color: var(--bg2-contrast, #111111);
}
.submenu li.active i, .submenu li.active span { color: var(--bg2-contrast, #111111); }

.sidebar li.open > .submenu { display: block; }

body.collapsed .submenu { display: none !important; visibility: hidden; }
body.collapsed .sidebar { overflow-y: auto; }

/* Rodapé */
.sidebar-footer{
  border-top: 1px solid rgba(255,255,255,0.08);
  padding: .6rem 1rem .75rem;
  font-size: .78rem;
  line-height: 1.2;
  color: color-mix(in srgb, var(--bg1-contrast, #FFFFFF) 70%, transparent);
}
.sidebar-footer .user{
  font-weight: 600;
  color: var(--bg2, #F1C40F);
  display: block;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-footer .org{
  margin-top: .25rem;
  opacity: .9;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
body.collapsed .sidebar-footer .user,
body.collapsed .sidebar-footer .org { display: none; }
</style>

<aside class="sidebar">
  <div class="sidebar-header">
    <i class="menu-toggle fas fa-chevron-left" onclick="toggleSidebar()" title="Recolher/Expandir"></i>
    <span class="toggle-text" onclick="toggleSidebar()">Recolher menu</span>
  </div>

  <ul>
    <li>
      <div class="menu-item <?= $isDashboard ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/dashboard"
           onclick="onMenuClick(this, event)">
        <i class="fas fa-tachometer-alt icon-main"></i><span>Dashboard</span>
      </div>
    </li>
    <li>
      <div class="menu-item <?= $isMapaEstrategico ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/mapa_estrategico"
           onclick="onMenuClick(this, event)">
        <i class="fas fa-map icon-main"></i><span>Mapa Estratégico</span>
      </div>
    </li>
    <li class="<?= $isOKRGroup ? 'open' : '' ?>">
      <div class="menu-item <?= $isOKRGroup ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/meus_okrs"
           onclick="onMenuClick(this, event)">
        <i class="fas fa-crosshairs icon-main"></i><span>Meus OKRs</span>
        <i class="fas fa-chevron-down icon-chevron"
           title="Abrir/Fechar"
           onclick="event.stopPropagation(); this.closest('li').classList.toggle('open');"></i>
      </div>
      <ul class="submenu">
        <li class="<?= $isNewObjective ? 'active' : '' ?>"
            data-href="https://planningbi.com.br/OKR_system/novo_objetivo"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-plus"></i><span>Novo Objetivo</span>
        </li>
        <li class="<?= $isNewKR ? 'active' : '' ?>"
            data-href="https://planningbi.com.br/OKR_system/novo_key_result"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-plus"></i><span>Novo Key Result</span>
        </li>
      </ul>
    </li>
    <li>
      <div class="menu-item <?= $isOrcamento ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/orcamento"
           onclick="onMenuClick(this, event)">
        <i class="fas fa-file-invoice-dollar icon-main"></i><span>Orçamento</span>
      </div>
    </li>
    <li class="<?= $isReports ? 'open' : '' ?>">
      <div class="menu-item <?= $isReports ? 'active' : '' ?>" onclick="onMenuClick(this, event)">
        <i class="fas fa-chart-line icon-main"></i><span>Relatórios</span>
        <i class="fas fa-chevron-down icon-chevron"></i>
      </div>
      <ul class="submenu">
        <li class="<?= ($currentPath === '/OKR_system/views/rel_vendas.php') ? 'active' : '' ?>"
            data-href="/OKR_system/views/rel_vendas.php"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-shopping-cart"></i><span>Vendas</span>
        </li>
        <li class="<?= ($currentPath === '/OKR_system/views/rel_desempenho.php') ? 'active' : '' ?>"
            data-href="/OKR_system/views/rel_desempenho.php"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-chart-bar"></i><span>Desempenho</span>
        </li>
      </ul>
    </li>
    <li>
      <div class="menu-item <?= $isAprovacao ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/aprovacao"
           onclick="onMenuClick(this, event)">
        <i class="fas fa-clipboard-check icon-main"></i><span>Aprovações</span>
      </div>
    </li>
    <li class="<?= $isSettings ? 'open' : '' ?>">
      <div class="menu-item <?= $isSettings ? 'active' : '' ?>" onclick="onMenuClick(this, event)">
        <i class="fas fa-cog icon-main"></i><span>Configurações</span>
        <i class="fas fa-chevron-down icon-chevron"></i>
      </div>
      <ul class="submenu">
        <li class="<?= $isConfigStyle ? 'active' : '' ?>"
            data-href="/OKR_system/views/config_style.php"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-palette"></i><span>Personalizar Estilo</span>
        </li>
        <li class="<?= $isOrgConfig ? 'active' : '' ?>"
            data-href="/OKR_system/views/organizacao.php"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-building"></i><span>Editar Organização</span>
        </li>
        <li class="<?= $isUsersMgmt ? 'active' : '' ?>"
            data-href="/OKR_system/views/usuarios.php"
            onclick="onSubmenuClick(this)">
          <i class="fas fa-users-gear"></i><span>Gerenciar Usuários</span>
        </li>
      </ul>
    </li>
  </ul>

  <!-- Rodapé -->
  <div class="sidebar-footer">
    <span class="user" title="<?= htmlspecialchars($userShort) ?>">
      <?= htmlspecialchars($userShort) ?>
    </span>
    <span class="org" title="#<?= htmlspecialchars($companyIdText) ?> - <?= htmlspecialchars($orgText) ?>">
      #<?= htmlspecialchars($companyIdText) ?> - <?= htmlspecialchars($orgText) ?>
    </span>
  </div>
</aside>

<script>
function clearActive() {
  document.querySelectorAll('.menu-item.active, .submenu li.active')
    .forEach(el => el.classList.remove('active'));
}
function closeAllSubmenus() {
  document.querySelectorAll('.sidebar li.open').forEach(li => li.classList.remove('open'));
}
function onMenuClick(el, ev) {
  const li = el.parentElement;
  const submenu = li.querySelector('.submenu');

  if (submenu) {
    if (document.body.classList.contains('collapsed')) {
      document.body.classList.remove('collapsed');
      updateToggleIcon();
      updateToggleText();
    }
    if (ev && (ev.target.classList && (ev.target.classList.contains('icon-chevron') || ev.target.closest('.icon-chevron')))) {
      li.classList.toggle('open');
      return;
    }
    const href = el.getAttribute('data-href');
    if (href) {
      clearActive();
      el.classList.add('active');
      window.location = href;
    } else {
      li.classList.toggle('open');
    }
  } else {
    clearActive();
    el.classList.add('active');
    const href = el.getAttribute('data-href');
    if (href) window.location = href;
  }
}
function onSubmenuClick(el) {
  if (document.body.classList.contains('collapsed')) {
    document.body.classList.remove('collapsed');
    updateToggleIcon();
    updateToggleText();
  }
  clearActive();
  const ul = el.closest('ul.submenu');
  const parentLi = ul ? ul.closest('li') : null;
  if (parentLi) parentLi.classList.add('open');
  el.classList.add('active');
  window.location = el.getAttribute('data-href');
}
function autoCollapse() {
  const wasExpanded = !document.body.classList.contains('collapsed');
  if (window.innerWidth <= 768) {
    document.body.classList.add('collapsed');
    if (wasExpanded) closeAllSubmenus();
  } else {
    document.body.classList.remove('collapsed');
  }
}
function updateToggleIcon() {
  const toggle = document.querySelector('.menu-toggle');
  if (!toggle) return;
  if (document.body.classList.contains('collapsed')) {
    toggle.classList.remove('fa-chevron-left'); toggle.classList.add('fa-chevron-right');
  } else {
    toggle.classList.remove('fa-chevron-right'); toggle.classList.add('fa-chevron-left');
  }
}
function updateToggleText() {
  const txt = document.querySelector('.toggle-text');
  if (!txt) return;
  txt.textContent = document.body.classList.contains('collapsed') ? '' : 'Recolher menu';
}
function toggleSidebar() {
  const isExpanding = document.body.classList.contains('collapsed');
  document.body.classList.toggle('collapsed');
  if (!isExpanding) { closeAllSubmenus(); }
  updateToggleIcon();
  updateToggleText();
}
window.addEventListener('DOMContentLoaded', () => {
  autoCollapse();
  updateToggleIcon();
  updateToggleText();
  if (document.body.classList.contains('collapsed')) closeAllSubmenus();
});
window.addEventListener('resize', () => {
  const prevCollapsed = document.body.classList.contains('collapsed');
  autoCollapse();
  updateToggleIcon();
  updateToggleText();
  if (!prevCollapsed && document.body.classList.contains('collapsed')) closeAllSubmenus();
});
</script>
