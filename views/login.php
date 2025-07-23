<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
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

      <!-- Formulário de Login -->
      <form action="/OKR_system/auth/auth_login.php" method="POST">
        <div class="mb-3">
          <label for="email" class="form-label">E-mail</label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control"
                 placeholder="seu@exemplo.com"
                 required>
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Senha</label>
          <input type="password"
                 id="password"
                 name="password"
                 class="form-control"
                 placeholder="•••••••••"
                 required>
        </div>
        <div class="mb-3">
          <button type="submit" class="btn btn-primary w-100">Entrar</button>
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
        <a href="https://planningbi.com.br/OKR_system/cadastro_site"
           class="btn btn-primary w-100">
          Criar conta grátis
        </a>
      </div>

    </div>
  </div>

</body>
</html>
