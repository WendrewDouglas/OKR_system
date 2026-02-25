<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../auth/config.php'; // garante .env carregado

// Le site key e provider do .env/config
$captchaSiteKey =
  defined('CAPTCHA_SITE_KEY') ? CAPTCHA_SITE_KEY
  : (getenv('CAPTCHA_SITE_KEY') ?: ($_ENV['CAPTCHA_SITE_KEY'] ?? ''));

// Normaliza provider e remove comentários inline do .env (ex: "recaptcha # ...")
$providerRaw =
  defined('CAPTCHA_PROVIDER') ? CAPTCHA_PROVIDER
  : (getenv('CAPTCHA_PROVIDER') ?: ($_ENV['CAPTCHA_PROVIDER'] ?? 'recaptcha'));
$provider = strtolower(trim(preg_split('/\s*#/', (string)$providerRaw, 2)[0]));

// Forçamos clássico (v2 invisível). NÃO usar enterprise.js aqui.
$recaptchaSrc = 'https://www.google.com/recaptcha/api.js';

// Se não houver site key, não aplicamos a classe g-recaptcha no botão
$hasSiteKey = !empty($captchaSiteKey);
$siteKeyAttr = htmlspecialchars($captchaSiteKey, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">

  <!-- reCAPTCHA v2 (invisível) — SEM ?render= -->
  <?php if ($hasSiteKey): ?>
    <script src="<?= $recaptchaSrc ?>" async defer></script>
  <?php endif; ?>
</head>
<body class="fullscreen-center">

  <div class="login-card">
    <div class="login-illustration"></div>

    <div class="login-form-wrapper">
      <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
        <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg"
             alt="Logo" class="logo">
      </a>

      <!-- Formulário de Login -->
      <form id="login-form" action="/OKR_system/auth/auth_login.php" method="POST" novalidate>
        <div class="mb-3">
          <label for="email" class="form-label">E-mail</label>
          <input type="email" id="email" name="email" class="form-control"
                 placeholder="seu@exemplo.com" autocomplete="username" required>
        </div>

        <div class="mb-3">
          <label for="password" class="form-label">Senha</label>
          <input type="password" id="password" name="password" class="form-control"
                 placeholder="•••••••••" autocomplete="current-password" required>
        </div>

        <!-- reCAPTCHA -->
        <input type="hidden" name="recaptcha_action" value="login">
        <input type="hidden" id="recaptcha_token" name="recaptcha_token" value="">
        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" value="">
        <div id="captcha-error" class="error-message" style="display:none;"></div>

        <div class="mb-3">
          <?php if ($hasSiteKey): ?>
            <!-- v2 invisível: a lib intercepta o clique e chama o callback -->
            <button
              id="btn-entrar"
              type="submit"
              class="g-recaptcha btn btn-primary w-100"
              data-sitekey="<?= $siteKeyAttr ?>"
              data-callback="onLoginCaptchaOk"
              data-action="login">
              Entrar
            </button>
          <?php else: ?>
            <!-- Sem site key configurada? segue sem reCAPTCHA -->
            <button id="btn-entrar" type="submit" class="btn btn-primary w-100">Entrar</button>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <button type="button"
                  onclick="location.href='https://planningbi.com.br/OKR_system/password_reset_request'"
                  class="password-reset-link">
            Esqueci minha senha
          </button>
        </div>

        <!-- Mensagem de erro vinda da sessão -->
        <div class="mb-3">
          <?php if (!empty($_SESSION['error_message'])): ?>
            <div class="error-message">
              <?= htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
          <?php endif; ?>
        </div>
      </form>

      <div class="cta-section">
        <h5>Primeira vez aqui?</h5>
        <p>Crie sua conta e gerencie seus OKRs na palma da mão:</p>
        <a href="https://planningbi.com.br/acesso-antecipado-okr-bsc/" class="btn btn-primary w-100">
          Criar conta grátis
        </a>
      </div>

    </div>
  </div>

<?php if ($hasSiteKey): ?>
<script>
  // Callback do v2 invisível: recebe o token, preenche e envia
  function onLoginCaptchaOk(token) {
    try {
      document.getElementById('recaptcha_token').value = token;
      document.getElementById('g-recaptcha-response').value = token; // compat
      document.getElementById('login-form').submit();
    } catch (e) {
      console.warn('[reCAPTCHA] callback error', e);
      // fallback: envia sem token (ou troque por bloqueio exibindo erro)
      document.getElementById('login-form').submit();
    }
  }
</script>
<?php endif; ?>

</body>
</html>
