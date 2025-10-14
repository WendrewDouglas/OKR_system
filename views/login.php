<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/**
 * Opcional: caso seu projeto já tenha um bootstrap/config, inclua-o.
 * (mantive comentado para não quebrar ambientes sem este arquivo)
 */
// require_once __DIR__ . '/../auth/config.php';

/** Captura a site key do .env / ambiente */
$captchaSiteKey =
  getenv('CAPTCHA_SITE_KEY')
  ?: ($_ENV['CAPTCHA_SITE_KEY'] ?? (defined('CAPTCHA_SITE_KEY') ? CAPTCHA_SITE_KEY : ''));

// Sanitiza para uso em HTML/JS
$captchaSiteKeyAttr = htmlspecialchars($captchaSiteKey, ENT_QUOTES, 'UTF-8');
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

  <!-- reCAPTCHA Enterprise: carrega já com render=SITE_KEY -->
  <script src="https://www.google.com/recaptcha/api.js?render=<?=$captchaSiteKeyAttr?>" async defer></script>
</head>
<body class="fullscreen-center">

  <div class="login-card">

    <!-- 1. Ilustração (metade esquerda) -->
    <div class="login-illustration">
      <!-- placeholder para a ilustração -->
    </div>

    <!-- 2. Formulário e CTA (metade direita) -->
    <div class="login-form-wrapper">

      <!-- Logo clicável -->
      <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
        <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg"
             alt="Logo"
             class="logo">
      </a>

      <!-- Formulário de Login (único) -->
      <form id="login-form" action="/OKR_system/auth/auth_login.php" method="POST" novalidate>
        <div class="mb-3">
          <label for="email" class="form-label">E-mail</label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control"
                 placeholder="seu@exemplo.com"
                 autocomplete="username"
                 required>
        </div>

        <div class="mb-3">
          <label for="password" class="form-label">Senha</label>
          <input type="password"
                 id="password"
                 name="password"
                 class="form-control"
                 placeholder="•••••••••"
                 autocomplete="current-password"
                 required>
        </div>

        <!-- Campos ocultos p/ token do reCAPTCHA -->
        <input type="hidden" name="recaptcha_action" value="login">
        <input type="hidden" id="recaptcha_token" name="recaptcha_token" value="">
        <!-- Compatibilidade: alguns backends esperam este nome -->
        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" value="">

        <!-- Mensagem de erro do reCAPTCHA (UX) -->
        <div id="captcha-error" class="error-message" style="display:none;"></div>

        <div class="mb-3">
          <button id="btn-entrar" type="submit" class="btn btn-primary w-100">Entrar</button>
        </div>

        <div class="mb-3">
          <button type="button"
                  onclick="location.href='https://planningbi.com.br/OKR_system/password_reset_request'"
                  class="password-reset-link">
            Esqueci minha senha
          </button>
        </div>

        <!-- Mensagem de erro centralizada e formatada -->
        <div class="mb-3">
          <?php if (!empty($_SESSION['error_message'])): ?>
            <div class="error-message">
              <?= htmlspecialchars($_SESSION['error_message'], ENT_QUOTES); ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
          <?php endif; ?>
        </div>
      </form>

      <!-- Call to Action para Cadastro -->
      <div class="cta-section">
        <h5>Primeira vez aqui?</h5>
        <p>Crie sua conta e gerencie seus OKRs na palma da mão:</p>
        <a href="https://planningbi.com.br/acesso-antecipado-okr-bsc/"
           class="btn btn-primary w-100">
          Criar conta grátis
        </a>
      </div>

    </div>
  </div>

  <script>
    // Chave do site vinda do PHP (.env)
    const RECAPTCHA_SITE_KEY = <?= json_encode($captchaSiteKey, JSON_UNESCAPED_SLASHES) ?>;

    // Helper: exibe mensagens de erro de forma amigável
    function showCaptchaError(msg) {
      var box = document.getElementById('captcha-error');
      if (!box) return;
      box.textContent = msg || 'Não foi possível validar o reCAPTCHA. Tente novamente.';
      box.style.display = 'block';
    }

    document.addEventListener('DOMContentLoaded', function () {
      var form  = document.getElementById('login-form');
      var btn   = document.getElementById('btn-entrar');
      var tok1  = document.getElementById('recaptcha_token');
      var tok2  = document.getElementById('g-recaptcha-response');

      if (!form) return;

      form.addEventListener('submit', function (e) {
        // Se já temos token preenchido, deixa seguir (evita loop)
        if (tok1 && tok1.value) return;

        e.preventDefault();

        if (!RECAPTCHA_SITE_KEY) {
          // Se a chave não está configurada, evitamos bloquear o login,
          // mas alertamos para configuração correta em produção.
          console.warn('CAPTCHA_SITE_KEY não configurado. Submetendo sem token.');
          form.submit();
          return;
        }

        // Desabilita o botão para evitar duplo clique
        if (btn) btn.disabled = true;

        // Gera o token do reCAPTCHA Enterprise
        if (typeof grecaptcha === 'undefined') {
          if (btn) btn.disabled = false;
          showCaptchaError('Falha ao carregar o reCAPTCHA. Atualize a página e tente novamente.');
          return;
        }

        grecaptcha.ready(function () {
          grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'login' })
            .then(function (token) {
              if (tok1) tok1.value = token;
              if (tok2) tok2.value = token; // compatibilidade
              form.submit();
            })
            .catch(function () {
              if (btn) btn.disabled = false;
              showCaptchaError();
            });
        });
      }, false);
    });
  </script>

</body>
</html>
