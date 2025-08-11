<?php
// views/home.php

// DEV ONLY (remova em produção)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// Conexão
try {
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

// Totais
$totais = $pdo->query("
  SELECT
    (SELECT COUNT(*) FROM objetivos) AS total_obj,
    (SELECT COUNT(*) FROM key_results) AS total_kr,
    (SELECT COUNT(*) FROM key_results kr
      WHERE kr.dt_conclusao IS NOT NULL
         OR kr.status IN ('Concluído','Concluido','Completo','Finalizado')
    ) AS total_kr_done,
    (SELECT COUNT(*) FROM key_results kr
      WHERE kr.status = 'Em Risco'
         OR (kr.dt_conclusao IS NULL AND kr.data_fim IS NOT NULL AND kr.data_fim < CURDATE())
         OR kr.farol IN ('vermelho','amarelo')
    ) AS total_kr_risk
")->fetch();

// Pilares BSC
$pilares = $pdo->query("
  SELECT
    p.id_pilar,
    p.descricao_exibicao AS pilar_nome,
    COALESCE(COUNT(DISTINCT o.id_objetivo),0) AS objetivos,
    COALESCE(COUNT(kr.id_kr),0) AS krs,
    COALESCE(SUM(CASE
      WHEN kr.dt_conclusao IS NOT NULL OR kr.status IN ('Concluído','Concluido','Completo','Finalizado') THEN 1
      ELSE 0 END),0) AS krs_concluidos,
    COALESCE(SUM(CASE
      WHEN kr.status = 'Em Risco'
        OR (kr.dt_conclusao IS NULL AND kr.data_fim IS NOT NULL AND kr.data_fim < CURDATE())
        OR kr.farol IN ('vermelho','amarelo') THEN 1
      ELSE 0 END),0) AS krs_risco
  FROM dom_pilar_bsc p
  LEFT JOIN objetivos o ON o.pilar_bsc = p.id_pilar
  LEFT JOIN key_results kr ON kr.id_objetivo = o.id_objetivo
  GROUP BY p.id_pilar, p.descricao_exibicao
  ORDER BY p.id_pilar
")->fetchAll();

function pct($parte, $todo) { return $todo ? (int)round(($parte/$todo)*100) : 0; }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – OKR System</title>

  <!-- CSS globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    /* ===== Fundo branco pedido ===== */
    body { background:#fff !important; color:#111; }

    /* ===== Layout que se adapta ao chat ===== */
    :root{ --chat-w: 0px; }              /* largura atual do chat (JS atualiza) */
    .content { background: transparent; }
    main.dashboard-container{
      padding: 24px;
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
      margin-right: var(--chat-w);       /* dá espaço para o chat expandido */
      transition: margin-right .25s ease;
    }

    /* ==== Paleta e cards (tema escuro nos cards) ==== */
    :root{
      --bg-soft:#171b21;
      --card:#12161c;
      --muted:#a6adbb;
      --text:#eaeef6;
      --gold:#f6c343;
      --green:#22c55e;
      --blue:#60a5fa;
      --red:#ef4444;
      --border:#222733;
      --shadow:0 10px 30px rgba(0,0,0,.20);
    }

    /* VISÃO & MISSÃO */
    .vision-mission{
      display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    }
    @media (max-width: 900px){ .vision-mission{ grid-template-columns: 1fr; } }
    .vm-card{
      background: linear-gradient(180deg, var(--card), #0d1117);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px 22px;
      box-shadow: var(--shadow);
      position: relative; overflow: hidden;
      color: var(--text);
    }
    .vm-card:before{
      content:""; position:absolute; inset:0;
      background: radial-gradient(600px 120px at 10% -10%, rgba(246,195,67,.12), transparent 40%),
                  radial-gradient(500px 200px at 110% 10%, rgba(96,165,250,.10), transparent 50%);
      pointer-events:none;
    }
    .vm-title{ display:flex; align-items:center; gap:10px; margin-bottom:8px; font-weight:700; letter-spacing:.3px; }
    .vm-title .badge{ background: var(--gold); color:#1a1a1a; padding:6px 10px; border-radius:999px; font-size:.75rem; font-weight:800; text-transform:uppercase; }
    .vm-text{ color:var(--muted); line-height:1.6; }

    /* PILARES */
    .pillars{ display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; }
    @media (max-width: 1200px){ .pillars{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px){ .pillars{ grid-template-columns: 1fr; } }
    .pillar-card{
      background: var(--bg-soft);
      border: 1px solid var(--border);
      border-radius: 16px; padding: 18px; box-shadow: var(--shadow);
      position:relative; overflow:hidden; transition: transform .2s ease, border-color .2s ease; color: var(--text);
    }
    .pillar-card:hover{ transform: translateY(-2px); border-color: #293140; }
    .pillar-header{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
    .pillar-title{ display:flex; align-items:center; gap:10px; font-weight:700; }
    .pillar-title i{
      color: var(--gold); background: rgba(246,195,67,.12); width: 40px; height: 40px; border-radius: 12px;
      display:grid; place-items:center; border:1px solid rgba(246,195,67,.25);
    }
    .pillar-stats{ display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin:12px 0 10px; }
    .stat{ background: #0e131a; border:1px solid var(--border); border-radius: 12px; padding:10px; text-align:center; }
    .stat .label{ font-size:.75rem; color:var(--muted); }
    .stat .value{ font-size:1.25rem; font-weight:800; letter-spacing:.2px; color:var(--text); }
    .progress-wrap{ margin-top:10px; }
    .progress-label{ display:flex; align-items:center; justify-content:space-between; font-size:.85rem; color:var(--muted); margin-bottom:6px;}
    .progress-bar{ width:100%; height:10px; background:#0b0f14; border:1px solid var(--border); border-radius:999px; overflow:hidden; }
    .progress-fill{ height:100%; width:0%; background: linear-gradient(90deg, var(--gold), var(--green)); border-right:1px solid rgba(255,255,255,.15); transition: width 1s ease-in-out; }

    .risk-badge{
      display:inline-flex; align-items:center; gap:6px;
      background: rgba(239,68,68,.12);
      color: #fecaca;
      border:1px solid rgba(239,68,68,.35);
      padding:4px 10px; border-radius:999px;
      font-size:.8rem; font-weight:700;
    }
    /* rodapé centralizado do card (risco) */
    .pillar-footer{
      margin-top: 12px;
      display:flex;
      justify-content:center;
    }

    /* KPIs */
    .kpi-row{ display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; }
    @media (max-width: 1200px){ .kpi-row{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px){ .kpi-row{ grid-template-columns: 1fr; } }
    .kpi-card{
      background: linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border);
      border-radius:16px; padding:18px; box-shadow: var(--shadow);
      position:relative; overflow:hidden; color: var(--text);
    }
    .kpi-card .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; color:var(--muted); font-size:.9rem; }
    .kpi-card .kpi-value{ font-size:2rem; font-weight:900; letter-spacing:.3px; }
    .kpi-icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; border:1px solid var(--border); color:#c7d2fe; background:rgba(96,165,250,.12); }
    .kpi-card.success .kpi-icon{ color:#86efac; background:rgba(34,197,94,.12); }
    .kpi-card.danger .kpi-icon{ color:#fca5a5; background:rgba(239,68,68,.12); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="dashboard-container">

      <!-- Visão & Missão -->
      <section class="vision-mission">
        <article class="vm-card">
          <div class="vm-title"><span class="badge">Visão</span><i class="fa-regular fa-eye"></i></div>
          <p class="vm-text">
            Ser referência em excelência operacional e inovação, impulsionando crescimento sustentável e impacto positivo no mercado.
            (Texto de exemplo — substitua pela visão oficial).
          </p>
        </article>
        <article class="vm-card">
          <div class="vm-title"><span class="badge">Missão</span><i class="fa-solid fa-rocket"></i></div>
          <p class="vm-text">
            Entregar valor contínuo aos clientes por meio de soluções simples, seguras e escaláveis,
            promovendo a alta performance das equipes. (Texto de exemplo — substitua pela missão oficial).
          </p>
        </article>
      </section>

      <!-- Pilares BSC -->
      <section class="pillars">
        <?php foreach ($pilares as $p):
          $pctPilar = pct((int)$p['krs_concluidos'], (int)$p['krs']);
        ?>
        <div class="pillar-card">
          <div class="pillar-header">
            <div class="pillar-title">
              <i class="fa-solid fa-layer-group"></i>
              <span><?= htmlspecialchars($p['pilar_nome'] ?: 'Pilar') ?></span>
            </div>
            <!-- (badge de risco removida do topo) -->
          </div>

          <div class="pillar-stats">
            <div class="stat">
              <div class="label">Objetivos</div>
              <div class="value countup" data-target="<?= (int)$p['objetivos'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">KRs</div>
              <div class="value countup" data-target="<?= (int)$p['krs'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">Concluídos</div>
              <div class="value countup" data-target="<?= (int)$p['krs_concluidos'] ?>">0</div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-label">
              <span>Progresso do pilar</span>
              <strong><span class="progress-pct"><?= $pctPilar ?></span>%</strong>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:0%" data-final="<?= $pctPilar ?>"></div>
            </div>
          </div>

          <!-- Rodapé com risco centralizado -->
          <div class="pillar-footer">
            <span class="risk-badge">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <?= (int)$p['krs_risco'] ?> em risco
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <!-- KPIs gerais -->
      <section class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-head"><span>Total de Objetivos</span><div class="kpi-icon"><i class="fa-solid fa-bullseye"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_obj'] ?>">0</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-head"><span>Total de KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr'] ?>">0</div>
        </div>
        <div class="kpi-card success">
          <div class="kpi-head"><span>KRs Concluídos</span><div class="kpi-icon"><i class="fa-solid fa-check-double"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_done'] ?>">0</div>
        </div>
        <div class="kpi-card danger">
          <div class="kpi-head"><span>KRs em Risco</span><div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_risk'] ?>">0</div>
        </div>
      </section>
    </main>

    <!-- Chat (inalterado) -->
    <?php include __DIR__ . '/partials/chat.php'; ?>
  </div>

  <script>
    // --------- Count-up e progress ----------
    function animateCounter(el, target, duration=900){
      const start=0, t0=performance.now();
      function tick(t){
        const p=Math.min((t-t0)/duration,1);
        el.textContent = Math.floor(start+(target-start)*p).toLocaleString('pt-BR');
        if(p<1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    }
    function animateProgressBars(){
      document.querySelectorAll('.progress-fill').forEach(bar=>{
        const to=parseInt(bar.getAttribute('data-final')||'0',10);
        requestAnimationFrame(()=>{ bar.style.width = Math.max(0,Math.min(100,to))+'%'; });
      });
    }

    // --------- Adaptação ao chat ----------
    // Tenta detectar elementos comuns do seu chat:
    const CHAT_SELECTORS = ['#chatPanel', '.chat-panel', '.chat-container', '#chat', '.drawer-chat'];
    const TOGGLE_SELECTORS = ['#chatToggle', '.chat-toggle', '.btn-chat-toggle', '.chat-icon', '.chat-open'];

    function findChatEl(){
      for(const s of CHAT_SELECTORS){
        const el=document.querySelector(s);
        if(el) return el;
      }
      return null;
    }
    function findToggleEls(){
      let arr=[];
      TOGGLE_SELECTORS.forEach(s=>{
        document.querySelectorAll(s).forEach(btn=>arr.push(btn));
      });
      return arr;
    }
    function isOpen(el){
      const style = getComputedStyle(el);
      const visible = style.display!=='none' && style.visibility!=='hidden';
      const w = el.offsetWidth;
      return (visible && w>0) || el.classList.contains('open') || el.classList.contains('show') || el.getAttribute('aria-expanded')==='true';
    }
    function updateChatWidth(){
      const el = findChatEl();
      const w = (el && isOpen(el)) ? el.offsetWidth : 0;
      document.documentElement.style.setProperty('--chat-w', (w||0)+'px');
    }
    function setupChatObservers(){
      const chat = findChatEl();
      if(!chat) return;
      const mo = new MutationObserver(()=>updateChatWidth());
      mo.observe(chat, { attributes:true, attributeFilter:['style','class','aria-expanded'] });
      window.addEventListener('resize', updateChatWidth);
      findToggleEls().forEach(btn=>{
        btn.addEventListener('click', ()=>setTimeout(updateChatWidth, 200));
      });
      updateChatWidth();
    }

    document.addEventListener('DOMContentLoaded', ()=>{
      document.querySelectorAll('.countup[data-target]').forEach(el=>{
        const tgt = parseInt(el.getAttribute('data-target')||'0',10);
        animateCounter(el, tgt, 800 + Math.random()*400);
      });
      animateProgressBars();

      setupChatObservers();
      const moBody = new MutationObserver(()=>{
        if(findChatEl()){ setupChatObservers(); moBody.disconnect(); }
      });
      moBody.observe(document.body, { childList:true, subtree:true });
    });
  </script>
</body>
</html>
