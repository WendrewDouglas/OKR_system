<?php
?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Diagnóstico Executivo & OKRs – PlanningBI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Quizz executivo de diagnóstico de execução & OKRs. Receba um relatório em PDF.">
  <link rel="icon" href="/OKR_system/assets/favicon.png">
  <style>
    :root{
      --bg:#0a0e14;
      --muted:#9aa4b2;
      --text:#e6edf3;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue","Noto Sans",Arial}
    .topbar{
      display:flex;justify-content:space-between;align-items:center;
      padding:12px 16px;background:var(--bg);
      border-bottom:1px solid rgba(255,255,255,.06)
    }
    /* BRAND */
    .brand{display:flex;align-items:center;gap:10px;min-width:0;}
    .brand a{display:flex;align-items:center;text-decoration:none}
    .brand img{
      display:block;
      height:34px;              /* altura padrão do cabeçalho */
      width:auto;               /* mantém proporção */
      max-width:240px;          /* evita esticar demais em telas largas */
      object-fit:contain;
      image-rendering:-webkit-optimize-contrast;
    }
    .brand small{
      color:var(--muted);font-weight:600;white-space:nowrap
    }
    /* acessibilidade: esconder visualmente, mas manter para leitores de tela */
    .sr-only{
      position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
      clip:rect(0,0,0,0);white-space:nowrap;border:0
    }

    /* NAV */
    .topnav{display:flex;flex-wrap:wrap;gap:14px}
    .topnav a{color:var(--muted);text-decoration:none}
    .topnav a:hover{color:var(--text)}

    /* responsivo: reduz um pouco a logo em telas menores */
    @media (max-width:640px){
      .brand img{height:28px;max-width:200px}
      .brand small{font-size:.9rem}
    }
  </style>
</head>
<body>
<header class="topbar">
  <div class="brand">
    <a href="/" aria-label="PlanningBI - Página inicial">
      <img
        src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol-1024x267.png"
        alt="PlanningBI"
        loading="eager"
        fetchpriority="high"
        decoding="async"
      >
      <span class="sr-only">PlanningBI</span>
    </a>
    <small>OKR System</small>
  </div>

  <nav class="topnav" aria-label="Navegação principal">
    <a href="/">Início</a>
    <a href="/acesso-antecipado-okr-bsc/">Sistema</a>
    <a href="https://api.whatsapp.com/send/?phone=5518996538145&text=Quero+saber+mais+sobre+o+curso+de+BSC+%2B+OKRs">Contato</a>
  </nav>
</header>
