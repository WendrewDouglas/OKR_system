<?php
// /OKR_system/views/password_reset_request.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Gera CSRF se não existir
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Conexão PDO
try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
  http_response_code(500);
  echo "Erro ao conectar ao banco.";
  exit;
}

// Lê feedback neutro de sessão (exibir modal)
$feedbackNeutral = !empty($_SESSION['reset_feedback_neutral']);
unset($_SESSION['reset_feedback_neutral']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Sempre retorna resposta neutra no final
  $redirect = function() {
    $_SESSION['reset_feedback_neutral'] = true;
    header('Location: /OKR_system/views/password_reset_request.php');
    exit;
  };

  // Valida CSRF (resposta neutra em caso de falha)
  $csrfSess = $_SESSION['csrf_token'] ?? '';
  $csrfPost = $_POST['csrf_token'] ?? '';
  if (!$csrfSess || !hash_equals($csrfSess, (string)$csrfPost)) {
    $redirect();
  }

  // E-mail (não vazar motivo na resposta)
  $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
  if (!$email) {
    $redirect();
  }
  $email = trim($email);

  // CAPTCHA (se ligado) — resposta neutra em caso de falha
  $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
  $captchaToken = $_POST['g-recaptcha-response'] ?? $_POST['h-captcha-response'] ?? $_POST['captcha_token'] ?? null;
  try {
    verifyCaptchaOrFail($captchaToken, $ip);
  } catch (Throwable $e) {
    $redirect();
  }

  // Busca usuário (sem vazar se existe)
  $stmt = $pdo->prepare(
    "SELECT id_user
       FROM usuarios
      WHERE LOWER(TRIM(email_corporativo)) = LOWER(TRIM(:email))
      LIMIT 1"
  );
  $stmt->execute([':email' => $email]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $userId = $row ? (int)$row['id_user'] : null;

  // Rate limit (por IP e, se houver, por user_id)
  try {
    rateLimitResetRequestOrFail($pdo, $userId, $ip);
  } catch (Throwable $e) {
    // Mesmo com rate limit excedido, resposta é neutra
    $redirect();
  }

  // Se o usuário existe, gera selector/verifier e envia e-mail
  if ($userId !== null) {
    try {
      [$selector, $verifier] = generateSelectorVerifier();
      $verifierHash = hashVerifier($verifier);

      $pdo->beginTransaction();

      // Invalida resets anteriores do usuário (limpeza simples)
      $pdo->prepare("DELETE FROM usuarios_password_resets WHERE user_id = ?")
          ->execute([$userId]);

      // Insere novo reset com expiração de 1 hora
      $ins = $pdo->prepare("
        INSERT INTO usuarios_password_resets
          (user_id, selector, verifier_hash, expira_em, created_at, ip_request, user_agent_request)
        VALUES
          (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), ?, ?)
      ");
      $ins->execute([$userId, $selector, $verifierHash, $ip, $ua]);

      $pdo->commit();

      // Envia e-mail (silenciar exceções para manter resposta neutra)
      try {
        sendPasswordResetEmail($email, $selector, $verifier);
      } catch (Throwable $e) {
        // loga e segue neutro
        app_log('RESET_EMAIL_SEND_FAIL', ['email' => mask_email($email), 'err' => $e->getMessage()]);
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // Falhas internas → ainda assim resposta neutra
      app_log('RESET_REQUEST_INTERNAL_FAIL', ['email' => mask_email($email), 'err' => $e->getMessage()]);
    }
  }

  // Sempre resposta neutra
  $redirect();
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperar Senha – OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">

  <?php if (CAPTCHA_PROVIDER === 'recaptcha' && CAPTCHA_SITE_KEY): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php elseif (CAPTCHA_PROVIDER === 'hcaptcha' && CAPTCHA_SITE_KEY): ?>
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
  <?php endif; ?>

  <style>
    /* Modal overlay */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.5);
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }
    .modal-overlay.active { display: flex; }

    /* Modal box */
    .modal-box {
      background: #fff;
      padding: 2rem;
      border-radius: .5rem;
      max-width: 420px;
      width: 90%;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
      text-align: center;
    }
    .modal-box h3 { margin-bottom: 1rem; }
    .modal-box p  { margin-bottom: 1.5rem; }
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

      <div style="text-align:center; margin-bottom:2rem;">
        <h2 style="margin-bottom:.3rem;">Recuperar Senha</h2>
        <p>Digite seu e-mail para receber o link de redefinição.</p>
      </div>

      <form action="/OKR_system/views/password_reset_request.php" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

        <div class="mb-4">
          <label for="email" class="form-label d-block text-center mb-2">E-mail cadastrado</label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control mx-auto"
                 style="max-width:300px;"
                 placeholder="seu@exemplo.com"
                 required>
        </div>

        <?php if (CAPTCHA_PROVIDER === 'recaptcha' && CAPTCHA_SITE_KEY): ?>
          <div class="mb-3" style="display:flex; justify-content:center;">
            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(CAPTCHA_SITE_KEY) ?>"></div>
          </div>
        <?php elseif (CAPTCHA_PROVIDER === 'hcaptcha' && CAPTCHA_SITE_KEY): ?>
          <div class="mb-3" style="display:flex; justify-content:center;">
            <div class="h-captcha" data-sitekey="<?= htmlspecialchars(CAPTCHA_SITE_KEY) ?>"></div>
          </div>
        <?php endif; ?>

        <div class="text-center" style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary w-50">Recuperar senha</button>
        </div>
      </form>

      <div style="display:flex; justify-content:center; margin-top:1rem;">
        <a href="/OKR_system/views/login.php">Voltar ao login</a>
      </div>
    </div>
  </div>

  <!-- Modal de feedback neutro -->
  <div id="feedbackModal" class="modal-overlay">
    <div class="modal-box">
      <h3>Verifique seu e-mail</h3>
      <p>
        Se o e-mail informado estiver cadastrado, enviaremos um link para redefinição de senha.
        Verifique sua caixa de entrada e o spam.
      </p>
      <button id="closeModal" class="btn btn-primary">Ok</button>
    </div>
  </div>

  <script>
    (function() {
      const modal = document.getElementById('feedbackModal');
      const shouldShow = <?= $feedbackNeutral ? 'true' : 'false' ?>;
      if (shouldShow && modal) {
        modal.classList.add('active');
        const btn = document.getElementById('closeModal');
        if (btn) btn.addEventListener('click', () => modal.classList.remove('active'));
      }
    })();
  </script>
</body>
</html>
