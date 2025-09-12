<?php
// views/meus_okrs.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}


/* ============ INJETAR O TEMA (uma vez por página) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  // Se quiser forçar recarregar em testes, acrescente ?nocache=1
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}


try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare("SELECT * FROM objetivos WHERE dono = :id ORDER BY dt_prazo ASC");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $objetivos = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro ao conectar: " . $e->getMessage());
}

function fmtData($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '—';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Resolve farol de confiança a partir de possíveis nomes de campo
function resolveFarol(array $row): string {
    foreach (['farol', 'farol_conf', 'farol_confianca', 'farol_objetivo'] as $k) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
            return strtolower((string)$row[$k]);
        }
    }
    return '-';
}

function slug($s){
  $s = mb_strtolower(trim((string)$s),'UTF-8');
  $s = @iconv('UTF-8','ASCII//TRANSLIT',$s) ?: $s;
  $s = preg_replace('/[^a-z0-9]+/',' ', $s);
  return trim(preg_replace('/\s+/',' ', $s));
}
/** Cor de texto com bom contraste sobre um fundo colorido */
function pill_text_color(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
  $lum = (0.2126*$r + 0.7152*$g + 0.0722*$b);
  return ($lum > 160) ? '#111' : '#fff';
}
/** Cor padrão do pilar (mesmo mapa da página Mapa Estratégico) */
function pilar_color(string $pilar): string {
  $key = slug($pilar);
  $colorMap = [
    'financeiro' => '#f39c12',
    'cliente' => '#27ae60', 'clientes' => '#27ae60',
    'processos' => '#2980b9', 'processos internos' => '#2980b9',
    'aprendizado' => '#8e44ad', 'aprendizado e crescimento' => '#8e44ad',
  ];
  return $colorMap[$key] ?? '#6c757d';
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Meus OKRs – OKR System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSS globais -->
    <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          crossorigin="anonymous"/>

    <style>
      /* ========= Paleta (padrão do dashboard) ========= */
      :root{
        --bg-soft:#171b21;
        --card: var(--bg1, #222222);
        --muted:#a6adbb;
        --text:#eaeef6;
        --gold:var(--bg2, #F1C40F);
        --green:#22c55e;
        --blue:#60a5fa;
        --red:#ef4444;
        --border:#222733;
        --shadow:0 10px 30px rgba(0,0,0,.20);
      }

      .main-wrapper{ padding:2rem 2rem 2rem 1.5rem; }
      @media (max-width: 991px){ .main-wrapper{ padding:1rem; } }

      .grid-okrs{
        display:grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap:16px;
      }

      /* =================== CARD OKR =================== */
      .okr-card{
        background: linear-gradient(180deg, var(--card), #0e1319);
        border:1px solid var(--border);
        border-radius:16px;
        padding:14px 14px 10px;
        box-shadow: var(--shadow);
        color: var(--text);
        position:relative;
        overflow:hidden;
        transition: transform .2s ease, border-color .2s ease;
        font-size: .80rem; /* textos menores */
        display:flex;             /* NOVO */
        flex-direction:column;    /* NOVO */
        font-weight:400;
      }
      .okr-card:hover{ transform: translateY(-2px); border-color:#293140; }

      .okr-head{
        display:flex; align-items:center; justify-content:space-between; gap:10px;
        margin-bottom:4px;
      }
      .okr-title{
        display:flex; align-items:center; gap:10px; font-weight:400; letter-spacing:.2px;
        line-height:1.15; font-size: .80rem; /* menor */ color: var(--gold)
      }
      /* Ícone sem bordas/preenchimentos */
      .okr-title i{
        color: var(--gold);
        font-size: 1.05rem;
      }

      .okr-expand{
        background:#0b0f14;
        border:1px solid var(--border);
        color:var(--muted);
        width:34px; height:34px; border-radius:10px;
        display:grid; place-items:center; cursor:pointer;
        transition: border-color .2s ease, background .2s ease;
        font-size: .86rem;
      }
      .okr-expand:hover{ border-color:#304054; }
      .okr-expand .chev{ transition: transform .2s ease; font-size: .9rem; }
      .okr-card.open .okr-expand .chev{ transform: rotate(180deg); }

      .okr-meta{
        display:flex; flex-wrap:wrap; gap:10px; margin: 6px 0 0;
        font-size:.82rem; color:var(--muted);
      }
      .okr-meta .item{ display:flex; align-items:center; gap:6px; }
      .okr-meta .item i{ font-size:.95rem; color:#c7d2fe; }

      .okr-body{
        max-height:0; opacity:0; overflow:hidden;
        transition: max-height .25s ease, opacity .2s ease, margin-top .2s ease;
      }
      .okr-card.open .okr-body{
        max-height:600px; opacity:1; margin-top:8px;
      }
      .okr-body .row{ display:grid; grid-template-columns: 1fr; gap:6px; }
      .okr-body .line{
        background:#0e131a; border:1px solid var(--border);
        border-radius:12px; padding:8px 10px; color:var(--muted);
        font-size:.86rem; /* menor */
      }
      .okr-body .line strong{ color:var(--text); font-weight:400; }

      /* Chips (Aprovação → Status → Farol) */
      .okr-badges{ margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; }
      .chip{
        display:inline-flex; align-items:center; gap:6px;
        padding:5px 9px; border-radius:999px; font-size:.70rem; font-weight:400;
        border:1px solid var(--border); background:#0e131a; color:var(--muted);
        line-height:1;
      }
      .chip i{ font-size:.70rem; }
      .chip .chip-label{ opacity:.85; }
      .chip .chip-sep{ opacity:.5; }
      .chip .chip-val{ font-weight:800; color:var(--text); }

      .chip.ok{ background:rgba(34,197,94,.12); color:#dcfce7; border-color:rgba(34,197,94,.35); }
      .chip.warn{ background:rgba(246,195,67,.12); color:#fde68a; border-color:rgba(246,195,67,.35); }
      .chip.danger{ background:rgba(239,68,68,.12); color:#fecaca; border-color:rgba(239,68,68,.35); }
      .chip.info{ background:rgba(96,165,250,.12); color:#dbeafe; border-color:rgba(96,165,250,.35); }
      .chip.neutral{ background:#0e131a; color:var(--muted); }

      /* Dot de farol */
      .dot{ width:8px; height:8px; border-radius:50%; display:inline-block; }
      .dot.green{ background: var(--green); }
      .dot.yellow{ background: var(--gold); }
      .dot.red{ background: var(--red); }
      .dot.gray{ background: #6b7280; }

      .okr-footer{
        margin-top:auto; display:flex; justify-content:center;
      }

      .btn-detail{
        display:inline-flex; align-items:center; gap:8px;
        padding:10px 16px; border-radius:12px;
        background: var(--gold);           /* dourado */
        color:#111;                        /* contraste */
        font-weight:600; text-decoration:none;
        border:1px solid rgba(246,195,67,.9);
        box-shadow:0 6px 20px rgba(246,195,67,.22);
        transition:transform .15s ease, filter .15s ease, box-shadow .15s ease;
      }
      .btn-detail:hover{
        filter:brightness(.96);
        transform:translateY(-1px);
        box-shadow:0 10px 28px rgba(246,195,67,.28);
      }
      .btn-detail i{ font-size:.80rem; }

      .empty-message {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 2rem;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      }
      .empty-message p { font-size:1.05rem; color:#444; margin-bottom:1.2rem; }
      .btn-novo{ display:inline-block; padding:.55rem 1.1rem; font-size:.98rem; border-radius:30px; }

      /* ===== Barra de progresso do objetivo ===== */
      .okr-prog{ margin-top:8px; }
      .okr-prog .track{
        position:relative; height:10px; border-radius:999px;
        background:#0b0f14; border:1px solid var(--border); overflow:hidden;
      }
      .okr-prog .fill{
        position:absolute; top:0; left:0; height:100%; width:0%;
        transition:width .35s ease;
      }
      .okr-prog .fill.ok{ background: var(--green); }
      .okr-prog .fill.bad{ background: var(--red); }

      /* marcador do esperado */
      .okr-prog .expect{
        position:absolute; top:-3px; bottom:-3px; width:2px;
        background: var(--blue); opacity:.95;
      }

      .okr-prog .caption{
        display:flex; justify-content:space-between;
        color:var(--muted); font-size:.75rem; margin-top:6px;
      }

      .pill-pilar{
        display:inline-flex; align-items:center; gap:6px;
        padding:5px 9px; border-radius:999px; font-size:.78rem; font-weight:600;
        border:1px solid transparent; line-height:1;
      }


    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/../views/partials/header.php'; ?>

        <main id="main-content" class="main-wrapper">
            <h1 style="font-size:1.15rem"><i class="fas fa-bullseye me-2"></i>Meus OKRs</h1>

            <?php if (empty($objetivos)): ?>
                <div class="empty-message mt-4">
                    <p>🎯 Você ainda não possui objetivos cadastrados como responsável.</p>
                    <div>
                        <a href="/OKR_system/novo_objetivo" class="btn btn-primary btn-novo">
                            <i class="fas fa-plus me-2"></i>Criar novo objetivo
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid-okrs mt-4">
                    <?php foreach ($objetivos as $obj): ?>
                        <?php
                          // Aprovação
                          $aprov = strtolower((string)($obj['status_aprovacao'] ?? ''));
                          $aprovClass = ($aprov === 'pendente') ? 'warn' : (($aprov === 'reprovado') ? 'danger' : 'ok');
                          $aprovTxt = $aprov ? ucfirst($aprov) : '—';

                          // Status
                          $status = strtolower((string)($obj['status'] ?? ''));
                          $statusClass =
                              ($status === 'em risco') ? 'danger' :
                              (($status === 'concluído' || $status === 'concluido' || $status === 'finalizado' || $status === 'completo') ? 'ok' :
                              'info');
                          $statusTxt = $status ? ucfirst($status) : '—';

                          // Farol de confiança
                          $farol = resolveFarol($obj); // 'verde', 'amarelo', 'vermelho' ou '-'
                          $farolNorm = in_array($farol, ['verde','amarelo','vermelho']) ? $farol : 'indefinido';
                          $farolClass =
                              ($farolNorm === 'verde') ? 'ok' :
                              (($farolNorm === 'amarelo') ? 'warn' :
                              (($farolNorm === 'vermelho') ? 'danger' : 'neutral'));
                          $farolTxt =
                              ($farolNorm === 'verde') ? 'Verde' :
                              (($farolNorm === 'amarelo') ? 'Amarelo' :
                              (($farolNorm === 'vermelho') ? 'Vermelho' : 'Indefinido'));
                          $farolDotClass =
                              ($farolNorm === 'verde') ? 'green' :
                              (($farolNorm === 'amarelo') ? 'yellow' :
                              (($farolNorm === 'vermelho') ? 'red' : 'gray'));
                        ?>
                        <div class="okr-card" data-id="<?= h($obj['id_objetivo']) ?>">
                          <div class="okr-head">
                            <div class="okr-title">
                              <i class="fa-solid fa-bullseye" aria-hidden="true"></i>
                              <span><?= h($obj['descricao']) ?></span>
                            </div>
                            <button type="button" class="okr-expand" aria-label="Expandir">
                              <i class="fa-solid fa-chevron-down chev" aria-hidden="true"></i>
                            </button>
                          </div>

                          <div class="okr-meta">
                            <div class="item" title="Prazo">
                              <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                              <span>Prazo: <strong><?= fmtData($obj['dt_prazo']) ?></strong></span>
                            </div>
                            <div class="item" title="Ciclo">
                              <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                              <span>Ciclo: <strong><?= h($obj['tipo_ciclo']) . ' - ' . h($obj['ciclo']) ?></strong></span>
                            </div>
                          </div>

                          <div class="okr-prog" id="prog-<?= (int)$obj['id_objetivo'] ?>" data-id="<?= (int)$obj['id_objetivo'] ?>" aria-label="Progresso do objetivo">
                            <div class="track">
                              <div class="fill"></div>
                              <div class="expect" style="left:0%"></div>
                            </div>
                            <div class="caption">
                              <span>Atual: <strong class="lbl-atual">—</strong></span>
                              <span>Esperado: <strong class="lbl-esper">—</strong></span>
                            </div>
                          </div>

                          <div class="okr-body" id="okr-body-<?= h($obj['id_objetivo']) ?>">
                            <div class="row">
                              <div class="line">
                                <strong><i class="fa-solid fa-tag me-2" aria-hidden="true"></i>Tipo:</strong>
                                <span><?= h(ucfirst((string)$obj['tipo'])) ?></span>
                              </div>
                              <?php
                                $pilCor = pilar_color((string)$obj['pilar_bsc']);
                                $pilFg  = pill_text_color($pilCor);
                              ?>
                              <div class="line">
                                <strong><i class="fa-solid fa-diagram-project me-2" aria-hidden="true"></i>Pilar:</strong>
                                <span
                                  class="pill-pilar"
                                  style="background: <?= h($pilCor) ?>; color: <?= h($pilFg) ?>; border-color: <?= h($pilCor) ?>;"
                                  title="Pilar estratégico"
                                >
                                  <?= h($obj['pilar_bsc']) ?>
                                </span>
                              </div>
                            </div>
                          </div>

                          <!-- Chips na ordem: Aprovação → Status → Farol -->
                          <div class="okr-badges" role="group" aria-label="Indicadores do objetivo">
                            <span class="chip <?= $aprovClass ?>" aria-label="Aprovação: <?= h($aprovTxt) ?>">
                              <i class="fa-regular fa-hourglass-half" aria-hidden="true"></i>
                              <span class="chip-label">Aprovação</span>
                              <span class="chip-sep">·</span>
                              <span class="chip-val"><?= h($aprovTxt) ?></span>
                            </span>

                            <span class="chip <?= $statusClass ?>" aria-label="Status: <?= h($statusTxt) ?>">
                              <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                              <span class="chip-label">Status</span>
                              <span class="chip-sep">·</span>
                              <span class="chip-val"><?= h($statusTxt) ?></span>
                            </span>

                            <span class="chip <?= $farolClass ?>" aria-label="Farol de confiança: <?= h($farolTxt) ?>">
                              <span class="dot <?= $farolDotClass ?>" aria-hidden="true"></span>
                              <span class="chip-label">Farol</span>
                              <span class="chip-sep">·</span>
                              <span class="chip-val"><?= h($farolTxt) ?></span>
                            </span>
                          </div>

                          <div class="okr-footer">
                            <a href="/OKR_system/views/detalhe_okr.php?id=<?= (int)$obj['id_objetivo'] ?>" class="btn-detail" role="button">
                              <i class="fa-regular fa-circle-right" aria-hidden="true"></i> Detalhar
                            </a>
                          </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Chat (mantido padrão) -->
            <?php include __DIR__ . '/../views/partials/chat.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Toggle de expansão
      document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.okr-expand');
        if (!btn) return;
        const card = btn.closest('.okr-card');
        card.classList.toggle('open');
      });
    </script>
    <script>
      // calcula média de % atual/esperado dos KRs (clamp 0..100)
      function computeObjectiveProgress(krs){
        let sA=0, nA=0, sE=0, nE=0;
        for (const kr of (krs||[])) {
          const pa = kr?.progress?.pct_atual;
          const pe = kr?.progress?.pct_esperado;
          if (Number.isFinite(pa)) { sA += Math.max(0, Math.min(100, pa)); nA++; }
          if (Number.isFinite(pe)) { sE += Math.max(0, Math.min(100, pe)); nE++; }
        }
        const pctA = nA ? Math.round(sA / nA) : null;
        const pctE = nE ? Math.round(sE / nE) : null;
        const ok   = (pctA!==null && pctE!==null) ? (pctA >= pctE) : null;
        return { pctA, pctE, ok };
      }

      function paintProgress(el, prog){
        const fill = el.querySelector('.fill');
        const exp  = el.querySelector('.expect');
        const la   = el.querySelector('.lbl-atual');
        const le   = el.querySelector('.lbl-esper');

        const a = prog.pctA==null ? 0 : Math.max(0, Math.min(100, prog.pctA));
        const e = prog.pctE==null ? 0 : Math.max(0, Math.min(100, prog.pctE));

        fill.style.width = a + '%';
        exp.style.left   = e + '%';

        fill.classList.remove('ok','bad');
        if (prog.ok === true) fill.classList.add('ok');
        else if (prog.ok === false) fill.classList.add('bad');

        la.textContent = (prog.pctA==null ? '—' : (prog.pctA + '%'));
        le.textContent = (prog.pctE==null ? '—' : (prog.pctE + '%'));
      }

      async function loadCardProgress(card){
        const objId = card.dataset.id;
        const progEl = card.querySelector('.okr-prog');
        if (!objId || !progEl) return;

        try{
          const url  = `/OKR_system/views/detalhe_okr.php?ajax=load_krs&id_objetivo=${encodeURIComponent(objId)}`;
          const resp = await fetch(url, { headers:{'Accept':'application/json'} });
          const data = await resp.json();
          if (data?.success && Array.isArray(data.krs)) {
            const prog = computeObjectiveProgress(data.krs);
            paintProgress(progEl, prog);
          }
        } catch(e){
          console.error('Falha ao carregar progresso do objetivo', objId, e);
        }
      }

      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.okr-card').forEach(loadCardProgress);
      });
    </script>
</body>
</html>
