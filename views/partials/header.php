<?php 
// partials/header.php
// Exibe o header fixo em todas as páginas
// Deve ser incluído dentro da <div class="content">, antes do <main>

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Usuário e notificações (definidos em session no login)
require_once dirname(__DIR__, 2) . '/auth/helpers/nome_format.php';
$userName = nome_exibicao(
  (string)($_SESSION['primeiro_nome'] ?? ''),
  (string)($_SESSION['ultimo_nome'] ?? '')
);
if ($userName === '') { $userName = (string)($_SESSION['user_name'] ?? 'Usuário'); }
$newMessages = (int)($_SESSION['new_messages'] ?? 0);

// ===== Avatar (catálogo único, via auth/avatar_helpers.php) =====
$id_user = $_SESSION['user_id'] ?? null;
require_once dirname(__DIR__, 2) . '/auth/avatar_helpers.php';
$avatarData = avatar_resolve((int) ($id_user ?? 0));
$avatarUrl  = $avatarData['url'];

/* ====== LOGO DA COMPANY_STYLE (ATUALIZADO) ======
   - Descobre id_company
   - Busca logo e o hash MD5(logo_base64)
   - Se hash mudou ou empresa mudou, atualiza a sessão
*/
$logoSrcDefault = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg';

// Garante config/conn
try {
  if (!isset($pdo)) {
    $root = $root ?? dirname(__DIR__, 2);
    $cfg  = $cfg  ?? ($root . '/auth/config.php');
    if (is_file($cfg)) {
      require_once $cfg;
      $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
      $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);
    }
  }

  $logoSrc = null;

  if (isset($pdo)) {
    // 1) Descobre o id_company
    $companyId = $_SESSION['id_company'] 
              ?? $_SESSION['company_id'] 
              ?? null;

    if (!$companyId && $id_user) {
      $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :id LIMIT 1");
      $st->execute([':id' => $id_user]);
      $companyId = $st->fetchColumn() ?: null;
    }
    if (!$companyId) { $companyId = 1; }

    // 2) Se empresa mudou, invalida cache
    if (isset($_SESSION['company_logo_company_id']) && $_SESSION['company_logo_company_id'] !== $companyId) {
      unset($_SESSION['company_logo_base64'], $_SESSION['company_logo_hash']);
    }

    // 3) Busca logo atual e o hash de conteúdo
    $st = $pdo->prepare("
      SELECT logo_base64, MD5(logo_base64) AS logo_hash
        FROM company_style
       WHERE id_company = :cid
       ORDER BY COALESCE(updated_at, created_at) DESC, id_style DESC
       LIMIT 1
    ");
    $st->execute([':cid' => $companyId]);
    $row = $st->fetch();

    if ($row && is_string($row['logo_base64']) && str_starts_with($row['logo_base64'], 'data:image/')) {
      $dbLogo  = $row['logo_base64'];
      $dbHash  = $row['logo_hash'] ?? null;
      $sesHash = $_SESSION['company_logo_hash'] ?? null;

      // 4) Atualiza sessão se não houver cache ou se o hash mudou
      if (!$sesHash || $sesHash !== $dbHash) {
        $_SESSION['company_logo_base64']     = $dbLogo;
        $_SESSION['company_logo_hash']       = $dbHash;
        $_SESSION['company_logo_company_id'] = $companyId;
      }
    }

    // 5) Define a fonte da logo (cache já atualizado acima, se necessário)
    $logoSrc = $_SESSION['company_logo_base64'] ?? null;
  }

  // Fallback
  if (empty($logoSrc)) {
    $logoSrc = $logoSrcDefault;
  }
} catch (Throwable $e) {
  // Falha silenciosa: usa fallback
  $logoSrc = $logoSrcDefault;
}
?>
<!-- ====== HEADER ====== -->
<link rel="stylesheet" href="/OKR_system/assets/css/avatar.css">
<style>
/* (estilos inalterados) */
.header { height: 60px; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
.menu-toggle { font-size: 1.5rem; cursor: pointer; margin-right: 1rem; color: #2C3E50; }
.header .left { display: flex; align-items: center; }
.header .left .logo-link img { height: 36px; width: auto; max-width: 240px; object-fit: contain; transition: transform 0.2s ease-in-out; }
.header .left .logo-link:hover img { transform: scale(1.1); }
.header .right { display: flex; align-items: center; position: relative; gap: 16px; }
.header .header-quick-actions { display: flex; gap: 6px; align-items: center; }
.header .header-qa-btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 8px; background: var(--bg2, #F1C40F); color: var(--bg2-contrast, #111111); font-size: .8rem; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: transform .15s, filter .15s; white-space: nowrap; line-height: 1.2; box-sizing: border-box; }
.header .header-qa-btn:hover { filter: brightness(.92); transform: translateY(-1px); color: var(--bg2-contrast, #111111); text-decoration: none; }
.header .header-qa-btn i { font-size: .75rem; color: inherit; }
.notif-link { position: relative; display: inline-block; line-height: 1; color: #2C3E50; }
.notif-link i { font-size: 1.2rem; }
.notif-link .badge { position: absolute; top: -6px; right: -8px; display: none; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 800; line-height: 18px; text-align: center; box-shadow: 0 0 0 2px #fff; }
.profile { display: flex; align-items: center; cursor: pointer; position: relative; }
.profile img { width: 32px; height: 32px; border-radius: 50%; margin-right: 0.5rem; object-fit: cover; }
.profile span { color: #2C3E50; font-weight: 500; }
.profile-menu { display: none; position: absolute; top: 100%; right: 0; background: #fff; border: 1px solid #ddd; box-shadow: 0 2px 8px rgba(0,0,0,0.1); list-style: none; margin: 0; padding: 0.5rem 0; min-width: 150px; z-index: 200; }
.profile.open .profile-menu { display: block; }
.profile-menu li { padding: 0; }
.profile-menu a { display: flex; align-items: center; padding: 0.5rem 1rem; color: #222222; text-decoration: none; transition: background 0.2s; }
.profile-menu a:hover { background: #f1c40f; }
.profile-menu a i { margin-right: 0.5rem; color: #222222; }
.content { margin-left: var(--sidebar-width); transition: margin-left var(--transition-speed); }
body.collapsed .content { margin-left: var(--sidebar-collapsed); }
@media (max-width: 768px) {
  .header .header-qa-btn { padding: 6px 8px; }
  .header .header-qa-btn .qa-label { display: none; }
}
</style>

<header class="header">
  <div class="left">
    <a href="https://planningbi.com.br/" class="logo-link"
       aria-label="Ir para página inicial" target="_blank" rel="noopener">
      <img src="/OKR_system/assets/img/logo-horizontal-branca.png" alt="PlanningBI">
    </a>
  </div>
  <div class="right">
    <!-- Quick Actions: + Objetivo / + KR (saíram do submenu "Meus OKRs" da sidebar) -->
    <?php
      // Mesma regra de visibilidade que a sidebar usava: dom_paginas.requires_cap.
      // acl.php exige as constantes de config; carrega ambos de forma idempotente.
      $__root    = dirname(__DIR__, 2);
      $__cfgPath = $__root . '/auth/config.php';
      $__aclPath = $__root . '/auth/acl.php';
      if (!defined('DB_HOST') && is_file($__cfgPath)) { require_once $__cfgPath; }
      if (is_file($__aclPath)) { require_once $__aclPath; }

      $__qaCan = static function (string $path): bool {
        if (!function_exists('can_open_path')) { return false; }
        try { return can_open_path($path); } catch (Throwable $e) { return false; }
      };
      $canNewObjective = $__qaCan('/OKR_system/views/novo_objetivo.php');
      $canNewKR        = $__qaCan('/OKR_system/views/novo_key_result.php');
    ?>
    <?php if ($canNewObjective || $canNewKR): ?>
    <div class="header-quick-actions">
      <?php if ($canNewObjective): ?>
      <a href="/OKR_system/views/novo_objetivo.php" class="header-qa-btn"
         title="Novo Objetivo" aria-label="Novo Objetivo">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>
        <span class="qa-label">Objetivo</span>
      </a>
      <?php endif; ?>
      <?php if ($canNewKR): ?>
      <a href="/OKR_system/views/novo_key_result.php" class="header-qa-btn"
         title="Novo Key Result" aria-label="Novo Key Result">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>
        <span class="qa-label">KR</span>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tutorial -->
    <button class="notif-link" onclick="tutOpen()" aria-label="Tutorial do sistema" title="Tour pelo sistema" style="background:none;border:none;cursor:pointer">
      <i class="fa-solid fa-graduation-cap" aria-hidden="true" style="color:#128C7E"></i>
    </button>

    <!-- Envelope + badge numérico -->
    <a href="/OKR_system/views/notificacoes.php" class="notif-link" aria-label="Abrir notificações">
      <i class="fa-regular fa-envelope" aria-hidden="true"></i>
      <span id="notifBadge" class="badge" aria-live="polite"
            <?php if ($newMessages > 0): ?>style="display:inline-block"<?php endif; ?>>
        <?= $newMessages > 99 ? '99+' : ($newMessages > 0 ? (int)$newMessages : '') ?>
      </span>
    </a>

    <!-- Perfil -->
    <div class="profile" onclick="toggleProfileMenu(event)">
      <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar do usuário" loading="lazy">
      <span><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
      <ul class="profile-menu">
        <li>
          <a href="/OKR_system/views/profile_user.php">
            <i class="fas fa-user"></i>Ver perfil
          </a>
        </li>
        <li>
          <a href="https://planningbi.com.br/">
            <i class="fas fa-sign-out-alt"></i>Sair
          </a>
        </li>
      </ul>
    </div>
  </div>
</header>

<script>
function toggleProfileMenu(e) {
  e.stopPropagation();
  const profile = e.currentTarget;
  const isOpen = profile.classList.contains('open');
  document.querySelectorAll('.profile').forEach(el => el.classList.remove('open'));
  if (!isOpen) profile.classList.add('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.profile')) {
    document.querySelectorAll('.profile').forEach(el => el.classList.remove('open'));
  }
});
</script>
<script>
(function(){
  const API = '/OKR_system/auth/notificacoes_api.php';
  const badge = document.getElementById('notifBadge');

  function renderBadge(count){
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
      badge.textContent = '';
    }
  }

  async function refreshBadge(){
    try{
      const r = await fetch(API+'?action=count', { cache:'no-store' });
      const j = await r.json();
      const count = parseInt(j?.count ?? 0, 10) || 0;
      renderBadge(count);
    }catch(e){
      // silencioso
    }
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    refreshBadge();
    setInterval(refreshBadge, 60000);
  });
})();
</script>
