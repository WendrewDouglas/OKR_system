<?php
// partials/tutorial.php — Tutorial interativo do sistema OKR
// Incluir no final do <body>, após chat.php
$tutorialUserName = $_SESSION['primeiro_nome'] ?? $_SESSION['user_name'] ?? 'Usuário';
?>

<style>
/* ═══════════════════════════════════════
   TUTORIAL OVERLAY
   ═══════════════════════════════════════ */
#tut-overlay{
  position:fixed; inset:0; z-index:9999;
  background:rgba(0,0,0,.82);
  backdrop-filter:blur(6px);
  display:none; place-items:center;
  opacity:0; transition:opacity .35s ease;
}
#tut-overlay.show{
  display:grid; opacity:1;
}

/* Card central */
#tut-card{
  width:min(680px, 92vw);
  max-height:88vh;
  background:linear-gradient(180deg, #111720, #0b0f14);
  border:1px solid #1e2736;
  border-radius:22px;
  box-shadow:0 30px 80px rgba(0,0,0,.5);
  color:#eaeef6;
  overflow:hidden;
  display:flex; flex-direction:column;
  position:relative;
}

/* Header do card */
.tut-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 22px 14px;
  border-bottom:1px solid #1e2736;
}
.tut-header-left{
  display:flex; align-items:center; gap:10px;
}
.tut-logo{
  width:38px; height:38px; border-radius:12px;
  background:linear-gradient(135deg, var(--gold, #F1C40F), #d4a017);
  display:grid; place-items:center;
  font-size:1.1rem; color:#111;
}
.tut-header-title{
  font-size:.92rem; font-weight:700;
}
.tut-header-sub{
  font-size:.7rem; color:#a6adbb; margin-top:1px;
}
.tut-close{
  width:32px; height:32px; border-radius:8px;
  background:#0e131a; border:1px solid #1e2736;
  color:#a6adbb; display:grid; place-items:center;
  cursor:pointer; font-size:.85rem;
  transition:all .15s;
}
.tut-close:hover{ border-color:#ef4444; color:#ef4444; }

/* Progress bar */
.tut-progress{
  height:3px; background:#1e2736; position:relative;
}
.tut-progress-fill{
  height:100%; background:var(--gold, #F1C40F);
  border-radius:0 3px 3px 0;
  transition:width .4s ease;
}

/* Slide area */
.tut-body{
  flex:1; overflow-y:auto; padding:0;
  position:relative; min-height:320px;
}
.tut-slide{
  position:absolute; inset:0;
  padding:28px 28px 20px;
  display:flex; flex-direction:column;
  opacity:0; transform:translateX(40px);
  transition:opacity .35s ease, transform .35s ease;
  pointer-events:none;
  overflow-y:auto;
}
.tut-slide.active{
  opacity:1; transform:translateX(0);
  pointer-events:auto;
  position:relative;
}
.tut-slide.exit-left{
  opacity:0; transform:translateX(-40px);
}

/* Slide content */
.tut-icon-row{
  display:flex; align-items:center; gap:14px;
  margin-bottom:16px;
}
.tut-icon-circle{
  width:52px; height:52px; border-radius:16px;
  display:grid; place-items:center;
  font-size:1.4rem; flex-shrink:0;
}
.tut-icon-circle.gold{ background:rgba(246,195,67,.14); color:var(--gold, #F1C40F); }
.tut-icon-circle.blue{ background:rgba(96,165,250,.14); color:#60a5fa; }
.tut-icon-circle.green{ background:rgba(34,197,94,.14); color:#22c55e; }
.tut-icon-circle.purple{ background:rgba(168,85,247,.14); color:#a78bfa; }
.tut-icon-circle.red{ background:rgba(239,68,68,.14); color:#ef4444; }
.tut-icon-circle.teal{ background:rgba(20,184,166,.14); color:#14b8a6; }
.tut-icon-circle.ai{
  background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6);
  color:#fff; font-size:1.2rem;
}

.tut-slide-title{
  font-size:1.15rem; font-weight:800; line-height:1.3;
}
.tut-slide-subtitle{
  font-size:.78rem; color:#a6adbb; margin-top:2px;
}

.tut-text{
  font-size:.84rem; color:#c9cfd9; line-height:1.65;
  margin-top:6px;
}
.tut-text strong{ color:#eaeef6; font-weight:600; }

/* Feature cards dentro do slide */
.tut-features{
  display:grid; grid-template-columns:1fr 1fr; gap:10px;
  margin-top:16px;
}
@media(max-width:500px){ .tut-features{ grid-template-columns:1fr; } }

.tut-feat{
  display:flex; align-items:flex-start; gap:10px;
  padding:12px; border-radius:12px;
  background:rgba(255,255,255,.03);
  border:1px solid #1e2736;
  transition:border-color .2s;
}
.tut-feat:hover{ border-color:rgba(246,195,67,.2); }
.tut-feat-icon{
  width:32px; height:32px; border-radius:8px;
  display:grid; place-items:center;
  font-size:.8rem; flex-shrink:0;
}
.tut-feat-title{
  font-size:.78rem; font-weight:700; color:#eaeef6;
}
.tut-feat-desc{
  font-size:.7rem; color:#a6adbb; margin-top:2px; line-height:1.4;
}

/* Tip box */
.tut-tip{
  display:flex; align-items:flex-start; gap:10px;
  margin-top:16px; padding:12px 14px; border-radius:12px;
  background:rgba(96,165,250,.06);
  border:1px solid rgba(96,165,250,.15);
}
.tut-tip i{ color:#60a5fa; font-size:.9rem; margin-top:2px; flex-shrink:0; }
.tut-tip-text{ font-size:.76rem; color:#bfdbfe; line-height:1.5; }

/* Footer (navigation) */
.tut-footer{
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 22px 18px;
  border-top:1px solid #1e2736;
}
.tut-dots{
  display:flex; gap:6px;
}
.tut-dot{
  width:8px; height:8px; border-radius:50%;
  background:#1e2736; transition:all .25s;
  cursor:pointer;
}
.tut-dot.active{
  background:var(--gold, #F1C40F);
  width:22px; border-radius:4px;
}
.tut-dot:hover:not(.active){ background:#3a4050; }

.tut-nav{
  display:flex; gap:8px;
}
.tut-btn{
  padding:8px 18px; border-radius:10px;
  font-size:.8rem; font-weight:600;
  border:1px solid #1e2736;
  background:#0e131a; color:#a6adbb;
  cursor:pointer; transition:all .15s;
}
.tut-btn:hover{ border-color:#3a4050; color:#eaeef6; }
.tut-btn.primary{
  background:var(--gold, #F1C40F); color:#111;
  border-color:var(--gold, #F1C40F);
  box-shadow:0 4px 14px rgba(246,195,67,.25);
}
.tut-btn.primary:hover{
  filter:brightness(.92); transform:translateY(-1px);
}

/* Keyboard hint */
.tut-kbd{
  font-size:.65rem; color:#6b7280; margin-left:auto; margin-right:12px;
}
.tut-kbd kbd{
  padding:2px 5px; border-radius:4px; font-size:.62rem;
  background:#1e2736; border:1px solid #2a3340; color:#a6adbb;
}
</style>

<!-- Tutorial overlay -->
<div id="tut-overlay">
  <div id="tut-card">
    <div class="tut-header">
      <div class="tut-header-left">
        <div class="tut-logo"><i class="fa-solid fa-graduation-cap"></i></div>
        <div>
          <div class="tut-header-title">Tour pelo Sistema</div>
          <div class="tut-header-sub">Conheça as principais funcionalidades</div>
        </div>
      </div>
      <button class="tut-close" onclick="tutClose()" title="Fechar tutorial">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="tut-progress"><div class="tut-progress-fill" id="tut-progress-fill"></div></div>

    <div class="tut-body" id="tut-body">

      <!-- SLIDE 0: Boas-vindas -->
      <div class="tut-slide" data-slide="0">
        <div class="tut-icon-row">
          <div class="tut-icon-circle gold"><i class="fa-solid fa-hand-sparkles"></i></div>
          <div>
            <div class="tut-slide-title">Bem-vindo(a), <?= htmlspecialchars($tutorialUserName) ?>!</div>
            <div class="tut-slide-subtitle">Vamos fazer um tour rápido pelo sistema OKR</div>
          </div>
        </div>
        <div class="tut-text">
          Este sistema foi criado para ajudar você e sua equipe a <strong>definir, acompanhar e alcançar objetivos estratégicos</strong> usando a metodologia OKR (Objectives & Key Results).
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon gold" style="background:rgba(246,195,67,.12)"><i class="fa-solid fa-bullseye"></i></div>
            <div><div class="tut-feat-title">Objetivos</div><div class="tut-feat-desc">Defina metas ambiciosas e alinhadas à estratégia</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon blue" style="background:rgba(96,165,250,.12);color:#60a5fa"><i class="fa-solid fa-key"></i></div>
            <div><div class="tut-feat-title">Key Results</div><div class="tut-feat-desc">Meça o progresso com indicadores claros</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon green" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="fa-solid fa-list-check"></i></div>
            <div><div class="tut-feat-title">Iniciativas</div><div class="tut-feat-desc">Planeje as ações concretas para atingir os resultados</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon purple" style="background:rgba(168,85,247,.12);color:#a78bfa"><i class="fa-solid fa-coins"></i></div>
            <div><div class="tut-feat-title">Orçamentos</div><div class="tut-feat-desc">Controle investimentos e despesas por iniciativa</div></div>
          </div>
        </div>
        <div class="tut-tip">
          <i class="fa-solid fa-lightbulb"></i>
          <div class="tut-tip-text">Use as <strong>setas do teclado</strong> ou os botões abaixo para navegar pelo tour.</div>
        </div>
      </div>

      <!-- SLIDE 1: Dashboard -->
      <div class="tut-slide" data-slide="1">
        <div class="tut-icon-row">
          <div class="tut-icon-circle blue"><i class="fa-solid fa-chart-line"></i></div>
          <div>
            <div class="tut-slide-title">Dashboard</div>
            <div class="tut-slide-subtitle">Visão geral dos seus indicadores</div>
          </div>
        </div>
        <div class="tut-text">
          O Dashboard é sua <strong>página inicial</strong>. Nele você encontra uma visão consolidada de todos os KPIs da sua organização, organizados pelos <strong>4 pilares do BSC</strong> (Balanced Scorecard):
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(243,156,18,.12);color:#f39c12"><i class="fa-solid fa-coins"></i></div>
            <div><div class="tut-feat-title">Financeiro</div><div class="tut-feat-desc">Receita, lucratividade e saúde financeira</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(39,174,96,.12);color:#27ae60"><i class="fa-solid fa-users"></i></div>
            <div><div class="tut-feat-title">Cliente</div><div class="tut-feat-desc">Satisfação, retenção e aquisição de clientes</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(41,128,185,.12);color:#2980b9"><i class="fa-solid fa-gears"></i></div>
            <div><div class="tut-feat-title">Processos Internos</div><div class="tut-feat-desc">Eficiência operacional e qualidade</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fa-solid fa-graduation-cap"></i></div>
            <div><div class="tut-feat-title">Aprendizado</div><div class="tut-feat-desc">Capacitação da equipe e inovação</div></div>
          </div>
        </div>
        <div class="tut-tip">
          <i class="fa-solid fa-lightbulb"></i>
          <div class="tut-tip-text">Os <strong>faróis coloridos</strong> (verde, amarelo, vermelho) indicam rapidamente a saúde de cada objetivo. Verde = no trilho, Amarelo = atenção, Vermelho = crítico.</div>
        </div>
      </div>

      <!-- SLIDE 2: Meus OKRs / Cascata -->
      <div class="tut-slide" data-slide="2">
        <div class="tut-icon-row">
          <div class="tut-icon-circle gold"><i class="fa-solid fa-sitemap"></i></div>
          <div>
            <div class="tut-slide-title">Meus OKRs — Cascata</div>
            <div class="tut-slide-subtitle">Navegue pela hierarquia completa</div>
          </div>
        </div>
        <div class="tut-text">
          A página <strong>Meus OKRs</strong> mostra a <strong>cascata completa</strong> da sua organização. Cada nível pode ser expandido clicando no card:
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;margin:14px 0">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="width:28px;height:28px;border-radius:8px;background:rgba(246,195,67,.14);display:grid;place-items:center;color:var(--gold,#F1C40F);font-size:.8rem;flex-shrink:0"><i class="fa-solid fa-bullseye"></i></span>
            <span style="font-size:.82rem"><strong style="color:var(--gold,#F1C40F)">Objetivo</strong> — O que queremos alcançar</span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;padding-left:20px">
            <span style="width:28px;height:28px;border-radius:8px;background:rgba(96,165,250,.14);display:grid;place-items:center;color:#60a5fa;font-size:.8rem;flex-shrink:0"><i class="fa-solid fa-key"></i></span>
            <span style="font-size:.82rem"><strong style="color:#60a5fa">Key Result</strong> — Como medimos o sucesso</span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;padding-left:40px">
            <span style="width:28px;height:28px;border-radius:8px;background:rgba(34,197,94,.14);display:grid;place-items:center;color:#22c55e;font-size:.8rem;flex-shrink:0"><i class="fa-solid fa-list-check"></i></span>
            <span style="font-size:.82rem"><strong style="color:#22c55e">Iniciativa</strong> — As ações concretas</span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;padding-left:60px">
            <span style="width:28px;height:28px;border-radius:8px;background:rgba(168,85,247,.14);display:grid;place-items:center;color:#a78bfa;font-size:.8rem;flex-shrink:0"><i class="fa-solid fa-coins"></i></span>
            <span style="font-size:.82rem"><strong style="color:#a78bfa">Orçamento</strong> — Investimento associado</span>
          </div>
        </div>
        <div class="tut-tip">
          <i class="fa-solid fa-lightbulb"></i>
          <div class="tut-tip-text">Use o botão <strong>"Toda a Empresa"</strong> para ver a visão completa, ou <strong>"Meus OKRs"</strong> para filtrar apenas onde você participa. Os <strong>avatares de sócios</strong> mostram quem está envolvido em cada nível.</div>
        </div>
      </div>

      <!-- SLIDE 3: Minhas Tarefas -->
      <div class="tut-slide" data-slide="3">
        <div class="tut-icon-row">
          <div class="tut-icon-circle green"><i class="fa-solid fa-clipboard-list"></i></div>
          <div>
            <div class="tut-slide-title">Minhas Tarefas</div>
            <div class="tut-slide-subtitle">Tudo o que você precisa fazer, em um só lugar</div>
          </div>
        </div>
        <div class="tut-text">
          Aqui estão reunidos <strong>todos os itens sob sua responsabilidade</strong> — objetivos, Key Results e iniciativas — organizados por prioridade:
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(239,68,68,.12);color:#ef4444"><i class="fa-solid fa-clock"></i></div>
            <div><div class="tut-feat-title">Atrasadas</div><div class="tut-feat-desc">Itens cujo prazo já passou — atenção imediata</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="fa-solid fa-check-circle"></i></div>
            <div><div class="tut-feat-title">No Prazo</div><div class="tut-feat-desc">Itens dentro do prazo esperado</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(107,114,128,.12);color:#9ca3af"><i class="fa-solid fa-flag-checkered"></i></div>
            <div><div class="tut-feat-title">Concluídas</div><div class="tut-feat-desc">Itens finalizados com sucesso</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(96,165,250,.12);color:#60a5fa"><i class="fa-solid fa-filter"></i></div>
            <div><div class="tut-feat-title">Filtros</div><div class="tut-feat-desc">Filtre por tipo, status ou pesquise por texto</div></div>
          </div>
        </div>
      </div>

      <!-- SLIDE 4: Mapa Estratégico -->
      <div class="tut-slide" data-slide="4">
        <div class="tut-icon-row">
          <div class="tut-icon-circle teal"><i class="fa-solid fa-map"></i></div>
          <div>
            <div class="tut-slide-title">Mapa Estratégico</div>
            <div class="tut-slide-subtitle">Visão de alto nível da estratégia</div>
          </div>
        </div>
        <div class="tut-text">
          O Mapa Estratégico apresenta seus <strong>objetivos agrupados por pilar do BSC</strong> em uma visão visual e intuitiva. Cada card mostra o objetivo com seus indicadores de progresso e farol de confiança.
        </div>
        <div class="tut-text" style="margin-top:10px">
          Esta é a ferramenta ideal para <strong>reuniões estratégicas</strong> e para comunicar o direcionamento da empresa. Os links entre objetivos mostram como eles se conectam entre si.
        </div>
        <div class="tut-tip">
          <i class="fa-solid fa-lightbulb"></i>
          <div class="tut-tip-text">Clique em qualquer objetivo no mapa para ir direto à <strong>página de detalhamento</strong>, onde você encontra os KRs, milestones e apontamentos.</div>
        </div>
      </div>

      <!-- SLIDE 5: Detalhe do Objetivo -->
      <div class="tut-slide" data-slide="5">
        <div class="tut-icon-row">
          <div class="tut-icon-circle blue"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
          <div>
            <div class="tut-slide-title">Detalhamento de OKR</div>
            <div class="tut-slide-subtitle">Gerencie KRs, iniciativas e orçamento</div>
          </div>
        </div>
        <div class="tut-text">
          Ao clicar em <strong>"Detalhar"</strong> em um objetivo, você acessa a página completa de gestão:
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(96,165,250,.12);color:#60a5fa"><i class="fa-solid fa-chart-bar"></i></div>
            <div><div class="tut-feat-title">Milestones</div><div class="tut-feat-desc">Acompanhe marcos intermediários com valores esperado vs. real</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="fa-solid fa-pen-to-square"></i></div>
            <div><div class="tut-feat-title">Apontamentos</div><div class="tut-feat-desc">Registre o progresso real com evidências</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(168,85,247,.12);color:#a78bfa"><i class="fa-solid fa-money-bill-trend-up"></i></div>
            <div><div class="tut-feat-title">Orçamento</div><div class="tut-feat-desc">Veja planejado vs. realizado em gráficos mensais</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(246,195,67,.12);color:var(--gold,#F1C40F)"><i class="fa-solid fa-users-gear"></i></div>
            <div><div class="tut-feat-title">Responsáveis</div><div class="tut-feat-desc">Defina múltiplos responsáveis por iniciativa</div></div>
          </div>
        </div>
      </div>

      <!-- SLIDE 6: Aprovações e Relatórios -->
      <div class="tut-slide" data-slide="6">
        <div class="tut-icon-row">
          <div class="tut-icon-circle red"><i class="fa-solid fa-stamp"></i></div>
          <div>
            <div class="tut-slide-title">Aprovações e Relatórios</div>
            <div class="tut-slide-subtitle">Governança e visibilidade</div>
          </div>
        </div>
        <div class="tut-text">
          O sistema possui um <strong>fluxo de aprovação</strong> para garantir a governança dos seus OKRs e orçamentos:
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(239,68,68,.12);color:#ef4444"><i class="fa-solid fa-stamp"></i></div>
            <div><div class="tut-feat-title">Aprovações</div><div class="tut-feat-desc">Aprove ou solicite revisão de objetivos, KRs e orçamentos</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(96,165,250,.12);color:#60a5fa"><i class="fa-solid fa-file-lines"></i></div>
            <div><div class="tut-feat-title">Relatório One-Page</div><div class="tut-feat-desc">Visão executiva resumida de todos os OKRs em uma única página</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(246,195,67,.12);color:var(--gold,#F1C40F)"><i class="fa-solid fa-bell"></i></div>
            <div><div class="tut-feat-title">Notificações</div><div class="tut-feat-desc">Receba alertas sobre pendências e prazos próximos</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="fa-solid fa-palette"></i></div>
            <div><div class="tut-feat-title">Personalização</div><div class="tut-feat-desc">Configure cores, logo e identidade visual da sua empresa</div></div>
          </div>
        </div>
      </div>

      <!-- SLIDE 7: IA / Encerramento -->
      <div class="tut-slide" data-slide="7">
        <div class="tut-icon-row">
          <div class="tut-icon-circle ai"><i class="fa-solid fa-robot"></i></div>
          <div>
            <div class="tut-slide-title">Seu Assistente de IA</div>
            <div class="tut-slide-subtitle">Sempre à disposição para ajudar</div>
          </div>
        </div>
        <div class="tut-text">
          Você tem à disposição o <strong>OKR Master</strong>, uma IA especializada em OKRs que pode te ajudar sempre que precisar:
        </div>
        <div class="tut-features">
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(6,182,212,.12);color:#06b6d4"><i class="fa-solid fa-circle-question"></i></div>
            <div><div class="tut-feat-title">Tire dúvidas</div><div class="tut-feat-desc">"O que significa baseline?" "Como definir uma boa meta?"</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
            <div><div class="tut-feat-title">Sugestões</div><div class="tut-feat-desc">Peça ajuda para escrever objetivos, KRs e iniciativas</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(96,165,250,.12);color:#60a5fa"><i class="fa-solid fa-route"></i></div>
            <div><div class="tut-feat-title">Orientações</div><div class="tut-feat-desc">"Como faço para lançar uma despesa?" "Onde vejo meu progresso?"</div></div>
          </div>
          <div class="tut-feat">
            <div class="tut-feat-icon" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="fa-solid fa-brain"></i></div>
            <div><div class="tut-feat-title">Análises</div><div class="tut-feat-desc">Peça avaliação da qualidade dos seus OKRs</div></div>
          </div>
        </div>
        <div class="tut-tip" style="background:rgba(246,195,67,.06);border-color:rgba(246,195,67,.15)">
          <i class="fa-solid fa-sparkles" style="color:var(--gold,#F1C40F)"></i>
          <div class="tut-tip-text" style="color:#fde68a">
            Clique no <strong>ícone verde</strong> no canto da tela para abrir o chat a qualquer momento. E sempre que quiser rever este tutorial, clique no <strong><i class="fa-solid fa-graduation-cap"></i></strong> no cabeçalho.
          </div>
        </div>
      </div>

    </div><!-- /tut-body -->

    <div class="tut-footer">
      <div class="tut-dots" id="tut-dots"></div>
      <span class="tut-kbd"><kbd>&larr;</kbd> <kbd>&rarr;</kbd> para navegar</span>
      <div class="tut-nav">
        <button class="tut-btn" id="tut-prev" onclick="tutPrev()">
          <i class="fa-solid fa-chevron-left"></i> Anterior
        </button>
        <button class="tut-btn primary" id="tut-next" onclick="tutNext()">
          Próximo <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';

  const TOTAL_SLIDES = 8;
  let current = 0;
  let isOpen = false;

  const overlay   = document.getElementById('tut-overlay');
  const progressF = document.getElementById('tut-progress-fill');
  const dotsEl    = document.getElementById('tut-dots');
  const prevBtn   = document.getElementById('tut-prev');
  const nextBtn   = document.getElementById('tut-next');
  const body      = document.getElementById('tut-body');

  // Build dots
  for (let i = 0; i < TOTAL_SLIDES; i++) {
    const d = document.createElement('span');
    d.className = 'tut-dot' + (i === 0 ? ' active' : '');
    d.onclick = () => tutGo(i);
    dotsEl.appendChild(d);
  }

  function updateUI() {
    // Progress
    progressF.style.width = ((current + 1) / TOTAL_SLIDES * 100) + '%';

    // Dots
    dotsEl.querySelectorAll('.tut-dot').forEach((d, i) => {
      d.classList.toggle('active', i === current);
    });

    // Slides
    body.querySelectorAll('.tut-slide').forEach((s, i) => {
      s.classList.remove('active', 'exit-left');
      if (i === current) {
        s.classList.add('active');
      } else if (i < current) {
        s.classList.add('exit-left');
      }
    });

    // Buttons
    prevBtn.style.visibility = current === 0 ? 'hidden' : 'visible';
    if (current === TOTAL_SLIDES - 1) {
      nextBtn.innerHTML = '<i class="fa-solid fa-check"></i> Concluir';
    } else {
      nextBtn.innerHTML = 'Próximo <i class="fa-solid fa-chevron-right"></i>';
    }
  }

  window.tutOpen = function() {
    current = 0;
    updateUI();
    overlay.classList.add('show');
    isOpen = true;
    // Save that user has seen the tutorial
    try { localStorage.setItem('okr_tutorial_seen', '1'); } catch(e){}
  };

  window.tutClose = function() {
    overlay.classList.remove('show');
    isOpen = false;
  };

  window.tutNext = function() {
    if (current < TOTAL_SLIDES - 1) {
      current++;
      updateUI();
    } else {
      tutClose();
    }
  };

  window.tutPrev = function() {
    if (current > 0) {
      current--;
      updateUI();
    }
  };

  window.tutGo = function(i) {
    if (i >= 0 && i < TOTAL_SLIDES) {
      current = i;
      updateUI();
    }
  };

  // Keyboard navigation
  document.addEventListener('keydown', function(e) {
    if (!isOpen) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); tutNext(); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); tutPrev(); }
    if (e.key === 'Escape') { e.preventDefault(); tutClose(); }
  });

  // Click outside to close
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) tutClose();
  });

  // Auto-open on first visit
  document.addEventListener('DOMContentLoaded', function() {
    try {
      if (!localStorage.getItem('okr_tutorial_seen')) {
        setTimeout(tutOpen, 800);
      }
    } catch(e){}
  });
})();
</script>
