<?php 
// partials/header.php
// Exibe o header fixo em todas as páginas
// Deve ser incluído dentro da <div class="content">, antes do <main>

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Usuário e notificações (definidos em session no login)
$userName    = $_SESSION['user_name']    ?? 'Usuário';
$newMessages = (int)($_SESSION['new_messages'] ?? 0);

// ===== Avatar (usuarios.avatar_id -> avatars.filename) =====
$id_user       = $_SESSION['user_id'] ?? null;
$avatarBaseWeb = '/OKR_system/assets/img/avatars/default_avatar/'; // onde estão seus PNGs
$avatarUrl     = $avatarBaseWeb . 'default.png';

// Validador simples para nomes (seu acervo é todo .png)
$validPng = function($fn) {
  return is_string($fn) && preg_match('/^[a-z0-9_.-]+\.png$/i', $fn);
};

/* >>>>>>> Correção ESSENCIAL do cache <<<<<<<
   - Se houver avatar_filename na sessão, mas:
     a) não houver avatar_user_id, OU
     b) avatar_user_id != user_id atual,
     então invalida o cache para forçar leitura correta.
*/
if (isset($_SESSION['avatar_filename'])) {
  if (!isset($_SESSION['avatar_user_id']) || $_SESSION['avatar_user_id'] !== $id_user) {
    unset($_SESSION['avatar_filename'], $_SESSION['avatar_user_id']);
  }
}

// Se já tiver em sessão (cache do MESMO user), usa direto
if (!empty($_SESSION['avatar_filename']) && $validPng($_SESSION['avatar_filename'])) {
  $avatarUrl = $avatarBaseWeb . $_SESSION['avatar_filename'];
}
// Senão, consulta no banco e faz cache em sessão
elseif ($id_user) {
  try {
    // Caminho correto: subir 2 níveis a partir de views/partials -> /OKR_system
    $root = dirname(__DIR__, 2);
    $cfg  = $root . '/auth/config.php';

    if (is_file($cfg)) {
      require_once $cfg;

      $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
      $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);

      $st = $pdo->prepare("
        SELECT a.filename
          FROM usuarios u
          JOIN avatars  a ON a.id = u.avatar_id
         WHERE u.id_user = :id
         LIMIT 1
      ");
      $st->execute([':id' => $id_user]);
      $fn = $st->fetchColumn();

      if ($validPng($fn)) {
        $_SESSION['avatar_filename'] = $fn;        // cache
        $_SESSION['avatar_user_id']  = $id_user;   // vínculo com o usuário atual
        $avatarUrl = $avatarBaseWeb . $fn;
      }
    }
  } catch (Throwable $e) {
    // Mantém default.png em caso de erro
  }
}

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
<style>
/* (estilos inalterados) */
.header { height: 60px; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
.menu-toggle { font-size: 1.5rem; cursor: pointer; margin-right: 1rem; color: #2C3E50; }
.header .left { display: flex; align-items: center; }
.header .left .logo-link img { height: auto; width: auto; max-height: 30px; max-width: 240px; object-fit: contain; transition: transform 0.2s ease-in-out; }
.header .left .logo-link:hover img { transform: scale(1.1); }
.header .right { display: flex; align-items: center; position: relative; gap: 16px; }
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
</style>

<header class="header">
  <div class="left">
    <a href="https://planningbi.com.br/" class="logo-link"
       aria-label="Ir para página inicial" target="_blank" rel="noopener">
      <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
    </a>
  </div>
  <div class="right">
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
