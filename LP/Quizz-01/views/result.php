<?php
// views/result.php
require __DIR__ . '/partials/header.php';
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
?>
<section id="result" class="wrap">
  <div class="card">
    <h2>Seu resultado</h2>

    <!-- Layout em áreas: score/topo + bullets + alavancas à esquerda | radar à direita -->
    <div class="result-grid">
      <!-- (1) Score no topo -->
      <div class="score-wrap">
        <div class="score">
          <span class="score-number" id="scoreTotal">--</span>
          <span class="score-label" id="scoreLabel">—</span>
        </div>
      </div>

      <!-- (2) Bullets/insights alinhados no topo ao lado do score -->
      <div class="insights-wrap">
        <ul id="quickInsights" class="insights"></ul>
      </div>

      <!-- (3) Alavancas logo abaixo dos bullets -->
      <div class="levers-wrap">
        <h3>3 alavancas para os próximos 90 dias</h3>
        <ol id="levers" class="levers"></ol>
      </div>

      <!-- (4) Radar ocupa toda a coluna direita -->
      <div class="radar-wrap">
        <canvas id="radarChart" width="360" height="360" aria-label="Radar por domínios"></canvas>
      </div>
    </div>

    <div id="resultMsg" class="msg" aria-live="polite"></div>
  </div>

  <!-- CTA focado em WhatsApp -->
  <div class="card cta-card">
    <div class="cta-badge">Bônus limitado</div>
    <!-- PERSONALIZAÇÃO DINÂMICA -->
    <h3 id="ctaPersonal">Receba seu relatório em PDF no WhatsApp e ganhe <u>3 meses de acesso grátis</u> à plataforma PlanningBI</h3>

    <p class="cta-sub">
      Acesse uma das maiores plataformas de planejamento e gestão de metas do país, com <b>IA integrada</b> que te orienta em cada etapa — da estratégia à execução.
      As <b>instruções de acesso</b> chegam direto no seu WhatsApp junto com o PDF do diagnóstico.
    </p>

    <!-- Aviso de confiança: sem cartão -->
    <div class="trust-note">
      ✅ <b>Sem cartão de crédito.</b> Não pedimos nenhum meio de pagamento e <b>não há cobrança automática</b>. Acesso realmente gratuito por 90 dias.
    </div>

    <form id="formWhats" class="cta-form" novalidate>
      <div class="grid-2">
        <div class="field">
          <label for="whats">WhatsApp</label>

          <!-- Linha com seletor de país (flag + DDI compacto) e campo de telefone -->
          <div class="phone-row" id="phoneRow">
            <button class="flag-btn" id="flagBtn" type="button" aria-haspopup="listbox" aria-expanded="false" data-country="BR" title="Alterar país (atual: Brasil)">
              <span class="flag-ico" id="flagIco" aria-hidden="true"></span>
              <span class="ddi" id="ddiText">+55</span>
              <span class="caret" aria-hidden="true">▾</span>
              <span class="sr-only">Abrir lista de países</span>
            </button>
            <ul class="flag-list" id="flagList" role="listbox" aria-label="Escolher país">
              <li role="option" data-country="BR" data-ddi="+55">🇧🇷 Brasil  (+55)</li>
              <li role="option" data-country="PT" data-ddi="+351">🇵🇹 Portugal (+351)</li>
              <li role="option" data-country="AO" data-ddi="+244">🇦🇴 Angola   (+244)</li>
              <li role="option" data-country="MZ" data-ddi="+258">🇲🇿 Moçambique (+258)</li>
              <li role="option" data-country="CV" data-ddi="+238">🇨🇻 Cabo Verde (+238)</li>
              <li role="option" data-country="GW" data-ddi="+245">🇬🇼 Guiné-Bissau (+245)</li>
              <li role="option" data-country="ST" data-ddi="+239">🇸🇹 São Tomé e Príncipe (+239)</li>
              <li role="option" data-country="TL" data-ddi="+670">🇹🇱 Timor-Leste (+670)</li>
            </ul>

            <!-- Campo de telefone -->
            <div class="input-icon">
              <!-- Ícone WhatsApp -->
              <svg aria-hidden="true" viewBox="0 0 32 32" class="wa-icon">
                <path fill="#25D366" d="M16.01 3.2c-6.98 0-12.66 5.68-12.66 12.66 0 2.23.59 4.33 1.62 6.15L3.2 28.8l6.99-1.82c1.74.95 3.73 1.49 5.82 1.49 6.98 0 12.66-5.68 12.66-12.66S23 3.2 16.01 3.2Zm7.43 18.17c-.3.84-1.48 1.54-2.05 1.58-.53.03-1.2.04-1.94-.12-.45-.1-1.04-.34-1.79-.66-3.15-1.36-5.19-4.52-5.35-4.73-.16-.21-1.28-1.71-1.28-3.26s.8-2.31 1.08-2.63c.28-.32.61-.4.81-.4.2 0 .4 0 .57.01.18.01.43-.07.67.51.26.64.88 2.22.96 2.38.08.16.13.35.02.56-.11.21-.17.35-.32.54-.16.19-.34.42-.49.56-.16.14-.33.3-.14.61.19.32.84 1.38 1.81 2.24 1.25 1.12 2.3 1.47 2.62 1.63.32.16.51.14.7-.08.19-.22.8-.93 1.02-1.25.21-.32.45-.26.76-.16.31.1 1.98.93 2.32 1.1.34.16.56.24.64.38.08.14.08.82-.22 1.66Z"/>
              </svg>
              <input type="tel" id="whats" placeholder="(11) 99999-9999" inputmode="tel" maxlength="20" />
            </div>
          </div>

          <small class="hint" id="hint">Brasil: digite DDD + celular. Ex.: (11) 91234-5678</small>
        </div>
      </div>

      <button id="btnSendWhats" class="btn primary" type="submit">Quero receber no WhatsApp</button>
      <div id="whatsMsg" class="msg" aria-live="polite"></div>
    </form>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  :root { --bg:#0b0d10; --card:#141820; --muted:#9aa4b2; --text:#e6edf3; --brand:#d4af37; --danger:#ff6b6b; --warn:#feca57; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto; }
  .wrap { max-width:980px; margin:40px auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:20px; }
  .card + .card { margin-top:14px; }

  .result-grid{
    display:grid;
    grid-template-columns: 1.1fr 1fr;
    grid-template-areas:
      "score   radar"
      "insights radar"
      "levers  radar";
    gap:14px;
    align-items:start;
  }
  .score-wrap    { grid-area: score; }
  .insights-wrap { grid-area: insights; }
  .levers-wrap   { grid-area: levers; }
  .radar-wrap    { grid-area: radar; display:flex; align-items:center; justify-content:center; }

  .score { display:flex; align-items:baseline; gap:12px; }
  .score-number { font-size:48px; font-weight:900; color:var(--brand); }
  .score-label { font-size:16px; color:var(--muted); }

  .insights { margin:0; padding-left:18px; }
  .insights li { margin:6px 0; }
  .insights li.danger { color:var(--danger); font-weight:700; }
  .insights li.warn   { color:var(--warn);   font-weight:700; }
  .insights li.ok     { color:#cbd5e1; }

  .levers-wrap h3{ margin:6px 0 6px; }
  .levers { margin:0; padding-left:20px; }
  .levers li { margin:6px 0; }

  .btn { border:1px solid rgba(255,255,255,.15); background:#10151c; color:#fff; padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:700; }
  .btn.primary { background:var(--brand); border-color:transparent; color:#0b1117; }
  .msg { margin-top:10px; font-size:14px; color:var(--muted); min-height:18px; }
  .msg.error { color:var(--danger); }

  .cta-card{ overflow:hidden; }
  .cta-badge{
    display:inline-block;
    background:linear-gradient(90deg, #d4af37, #f6e27a);
    color:#1a1a1a; font-weight:900; font-size:12px;
    padding:6px 10px; border-radius:999px;
    margin-bottom:8px;
  }
  .cta-sub{ color:var(--muted); margin-top:6px; }

  .trust-note{
    margin:8px 0 14px;
    padding:10px 12px;
    border:1px dashed rgba(255,255,255,.18);
    border-radius:10px;
    background:#10151c;
    color:#cfe8d1;
  }

  .cta-form .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .field{ display:flex; flex-direction:column; gap:6px; }
  .field input{ background:#0f1319; border:1px solid rgba(255,255,255,.12); border-radius:10px; color:var(--text); padding:12px 14px; }
  .hint{ color:var(--muted); font-size:12px; }

  .phone-row{ display:flex; align-items:center; gap:8px; position:relative; }
  .input-icon{ position:relative; flex:1; }
  .input-icon input{ padding-left:44px; width:100%; }

  .flag-btn{
    display:inline-flex; align-items:center; gap:6px;
    height:36px; padding:0 10px;
    border-radius:8px; background:#0f1319; border:1px solid rgba(255,255,255,.12);
    color:#e6edf3; cursor:pointer;
  }
  .flag-ico{ width:18px; height:14px; background-repeat:no-repeat; background-position:center; background-size:cover; border-radius:2px; box-shadow:0 0 0 1px rgba(255,255,255,.08) inset; }
  .ddi{ font-variant-numeric:tabular-nums; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size:13px; opacity:.95; }
  .caret{ font-size:12px; opacity:.8; }
  .sr-only{ position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }

  .flag-list{
    display:none; position:absolute; top:42px; left:0; z-index:5;
    background:#0f1319; border:1px solid rgba(255,255,255,.12); border-radius:10px;
    list-style:none; padding:6px; margin:0; min-width:260px; max-height:240px; overflow:auto;
  }
  .flag-list li{ padding:8px 10px; border-radius:8px; cursor:pointer; font-size:14px; }
  .flag-list li:hover{ background:#151a22; }

  .wa-icon{
    position:absolute; left:12px; top:50%; transform:translateY(-50%);
    width:22px; height:22px; pointer-events:none;
    filter: drop-shadow(0 0 2px rgba(0,0,0,.4));
  }

  @media (max-width:900px){
    .result-grid{
      grid-template-columns: 1fr;
      grid-template-areas:
        "score"
        "insights"
        "levers"
        "radar";
    }
    .cta-form .grid-2{ grid-template-columns:1fr; }
  }
</style>

<script>
  const SID = <?='"'.htmlspecialchars($sid, ENT_QUOTES).'"'?>;
  if (!SID) { alert('Sessão inválida. Volte à tela inicial.'); location.href='/OKR_system/LP/Quizz-01/views/start.php'; }

  const API_BASE = '/OKR_system/LP/Quizz-01/auth/';
  const API = {
    finalize:  API_BASE+'sessao_finalize.php',
    genPDF:    API_BASE+'report_generate.php',
    sendWhats: API_BASE+'whatsapp_send.php'
  };

  const $ = s => document.querySelector(s);
  let pdfUrl = null;
  let currentCountry = 'BR';
  let currentDDI = '+55';

  async function api(method, url, data){
    const r = await fetch(url, { method, headers:{'Content-Type':'application/json','Accept':'application/json'}, body:data?JSON.stringify(data):undefined, cache:'no-store' });
    const t = await r.text();
    let j; try{ j=JSON.parse(t);}catch{ throw new Error(`Resposta não-JSON de ${method} ${url} (${r.status}). Trecho: ${t.replace(/\s+/g,' ').slice(0,200)}`); }
    if(!r.ok || j.error) throw new Error(j.message||j.error||`HTTP ${r.status}`);
    return j;
  }

  function classify(score){
    if(score>=70) return {tier:'verde',    label:'Verde (Saudável)', color:'#1dd1a1'};
    if(score>=40) return {tier:'amarelo',  label:'Amarelo (Moderado)', color:'#feca57'};
    return {tier:'vermelho', label:'Vermelho (Risco Alto)', color:'#ff6b6b'};
  }

  function buildRadar(scores){
    const labels = Object.keys(scores||{});
    const data = labels.map(k=>scores[k]);
    const ctx = document.getElementById('radarChart').getContext('2d');
    new Chart(ctx,{
      type:'radar',
      data:{ labels, datasets:[{ label:'Score por domínio', data }]},
      options:{
        responsive:true,
        scales:{
          r:{
            suggestedMin:0, suggestedMax:100,
            ticks:{ display:false },
            grid:{ color:'rgba(154,164,178,.35)' },
            angleLines:{ color:'rgba(154,164,178,.35)' },
            pointLabels:{ color:'#9aa4b2' }
          }
        },
        plugins:{ legend:{ display:false } }
      }
    });
  }

  function weakestDomains(scores, n=2){
    const entries = Object.entries(scores||{});
    entries.sort((a,b)=> (a[1]??0) - (b[1]??0));
    return entries.slice(0, n).map(e=>e[0]).filter(Boolean);
  }

  function firstName(full){
    if(!full) return '';
    const n = String(full).trim().split(/\s+/)[0];
    return n.charAt(0).toUpperCase() + n.slice(1);
  }

  function makePersonalCTA({name, tier, lows}){
    const nome = name || 'Você';
    const pontos = lows.length ? lows.join(' e ') : 'os pontos certos';
    if(tier==='amarelo'){
      return `${nome}, você tem uma boa visão — e dá para otimizar ainda mais trabalhando ${pontos}. Estou liberando um material especial e <b>3 meses de acesso grátis</b> à nossa plataforma. Informe seu WhatsApp e enviarei agora para te ajudar a acelerar seus resultados.`;
    }
    if(tier==='verde'){
      return `${nome}, excelente! Seu diagnóstico está saudável. Que tal <b>escalar</b> a execução e padronizar rotinas de alta performance? Vou te enviar um pacote avançado + <b>3 meses de acesso grátis</b> à plataforma para multiplicar resultados.`;
    }
    return `${nome}, ótimo ponto de partida. Para destravar rápido, vamos focar em ${pontos}. Estou te oferecendo um guia prático e <b>3 meses de acesso grátis</b> à plataforma com IA que te orienta passo a passo. Deixe seu WhatsApp e eu envio agora.`;
  }

  function classByPercent(p){
    if (p <= 20) return 'danger';
    if (p <= 50) return 'warn';
    return 'ok';
  }

  // ======= Máscara BR =======
  function formatBRMask(digits){
    const v = digits.replace(/\D/g,'').slice(0,11);
    const ddd = v.slice(0,2);
    const p1  = v.slice(2,7);
    const p2  = v.slice(7,11);
    if(v.length <= 2) return `(${v}`;
    if(v.length <= 7) return `(${ddd}) ${p1}`;
    return `(${ddd}) ${p1}-${p2}`;
  }
  function getBRDigits(){ return $('#whats').value.replace(/\D/g,'').slice(0,11); }
  function applyBRMaskOnInput(){
    const el = $('#whats');
    const digits = el.value.replace(/\D/g,'');
    el.value = formatBRMask(digits);
  }

  // ======= País/DDI =======
  const flagBtn  = document.getElementById('flagBtn');
  const flagList = document.getElementById('flagList');
  const flagIco  = document.getElementById('flagIco');
  const ddiText  = document.getElementById('ddiText');
  const hint     = document.getElementById('hint');
  const inputTel = document.getElementById('whats');

  const FLAG_SVGS = {
    BR: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%2714%27 fill=%27%2300903b%27/><polygon points=%279,2 16,7 9,12 2,7%27 fill=%27%23ffdf00%27/><circle cx=%279%27 cy=%277%27 r=%272.6%27 fill=%27%23003da5%27/></svg>")',
    PT: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%2714%27 fill=%27%23ff0000%27/><rect width=%276.5%27 height=%2714%27 fill=%27%2300903b%27/></svg>")',
    AO: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%277%27 fill=%27%23ce1126%27/><rect y=%277%27 width=%2718%27 height=%277%27 fill=%27000%27/></svg>")',
    MZ: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%274.7%27 fill=%27%2300903b%27/><rect y=%274.7%27 width=%2718%27 height=%274.7%27 fill=%27000%27/><rect y=%279.4%27 width=%2718%27 height=%274.6%27 fill=%27%23ffd700%27/></svg>")',
    CV: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%2714%27 fill=%27003f87%27/><rect y=%276.2%27 width=%2718%27 height=%271.2%27 fill=%27%23fff%27/><rect y=%277.8%27 width=%2718%27 height=%270.7%27 fill=%27%23cf2027%27/></svg>")',
    GW: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%277%27 fill=%27%23fcd116%27/><rect y=%277%27 width=%2718%27 height=%277%27 fill=%27%2300903b%27/><rect width=%275.5%27 height=%2714%27 fill=%27%23ce1126%27/></svg>")',
    ST: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%2714%27 fill=%27%2300903b%27/><rect y=%274.7%27 width=%2718%27 height=%274.7%27 fill=%27%23ffd700%27/><polygon points=%270,0 5.5,7 0,14%27 fill=%27%23ce1126%27/></svg>")',
    TL: 'url("data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 18 14%27><rect width=%2718%27 height=%2714%27 fill=%27%23da291c%27/><polygon points=%270,0 10,7 0,14%27 fill=%27000%27/></svg>")'
  };

  function countryKey(code){ return (code || 'BR').toUpperCase(); }
  function setFlagAndDDI(code, ddi){
    const key = countryKey(code);
    flagIco.style.backgroundImage = FLAG_SVGS[key] || FLAG_SVGS.BR;
    ddiText.textContent = ddi || (key==='BR'?'+55':'');
    flagBtn.dataset.country = key;
    flagBtn.title = `Alterar país (atual: ${key==='BR'?'Brasil': key==='PT'?'Portugal': key==='AO'?'Angola': key==='MZ'?'Moçambique': key==='CV'?'Cabo Verde': key==='GW'?'Guiné-Bissau': key==='ST'?'São Tomé e Príncipe': key==='TL'?'Timor-Leste':'País'})`;
  }

  flagBtn.addEventListener('click', () => {
    const open = flagBtn.getAttribute('aria-expanded') === 'true';
    flagBtn.setAttribute('aria-expanded', String(!open));
    flagList.style.display = open ? 'none' : 'block';
  });

  flagList.addEventListener('click', (e) => {
    const li = e.target.closest('li[role="option"]');
    if(!li) return;
    currentCountry = li.dataset.country;
    currentDDI     = li.dataset.ddi || '';
    setFlagAndDDI(currentCountry, currentDDI);

    flagBtn.setAttribute('aria-expanded', 'false');
    flagList.style.display = 'none';

    if(currentCountry === 'BR'){
      inputTel.value = '';
      inputTel.placeholder = '(11) 99999-9999';
      inputTel.maxLength = 20;
      inputTel.removeAttribute('data-free');
      hint.textContent = 'Brasil: digite DDD + celular. Ex.: (11) 91234-5678';
    }else{
      inputTel.value = '';
      inputTel.placeholder = 'Número com DDI (máx. 25 caracteres)';
      inputTel.maxLength = 25;
      inputTel.setAttribute('data-free','1');
      hint.textContent = `Digite o número incluindo o DDI ${currentDDI}. Máx. 25 caracteres.`;
    }
  });

  inputTel.addEventListener('input', () => {
    if(currentCountry === 'BR' && !inputTel.hasAttribute('data-free')){
      applyBRMaskOnInput();
    }
  });

  // ======= Boot =======
  (async function init(){
    try{
      setFlagAndDDI('BR', '+55');

      // Fecha/calcula (idempotente)
      const out = await api('POST', API.finalize, { session_token: SID });

      const cls = classify(out.score_total);
      $('#scoreTotal').textContent = out.score_total;
      $('#scoreLabel').textContent = cls.label;
      $('#scoreTotal').style.color = cls.color;

      // bullets rápidos
      const ul = $('#quickInsights'); ul.innerHTML='';
      (out.resumo?.bullets||[]).slice(0,3).forEach(t=>{
        const li=document.createElement('li');
        li.textContent=t;
        const m = t.match(/(\d+)\s*%/);
        if (m) li.classList.add(classByPercent(parseInt(m[1],10)));
        ul.appendChild(li);
      });

      // alavancas
      const ol = document.getElementById('levers'); ol.innerHTML='';
      (out.alavancas||[]).slice(0,3).forEach(t=>{
        const li=document.createElement('li'); li.innerHTML=t; ol.appendChild(li);
      });

      // radar
      buildRadar(out.score_por_dominio||{});

      // CTA personalizada (preferir nome salvo no start.php)
      const lsName  = (localStorage.getItem('lead_nome') || '').trim();
      const apiName = (out.lead_nome || out.lead?.nome || out.nome || '').trim();
      const rawName = lsName || apiName;
      const userFirst = firstName(rawName);
      const lows = weakestDomains(out.score_por_dominio||{}, 2);
      const cta = makePersonalCTA({name:userFirst, tier:cls.tier, lows});
      document.getElementById('ctaPersonal').innerHTML = cta;

      // GA4 — result page viewed
      gtag('event', 'quiz_result_view', {
        event_category: 'quiz',
        event_label: 'lp001',
        score_total: out.score_total,
        classificacao: cls.tier
      });

    }catch(err){
      const m = document.getElementById('resultMsg');
      m.classList.add('error');
      m.textContent = err.message||String(err);
      console.error('[result] init error:', err);
    }
  })();

  // ======= Envio WhatsApp =======
  document.getElementById('formWhats').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const w = document.getElementById('whatsMsg'); w.className='msg'; w.textContent='';

    let payloadPhone = '';
    if(currentCountry === 'BR'){
      const digits = getBRDigits();
      if(digits.length !== 11){
        w.classList.add('error');
        w.textContent = 'Digite um número BR válido: 11 dígitos (DDD + celular).';
        return;
      }
      payloadPhone = '+55' + digits; // E.164
    }else{
      const val = inputTel.value.trim();
      if(!val){
        w.classList.add('error');
        w.textContent = 'Informe o número de WhatsApp.';
        return;
      }
      if(val.length > 25){
        w.classList.add('error');
        w.textContent = 'Máximo de 25 caracteres para números internacionais.';
        return;
      }
      payloadPhone = val.startsWith('+') ? val : (currentDDI + ' ' + val);
    }

    try{
      // Gera (ou recupera) PDF seguro do relatório
      if(!pdfUrl){
        const r = await api('POST', API.genPDF, { session_token: SID });
        pdfUrl = r.pdf_url_segura || r.pdf_url || null;
      }

      // Solicita envio por WhatsApp (server decide anexar/encaminhar)
      const r2 = await api('POST', API.sendWhats, {
        session_token: SID,
        telefone_e164: payloadPhone,
        whatsapp_optin: true
      });
      // GA4 — lead WhatsApp submitted (principal conversion)
      gtag('event', 'form_submit', {
        event_category: 'quiz',
        event_label: 'whatsapp_lead'
      });
      gtag('event', 'conversion_event_submit_lead_form', {
        event_category: 'quiz',
        event_label: 'whatsapp_lead',
        value: 1,
        currency: 'BRL'
      });
      gtag('event', 'lead_whatsapp_submit', {
        event_category: 'quiz',
        event_label: 'lp001',
        country: currentCountry
      });

      w.textContent = (r2.status||'queued') === 'sent'
        ? 'Perfeito! Enviamos seu PDF e as instruções de acesso gratuito por WhatsApp.'
        : 'Solicitação enviada. Você receberá o PDF e as instruções de acesso em instantes.';

    }catch(err){
      w.classList.add('error'); w.textContent = 'Não foi possível enviar no WhatsApp: ' + (err.message||String(err));
      console.error('[result] whats error:', err);
    }
  });
</script>
