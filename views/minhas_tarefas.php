<?php
// views/minhas_tarefas.php — Minhas Tarefas (lista unificada de obrigações)
session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// Conexão
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

$currentUserId = (int)$_SESSION['user_id'];

// Dados do usuário logado
$stMe = $pdo->prepare("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.id_company,
         COALESCE(r.role_key,'colaborador') AS role_key
  FROM usuarios u
  LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
  LEFT JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
  WHERE u.id_user = :uid
  LIMIT 1
");
$stMe->execute([':uid' => $currentUserId]);
$me = $stMe->fetch();
if (!$me || empty($me['id_company'])) {
  header('Location: /OKR_system/organizacao');
  exit;
}
$myCompanyId = (int)$me['id_company'];
$myRole      = $me['role_key'];
$isAdmin     = in_array($myRole, ['admin_master','user_admin'], true);

// Determinar usuário-alvo
$targetUserId = $currentUserId;
if ($isAdmin && isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id'])) {
  $candidateId = (int)$_GET['user_id'];
  // Validar que pertence à mesma company (ou admin_master vê tudo)
  if ($myRole === 'admin_master') {
    $targetUserId = $candidateId;
  } else {
    $stCheck = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :uid LIMIT 1");
    $stCheck->execute([':uid' => $candidateId]);
    $cRow = $stCheck->fetch();
    if ($cRow && (int)$cRow['id_company'] === $myCompanyId) {
      $targetUserId = $candidateId;
    }
  }
}

// Dados do usuário-alvo
$stTarget = $pdo->prepare("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.id_company,
         COALESCE(r.role_key,'colaborador') AS role_key
  FROM usuarios u
  LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
  LEFT JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
  WHERE u.id_user = :uid
  LIMIT 1
");
$stTarget->execute([':uid' => $targetUserId]);
$target = $stTarget->fetch();
$targetName = trim(($target['primeiro_nome'] ?? '') . ' ' . ($target['ultimo_nome'] ?? ''));
$targetRole = $target['role_key'] ?? 'colaborador';
$targetCompanyId = (int)($target['id_company'] ?? $myCompanyId);

// Lista de usuários (para dropdown admin)
$userList = [];
if ($isAdmin) {
  if ($myRole === 'admin_master') {
    $stUsers = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios ORDER BY primeiro_nome, ultimo_nome");
    $stUsers->execute();
  } else {
    $stUsers = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios WHERE id_company = :cid ORDER BY primeiro_nome, ultimo_nome");
    $stUsers->execute([':cid' => $myCompanyId]);
  }
  $userList = $stUsers->fetchAll();
}

// ===================== QUERIES DE TAREFAS =====================
$tarefas = [];

// 1) Objetivos onde o usuário é dono
$stObj = $pdo->prepare("
  SELECT
    'objetivo' AS tipo,
    o.id_objetivo AS id_item,
    o.descricao AS descricao,
    NULL AS contexto_kr,
    NULL AS contexto_obj,
    o.dt_prazo AS dt_prazo,
    COALESCE(o.status,'') AS status_raw,
    o.id_objetivo AS nav_id
  FROM objetivos o
  WHERE o.dono = :uid AND o.id_company = :cid
");
$stObj->execute([':uid' => $targetUserId, ':cid' => $targetCompanyId]);
$tarefas = array_merge($tarefas, $stObj->fetchAll());

// 2) Key Results onde o usuário é responsável
$stKR = $pdo->prepare("
  SELECT
    'kr' AS tipo,
    kr.id_kr AS id_item,
    kr.descricao AS descricao,
    NULL AS contexto_kr,
    o.descricao AS contexto_obj,
    COALESCE(kr.dt_novo_prazo, kr.data_fim) AS dt_prazo,
    COALESCE(kr.status,'') AS status_raw,
    o.id_objetivo AS nav_id
  FROM key_results kr
  INNER JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
  WHERE kr.responsavel = :uid
");
$stKR->execute([':uid' => $targetUserId]);
$tarefas = array_merge($tarefas, $stKR->fetchAll());

// 3) Iniciativas via junction (iniciativas_envolvidos)
$stIni = $pdo->prepare("
  SELECT
    'iniciativa' AS tipo,
    i.id_iniciativa AS id_item,
    i.descricao AS descricao,
    kr.descricao AS contexto_kr,
    o.descricao AS contexto_obj,
    i.dt_prazo AS dt_prazo,
    COALESCE(i.status,'') AS status_raw,
    o.id_objetivo AS nav_id
  FROM iniciativas_envolvidos ie
  INNER JOIN iniciativas i ON i.id_iniciativa = ie.id_iniciativa
  INNER JOIN key_results kr ON kr.id_kr = i.id_kr
  INNER JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
  WHERE ie.id_user = :uid
");
$stIni->execute([':uid' => $targetUserId]);
$tarefas = array_merge($tarefas, $stIni->fetchAll());

// ===================== CLASSIFICAÇÃO =====================
$today = date('Y-m-d');
foreach ($tarefas as &$t) {
  $st = mb_strtolower($t['status_raw']);
  $prazo = $t['dt_prazo'];

  if (strpos($st, 'conclu') !== false) {
    $t['categoria'] = 'concluido';
  } elseif ($prazo && $prazo < $today) {
    $t['categoria'] = 'atrasado';
  } else {
    $t['categoria'] = 'no_prazo';
  }

  // Dias de atraso / restantes
  if ($prazo) {
    $diff = (int)((strtotime($today) - strtotime($prazo)) / 86400);
    $t['dias_diff'] = $diff; // positivo = atrasado, negativo = restantes
  } else {
    $t['dias_diff'] = null;
  }

  // Status label
  if (strpos($st, 'conclu') !== false)      $t['status_label'] = 'Concluído';
  elseif (strpos($st, 'cancel') !== false)   $t['status_label'] = 'Cancelado';
  elseif (strpos($st, 'andamento') !== false) $t['status_label'] = 'Em Andamento';
  elseif (strpos($st, 'inici') !== false)    $t['status_label'] = 'Não Iniciado';
  elseif ($st === '')                         $t['status_label'] = 'Sem Status';
  else                                        $t['status_label'] = ucfirst($st);
}
unset($t);

// Ordenar: atrasados primeiro (mais atrasado no topo), depois no prazo (deadline ASC), depois concluídos
usort($tarefas, function($a, $b) {
  $order = ['atrasado' => 0, 'no_prazo' => 1, 'concluido' => 2];
  $ca = $order[$a['categoria']] ?? 1;
  $cb = $order[$b['categoria']] ?? 1;
  if ($ca !== $cb) return $ca - $cb;

  if ($a['categoria'] === 'atrasado') {
    return ($b['dias_diff'] ?? 0) - ($a['dias_diff'] ?? 0); // mais atrasado primeiro
  }
  // no prazo: deadline mais próximo primeiro
  $pa = $a['dt_prazo'] ?? '9999-12-31';
  $pb = $b['dt_prazo'] ?? '9999-12-31';
  return strcmp($pa, $pb);
});

// KPIs
$total     = count($tarefas);
$atrasados = count(array_filter($tarefas, fn($t) => $t['categoria'] === 'atrasado'));
$noPrazo   = count(array_filter($tarefas, fn($t) => $t['categoria'] === 'no_prazo'));
$concluidos = count(array_filter($tarefas, fn($t) => $t['categoria'] === 'concluido'));
$pctConclusao = $total > 0 ? round(($concluidos / $total) * 100) : 0;

// Iniciais para avatar
$initials = mb_strtoupper(mb_substr($target['primeiro_nome'] ?? 'U', 0, 1) . mb_substr($target['ultimo_nome'] ?? '', 0, 1));
if (mb_strlen($initials) < 2) $initials = mb_strtoupper(mb_substr($target['primeiro_nome'] ?? 'U', 0, 2));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Minhas Tarefas – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet"
        href="/OKR_system/assets/company_theme.php?cid=<?= (int)($_SESSION['company_id'] ?? 0) ?>">

  <style>
    /* ========== PAGE-SPECIFIC STYLES ========== */
    .mt-page { padding: 1.5rem 2rem; max-width: 1200px; margin: 0 auto; }

    /* --- Profile Header --- */
    .mt-profile {
      display: flex; align-items: center; gap: 1.25rem;
      margin-bottom: 1.5rem; flex-wrap: wrap;
    }
    .mt-avatar {
      width: 64px; height: 64px; border-radius: 50%;
      background: linear-gradient(135deg, #d4a017, #f7dc6f);
      border: 3px solid #d4a017;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.4rem; font-weight: 700; color: #1a1a2e;
      flex-shrink: 0;
    }
    .mt-profile-info h1 { margin: 0; font-size: 1.3rem; color: var(--text, #eee); }
    .mt-role-badge {
      display: inline-block; padding: 2px 10px; border-radius: 12px;
      font-size: .7rem; font-weight: 600; text-transform: uppercase;
      margin-top: 4px;
    }
    .mt-role-badge.admin { background: #d4a017; color: #1a1a2e; }
    .mt-role-badge.colab { background: #3498db; color: #fff; }

    /* --- User Selector (admin) --- */
    .mt-user-select {
      margin-left: auto; display: flex; align-items: center; gap: .5rem;
    }
    .mt-user-select label { font-size: .8rem; color: var(--text-muted, #aaa); white-space: nowrap; }
    .mt-user-select select {
      background: var(--card, #1e1e2f); color: var(--text, #eee);
      border: 1px solid rgba(255,255,255,.12); border-radius: 8px;
      padding: 6px 12px; font-size: .82rem; min-width: 200px;
    }

    /* --- Stat Cards --- */
    .mt-stats {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .mt-stat {
      background: var(--card, #1e1e2f); border-radius: 12px;
      padding: 1rem 1.25rem; text-align: center;
      border: 1px solid rgba(255,255,255,.06);
    }
    .mt-stat .stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1.1; }
    .mt-stat .stat-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted, #aaa); margin-top: 4px; }
    .mt-stat.total .stat-value { color: var(--bg2, #f1c40f); }
    .mt-stat.atrasado .stat-value { color: #e74c3c; }
    .mt-stat.no-prazo .stat-value { color: #2ecc71; }
    .mt-stat.conclusao .stat-value { color: #d4a017; }

    /* --- Filter Pills --- */
    .mt-filters {
      display: flex; gap: .5rem; margin-bottom: 1.25rem; flex-wrap: wrap;
    }
    .mt-pill {
      padding: 6px 16px; border-radius: 20px; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: 1px solid rgba(255,255,255,.12);
      background: var(--card, #1e1e2f); color: var(--text, #eee);
      transition: all .2s;
    }
    .mt-pill:hover { border-color: var(--bg2, #f1c40f); }
    .mt-pill.active { background: var(--bg2, #f1c40f); color: var(--bg2-contrast, #111); border-color: transparent; }
    .mt-pill .pill-count {
      display: inline-block; background: rgba(255,255,255,.15); border-radius: 10px;
      padding: 1px 7px; font-size: .68rem; margin-left: 4px;
    }
    .mt-pill.active .pill-count { background: rgba(0,0,0,.2); }

    /* --- Task List --- */
    .mt-list { display: flex; flex-direction: column; gap: .6rem; }

    .mt-row {
      display: grid; grid-template-columns: 110px 1fr 150px 130px;
      align-items: center; gap: 1rem;
      background: var(--card, #1e1e2f); border-radius: 10px;
      padding: .85rem 1rem; border-left: 4px solid transparent;
      border: 1px solid rgba(255,255,255,.06);
      cursor: pointer; transition: all .2s;
      text-decoration: none; color: inherit;
    }
    .mt-row:hover { border-color: var(--bg2, #f1c40f); transform: translateY(-1px); }
    .mt-row[data-category="atrasado"] { border-left: 4px solid #e74c3c; }
    .mt-row[data-category="concluido"] { opacity: .55; }
    .mt-row[data-category="concluido"]:hover { opacity: .8; }

    /* Type badge */
    .mt-type {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 10px; border-radius: 6px; font-size: .7rem;
      font-weight: 700; text-transform: uppercase; white-space: nowrap;
    }
    .mt-type.objetivo   { background: rgba(52,152,219,.18); color: #5dade2; }
    .mt-type.kr         { background: rgba(212,160,23,.18); color: #f1c40f; }
    .mt-type.iniciativa { background: rgba(46,204,113,.18); color: #2ecc71; }

    /* Description */
    .mt-desc h3 {
      margin: 0; font-size: .85rem; font-weight: 600; color: var(--text, #eee);
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .mt-desc .mt-context {
      font-size: .7rem; color: var(--text-muted, #888); margin-top: 2px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* Deadline */
    .mt-deadline { text-align: center; }
    .mt-deadline .date { font-size: .78rem; color: var(--text-muted, #aaa); }
    .mt-deadline .days-pill {
      display: inline-block; padding: 2px 8px; border-radius: 10px;
      font-size: .66rem; font-weight: 600; margin-top: 3px;
    }
    .mt-deadline .days-pill.late  { background: rgba(231,76,60,.18); color: #e74c3c; }
    .mt-deadline .days-pill.ok    { background: rgba(46,204,113,.18); color: #2ecc71; }

    /* Status pill */
    .mt-status {
      display: inline-flex; align-items: center; justify-content: center;
      padding: 4px 12px; border-radius: 16px; font-size: .7rem;
      font-weight: 600; white-space: nowrap;
    }
    .mt-status.andamento  { background: rgba(52,152,219,.18); color: #5dade2; }
    .mt-status.nao-inic   { background: rgba(149,165,166,.18); color: #95a5a6; }
    .mt-status.concluido  { background: rgba(46,204,113,.18); color: #2ecc71; }
    .mt-status.cancelado  { background: rgba(231,76,60,.18); color: #e74c3c; }
    .mt-status.sem-status { background: rgba(149,165,166,.12); color: #7f8c8d; }

    /* Empty state */
    .mt-empty {
      text-align: center; padding: 3rem 1rem; color: var(--text-muted, #888);
    }
    .mt-empty i { font-size: 2.5rem; margin-bottom: .75rem; display: block; opacity: .4; }
    .mt-empty p { font-size: .9rem; }

    /* Responsive */
    @media (max-width: 900px) {
      .mt-stats { grid-template-columns: repeat(2, 1fr); }
      .mt-row { grid-template-columns: 1fr; gap: .5rem; }
      .mt-type { width: fit-content; }
      .mt-deadline, .mt-status { text-align: left; }
      .mt-user-select { margin-left: 0; width: 100%; }
      .mt-user-select select { width: 100%; }
    }
    @media (max-width: 500px) {
      .mt-page { padding: 1rem; }
      .mt-stats { grid-template-columns: 1fr 1fr; }
      .mt-profile { gap: .75rem; }
      .mt-avatar { width: 48px; height: 48px; font-size: 1.1rem; }
      .mt-profile-info h1 { font-size: 1.1rem; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="mt-page">

      <!-- ========== PROFILE HEADER ========== -->
      <div class="mt-profile">
        <div class="mt-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="mt-profile-info">
          <h1><?= htmlspecialchars($targetName ?: 'Usuário') ?></h1>
          <?php if (in_array($targetRole, ['admin_master','user_admin'])): ?>
            <span class="mt-role-badge admin"><?= htmlspecialchars($targetRole) ?></span>
          <?php else: ?>
            <span class="mt-role-badge colab">Colaborador</span>
          <?php endif; ?>
        </div>

        <?php if ($isAdmin && count($userList) > 1): ?>
        <div class="mt-user-select">
          <label for="selUser"><i class="fas fa-users"></i> Visualizar:</label>
          <select id="selUser" onchange="if(this.value) location.href='?user_id='+this.value">
            <?php foreach ($userList as $u): ?>
              <option value="<?= (int)$u['id_user'] ?>"
                      <?= (int)$u['id_user'] === $targetUserId ? 'selected' : '' ?>>
                <?= htmlspecialchars(trim($u['primeiro_nome'].' '.($u['ultimo_nome']??''))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>

      <!-- ========== STAT CARDS ========== -->
      <div class="mt-stats">
        <div class="mt-stat total">
          <div class="stat-value"><?= $total ?></div>
          <div class="stat-label"><i class="fas fa-layer-group"></i> Total</div>
        </div>
        <div class="mt-stat atrasado">
          <div class="stat-value"><?= $atrasados ?></div>
          <div class="stat-label"><i class="fas fa-clock"></i> Atrasados</div>
        </div>
        <div class="mt-stat no-prazo">
          <div class="stat-value"><?= $noPrazo ?></div>
          <div class="stat-label"><i class="fas fa-check-circle"></i> No Prazo</div>
        </div>
        <div class="mt-stat conclusao">
          <div class="stat-value"><?= $pctConclusao ?>%</div>
          <div class="stat-label"><i class="fas fa-trophy"></i> Conclusão</div>
        </div>
      </div>

      <!-- ========== FILTER PILLS ========== -->
      <div class="mt-filters">
        <span class="mt-pill active" data-filter="todos" onclick="filterTasks(this)">
          Todos <span class="pill-count"><?= $total ?></span>
        </span>
        <span class="mt-pill" data-filter="atrasado" onclick="filterTasks(this)">
          Atrasados <span class="pill-count"><?= $atrasados ?></span>
        </span>
        <span class="mt-pill" data-filter="no_prazo" onclick="filterTasks(this)">
          No Prazo <span class="pill-count"><?= $noPrazo ?></span>
        </span>
        <span class="mt-pill" data-filter="concluido" onclick="filterTasks(this)">
          Concluídos <span class="pill-count"><?= $concluidos ?></span>
        </span>
      </div>

      <!-- ========== TASK LIST ========== -->
      <div class="mt-list" id="taskList">
        <?php if (empty($tarefas)): ?>
          <div class="mt-empty">
            <i class="fas fa-clipboard-check"></i>
            <p>Nenhuma tarefa encontrada para este usuário.</p>
          </div>
        <?php else: ?>
          <?php foreach ($tarefas as $t):
            // Build navigation URL
            $navUrl = '/OKR_system/views/detalhe_okr.php?id=' . urlencode($t['nav_id']);

            // Type label & icon
            $typeLabels = ['objetivo' => 'Objetivo', 'kr' => 'Key Result', 'iniciativa' => 'Iniciativa'];
            $typeIcons  = ['objetivo' => 'fa-bullseye', 'kr' => 'fa-key', 'iniciativa' => 'fa-rocket'];
            $typeLabel = $typeLabels[$t['tipo']] ?? $t['tipo'];
            $typeIcon  = $typeIcons[$t['tipo']] ?? 'fa-circle';

            // Context breadcrumb
            $context = '';
            if ($t['tipo'] === 'kr' && $t['contexto_obj']) {
              $context = 'Objetivo: ' . mb_substr($t['contexto_obj'], 0, 80);
            } elseif ($t['tipo'] === 'iniciativa') {
              $parts = [];
              if ($t['contexto_obj']) $parts[] = mb_substr($t['contexto_obj'], 0, 50);
              if ($t['contexto_kr'])  $parts[] = mb_substr($t['contexto_kr'], 0, 50);
              $context = implode(' → ', $parts);
            }

            // Status CSS class
            $stLower = mb_strtolower($t['status_label']);
            if (strpos($stLower, 'andamento') !== false)      $statusClass = 'andamento';
            elseif (strpos($stLower, 'não') !== false || strpos($stLower, 'nao') !== false) $statusClass = 'nao-inic';
            elseif (strpos($stLower, 'conclu') !== false)      $statusClass = 'concluido';
            elseif (strpos($stLower, 'cancel') !== false)      $statusClass = 'cancelado';
            else                                                $statusClass = 'sem-status';

            // Deadline formatting
            $prazoFmt = '';
            $daysPill = '';
            if ($t['dt_prazo']) {
              $prazoFmt = date('d/m/Y', strtotime($t['dt_prazo']));
              if ($t['dias_diff'] !== null) {
                $d = $t['dias_diff'];
                if ($t['categoria'] === 'concluido') {
                  $daysPill = ''; // no pill for completed
                } elseif ($d > 0) {
                  $daysPill = '<span class="days-pill late">' . $d . 'd atrasado</span>';
                } elseif ($d === 0) {
                  $daysPill = '<span class="days-pill late">Vence hoje</span>';
                } else {
                  $daysPill = '<span class="days-pill ok">' . abs($d) . 'd restantes</span>';
                }
              }
            }
          ?>
          <a href="<?= htmlspecialchars($navUrl) ?>"
             class="mt-row"
             data-category="<?= $t['categoria'] ?>">
            <span class="mt-type <?= $t['tipo'] ?>">
              <i class="fas <?= $typeIcon ?>"></i> <?= $typeLabel ?>
            </span>
            <div class="mt-desc">
              <h3><?= htmlspecialchars($t['descricao'] ?: '(sem descrição)') ?></h3>
              <?php if ($context): ?>
                <div class="mt-context"><i class="fas fa-sitemap"></i> <?= htmlspecialchars($context) ?></div>
              <?php endif; ?>
            </div>
            <div class="mt-deadline">
              <?php if ($prazoFmt): ?>
                <div class="date"><?= $prazoFmt ?></div>
                <?= $daysPill ?>
              <?php else: ?>
                <div class="date" style="opacity:.5">Sem prazo</div>
              <?php endif; ?>
            </div>
            <span class="mt-status <?= $statusClass ?>"><?= htmlspecialchars($t['status_label']) ?></span>
          </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </main>
  </div>

  <script>
  function filterTasks(pill) {
    document.querySelectorAll('.mt-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');

    const filter = pill.dataset.filter;
    document.querySelectorAll('.mt-row').forEach(row => {
      if (filter === 'todos' || row.dataset.category === filter) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  }
  </script>
</body>
</html>
