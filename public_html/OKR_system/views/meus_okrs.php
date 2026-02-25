<?php
// views/meus_okrs.php — Cascata visual de OKRs

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__.'/../auth/acl.php';

gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}

if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

function fmtData($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '—';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pilar_color(string $pilar): string {
  $key = mb_strtolower(trim($pilar),'UTF-8');
  $key = @iconv('UTF-8','ASCII//TRANSLIT',$key) ?: $key;
  $key = preg_replace('/[^a-z0-9 ]/','', $key);
  $colorMap = [
    'financeiro' => '#f39c12',
    'cliente' => '#27ae60', 'clientes' => '#27ae60',
    'processos' => '#2980b9', 'processos internos' => '#2980b9',
    'aprendizado' => '#8e44ad', 'aprendizado e crescimento' => '#8e44ad',
  ];
  return $colorMap[trim($key)] ?? '#6c757d';
}
function pill_text_color(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
  return ((0.2126*$r + 0.7152*$g + 0.0722*$b) > 160) ? '#111' : '#fff';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Meus OKRs – Cascata</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

    <style>
    /* ═══════════════════════════════════════════════
       CASCATA OKR — Estilos
       ═══════════════════════════════════════════════ */
    .main-wrapper{
      padding:2rem 2rem 2rem 1.5rem;
      margin-right:var(--chat-w);
      transition:margin-right .25s ease;
    }
    @media(max-width:991px){ .main-wrapper{ padding:1rem; } }

    /* ── Scope toggle ── */
    .scope-bar{
      display:flex; gap:8px; margin:12px 0 18px;
    }
    .scope-btn{
      padding:7px 16px; border-radius:10px; font-size:.82rem; font-weight:600;
      border:1px solid var(--border); background:var(--btn); color:var(--muted);
      cursor:pointer; transition:all .2s ease;
    }
    .scope-btn.active{
      background:var(--gold); color:#111; border-color:var(--gold);
      box-shadow:0 4px 14px rgba(246,195,67,.25);
    }
    .scope-btn:hover:not(.active){ border-color:#3a4050; }

    /* ── Loading ── */
    .cascade-loading{
      text-align:center; padding:60px 20px; color:var(--muted); font-size:.9rem;
    }
    .cascade-loading i{ font-size:1.6rem; margin-bottom:10px; display:block; }

    /* ── Empty state ── */
    .cascade-empty{
      text-align:center; padding:60px 20px; color:var(--muted);
    }
    .cascade-empty i{ font-size:2.5rem; margin-bottom:14px; display:block; color:var(--gold); opacity:.5; }

    /* ══════════ TREE NODE (genérico) ══════════ */
    .tree-node{
      position:relative;
      margin-left:0;
    }
    .tree-children{
      margin-left:24px;
      padding-left:20px;
      border-left:2px solid var(--border);
      overflow:hidden;
      max-height:0;
      opacity:0;
      transition: max-height .4s ease, opacity .3s ease;
    }
    .tree-node.open > .tree-children{
      max-height:9999px;
      opacity:1;
    }

    /* ── NODE HEADER (linha clicável) ── */
    .node-header{
      display:flex; align-items:center; gap:10px;
      padding:12px 14px;
      margin:4px 0;
      border-radius:14px;
      cursor:pointer;
      transition:background .2s ease, border-color .2s ease, box-shadow .2s ease;
      border:1px solid var(--border);
      background: linear-gradient(180deg, var(--card), #0e1319);
      box-shadow: var(--shadow);
      position:relative;
    }
    .node-header:hover{
      border-color:#293140;
      transform:translateY(-1px);
      box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }
    .tree-node.open > .node-header{
      border-color:rgba(246,195,67,.25);
      background: linear-gradient(180deg, var(--card), #111720);
      box-shadow: 0 6px 20px rgba(246,195,67,.06);
    }

    /* Chevron de expand */
    .node-chevron{
      width:28px; height:28px; border-radius:8px;
      display:grid; place-items:center;
      background:var(--btn); border:1px solid var(--border);
      color:var(--muted); font-size:.75rem;
      transition:transform .25s ease, border-color .2s ease;
      flex-shrink:0;
    }
    .tree-node.open > .node-header .node-chevron{
      transform:rotate(90deg);
      border-color:var(--gold);
      color:var(--gold);
    }
    /* Nó folha: sem chevron visível */
    .node-chevron.leaf{
      visibility:hidden;
    }

    /* Ícone do tipo do nó */
    .node-icon{
      width:34px; height:34px; border-radius:10px;
      display:grid; place-items:center;
      font-size:.9rem; flex-shrink:0;
    }
    .node-icon.obj{ background:rgba(246,195,67,.14); color:var(--gold); }
    .node-icon.kr{ background:rgba(96,165,250,.14); color:var(--blue); }
    .node-icon.ini{ background:rgba(34,197,94,.14); color:var(--green); }
    .node-icon.orc{ background:rgba(168,85,247,.14); color:#a78bfa; }

    /* Título + meta */
    .node-info{ flex:1; min-width:0; }
    .node-title{
      font-size:.84rem; font-weight:600; color:var(--text);
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      line-height:1.3;
    }
    .node-sub{
      font-size:.73rem; color:var(--muted); margin-top:2px;
      display:flex; flex-wrap:wrap; gap:6px; align-items:center;
    }
    .node-sub .tag{
      display:inline-flex; align-items:center; gap:3px;
    }

    /* ── AVATAR ── */
    .avatar-sm{
      width:26px; height:26px; border-radius:50%;
      object-fit:cover;
      border:2px solid var(--border);
      flex-shrink:0;
    }
    .avatar-initials{
      width:26px; height:26px; border-radius:50%;
      display:grid; place-items:center;
      font-size:.55rem; font-weight:800;
      background:linear-gradient(135deg, #d4a017, #f7dc6f);
      color:#111; flex-shrink:0;
      border:2px solid rgba(246,195,67,.3);
    }
    .avatar-group{
      display:flex; align-items:center;
    }
    .avatar-group > *:not(:first-child){
      margin-left:-8px;
    }
    .avatar-group .avatar-sm,
    .avatar-group .avatar-initials{
      box-shadow:0 0 0 2px var(--card);
    }

    /* ── PILLS (status, pilar) ── */
    .pill-status{
      display:inline-flex; align-items:center; gap:4px;
      padding:3px 8px; border-radius:999px; font-size:.68rem; font-weight:600;
      border:1px solid rgba(255,255,255,.08);
      line-height:1;
    }
    .pill-status.ok{ background:rgba(34,197,94,.12); color:#86efac; border-color:rgba(34,197,94,.3); }
    .pill-status.warn{ background:rgba(246,195,67,.12); color:#fde68a; border-color:rgba(246,195,67,.3); }
    .pill-status.danger{ background:rgba(239,68,68,.12); color:#fca5a5; border-color:rgba(239,68,68,.3); }
    .pill-status.info{ background:rgba(96,165,250,.12); color:#bfdbfe; border-color:rgba(96,165,250,.3); }
    .pill-status.neutral{ background:rgba(255,255,255,.04); color:var(--muted); }

    .pill-pilar{
      display:inline-flex; align-items:center; gap:4px;
      padding:3px 8px; border-radius:999px; font-size:.68rem; font-weight:700;
      line-height:1;
    }

    /* ── Badge contadores ── */
    .node-badges{
      display:flex; gap:6px; align-items:center; flex-shrink:0;
    }
    .count-badge{
      display:inline-flex; align-items:center; gap:4px;
      padding:3px 8px; border-radius:8px; font-size:.68rem; font-weight:700;
      background:var(--btn); border:1px solid var(--border); color:var(--muted);
    }
    .count-badge i{ font-size:.65rem; }

    /* ── ORC (orçamento) node extras ── */
    .orc-bar{
      display:flex; align-items:center; gap:8px; margin-top:4px;
    }
    .orc-track{
      flex:1; height:6px; border-radius:999px; background:#0b0f14; border:1px solid var(--border);
      overflow:hidden; position:relative;
    }
    .orc-fill{
      height:100%; border-radius:999px; transition:width .4s ease;
    }
    .orc-fill.ok{ background:var(--green); }
    .orc-fill.warn{ background:#f59e0b; }
    .orc-fill.danger{ background:var(--red); }
    .orc-pct{
      font-size:.68rem; font-weight:700; color:var(--muted); min-width:36px; text-align:right;
    }

    /* ── Detalhes extras (dentro do nó expandido) ── */
    .node-detail-row{
      display:flex; flex-wrap:wrap; gap:10px; align-items:center;
      padding:6px 14px 10px 72px;
      font-size:.76rem; color:var(--muted);
    }
    .node-detail-row .dl{
      display:flex; align-items:center; gap:4px;
    }
    .node-detail-row strong{ color:var(--text); font-weight:600; }

    /* ── Responsivo ── */
    @media(max-width:768px){
      .tree-children{ margin-left:12px; padding-left:12px; }
      .node-header{ padding:10px 8px; gap:6px; }
      .node-badges{ display:none; }
      .node-detail-row{ padding-left:8px; }
    }

    /* ── Link detalhar ── */
    .node-link{
      display:inline-flex; align-items:center; gap:4px;
      padding:3px 10px; border-radius:8px; font-size:.72rem; font-weight:600;
      color:var(--gold); text-decoration:none;
      border:1px solid rgba(246,195,67,.25);
      transition: all .15s ease; flex-shrink:0;
    }
    .node-link:hover{
      background:rgba(246,195,67,.1);
      border-color:var(--gold);
    }

    /* ── Sócios group (à direita dos nodes obj/kr) ── */
    .socios-group{
      display:flex; align-items:center; gap:4px; flex-shrink:0;
    }
    .socios-group .socios-label{
      font-size:.62rem; color:var(--muted); text-transform:uppercase;
      letter-spacing:.5px; font-weight:700; margin-right:2px;
    }

    /* ── Modo "meus": nós sem participação ficam esmaecidos ── */
    .tree-node.dimmed > .node-header{
      opacity:.40;
      filter:grayscale(.3);
    }
    .tree-node.dimmed > .node-header:hover{
      opacity:.7;
    }
    /* Nó onde o user participa: borda de destaque */
    .tree-node.mine > .node-header{
      border-left:3px solid var(--gold);
    }

    /* ── Animação de entrada ── */
    @keyframes fadeSlideIn {
      from { opacity:0; transform:translateY(8px); }
      to   { opacity:1; transform:translateY(0); }
    }
    .tree-node{ animation: fadeSlideIn .3s ease both; }
    .tree-node:nth-child(2){ animation-delay:.05s; }
    .tree-node:nth-child(3){ animation-delay:.1s; }
    .tree-node:nth-child(4){ animation-delay:.15s; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/../views/partials/header.php'; ?>

        <main id="main-content" class="main-wrapper">
            <h1 style="font-size:1.15rem; display:flex; align-items:center; gap:10px;">
              <i class="fas fa-bullseye" style="color:var(--gold)"></i>
              Meus OKRs
            </h1>

            <!-- Toggle de escopo -->
            <div class="scope-bar">
              <button class="scope-btn active" data-scope="company">
                <i class="fa-solid fa-building"></i> Toda a Empresa
              </button>
              <button class="scope-btn" data-scope="meus">
                <i class="fa-solid fa-user"></i> Meus OKRs
              </button>
            </div>

            <!-- Container da cascata -->
            <div id="cascade-root">
              <div class="cascade-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Carregando cascata...
              </div>
            </div>

            <?php include __DIR__ . '/../views/partials/chat.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function(){
      'use strict';

      const root = document.getElementById('cascade-root');
      let currentScope = 'company';
      let loggedUserId = 0; // preenchido após fetch
      const AVATAR_DEFAULT = '/OKR_system/assets/img/avatars/default_avatar/default.png';

      /* ── helpers ── */
      const h = s => {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
      };

      const fmtDate = d => {
        if (!d || d === '0000-00-00') return '—';
        const [y,m,dd] = d.split('-');
        return `${dd}/${m}/${y}`;
      };

      const fmtMoney = v => {
        return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v||0);
      };

      const statusClass = s => {
        s = (s||'').toLowerCase();
        if (/conclu|finaliz|complet/.test(s)) return 'ok';
        if (/risco|critic|cancel/.test(s)) return 'danger';
        if (/penden|aguar/.test(s)) return 'warn';
        if (/andamento|ativ|progr/.test(s)) return 'info';
        return 'neutral';
      };

      const pilarColors = {
        'financeiro':'#f39c12',
        'cliente':'#27ae60','clientes':'#27ae60',
        'processos':'#2980b9','processos internos':'#2980b9',
        'aprendizado':'#8e44ad','aprendizado e crescimento':'#8e44ad',
      };
      const pilarColor = p => pilarColors[(p||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'')] || '#6c757d';
      const contrastColor = hex => {
        hex = hex.replace('#','');
        if (hex.length===3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        const r=parseInt(hex.substr(0,2),16), g=parseInt(hex.substr(2,2),16), b=parseInt(hex.substr(4,2),16);
        return (0.2126*r+0.7152*g+0.0722*b)>160 ? '#111' : '#fff';
      };

      /* ── Avatar HTML ── */
      function avatarHtml(person, cls='avatar-sm') {
        if (!person) return '';
        const src = person.avatar && person.avatar !== AVATAR_DEFAULT ? person.avatar : null;
        if (src) {
          return `<img class="${cls}" src="${h(src)}" alt="${h(person.nome)}" title="${h(person.nome)}" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">` +
                 `<span class="avatar-initials" style="display:none" title="${h(person.nome)}">${h(person.initials)}</span>`;
        }
        return `<span class="avatar-initials" title="${h(person.nome)}">${h(person.initials)}</span>`;
      }

      function avatarGroupHtml(people) {
        if (!people || !people.length) return '';
        let html = '<span class="avatar-group">';
        const show = people.slice(0, 4);
        show.forEach(p => { html += avatarHtml(p); });
        if (people.length > 4) {
          html += `<span class="avatar-initials" title="${people.length - 4} mais">+${people.length-4}</span>`;
        }
        html += '</span>';
        return html;
      }

      /* ── Build nodes ── */
      function buildOrcNode(orc) {
        const pct = orc.valor > 0 ? Math.min(100, Math.round((orc.total_despesas / orc.valor) * 100)) : 0;
        const barClass = pct > 90 ? 'danger' : (pct > 60 ? 'warn' : 'ok');
        const statusTxt = orc.status_aprovacao || 'pendente';
        return `
          <div class="tree-node">
            <div class="node-header" style="cursor:default">
              <span class="node-chevron leaf"><i class="fa-solid fa-chevron-right"></i></span>
              <span class="node-icon orc"><i class="fa-solid fa-coins"></i></span>
              <div class="node-info">
                <div class="node-title">${h(orc.codigo || 'Parcela')} — ${fmtMoney(orc.valor)}</div>
                <div class="node-sub">
                  <span class="tag"><i class="fa-regular fa-calendar"></i> ${fmtDate(orc.data_desembolso)}</span>
                  <span class="pill-status ${statusClass(statusTxt)}">${h(statusTxt)}</span>
                </div>
                <div class="orc-bar">
                  <div class="orc-track"><div class="orc-fill ${barClass}" style="width:${pct}%"></div></div>
                  <span class="orc-pct">${pct}%</span>
                  <span style="font-size:.68rem;color:var(--muted)">Gasto: ${fmtMoney(orc.total_despesas)}</span>
                </div>
              </div>
            </div>
          </div>`;
      }

      function buildIniNode(ini) {
        const hasOrc = ini.orcamento && ini.orcamento.items && ini.orcamento.items.length > 0;
        const childCount = hasOrc ? ini.orcamento.items.length : 0;
        const isLeaf = childCount === 0;
        const orcAprov = ini.orcamento ? ini.orcamento.aprovado : 0;
        const orcReal = ini.orcamento ? ini.orcamento.realizado : 0;

        let orcsHtml = '';
        if (hasOrc) {
          orcsHtml = ini.orcamento.items.map(buildOrcNode).join('');
        }

        const envolvidos = ini.envolvidos || [];
        const avatars = envolvidos.length > 0
          ? avatarGroupHtml(envolvidos)
          : avatarHtml(ini.responsavel);

        const isMeus = currentScope === 'meus';
        const mine = isMeus && userInIni(ini);
        const dimmed = isMeus && !mine;

        return `
          <div class="tree-node${mine?' mine':''}${dimmed?' dimmed':''}">
            <div class="node-header" onclick="this.parentElement.classList.toggle('open')">
              <span class="node-chevron ${isLeaf?'leaf':''}"><i class="fa-solid fa-chevron-right"></i></span>
              <span class="node-icon ini"><i class="fa-solid fa-list-check"></i></span>
              <div class="node-info">
                <div class="node-title"><span style="color:var(--green);font-weight:700">I${ini.num||''}</span> ${h(ini.descricao)}</div>
                <div class="node-sub">
                  ${avatars}
                  <span class="tag" style="margin-left:2px">${h(envolvidos.length ? envolvidos.map(e=>e.nome).join(', ') : (ini.responsavel?.nome || '—'))}</span>
                  <span class="tag"><i class="fa-regular fa-calendar"></i> ${fmtDate(ini.dt_prazo)}</span>
                  <span class="pill-status ${statusClass(ini.status)}">${h(ini.status || '—')}</span>
                </div>
              </div>
              <div class="node-badges">
                ${orcAprov > 0 ? `<span class="count-badge" title="Orçamento aprovado"><i class="fa-solid fa-coins"></i> ${fmtMoney(orcAprov)}</span>` : ''}
                ${childCount > 0 ? `<span class="count-badge"><i class="fa-solid fa-coins"></i> ${childCount}</span>` : ''}
              </div>
            </div>
            <div class="tree-children">${orcsHtml}</div>
          </div>`;
      }

      /* Checa se o user logado participa neste nó */
      function userInSocios(socios) {
        return (socios||[]).some(s => s.id_user === loggedUserId);
      }
      function userInIni(ini) {
        if (ini.responsavel?.id_user === loggedUserId) return true;
        return (ini.envolvidos||[]).some(e => e.id_user === loggedUserId);
      }
      function userInKr(kr) {
        if (kr.responsavel?.id_user === loggedUserId) return true;
        return (kr.iniciativas||[]).some(userInIni);
      }
      function userInObj(obj) {
        if (obj.dono?.id_user === loggedUserId) return true;
        return (obj.key_results||[]).some(userInKr);
      }

      function sociosHtml(socios) {
        if (!socios || !socios.length) return '';
        return `<div class="socios-group">
          <span class="socios-label">Socios</span>
          ${avatarGroupHtml(socios)}
        </div>`;
      }

      function buildKrNode(kr) {
        const childCount = (kr.iniciativas||[]).length;
        const isLeaf = childCount === 0;
        const inisHtml = (kr.iniciativas||[]).map(buildIniNode).join('');
        const isMeus = currentScope === 'meus';
        const mine = isMeus && userInKr(kr);
        const dimmed = isMeus && !mine;

        return `
          <div class="tree-node${mine?' mine':''}${dimmed?' dimmed':''}">
            <div class="node-header" onclick="this.parentElement.classList.toggle('open')">
              <span class="node-chevron ${isLeaf?'leaf':''}"><i class="fa-solid fa-chevron-right"></i></span>
              <span class="node-icon kr"><i class="fa-solid fa-key"></i></span>
              <div class="node-info">
                <div class="node-title"><span style="color:var(--blue);font-weight:700">KR${kr.num||''}</span> ${h(kr.descricao)}</div>
                <div class="node-sub">
                  ${avatarHtml(kr.responsavel)}
                  <span class="tag" style="margin-left:2px">${h(kr.responsavel?.nome || '—')}</span>
                  <span class="tag"><i class="fa-solid fa-crosshairs"></i> Meta: ${h(kr.meta??'—')} ${h(kr.unidade||'')}</span>
                  <span class="tag"><i class="fa-regular fa-calendar"></i> ${fmtDate(kr.data_fim)}</span>
                  <span class="pill-status ${statusClass(kr.status)}">${h(kr.status || '—')}</span>
                </div>
              </div>
              <div class="node-badges">
                ${sociosHtml(kr.socios)}
                ${childCount > 0 ? `<span class="count-badge" title="${childCount} iniciativas"><i class="fa-solid fa-list-check"></i> ${childCount}</span>` : ''}
              </div>
            </div>
            <div class="tree-children">${inisHtml}</div>
          </div>`;
      }

      function buildObjNode(obj) {
        const childCount = (obj.key_results||[]).length;
        const isLeaf = childCount === 0;
        const krsHtml = (obj.key_results||[]).map(buildKrNode).join('');

        const pilCor = pilarColor(obj.pilar_bsc||'');
        const pilFg  = contrastColor(pilCor);

        const isMeus = currentScope === 'meus';
        const mine = isMeus && userInObj(obj);
        const dimmed = isMeus && !mine;

        return `
          <div class="tree-node${mine?' mine':''}${dimmed?' dimmed':''}">
            <div class="node-header" onclick="this.parentElement.classList.toggle('open')">
              <span class="node-chevron ${isLeaf?'leaf':''}"><i class="fa-solid fa-chevron-right"></i></span>
              <span class="node-icon obj"><i class="fa-solid fa-bullseye"></i></span>
              <div class="node-info">
                <div class="node-title" style="color:var(--gold)">${h(obj.descricao)}</div>
                <div class="node-sub">
                  ${avatarHtml(obj.dono)}
                  <span class="tag" style="margin-left:2px">${h(obj.dono?.nome || '—')}</span>
                  <span class="pill-pilar" style="background:${pilCor};color:${pilFg}">${h(obj.pilar_bsc||'—')}</span>
                  <span class="tag"><i class="fa-regular fa-calendar"></i> ${fmtDate(obj.dt_prazo)}</span>
                  <span class="tag"><i class="fa-solid fa-rotate"></i> ${h((obj.tipo_ciclo||'')+ ' - ' +(obj.ciclo||''))}</span>
                  <span class="pill-status ${statusClass(obj.status)}">${h(obj.status || '—')}</span>
                </div>
              </div>
              <div class="node-badges">
                ${sociosHtml(obj.socios)}
                ${childCount > 0 ? `<span class="count-badge" title="${childCount} Key Results"><i class="fa-solid fa-key"></i> ${childCount}</span>` : ''}
                <a class="node-link" href="/OKR_system/views/detalhe_okr.php?id=${obj.id_objetivo}" onclick="event.stopPropagation()">
                  <i class="fa-regular fa-circle-right"></i> Detalhar
                </a>
              </div>
            </div>
            <div class="tree-children">${krsHtml}</div>
          </div>`;
      }

      /* ── Fetch & render ── */
      async function loadCascade(scope) {
        root.innerHTML = `<div class="cascade-loading"><i class="fa-solid fa-spinner fa-spin"></i>Carregando cascata...</div>`;
        try {
          const resp = await fetch(`/OKR_system/api/cascata_okrs.php?scope=${encodeURIComponent(scope)}`,{
            headers:{'Accept':'application/json'}
          });
          const data = await resp.json();
          if (!data.success) throw new Error(data.error||'Erro');

          if (data.user_id) loggedUserId = data.user_id;

          if (!data.objetivos || data.objetivos.length === 0) {
            root.innerHTML = `
              <div class="cascade-empty">
                <i class="fa-solid fa-bullseye"></i>
                <p>Nenhum objetivo encontrado.</p>
                <a href="/OKR_system/views/novo_objetivo.php" style="color:var(--gold)">
                  <i class="fa-solid fa-plus"></i> Criar novo objetivo
                </a>
              </div>`;
            return;
          }

          root.innerHTML = data.objetivos.map(buildObjNode).join('');
        } catch(e) {
          console.error('Cascata error:', e);
          root.innerHTML = `<div class="cascade-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>Erro ao carregar: ${h(e.message)}</p></div>`;
        }
      }

      /* ── Scope buttons ── */
      document.querySelectorAll('.scope-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.scope-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          currentScope = btn.dataset.scope;
          loadCascade(currentScope);
        });
      });

      /* ── Init ── */
      loadCascade(currentScope);
    })();
    </script>
</body>
</html>
