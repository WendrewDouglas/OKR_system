<?php
// Header
require __DIR__ . '/partials/header.php';
?>
<!-- ===== BODY: o quiz inteiro ===== -->
<section id="start" class="wrap">
  <div class="hero">
    <h1>Diagnóstico Executivo de Execução &amp; OKRs</h1>
    <p class="sub">Resultado em 4 minutos. Receba o relatório em PDF.</p>

    <form id="formStart" class="card" novalidate>
      <div class="field">
        <label for="email">E-mail corporativo</label>
        <input type="email" id="email" name="email" placeholder="nome@empresa.com.br" required autocomplete="email" />
        <small class="hint">Usaremos para gerar e enviar seu relatório. Nada de spam.</small>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="nome">Nome (opcional)</label>
          <input type="text" id="nome" name="nome" autocomplete="name" />
        </div>
        <div class="field">
          <label for="cargo">Cargo (opcional)</label>
          <select id="cargo" name="cargo">
            <option value="">Selecione…</option>
            <option>CEO</option><option>COO</option><option>CTO</option>
            <option>CFO</option><option>Diretor</option><option>Gerente</option>
          </select>
        </div>
      </div>

      <label class="check">
        <input type="checkbox" id="consent_termos" checked required />
        <span>Autorizo o uso dos meus dados para gerar o relatório do diagnóstico.</span>
      </label>
      <label class="check">
        <input type="checkbox" id="consent_marketing" />
        <span>Quero receber conteúdos sobre execução/OKRs.</span>
      </label>

      <button id="btnStart" type="submit" class="btn primary">Começar diagnóstico</button>
      <div id="startMsg" class="msg" aria-live="polite"></div>
    </form>
  </div>
</section>

<section id="quiz" class="wrap hidden" aria-hidden="true">
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
    <div class="progress-bar" id="progressBar" style="width:0%"></div>
    <div class="progress-text" id="progressText">0%</div>
  </div>

  <div id="questionCard" class="card">
    <div class="q-head">
      <span id="qDomain" class="chip">Domínio</span>
      <span id="qIndex" class="step">Pergunta 1 de N</span>
    </div>
    <h2 id="qText">Texto da pergunta</h2>
    <div id="qOptions" class="options" role="group" aria-labelledby="qText"></div>

    <div class="actions">
      <button id="btnPrev" class="btn ghost" type="button" disabled>Voltar</button>
      <button id="btnNext" class="btn primary" type="button" disabled>Avançar</button>
    </div>
    <div id="quizMsg" class="msg" aria-live="polite"></div>
  </div>
</section>

<section id="result" class="wrap hidden" aria-hidden="true">
  <div class="card">
    <h2>Seu resultado</h2>
    <div class="grid-2">
      <div>
        <div class="score">
          <span class="score-number" id="scoreTotal">--</span>
          <span class="score-label" id="scoreLabel">—</span>
        </div>
        <ul id="quickInsights" class="insights"></ul>
      </div>
      <div>
        <canvas id="radarChart" width="360" height="360" aria-label="Radar por domínios"></canvas>
      </div>
    </div>

    <div class="divider"></div>

    <h3>3 alavancas para os próximos 90 dias</h3>
    <ol id="levers" class="levers"></ol>

    <div class="flex gap">
      <a id="btnPDF" class="btn" href="#" target="_blank" rel="noopener" download>Baixar PDF</a>
      <button id="btnGeneratePDF" class="btn primary" type="button">Gerar/atualizar PDF</button>
    </div>
    <div id="resultMsg" class="msg" aria-live="polite"></div>
  </div>

  <div class="card">
    <h3>Quer receber o PDF no seu WhatsApp?</h3>
    <form id="formWhats" novalidate>
      <div class="grid-2">
        <div class="field">
          <label for="whats">WhatsApp (DDI + DDD + número)</label>
          <input type="tel" id="whats" placeholder="+55 11 99999-9999" inputmode="tel" />
          <small class="hint">Formato E.164. Ex.: <b>+5511999999999</b></small>
        </div>
        <div class="field">
          <label>&nbsp;</label>
          <label class="check">
            <input type="checkbox" id="whatsOptin" />
            <span>Aceito receber meu relatório via WhatsApp.</span>
          </label>
        </div>
      </div>
      <button id="btnSendWhats" class="btn" type="submit">Enviar PDF por WhatsApp</button>
      <div id="whatsMsg" class="msg" aria-live="polite"></div>
    </form>
  </div>
</section>

<?php
// Footer
require __DIR__ . '/partials/footer.php';
?>

<!-- ===== ESTILOS (inline para simplificar) ===== -->
<style>
  /* ALTERAÇÃO: apenas --brand passou a ser gold */
  :root { --bg:#0b0d10; --card:#141820; --muted:#9aa4b2; --text:#e6edf3; --brand:#d4af37; --brand-2:#1dd1a1; --danger:#ff6b6b; --warn:#feca57; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue","Noto Sans",Arial; }
  .wrap { max-width:980px; margin:40px auto; padding:0 16px; }
  .hidden { display:none; }
  .hero { text-align:center; margin-bottom:24px; }
  h1 { font-size:32px; margin:8px 0; }
  .sub { color:var(--muted); margin-bottom:24px; }
  .card { background:var(--card); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
  .field { margin-bottom:14px; }
  label { display:block; margin-bottom:6px; font-weight:600; }
  input, select { width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,.1); background:#0f1319; color:var(--text); outline:none; }
  input:focus, select:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(79,140,255,.2); }
  .hint { color:var(--muted); font-size:12px; }
  .check { display:flex; gap:10px; align-items:flex-start; color:var(--muted); font-size:14px; }
  .check input { width:auto; margin-top:3px; }
  .btn { border:1px solid rgba(255,255,255,.15); background:#10151c; color:var(--text); padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:700; }
  .btn:hover { filter:brightness(1.1); }
  .btn.primary { background:var(--brand); border-color:transparent; color:#0b1117; }
  .btn.ghost { background:transparent; }
  .msg { margin-top:10px; font-size:14px; color:var(--muted); min-height:18px; }
  .msg.error { color:var(--danger); }
  .msg.warn { color:var(--warn); }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .progress { position:relative; height:10px; background:#10141a; border-radius:999px; margin-bottom:16px; }
  .progress-bar { height:10px; background:linear-gradient(90deg, var(--brand), var(--brand-2)); border-radius:999px; transition:width .25s ease; }
  .progress-text { position:absolute; top:-26px; right:0; font-size:12px; color:var(--muted); }
  .q-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
  .chip { display:inline-block; background:#0f1319; border:1px solid rgba(255,255,255,.12); color:var(--muted); padding:6px 10px; border-radius:999px; font-size:12px; }
  .step { color:var(--muted); font-size:12px; }
  h2 { margin:8px 0 10px; }
  .options { display:grid; gap:10px; }
  .opt { border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:14px; display:flex; gap:12px; align-items:flex-start; cursor:pointer; background:#0f1319; }
  .opt input { margin-top:3px; }
  .opt.selected { border-color:var(--brand); box-shadow:0 0 0 3px rgba(79,140,255,.2); }
  .actions { display:flex; justify-content:space-between; margin-top:14px; }
  .score { display:flex; align-items:baseline; gap:12px; }
  .score-number { font-size:48px; font-weight:900; }
  .score-label { font-size:16px; color:var(--muted); }
  .divider { height:1px; background:rgba(255,255,255,.06); margin:16px 0; }
  .levers { margin:0; padding-left:18px; }
  .insights { margin:0; padding-left:16px; color:var(--muted); }
  .flex.gap { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  input[type="checkbox"], input[type="radio"] { accent-color: var(--brand); }
  @media (max-width:800px){ .grid-2{ grid-template-columns:1fr; } }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  // ====== ENDPOINTS: agora com prefixo /OKR_system ======
  const API_BASE = '/OKR_system/LP/Quizz-01/auth/';
  const API = {
    versaoAtiva: API_BASE + 'versao_ativa.php',
    leadStart:   API_BASE + 'lead_start.php',
    sessStart:   API_BASE + 'sessao_start.php',
    answer:      API_BASE + 'answer.php',
    finalize:    API_BASE + 'finalize.php',
    genPDF:      API_BASE + 'report_generate.php',
    sendWhats:   API_BASE + 'whatsapp_send.php'
  };

  // ====== Helpers ======
  const $ = s => document.querySelector(s);
  const fmtE164 = v => v.replace(/[^\d+]/g,'').replace(/^00/,'+');
  const isCorporate = email => !/@(gmail|hotmail|outlook|yahoo|icloud)\./i.test(email);
  const utms = (() => { const p = new URLSearchParams(location.search);
    return {
      utm_source:p.get('utm_source')||undefined,
      utm_medium:p.get('utm_medium')||undefined,
      utm_campaign:p.get('utm_campaign')||undefined,
      utm_content:p.get('utm_content')||undefined,
      utm_term:p.get('utm_term')||undefined
    };
  })();
  const state = {
    id_lead:null, session_token:null, id_versao:null,
    perguntas:[], opcoesPorPerg:{}, dominios:{}, ordem:[],
    idx:0, respostas:{}, qStartTime:null, radar:null, pdfUrl:null
  };

  /* ====== NOVO: função para "super" exibir qualquer erro na área visível ====== */
  function surfaceError(message) {
    const startVisible  = !document.getElementById('start').classList.contains('hidden');
    const quizVisible   = !document.getElementById('quiz').classList.contains('hidden');
    const resultVisible = !document.getElementById('result').classList.contains('hidden');
    const el =
      (startVisible  && document.getElementById('startMsg'))  ||
      (quizVisible   && document.getElementById('quizMsg'))   ||
      (resultVisible && document.getElementById('resultMsg')) ||
      document.getElementById('startMsg');
    el.classList.add('error');
    el.textContent = String(message || 'Erro desconhecido.');
    // também joga no console para inspeção
    console.error('[UI Error]', message);
  }

  // ====== NOVO: handlers globais de erro e rejeições não tratadas ======
  window.addEventListener('error', (ev) => {
    surfaceError(`Erro de script: ${ev.message}`);
  });
  window.addEventListener('unhandledrejection', (ev) => {
    const msg = (ev.reason && (ev.reason.message || ev.reason)) || 'Falha não tratada.';
    surfaceError(`Erro inesperado: ${msg}`);
  });

  // Fetch com validação de JSON (evita “404 HTML do WordPress”) + contexto de erro
  async function api(method, url, data){
    const opt = {
      method,
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      credentials: 'same-origin',
      cache: 'no-store'
    };
    if(data) opt.body = JSON.stringify(data);

    let r, text;
    try {
      r = await fetch(url, opt);
      text = await r.text();
    } catch(fetchErr) {
      const msg = `Falha de rede ao chamar ${method} ${url}: ${fetchErr && fetchErr.message ? fetchErr.message : fetchErr}`;
      surfaceError(msg);
      throw new Error(msg);
    }

    let json;
    try { json = JSON.parse(text); }
    catch {
      const snippet = String(text).replace(/\s+/g,' ').slice(0,200);
      const msg = `Resposta não-JSON de ${method} ${url} (status ${r.status}). Trecho: ${snippet}`;
      surfaceError(msg);
      throw new Error(msg);
    }

    if(!r.ok || (json && (json.error || json.message))){
      const msg = `Erro da API em ${method} ${url} (status ${r.status}): ${json.message || json.error || 'Falha na requisição.'}`;
      surfaceError(msg);
      throw new Error(msg);
    }
    return json;
  }

  function setProgress(n,t){
    const pct = Math.round((n/t)*100);
    $('#progressBar').style.width = pct + '%';
    $('#progressText').textContent = pct + '%';
    $('#qIndex').textContent = `Pergunta ${n} de ${t}`;
    const pb = document.querySelector('.progress');
    pb.setAttribute('aria-valuenow', String(pct));
  }

  function paintOptions(pergunta){
    const wrap = $('#qOptions');
    wrap.innerHTML = '';
    const opts = state.opcoesPorPerg[pergunta.id_pergunta] || pergunta.opcoes || [];
    opts.sort((a,b)=>a.ordem-b.ordem).forEach(o=>{
      const id = `opt_${pergunta.id_pergunta}_${o.id_opcao}`;
      const row = document.createElement('div');
      row.className = 'opt'; row.role = 'radio'; row.tabIndex = 0;
      row.innerHTML = `
        <input type="radio" id="${id}" name="opt" value="${o.id_opcao}" />
        <label for="${id}" style="margin:0">${o.texto}</label>
      `;
      const selectThis = () => {
        [...wrap.querySelectorAll('.opt')].forEach(el=>el.classList.remove('selected'));
        row.classList.add('selected');
        $('#btnNext').disabled = false;
        wrap.querySelector(`#${id}`).checked = true;
      };
      row.addEventListener('click', selectThis);
      row.addEventListener('keydown', (ev)=>{ if(ev.key==='Enter' || ev.key===' ') { ev.preventDefault(); selectThis(); }});
      wrap.appendChild(row);
    });
    $('#btnNext').disabled = true;
  }

  function showQuestion(){
    const total = state.ordem.length;
    const qId = state.ordem[state.idx];
    const p = state.perguntas.find(x=>x.id_pergunta===qId);
    if(!p) return;
    setProgress(state.idx+1, total);
    $('#qDomain').textContent = state.dominios[p.id_dominio]?.nome || '—';
    $('#qText').textContent = p.texto;
    paintOptions(p);
    $('#btnPrev').disabled = (state.idx===0);
    state.qStartTime = performance.now();
  }

  function classify(score){
    if(score>=70) return {label:'Verde (Saudável)', color:'#1dd1a1'};
    if(score>=40) return {label:'Amarelo (Moderado)', color:'#feca57'};
    return {label:'Vermelho (Risco Alto)', color:'#ff6b6b'};
  }

  function buildRadar(scores){
    const labels = Object.keys(scores);
    const data = labels.map(k=>scores[k]);
    if(state.radar) state.radar.destroy();
    const ctx = document.getElementById('radarChart').getContext('2d');
    state.radar = new Chart(ctx,{
      type:'radar',
      data:{ labels, datasets:[{ label:'Score por domínio', data }]},
      options:{ responsive:true, scales:{ r:{ suggestedMin:0, suggestedMax:100, ticks:{ display:false } } }, plugins:{ legend:{ display:false } } }
    });
  }

  const toUnique = a => [...new Set(a)];

  // ====== Fluxo: início ======
  document.getElementById('formStart').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const email = $('#email').value.trim();
    const msg = $('#startMsg');
    msg.classList.remove('error','warn'); msg.textContent = '';

    if(!email){
      msg.classList.add('error'); msg.textContent = 'Informe seu e-mail corporativo.'; return;
    }
    if(!isCorporate(email)){
      msg.classList.add('warn');
      msg.textContent = 'Prefira um e-mail corporativo (ex.: nome@empresa.com.br).';
      // segue em frente mesmo assim, se desejar bloquear troque warn->error e retorne.
    }

    try{
      $('#btnStart').disabled = true;

      // 1) lead
      const lead = await api('POST', API.leadStart, {
        email,
        nome: $('#nome').value || undefined,
        cargo: $('#cargo').value || undefined,
        consent_termos: $('#consent_termos').checked,
        consent_marketing: $('#consent_marketing').checked,
        ...utms
      });
      state.id_lead = lead.id_lead;

      // 2) versão ativa + perguntas/opções
      const v = await api('GET', API.versaoAtiva);
      state.id_versao = v.id_versao;
      state.perguntas = v.perguntas || [];
      state.dominios = (v.dominios || []).reduce((acc,d)=>{acc[d.id_dominio]=d; return acc;}, {});
      state.perguntas.forEach(p=> state.opcoesPorPerg[p.id_pergunta] = p.opcoes || []);
      state.ordem = toUnique(state.perguntas.map(p=>p.id_pergunta))
        .sort((a,b)=>{
          const pa = state.perguntas.find(x=>x.id_pergunta===a);
          const pb = state.perguntas.find(x=>x.id_pergunta===b);
          return (pa?.ordem||0)-(pb?.ordem||0);
        });

      // 3) sessão
      const ses = await api('POST', API.sessStart, { id_versao: state.id_versao, id_lead: state.id_lead });
      state.session_token = ses.session_token;

      // abrir quiz
      document.getElementById('start').classList.add('hidden');
      document.getElementById('quiz').classList.remove('hidden');
      document.getElementById('quiz').setAttribute('aria-hidden','false');
      showQuestion();
    } catch(err){
      // já aparece pelo surfaceError() dentro do api(), mas mantemos aqui tbm
      msg.classList.add('error');
      msg.textContent = 'Não foi possível iniciar. ' + (err.message || err);
    } finally {
      $('#btnStart').disabled = false;
    }
  });

  // ====== Fluxo: responder ======
  document.getElementById('btnNext').addEventListener('click', async ()=>{
    const wrap = $('#qOptions');
    const selected = wrap.querySelector('input[name="opt"]:checked');
    if(!selected) return;
    const id_opcao = +selected.value;
    const qId = state.ordem[state.idx];
    const timeMs = Math.round(performance.now() - (state.qStartTime || performance.now()));
    try{
      $('#btnNext').disabled = true;
      await api('POST', API.answer, {
        session_token: state.session_token,
        id_pergunta: qId,
        id_opcao,
        tempo_na_tela_ms: timeMs
      });
      state.respostas[qId] = id_opcao;

      if(state.idx === state.ordem.length - 1){
        await finalizeQuiz();
      } else {
        state.idx++;
        showQuestion();
      }
    } catch(err){
      const qm = $('#quizMsg');
      qm.classList.add('error');
      qm.textContent = 'Erro ao salvar resposta: ' + (err.message || err);
    } finally {
      $('#btnNext').disabled = false;
    }
  });

  document.getElementById('btnPrev').addEventListener('click', ()=>{
    if(state.idx > 0){
      state.idx--;
      showQuestion();
    }
  });

  // ====== Fluxo: finalizar / exibir resultado ======
  async function finalizeQuiz(){
    try{
      $('#quizMsg').classList.remove('error'); $('#quizMsg').textContent = '';
      const out = await api('POST', API.finalize, { session_token: state.session_token });

      const cls = classify(out.score_total);
      $('#scoreTotal').textContent = out.score_total;
      $('#scoreLabel').textContent = cls.label;
      $('#scoreTotal').style.color = cls.color;

      const ul = $('#quickInsights'); ul.innerHTML='';
      (out.resumo?.bullets || []).slice(0,3).forEach(t=>{
        const li = document.createElement('li'); li.textContent = t; ul.appendChild(li);
      });

      const ol = $('#levers'); ol.innerHTML='';
      (out.alavancas || []).slice(0,3).forEach(t=>{
        const li = document.createElement('li'); li.innerHTML = t; ol.appendChild(li);
      });

      buildRadar(out.score_por_dominio || {});

      document.getElementById('quiz').classList.add('hidden');
      document.getElementById('quiz').setAttribute('aria-hidden','true');
      document.getElementById('result').classList.remove('hidden');
      document.getElementById('result').setAttribute('aria-hidden','false');
    } catch(err){
      $('#quizMsg').classList.add('error');
      $('#quizMsg').textContent = 'Erro ao finalizar: ' + (err.message || err);
    }
  }

  // ====== PDF ======
  document.getElementById('btnGeneratePDF').addEventListener('click', async ()=>{
    const msg = $('#resultMsg');
    msg.classList.remove('error'); msg.textContent = 'Gerando PDF…';
    try{
      const r = await api('POST', API.genPDF, { session_token: state.session_token });
      state.pdfUrl = r.pdf_url_segura || r.pdf_url || null;
      if(state.pdfUrl){
        const a = document.getElementById('btnPDF');
        a.href = state.pdfUrl;
        msg.textContent = 'PDF pronto. Você pode baixar ou enviar por WhatsApp.';
      } else {
        throw new Error('PDF não retornado.');
      }
    } catch(err){
      msg.classList.add('error');
      msg.textContent = 'Falha ao gerar PDF: ' + (err.message || err);
    }
  });

  // ====== WhatsApp ======
  document.getElementById('formWhats').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const wmsg = $('#whatsMsg');
    wmsg.classList.remove('error'); wmsg.textContent = '';
    const raw = $('#whats').value.trim();
    const phone = fmtE164(raw);
    const optin = $('#whatsOptin').checked;

    if(!optin){
      wmsg.classList.add('error');
      wmsg.textContent = 'Marque o opt-in para enviar via WhatsApp.';
      return;
    }
    if(!/^\+\d{10,15}$/.test(phone)){
      wmsg.classList.add('error');
      wmsg.textContent = 'Informe no formato internacional E.164. Ex.: +5511999999999';
      return;
    }

    try{
      if(!state.pdfUrl){
        const r = await api('POST', API.genPDF, { session_token: state.session_token });
        state.pdfUrl = r.pdf_url_segura || r.pdf_url || null;
      }
      const r2 = await api('POST', API.sendWhats, {
        session_token: state.session_token,
        telefone_e164: phone,
        whatsapp_optin: true
      });
      wmsg.textContent = (r2.status||'queued') === 'sent'
        ? 'Enviado com sucesso no WhatsApp.'
        : 'Solicitação enviada. Você receberá em instantes.';
    } catch(err){
      wmsg.classList.add('error');
      wmsg.textContent = 'Falha ao enviar no WhatsApp: ' + (err.message || err);
    }
  });

  // Acessibilidade/atalho: Enter para avançar
  document.addEventListener('keydown',(ev)=>{
    const quizVisible = !document.getElementById('quiz').classList.contains('hidden');
    if(ev.key==='Enter' && quizVisible){
      const btn = document.getElementById('btnNext');
      if(!btn.disabled) btn.click();
    }
  });
</script>
