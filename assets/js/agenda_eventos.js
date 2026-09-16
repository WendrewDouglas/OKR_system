/* assets/js/agenda_eventos.js — cadastro e detalhe dos eventos da empresa.
 *
 * Roda POR FORA do agenda.js de propósito: o motor de prazos não muda de
 * comportamento por causa daqui. A ligação entre os dois é só o atributo
 * data-agev que o agenda.js coloca no item do evento, capturado por delegação
 * em document. Depois de gravar, recarregamos a página em vez de mexer no
 * estado interno do calendário.
 */
(function () {
  'use strict';

  var A = window.AGENDA || {};
  var CATALOGO = A.eventos_empresa || {};
  var PESSOAS  = A.pessoas || {};
  var PODE     = !!A.pode_gerenciar_eventos;
  var API      = '/OKR_system/auth/agenda_eventos_api.php';

  var fundo   = document.getElementById('agevFundo');
  var dDet    = document.getElementById('agevDetalhe');
  var dForm   = document.getElementById('agevForm');
  if (!fundo || !dDet) return;

  // Contexto da ocorrência aberta no painel de detalhe.
  var atual = { id: 0, dataRef: '', data: '' };

  var DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function dataBr(iso) {
    if (!iso || iso.length < 10) return '';
    return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
  }

  function fechar() {
    fundo.classList.remove('aberto');
    [dDet, dForm].forEach(function (d) {
      if (!d) return;
      d.classList.remove('aberto');
      d.setAttribute('aria-hidden', 'true');
    });
  }

  function abrir(drawer) {
    fundo.classList.add('aberto');
    drawer.classList.add('aberto');
    drawer.setAttribute('aria-hidden', 'false');
  }

  /** Frase legível da regra de repetição, para o painel de detalhe. */
  function textoRepeticao(ev) {
    var r = ev.recorrencia || 'nenhuma';
    if (r === 'nenhuma') return 'Não se repete';

    var dias = String(ev.dias_semana || '').split(',').filter(function (d) { return d !== ''; });
    var nomes = dias.map(function (d) { return DIAS[parseInt(d, 10)]; }).filter(Boolean).join(', ');

    var txt;
    if (r === 'semanal')        txt = 'Toda semana' + (nomes ? ' (' + nomes + ')' : '');
    else if (r === 'quinzenal') txt = 'A cada 15 dias' + (nomes ? ' (' + nomes + ')' : '');
    else if (r === 'mensal')    txt = (ev.regra_mensal === 'semana')
                                    ? 'Todo mês, na mesma posição da semana'
                                    : 'Todo mês, no mesmo dia';
    else txt = r;

    if (ev.data_fim) txt += ' · até ' + dataBr(ev.data_fim);
    return txt;
  }

  function nomeDe(id) {
    var p = PESSOAS[id];
    return p ? (p.nome || p.nome_curto || ('Usuário ' + id)) : ('Usuário ' + id);
  }

  /* ---------------- painel de detalhe ---------------- */

  function abrirDetalhe(id, dataRef, data) {
    var ev = CATALOGO[id];
    if (!ev) return;
    atual = { id: id, dataRef: dataRef, data: data };

    document.getElementById('agevDetTitulo').textContent = ev.titulo || 'Evento';

    var hora = ev.hora_inicio
      ? (ev.hora_inicio + (ev.hora_fim ? ' às ' + ev.hora_fim : ''))
      : 'Sem horário definido';

    var organiza = [], participa = [];
    (ev.pessoas || []).forEach(function (p) {
      (p.papel === 'responsavel' ? organiza : participa).push(nomeDe(p.id));
    });

    var html = '';
    html += '<dt>Data</dt><dd>' + esc(dataBr(data)) + '</dd>';
    html += '<dt>Horário</dt><dd>' + esc(hora) + '</dd>';
    if (ev.local)     html += '<dt>Local</dt><dd>' + esc(ev.local) + '</dd>';
    if (ev.descricao) html += '<dt>Descrição</dt><dd>' + esc(ev.descricao).replace(/\n/g, '<br>') + '</dd>';
    html += '<dt>Repetição</dt><dd>' + esc(textoRepeticao(ev)) + '</dd>';
    if (organiza.length)  html += '<dt>Organização</dt><dd>' + esc(organiza.join(', ')) + '</dd>';
    if (participa.length) html += '<dt>Participantes</dt><dd>' + esc(participa.join(', ')) + '</dd>';

    document.getElementById('agevDetCorpo').innerHTML = html;
    abrir(dDet);
  }

  // Delegação: o item do evento é criado pelo agenda.js a cada render.
  document.addEventListener('click', function (e) {
    var alvo = e.target.closest ? e.target.closest('[data-agev]') : null;
    if (alvo) {
      e.preventDefault();
      abrirDetalhe(
        parseInt(alvo.getAttribute('data-agev'), 10),
        alvo.getAttribute('data-agev-ref') || '',
        alvo.getAttribute('data-agev-data') || ''
      );
      return;
    }
    if (e.target.closest && e.target.closest('[data-agev-fechar]')) fechar();
  });

  fundo.addEventListener('click', fechar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });

  /* ---------------- cadastro (só quem gerencia) ---------------- */

  if (!PODE || !dForm) return;

  var elId    = document.getElementById('agevId');
  var elTit   = document.getElementById('agevTitulo');
  var elDesc  = document.getElementById('agevDescricao');
  var elLocal = document.getElementById('agevLocal');
  var elData  = document.getElementById('agevData');
  var elHi    = document.getElementById('agevHoraIni');
  var elHf    = document.getElementById('agevHoraFim');
  var elRec   = document.getElementById('agevRec');
  var elRegra = document.getElementById('agevRegraMes');
  var elFim   = document.getElementById('agevDataFim');

  function alternarRecorrencia() {
    var r = elRec.value;
    document.getElementById('agevBoxDias').hidden   = (r !== 'semanal' && r !== 'quinzenal');
    document.getElementById('agevBoxMensal').hidden = (r !== 'mensal');
    document.getElementById('agevBoxFim').hidden    = (r === 'nenhuma');
  }
  elRec.addEventListener('change', alternarRecorrencia);

  function marcar(seletor, valores) {
    var set = {};
    (valores || []).forEach(function (v) { set[String(v)] = true; });
    document.querySelectorAll(seletor).forEach(function (c) { c.checked = !!set[c.value]; });
  }

  function abrirForm(ev) {
    document.getElementById('agevFormTitulo').textContent = ev ? 'Editar evento' : 'Novo evento';
    elId.value    = ev ? ev.id : 0;
    elTit.value   = ev ? (ev.titulo || '') : '';
    elDesc.value  = ev ? (ev.descricao || '') : '';
    elLocal.value = ev ? (ev.local || '') : '';
    elData.value  = ev ? (ev.data_inicio || '') : '';
    elHi.value    = ev ? (ev.hora_inicio || '') : '';
    elHf.value    = ev ? (ev.hora_fim || '') : '';
    elRec.value   = ev ? (ev.recorrencia || 'nenhuma') : 'nenhuma';
    elRegra.value = (ev && ev.regra_mensal === 'semana') ? 'semana' : 'dia';
    elFim.value   = ev ? (ev.data_fim || '') : '';

    marcar('.agev-dia', ev ? String(ev.dias_semana || '').split(',') : []);
    marcar('.agev-pessoa', ev ? (ev.pessoas || []).map(function (p) { return p.id; }) : []);

    alternarRecorrencia();
    abrir(dForm);
  }

  function postar(campos, aoFinal) {
    var fd = new FormData();
    fd.append('csrf_token', window.AGEV_CSRF || '');
    Object.keys(campos).forEach(function (k) { fd.append(k, campos[k]); });

    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Resposta inválida do servidor.' }; }); })
      .then(function (j) {
        if (!j || !j.success) { alert((j && j.error) || 'Não foi possível concluir.'); return; }
        aoFinal();
      })
      .catch(function () { alert('Falha de conexão ao salvar o evento.'); });
  }

  function recarregar() { window.location.reload(); }

  var btnNovo = document.getElementById('agevNovo');
  if (btnNovo) btnNovo.addEventListener('click', function () { abrirForm(null); });

  document.getElementById('agevSalvar').addEventListener('click', function () {
    if (!elTit.value.trim()) { alert('Informe o título do evento.'); elTit.focus(); return; }
    if (!elData.value)       { alert('Informe a data do evento.');   elData.focus(); return; }

    var dias = Array.prototype.map.call(
      document.querySelectorAll('.agev-dia:checked'), function (c) { return c.value; }).join(',');
    var pessoas = Array.prototype.map.call(
      document.querySelectorAll('.agev-pessoa:checked'), function (c) { return parseInt(c.value, 10); });

    postar({
      acao: 'salvar',
      id_evento: elId.value,
      titulo: elTit.value.trim(),
      descricao: elDesc.value.trim(),
      local: elLocal.value.trim(),
      data_inicio: elData.value,
      hora_inicio: elHi.value,
      hora_fim: elHf.value,
      recorrencia: elRec.value,
      dias_semana: dias,
      regra_mensal: elRegra.value,
      data_fim: elFim.value,
      pessoas_json: JSON.stringify(pessoas)
    }, recarregar);
  });

  document.getElementById('agevEditar').addEventListener('click', function () {
    var ev = CATALOGO[atual.id];
    if (ev) abrirForm(ev);
  });

  document.getElementById('agevCancelarOc').addEventListener('click', function () {
    if (!atual.id || !atual.dataRef) return;
    var ev = CATALOGO[atual.id];
    if (!window.confirm('Cancelar o evento "' + (ev ? ev.titulo : '') + '" no dia ' + dataBr(atual.data) +
                        '?\nAs demais datas da série continuam.')) return;
    postar({ acao: 'ocorrencia', id_evento: atual.id, data_ref: atual.dataRef, tipo: 'cancelar' }, recarregar);
  });

  document.getElementById('agevExcluir').addEventListener('click', function () {
    if (!atual.id) return;
    var ev = CATALOGO[atual.id];
    if (!window.confirm('Excluir a série "' + (ev ? ev.titulo : '') + '" inteira?\n' +
                        'Todas as datas deixam de aparecer na agenda da empresa.')) return;
    postar({ acao: 'excluir', id_evento: atual.id }, recarregar);
  });
})();
