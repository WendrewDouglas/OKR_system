<?php
session_start();
// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – OKR System</title>
  <!-- CSS Globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <!-- Font Awesome ícones -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css"
        integrity="sha512-KfkfwYDsLkIlwQp6LFnl8zNdLGxu9YAA1QvwINks4PhcElQSvqcyVLLD9aMhXd13uQjoXtEKNosOWaZqXgel0g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <!-- Conteúdo principal com ID para deslocamento -->
    <main id="main-content" style="padding:1rem;">
      <h1>Bem-vindo, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuário') ?></h1>
      <section class="dashboard-cards">
        <div class="card">
          <h3>OKRs Atuais</h3>
          <p>Verifique o progresso dos seus objetivos e resultados-chave.</p>
        </div>
        <div class="card">
          <h3>Mensagens</h3>
          <p>Você tem <?= (int)($_SESSION['new_messages'] ?? 0) ?> novas mensagens.</p>
        </div>
      </section>
    </main>

    <!-- Chat -->
    <?php include __DIR__ . '/partials/chat.php'; ?>

  </div>

  <!-- Scripts Globais -->
  <script>
    // Se houver toggle de submenu, já está no sidebar.js inline
    // Aqui podem ir outros scripts comuns ao dashboard
  </script>
</body>
</html>

<!-- acabou -->
