<?php
$PAGE_TITLE = 'Avaliação · Módulo 1';
require __DIR__ . '/partials/header.php';
$base = '/OKR_system/LP/Quiz-OKRMaster';
?>
<section class="wrap narrow">
  <div class="hero">
    <span class="eyebrow">Programa de Formação OKR Master</span>
    <h1>Avaliação do Módulo 1: Balanced Scorecard</h1>
    <p class="sub">São 20 questões sobre a aplicação prática do BSC. A cada resposta você verá se acertou e a fundamentação. Leva cerca de 15 minutos.</p>
  </div>

  <form id="formStart" class="card" novalidate>
    <div class="field">
      <label for="nome">Nome completo</label>
      <input type="text" id="nome" name="nome" autocomplete="name" placeholder="Seu nome" required>
    </div>

    <div class="field">
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" autocomplete="email" placeholder="nome@empresa.com.br" required>
      <small class="hint">Usaremos para registrar seu resultado e dar continuidade à formação.</small>
    </div>

    <div class="field">
      <label for="data_aula">Data em que você teve a aula do Módulo 1</label>
      <input type="date" id="data_aula" name="data_aula" required>
      <small class="hint">Selecione o dia da sua aula presencial ou on-line.</small>
    </div>

    <label class="check">
      <input type="checkbox" id="consent_termos" required>
      <span>Autorizo o uso dos meus dados para registro e acompanhamento da formação OKR Master.</span>
    </label>

    <button id="btnStart" type="submit" class="btn primary block">Iniciar avaliação</button>
    <div id="startMsg" class="msg" aria-live="polite"></div>
  </form>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
  const BASE = '<?php echo $base; ?>';
  const API  = { start: BASE + '/auth/sessao_start.php' };
  const $ = s => document.querySelector(s);

  // Limita o date picker: nao permite datas futuras.
  // Usa data LOCAL (nao toISOString, que e UTC e pode adiantar/atrasar um dia).
  (function(){
    const d = $('#data_aula');
    const p = n => String(n).padStart(2,'0');
    const local = dt => dt.getFullYear()+'-'+p(dt.getMonth()+1)+'-'+p(dt.getDate());
    const hoje = new Date();
    d.max = local(hoje);
    const min = new Date(hoje.getTime() - 120*864e5);
    d.min = local(min);
  })();

  gtag('event', 'okrm_avaliacao_view', { event_category:'okrmaster', event_label:'modulo_1' });

  async function api(url, data){
    const r = await fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify(data), cache:'no-store', credentials:'same-origin'
    });
    const t = await r.text();
    let j; try{ j = JSON.parse(t); }catch(e){ throw new Error('Resposta inválida do servidor.'); }
    if(!r.ok || j.error){ const err = new Error(j.error || ('Falha '+r.status)); err.status = r.status; err.data = j; throw err; }
    return j;
  }

  $('#formStart').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const msg = $('#startMsg'); msg.className='msg'; msg.textContent='';
    const nome = $('#nome').value.trim();
    const email = $('#email').value.trim();
    const data_aula = $('#data_aula').value;
    const consent = $('#consent_termos').checked;

    if(nome.length < 3){ msg.classList.add('error'); msg.textContent='Informe seu nome completo.'; $('#nome').focus(); return; }
    if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ msg.classList.add('error'); msg.textContent='Informe um e-mail válido.'; $('#email').focus(); return; }
    if(!data_aula){ msg.classList.add('error'); msg.textContent='Selecione a data da sua aula do Módulo 1.'; $('#data_aula').focus(); return; }
    if(!consent){ msg.classList.add('error'); msg.textContent='É necessário autorizar o uso dos dados para continuar.'; return; }

    try{
      $('#btnStart').disabled = true;
      $('#btnStart').textContent = 'Preparando…';
      const res = await api(API.start, { nome, email, data_aula, consent_termos:consent });

      try{ localStorage.setItem('okrm_nome', nome); }catch(_){}
      gtag('event','okrm_avaliacao_start',{ event_category:'okrmaster', event_label:'modulo_1' });

      location.href = BASE + '/views/run.php?sid=' + encodeURIComponent(res.session_token);
    }catch(err){
      $('#btnStart').disabled = false;
      $('#btnStart').textContent = 'Iniciar avaliação';
      msg.classList.add('error');
      if(err.status === 409){
        msg.textContent = 'Você já concluiu esta avaliação. Se precisa refazê-la, fale com seu instrutor da PlanningBI.';
      }else{
        msg.textContent = err.message || 'Não foi possível iniciar. Tente novamente.';
      }
    }
  });
</script>
