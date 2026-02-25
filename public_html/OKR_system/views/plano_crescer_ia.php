<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crescer com IA — Plano de Negócio</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #0B0E1A;
  --bg2: #101425;
  --surface: #161A2E;
  --surface2: #1C2140;
  --cyan: #00E5CC;
  --cyan-dim: rgba(0,229,204,0.15);
  --blue: #3B82F6;
  --purple: #A855F7;
  --orange: #F97316;
  --rose: #F43F5E;
  --green: #22C55E;
  --yellow: #FACC15;
  --white: #F0F4FF;
  --gray: #8892B0;
  --gray2: #5A6380;
  --w-color: #3B82F6;
  --b-color: #22C55E;
  --m-color: #F97316;
}

*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

body {
  background: var(--bg);
  color: var(--white);
  font-family: 'Outfit', sans-serif;
  overflow-x: hidden;
  line-height: 1.6;
}

/* Noise overlay */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 9999;
}

/* Scrollbar */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--cyan); border-radius: 3px; }

.container { max-width: 1100px; margin: 0 auto; padding: 0 24px; }

/* ====== HERO ====== */
.hero {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.hero-bg {
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 30%, rgba(0,229,204,0.08) 0%, transparent 60%),
              radial-gradient(ellipse 60% 40% at 80% 70%, rgba(168,85,247,0.06) 0%, transparent 50%);
}
.hero-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(0,229,204,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,229,204,0.03) 1px, transparent 1px);
  background-size: 60px 60px;
  mask-image: radial-gradient(ellipse 70% 60% at 50% 40%, black 20%, transparent 70%);
}
.hero-content { position: relative; text-align: center; }
.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 20px;
  border: 1px solid rgba(0,229,204,0.25);
  border-radius: 100px;
  font-size: 13px;
  color: var(--cyan);
  font-weight: 500;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin-bottom: 32px;
  background: rgba(0,229,204,0.05);
  animation: fadeDown 0.8s ease both;
}
.hero-badge::before { content: '🤖'; font-size: 16px; }

.hero h1 {
  font-size: clamp(3rem, 7vw, 5.5rem);
  font-weight: 900;
  line-height: 1.05;
  letter-spacing: -0.03em;
  margin-bottom: 20px;
  animation: fadeDown 0.8s ease 0.1s both;
}
.hero h1 .gradient {
  background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}
.hero-sub {
  font-size: clamp(1.1rem, 2vw, 1.4rem);
  color: var(--gray);
  max-width: 600px;
  margin: 0 auto 40px;
  font-weight: 300;
  animation: fadeDown 0.8s ease 0.2s both;
}
.hero-stats {
  display: flex;
  justify-content: center;
  gap: 48px;
  flex-wrap: wrap;
  animation: fadeDown 0.8s ease 0.3s both;
}
.hero-stat {
  text-align: center;
}
.hero-stat .num {
  font-family: 'Space Mono', monospace;
  font-size: 2.4rem;
  font-weight: 700;
  color: var(--cyan);
  display: block;
}
.hero-stat .label {
  font-size: 0.85rem;
  color: var(--gray);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 500;
}

.scroll-hint {
  position: absolute;
  bottom: 40px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: var(--gray2);
  font-size: 12px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  animation: pulse 2s ease infinite;
}
.scroll-hint .arrow {
  width: 20px;
  height: 20px;
  border-right: 2px solid var(--cyan);
  border-bottom: 2px solid var(--cyan);
  transform: rotate(45deg);
  opacity: 0.5;
}

/* ====== SECTIONS ====== */
section { padding: 100px 0; position: relative; }

.section-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: 'Space Mono', monospace;
  font-size: 12px;
  color: var(--cyan);
  text-transform: uppercase;
  letter-spacing: 0.15em;
  margin-bottom: 16px;
}
.section-label::before {
  content: '';
  display: block;
  width: 32px;
  height: 1px;
  background: var(--cyan);
}

.section-title {
  font-size: clamp(2rem, 4vw, 3rem);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin-bottom: 48px;
  line-height: 1.1;
}

/* ====== TEAM ====== */
.team-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
}
.team-card {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 20px;
  padding: 32px 28px;
  position: relative;
  overflow: hidden;
  transition: transform 0.3s ease, border-color 0.3s ease;
}
.team-card:hover {
  transform: translateY(-4px);
  border-color: rgba(255,255,255,0.12);
}
.team-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
}
.team-card.w::before { background: linear-gradient(90deg, var(--w-color), var(--blue)); }
.team-card.b::before { background: linear-gradient(90deg, var(--b-color), #10B981); }
.team-card.m::before { background: linear-gradient(90deg, var(--m-color), var(--yellow)); }

.team-icon {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  margin-bottom: 20px;
}
.team-card.w .team-icon { background: rgba(59,130,246,0.12); }
.team-card.b .team-icon { background: rgba(34,197,94,0.12); }
.team-card.m .team-icon { background: rgba(249,115,22,0.12); }

.team-name {
  font-size: 1.3rem;
  font-weight: 700;
  margin-bottom: 4px;
}
.team-card.w .team-name { color: var(--w-color); }
.team-card.b .team-name { color: var(--b-color); }
.team-card.m .team-name { color: var(--m-color); }

.team-role {
  font-size: 0.85rem;
  color: var(--gray);
  margin-bottom: 16px;
  font-weight: 500;
}
.team-desc {
  font-size: 0.9rem;
  color: var(--gray);
  line-height: 1.6;
}
.team-keyword {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 100px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-top: 16px;
}
.team-card.w .team-keyword { background: rgba(59,130,246,0.12); color: var(--w-color); }
.team-card.b .team-keyword { background: rgba(34,197,94,0.12); color: var(--b-color); }
.team-card.m .team-keyword { background: rgba(249,115,22,0.12); color: var(--m-color); }

/* ====== FORMATO ====== */
.format-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}
.format-card {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 16px;
  padding: 28px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.format-card .icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--cyan-dim);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.format-card h3 {
  font-size: 1rem;
  font-weight: 700;
  margin-bottom: 4px;
}
.format-card p {
  font-size: 0.88rem;
  color: var(--gray);
  line-height: 1.5;
}

/* ====== FUNNEL ====== */
.funnel-section { background: var(--bg2); }

.funnel-flow {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  flex-wrap: wrap;
  margin-bottom: 48px;
}
.funnel-step {
  text-align: center;
  padding: 24px 20px;
  flex: 1;
  min-width: 150px;
  position: relative;
}
.funnel-step .num {
  font-family: 'Space Mono', monospace;
  font-size: 2rem;
  font-weight: 700;
  opacity: 0.15;
  position: absolute;
  top: 8px;
  left: 50%;
  transform: translateX(-50%);
}
.funnel-step .emoji {
  font-size: 2.2rem;
  margin-bottom: 12px;
  display: block;
}
.funnel-step .title {
  font-size: 0.95rem;
  font-weight: 700;
  margin-bottom: 4px;
}
.funnel-step .desc {
  font-size: 0.78rem;
  color: var(--gray);
}
.funnel-arrow {
  font-size: 1.5rem;
  color: var(--cyan);
  opacity: 0.4;
  flex-shrink: 0;
}

.channels-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}
.channel-card {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 14px;
  padding: 24px 20px;
  text-align: center;
  transition: border-color 0.3s;
}
.channel-card:hover { border-color: rgba(0,229,204,0.2); }
.channel-card .emoji { font-size: 1.8rem; display: block; margin-bottom: 10px; }
.channel-card h4 { font-size: 0.9rem; font-weight: 700; margin-bottom: 6px; }
.channel-card p { font-size: 0.8rem; color: var(--gray); line-height: 1.5; }
.channel-cost {
  display: inline-block;
  margin-top: 10px;
  padding: 3px 12px;
  border-radius: 100px;
  font-family: 'Space Mono', monospace;
  font-size: 0.72rem;
  font-weight: 700;
}
.channel-cost.free { background: rgba(34,197,94,0.12); color: var(--green); }
.channel-cost.paid { background: rgba(249,115,22,0.12); color: var(--orange); }

/* ====== TIMELINE ====== */
.timeline {
  position: relative;
  padding-left: 80px;
}
.timeline::before {
  content: '';
  position: absolute;
  left: 30px;
  top: 0;
  bottom: 0;
  width: 2px;
  background: linear-gradient(to bottom, var(--cyan), var(--purple), var(--orange), var(--green));
  border-radius: 2px;
}

.tl-item {
  position: relative;
  margin-bottom: 48px;
}
.tl-dot {
  position: absolute;
  left: -56px;
  top: 6px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 3px solid;
}
.tl-item.prep .tl-dot { border-color: var(--cyan); background: var(--bg); }
.tl-item.sales .tl-dot { border-color: var(--orange); background: var(--bg); }
.tl-item.class .tl-dot { border-color: var(--purple); background: var(--bg); }
.tl-item.close .tl-dot { border-color: var(--green); background: var(--bg); }
.tl-item.mile .tl-dot { border-color: var(--rose); background: var(--rose); box-shadow: 0 0 12px rgba(244,63,94,0.4); }

.tl-date {
  font-family: 'Space Mono', monospace;
  font-size: 0.75rem;
  color: var(--gray2);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 6px;
}
.tl-title {
  font-size: 1.15rem;
  font-weight: 700;
  margin-bottom: 6px;
}
.tl-desc {
  font-size: 0.88rem;
  color: var(--gray);
  line-height: 1.6;
  max-width: 700px;
}
.tl-tag {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-top: 8px;
}
.tl-tag.prep { background: rgba(0,229,204,0.12); color: var(--cyan); }
.tl-tag.sales { background: rgba(249,115,22,0.12); color: var(--orange); }
.tl-tag.class { background: rgba(168,85,247,0.12); color: var(--purple); }
.tl-tag.close { background: rgba(34,197,94,0.12); color: var(--green); }
.tl-tag.mile { background: rgba(244,63,94,0.12); color: var(--rose); }

/* ====== FINANCEIRO ====== */
.finance-section { background: var(--bg2); }

.finance-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 48px;
}
.fin-card {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 16px;
  padding: 28px 24px;
  text-align: center;
}
.fin-card .month {
  font-size: 0.85rem;
  color: var(--gray);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 600;
  margin-bottom: 12px;
}
.fin-card .amount {
  font-family: 'Space Mono', monospace;
  font-size: 1.6rem;
  font-weight: 700;
  margin-bottom: 8px;
}
.fin-card .detail {
  font-size: 0.8rem;
  color: var(--gray);
  line-height: 1.5;
}
.fin-card.inv { border-top: 3px solid var(--rose); }
.fin-card.inv .amount { color: var(--rose); }
.fin-card.apr { border-top: 3px solid var(--yellow); }
.fin-card.apr .amount { color: var(--yellow); }
.fin-card.may { border-top: 3px solid var(--orange); }
.fin-card.may .amount { color: var(--orange); }
.fin-card.jun { border-top: 3px solid var(--green); }
.fin-card.jun .amount { color: var(--green); }

.price-row {
  display: flex;
  justify-content: center;
  gap: 32px;
  flex-wrap: wrap;
}
.price-box {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 16px;
  padding: 32px 40px;
  text-align: center;
  position: relative;
}
.price-box.founder { border-color: rgba(0,229,204,0.3); }
.price-box.founder::after {
  content: '★ FUNDADORA';
  position: absolute;
  top: -12px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--cyan);
  color: var(--bg);
  font-size: 0.68rem;
  font-weight: 800;
  padding: 3px 14px;
  border-radius: 100px;
  letter-spacing: 0.08em;
}
.price-box .price {
  font-family: 'Space Mono', monospace;
  font-size: 2.4rem;
  font-weight: 700;
}
.price-box.founder .price { color: var(--cyan); }
.price-box.normal .price { color: var(--gray); }
.price-box .label {
  font-size: 0.85rem;
  color: var(--gray);
  margin-top: 4px;
}

/* ====== VALIDATION ====== */
.validation-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
}
.val-card {
  border-radius: 20px;
  padding: 32px 28px;
  border: 1px solid;
  position: relative;
  overflow: hidden;
}
.val-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  opacity: 0.04;
  border-radius: 20px;
}
.val-card.success {
  border-color: rgba(34,197,94,0.3);
  background: rgba(34,197,94,0.05);
}
.val-card.partial {
  border-color: rgba(250,204,21,0.3);
  background: rgba(250,204,21,0.05);
}
.val-card.fail {
  border-color: rgba(244,63,94,0.3);
  background: rgba(244,63,94,0.05);
}
.val-icon {
  font-size: 2.5rem;
  margin-bottom: 16px;
  display: block;
}
.val-title {
  font-size: 1.2rem;
  font-weight: 800;
  margin-bottom: 12px;
}
.val-card.success .val-title { color: var(--green); }
.val-card.partial .val-title { color: var(--yellow); }
.val-card.fail .val-title { color: var(--rose); }
.val-criteria {
  font-size: 0.88rem;
  color: var(--gray);
  line-height: 1.6;
  margin-bottom: 16px;
}
.val-action {
  font-size: 0.85rem;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 10px;
  display: inline-block;
}
.val-card.success .val-action { background: rgba(34,197,94,0.12); color: var(--green); }
.val-card.partial .val-action { background: rgba(250,204,21,0.12); color: var(--yellow); }
.val-card.fail .val-action { background: rgba(244,63,94,0.12); color: var(--rose); }

/* ====== KR SUMMARY ====== */
.kr-grid {
  display: grid;
  gap: 16px;
}
.kr-row {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 14px;
  padding: 24px 28px;
  display: flex;
  align-items: center;
  gap: 20px;
  transition: border-color 0.3s;
}
.kr-row:hover { border-color: rgba(255,255,255,0.1); }
.kr-badge {
  font-family: 'Space Mono', monospace;
  font-size: 0.8rem;
  font-weight: 700;
  padding: 6px 14px;
  border-radius: 10px;
  flex-shrink: 0;
  min-width: 54px;
  text-align: center;
}
.kr-badge.kr1 { background: rgba(244,63,94,0.12); color: var(--rose); }
.kr-badge.kr2 { background: rgba(249,115,22,0.12); color: var(--orange); }
.kr-badge.kr3 { background: rgba(34,197,94,0.12); color: var(--green); }
.kr-badge.kr4 { background: rgba(168,85,247,0.12); color: var(--purple); }
.kr-badge.kr5 { background: rgba(0,229,204,0.12); color: var(--cyan); }
.kr-text { flex: 1; }
.kr-text h4 { font-size: 1rem; font-weight: 700; margin-bottom: 2px; }
.kr-text p { font-size: 0.82rem; color: var(--gray); }
.kr-target {
  font-family: 'Space Mono', monospace;
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--cyan);
  flex-shrink: 0;
}
.kr-deadline {
  font-size: 0.75rem;
  color: var(--gray2);
  flex-shrink: 0;
  font-weight: 500;
}

/* ====== QUOTE ====== */
.quote-section {
  text-align: center;
  padding: 120px 0;
  background: linear-gradient(180deg, var(--bg) 0%, var(--bg2) 50%, var(--bg) 100%);
}
.quote {
  font-size: clamp(1.6rem, 3.5vw, 2.6rem);
  font-weight: 300;
  color: var(--gray);
  max-width: 800px;
  margin: 0 auto;
  line-height: 1.4;
  letter-spacing: -0.01em;
}
.quote em {
  font-style: normal;
  color: var(--cyan);
  font-weight: 600;
}

/* ====== GOLDEN RULE ====== */
.rule-box {
  background: var(--surface);
  border: 1px solid rgba(0,229,204,0.15);
  border-radius: 20px;
  padding: 40px;
  display: flex;
  align-items: center;
  gap: 40px;
  margin-top: 48px;
}
.rule-icon { font-size: 3rem; flex-shrink: 0; }
.rule-text h3 {
  font-size: 1.2rem;
  font-weight: 800;
  color: var(--cyan);
  margin-bottom: 8px;
}
.rule-text p { color: var(--gray); font-size: 0.95rem; line-height: 1.6; }
.rule-text strong.w { color: var(--w-color); }
.rule-text strong.b { color: var(--b-color); }
.rule-text strong.m { color: var(--m-color); }

/* ====== FOOTER ====== */
footer {
  text-align: center;
  padding: 60px 24px;
  border-top: 1px solid rgba(255,255,255,0.05);
}
footer p { color: var(--gray2); font-size: 0.85rem; }

/* ====== ANIMATIONS ====== */
@keyframes fadeDown {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
  0%, 100% { opacity: 0.4; transform: translateX(-50%) translateY(0); }
  50% { opacity: 0.8; transform: translateX(-50%) translateY(6px); }
}

.reveal {
  opacity: 0;
  transform: translateY(30px);
  transition: opacity 0.7s ease, transform 0.7s ease;
}
.reveal.visible {
  opacity: 1;
  transform: translateY(0);
}

/* ====== RESPONSIVE ====== */
@media (max-width: 768px) {
  .team-grid, .channels-grid, .validation-grid { grid-template-columns: 1fr; }
  .format-grid { grid-template-columns: 1fr; }
  .finance-grid { grid-template-columns: repeat(2, 1fr); }
  .timeline { padding-left: 60px; }
  .timeline::before { left: 20px; }
  .tl-dot { left: -46px; }
  .hero-stats { gap: 24px; }
  .rule-box { flex-direction: column; text-align: center; gap: 20px; }
  .kr-row { flex-wrap: wrap; }
  .kr-target { width: 100%; text-align: left; }
  .price-row { gap: 16px; }
}
</style>
</head>
<body>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid"></div>
  <div class="hero-content">
    <div class="hero-badge">Plano de Negócio 2026</div>
    <h1>
      <span class="gradient">Crescer com IA</span>
    </h1>
    <p class="hero-sub">
      Curso presencial de Inteligência Artificial para crianças de 9 a 12 anos em Araçatuba–SP
    </p>
    <div class="hero-stats">
      <div class="hero-stat">
        <span class="num">12</span>
        <span class="label">Aulas por módulo</span>
      </div>
      <div class="hero-stat">
        <span class="num">1h30</span>
        <span class="label">Por aula</span>
      </div>
      <div class="hero-stat">
        <span class="num">24</span>
        <span class="label">Vagas (2 turmas)</span>
      </div>
      <div class="hero-stat">
        <span class="num">33,3%</span>
        <span class="label">Por sócio</span>
      </div>
    </div>
  </div>
  <div class="scroll-hint">
    <span>Scroll</span>
    <div class="arrow"></div>
  </div>
</section>

<!-- EQUIPE -->
<section>
  <div class="container">
    <div class="reveal">
      <div class="section-label">Equipe</div>
      <h2 class="section-title">Três sócios, três expertises</h2>
    </div>
    <div class="team-grid reveal">
      <div class="team-card w">
        <div class="team-icon">💻</div>
        <div class="team-name">Wendrew</div>
        <div class="team-role">UNESP / USP</div>
        <div class="team-desc">Conteúdo técnico, ferramentas de IA, site, infraestrutura digital. Instrutor em sala com foco em tecnologia.</div>
        <span class="team-keyword">Técnica</span>
      </div>
      <div class="team-card b">
        <div class="team-icon">🧠</div>
        <div class="team-name">Bruna</div>
        <div class="team-role">Psicóloga · CRP 06/153379</div>
        <div class="team-desc">Conteúdo pedagógico, acompanhamento emocional, relatórios individuais. Autoridade clínica no Instagram.</div>
        <span class="team-keyword">Autoridade</span>
      </div>
      <div class="team-card m">
        <div class="team-icon">📣</div>
        <div class="team-name">Marcela</div>
        <div class="team-role">Gestão Comercial</div>
        <div class="team-desc">Motor de distribuição: panfletagem, WhatsApp, follow-up, vendas, cobranças, Instagram, logística.</div>
        <span class="team-keyword">Distribuição</span>
      </div>
    </div>

    <div class="rule-box reveal">
      <div class="rule-icon">⚡</div>
      <div class="rule-text">
        <h3>Regra de Ouro</h3>
        <p><strong class="b">Bruna</strong> é autoridade — conteúdo de valor, presença orgânica, credibilidade clínica.<br>
           <strong class="m">Marcela</strong> é distribuição — panfleto, WhatsApp, follow-up, venda.<br>
           <strong class="w">Wendrew</strong> é técnica — site, IA, ferramentas, sala de aula.<br>
           <em style="color:var(--cyan)">Nunca inverter.</em></p>
      </div>
    </div>
  </div>
</section>

<!-- FORMATO -->
<section style="background:var(--bg2)">
  <div class="container">
    <div class="reveal">
      <div class="section-label">Formato</div>
      <h2 class="section-title">Como funciona o curso</h2>
    </div>
    <div class="format-grid reveal">
      <div class="format-card">
        <div class="icon">⏱️</div>
        <div><h3>Aulas de 1h30</h3><p>2 blocos de 40min com 10min de intervalo. Criança sai querendo mais, não cansada.</p></div>
      </div>
      <div class="format-card">
        <div class="icon">📅</div>
        <div><h3>Sábados de manhã</h3><p>Turma A: 8h–9h30 · Turma B: 10h–11h30. Máximo 12 alunos por turma.</p></div>
      </div>
      <div class="format-card">
        <div class="icon">👨‍🏫</div>
        <div><h3>2 instrutores em sala</h3><p>Wendrew (técnico) + Bruna (pedagógico) em todas as aulas, sempre juntos.</p></div>
      </div>
      <div class="format-card">
        <div class="icon">🎯</div>
        <div><h3>Projeto final</h3><p>Nas aulas 9–12 os alunos criam projetos com IA. Apresentação para os pais no encerramento.</p></div>
      </div>
      <div class="format-card">
        <div class="icon">📱</div>
        <div><h3>Aluno traz tablet</h3><p>Modo foco obrigatório. Regras de uso com embasamento pedagógico da Bruna.</p></div>
      </div>
      <div class="format-card">
        <div class="icon">📊</div>
        <div><h3>Relatório individual</h3><p>Cada aluno recebe relatório de desenvolvimento assinado pela Bruna (CRP).</p></div>
      </div>
    </div>
  </div>
</section>

<!-- FUNIL -->
<section class="funnel-section">
  <div class="container">
    <div class="reveal">
      <div class="section-label">Estratégia</div>
      <h2 class="section-title">Funil de Captação</h2>
    </div>
    <div class="funnel-flow reveal">
      <div class="funnel-step">
        <span class="num">01</span>
        <span class="emoji">📄</span>
        <div class="title">Panfleto + QR</div>
        <div class="desc">Na saída das escolas particulares</div>
      </div>
      <div class="funnel-arrow">→</div>
      <div class="funnel-step">
        <span class="num">02</span>
        <span class="emoji">📚</span>
        <div class="title">E-book gratuito</div>
        <div class="desc">"5 coisas que seu filho já faz com IA"</div>
      </div>
      <div class="funnel-arrow">→</div>
      <div class="funnel-step">
        <span class="num">03</span>
        <span class="emoji">💬</span>
        <div class="title">Lead no WhatsApp</div>
        <div class="desc">Contato direto com a família</div>
      </div>
      <div class="funnel-arrow">→</div>
      <div class="funnel-step">
        <span class="num">04</span>
        <span class="emoji">🎪</span>
        <div class="title">Evento experimental</div>
        <div class="desc">1h gratuita. Pais veem no final</div>
      </div>
      <div class="funnel-arrow">→</div>
      <div class="funnel-step">
        <span class="num">05</span>
        <span class="emoji">✅</span>
        <div class="title">Matrícula</div>
        <div class="desc">Contrato + pagamento no dia</div>
      </div>
    </div>

    <div class="reveal">
      <h3 style="font-size:1.1rem; margin-bottom:20px; color:var(--gray)">Canais de Prospecção</h3>
    </div>
    <div class="channels-grid reveal">
      <div class="channel-card">
        <span class="emoji">🏫</span>
        <h4>Panfletagem escolar</h4>
        <p>3–5 escolas particulares, horário de saída, Marcela pessoalmente. 3 ondas: março, março e junho.</p>
        <span class="channel-cost paid">R$ 200–400</span>
      </div>
      <div class="channel-card">
        <span class="emoji">📸</span>
        <h4>Instagram da Bruna</h4>
        <p>2 posts/semana como psicóloga sobre crianças + IA. Conteúdo de valor, não venda. Menção natural.</p>
        <span class="channel-cost free">R$ 0</span>
      </div>
      <div class="channel-card">
        <span class="emoji">💬</span>
        <h4>Grupos de WhatsApp</h4>
        <p>Marcela distribui e-book. Bruna participa organicamente respondendo dúvidas como profissional.</p>
        <span class="channel-cost free">R$ 0</span>
      </div>
      <div class="channel-card">
        <span class="emoji">🤝</span>
        <h4>Rede pessoal Bruna</h4>
        <p>Mensagens individuais para mães de pacientes e colegas. Pessoal, não automatizado.</p>
        <span class="channel-cost free">R$ 0</span>
      </div>
      <div class="channel-card">
        <span class="emoji">📱</span>
        <h4>Instagram Crescer</h4>
        <p>Conteúdo real das aulas, bastidores, depoimentos. 3+ posts/semana com impulsionamento.</p>
        <span class="channel-cost paid">R$ 150/mês</span>
      </div>
      <div class="channel-card">
        <span class="emoji">🏥</span>
        <h4>Pontos estratégicos</h4>
        <p>Pediatras, escolas de inglês, lojas infantis. Material na recepção com QR do e-book.</p>
        <span class="channel-cost free">incluso</span>
      </div>
    </div>
  </div>
</section>

<!-- TIMELINE -->
<section>
  <div class="container">
    <div class="reveal">
      <div class="section-label">Cronograma</div>
      <h2 class="section-title">Marcos do trimestre</h2>
    </div>
    <div class="timeline">
      <div class="tl-item prep reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">01–07 Março</div>
        <div class="tl-title">Preparação</div>
        <div class="tl-desc">Site, e-book, panfleto com QR, vídeo 60s, planilhas, conteúdo da aula experimental, contrato. Tudo pronto para captar no dia 8.</div>
        <span class="tl-tag prep">Semana 1</span>
      </div>

      <div class="tl-item sales reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">08–14 Março</div>
        <div class="tl-title">Prospecção — Onda 1</div>
        <div class="tl-desc">Panfletagem na saída das escolas, mensagens individuais da Bruna, distribuição do e-book nos grupos de WhatsApp, primeiro post da Bruna no Instagram, impulsionamento.</div>
        <span class="tl-tag sales">Captação</span>
      </div>

      <div class="tl-item mile reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">15 Março (Sábado)</div>
        <div class="tl-title">★ 1º Evento Experimental</div>
        <div class="tl-desc">1h de atividade prática com IA. Crianças experimentam, pais assistem nos 15min finais. Meta: fechar ≥ 6 matrículas no dia (matrícula R$80).</div>
        <span class="tl-tag mile">Marco</span>
      </div>

      <div class="tl-item sales reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">22–31 Março</div>
        <div class="tl-title">Prospecção — Onda 2 + Fechamento</div>
        <div class="tl-desc">2ª panfletagem com fotos do evento. 2º evento experimental se &lt; 12 matrículas. Follow-up com todos os leads. Deadline Turma Fundadora: 31/mar.</div>
        <span class="tl-tag sales">Captação</span>
      </div>

      <div class="tl-item mile reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">31 Março</div>
        <div class="tl-title">★ Deadline Turma Fundadora</div>
        <div class="tl-desc">Preço de R$397/mês travado para quem fechar até esta data. Após: R$497. Meta acumulada: ≥ 12 matrículas.</div>
        <span class="tl-tag mile">Marco</span>
      </div>

      <div class="tl-item mile reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">11 Abril (Sábado)</div>
        <div class="tl-title">★ Primeira Aula Oficial</div>
        <div class="tl-desc">Turma A: 8h–9h30. Turma B: 10h–11h30. Wendrew + Bruna em sala. Marcela recepciona, fotografa, filma.</div>
        <span class="tl-tag class">Aulas</span>
      </div>

      <div class="tl-item class reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">Abril–Maio</div>
        <div class="tl-title">Aulas 1 a 8 + Crescimento</div>
        <div class="tl-desc">Bruna mantém 2 posts/semana no Instagram pessoal. Marcela capta novos alunos. Se &lt; 18 alunos em 25/abr: evento experimental extra em 3/mai. NPS #1 aplicado em maio.</div>
        <span class="tl-tag class">Execução</span>
      </div>

      <div class="tl-item class reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">Maio–Junho</div>
        <div class="tl-title">Aulas 9 a 12 + Projeto Final</div>
        <div class="tl-desc">Crianças desenvolvem projetos com IA. 3ª onda de panfletagem escolar com fotos reais e convite para apresentação. Relatórios individuais (Bruna, CRP).</div>
        <span class="tl-tag class">Execução</span>
      </div>

      <div class="tl-item mile reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">27 Junho (Sábado)</div>
        <div class="tl-title">★ Apresentação dos Projetos Finais</div>
        <div class="tl-desc">Pais assistem. Famílias externas convidadas. Entrega de relatórios e certificados. Abertura de matrículas do Módulo 2. Coleta de depoimentos em vídeo.</div>
        <span class="tl-tag mile">Marco</span>
      </div>

      <div class="tl-item mile reveal">
        <div class="tl-dot"></div>
        <div class="tl-date">30 Junho</div>
        <div class="tl-title">★ Reunião Final — Modelo Validou?</div>
        <div class="tl-desc">Todos os 5 KRs avaliados com números reais. Decisão objetiva: abrir 3ª turma, manter 2, ou parar e reavaliar.</div>
        <span class="tl-tag mile">Validação</span>
      </div>
    </div>
  </div>
</section>

<!-- KRs -->
<section style="background:var(--bg2)">
  <div class="container">
    <div class="reveal">
      <div class="section-label">OKR</div>
      <h2 class="section-title">5 Key Results</h2>
    </div>
    <div class="kr-grid reveal">
      <div class="kr-row">
        <span class="kr-badge kr1">KR1</span>
        <div class="kr-text">
          <h4>Matrículas no Lançamento</h4>
          <p>Alunos pagantes com contrato assinado e 1ª mensalidade paga</p>
        </div>
        <div class="kr-target">≥ 12</div>
        <div class="kr-deadline">até 31/Mar</div>
      </div>
      <div class="kr-row">
        <span class="kr-badge kr2">KR2</span>
        <div class="kr-text">
          <h4>Crescimento da Base</h4>
          <p>Alunos ativos com mensalidade em dia e frequência ≥ 75%</p>
        </div>
        <div class="kr-target">≥ 18</div>
        <div class="kr-deadline">até 31/Mai</div>
      </div>
      <div class="kr-row">
        <span class="kr-badge kr3">KR3</span>
        <div class="kr-text">
          <h4>Lotação das Turmas</h4>
          <p>Alunos ativos distribuídos em 2 turmas, mínimo 10 por turma</p>
        </div>
        <div class="kr-target">≥ 22</div>
        <div class="kr-deadline">até 30/Jun</div>
      </div>
      <div class="kr-row">
        <span class="kr-badge kr4">KR4</span>
        <div class="kr-text">
          <h4>Retenção de Alunos</h4>
          <p>Alunos de abril que permanecem ativos e pagantes em junho</p>
        </div>
        <div class="kr-target">≥ 85%</div>
        <div class="kr-deadline">medido 30/Jun</div>
      </div>
      <div class="kr-row">
        <span class="kr-badge kr5">KR5</span>
        <div class="kr-text">
          <h4>Satisfação dos Pais (NPS)</h4>
          <p>Nota média nas pesquisas de maio e junho (≥ 70% de respostas)</p>
        </div>
        <div class="kr-target">≥ 8,5</div>
        <div class="kr-deadline">Mai + Jun</div>
      </div>
    </div>
  </div>
</section>

<!-- FINANCEIRO -->
<section class="finance-section">
  <div class="container">
    <div class="reveal">
      <div class="section-label">Financeiro</div>
      <h2 class="section-title">Investimento e Projeção</h2>
    </div>
    <div class="price-row reveal" style="margin-bottom:48px">
      <div class="price-box founder">
        <div class="price">R$ 397</div>
        <div class="label">/mês · Turma Fundadora</div>
      </div>
      <div class="price-box normal">
        <div class="price">R$ 497</div>
        <div class="label">/mês · Preço normal (após 31/mar)</div>
      </div>
    </div>
    <div class="finance-grid reveal">
      <div class="fin-card inv">
        <div class="month">Investimento</div>
        <div class="amount">R$ 400–667</div>
        <div class="detail">por sócio<br>Total: R$ 1.200–2.000</div>
      </div>
      <div class="fin-card apr">
        <div class="month">Abril</div>
        <div class="amount">R$ 1.400–1.900</div>
        <div class="detail">por sócio · 12–16 alunos<br>Receita: R$ 4.700–6.300</div>
      </div>
      <div class="fin-card may">
        <div class="month">Maio</div>
        <div class="amount">R$ 1.900–3.000</div>
        <div class="detail">por sócio · 16–20 alunos<br>Receita: R$ 6.300–9.500</div>
      </div>
      <div class="fin-card jun">
        <div class="month">Junho</div>
        <div class="amount">R$ 3.000–3.800</div>
        <div class="detail">por sócio · 20–24 alunos<br>Receita: R$ 9.500–11.900</div>
      </div>
    </div>
  </div>
</section>

<!-- VALIDAÇÃO -->
<section>
  <div class="container">
    <div class="reveal">
      <div class="section-label">30 de Junho</div>
      <h2 class="section-title">Critérios de Validação</h2>
    </div>
    <div class="validation-grid reveal">
      <div class="val-card success">
        <span class="val-icon">🚀</span>
        <div class="val-title">Sucesso</div>
        <div class="val-criteria">≥ 22 alunos ativos<br>NPS ≥ 8,5/10<br>Retenção ≥ 85%</div>
        <span class="val-action">Abrir 3ª turma + Workshop para pais</span>
      </div>
      <div class="val-card partial">
        <span class="val-icon">⚠️</span>
        <div class="val-title">Parcial</div>
        <div class="val-criteria">14–21 alunos ativos<br>NPS ≥ 7,5/10<br>Retenção ≥ 75%</div>
        <span class="val-action">Manter 2 turmas + Ajustar + Novo evento</span>
      </div>
      <div class="val-card fail">
        <span class="val-icon">🛑</span>
        <div class="val-title">Fracasso</div>
        <div class="val-criteria">&lt; 14 alunos OU<br>NPS &lt; 7,5 OU<br>Retenção &lt; 70%</div>
        <span class="val-action">Parar e reavaliar antes de investir mais</span>
      </div>
    </div>
  </div>
</section>

<!-- QUOTE -->
<section class="quote-section">
  <div class="container">
    <p class="quote reveal">
      "Primeiro, <em>12 crianças pagantes</em> até 31 de março.<br>Só isso.<br><br>Depois, dar a <em>melhor aula</em> que essas crianças já tiveram."
    </p>
  </div>
</section>

<footer>
  <p>Crescer com IA · Araçatuba–SP · Fevereiro 2026</p>
</footer>

<script>
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
    }
  });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
</script>
</body>
</html>