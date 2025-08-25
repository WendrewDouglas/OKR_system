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
        --card:#12161c;
        --muted:#a6adbb;
        --text:#eaeef6;
        --gold:#f6c343;
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
        font-size: .92rem; /* textos menores */
      }
      .okr-card:hover{ transform: translateY(-2px); border-color:#293140; }

      .okr-head{
        display:flex; align-items:center; justify-content:space-between; gap:10px;
        margin-bottom:4px;
      }
      .okr-title{
        display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.2px;
        line-height:1.15; font-size: .98rem; /* menor */
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
      .okr-body .line strong{ color:var(--text); font-weight:700; }

      /* Chips (Aprovação → Status → Farol) */
      .okr-badges{ margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; }
      .chip{
        display:inline-flex; align-items:center; gap:6px;
        padding:5px 9px; border-radius:999px; font-size:.72rem; font-weight:700;
        border:1px solid var(--border); background:#0e131a; color:var(--muted);
        line-height:1;
      }
      .chip i{ font-size:.78rem; }
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
        margin-top:10px; display:flex; justify-content:flex-end;
      }

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

                          <div class="okr-body" id="okr-body-<?= h($obj['id_objetivo']) ?>">
                            <div class="row">
                              <div class="line">
                                <strong><i class="fa-solid fa-tag me-2" aria-hidden="true"></i>Tipo:</strong>
                                <span><?= h(ucfirst((string)$obj['tipo'])) ?></span>
                              </div>
                              <div class="line">
                                <strong><i class="fa-solid fa-diagram-project me-2" aria-hidden="true"></i>Pilar:</strong>
                                <span><?= h($obj['pilar_bsc']) ?></span>
                              </div>

                              <?php if (!empty($obj['justificativa_ia'])): ?>
                                <div class="line">
                                  <strong><i class="fa-solid fa-robot me-2" aria-hidden="true"></i>Justificativa IA:</strong><br>
                                  <small style="white-space:pre-wrap;"><?= nl2br(h($obj['justificativa_ia'])) ?></small>
                                </div>
                              <?php endif; ?>
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
                            <a href="/OKR_system/views/detalhe_okr.php?id=<?= (int)$obj['id_objetivo'] ?>" class="btn btn-sm btn-outline-primary">
                              Detalhar
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
</body>
</html>
