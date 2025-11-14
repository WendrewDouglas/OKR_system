<?php
// views/start.php
require __DIR__ . '/partials/header.php';
?>
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
          <label for="nome">Nome</label>
          <input type="text" id="nome" name="nome" autocomplete="name" required />
        </div>
        <div class="field">
          <label for="cargo">Posição profissional</label>
          <select id="cargo" name="cargo" required>
            <option value="" disabled selected>Selecione…</option>
            <!-- opções serão carregadas via JS a partir do banco -->
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
      <pre id="debugBox" style="display:none; margin-top:8px; padding:10px; background:#0f1319; border:1px solid rgba(255,255,255,.08); border-radius:10px; color:#9aa4b2; font-size:12px; max-height:240px; overflow:auto;"></pre>
    </form>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

<style>
  :root { --bg:#0b0d10; --card:#141820; --muted:#9aa4b2; --text:#e6edf3; --brand:#d4af37; --brand-2:#1dd1a1; --danger:#ff6b6b; --warn:#feca57; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue","Noto Sans",Arial; }
  .wrap { max-width:980px; margin:40px auto; padding:0 16px; }
  .hero { text-align:center; margin-bottom:24px; }
  h1 { font-size:32px; margin:8px 0; }
  .sub { color:var(--muted); margin-bottom:24px; }
  .card { background:var(--card); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
  .field { margin-bottom:14px; }
  label { display:block; margin-bottom:6px; font-weight:600; }
  input, select { width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,.1); background:#0f1319; color:var(--text); outline:none; }
  input:focus, select:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(212,175,55,.25); }
  .hint { color:var(--muted); font-size:12px; }
  .check { display:flex; gap:10px; align-items:flex-start; color:var(--muted); font-size:14px; }
  .check input { width:auto; margin-top:3px; accent-color: var(--brand); }
  .btn { border:1px solid rgba(255,255,255,.15); background:#10151c; color:#e6edf3; padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:700; }
  .btn.primary { background:var(--brand); border-color:transparent; color:#0b1117; }
  .btn:hover { filter:brightness(1.1); }
  .msg { margin-top:10px; font-size:14px; color:var(--muted); min-height:18px; }
  .msg.error { color:var(--danger); }
  .msg.warn { color:var(--warn); }
  .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  @media (max-width: 720px) {
    .grid-2 { grid-template-columns: 1fr; }
  }
</style>

<script>
  // === ENDPOINTS ===
  const API_BASE = '/OKR_system/LP/Quizz-01/auth/';
  const API = {
    versaoAtiva: API_BASE + 'versao_ativa.php',
    leadStart:   API_BASE + 'lead_start.php',
    sessStart:   API_BASE + 'sessao_start.php',
    cargosList:  API_BASE + 'cargos_list.php'
  };

  // Slug da landing/quiz
  const QUIZ_SLUG = 'lp001';

  // === HELPERS ===
  const $ = s => document.querySelector(s);
  const isCorporate = e => !/@(gmail|hotmail|outlook|yahoo|icloud)\./i.test(e);

  const utms = (() => {
    const p = new URLSearchParams(location.search);
    return {
      utm_source:  p.get('utm_source')  || undefined,
      utm_medium:  p.get('utm_medium')  || undefined,
      utm_campaign:p.get('utm_campaign')|| undefined,
      utm_content: p.get('utm_content') || undefined,
      utm_term:    p.get('utm_term')    || undefined
    };
  })();

  const debugBox = $('#debugBox');
  function showDebug(obj, title = 'DEBUG') {
    try {
      debugBox.style.display = 'block';
      const time = new Date().toISOString();
      const payload = (typeof obj === 'string') ? obj : JSON.stringify(obj, null, 2);
      debugBox.textContent += `\n[${time}] ${title}\n${payload}\n`;
    } catch (_) {}
  }
  function clearDebug(){
    if (debugBox) {
      debugBox.textContent = '';
      debugBox.style.display='none';
    }
  }

  async function api(method, url, data){
    const opt = {
      method,
      headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
      cache:'no-store',
      credentials:'same-origin'
    };
    if (data) opt.body = JSON.stringify(data);
    const started = performance.now();
    const r = await fetch(url, opt);
    const rawText = await r.text();
    const elapsed = (performance.now() - started).toFixed(1);

    showDebug({
      url, method, status: r.status, ok: r.ok, elapsed_ms: elapsed,
      headers: {
        'content-type': r.headers.get('content-type'),
        'cache-control': r.headers.get('cache-control')
      },
      raw_preview: rawText.replace(/\s+/g,' ').slice(0,500)
    }, 'api() response');

    let json;
    try { json = JSON.parse(rawText); }
    catch {
      throw new Error(`Resposta não-JSON de ${method} ${url} (status ${r.status}). Trecho: ${rawText.replace(/\s+/g,' ').slice(0,200)}`);
    }
    if (!r.ok || json.error) {
      const msg = json.message || json.error || `Falha HTTP ${r.status}`;
      throw new Error(msg);
    }
    return json;
  }

  // === CARREGA CARGOS ===
  (async () => {
    const sel = $('#cargo');
    const msg = $('#startMsg');
    clearDebug();
    try {
      const res = await fetch(API.cargosList, { headers:{ 'Accept':'application/json' }, cache:'no-store', credentials:'same-origin' });
      const body = await res.text();
      showDebug({endpoint:API.cargosList,status:res.status,ok:res.ok,body_preview:body.replace(/\s+/g,' ').slice(0,800)}, 'cargos_list fetch');
      let j = JSON.parse(body);
      if (!res.ok || !j || j.ok !== true) {
        msg.className = 'msg error';
        msg.textContent = (j && (j.message || j.error)) ? `Falha ao carregar cargos: ${j.message || j.error}` : `Falha ao carregar cargos (HTTP ${res.status}).`;
        return;
      }
      sel.querySelectorAll('option:not([value=""])').forEach(o => o.remove());
      (j.data||[]).forEach(row => {
        const opt = document.createElement('option');
        opt.value = String(row.id_cargo);
        opt.textContent = row.nome;
        sel.appendChild(opt);
      });
    } catch (e) {
      msg.className = 'msg error';
      msg.textContent = (e && e.message) ? e.message : 'Erro inesperado ao carregar cargos.';
      showDebug(String(e), 'Exception (cargos_list)');
    }
  })();

  // === SUBMISSÃO ===
  document.getElementById('formStart').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const msg = $('#startMsg'); msg.className='msg'; msg.textContent='';
    const email = $('#email').value.trim();
    const nome  = $('#nome').value.trim();
    const id_cargo = parseInt($('#cargo').value || '0', 10) || null;

    if(!email){ msg.classList.add('error'); msg.textContent='Informe seu e-mail corporativo.'; $('#email').focus(); return; }
    if(!isCorporate(email)){ msg.classList.add('warn'); msg.textContent='Prefira e-mail corporativo (ex.: nome@empresa.com.br).'; }
    if(!nome){ msg.classList.add('error'); msg.textContent='Informe seu nome.'; $('#nome').focus(); return; }
    if(!id_cargo){ msg.classList.add('error'); msg.textContent='Selecione sua posição profissional.'; $('#cargo').focus(); return; }
    if(!$('#consent_termos').checked){ msg.classList.add('error'); msg.textContent='Para continuar, é necessário autorizar o uso dos dados para gerar o relatório.'; return; }

    try{
      $('#btnStart').disabled = true;

      // 1) Cria lead
      const lead = await api('POST', API.leadStart, {
        email, nome, id_cargo,
        consent_termos: $('#consent_termos').checked,
        consent_marketing: $('#consent_marketing').checked,
        ...utms
      });

      try {
        localStorage.setItem('lead_nome', nome);
        localStorage.setItem('lead_email', email);
        if (lead && lead.id_lead) localStorage.setItem('lead_id', String(lead.id_lead));
      } catch(_) {}

      // 2) Busca versão ativa já filtrando por slug/cargo
      const qsVersao = new URLSearchParams({ slug: QUIZ_SLUG, id_cargo: String(id_cargo) }).toString();
      const v = await api('GET', API.versaoAtiva + '?' + qsVersao);

      // 3) Cria sessão
      const ses = await api('POST', API.sessStart, { id_versao: v.id_versao, id_lead: lead.id_lead });

      // 4) Redireciona para execução (agora levando também o id_cargo)
      window.location.href =
        '/OKR_system/LP/Quizz-01/views/run.php?sid=' +
        encodeURIComponent(ses.session_token) +
        '&id_cargo=' + encodeURIComponent(id_cargo);
    }catch(err){
      msg.classList.add('error');
      msg.textContent = (err && err.message) ? err.message : String(err);
      showDebug(String(err), 'Exception (submit)');
    }finally{
      $('#btnStart').disabled = false;
    }
  });
</script>
