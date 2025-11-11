<?php
// views/run.php
require __DIR__ . '/partials/header.php';
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
?>
<div id="debugTop" style="background:#2a2f3a;color:#ffd166;padding:10px 14px;font:14px/1.4 system-ui;display:flex;justify-content:space-between;align-items:center;">
  <div><b>Debug:</b> SID =
    <code style="background:#1e222b;padding:2px 6px;border-radius:6px;color:#e6edf3;"><?php echo htmlspecialchars($sid ?: '(vazio)'); ?></code>
  </div>
  <button type="button" onclick="this.parentElement.remove()" style="background:#ffd166;border:0;border-radius:8px;padding:6px 10px;cursor:pointer;color:#1a1a1a;font-weight:700">
    fechar
  </button>
</div>

<section id="quiz" class="wrap">
  <div id="stepper" class="stepper" aria-label="Progresso por etapas"></div>

  <div id="questionCard" class="card">
    <div class="q-head">
      <span id="qDomain" class="chip">Domínio</span>
      <span id="qIndex" class="step">Pergunta 1 de N</span>
    </div>
    <h2 id="qText">—</h2>
    <div id="qOptions" class="options" role="group" aria-labelledby="qText"></div>

    <!-- Feedback pós-confirmação -->
    <div id="feedback" style="display:none; margin-top:10px;">
      <div id="fbCorrect" style="display:none; border-left:4px solid #1dd1a1; background:#0f1915; padding:10px; border-radius:8px; margin-bottom:8px;"></div>
      <div id="fbChosen"  style="display:none; border-left:4px solid #ff6b6b; background:#1e0f10; padding:10px; border-radius:8px;"></div>
    </div>

    <div class="actions">
      <button id="btnPrev" class="btn ghost" type="button" disabled>Voltar</button>
      <button id="btnAction" class="btn primary" type="button" disabled>Confirmar</button>
    </div>

    <div id="quizMsg" class="msg" aria-live="polite"></div>
    <div id="quizHardError" style="display:none;margin-top:10px;padding:12px;border-radius:10px;background:#3a1f1f;color:#ffd4d4;font-weight:600"></div>
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
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<style>
  :root { --bg:#0b0d10; --card:#141820; --muted:#9aa4b2; --text:#e6edf3; --brand:#d4af37; --brand-2:#1dd1a1; --danger:#ff6b6b; --warn:#feca57; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue","Noto Sans",Arial; }
  .wrap { max-width:980px; margin:28px auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,.2); }

  .stepper { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:18px; position:relative; }
  .stepper .step-item { display:flex; flex-direction:column; align-items:center; flex:1 1 0; position:relative; min-width:0; }
  .stepper .step-dot { width:14px; height:14px; border-radius:50%; background:#2a2f3a; border:2px solid #2a2f3a; transition:background .25s, border-color .25s, transform .15s; z-index:1; }
  .stepper .step-label { margin-top:6px; font-size:12px; color:var(--muted); user-select:none; }
  .stepper .step-connector { position:absolute; top:6px; left:-50%; width:100%; height:2px; background:#2a2f3a; z-index:0; transition:background .25s; }
  .stepper .step-item:first-child .step-connector { display:none; }
  .stepper .step-item.is-active .step-dot, .stepper .step-item.is-done .step-dot { background:var(--brand); border-color:var(--brand); }
  .stepper .step-item.is-active .step-label, .stepper .step-item.is-done .step-label { color:var(--brand); }
  .stepper .step-item.is-done .step-connector, .stepper .step-item.is-active .step-connector { background:var(--brand); }

  .q-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
  .chip { display:inline-block; background:#0f1319; border:1px solid rgba(255,255,255,.12); color:var(--muted); padding:6px 10px; border-radius:999px; font-size:12px; }
  .step { color:var(--muted); font-size:12px; }
  h2 { margin:8px 0 10px; }
  .options { display:grid; gap:10px; }
  .opt { border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:14px; display:flex; gap:12px; align-items:flex-start; cursor:pointer; background:#0f1319; }
  .opt input { margin-top:3px; accent-color: var(--brand); }
  .opt.selected { border-color:var(--brand); box-shadow:0 0 0 3px rgba(212,175,55,.25); }
  .actions { display:flex; justify-content:space-between; margin-top:14px; }
  .btn { border:1px solid rgba(255,255,255,.15); background:#10151c; color:#e6edf3; padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:700; }
  .btn.primary { background:var(--brand); border-color:transparent; color:#0b1117; }
  .btn.ghost { background:transparent; }
  .msg { margin-top:10px; font-size:14px; color:var(--muted); min-height:18px; }
  .hidden { display:none; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .score { display:flex; align-items:baseline; gap:12px; }
  .score-number { font-size:48px; font-weight:900; }
  .score-label { font-size:16px; color:var(--muted); }
  abbr[title] { text-decoration: underline dotted; cursor: help; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const SID = <?='"'.htmlspecialchars($sid, ENT_QUOTES).'"'?>;
  if (!SID) {
    alert('Sessão inválida. Voltando ao início.');
    location.href='/OKR_system/LP/Quizz-01/views/start.php';
  }

  const API_BASE = '/OKR_system/LP/Quizz-01/auth/';
  const API = {
    versaoAtiva: API_BASE+'versao_ativa.php',
    answer:      API_BASE+'sessao_answer.php',
    finalize:    API_BASE+'sessao_finalize.php'
  };

  const $ = s => document.querySelector(s);
  const state = { perguntas:[], opcoes:{}, dominios:{}, ordem:[], idx:0, qStartTime:null, phase:'confirm' };

  async function api(method, url, data){
    const r = await fetch(url, {
      method,
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: data ? JSON.stringify(data) : undefined,
      cache:'no-store'
    });
    const t = await r.text();
    let j;
    try { j = JSON.parse(t); }
    catch(e) {
      const hard = $('#quizHardError');
      hard.style.display='block';
      hard.textContent = `Resposta não-JSON de ${method} ${url} (status ${r.status}). Trecho: ` + t.replace(/\s+/g,' ').slice(0,200);
      throw new Error(hard.textContent);
    }
    if (!r.ok || j.error) {
      const hard = $('#quizHardError');
      hard.style.display='block';
      hard.textContent = (j && (j.message || j.error)) ? (j.message || j.error) : `Falha HTTP ${r.status}`;
      throw new Error(hard.textContent);
    }
    return j;
  }

  function buildStepper(total){
    const host = $('#stepper');
    host.innerHTML = '';
    for(let i=0;i<total;i++){
      const item = document.createElement('div');
      item.className = 'step-item';
      const conn = document.createElement('div');
      conn.className = 'step-connector';
      item.appendChild(conn);
      const dot = document.createElement('div');
      dot.className = 'step-dot';
      item.appendChild(dot);
      const lbl = document.createElement('div');
      lbl.className = 'step-label';
      lbl.textContent = (i+1).toString();
      item.appendChild(lbl);
      host.appendChild(item);
    }
  }

  function updateStepper(currentIndex, total){
    const items = Array.from(document.querySelectorAll('.stepper .step-item'));
    items.forEach((el, idx) => {
      el.classList.remove('is-active','is-done');
      if(idx < currentIndex) el.classList.add('is-done');
      if(idx === currentIndex) el.classList.add('is-active');
    });
    $('#qIndex').textContent = `Pergunta ${currentIndex+1} de ${total}`;
  }

  function applyGlossary(text, gloss){
    if (!text || !gloss || !Array.isArray(gloss)) return text;
    let out = text;
    gloss.forEach(g => {
      const term = (g.term||'').trim();
      const def  = (g.def||'').trim();
      if (!term || !def) return;
      const re = new RegExp(`\\b(${term.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')})\\b`, 'gi');
      out = out.replace(re, `<abbr title="${def}">$1</abbr>`);
    });
    return out;
  }

  function shuffle(a){
    for(let i=a.length-1;i>0;i--){
      const j = Math.floor(Math.random()*(i+1));
      [a[i],a[j]] = [a[j],a[i]];
    }
    return a;
  }

  function paintOptions(p){
    const wrap = $('#qOptions');
    wrap.innerHTML = '';
    const canonic = (state.opcoes[p.id_pergunta] || p.opcoes || []).slice();
    // embaralha a exibição, mas sempre posta id_opcao
    const shown = shuffle(canonic.map(x=>({...x})));

    shown.forEach(o=>{
      const id = `opt_${p.id_pergunta}_${o.id_opcao}`;
      const row = document.createElement('div');
      row.className = 'opt'; row.role = 'radio'; row.tabIndex = 0;
      row.innerHTML = `
        <input type="radio" id="${id}" name="opt" value="${o.id_opcao}" />
        <label for="${id}" style="margin:0">${o.texto}</label>
      `;
      const selectThis = () => {
        [...wrap.querySelectorAll('.opt')].forEach(el=>el.classList.remove('selected'));
        row.classList.add('selected');
        $('#btnAction').disabled = false;
      };
      row.addEventListener('click', selectThis);
      row.addEventListener('keydown', (ev)=>{ if(ev.key==='Enter' || ev.key===' ') { ev.preventDefault(); selectThis(); }});
      wrap.appendChild(row);
    });

    $('#btnAction').disabled = true;
  }

  function showQuestion(){
    const total = state.ordem.length;
    const qId = state.ordem[state.idx];
    const p = state.perguntas.find(x=>x.id_pergunta===qId);
    if(!p) return;

    updateStepper(state.idx, total);
    $('#qDomain').textContent = state.dominios[p.id_dominio]?.nome || '—';

    // aplica glossário no enunciado (se houver)
    let texto = p.texto;
    try {
      const gloss = p.glossario_json ? JSON.parse(p.glossario_json) : null;
      texto = applyGlossary(texto, gloss);
    } catch(_) {}
    $('#qText').innerHTML = texto;

    // limpa feedback e pinta opções
    $('#feedback').style.display='none';
    $('#fbCorrect').style.display='none';
    $('#fbChosen').style.display='none';
    paintOptions(p);

    // estado do botão
    state.phase = 'confirm';
    $('#btnAction').textContent = (state.idx === total-1) ? 'Confirmar' : 'Confirmar';
    $('#btnPrev').disabled = (state.idx===0);
    state.qStartTime = performance.now();
  }

  async function finalizeQuiz(){
    try{
      $('#quizMsg').classList.remove('error'); $('#quizMsg').textContent = '';
      await api('POST', API.finalize, { session_token: SID });
      location.href = '/OKR_system/LP/Quizz-01/views/result.php?sid=' + encodeURIComponent(SID);
    }catch(err){
      $('#quizMsg').classList.add('error');
      $('#quizMsg').textContent = err.message || String(err);
    }
  }

  (async function init(){
    try{
      const v = await api('GET', API.versaoAtiva);
      state.perguntas = v.perguntas||[];
      state.dominios = (v.dominios||[]).reduce((a,d)=>{a[d.id_dominio]=d; return a;}, {});
      state.perguntas.forEach(p=> state.opcoes[p.id_pergunta]=p.opcoes||[]);
      state.ordem = state.perguntas.map(p=>p.id_pergunta).sort((a,b)=>{
        const pa = state.perguntas.find(x=>x.id_pergunta===a);
        const pb = state.perguntas.find(x=>x.id_pergunta===b);
        return (pa?.ordem||0)-(pb?.ordem||0);
      });

      buildStepper(state.ordem.length);
      showQuestion();
    }catch(err){
      const hard = $('#quizHardError');
      hard.style.display='block';
      hard.textContent = err.message || String(err);
    }
  })();

  // Botões
  document.getElementById('btnPrev').addEventListener('click', ()=>{
    if(state.idx > 0){
      state.idx--;
      showQuestion();
    }
  });

  document.getElementById('btnAction').addEventListener('click', async ()=>{
    const total = state.ordem.length;
    // Fase 1: Confirmar => chama backend, exibe feedback, troca para Próxima/Ver resultado
    if (state.phase === 'confirm') {
      const wrap = $('#qOptions');
      const selected = wrap.querySelector('input[name="opt"]:checked');
      if(!selected) return;
      const id_opcao = +selected.value;
      const qId = state.ordem[state.idx];
      const timeMs = Math.round(performance.now() - (state.qStartTime || performance.now()));

      try{
        $('#btnAction').disabled = true;
        const ans = await api('POST', API.answer, {
          session_token: SID,
          id_pergunta: qId,
          id_opcao,
          tempo_na_tela_ms: timeMs
        });

        // trava seleção
        wrap.querySelectorAll('input, .opt').forEach(el=> el.disabled = true);

        // mostra feedback
        $('#feedback').style.display='block';
        if (ans.correta && ans.correta.explicacao) {
          const c = $('#fbCorrect');
          c.style.display = 'block';
          c.innerHTML = `<b>Resposta correta:</b> ${ans.correta.explicacao}`;
        }
        if (ans.escolhida && ans.escolhida.id_opcao !== (ans.correta?.id_opcao)) {
          const ch = $('#fbChosen');
          ch.style.display = 'block';
          ch.innerHTML = `<b>Sua escolha:</b> ${ans.escolhida.explicacao || '—'}`;
        }

        // troca para próxima
        state.phase = 'next';
        $('#btnAction').textContent = (state.idx === total-1) ? 'Ver resultado' : 'Próxima';
        $('#btnAction').disabled = false;
      }catch(err){
        $('#quizMsg').classList.add('error');
        $('#quizMsg').textContent = 'Erro ao salvar resposta: ' + (err.message || err);
        $('#btnAction').disabled = false;
      }
      return;
    }

    // Fase 2: Próxima/Ver resultado
    if (state.phase === 'next') {
      if (state.idx === state.ordem.length - 1) {
        await finalizeQuiz();
      } else {
        state.idx++;
        showQuestion();
      }
      return;
    }
  });
</script>
