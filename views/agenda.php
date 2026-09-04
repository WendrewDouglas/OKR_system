<?php
// views/agenda.php — Agenda geral (calendário unificado de prazos).
// Fase 1: grade do mês + painel do dia. Filtros e demais visões vêm nas próximas.
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/helpers/nome_format.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/helpers/agenda_events.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// Conexão
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die('Erro ao conectar ao banco.');
}

$currentUserId = (int)$_SESSION['user_id'];

// Empresa do usuário: a agenda é sempre da própria company.
$companyId = (int)($_SESSION['id_company'] ?? $_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
  $stC = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :uid LIMIT 1");
  $stC->execute([':uid' => $currentUserId]);
  $companyId = (int)$stC->fetchColumn();
  if ($companyId > 0) { $_SESSION['id_company'] = $companyId; }
}
if ($companyId <= 0) {
  header('Location: /OKR_system/organizacao');
  exit;
}

$dados = agenda_build_events($pdo, $companyId);

$totalEventos = count($dados['eventos']);
$totalPessoas = count($dados['pessoas']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agenda – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="/OKR_system/assets/company_theme.php?cid=<?= $companyId ?>">
  <link rel="stylesheet" href="/OKR_system/assets/css/pages/agenda.css">
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="ag-page">

      <div class="ag-head">
        <div>
          <h1><i class="fa-regular fa-calendar-days"></i>Agenda</h1>
          <div class="ag-sub">
            <?= (int)$totalEventos ?> prazos da empresa, de <?= (int)$totalPessoas ?> responsáveis
          </div>
        </div>
        <div class="ag-nav">
          <button type="button" id="agPrev" aria-label="Mês anterior"><i class="fa-solid fa-chevron-left"></i></button>
          <div class="ag-periodo" id="agPeriodo">—</div>
          <button type="button" id="agNext" aria-label="Próximo mês"><i class="fa-solid fa-chevron-right"></i></button>
          <button type="button" id="agHoje" class="ag-hoje-btn">Hoje</button>
        </div>
      </div>

      <div class="ag-resumo" id="agResumo"></div>

      <div class="ag-main">
      <div class="ag-cal">

      <div class="ag-legenda">
        <span class="item"><i class="fa-solid fa-bullseye"></i>Objetivo</span>
        <span class="item"><i class="fa-solid fa-crosshairs"></i>Key Result</span>
        <span class="item"><i class="fa-solid fa-list-check"></i>Iniciativa</span>
        <span class="item"><i class="fa-solid fa-circle"></i>Marco</span>
        <span class="item"><i class="fa-solid fa-flag"></i>Início</span>
        <span class="sep"></span>
        <span class="item"><span class="dot" style="background:var(--ag-vencido)"></span>Vencido</span>
        <span class="item"><span class="dot" style="background:var(--ag-hoje)"></span>Hoje</span>
        <span class="item"><span class="dot" style="background:var(--ag-proximo)"></span>7 dias</span>
        <span class="item"><span class="dot" style="background:var(--ag-futuro)"></span>Futuro</span>
        <span class="item"><span class="dot" style="background:var(--ag-concluido)"></span>Concluído</span>
        <span class="item"><span class="dot" style="background:var(--ag-neutro)"></span>Cancelado / pausado</span>
      </div>

      <div class="ag-grid" id="agGrid" role="grid" aria-label="Calendário de prazos"></div>

      </div><!-- /ag-cal -->

      <aside class="ag-rail" id="agDia" aria-live="polite"></aside>
      </div><!-- /ag-main -->

    </main>
  </div>

  <script>
    window.AGENDA = <?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="/OKR_system/assets/js/agenda.js"></script>
</body>
</html>
