/* Agenda geral — grade do mês, trilho do dia e filtros em cascata.
 *
 * Consome window.AGENDA (payload normalizado de auth/helpers/agenda_events.php):
 *   { eventos, pessoas, objetivos, krs, iniciativas, hoje, eu }
 * O evento só traz ids; título, contexto e pessoas saem dos catálogos.
 *
 * Todo o volume cabe em memória (pico de 296 eventos numa empresa), então nem
 * navegar entre meses nem filtrar faz round-trip.
 */
(function () {
  'use strict';

  var A = window.AGENDA;
  if (!A) return;

  var TIPOS = {
    objetivo:        { icone: 'fa-bullseye',   rotulo: 'Objetivo' },
    inicio_objetivo: { icone: 'fa-flag',       rotulo: 'Início do objetivo' },
    kr:              { icone: 'fa-crosshairs', rotulo: 'Key Result' },
    iniciativa:      { icone: 'fa-list-check', rotulo: 'Iniciativa' },
    marco:           { icone: 'fa-circle',     rotulo: 'Marco' }
  };
  var ESTADOS = {
    vencido: 'Vencido', hoje: 'Vence hoje', proximo: 'Vence em 7 dias',
    futuro: 'Futuro', concluido: 'Concluído', cancelado: 'Cancelado',
    pausado: 'Pausado', sem_data: 'Sem data'
  };
  var FAROL = { verde: 'No ritmo', amarelo: 'Atenção', vermelho: 'Crítico', cinza: 'Sem leitura' };

  var ORDEM_ESTADO = ['vencido', 'hoje', 'proximo', 'futuro', 'concluido', 'pausado', 'cancelado', 'sem_data'];
  var ORDEM_TIPO   = ['objetivo', 'kr', 'iniciativa', 'marco', 'inicio_objetivo'];

  var MESES = ['janeiro','fevereiro','março','abril','maio','junho',
               'julho','agosto','setembro','outubro','novembro','dezembro'];
  var DOW = ['dom','seg','ter','qua','qui','sex','sáb'];

  var MAX_CHIPS = 3;
  var MAX_MARCOS = 2;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function chave(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function num(v) {
    if (v === null || v === undefined) return '—';
    return window.fmtNum ? window.fmtNum(v) : String(v);
  }

  /** Título, contexto e pessoas saem do catálogo do tipo do evento. */
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

  // resolver() é chamado muitas vezes por render; o payload é imutável, então cacheia.
  var _cache = {};
  function res(ev) {
    var c = _cache[ev.id];
    if (!c) { c = _cache[ev.id] = resolver(ev); }
    return c;
  }

  /* ===================== FILTROS ===================== */

  var DIMS = [
    { k: 'pessoa',     rot: 'Responsável', icone: 'fa-user',        busca: true  },
    { k: 'objetivo',   rot: 'Objetivo',    icone: 'fa-bullseye',    busca: false },
    { k: 'kr',         rot: 'Key Result',  icone: 'fa-crosshairs',  busca: true  },
    { k: 'iniciativa', rot: 'Iniciativa',  icone: 'fa-list-check',  busca: true  },
    { k: 'tipo',       rot: 'Tipo',        icone: 'fa-shapes',      busca: false },
    { k: 'estado',     rot: 'Situação',    icone: 'fa-signal',      busca: false }
  ];

  var filtros = {
    pessoa: [], objetivo: [], kr: [], iniciativa: [], tipo: [], estado: [],
    busca: '', soPrincipal: false
  };

  /** Valores de um evento numa dimensão. Evento sem pessoa cai no balde __sem__. */
  function chavesDim(ev, dim) {
    if (dim === 'pessoa') {
      var ps = res(ev).pessoas;
      if (filtros.soPrincipal) ps = ps.filter(function (p) { return p.papel === 'responsavel'; });
      return ps.length ? ps.map(function (p) { return String(p.id); }) : ['__sem__'];
    }
    if (dim === 'objetivo')   return ev.id_objetivo   ? [String(ev.id_objetivo)] : [];
    if (dim === 'kr')         return ev.id_kr         ? [ev.id_kr] : [];
    if (dim === 'iniciativa') return ev.id_iniciativa ? [ev.id_iniciativa] : [];
    if (dim === 'tipo')       return [ev.tipo];
    if (dim === 'estado')     return [ev.estado];
    return [];
  }

  function passaDim(ev, dim) {
    var sel = filtros[dim];
    if (!sel.length) return true;
    var ks = chavesDim(ev, dim);
    for (var i = 0; i < ks.length; i++) {
      if (sel.indexOf(ks[i]) >= 0) return true;
    }
    return false;
  }

  function passaBusca(ev) {
    if (!filtros.busca) return true;
    var r = res(ev);
    var alvo = (r.titulo + ' ' + (r.contexto || '') + ' ' +
      r.pessoas.map(function (p) { return (A.pessoas[p.id] || {}).nome || ''; }).join(' ')).toLowerCase();
    return alvo.indexOf(filtros.busca) >= 0;
  }

  /**
   * Aplica os filtros. `exceto` ignora uma dimensão — é o que faz as opções de
   * uma dimensão refletirem os filtros das outras (cascata e contadores), sem
   * precisar de regra especial ligando objetivo -> KR -> iniciativa.
   */
  function filtrar(exceto) {
    return A.eventos.filter(function (ev) {
      if (!passaBusca(ev)) return false;
      for (var i = 0; i < DIMS.length; i++) {
        if (DIMS[i].k === exceto) continue;
        if (!passaDim(ev, DIMS[i].k)) return false;
      }
      return true;
    });
  }

  function rotuloOpcao(dim, k) {
    if (dim === 'pessoa') {
      return k === '__sem__' ? 'Sem responsável' : ((A.pessoas[k] || {}).nome || ('Usuário ' + k));
    }
    if (dim === 'objetivo')   return (A.objetivos[k]   || {}).descricao || k;
    if (dim === 'kr')         return (A.krs[k]         || {}).descricao || k;
    if (dim === 'iniciativa') return (A.iniciativas[k] || {}).descricao || k;
    if (dim === 'tipo')       return (TIPOS[k] || {}).rotulo || k;
    if (dim === 'estado')     return ESTADOS[k] || k;
    return k;
  }

  /** Opções de uma dimensão, com contagem, já dentro do recorte das outras. */
  function opcoesDe(dim) {
    var base = filtrar(dim);
    var cont = {};
    base.forEach(function (ev) {
      chavesDim(ev, dim).forEach(function (k) { cont[k] = (cont[k] || 0) + 1; });
    });
    // Mantém visível o que está marcado mesmo com contagem zero, senão o
    // usuário perde o controle de desmarcar.
    filtros[dim].forEach(function (k) { if (!(k in cont)) cont[k] = 0; });

    var lista = Object.keys(cont).map(function (k) {
      return { k: k, n: cont[k], rot: rotuloOpcao(dim, k) };
    });
    if (dim === 'tipo') {
      lista.sort(function (a, b) { return ORDEM_TIPO.indexOf(a.k) - ORDEM_TIPO.indexOf(b.k); });
    } else if (dim === 'estado') {
      lista.sort(function (a, b) { return ORDEM_ESTADO.indexOf(a.k) - ORDEM_ESTADO.indexOf(b.k); });
    } else {
      lista.sort(function (a, b) {
        if (b.n !== a.n) return b.n - a.n;
        return a.rot.localeCompare(b.rot, 'pt-BR');
      });
    }
    return lista;
  }

  function temFiltro() {
    if (filtros.busca || filtros.soPrincipal) return true;
    for (var i = 0; i < DIMS.length; i++) {
      if (filtros[DIMS[i].k].length) return true;
    }
    return false;
  }

  function limparTudo() {
    DIMS.forEach(function (d) { filtros[d.k] = []; });
    filtros.busca = '';
    filtros.soPrincipal = false;
    var b = document.getElementById('agBusca');
    if (b) b.value = '';
    aplicar();
  }

  /* ===================== ESTADO DA TELA ===================== */

  var hoje = A.hoje;
  var pHoje = hoje.split('-');
  var ano = parseInt(pHoje[0], 10);
  var mes = parseInt(pHoje[1], 10) - 1;
  var diaSel = hoje;

  var eventosVis = A.eventos;
  var porData = {};

  var elGrid    = document.getElementById('agGrid');
  var elPeriodo = document.getElementById('agPeriodo');
  var elDia     = document.getElementById('agDia');
  var elResumo  = document.getElementById('agResumo');
  var elFiltros = document.getElementById('agFiltros');
  var elAtivos  = document.getElementById('agAtivos');

  function reindexar() {
    porData = {};
    eventosVis.forEach(function (ev) {
      if (!ev.data) return;
      (porData[ev.data] = porData[ev.data] || []).push(ev);
    });
  }

  function aplicar() {
    eventosVis = filtrar(null);
    reindexar();
    renderFiltros();
    renderAtivos();
    renderMes();
    renderDia();
  }

  /* ===================== BARRA DE FILTROS ===================== */

  var abertoEm = null; // dimensão com painel aberto

  function renderFiltros() {
    var html = '';
    DIMS.forEach(function (d) {
      var sel = filtros[d.k];
      var rot = d.rot;
      if (sel.length === 1) rot = rotuloOpcao(d.k, sel[0]);
      else if (sel.length > 1) rot = d.rot + ' (' + sel.length + ')';
      html +=
        '<div class="ag-fdrop' + (abertoEm === d.k ? ' aberto' : '') + '" data-dim="' + d.k + '">' +
          '<button type="button" class="ag-fbtn' + (sel.length ? ' ativo' : '') + '" data-toggle="' + d.k + '">' +
            '<i class="fa-solid ' + d.icone + '"></i>' +
            '<span class="rot">' + esc(rot) + '</span>' +
            '<i class="fa-solid fa-chevron-down cv"></i>' +
          '</button>' +
          (abertoEm === d.k ? painelHtml(d) : '') +
        '</div>';
    });
    elFiltros.innerHTML = html;
  }

  function painelHtml(d) {
    var ops = opcoesDe(d.k);
    var termo = (painelHtml.termo || '').toLowerCase();
    var vis = termo ? ops.filter(function (o) { return o.rot.toLowerCase().indexOf(termo) >= 0; }) : ops;

    var h = '<div class="ag-fpanel">';
    if (d.busca) {
      h += '<input type="text" class="ag-fbusca" placeholder="Buscar…" value="' + esc(painelHtml.termo || '') + '">';
    }
    if (d.k === 'pessoa') {
      h += '<label class="ag-fopt so-principal">' +
             '<input type="checkbox" ' + (filtros.soPrincipal ? 'checked' : '') + ' data-principal="1">' +
             '<span class="t">Só quando é o responsável principal</span>' +
           '</label><div class="ag-fsep"></div>';
    }
    if (!vis.length) {
      h += '<div class="ag-fvazio">Nada aqui com os filtros atuais.</div>';
    }
    vis.forEach(function (o) {
      var marcado = filtros[d.k].indexOf(o.k) >= 0;
      h += '<label class="ag-fopt' + (o.n === 0 ? ' zero' : '') + '">' +
             '<input type="checkbox" data-opt="' + esc(o.k) + '" ' + (marcado ? 'checked' : '') + '>' +
             '<span class="t">' + esc(o.rot) + '</span>' +
             '<span class="n">' + o.n + '</span>' +
           '</label>';
    });
    if (filtros[d.k].length) {
      h += '<div class="ag-fsep"></div><button type="button" class="ag-flimpa" data-limpa="' + d.k + '">Limpar ' + esc(d.rot.toLowerCase()) + '</button>';
    }
    return h + '</div>';
  }

  function renderAtivos() {
    if (!temFiltro()) { elAtivos.innerHTML = ''; return; }
    var h = '<span class="lbl">' + eventosVis.length + ' de ' + A.eventos.length + ' prazos</span>';
    DIMS.forEach(function (d) {
      filtros[d.k].forEach(function (k) {
        h += '<button type="button" class="ag-chipf" data-rm="' + d.k + '" data-k="' + esc(k) + '">' +
               esc(rotuloOpcao(d.k, k)) + ' <i class="fa-solid fa-xmark"></i></button>';
      });
    });
    if (filtros.soPrincipal) {
      h += '<button type="button" class="ag-chipf" data-rm="__principal__">Só responsável principal <i class="fa-solid fa-xmark"></i></button>';
    }
    if (filtros.busca) {
      h += '<button type="button" class="ag-chipf" data-rm="__busca__">“' + esc(filtros.busca) + '” <i class="fa-solid fa-xmark"></i></button>';
    }
    h += '<button type="button" class="ag-flimpatudo" id="agLimpar">Limpar tudo</button>';
    elAtivos.innerHTML = h;
  }

  /* ===================== GRADE ===================== */

  function mesTitulo(m) { return MESES[m].charAt(0).toUpperCase() + MESES[m].slice(1); }

  function renderMes() {
    elPeriodo.textContent = mesTitulo(mes) + ' de ' + ano;

    var html = '';
    DOW.forEach(function (d) { html += '<div class="ag-dow">' + d + '</div>'; });

    // Sempre 6 semanas: a grade não "pula" de altura ao trocar de mês.
    var primeiro = new Date(ano, mes, 1);
    var inicio = new Date(ano, mes, 1 - primeiro.getDay());

    for (var i = 0; i < 42; i++) {
      var d = new Date(inicio.getFullYear(), inicio.getMonth(), inicio.getDate() + i);
      var k = chave(d.getFullYear(), d.getMonth(), d.getDate());
      var fora = d.getMonth() !== mes;
      var n = (porData[k] || []).length;
      var cls = 'ag-cell' + (fora ? ' fora' : '') +
                (k === hoje ? ' hoje' : '') + (k === diaSel ? ' sel' : '');
      html += '<div class="' + cls + '" data-dia="' + k + '" role="gridcell" tabindex="0"' +
                ' aria-label="' + d.getDate() + ' de ' + MESES[d.getMonth()] +
                (n ? ', ' + n + (n > 1 ? ' prazos' : ' prazo') : ', sem prazos') + '">' +
                '<div class="ag-daynum">' + d.getDate() + '</div>' +
                chipsDoDia(k) +
              '</div>';
    }
    elGrid.innerHTML = html;
    renderResumo();
  }

  function chipsDoDia(k) {
    var evs = porData[k];
    if (!evs || !evs.length) return '';

    var marcos = [], outros = [];
    evs.forEach(function (e) { (e.tipo === 'marco' ? marcos : outros).push(e); });

    var chips = [];
    outros.forEach(function (e) {
      var r = res(e);
      chips.push(
        '<div class="ag-chip est-' + e.estado + ' ' + e.estado + '" title="' + esc(TIPOS[e.tipo].rotulo + ' · ' + r.titulo) + '">' +
          '<i class="fa-solid ' + TIPOS[e.tipo].icone + '"></i>' +
          '<span class="txt">' + esc(r.titulo) + '</span>' +
        '</div>');
    });

    if (marcos.length > MAX_MARCOS) {
      // O pior estado do grupo comanda a cor: um marco vencido no meio do bolo
      // não pode ficar invisível.
      var pior = marcos.reduce(function (acc, e) {
        return ORDEM_ESTADO.indexOf(e.estado) < ORDEM_ESTADO.indexOf(acc) ? e.estado : acc;
      }, 'sem_data');
      chips.push(
        '<div class="ag-chip grupo est-' + pior + '" title="' + marcos.length + ' marcos neste dia">' +
          '<i class="fa-solid fa-circle"></i>' +
          '<span class="n">' + marcos.length + '</span>' +
          '<span class="txt">marcos</span>' +
        '</div>');
    } else {
      marcos.forEach(function (e) {
        var r = res(e);
        chips.push(
          '<div class="ag-chip est-' + e.estado + ' ' + e.estado + '" title="' + esc('Marco · ' + r.titulo) + '">' +
            '<i class="fa-solid fa-circle"></i>' +
            '<span class="txt">' + esc(r.titulo) + '</span>' +
          '</div>');
      });
    }

    var out = chips.slice(0, MAX_CHIPS).join('');
    if (chips.length > MAX_CHIPS) {
      out += '<div class="ag-mais">+' + (chips.length - MAX_CHIPS) + '</div>';
    }
    return out;
  }

  /* ===================== TRILHO ===================== */

  function pessoasHtml(lista) {
    return lista.map(function (pp) {
      var u = A.pessoas[pp.id];
      if (!u) return '';
      var co = pp.papel === 'corresponsavel';
      var img = u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : '';
      return '<span class="ag-pessoa' + (co ? ' co' : '') + '" title="' +
             (co ? 'Corresponsável' : 'Responsável') + '">' + img + esc(u.nome) + '</span>';
    }).join('');
  }

  function itemHtml(e) {
    var r = res(e);
    var kr = (e.tipo === 'kr' || e.tipo === 'marco') ? A.krs[e.id_kr] : null;

    var extra = '';
    if (e.tipo === 'marco' && e.meta) {
      extra += '<span class="ag-tag">Marco ' + e.meta.num_ordem + '</span>';
      extra += '<span class="ag-tag">' + (e.meta.apontado ? 'Apontado' : 'Sem apontamento') + '</span>';
    }
    if (e.tipo === 'kr' && e.meta && e.meta.prorrogado) {
      extra += '<span class="ag-tag">Prazo prorrogado</span>';
    }
    // Farol é atingimento e só aparece aqui: na grade a cor já é urgência.
    if (kr && kr.farol) {
      extra += '<span class="ag-tag ag-farol f-' + esc(kr.farol) + '">' +
               '<span class="bola"></span>' + esc(FAROL[kr.farol] || kr.farol) + '</span>';
    }

    var detalhe = '';
    if (e.tipo === 'marco' && e.meta) {
      detalhe = '<div class="ag-valores">Esperado <b>' + num(e.meta.valor_esperado) + '</b>' +
                (e.meta.valor_real !== null && e.meta.valor_real !== undefined
                  ? ' · Realizado <b>' + num(e.meta.valor_real) + '</b>' : '') +
                (kr && kr.unidade ? ' ' + esc(kr.unidade) : '') + '</div>';
    }
    if (e.tipo === 'kr' && kr && kr.progresso !== null && kr.progresso !== undefined) {
      var p = Math.max(0, Math.min(100, kr.progresso));
      var esp = (kr.esperado === null || kr.esperado === undefined)
        ? null : Math.max(0, Math.min(100, kr.esperado));
      detalhe =
        '<div class="ag-prog f-' + esc(kr.farol || 'cinza') + '">' +
          '<div class="barra"><i style="width:' + p + '%"></i>' +
            (esp !== null ? '<b style="left:' + esp + '%" title="Esperado ' + num(esp) + '%"></b>' : '') +
          '</div>' +
          '<div class="rot">' + num(kr.progresso) + '% concluído' +
            (esp !== null ? ' · esperado ' + num(esp) + '%' : '') + '</div>' +
        '</div>';
    }

    return '<a class="ag-item est-' + e.estado + '" href="' + esc(r.url) + '">' +
             '<div class="marca"><i class="fa-solid ' + TIPOS[e.tipo].icone + '"></i></div>' +
             '<div class="corpo">' +
               '<div class="tit">' + esc(r.titulo) + '</div>' +
               '<div class="ctx">' + esc(TIPOS[e.tipo].rotulo) +
                 (r.contexto ? ' em: ' + esc(r.contexto) : '') + '</div>' +
               '<div class="tags">' +
                 '<span class="ag-tag estado">' + esc(ESTADOS[e.estado] || e.estado) + '</span>' +
                 extra + pessoasHtml(r.pessoas) +
               '</div>' +
               detalhe +
             '</div>' +
           '</a>';
  }

  function renderDia() {
    var evs = (porData[diaSel] || []).slice();
    var p = diaSel.split('-');
    var titulo = (+p[2]) + ' de ' + MESES[+p[1] - 1] + ' de ' + p[0];

    var html = '<h2><i class="fa-regular fa-calendar-check"></i>' + esc(titulo) +
               (evs.length ? '<span class="cnt">' + evs.length + (evs.length > 1 ? ' eventos' : ' evento') + '</span>' : '') +
               '</h2>';

    if (evs.length) {
      evs.sort(function (a, b) { return ORDEM_TIPO.indexOf(a.tipo) - ORDEM_TIPO.indexOf(b.tipo); });
      evs.forEach(function (e) { html += itemHtml(e); });
    } else {
      html += '<div class="ag-vazio">' +
        (temFiltro() ? 'Nenhum prazo neste dia com os filtros atuais.' : 'Nenhum prazo neste dia.') + '</div>';
      // Dia vazio é o caso comum: em vez de painel morto, mostra o que vem
      // a seguir — já dentro do filtro ativo.
      var prox = eventosVis.filter(function (e) {
        return e.data && e.data > diaSel &&
               e.estado !== 'concluido' && e.estado !== 'cancelado' && e.estado !== 'pausado';
      }).slice(0, 5);
      if (prox.length) {
        html += '<div class="ag-prox-tit">Próximos prazos</div>';
        prox.forEach(function (e) { html += itemHtml(e); });
      }
    }
    elDia.innerHTML = html;
  }

  /* ===================== RESUMO ===================== */

  function renderResumo() {
    var pre = ano + '-' + pad(mes + 1) + '-';
    var c = { total: 0, vencido: 0, proximo: 0, concluido: 0 };
    eventosVis.forEach(function (e) {
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
  function kpi(cls, v, rot) {
    return '<div class="ag-kpi ' + cls + '"><div class="v">' + v + '</div><div class="l">' + rot + '</div></div>';
  }

  /* ===================== INTERAÇÃO ===================== */

  function selecionar(dia, focar) {
    diaSel = dia;
    var d = diaSel.split('-');
    var y = parseInt(d[0], 10), m = parseInt(d[1], 10) - 1;
    if (y !== ano || m !== mes) { ano = y; mes = m; }
    renderMes();
    renderDia();
    if (focar) {
      var alvo = elGrid.querySelector('[data-dia="' + diaSel + '"]');
      if (alvo) alvo.focus();
    }
    if (window.matchMedia('(max-width: 1100px)').matches) {
      elDia.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  elGrid.addEventListener('click', function (ev) {
    var cell = ev.target.closest('.ag-cell');
    if (cell) selecionar(cell.getAttribute('data-dia'), false);
  });

  var PASSO = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
  elGrid.addEventListener('keydown', function (ev) {
    var cell = ev.target.closest('.ag-cell');
    if (!cell) return;
    if (ev.key === 'Enter' || ev.key === ' ') {
      ev.preventDefault();
      selecionar(cell.getAttribute('data-dia'), true);
      return;
    }
    var passo = PASSO[ev.key];
    if (passo === undefined) return;
    ev.preventDefault();
    var d = cell.getAttribute('data-dia').split('-');
    var dt = new Date(+d[0], +d[1] - 1, +d[2] + passo);
    selecionar(chave(dt.getFullYear(), dt.getMonth(), dt.getDate()), true);
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
    ano = parseInt(pHoje[0], 10); mes = parseInt(pHoje[1], 10) - 1;
    selecionar(hoje, false);
  });

  /* --- barra de filtros --- */

  elFiltros.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-toggle]');
    if (btn) {
      var dim = btn.getAttribute('data-toggle');
      abertoEm = (abertoEm === dim) ? null : dim;
      painelHtml.termo = '';
      renderFiltros();
      var inp = elFiltros.querySelector('.ag-fbusca');
      if (inp) inp.focus();
      return;
    }
    var limpa = ev.target.closest('[data-limpa]');
    if (limpa) {
      filtros[limpa.getAttribute('data-limpa')] = [];
      aplicar();
      return;
    }
    // clique dentro do painel não deve fechá-lo
    if (ev.target.closest('.ag-fpanel')) ev.stopPropagation();
  });

  elFiltros.addEventListener('change', function (ev) {
    var alvo = ev.target;
    if (alvo.hasAttribute('data-principal')) {
      filtros.soPrincipal = alvo.checked;
      aplicar();
      return;
    }
    if (!alvo.hasAttribute('data-opt')) return;
    var dim = alvo.closest('.ag-fdrop').getAttribute('data-dim');
    var k = alvo.getAttribute('data-opt');
    var i = filtros[dim].indexOf(k);
    if (alvo.checked && i < 0) filtros[dim].push(k);
    else if (!alvo.checked && i >= 0) filtros[dim].splice(i, 1);
    aplicar();
  });

  elFiltros.addEventListener('input', function (ev) {
    if (!ev.target.classList.contains('ag-fbusca')) return;
    painelHtml.termo = ev.target.value;
    var pos = ev.target.selectionStart;
    renderFiltros();
    var inp = elFiltros.querySelector('.ag-fbusca');
    if (inp) { inp.focus(); inp.setSelectionRange(pos, pos); }
  });

  document.addEventListener('click', function (ev) {
    if (abertoEm && !ev.target.closest('.ag-fdrop')) {
      abertoEm = null;
      renderFiltros();
    }
  });

  elAtivos.addEventListener('click', function (ev) {
    var rm = ev.target.closest('[data-rm]');
    if (rm) {
      var dim = rm.getAttribute('data-rm');
      if (dim === '__busca__') { filtros.busca = ''; document.getElementById('agBusca').value = ''; }
      else if (dim === '__principal__') { filtros.soPrincipal = false; }
      else {
        var k = rm.getAttribute('data-k');
        var i = filtros[dim].indexOf(k);
        if (i >= 0) filtros[dim].splice(i, 1);
      }
      aplicar();
      return;
    }
    if (ev.target.id === 'agLimpar') limparTudo();
  });

  var tBusca = null;
  document.getElementById('agBusca').addEventListener('input', function (ev) {
    var v = ev.target.value.trim().toLowerCase();
    clearTimeout(tBusca);
    tBusca = setTimeout(function () { filtros.busca = v; aplicar(); }, 180);
  });

  /* --- presets --- */

  document.getElementById('agPendencias').addEventListener('click', function () {
    var alvo = ['vencido', 'hoje', 'proximo'];
    var ativo = filtros.estado.length === alvo.length &&
                alvo.every(function (k) { return filtros.estado.indexOf(k) >= 0; });
    filtros.estado = ativo ? [] : alvo.slice();
    aplicar();
  });

  var btnMeus = document.getElementById('agMeus');
  if (btnMeus) {
    btnMeus.addEventListener('click', function () {
      var eu = String(A.eu);
      var ativo = filtros.pessoa.length === 1 && filtros.pessoa[0] === eu;
      filtros.pessoa = ativo ? [] : [eu];
      aplicar();
    });
  }

  aplicar();
})();
