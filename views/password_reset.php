<?php
// /OKR_system/views/password_reset.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helpers
function json_error_and_exit(int $code, string $msg): void {
  http_response_code($code);
  echo $msg;
  exit;
}

// Conexão PDO
try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
  json_error_and_exit(500, 'Erro ao conectar ao banco.');
}

// Normaliza entradas
function norm_hex(?string $s): string {
  $s = is_string($s) ? trim($s) : '';
  return strtolower($s);
}

// --- Lê parâmetros (GET para exibir form / POST para trocar a senha) ---
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$selector = $method === 'POST' ? ($_POST['selector'] ?? '') : ($_GET['selector'] ?? '');
$verifier = $method === 'POST' ? ($_POST['verifier'] ?? '') : ($_GET['verifier'] ?? '');
$selector = norm_hex($selector);
$verifier = norm_hex($verifier);

// Valida formato mínimo
$selectorOk = (bool)preg_match('/^[a-f0-9]{32}$/', $selector); // CHAR(32) na tabela
// Observação: dependendo da sua generateSelectorVerifier(), o verifier pode ter 32 ou 64 hex.
// Como no request você salvou verifier_hash como CHAR(64), aqui aceitamos 32..64 hex e normalizamos.
$verifierOk = (bool)preg_match('/^[a-f0-9]{32,64}$/', $verifier);

if (!$selectorOk || !$verifierOk) {
  // Link quebrado/copied errado
  $invalid = true;
} else {
  // Busca o reset por selector
  $st = $pdo->prepare("
    SELECT id_reset, user_id, verifier_hash, expira_em, used_at
      FROM usuarios_password_resets
     WHERE selector = :sel
     LIMIT 1
  ");
  $st->execute([':sel' => $selector]);
  $reset = $st->fetch(PDO::FETCH_ASSOC);
  $invalid = !$reset;
}

if (!$invalid && $reset) {
  // Verifica expiração/uso
  if (!empty($reset['used_at'])) {
    $invalid = true;
  } elseif (strtotime($reset['expira_em']) < time()) {
    $invalid = true;
  } else {
    // Compara hash do verifier. Usamos a MESMA função do request.
    // 1) Preferência: hashVerifier() (se foi usada no request)
    $hashA = function_exists('hashVerifier')
      ? hashVerifier($verifier)
      : hash_hmac('sha256', $verifier, APP_TOKEN_PEPPER);

    // 2) Fallback de compatibilidade (se no request foi usado sha256(pepa+verifier)):
    $hashB = hash('sha256', APP_TOKEN_PEPPER . $verifier);

    if (!hash_equals($reset['verifier_hash'], $hashA) && !hash_equals($reset['verifier_hash'], $hashB)) {
      $invalid = true;
    }
  }
}

// POST = tentar trocar a senha
if ($method === 'POST') {
  // Falha de CSRF ou link inválido → mensagem padrão
  $csrfSess = $_SESSION['csrf_token'] ?? '';
  $csrfPost = $_POST['csrf_token'] ?? '';
  if (!$csrfSess || !hash_equals($csrfSess, (string)$csrfPost) || $invalid) {
    $_SESSION['reset_error'] = 'Link inválido ou expirado. Solicite novo link.';
    header('Location: /OKR_system/views/password_reset.php?selector=' . urlencode($selector) . '&verifier=' . urlencode($verifier));
    exit;
  }

  // Valida senhas
  $senha = (string)($_POST['senha'] ?? '');
  $conf  = (string)($_POST['senha_confirm'] ?? '');

  // Política mínima (ajuste se quiser igual ao cadastro)
  $senhaRegex = '/(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/';
  if (!preg_match($senhaRegex, $senha)) {
    $_SESSION['reset_error'] = 'A nova senha não atende aos requisitos mínimos.';
    header('Location: /OKR_system/views/password_reset.php?selector=' . urlencode($selector) . '&verifier=' . urlencode($verifier));
    exit;
  }
  if ($senha !== $conf) {
    $_SESSION['reset_error'] = 'As senhas não coincidem.';
    header('Location: /OKR_system/views/password_reset.php?selector=' . urlencode($selector) . '&verifier=' . urlencode($verifier));
    exit;
  }

  // Tudo ok: troca a senha e marca o reset como usado
  try {
    $pdo->beginTransaction();

    // 1) Atualiza/insere credencial
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($senha, $algo);

    $upCred = $pdo->prepare("
      INSERT INTO usuarios_credenciais (id_user, senha_hash)
      VALUES (:uid, :hash)
      ON DUPLICATE KEY UPDATE senha_hash = VALUES(senha_hash)
    ");
    $upCred->execute([':uid' => (int)$reset['user_id'], ':hash' => $hash]);

    // 2) Marca reset como usado e guarda IP/UA
    $ipUse = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $uaUse = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
    $upReset = $pdo->prepare("
      UPDATE usuarios_password_resets
         SET used_at = NOW(), ip_use = :ip, user_agent_use = :ua
       WHERE id_reset = :id
    ");
    $upReset->execute([':ip' => $ipUse, ':ua' => $uaUse, ':id' => (int)$reset['id_reset']]);

    // 3) (Opcional) Apaga outros resets pendentes do mesmo usuário
    $pdo->prepare("
      DELETE FROM usuarios_password_resets
       WHERE user_id = :uid AND id_reset <> :id
    ")->execute([':uid' => (int)$reset['user_id'], ':id' => (int)$reset['id_reset']]);

    $pdo->commit();

    // Mensagem de sucesso e redireciona ao login
    $_SESSION['success_message'] = 'Senha alterada com sucesso. Faça login com a nova senha.';
    header('Location: /OKR_system/views/login.php');
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASSWORD_RESET_TX_FAIL: ' . $e->getMessage());
    $_SESSION['reset_error'] = 'Falha ao alterar a senha. Tente novamente mais tarde.';
    header('Location: /OKR_system/views/password_reset.php?selector=' . urlencode($selector) . '&verifier=' . urlencode($verifier));
    exit;
  }
}

// GET = exibir tela
$flashError = $_SESSION['reset_error'] ?? '';
unset($_SESSION['reset_error']);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redefinir Senha – OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <style>
    .input-icon{ position:relative; }
    .input-icon .addon{
      position:absolute; right:.5rem; top:50%; transform:translateY(-50%);
      display:inline-flex; align-items:center; gap:.35rem;
      font-size:1rem; color:#6b7280; cursor:pointer; user-select:none;
      background:none; border:none; padding:0;
    }
    .input-icon input{ padding-right:2.7rem; }
    .ps-meter{ height:8px; background:#f3f4f6; border-radius:999px; overflow:hidden; margin:.5rem 0; }
    .ps-meter > span{ display:block; height:100%; width:0%; background:linear-gradient(90deg, #f43f5e, #f59e0b, #10b981); transition:width .25s ease; }
    .ps-checks{ display:grid; grid-template-columns:1fr 1fr; gap:.35rem .75rem; margin:.35rem 0 0; font-size:.85rem; }
    .ps-checks .ok{ color:var(--okr-success, #10b981); }
    .ps-checks .no{ color:var(--okr-muted, #9ca3af); }
    .caps{ color:var(--okr-warning, #f59e0b); font-size:.82rem; display:none; }
    .help{ font-size:.8rem; color:var(--okr-muted, #9ca3af); margin-top:.25rem; }
  </style>
</head>
<body class="fullscreen-center">

  <div class="login-card">
    <div class="login-illustration"><!-- ilustração --></div>

    <div class="login-form-wrapper">
      <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
        <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg"
             alt="Logo" class="logo">
      </a>

      <?php if ($invalid): ?>
        <div class="error-message" style="margin-top:1rem;">
          Link inválido ou expirado. Solicite novo link.
        </div>
        <div style="margin-top:1rem;">
          <a class="btn btn-secondary w-100" href="/OKR_system/views/password_reset_request.php">Voltar para recuperar senha</a>
        </div>
      <?php else: ?>
        <form id="resetForm" action="/OKR_system/views/password_reset.php" method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
          <input type="hidden" name="selector"   value="<?= htmlspecialchars($selector, ENT_QUOTES) ?>">
          <input type="hidden" name="verifier"   value="<?= htmlspecialchars($verifier, ENT_QUOTES) ?>">

          <div class="mb-3">
            <label for="senha" class="form-label">Nova senha</label>
            <div class="input-icon">
              <input type="password"
                     id="senha" name="senha"
                     class="form-control"
                     placeholder="Crie uma senha forte"
                     autocomplete="new-password"
                     required minlength="8"
                     pattern="(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$"
                     aria-describedby="help_senha ps_checks ps_caps">
              <button type="button" class="addon toggle-password" data-target="#senha" aria-label="Mostrar senha">👁️</button>
            </div>
            <div class="caps" id="ps_caps" aria-live="polite">Caps Lock ativado</div>
            <div class="ps-meter" aria-hidden="true"><span id="ps_bar"></span></div>
            <div id="ps_checks" class="ps-checks" aria-live="polite">
              <span id="pc_len" class="no">8+ caracteres</span>
              <span id="pc_up"  class="no">Letra maiúscula</span>
              <span id="pc_lo"  class="no">Letra minúscula</span>
              <span id="pc_num" class="no">Número</span>
              <span id="pc_sym" class="no">Símbolo</span>
            </div>
            <div id="help_senha" class="help">Dica: combine frases, números e símbolos.</div>
          </div>

          <div class="mb-3">
            <label for="senha_confirm" class="form-label">Confirmar nova senha</label>
            <div class="input-icon">
              <input type="password"
                     id="senha_confirm" name="senha_confirm"
                     class="form-control"
                     placeholder="Repita a senha"
                     autocomplete="new-password"
                     required aria-describedby="ps_feedback">
              <button type="button" class="addon toggle-password" data-target="#senha_confirm" aria-label="Mostrar senha">👁️</button>
            </div>
            <div id="ps_feedback" class="help" aria-live="polite"></div>
          </div>

          <?php if (!empty($flashError)): ?>
            <div class="error-message" style="margin: .5rem 0 1rem 0;"><?= htmlspecialchars($flashError) ?></div>
          <?php endif; ?>

          <div class="mb-3">
            <button type="submit" id="submitBtn" class="btn btn-primary w-100">Salvar nova senha</button>
          </div>
          <div>
            <a href="/OKR_system/views/login.php" class="password-reset-link">Voltar ao login</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

<script>
(function(){
  const form      = document.getElementById('resetForm');
  if (!form) return;

  const elSenha   = document.getElementById('senha');
  const elConfirm = document.getElementById('senha_confirm');
  const elFeed    = document.getElementById('ps_feedback');
  const bar       = document.getElementById('ps_bar');
  const caps      = document.getElementById('ps_caps');
  const submitBtn = document.getElementById('submitBtn');

  // Toggle senha
  document.querySelectorAll('.toggle-password').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const targetSel = btn.getAttribute('data-target');
      const input = document.querySelector(targetSel);
      if (!input) return;
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      btn.textContent = isPass ? '🙈' : '👁️';
      btn.setAttribute('aria-label', isPass ? 'Esconder senha' : 'Mostrar senha');
    });
  });

  // Caps Lock
  function handleCaps(e){
    if (typeof e.getModifierState === 'function') {
      caps.style.display = e.getModifierState('CapsLock') ? 'block' : 'none';
    }
  }
  elSenha.addEventListener('keydown', handleCaps);
  elSenha.addEventListener('keyup', handleCaps);

  // Força da senha + checklist
  const pc_len = document.getElementById('pc_len');
  const pc_up  = document.getElementById('pc_up');
  const pc_lo  = document.getElementById('pc_lo');
  const pc_num = document.getElementById('pc_num');
  const pc_sym = document.getElementById('pc_sym');

  function passScore(s){
    const hasLen = s.length >= 8;
    const hasUp  = /[A-Z]/.test(s);
    const hasLo  = /[a-z]/.test(s);
    const hasNum = /\d/.test(s);
    const hasSym = /\W/.test(s);

    pc_len.className = hasLen ? 'ok' : 'no';
    pc_up.className  = hasUp  ? 'ok' : 'no';
    pc_lo.className  = hasLo  ? 'ok' : 'no';
    pc_num.className = hasNum ? 'ok' : 'no';
    pc_sym.className = hasSym ? 'ok' : 'no';

    return [hasLen,hasUp,hasLo,hasNum,hasSym].filter(Boolean).length;
  }
  function updateBar(score){ bar.style.width = ((score/5)*100) + '%'; }
  function updateMatch(){
    const s = elSenha.value, c = elConfirm.value;
    if (!c){ elFeed.textContent = ''; return; }
    if (s === c){
      elFeed.textContent = '✅ Senhas conferem.';
      elFeed.style.color = '#065f46';
    } else {
      elFeed.textContent = '❌ Senhas não coincidem.';
      elFeed.style.color = '#b91c1c';
    }
  }
  elSenha.addEventListener('input', ()=>{ updateBar(passScore(elSenha.value)); updateMatch(); });
  elConfirm.addEventListener('input', updateMatch);

  // Validação client-side no submit
  const complex = /(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/;
  form.addEventListener('submit', function(e){
    if (!complex.test(elSenha.value)){
      e.preventDefault();
      elSenha.focus();
      alert('Sua senha precisa atender aos requisitos mínimos: 8+ caracteres, maiúscula, minúscula, número e símbolo.');
      return;
    }
    if (elSenha.value !== elConfirm.value){
      e.preventDefault();
      elConfirm.focus();
      alert('As senhas não coincidem.');
      return;
    }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Salvando…';
  });
})();
</script>
</body>
</html>
