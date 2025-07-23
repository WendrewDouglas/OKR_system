<?php
// password_reset_request.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';


// Conexão PDO…
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Erro ao conectar ao banco.";
    exit;
}

$feedbackType  = $_SESSION['feedback_type']  ?? '';
$feedbackEmail = $_SESSION['feedback_email'] ?? '';
unset($_SESSION['feedback_type'], $_SESSION['feedback_email']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $_SESSION['feedback_type']  = 'error';
        $_SESSION['feedback_email'] = '';
    } else {
        try {
            $stmt = $pdo->prepare(
              'SELECT id_user FROM usuarios 
               WHERE LOWER(TRIM(email_corporativo)) = LOWER(TRIM(:email))'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // NÃO lançar exceção aqui!
            if (!$user) {
                // não achou
                $_SESSION['feedback_type']  = 'error';
                $_SESSION['feedback_email'] = $email;
            } else {
              // sucesso: gera token
              $token = bin2hex(random_bytes(16));

              // salva no banco
              $stmt = $pdo->prepare("
                  INSERT INTO usuarios_password_resets (user_id, token, expira_em)
                  VALUES (:uid, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))
              ");
              $stmt->execute([
                  'uid'   => $user['id_user'],
                  'token' => $token,
              ]);

              // envia o e-mail
              if (! sendPasswordResetEmail($email, $token)) {
                  // se falhar, registre no log, mas mantenha o feedback de sucesso
                  error_log("Falha ao enviar e-mail de recuperação para {$email}");
              }

              $_SESSION['feedback_type']  = 'success';
              $_SESSION['feedback_email'] = $email;            
            }
        } catch (Throwable $e) {
            // aquisições de erro real de sistema: define error
            $_SESSION['feedback_type']  = 'error';
            $_SESSION['feedback_email'] = $email;
        }
    }

    header('Location: /OKR_system/views/password_reset_request.php');
    exit;
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
      max-width: 400px;
      width: 90%;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
      text-align: center;
    }
    .modal-box h3 { margin-bottom: 1rem; }
    .modal-box p { margin-bottom: 1.5rem; }
    .modal-box a.btn { display: inline-block; margin-top: .5rem; }
  </style>
</head>
<body class="fullscreen-center">
  <div class="login-card">
    <div class="login-illustration"></div>
    <div class="login-form-wrapper">

      <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
        <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizontal.jpg"
             alt="Logo" class="logo">
      </a>

      <div style="text-align:center; margin-bottom:3rem;">
        <h2 style="margin-bottom:.2rem;">Recuperar Senha</h2>
        <p>Digite seu e-mail para receber o link de redefinição.</p>
      </div>

      <form action="/OKR_system/views/password_reset_request.php" method="POST">
        <div class="mb-4">
          <label for="email" class="form-label d-block text-center mb-2">
            E-mail cadastrado
          </label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control mx-auto"
                 style="max-width:300px;"
                 placeholder="seu@exemplo.com"
                 required>
        </div>
        <div class="text-center" style="margin-top:2rem;">
          <button type="submit" class="btn btn-primary w-50">
            Recuperar senha
          </button>
        </div>
      </form>

      <div style="display:flex; justify-content:center; margin-top:1rem;">
        <a href="https://planningbi.com.br/OKR_system/login">Voltar ao login</a>
      </div>

    </div>
  </div>

  <!-- Modal de feedback -->
  <div id="feedbackModal" class="modal-overlay">
    <div class="modal-box">
      <?php if ($feedbackType === 'success'): ?>
        <h3>E-mail enviado!</h3>
        <p>
          E-mail de recuperação de senha enviado para<br>
          <strong><?= htmlspecialchars($feedbackEmail) ?></strong><br>
          Caso não receba, verifique a caixa de spam ou entre em contato com nosso suporte.
        </p>
        <button id="closeModal" class="btn btn-primary">Ok</button>

      <?php elseif ($feedbackType === 'error'): ?>
        <h3>E-mail não encontrado</h3>
        <p>
          Não identificamos <strong><?= htmlspecialchars($feedbackEmail) ?></strong><br>
          em nossa base de usuários.<br>
          Caso não tenha se cadastrado, <a href="https://planningbi.com.br/OKR_system/cadastro_site">clique aqui</a>.<br>
          Em casos de problemas, acione nosso suporte.
        </p>
        <button id="closeModal" class="btn btn-secondary">Ok</button>
      <?php endif; ?>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const modal = document.getElementById('feedbackModal');
      if (modal && !modal.classList.contains('')) {
        // Se houve feedback, feedbackType não vazio → exibe modal
        <?php if ($feedbackType): ?>
          modal.classList.add('active');
        <?php endif; ?>

        // fechar ao clicar no botão
        document.getElementById('closeModal')
          .addEventListener('click', () => modal.classList.remove('active'));
      }
    });
  </script>
</body>
</html>
