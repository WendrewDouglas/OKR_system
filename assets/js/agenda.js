/* Agenda geral — render da grade do mês.
 *
 * Consome window.AGENDA (payload normalizado de auth/helpers/agenda_events.php):
 *   { eventos, pessoas, objetivos, krs, iniciativas, hoje }
 * O evento só traz ids; título, contexto e pessoas saem dos catálogos.
 *
 * Todo o volume cabe em memória (pico de 296 eventos numa empresa), então
 * navegar entre meses não faz round-trip.
 */
(function () {
  'use strict';

  var A = window.AGENDA;
  if (!A) return;

  var TIPOS = {
    objetivo:        { icone: 'fa-bullseye',       rotulo: 'Objetivo' },
    inicio_objetivo: { icone: 'fa-flag',           rotulo: 'Início do objetivo' },
    kr:              { icone: 'fa-crosshairs',     rotulo: 'Key Result' },
    iniciativa:      { icone: 'fa-list-check',     rotulo: 'Iniciativa' },
    marco:           { icone: 'fa-circle',         rotulo: 'Marco' }
  };
  var ESTADOS = {
    vencido:   'Vencido',
    hoje:      'Vence hoje',
    proximo:   'Vence em 7 dias',
    futuro:    'Futuro',
    concluido: 'Concluído',
    cancelado: 'Cancelado',
    pausado:   'Pausado',
    sem_data:  'Sem data'
  };
  var MESES = ['janeiro','fevereiro','março','abril','maio','junho',
               'julho','agosto','setembro','outubro','novembro','dezembro'];
  var DOW = ['dom','seg','ter','qua','qui','sex','sáb'];

  var MAX_CHIPS = 3;   // acima disso o dia mostra "+N"
  var MAX_MARCOS = 2;  // acima disso os marcos do dia colapsam num contador

  /* ---------- utilidades de data (string YYYY-MM-DD, sem Date/fuso) ---------- */

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function chave(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
  function hojeStr() { return A.hoje; }

  /* ---------- índice ---------- */

  var porData = {};
  A.eventos.forEach(function (ev) {
    if (!ev.data) return;
    (porData[ev.data] = porData[ev.data] || []).push(ev);
  });

  /**
   * Resolve o que o evento não carrega: título, contexto e pessoas vêm do
   * catálogo correspondente ao tipo.
   */
  function resolver(ev) {
    var titulo = '', contexto = null, pessoas = [];
    if (ev.tipo === 'kr' || ev.tipo === 'marco') {
      var kr = A.krs[ev.id_kr];
      if (kr) { titulo = kr.descricao; pessoas = kr.pessoas || []; }
      var o1 = A.objetivos[ev.id_objetivo];
      contexto = o1 ? o1.descricao : null;
    } else if (ev.tipo === 'iniciativa') {
      var ini = A.iniciativas[ev.id_iniciativa];
      if (ini) { titulo = ini.descricao; pessoas = ini.pessoas || []; }
      var kr2 = A.krs[ev.id_kr];
      contexto = kr2 ? kr2.descricao : null;
    } else {
      var o2 = A.objetivos[ev.id_objetivo];
      if (o2) { titulo = o2.descricao; pessoas = o2.pessoas || []; }
    }
    return {
      titulo: titulo || '(sem descrição)',
      contexto: contexto,
      pessoas: pessoas,
      url: '/OKR_system/views/detalhe_okr.php?id=' + ev.id_objetivo
    };
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ---------- estado da tela ---------- */

  var hoje = hojeStr();
  var partes = hoje.split('-');
  var ano = parseInt(partes[0], 10);
  var mes = parseInt(partes[1], 10) - 1;
  var diaSel = hoje;

  var elGrid    = document.getElementById('agGrid');
  var elPeriodo = document.getElementById('agPeriodo');
  var elDia     = document.getElementById('agDia');
  var elResumo  = document.getElementById('agResumo');

  /* ---------- render da grade ---------- */

  function renderMes() {
    elPeriodo.textContent = MESES[mes] + ' de ' + ano;

    var html = '';
    DOW.forEach(function (d) { html += '<div class="ag-dow">' + d + '</div>'; });

    // Começa no domingo da semana que contém o dia 1, e sempre desenha 6
    // semanas: a grade não "pula" de altura ao trocar de mês.
    var primeiro = new Date(ano, mes, 1);
    var inicio = new Date(ano, mes, 1 - primeiro.getDay());

    for (var i = 0; i < 42; i++) {
      var d = new Date(inicio.getFullYear(), inicio.getMonth(), inicio.getDate() + i);
      var k = chave(d.getFullYear(), d.getMonth(), d.getDate());
      var fora = d.getMonth() !== mes;
      var cls = 'ag-cell' + (fora ? ' fora' : '') +
                (k === hoje ? ' hoje' : '') + (k === diaSel ? ' sel' : '');
      html += '<div class="' + cls + '" data-dia="' + k + '">' +
                '<div class="ag-daynum">' + d.getDate() + '</div>' +
                chipsDoDia(k) +
              '</div>';
    }
    elGrid.innerHTML = html;
    renderResumo();
  }

  /**
   * Monta os chips de um dia. Os marcos colapsam num contador quando passam de
   * MAX_MARCOS: são 60% de todos os eventos e afogariam os prazos.
   */
  function chipsDoDia(k) {
    var evs = porData[k];
    if (!evs || !evs.length) return '';

    var marcos = [], outros = [];
    evs.forEach(function (e) { (e.tipo === 'marco' ? marcos : outros).push(e); });

    var chips = [];

    outros.forEach(function (e) {
      var r = resolver(e);
      chips.push(
        '<div class="ag-chip est-' + e.estado + ' ' + e.estado + '" title="' + esc(TIPOS[e.tipo].rotulo + ' · ' + r.titulo) + '">' +
          '<i class="fa-solid ' + TIPOS[e.tipo].icone + '"></i>' +
          '<span class="txt">' + esc(r.titulo) + '</span>' +
        '</div>');
    });

    if (marcos.length > MAX_MARCOS) {
      // O pior estado do grupo comanda a cor: um marco vencido no meio do
      // bolo não pode ficar invisível.
      var ordem = ['vencido', 'hoje', 'proximo', 'futuro', 'concluido', 'pausado', 'cancelado', 'sem_data'];
      var pior = marcos.reduce(function (acc, e) {
        return ordem.indexOf(e.estado) < ordem.indexOf(acc) ? e.estado : acc;
      }, 'sem_data');
      chips.push(
        '<div class="ag-chip grupo est-' + pior + '" title="' + marcos.length + ' marcos neste dia">' +
          '<i class="fa-solid fa-circle"></i>' +
          '<span class="txt">' + marcos.length + ' marcos</span>' +
        '</div>');
    } else {
      marcos.forEach(function (e) {
        var r = resolver(e);
        chips.push(
          '<div class="ag-chip est-' + e.estado + ' ' + e.estado + '" title="' + esc('Marco · ' + r.titulo) + '">' +
            '<i class="fa-solid fa-circle"></i>' +
            '<span class="txt">' + esc(r.titulo) + '</span>' +
          '</div>');
      });
    }

    var visiveis = chips.slice(0, MAX_CHIPS).join('');
    if (chips.length > MAX_CHIPS) {
      visiveis += '<div class="ag-mais">+' + (chips.length - MAX_CHIPS) + '</div>';
    }
    return visiveis;
  }

  /* ---------- painel do dia ---------- */

  function renderDia() {
    var evs = (porData[diaSel] || []).slice();
    var p = diaSel.split('-');
    var dt = new Date(+p[0], +p[1] - 1, +p[2]);
    var titulo = dt.getDate() + ' de ' + MESES[dt.getMonth()] + ' de ' + dt.getFullYear();

    var html = '<h2><i class="fa-regular fa-calendar-check"></i>' + esc(titulo) +
               '<span class="cnt">' + (evs.length ? evs.length + (evs.length > 1 ? ' eventos' : ' evento') : '') + '</span></h2>';

    if (!evs.length) {
      html += '<div class="ag-vazio">Nenhum prazo neste dia.</div>';
      elDia.innerHTML = html;
      return;
    }

    var ordem = ['objetivo', 'kr', 'iniciativa', 'marco', 'inicio_objetivo'];
    evs.sort(function (a, b) { return ordem.indexOf(a.tipo) - ordem.indexOf(b.tipo); });

    evs.forEach(function (e) {
      var r = resolver(e);
      var pessoas = r.pessoas.map(function (pp) {
        var u = A.pessoas[pp.id];
        if (!u) return '';
        var img = u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '';
        return '<span class="ag-pessoa ' + (pp.papel === 'corresponsavel' ? 'co' : '') + '" title="' +
               esc(pp.papel === 'corresponsavel' ? 'Corresponsável' : 'Responsável') + '">' +
               img + esc(u.nome) + '</span>';
      }).join('');

      var extra = '';
      if (e.tipo === 'marco' && e.meta) {
        extra = '<span class="ag-tag">Marco ' + e.meta.num_ordem + '</span>' +
                '<span class="ag-tag">' + (e.meta.apontado ? 'Apontado' : 'Sem apontamento') + '</span>';
      }
      if (e.tipo === 'kr' && e.meta && e.meta.prorrogado) {
        extra = '<span class="ag-tag">Prazo prorrogado</span>';
      }

      html +=
        '<a class="ag-item est-' + e.estado + '" href="' + esc(r.url) + '">' +
          '<div class="marca"><i class="fa-solid ' + TIPOS[e.tipo].icone + '"></i></div>' +
          '<div class="corpo">' +
            '<div class="tit">' + esc(r.titulo) + '</div>' +
            (r.contexto ? '<div class="ctx">' + esc(TIPOS[e.tipo].rotulo) + ' em: ' + esc(r.contexto) + '</div>'
                        : '<div class="ctx">' + esc(TIPOS[e.tipo].rotulo) + '</div>') +
            '<div class="tags">' +
              '<span class="ag-tag estado">' + esc(ESTADOS[e.estado] || e.estado) + '</span>' +
              extra + pessoas +
            '</div>' +
          '</div>' +
        '</a>';
    });

    elDia.innerHTML = html;
  }

  /* ---------- resumo do mês ---------- */

  function renderResumo() {
    var pre = ano + '-' + pad(mes + 1) + '-';
    var c = { total: 0, vencido: 0, proximo: 0, concluido: 0 };
    A.eventos.forEach(function (e) {
      if (!e.data || e.data.indexOf(pre) !== 0) return;
      c.total++;
      if (e.estado === 'vencido') c.vencido++;
      else if (e.estado === 'proximo' || e.estado === 'hoje') c.proximo++;
      else if (e.estado === 'concluido') c.concluido++;
    });
    elResumo.innerHTML =
      kpi('', c.total, 'no mês') +
      kpi('vencido', c.vencido, 'vencidos') +
      kpi('proximo', c.proximo, 'vencendo') +
      kpi('concluido', c.concluido, 'concluídos');
  }

  function kpi(cls, valor, rotulo) {
    return '<div class="ag-kpi ' + cls + '"><div class="v">' + valor + '</div><div class="l">' + rotulo + '</div></div>';
  }

  /* ---------- eventos de UI ---------- */

  elGrid.addEventListener('click', function (ev) {
    var cell = ev.target.closest('.ag-cell');
    if (!cell) return;
    diaSel = cell.getAttribute('data-dia');
    var d = diaSel.split('-');
    // Clicar num dia de outro mês navega para lá, em vez de não fazer nada.
    var y = parseInt(d[0], 10), m = parseInt(d[1], 10) - 1;
    if (y !== ano || m !== mes) { ano = y; mes = m; }
    renderMes();
    renderDia();
    elDia.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  function irPara(delta) {
    mes += delta;
    if (mes < 0) { mes = 11; ano--; }
    else if (mes > 11) { mes = 0; ano++; }
    renderMes();
  }

  document.getElementById('agPrev').addEventListener('click', function () { irPara(-1); });
  document.getElementById('agNext').addEventListener('click', function () { irPara(1); });
  document.getElementById('agHoje').addEventListener('click', function () {
    var p = hoje.split('-');
    ano = parseInt(p[0], 10); mes = parseInt(p[1], 10) - 1; diaSel = hoje;
    renderMes(); renderDia();
  });

  renderMes();
  renderDia();
})();
