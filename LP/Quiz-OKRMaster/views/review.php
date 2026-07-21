<?php
$PAGE_TITLE = 'Revisão · Módulo 1';
require __DIR__ . '/partials/header.php';
$base = '/OKR_system/LP/Quiz-OKRMaster';
$sid  = isset($_GET['sid']) ? trim($_GET['sid']) : '';
?>
<section class="wrap">
  <div class="card">
    <h2>Revisão das respostas — Módulo 1</h2>
    <p class="muted">Confira cada questão, o gabarito e a fundamentação. Use os filtros para focar nos erros.</p>
    <div class="filters" id="filters">
      <button class="filter-btn active" data-f="todas">Todas</button>
      <button class="filter-btn" data-f="erros">Somente erros</button>
      <button class="filter-btn" data-f="acertos">Somente acertos</button>
    </div>
  </div>

  <div id="loading" class="card center"><div class="spinner"></div><div id="loadErr" class="msg error"></div></div>
  <div id="list"></div>

  <div class="card center">
    <a class="btn primary" href="<?php echo $base; ?>/views/result.php?sid=<?php echo urlencode($sid); ?>">Voltar ao resultado</a>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
  const BASE = '<?php echo $base; ?>';
  const SID  = <?php echo json_encode($sid, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
  const LETRAS = ['A','B','C','D','E','F'];
  const $ = s => document.querySelector(s);

  function itemHTML(q){
    const stat = q.acertou
      ? '<span class="rev-status ok">✓ Acertou</span>'
      : '<span class="rev-status no">✕ Errou</span>';
    let alts = '';
    q.alternativas.forEach((a,i)=>{
      const letra = LETRAS[i];
      let cls = 'rev-alt';
      if(a.is_correta) cls += ' correct';
      else if(a.id_alternativa === q.escolhida) cls += ' chosen-wrong';
      let tag = '';
      if(a.is_correta) tag = ' <b style="color:#1dd1a1">✓ correta</b>';
      else if(a.id_alternativa === q.escolhida) tag = ' <b style="color:#ff6b6b">sua escolha</b>';
      alts += `<div class="${cls}"><span class="letra">${letra}</span><span>${a.texto}${tag}</span></div>`;
      // justificativa: mostra da correta sempre, e da escolhida errada
      if(a.is_correta || a.id_alternativa === q.escolhida){
        alts += `<div class="rev-just">${a.justificativa}</div>`;
      }
    });
    return `<div class="rev-item" data-ok="${q.acertou?1:0}">
      <div class="rev-head">
        <span class="rev-num">QUESTÃO ${q.ordem} · ${q.bloco}</span>${stat}
      </div>
      <div class="rev-q">${q.enunciado}</div>
      ${alts}
    </div>`;
  }

  function applyFilter(f){
    document.querySelectorAll('.rev-item').forEach(el=>{
      const ok = el.dataset.ok === '1';
      el.style.display = (f==='todas' || (f==='erros'&&!ok) || (f==='acertos'&&ok)) ? '' : 'none';
    });
  }

  $('#filters').addEventListener('click', e=>{
    const b = e.target.closest('.filter-btn'); if(!b) return;
    document.querySelectorAll('.filter-btn').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    applyFilter(b.dataset.f);
  });

  (async function(){
    try{
      const r = await fetch(BASE + '/auth/resultado_get.php?sid=' + encodeURIComponent(SID) + '&full=1', {headers:{'Accept':'application/json'}, cache:'no-store'});
      const t = await r.text(); let d;
      try{ d = JSON.parse(t); }catch(e){ throw new Error('Resposta inválida do servidor.'); }
      if(!r.ok || d.error) throw new Error(d.error || 'Falha ao carregar revisão.');

      $('#loading').classList.add('hidden');
      $('#list').innerHTML = (d.revisao || []).map(itemHTML).join('');
    }catch(err){
      $('#loadErr').textContent = err.message || 'Não foi possível carregar a revisão.';
    }
  })();
</script>
