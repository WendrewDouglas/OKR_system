/* =============================================================
   Perspectivas de Gestão (FMX) — comportamento da trilha.
   Navegação por blocos, validação client-side espelhando o backend,
   coleta por tipo de pergunta, autosave (localStorage) e retomada.
   Endpoints relativos a /public/ -> ../api/
   ============================================================= */
(function () {
  'use strict';

  var body = document.body;
  var CFG = {
    csrf: body.getAttribute('data-csrf') || '',
    api: body.getAttribute('data-api') || '../api/',
    totalSteps: parseInt(body.getAttribute('data-total-steps') || '8', 10)
  };
  var LS_KEY = 'pg_perspectivas_gestao_v1';
  var TEXT_MIN = 5;    // respostas abertas longas
  var SUBTEXT_MIN = 2; // subcampos estruturados (nome de animal, frente, etc.)

  var form = document.getElementById('pg-form');
  var steps = Array.prototype.slice.call(document.querySelectorAll('.pg-step'));
  var fill = document.getElementById('pg-progress-fill');
  var stepLabel = document.getElementById('pg-progress-step');

  var state = { step: 0, sessionToken: null, busy: false };

  try { document.getElementById('pg-year').textContent = String(new Date().getFullYear()); } catch (e) {}

  /* --------------------------- Rede --------------------------- */
  function postJSON(endpoint, data) {
    var payload = Object.assign({ csrf: CFG.csrf }, data);
    return fetch(CFG.api + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (j) { return { http: r.status, body: j }; })
        .catch(function () { return { http: r.status, body: { ok: false, error: { message: 'Resposta inválida do servidor.' } } }; });
    });
  }

  /* --------------------- Navegação/trilha --------------------- */
  function indexByStep(val) {
    for (var i = 0; i < steps.length; i++) {
      if (steps[i].getAttribute('data-step') === String(val)) return i;
    }
    return -1;
  }

  function showStep(i) {
    if (i < 0 || i >= steps.length) return;
    var current = steps[state.step];
    var nextEl = steps[i];
    var forward = i >= state.step;

    if (current && current !== nextEl) {
      current.classList.remove('is-active');
      current.classList.add(forward ? 'leave-left' : 'leave-right');
      setTimeout(function () { current.classList.remove('leave-left', 'leave-right'); }, 380);
    }
    nextEl.classList.remove('leave-left', 'leave-right');
    // reflow para reiniciar a animação de entrada
    void nextEl.offsetWidth;
    nextEl.classList.add('is-active');
    nextEl.classList.add(forward ? 'enter-right' : 'enter-left');
    setTimeout(function () { nextEl.classList.remove('enter-right', 'enter-left'); }, 380);

    state.step = i;
    updateProgress();
    try { document.getElementById('pg-card').scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
  }

  function updateProgress() {
    var dataStep = steps[state.step].getAttribute('data-step');
    var n;
    if (dataStep === 'thanks') { n = CFG.totalSteps; }
    else { n = parseInt(dataStep, 10) + 1; }
    var pct = Math.round((n / CFG.totalSteps) * 100);
    if (fill) fill.style.width = pct + '%';
    if (stepLabel) stepLabel.textContent = String(Math.min(n, CFG.totalSteps));
  }

  /* --------------------- Escala 0..10 ------------------------ */
  document.addEventListener('click', function (ev) {
    var pill = ev.target.closest ? ev.target.closest('.pg-pill') : null;
    if (!pill) return;
    var scale = pill.parentNode;
    var pills = scale.querySelectorAll('.pg-pill');
    for (var i = 0; i < pills.length; i++) {
      pills[i].classList.remove('is-selected');
      pills[i].setAttribute('aria-checked', 'false');
    }
    pill.classList.add('is-selected');
    pill.setAttribute('aria-checked', 'true');
    scale.setAttribute('data-value', pill.getAttribute('data-val'));
    clearError(scale.closest('.pg-question'));
    saveDraft();
  });

  function scaleValue(scaleEl) {
    if (!scaleEl || !scaleEl.hasAttribute('data-value')) return null;
    var v = parseInt(scaleEl.getAttribute('data-value'), 10);
    return (v >= 0 && v <= 10) ? v : null;
  }

  function setScaleValue(scaleEl, val) {
    if (!scaleEl) return;
    var pills = scaleEl.querySelectorAll('.pg-pill');
    for (var i = 0; i < pills.length; i++) {
      var match = parseInt(pills[i].getAttribute('data-val'), 10) === val;
      pills[i].classList.toggle('is-selected', match);
      pills[i].setAttribute('aria-checked', match ? 'true' : 'false');
    }
    if (val !== null && val !== undefined) scaleEl.setAttribute('data-value', String(val));
  }

  /* --------------- Coleta + validação por pergunta ------------ */
  function collectQuestion(qEl) {
    var shape = qEl.getAttribute('data-shape');
    var atype = qEl.getAttribute('data-atype');
    var qkey = qEl.getAttribute('data-qkey');
    var value, err = null;

    function text(el) { return (el && el.value ? el.value : '').trim(); }

    if (shape === 'open') {
      value = text(qEl.querySelector('[data-role="open"]'));
      if (value.length < TEXT_MIN) err = 'Resposta muito curta (mínimo ' + TEXT_MIN + ' caracteres).';

    } else if (shape === 'scale') {
      value = scaleValue(qEl.querySelector('.pg-scale'));
      if (value === null) err = 'Informe uma nota de 0 a 10.';

    } else if (shape === 'fields') {
      value = {};
      var fields = qEl.querySelectorAll('.pg-field');
      for (var i = 0; i < fields.length && !err; i++) {
        var f = fields[i];
        var name = f.getAttribute('data-field');
        var ftype = f.getAttribute('data-ftype');
        if (ftype === 'scale') {
          var sv = scaleValue(f.querySelector('.pg-scale'));
          if (sv === null) { err = 'Informe uma nota de 0 a 10.'; break; }
          value[name] = sv;
        } else if (ftype === 'enum') {
          var checked = f.querySelector('input[type="radio"]:checked');
          if (!checked) { err = 'Selecione uma das opções.'; break; }
          value[name] = checked.value;
        } else {
          var t = text(f.querySelector('textarea, input'));
          if (t.length < SUBTEXT_MIN) { err = 'Preencha todos os campos solicitados.'; break; }
          value[name] = t;
        }
      }

    } else if (shape === 'groups') {
      value = {};
      var groups = qEl.querySelectorAll('.pg-group');
      for (var g = 0; g < groups.length && !err; g++) {
        var gEl = groups[g];
        var gname = gEl.getAttribute('data-group');
        value[gname] = {};
        var gfields = gEl.querySelectorAll('.pg-field');
        for (var j = 0; j < gfields.length; j++) {
          var fn = gfields[j].getAttribute('data-field');
          var tv = text(gfields[j].querySelector('textarea, input'));
          if (tv.length < SUBTEXT_MIN) { err = 'Preencha todos os campos solicitados.'; break; }
          value[gname][fn] = tv;
        }
      }

    } else if (shape === 'matrix_flat') {
      value = {};
      var rows = qEl.querySelectorAll('.pg-matrix-row');
      for (var r = 0; r < rows.length && !err; r++) {
        var key = rows[r].getAttribute('data-key');
        var mv = scaleValue(rows[r].querySelector('.pg-scale'));
        if (mv === null) { err = 'Preencha todas as notas de 0 a 10.'; break; }
        value[key] = mv;
      }

    } else if (shape === 'matrix_nested') {
      value = {};
      var units = qEl.querySelectorAll('.pg-unit');
      for (var u = 0; u < units.length && !err; u++) {
        var rk = units[u].getAttribute('data-row');
        value[rk] = {};
        var crits = units[u].querySelectorAll('.pg-unit-crit');
        for (var c = 0; c < crits.length; c++) {
          var ck = crits[c].getAttribute('data-col');
          var cv = scaleValue(crits[c].querySelector('.pg-scale'));
          if (cv === null) { err = 'Preencha todas as notas de todas as unidades.'; break; }
          value[rk][ck] = cv;
        }
      }
    }

    return { qkey: qkey, atype: atype, value: value, error: err };
  }

  function clearError(qEl) {
    if (!qEl) return;
    var box = qEl.querySelector('.pg-q-error');
    if (box) box.textContent = '';
    qEl.classList.remove('has-error');
  }

  function setError(qEl, msg) {
    if (!qEl) return;
    var box = qEl.querySelector('.pg-q-error');
    if (box) box.textContent = msg;
    qEl.classList.add('has-error');
  }

  /** Valida e coleta um bloco inteiro. Retorna {ok, answers, firstInvalid}. */
  function collectBlock(stepEl) {
    var qEls = stepEl.querySelectorAll('.pg-question');
    var answers = [];
    var firstInvalid = null;
    for (var i = 0; i < qEls.length; i++) {
      clearError(qEls[i]);
      var res = collectQuestion(qEls[i]);
      if (res.error) {
        setError(qEls[i], res.error);
        if (!firstInvalid) firstInvalid = qEls[i];
      } else {
        answers.push({ question_key: res.qkey, answer_type: res.atype, value: res.value });
      }
    }
    return { ok: !firstInvalid, answers: answers, firstInvalid: firstInvalid };
  }

  /* --------------------- Autosave (rascunho) ----------------- */
  function collectAllAnswersRaw() {
    var out = {};
    var qEls = form.querySelectorAll('.pg-question');
    for (var i = 0; i < qEls.length; i++) {
      var res = collectQuestion(qEls[i]);
      out[res.qkey] = res.value; // grava mesmo parcial (para retomar)
    }
    return out;
  }

  function saveDraft() {
    try {
      var data = {
        sessionToken: state.sessionToken,
        identification: {
          nome: val('pg-nome'), email: val('pg-email'), whatsapp: val('pg-whatsapp')
        },
        answers: collectAllAnswersRaw()
      };
      localStorage.setItem(LS_KEY, JSON.stringify(data));
    } catch (e) {}
  }

  function clearDraft() { try { localStorage.removeItem(LS_KEY); } catch (e) {} }

  function restoreDraft() {
    var raw;
    try { raw = localStorage.getItem(LS_KEY); } catch (e) { return; }
    if (!raw) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    if (!data) return;

    if (data.identification) {
      setVal('pg-nome', data.identification.nome);
      setVal('pg-email', data.identification.email);
      setVal('pg-whatsapp', data.identification.whatsapp);
    }
    if (data.sessionToken) state.sessionToken = data.sessionToken;
    if (data.answers) {
      var qEls = form.querySelectorAll('.pg-question');
      for (var i = 0; i < qEls.length; i++) {
        var qkey = qEls[i].getAttribute('data-qkey');
        if (data.answers[qkey] !== undefined && data.answers[qkey] !== null) {
          applyValue(qEls[i], data.answers[qkey]);
        }
      }
    }
  }

  /** Reaplica um valor salvo ao DOM da pergunta. */
  function applyValue(qEl, value) {
    var shape = qEl.getAttribute('data-shape');
    if (shape === 'open') {
      var ta = qEl.querySelector('[data-role="open"]'); if (ta) ta.value = value || '';
    } else if (shape === 'scale') {
      setScaleValue(qEl.querySelector('.pg-scale'), value);
    } else if (shape === 'fields') {
      var fields = qEl.querySelectorAll('.pg-field');
      for (var i = 0; i < fields.length; i++) {
        var name = fields[i].getAttribute('data-field');
        var ftype = fields[i].getAttribute('data-ftype');
        var v = value ? value[name] : undefined;
        if (v === undefined) continue;
        if (ftype === 'scale') setScaleValue(fields[i].querySelector('.pg-scale'), v);
        else if (ftype === 'enum') {
          var radios = fields[i].querySelectorAll('input[type="radio"]');
          for (var k = 0; k < radios.length; k++) radios[k].checked = (radios[k].value === v);
        } else { var el = fields[i].querySelector('textarea, input'); if (el) el.value = v; }
      }
    } else if (shape === 'groups') {
      var groups = qEl.querySelectorAll('.pg-group');
      for (var g = 0; g < groups.length; g++) {
        var gname = groups[g].getAttribute('data-group');
        var gv = value ? value[gname] : undefined;
        if (!gv) continue;
        var gf = groups[g].querySelectorAll('.pg-field');
        for (var j = 0; j < gf.length; j++) {
          var fn = gf[j].getAttribute('data-field');
          if (gv[fn] !== undefined) { var e2 = gf[j].querySelector('textarea, input'); if (e2) e2.value = gv[fn]; }
        }
      }
    } else if (shape === 'matrix_flat') {
      var rows = qEl.querySelectorAll('.pg-matrix-row');
      for (var r = 0; r < rows.length; r++) {
        var key = rows[r].getAttribute('data-key');
        if (value && value[key] !== undefined) setScaleValue(rows[r].querySelector('.pg-scale'), value[key]);
      }
    } else if (shape === 'matrix_nested') {
      var units = qEl.querySelectorAll('.pg-unit');
      for (var u = 0; u < units.length; u++) {
        var rk = units[u].getAttribute('data-row');
        var rv = value ? value[rk] : undefined;
        if (!rv) continue;
        var crits = units[u].querySelectorAll('.pg-unit-crit');
        for (var c = 0; c < crits.length; c++) {
          var ck = crits[c].getAttribute('data-col');
          if (rv[ck] !== undefined) setScaleValue(crits[c].querySelector('.pg-scale'), rv[ck]);
        }
      }
    }
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
  function setVal(id, v) { var el = document.getElementById(id); if (el && v) el.value = v; }

  /* --------------------- Identificação ----------------------- */
  function validateIdentification() {
    var ok = true;
    clearFieldError('nome'); clearFieldError('email'); clearFieldError('whatsapp'); clearFieldError('consent');

    var nome = val('pg-nome');
    if (nome.length < 2) { fieldError('nome', 'Informe seu nome completo.'); ok = false; }

    var email = val('pg-email');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { fieldError('email', 'Informe um e-mail válido.'); ok = false; }

    var wp = val('pg-whatsapp').replace(/\D+/g, '');
    if (wp.length < 10 || wp.length > 13) { fieldError('whatsapp', 'Informe um WhatsApp válido com DDD.'); ok = false; }

    if (!document.getElementById('pg-consent').checked) { fieldError('consent', 'É necessário aceitar o termo para continuar.'); ok = false; }

    return ok;
  }

  function fieldError(name, msg) {
    var box = document.querySelector('.pg-q-error[data-for="' + name + '"]');
    if (box) box.textContent = msg;
  }
  function clearFieldError(name) {
    var box = document.querySelector('.pg-q-error[data-for="' + name + '"]');
    if (box) box.textContent = '';
  }

  /* --------------------- Ações principais -------------------- */
  function doStart() {
    if (state.busy) return;
    if (!validateIdentification()) { focusFirst('.pg-q-error[data-for]'); return; }
    state.busy = true; setBusy('start', true);

    postJSON('start.php', {
      nome: val('pg-nome'),
      email: val('pg-email'),
      whatsapp: val('pg-whatsapp'),
      consent: document.getElementById('pg-consent').checked,
      website: val('pg-website')
    }).then(function (resp) {
      state.busy = false; setBusy('start', false);
      if (!resp.body || !resp.body.ok) { return handleError(resp); }
      state.sessionToken = resp.body.data.session_token;
      saveDraft();
      // Vai ao primeiro bloco (step 1).
      var target = indexByStep(1);
      showStep(target >= 0 ? target : 1);
    }).catch(function () {
      state.busy = false; setBusy('start', false);
      alertBox('Falha de conexão. Verifique sua internet e tente novamente.');
    });
  }

  function doNextOrFinish(finish) {
    if (state.busy) return;
    var stepEl = steps[state.step];
    var res = collectBlock(stepEl);
    if (!res.ok) { if (res.firstInvalid) scrollTo(res.firstInvalid); return; }
    if (!state.sessionToken) { alertBox('Sessão expirada. Recarregue a página.'); return; }

    state.busy = true;
    saveDraft();

    postJSON('save_block.php', {
      session_token: state.sessionToken,
      block_key: stepEl.getAttribute('data-block'),
      answers: res.answers
    }).then(function (resp) {
      if (!resp.body || !resp.body.ok) { state.busy = false; return handleError(resp, stepEl); }
      if (finish) { return doFinish(); }
      state.busy = false;
      showStep(state.step + 1);
    }).catch(function () {
      state.busy = false;
      alertBox('Falha de conexão ao salvar. Tente novamente.');
    });
  }

  function doFinish() {
    postJSON('finish.php', { session_token: state.sessionToken }).then(function (resp) {
      state.busy = false;
      if (!resp.body || !resp.body.ok) { return handleError(resp); }
      clearDraft();
      var t = indexByStep('thanks');
      showStep(t >= 0 ? t : steps.length - 1);
    }).catch(function () {
      state.busy = false;
      alertBox('Falha de conexão ao concluir. Tente novamente.');
    });
  }

  /* --------------------- Erros do servidor ------------------- */
  function handleError(resp, stepEl) {
    var err = (resp.body && resp.body.error) || {};
    var fields = err.fields || {};

    // Erros de identificação
    if (err.code === 'validation_error' && (fields.nome || fields.email || fields.whatsapp || fields.consent)) {
      if (fields.nome) fieldError('nome', fields.nome);
      if (fields.email) fieldError('email', fields.email);
      if (fields.whatsapp) fieldError('whatsapp', fields.whatsapp);
      if (fields.consent) fieldError('consent', fields.consent);
      return;
    }
    // Erros por pergunta (save_block)
    if ((err.code === 'validation_error' || err.code === 'incomplete') && stepEl) {
      var mapped = fields.missing_by_block ? flattenMissing(fields) : fields;
      var qEls = stepEl.querySelectorAll('.pg-question');
      var first = null;
      for (var i = 0; i < qEls.length; i++) {
        var qk = qEls[i].getAttribute('data-qkey');
        if (mapped[qk]) { setError(qEls[i], typeof mapped[qk] === 'string' ? mapped[qk] : 'Resposta obrigatória.'); if (!first) first = qEls[i]; }
      }
      if (first) { scrollTo(first); return; }
    }
    // incomplete no finish: leva ao primeiro bloco com pendência
    if (err.code === 'incomplete' && fields.missing_by_block) {
      var blocks = Object.keys(fields.missing_by_block);
      if (blocks.length) { var idx = indexByStep(stepIndexOfBlock(blocks[0])); if (idx >= 0) showStep(idx); }
      alertBox('Ainda faltam perguntas obrigatórias. Revise os blocos destacados.');
      return;
    }
    alertBox(err.message || 'Não foi possível processar. Tente novamente.');
  }

  function flattenMissing(fields) {
    var out = {};
    var mb = fields.missing_by_block || {};
    Object.keys(mb).forEach(function (b) { (mb[b] || []).forEach(function (qk) { out[qk] = 'Resposta obrigatória.'; }); });
    return out;
  }

  function stepIndexOfBlock(bkey) {
    for (var i = 0; i < steps.length; i++) {
      if (steps[i].getAttribute('data-block') === bkey) return steps[i].getAttribute('data-step');
    }
    return 1;
  }

  /* --------------------- Utilidades UI ----------------------- */
  function scrollTo(el) { try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {} var f = el.querySelector('textarea,input,.pg-pill'); if (f) try { f.focus(); } catch (e) {} }
  function focusFirst(sel) { var box = document.querySelector(sel); if (box) scrollTo(box.parentNode || box); }
  function setBusy(action, on) {
    var btn = document.querySelector('[data-action="' + action + '"]');
    if (btn) { btn.disabled = on; btn.classList.toggle('is-busy', on); }
  }
  function alertBox(msg) {
    var el = document.getElementById('pg-alert');
    if (!el) {
      el = document.createElement('div'); el.id = 'pg-alert'; el.className = 'pg-alert';
      document.getElementById('pg-card').insertBefore(el, form);
    }
    el.textContent = msg; el.style.display = 'block';
    setTimeout(function () { try { el.style.display = 'none'; } catch (e) {} }, 6000);
  }

  /* --------------------- Ligações de eventos ----------------- */
  form.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-action]') : null;
    if (!btn) return;
    var action = btn.getAttribute('data-action');
    if (action === 'start') doStart();
    else if (action === 'next') doNextOrFinish(false);
    else if (action === 'finish') doNextOrFinish(true);
    else if (action === 'prev') { if (state.step > 0) showStep(state.step - 1); }
  });

  // Autosave em digitação (debounce leve)
  var saveTimer = null;
  form.addEventListener('input', function () {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, 500);
  });

  // Limpa erro ao editar
  form.addEventListener('input', function (ev) {
    var q = ev.target.closest ? ev.target.closest('.pg-question') : null;
    if (q) clearError(q);
  });

  /* --------------------- Inicialização ----------------------- */
  restoreDraft();
  updateProgress();
})();
