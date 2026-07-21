<?php
$PAGE_TITLE = 'Resultado · Módulo 1';
require __DIR__ . '/partials/header.php';
$base = '/OKR_system/LP/Quiz-OKRMaster';
$sid  = isset($_GET['sid']) ? trim($_GET['sid']) : '';
?>
<section class="wrap">
  <div id="loading" class="card center">
    <div class="spinner"></div>
    <p class="muted">Calculando seu resultado…</p>
    <div id="loadErr" class="msg error"></div>
  </div>

  <div id="result" class="hidden">
    <div class="card">
      <div class="score-hero">
        <div class="score-ring" id="ring">
          <div class="inner">
            <div class="score-num" id="scoreNum">--</div>
            <div class="score-den" id="scoreDen">de 20</div>
          </div>
        </div>
        <div class="faixa-tag" id="faixaTag">—</div>
        <p class="leitura" id="leitura"></p>
      </div>

      <div class="metrics">
        <div class="metric"><div class="v" id="mPct">--%</div><div class="l">Aproveitamento</div></div>
        <div class="metric"><div class="v" id="mTotal">--:--</div><div class="l">Tempo total</div></div>
        <div class="metric"><div class="v" id="mMedia">--s</div><div class="l">Média por questão</div></div>
      </div>
    </div>

    <div class="card">
      <h3>Desempenho por bloco de competência</h3>
      <div id="blocos" class="blocos"></div>
    </div>

    <div class="card">
      <div class="thanks">
        <h2 id="thanksTitle">Obrigado por concluir a avaliação!</h2>
        <p class="muted">Seu resultado do Módulo 1 foi registrado com sucesso.</p>

        <div class="next-step">
          <h3>Próximo passo</h3>
          <p style="margin:0">Um dos instrutores da <b>PlanningBI</b> entrará em contato com você para dar continuidade ao seu processo de formação como <b>OKR Master</b>. Fique de olho no seu e-mail e WhatsApp.</p>
        </div>

        <div class="btn-row" style="justify-content:center;margin-top:20px">
          <a id="btnReview" class="btn ghost" href="#">Revisar minhas respostas</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>
  const BASE = '<?php echo $base; ?>';
  const SID  = <?php echo json_encode($sid, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
  const $ = s => document.querySelector(s);
  const CORES = { verde:'#1dd1a1', amarelo:'#feca57', vermelho:'#ff6b6b' };

  function fmtDur(ms){ const s=Math.round(ms/1000); const m=Math.floor(s/60); return m>0 ? `${m}:${String(s%60).padStart(2,'0')}` : `${s}s`; }

  (async function(){
    try{
      const r = await fetch(BASE + '/auth/resultado_get.php?sid=' + encodeURIComponent(SID), {headers:{'Accept':'application/json'}, cache:'no-store'});
      const t = await r.text(); let d;
      try{ d = JSON.parse(t); }catch(e){ throw new Error('Resposta inválida do servidor.'); }
      if(!r.ok || d.error) throw new Error(d.error || 'Falha ao carregar resultado.');

      const cor = CORES[d.cor] || CORES.verde;
      const pct = d.percentual;

      $('#loading').classList.add('hidden');
      $('#result').classList.remove('hidden');

      // anima o anel
      const ring = $('#ring');
      ring.style.setProperty('--ring-color', cor);
      ring.style.setProperty('--pct', '0%');
      $('#scoreNum').style.color = cor;
      $('#scoreNum').textContent = d.acertos;
      $('#scoreDen').textContent = 'de ' + d.total;
      requestAnimationFrame(()=> setTimeout(()=> ring.style.setProperty('--pct', pct + '%'), 60));

      const tag = $('#faixaTag');
      tag.textContent = d.faixa; tag.style.color = cor;
      $('#leitura').textContent = d.leitura;

      $('#mPct').textContent = pct + '%'; $('#mPct').style.color = cor;
      $('#mTotal').textContent = fmtDur(d.tempo_total_ms);
      $('#mMedia').textContent = Math.round(d.tempo_medio_ms/1000) + 's';

      if(d.nome){ $('#thanksTitle').textContent = 'Obrigado, ' + d.nome.split(' ')[0] + '!'; }

      // blocos com barra
      const host = $('#blocos');
      const entries = Object.entries(d.score_por_bloco || {});
      if(!entries.length){ host.innerHTML = '<p class="muted small">Sem dados de bloco.</p>'; }
      entries.forEach(([nome,val])=>{
        const c = val>=70 ? CORES.verde : (val>=40 ? CORES.amarelo : CORES.vermelho);
        const row = document.createElement('div');
        row.className = 'bloco-row';
        row.innerHTML = `<span class="nome">${nome}</span><span class="val">${val}%</span>
          <div class="bar"><span style="width:0%;background:${c}"></span></div>`;
        host.appendChild(row);
        requestAnimationFrame(()=> setTimeout(()=> row.querySelector('.bar span').style.width = val+'%', 120));
      });

      $('#btnReview').href = BASE + '/views/review.php?sid=' + encodeURIComponent(SID);
      gtag('event','okrm_resultado_view',{ event_category:'okrmaster', event_label:'modulo_1', value: d.acertos });
    }catch(err){
      $('#loadErr').textContent = err.message || 'Não foi possível carregar seu resultado.';
    }
  })();
</script>
