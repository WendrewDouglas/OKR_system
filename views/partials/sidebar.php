<?php
// partials/sidebar.php
// só inicia sessão se ainda não estiver ativa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPath      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$isDashboard      = ($currentPath === '/OKR_system/dashboard');
$isNewObjective   = ($currentPath === '/OKR_system/novo_objetivo');
$isNewKR          = ($currentPath === '/OKR_system/novo_key_result');
$isMyOKRs         = ($currentPath === '/OKR_system/meus_okrs');

$isReports        = in_array($currentPath, [
    '/OKR_system/views/rel_vendas.php',
    '/OKR_system/views/rel_desempenho.php'
]);

// --- Configurações (submenu) ---
$isConfigStyle = in_array($currentPath, [
    '/OKR_system/views/config_style.php',
    '/OKR_system/config_style'
]);
$isOrgConfig = in_array($currentPath, [
    '/OKR_system/views/organizacao.php',
    '/OKR_system/organizacao',
    '/OKR_system/views/configuracoes.php' // legado, mantém ativo no grupo
]);
$isSettings = ($isConfigStyle || $isOrgConfig);

$newMessages      = $_SESSION['new_messages'] ?? 0;
?>

<!-- ====== SIDEBAR ====== -->
<style>
:root {
  --sidebar-width: 250px;
  --sidebar-collapsed: 60px;
  --transition-speed: 0.3s;
}
.sidebar {
  width: var(--sidebar-width);
  background: #0d1117;
  color: #f1c40f;
  transition: width var(--transition-speed);
  position: fixed; top: 0; bottom: 0; left: 0;
  display: flex; flex-direction: column;
  z-index: 1000;
  overflow-x: hidden; /* evita extravaso horizontal */
}
body.collapsed .sidebar { width: var(--sidebar-collapsed); }

.sidebar-header {
  width: 100%; display: flex; align-items: center; justify-content: center; height: 60px;
}
.sidebar-header .menu-toggle { font-size: 1.5rem; color: #f1c40f; cursor: pointer; }
.sidebar-header .toggle-text {
  margin-left: 0.5rem; color: #f1c40f; font-size: 1rem; font-weight: 600;
  text-transform: uppercase; user-select: none; cursor: pointer;
}
body.collapsed .sidebar-header .toggle-text { display: none; }

.sidebar ul { list-style: none; margin: 0; padding: 0; flex: 1; }
.sidebar li { display: block; }

.sidebar .menu-item {
  display: flex; align-items: center; padding: 0.75rem 1rem; cursor: pointer;
  transition: background var(--transition-speed);
  white-space: nowrap; /* evita quebra feia quando estreito */
}
.sidebar .menu-item:hover { background: #4e4e4e; }
.sidebar .menu-item i.icon-main { min-width: 24px; text-align: center; margin-right: 1rem; }
.sidebar .menu-item span { flex: 1; }
.sidebar .menu-item.active { background: #f1c40f; color: #222222; }
.sidebar .menu-item.active i,
.sidebar .menu-item.active span { color: inherit; }

/* ao recolher, esconda textos e setas do item principal */
body.collapsed .sidebar .menu-item span,
body.collapsed .sidebar .menu-item .icon-chevron { display: none; }
/* centraliza apenas os ícones quando recolhido */
body.collapsed .sidebar .menu-item { justify-content: center; }
body.collapsed .sidebar .menu-item i.icon-main { margin-right: 0; }

.submenu {
  display: none; list-style: none; padding-left: 0; margin: 0; font-size: 0.85rem;
}
.submenu li {
  display: flex; align-items: center; padding: 0.25rem 1rem; cursor: pointer;
  transition: background var(--transition-speed); color: #ccc;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.submenu li:hover { background: #4e4e4e; }
.submenu li i { min-width: 24px; text-align: center; margin-right: 1rem; font-size: 0.75rem; color: #ccc; }
.submenu li span { color: #ccc; }
.submenu li.active { background: #f1c40f; color: #222222; }
.submenu li.active i, .submenu li.active span { color: inherit; }

.sidebar li.open > .submenu { display: block; }

/* ===== FIX: quando recolhido, garanta que NENHUM submenu apareça ===== */
body.collapsed .submenu { display: none !important; visibility: hidden; }

/* extra: evita qualquer transbordo visual */
body.collapsed .sidebar { overflow-y: auto; }
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
           onclick="onMenuClick(this)">
        <i class="fas fa-tachometer-alt icon-main"></i><span>Dashboard</span>
      </div>
    </li>

    <li>
      <div class="menu-item <?= $isMyOKRs ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/meus_okrs"
           onclick="onMenuClick(this)">
        <i class="fas fa-crosshairs icon-main"></i><span>Meus OKRs</span>
      </div>
    </li>

    <li>
      <div class="menu-item <?= $isNewObjective ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/novo_objetivo"
           onclick="onMenuClick(this)">
        <i class="fas fa-plus icon-main"></i><span>Novo Objetivo</span>
      </div>
    </li>

    <li>
      <div class="menu-item <?= $isNewKR ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/novo_key_result"
           onclick="onMenuClick(this)">
        <i class="fas fa-plus icon-main"></i><span>Novo Key Result</span>
      </div>
    </li>

    <li>
      <div class="menu-item <?= $isNewKR ? 'active' : '' ?>"
           data-href="https://planningbi.com.br/OKR_system/matriz_prioridade"
           onclick="onMenuClick(this)">
        <i class="fas fa-table icon-main"></i><span>Matriz de Prioridade</span>
      </div>
    </li>

    <li class="<?= $isReports ? 'open' : '' ?>">
      <div class="menu-item <?= $isReports ? 'active' : '' ?>" onclick="onMenuClick(this)">
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

    <!-- ===== Configurações com submenu ===== -->
    <li class="<?= $isSettings ? 'open' : '' ?>">
      <div class="menu-item <?= $isSettings ? 'active' : '' ?>" onclick="onMenuClick(this)">
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
      </ul>
    </li>
  </ul>
</aside>

<script>
function clearActive() {
  document.querySelectorAll('.menu-item.active, .submenu li.active')
    .forEach(el => el.classList.remove('active'));
}
function closeAllSubmenus() {
  document.querySelectorAll('.sidebar li.open').forEach(li => li.classList.remove('open'));
}
function onMenuClick(el) {
  const li = el.parentElement,
        submenu = li.querySelector('.submenu');
  if (submenu) {
    // se estiver recolhido, primeiro expande a barra para então permitir abrir submenu
    if (document.body.classList.contains('collapsed')) {
      document.body.classList.remove('collapsed');
      updateToggleIcon();
      updateToggleText();
    }
    li.classList.toggle('open');
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
  el.closest('li').classList.add('open');
  el.classList.add('active');
  window.location = el.getAttribute('data-href');
}
function autoCollapse() {
  const wasExpanded = !document.body.classList.contains('collapsed');
  if (window.innerWidth <= 768) {
    document.body.classList.add('collapsed');
    if (wasExpanded) closeAllSubmenus(); // fecha submenus ao recolher automático
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
  if (!isExpanding) {
    // se estou recolhendo agora, fecho todos os submenus para não "sobrar" texto
    closeAllSubmenus();
  }
  updateToggleIcon();
  updateToggleText();
}
window.addEventListener('DOMContentLoaded', () => {
  autoCollapse();
  updateToggleIcon();
  updateToggleText();
  // segurança extra: se carregar já recolhido, garanta submenus fechados
  if (document.body.classList.contains('collapsed')) closeAllSubmenus();
});
window.addEventListener('resize', () => {
  const prevCollapsed = document.body.classList.contains('collapsed');
  autoCollapse();
  updateToggleIcon();
  updateToggleText();
  // se mudou de expandido -> recolhido, fecha submenus
  if (!prevCollapsed && document.body.classList.contains('collapsed')) closeAllSubmenus();
});
</script>
