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
    :root{ --bg:#0a0e14; --muted:#9aa4b2; --text:#e6edf3; }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue","Noto Sans",Arial}

    .topbar{
      display:flex;justify-content:space-between;align-items:center;
      padding:12px 16px;background:var(--bg);
      border-bottom:1px solid rgba(255,255,255,.06);
      position:relative; /* para o dropdown posicionar */
      z-index:20;
    }

    /* BRAND */
    .brand{display:flex;align-items:center;gap:10px;min-width:0;}
    .brand a{display:flex;align-items:center;text-decoration:none}
    .brand img{
      display:block;height:34px;width:auto;max-width:240px;object-fit:contain;
      image-rendering:-webkit-optimize-contrast;
    }
    .brand small{color:var(--muted);font-weight:600;white-space:nowrap}

    .sr-only{
      position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
      clip:rect(0,0,0,0);white-space:nowrap;border:0
    }

    /* NAV */
    .topnav{display:flex;gap:14px}
    .topnav a{color:var(--muted);text-decoration:none}
    .topnav a:hover{color:var(--text)}

    /* TOGGLE (hamburger) — escondido no desktop */
    .menu-toggle{
      display:none;align-items:center;justify-content:center;
      width:40px;height:40px;border:1px solid rgba(255,255,255,.12);
      border-radius:10px;background:#0d1218;color:var(--text);cursor:pointer;
    }
    .menu-toggle:focus{outline:2px solid rgba(255,255,255,.25);outline-offset:2px}
    .menu-icon{position:relative;width:18px;height:2px;background:var(--text);}
    .menu-icon:before,.menu-icon:after{
      content:"";position:absolute;left:0;width:18px;height:2px;background:var(--text);
    }
    .menu-icon:before{top:-6px}.menu-icon:after{top:6px}

    /* Responsivo */
    @media (max-width:768px){
      .brand img{height:28px;max-width:200px}
      .brand small{font-size:.9rem}

      .menu-toggle{display:flex}              /* mostra o botão */
      .topnav{
        display:none;                         /* esconde menu em mobile por padrão */
        position:absolute; right:16px; top:56px;
        background:#0b1016; padding:10px; border-radius:12px;
        border:1px solid rgba(255,255,255,.08);
        flex-direction:column; gap:10px; min-width:180px;
        box-shadow:0 10px 30px rgba(0,0,0,.35);
      }
      .topbar[data-open="true"] .topnav{display:flex}  /* abre quando toggle ativo */
    }
  </style>
</head>
<body>
<header class="topbar" id="topbar">
  <div class="brand">
    <a href="/" aria-label="PlanningBI - Página inicial">
      <img
        src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol-1024x267.png"
        alt="PlanningBI"
        loading="eager" fetchpriority="high" decoding="async">
      <span class="sr-only">PlanningBI</span>
    </a>
    <small>OKR System</small>
  </div>

  <!-- Botão hambúrguer (apenas mobile) -->
  <button class="menu-toggle" id="menuToggle" aria-label="Abrir menu" aria-controls="mainNav" aria-expanded="false">
    <span class="menu-icon" aria-hidden="true"></span>
  </button>

  <nav class="topnav" id="mainNav" aria-label="Navegação principal">
    <a href="/">Início</a>
    <a href="/acesso-antecipado-okr-bsc/">Sistema</a>
    <a href="https://api.whatsapp.com/send/?phone=5518996538145&text=Quero+saber+mais+sobre+o+curso+de+BSC+%2B+OKRs">Contato</a>
  </nav>
</header>

<script>
  (function(){
    const topbar = document.getElementById('topbar');
    const toggle = document.getElementById('menuToggle');
    const nav    = document.getElementById('mainNav');

    function setOpen(open){
      topbar.dataset.open = open ? 'true' : 'false';
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', ()=> setOpen(topbar.dataset.open !== 'true'));

    // Fecha ao clicar fora
    document.addEventListener('click', (e)=>{
      if (!topbar.contains(e.target)) setOpen(false);
    });

    // Fecha ao navegar por link (melhor UX em mobile)
    nav.addEventListener('click', (e)=>{
      if (e.target.tagName === 'A') setOpen(false);
    });

    // Fecha no ESC
    document.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape') setOpen(false);
    });
  })();
</script>
