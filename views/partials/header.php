<?php
// partials/header.php
// Exibe o header fixo em todas as páginas
// Deve ser incluído dentro da <div class="content">, antes do <main>

// Usuário e notificações (definidos em session no login)
$userName    = $_SESSION['user_name']    ?? 'Usuário';
$newMessages = (int)($_SESSION['new_messages'] ?? 0);

// Avatar: assets/img/avatars/{id}.{ext} se existir; senão, padrão
$id_user  = $_SESSION['user_id'] ?? null;
$avatarUrl = '/OKR_system/assets/img/user-avatar.jpeg';
if ($id_user) {
    $webDir = '/OKR_system/assets/img/avatars/';
    $fsDir  = __DIR__ . '/../../assets/img/avatars/';
    foreach (['png','jpg','jpeg'] as $ext) {
        if (file_exists($fsDir . $id_user . '.' . $ext)) {
            $avatarUrl = $webDir . $id_user . '.' . $ext;
            break;
        }
    }
}
?>
<!-- ====== HEADER ====== -->
<style>
/* Header styles */
.header {
  height: 60px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 1rem;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  position: sticky;
  top: 0;
  z-index: 100;
}
.menu-toggle {
  font-size: 1.5rem;
  cursor: pointer;
  margin-right: 1rem;
  color: #2C3E50;
}
.header .left {
  display: flex;
  align-items: center;
}
.header .left .logo-link img {
  height: 32px;
  transition: transform 0.2s ease-in-out;
}
.header .left .logo-link:hover img {
  transform: scale(1.1);
}
.header .right {
  display: flex;
  align-items: center;
  position: relative;
  gap: 16px; /* <- dá respiro entre envelope e avatar */
}
.notif-link {
  position: relative;
  display: inline-block;
  line-height: 1;
  color: #2C3E50;
}
.notif-link i {
  font-size: 1.2rem;
}
.notif-link .badge {
  position: absolute;
  top: -6px;
  right: -8px;
  display: none;            /* começa escondido quando count=0 */
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: #ef4444;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  line-height: 18px;
  text-align: center;
  box-shadow: 0 0 0 2px #fff; /* contorno para destacar no fundo branco */
}
.profile {
  display: flex;
  align-items: center;
  cursor: pointer;
  position: relative;
}
.profile img {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  margin-right: 0.5rem;
}
.profile span {
  color: #2C3E50;
  font-weight: 500;
}
/* Profile dropdown menu */
.profile-menu {
  display: none;
  position: absolute;
  top: 100%;
  right: 0;
  background: #fff;
  border: 1px solid #ddd;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  list-style: none;
  margin: 0;
  padding: 0.5rem 0;
  min-width: 150px;
  z-index: 200;
}
.profile.open .profile-menu { display: block; }
.profile-menu li { padding: 0; }
.profile-menu a {
  display: flex;
  align-items: center;
  padding: 0.5rem 1rem;
  color: #222222;
  text-decoration: none;
  transition: background 0.2s;
}
.profile-menu a:hover { background: #f1c40f; }
.profile-menu a i { margin-right: 0.5rem; color: #222222; }

/* Ajuste para não sobrepor a sidebar */
.content { margin-left: var(--sidebar-width); transition: margin-left var(--transition-speed); }
body.collapsed .content { margin-left: var(--sidebar-collapsed); }
</style>

<header class="header">
  <div class="left">
    <a href="https://planningbi.com.br/" class="logo-link"
       aria-label="Ir para página inicial" target="_blank" rel="noopener">
      <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg" alt="Logo">
    </a>
  </div>
  <div class="right">
    <!-- Envelope + badge numérico -->
    <a href="/OKR_system/views/notificacoes.php"
       class="notif-link"
       aria-label="Abrir notificações">
      <i class="fa-regular fa-envelope" aria-hidden="true"></i>
      <span id="notifBadge" class="badge"
            aria-live="polite"
            <?php if ($newMessages > 0): ?>style="display:inline-block"<?php endif; ?>>
        <?= $newMessages > 99 ? '99+' : ($newMessages > 0 ? (int)$newMessages : '') ?>
      </span>
    </a>

    <!-- Perfil -->
    <div class="profile" onclick="toggleProfileMenu(event)">
      <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
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
    // já inicia com valor da sessão (server-side) e atualiza em seguida
    refreshBadge();
    // polling leve a cada 60s
    setInterval(refreshBadge, 60000);
  });
})();
</script>
