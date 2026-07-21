<?php
$PAGE_TITLE = 'Respondendo · Módulo 1';
require __DIR__ . '/partials/header.php';
$base = '/OKR_system/LP/Quiz-OKRMaster';
$sid  = isset($_GET['sid']) ? trim($_GET['sid']) : '';
?>
<section class="wrap">
  <div id="loading" class="card center">
    <div class="spinner"></div>
    <p class="muted">Carregando sua avaliação…</p>
    <div id="loadErr" class="msg error"></div>
  </div>

  <div id="quiz" class="card hidden">
    <div class="progress-head">
      <span id="pgLabel">Questão 1 de 20</span>
      <span id="pgTimer" class="muted">00:00</span>
    </div>
    <div class="progress-track"><div id="pgFill" class="progress-fill"></div></div>

    <div class="q-head">
      <span id="qChip" class="chip">Bloco</span>
    </div>
    <div id="qText" class="q-text">—</div>
    <div id="qOptions" class="options" role="group" aria-label="Alternativas"></div>

    <div id="feedback" class="fb hidden"></div>

    <div class="btn-row">
      <span></span>
      <button id="btnAction" class="btn primary" type="button" disabled>Confirmar resposta</button>
    </div>
    <div id="quizMsg" class="msg" aria-live="polite"></div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
  const BASE = '<?php echo $base; ?>';
  const SID  = <?php echo json_encode($sid, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
  const API = {
    load:     BASE + '/auth/versao_ativa.php',
    answer:   BASE + '/auth/sessao_answer.php',
    finalize: BASE + '/auth/sessao_finalize.php'
  };
  const LETRAS = ['A','B','C','D','E','F'];
  const $ = s => document.querySelector(s);

  if(!SID){ location.href = BASE + '/views/start.php'; }

  const state = { questoes:[], idx:0, fase:'responder', tInicioQuestao:0, tInicioTotal:Date.now(), timerInt:null };

  async function api(method, url, data){
    const r = await fetch(url, {
      method, headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: data ? JSON.stringify(data) : undefined, cache:'no-store', credentials:'same-origin'
    });
    const t = await r.text();
    let j; try{ j = JSON.parse(t); }catch(e){ throw new Error('Resposta inválida do servidor.'); }
    if(!r.ok || j.error){ const e = new Error(j.error || ('Falha '+r.status)); e.status=r.status; throw e; }
    return j;
  }

  function shuffle(a){ for(let i=a.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); [a[i],a[j]]=[a[j],a[i]]; } return a; }
  function fmt(ms){ const s=Math.floor(ms/1000); return String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0'); }

  function tickTimer(){ $('#pgTimer').textContent = fmt(Date.now() - state.tInicioTotal); }

  function render(){
    const total = state.questoes.length;
    const q = state.questoes[state.idx];

    $('#pgLabel').textContent = `Questão ${state.idx+1} de ${total}`;
    $('#pgFill').style.width = ((state.idx)/total*100) + '%';
    $('#qChip').textContent = q.bloco_nome || 'Módulo 1';
    $('#qText').textContent = q.enunciado;

    const wrap = $('#qOptions'); wrap.innerHTML='';
    // embaralha as alternativas: elimina viés de posição
    const alts = shuffle(q.alternativas.map(a=>({...a})));
    q._alts = alts;
    alts.forEach((a,i)=>{
      const row = document.createElement('div');
      row.className = 'opt'; row.tabIndex = 0; row.dataset.id = a.id_alternativa;
      row.innerHTML = `<span class="letra">${LETRAS[i]}</span><span class="txt">${a.texto}</span>`;
      const pick = ()=>{
        if(state.fase!=='responder') return;
        wrap.querySelectorAll('.opt').forEach(el=>el.classList.remove('selected'));
        row.classList.add('selected');
        state.escolhaId = +a.id_alternativa;
        $('#btnAction').disabled = false;
      };
      row.addEventListener('click', pick);
      row.addEventListener('keydown', ev=>{ if(ev.key==='Enter'||ev.key===' '){ ev.preventDefault(); pick(); }});
      wrap.appendChild(row);
    });

    $('#feedback').className = 'fb hidden'; $('#feedback').innerHTML='';
    $('#btnAction').disabled = true;
    $('#btnAction').textContent = 'Confirmar resposta';
    state.fase = 'responder';
    state.escolhaId = null;
    state.tInicioQuestao = performance.now();
  }

  function letraDe(id){ const i = state._alts_cache.findIndex(a=>+a.id_alternativa===+id); return i>=0 ? LETRAS[i] : '?'; }

  async function confirmar(){
    // PARA o relogio da questao ANTES de renderizar feedback:
    // o tempo medido e so o de decisao, sem a leitura da explicacao.
    const tempo_ms = Math.round(performance.now() - state.tInicioQuestao);
    const q = state.questoes[state.idx];
    state._alts_cache = q._alts;

    $('#btnAction').disabled = true;
    let ans;
    try{
      ans = await api('POST', API.answer, { session_token:SID, id_questao:q.id_questao, id_alternativa:state.escolhaId, tempo_ms });
    }catch(err){
      $('#quizMsg').className='msg error';
      $('#quizMsg').textContent = err.message || 'Erro ao registrar resposta.';
      $('#btnAction').disabled = false;
      return;
    }

    const wrap = $('#qOptions');
    wrap.querySelectorAll('.opt').forEach(el=>{
      el.classList.add('locked');
      const id = +el.dataset.id;
      if(id === ans.id_correta) el.classList.add('is-correct');
      if(id === state.escolhaId && !ans.acertou) el.classList.add('is-wrong');
    });

    // Feedback: veredito + fundamentacao da correta + (se errou) a justificativa
    // da escolhida + bloco recolhivel com as demais.
    const fb = $('#feedback'); fb.className='fb';
    const correta = q._alts.find(a=>+a.id_alternativa===ans.id_correta);
    const escolhida = q._alts.find(a=>+a.id_alternativa===state.escolhaId);

    let html = '';
    if(ans.acertou){
      html += `<div class="fb-verdict ok">✓ Resposta correta</div>`;
      html += `<div class="fb-box ok"><span class="lbl">Por que está correta</span>${correta.justificativa}</div>`;
    }else{
      html += `<div class="fb-verdict no">✕ Resposta incorreta</div>`;
      html += `<div class="fb-box no"><span class="lbl">Sua escolha (${letraDe(state.escolhaId)})</span>${escolhida.justificativa}</div>`;
      html += `<div class="fb-box ok"><span class="lbl">Resposta correta (${letraDe(ans.id_correta)})</span>${correta.justificativa}</div>`;
    }
    // demais alternativas
    const outras = q._alts.filter(a=> +a.id_alternativa!==ans.id_correta && +a.id_alternativa!==state.escolhaId);
    if(outras.length){
      let inner = outras.map(a=>`<p><b>Alternativa ${letraDe(a.id_alternativa)}:</b> ${a.justificativa}</p>`).join('');
      html += `<details class="mais"><summary>Ver a análise das demais alternativas</summary><div class="body">${inner}</div></details>`;
    }
    fb.innerHTML = html;

    gtag('event','okrm_questao_resp',{ event_category:'okrmaster', event_label:'q'+(state.idx+1), acertou: ans.acertou?1:0 });

    state.fase = 'avancar';
    const ultima = state.idx === state.questoes.length-1;
    $('#btnAction').textContent = ultima ? 'Ver meu resultado' : 'Próxima questão';
    $('#btnAction').disabled = false;
    $('#pgFill').style.width = ((state.idx+1)/state.questoes.length*100) + '%';
    fb.scrollIntoView({behavior:'smooth', block:'nearest'});
  }

  async function avancar(){
    if(state.idx === state.questoes.length-1){
      $('#btnAction').disabled = true;
      $('#btnAction').textContent = 'Calculando…';
      try{
        await api('POST', API.finalize, { session_token:SID });
        clearInterval(state.timerInt);
        gtag('event','okrm_avaliacao_complete',{ event_category:'okrmaster', event_label:'modulo_1' });
        location.href = BASE + '/views/result.php?sid=' + encodeURIComponent(SID);
      }catch(err){
        $('#quizMsg').className='msg error';
        $('#quizMsg').textContent = err.message || 'Erro ao finalizar.';
        $('#btnAction').disabled = false;
      }
      return;
    }
    state.idx++;
    render();
  }

  $('#btnAction').addEventListener('click', ()=>{
    if(state.fase==='responder'){ if(state.escolhaId) confirmar(); }
    else { avancar(); }
  });

  (async function init(){
    try{
      const data = await api('GET', API.load + '?sid=' + encodeURIComponent(SID));
      state.questoes = data.questoes || [];
      if(!state.questoes.length) throw new Error('Nenhuma questão disponível.');
      $('#loading').classList.add('hidden');
      $('#quiz').classList.remove('hidden');
      state.timerInt = setInterval(tickTimer, 1000);
      render();
    }catch(err){
      if(err.status === 409){
        $('#loadErr').innerHTML = 'Esta avaliação já foi concluída. <a href="'+BASE+'/views/result.php?sid='+encodeURIComponent(SID)+'" style="color:var(--brand)">Ver resultado</a>.';
      }else{
        $('#loadErr').textContent = err.message || 'Não foi possível carregar a avaliação.';
      }
    }
  })();
</script>
