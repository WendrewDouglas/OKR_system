<?php
// Cabecalho compartilhado da Avaliacao OKR Master.
// $PAGE_TITLE pode ser definido antes do require.
$titulo = isset($PAGE_TITLE) ? $PAGE_TITLE : 'Avaliação OKR Master';
$base   = '/OKR_system/LP/Quiz-OKRMaster';
?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars($titulo); ?> – PlanningBI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="description" content="Avaliação do Programa de Formação OKR Master – PlanningBI.">
  <link rel="icon" href="/OKR_system/assets/favicon.png">

  <!-- Google Tag Manager -->
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
  new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
  j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
  'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
  })(window,document,'script','dataLayer','GTM-TX984ZMD');</script>
  <!-- End Google Tag Manager -->

  <script async src="https://www.googletagmanager.com/gtag/js?id=GT-NMDJD744"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'GT-NMDJD744');
  </script>

  <link rel="stylesheet" href="<?php echo $base; ?>/assets/quiz.css">
  <style>
    .topbar{display:flex;justify-content:space-between;align-items:center;
      padding:12px 16px;background:var(--bg-top);border-bottom:1px solid var(--line);position:relative;z-index:20}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand a{display:flex;align-items:center;text-decoration:none}
    .brand img{display:block;height:32px;width:auto;max-width:230px;object-fit:contain}
    .brand small{color:var(--muted);font-weight:600;white-space:nowrap}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    .prog-mod{color:var(--muted);font-size:13px;white-space:nowrap}
    .prog-mod b{color:var(--brand)}
  </style>
</head>
<body>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TX984ZMD"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

<header class="topbar">
  <div class="brand">
    <a href="https://planningbi.com.br" aria-label="PlanningBI">
      <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol-1024x267.png"
           alt="PlanningBI" loading="eager" decoding="async">
      <span class="sr-only">PlanningBI</span>
    </a>
    <small>OKR Master</small>
  </div>
  <div class="prog-mod">Módulo <b>1</b> · Balanced Scorecard</div>
</header>
