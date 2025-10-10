<?php
// views/profile_user.php

// ===== DEV ONLY (remova em produção) =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// ===== Gate =====
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}
$id_user = (int)$_SESSION['user_id'];

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ===== Config + helpers =====
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php'; // sendPasswordResetEmail
if (($_GET['mode'] ?? '') === 'edit') {
  require_cap('W:objetivo@ORG');
}
// Gate automático pela tabela dom_paginas.requires_cap
gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

// ===== Conexão =====
try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
  $pdoOptions = (isset($options) && is_array($options)) ? $options : [];
  $pdoOptions += [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: " . $e->getMessage());
}

/* ---------- Paths dos avatares (galeria) ---------- */
$defaultsDir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar') ?: (__DIR__ . '/../assets/img/avatars/default_avatar');
$defaultsWeb = '/OKR_system/assets/img/avatars/default_avatar/';
$defaultFile = 'default.png';

// Garante pasta (só por segurança)
if (!is_dir($defaultsDir)) @mkdir($defaultsDir, 0755, true);

/* ---------- Helpers ---------- */
function mask_email_local(string $email): string {
  if (!str_contains($email, '@')) return $email;
  [$u, $d] = explode('@', $email, 2);
  $uMasked = mb_substr($u, 0, 1) . str_repeat('*', max(0, mb_strlen($u)-1));
  return $uMasked . '@' . $d;
}
function gallery_file_exists(string $dir, string $file): bool {
  if ($file === '') return false;
  return is_file(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file);
}

/* ---------- Descobre o ID do avatar default (por filename) ---------- */
$defaultAvatarId = null;
try {
  $q = $pdo->prepare("SELECT id FROM avatars WHERE filename = :fn AND active = 1 LIMIT 1");
  $q->execute([':fn' => $defaultFile]);
  $tmp = $q->fetchColumn();
  if ($tmp !== false) $defaultAvatarId = (int)$tmp;
} catch (Throwable $e) {
  // silencioso; fallback tratado abaixo
}

/* ---------- Carrega dados do usuário (com JOIN no avatar) ---------- */
$stmt = $pdo->prepare("
  SELECT u.*, a.filename AS avatar_filename
  FROM usuarios u
  LEFT JOIN avatars a ON a.id = u.avatar_id
  WHERE u.id_user = :id
  LIMIT 1
");
$stmt->execute([':id' => $id_user]);
$user = $stmt->fetch() ?: [];

/* ---------- Flash (PRG) ---------- */
$success = $_SESSION['success_message'] ?? '';
$errors  = $_SESSION['error_messages'] ?? [];
unset($_SESSION['success_message'], $_SESSION['error_messages']);

/* ---------- Processamento POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $_SESSION['error_messages'][] = 'Falha de segurança (CSRF). Recarregue a página.';
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  $action = $_POST['action'] ?? '';

  // Reset de senha
  if ($action === 'reset_password') {
    try {
      $token   = bin2hex(random_bytes(16));
      $expires = date('Y-m-d H:i:s', time() + 3600);
      $pdo->prepare("
        INSERT INTO usuarios_password_resets (user_id, token, expira_em)
        VALUES (:uid, :tok, :exp)
      ")->execute([':uid' => $id_user, ':tok' => $token, ':exp' => $expires]);

      $emailStmt = $pdo->prepare("SELECT email_corporativo FROM usuarios WHERE id_user = :id");
      $emailStmt->execute([':id' => $id_user]);
      $to = (string)$emailStmt->fetchColumn();

      if ($to && sendPasswordResetEmail($to, $token)) {
        $_SESSION['success_message'] = "E-mail de recuperação enviado para <strong>" . htmlspecialchars(mask_email_local($to)) . "</strong>.";
      } else {
        $_SESSION['error_messages'][] = 'Não foi possível enviar o e-mail de recuperação.';
      }
    } catch (Throwable $e) {
      $_SESSION['error_messages'][] = 'Erro ao solicitar recuperação de senha.';
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  // Remover avatar -> aplica avatar_id = (ID do default.png) OU NULL se não encontrado
  if ($action === 'remove_avatar') {
    try {
      if ($defaultAvatarId !== null) {
        $st = $pdo->prepare("
          UPDATE usuarios
             SET avatar_id = :defid,
                 dt_alteracao = NOW(),
                 id_user_alteracao = :u
           WHERE id_user = :id
        ");
        $st->execute([':defid' => $defaultAvatarId, ':u' => $id_user, ':id' => $id_user]);
      } else {
        // Fallback seguro (não deve ocorrer se a linha do default existe)
        $st = $pdo->prepare("
          UPDATE usuarios
             SET avatar_id = NULL,
                 dt_alteracao = NOW(),
                 id_user_alteracao = :u
           WHERE id_user = :id
        ");
        $st->execute([':u' => $id_user, ':id' => $id_user]);
      }

      // Mantém o header/UX em sincronia imediatamente
      $_SESSION['avatar_filename'] = $defaultFile;
      $_SESSION['success_message'] = 'Avatar redefinido para o padrão.';
    } catch (Throwable $e) {
      $_SESSION['error_messages'][] = 'Falha ao aplicar avatar padrão.';
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  // Escolher avatar da galeria -> grava avatar_id (FK)
  if ($action === 'choose_avatar') {
    $chosenId = (int)($_POST['chosen_id'] ?? 0);
    if ($chosenId <= 0) {
      $_SESSION['error_messages'][] = 'Selecione um avatar.';
      header('Location: ' . $_SERVER['REQUEST_URI']);
      exit;
    }

    try {
      // Busca o avatar escolhido (ativo)
      $av = $pdo->prepare("SELECT id, filename FROM avatars WHERE id = :id AND active = 1 LIMIT 1");
      $av->execute([':id' => $chosenId]);
      $row = $av->fetch();
      if (!$row) {
        $_SESSION['error_messages'][] = 'Avatar inválido ou inativo.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
      }
      $filename = (string)$row['filename'];
      if ($filename === '' || !gallery_file_exists($defaultsDir, $filename)) {
        $_SESSION['error_messages'][] = 'Arquivo de avatar não encontrado no servidor.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
      }

      $st = $pdo->prepare("
        UPDATE usuarios
           SET avatar_id = :aid,
               dt_alteracao = NOW(),
               id_user_alteracao = :u
         WHERE id_user = :id
      ");
      $st->execute([':aid' => (int)$row['id'], ':u' => $id_user, ':id' => $id_user]);

      $_SESSION['avatar_filename'] = $filename; // sincroniza header
      $_SESSION['success_message'] = 'Avatar aplicado com sucesso.';
    } catch (Throwable $e) {
      $_SESSION['error_messages'][] = 'Falha ao aplicar avatar.';
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  // Salvar demais dados
  if ($action === 'save_profile') {
    $pn  = trim($_POST['primeiro_nome'] ?? '');
    $un  = trim($_POST['ultimo_nome'] ?? '');
    $tel = trim($_POST['telefone'] ?? '');
    $emp = trim($_POST['empresa'] ?? '');
    $fx  = trim($_POST['faixa_qtd_funcionarios'] ?? '');

    if ($pn === '') {
      $_SESSION['error_messages'][] = 'Primeiro nome é obrigatório.';
      header('Location: ' . $_SERVER['REQUEST_URI']);
      exit;
    }

    try {
      $pdo->beginTransaction();
      $pdo->prepare("
        UPDATE usuarios SET
          primeiro_nome          = :pn,
          ultimo_nome            = :un,
          telefone               = :tel,
          empresa                = :emp,
          faixa_qtd_funcionarios = :fx,
          dt_alteracao           = NOW(),
          id_user_alteracao      = :u
        WHERE id_user = :id
      ")->execute([
        ':pn'  => $pn,
        ':un'  => $un ?: null,
        ':tel' => $tel ?: null,
        ':emp' => $emp ?: null,
        ':fx'  => $fx ?: null,
        ':u'   => $id_user,
        ':id'  => $id_user,
      ]);
      $pdo->commit();
      $_SESSION['success_message'] = 'Perfil salvo com sucesso.';
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // novo token
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['error_messages'][] = 'Erro ao salvar perfil.';
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
}

/* ---------- Recarrega dados (GET após PRG) ---------- */
$stmt = $pdo->prepare("
  SELECT u.*, a.filename AS avatar_filename
  FROM usuarios u
  LEFT JOIN avatars a ON a.id = u.avatar_id
  WHERE u.id_user = :id
  LIMIT 1
");
$stmt->execute([':id' => $id_user]);
$user = $stmt->fetch() ?: [];

/* ---------- Telefone formatado ---------- */
$raw = (string)($user['telefone'] ?? '');
$d = preg_replace('/\D+/', '', $raw);
if (strpos($d, '55') === 0 && strlen($d) > 10) $d = substr($d, 2);
$telFmt = strlen($d) === 11
  ? sprintf('(%s) %s-%s', substr($d,0,2), substr($d,2,5), substr($d,7,4))
  : $raw;

/* ---------- Avatar atual ---------- */
$avatarFilename = $defaultFile;

// 1) cache de sessão
if (!empty($_SESSION['avatar_filename'])) {
  $candidate = (string)$_SESSION['avatar_filename'];
  if ($candidate !== '' && gallery_file_exists($defaultsDir, $candidate)) {
    $avatarFilename = $candidate;
  }
}
// 2) filename via JOIN
elseif (!empty($user['avatar_filename']) && gallery_file_exists($defaultsDir, (string)$user['avatar_filename'])) {
  $avatarFilename = (string)$user['avatar_filename'];
  $_SESSION['avatar_filename'] = $avatarFilename;
}

// 3) fallback
if (!gallery_file_exists($defaultsDir, $avatarFilename)) {
  $avatarFilename = $defaultFile;
  $_SESSION['avatar_filename'] = $defaultFile;
}

$avatarUrl = $defaultsWeb . rawurlencode($avatarFilename);

/* ---------- AJAX: lista paginada de avatares (DB + sync da pasta) ---------- */
if (($_GET['ajax'] ?? '') === 'avatar_list') {
  if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
  header('Content-Type: application/json; charset=utf-8');

  $filter   = $_GET['filter'] ?? 'todos';
  if (!in_array($filter, ['todos','masculino','feminino'], true)) $filter = 'todos';

  $page     = max(1, (int)($_GET['page'] ?? 1));
  $pageSize = min(60, max(5, (int)($_GET['page_size'] ?? 15)));
  $offset   = ($page - 1) * $pageSize;

  try {
    // --- SINCRONIZAÇÃO LEVE (cache 5 min via APCu) ---
    $syncKey = 'avatars_sync_flag_' . md5($defaultsDir);
    $doSync = true;
    if (function_exists('apcu_fetch')) {
      $doSync = (apcu_fetch($syncKey) === false);
    }
    if ($doSync) {
      $files = glob($defaultsDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
      $ins = $pdo->prepare("INSERT IGNORE INTO avatars (filename, gender, active) VALUES (:f, :g, 1)");
      foreach ($files as $path) {
        $bn = basename($path);
        if (strcasecmp($bn, 'default.png') === 0) continue; // não listar default
        $gender = 'todos';
        if (stripos($bn, 'fem') === 0)      $gender = 'feminino';
        elseif (stripos($bn, 'user') === 0) $gender = 'masculino';
        $ins->execute([':f' => $bn, ':g' => $gender]);
      }
      if (function_exists('apcu_store')) apcu_store($syncKey, 1, 300);
    }
    // --- FIM SYNC ---

    // COUNT + SELECT paginado
    if ($filter === 'todos') {
      $total = (int)$pdo->query("SELECT COUNT(*) FROM avatars WHERE active = 1")->fetchColumn();
      $listStmt = $pdo->prepare("SELECT id, filename, gender
                                   FROM avatars
                                  WHERE active = 1
                                  ORDER BY id
                                  LIMIT :limit OFFSET :offset");
    } else {
      $countStmt = $pdo->prepare("SELECT COUNT(*) FROM avatars WHERE active = 1 AND gender = :g");
      $countStmt->execute([':g' => $filter]);
      $total = (int)$countStmt->fetchColumn();

      $listStmt = $pdo->prepare("SELECT id, filename, gender
                                   FROM avatars
                                  WHERE active = 1 AND gender = :g
                                  ORDER BY id
                                  LIMIT :limit OFFSET :offset");
      $listStmt->bindValue(':g', $filter);
    }

    $listStmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
      $bn = (string)$r['filename'];
      $thumbPath = $defaultsDir . '/_thumbs/' . $bn;
      $url = is_file($thumbPath)
        ? $defaultsWeb . '_thumbs/' . rawurlencode($bn)
        : $defaultsWeb . rawurlencode($bn);

      $items[] = [
        'id'     => (int)$r['id'],
        'file'   => $bn,
        'url'    => $url,
        'gender' => (string)$r['gender'],
      ];
    }

    header('Cache-Control: private, max-age=300');
    echo json_encode(['items' => $items, 'total' => $total], JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['items' => [], 'total' => 0, 'error' => 'Falha ao consultar/sincronizar avatares']);
  }
  exit;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Meu Perfil – OKR System</title>

  <!-- Tema dinâmico (necessário p/ sidebar) -->
  <?php if (!defined('PB_THEME_LINK_EMITTED')) { define('PB_THEME_LINK_EMITTED', true); ?>
    <link rel="stylesheet" href="/OKR_system/assets/company_theme.php">
  <?php } ?>

  <!-- CSS globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{
      --card: var(--bg1, #222222);
      --border:#222733;
      --soft:#0d1117;
      --text:#eaeef6;
      --muted:#a6adbb;
      --gold: var(--bg2, #F1C40F);
      --shadow: 0 10px 30px rgba(0,0,0,.20);
    }
    body{ background:#fff !important; color:#111; }
    .content{ background: transparent; }

    main.profile-wrapper{ padding:24px; display:grid; grid-template-columns:1fr; gap:24px; }

    .profile-grid{ display:grid; grid-template-columns:360px 1fr; gap:20px; }
    @media (max-width:1000px){ .profile-grid{ grid-template-columns:1fr; } }

    .card-dk{
      background: linear-gradient(180deg, var(--card), var(--soft));
      border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow);
      color:var(--text); overflow:hidden;
    }
    .card-dk header{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px 16px; border-bottom:1px solid var(--border); background:#0b101a; }
    .card-dk header h2{ margin:0; font-size:1.05rem; letter-spacing:.2px; }
    .card-dk .card-body{ padding:16px; }

    .alert{ padding:10px 12px; border-radius:12px; border:1px solid; margin-bottom:12px; font-size:.95rem; }
    .alert-success{ background:#0f2b20; border-color:#1e7f5a; color:#c6f6d5; }
    .alert-danger{ background:#2a0f13; border-color:#b91c1c; color:#fecaca; }

    .section-title{ display:flex; align-items:center; gap:10px; font-weight:800; color:#fff; }
    .section-title .badge{ background:var(--gold); color:#1a1a1a; padding:5px 9px; border-radius:999px; font-size:.72rem; font-weight:800; text-transform:uppercase; }

    .avatar-box{ display:flex; flex-direction:column; align-items:center; gap:12px; }
    .avatar-box .img-wrap{ width:144px; height:144px; border-radius:50%; border:1px solid var(--border); overflow:hidden; background:#0d1117; display:grid; place-items:center; }
    .avatar-box img{ width:100%; height:100%; object-fit:cover; display:block; }

    .btn{ appearance:none; border:1px solid var(--border); border-radius:12px; padding:10px 14px; font-weight:700; cursor:pointer; color:#e5e7eb; background:#1f2937; transition:.15s; display:inline-flex; gap:8px; align-items:center; }
    .btn:hover{ transform: translateY(-1px); border-color:#2a3342; }
    .btn-outline{ background: transparent; }
    .btn-danger{ background:#7f1d1d; border-color:#b91c1c; }
    .btn-primary{ background:#111827; }
    .btn-right{ display:flex; justify-content:flex-end; gap:10px; }

    .form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:700px){ .form-grid{ grid-template-columns:1fr; } }
    .form-group{ display:flex; flex-direction:column; gap:6px; }
    .form-group label{ font-weight:700; color:#d1d5db; }
    .form-control, .form-select{ background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:12px; padding:10px 12px; outline:none; }
    .form-control:focus, .form-select:focus{ border-color:#334155; box-shadow:0 0 0 3px rgba(148,163,184,.15); }
    .split{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:900px){ .split{ grid-template-columns:1fr; } }

    /* -------- Modal Avatar (somente galeria) -------- */
    .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; padding:1rem; z-index:2050; }
    .modal-backdrop.show{ display:flex; }
    .modal{ width:min(920px, 96vw); max-height:90vh; overflow:auto; background:#0f1420; color:#e5e7eb; border:1px solid #223047; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.4); }
    .modal header{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #1f2a3a; background:#0b101a; }
    .modal .modal-body{ padding:16px; }
    .modal .modal-actions{ display:flex; gap:10px; justify-content:flex-end; padding:12px 16px; border-top:1px solid #1f2a3a; background:#0b101a; }

    .icon-filters{ display:flex; gap:8px; align-items:center; margin:8px 0 12px; }
    .chip-icon{ border:1px solid #273244; background:#0c1118; color:#9ca3af; padding:8px 10px; border-radius:999px; display:inline-flex; gap:8px; align-items:center; cursor:pointer; font-weight:800; }
    .chip-icon i{ font-size:1rem; }
    .chip-icon.active{ background: #1c2a44; color:#fff; border-color:#3b5aa1; box-shadow:0 0 0 3px rgba(59,90,161,.25) inset; }

    .grid-avatars{ display:grid; grid-template-columns: repeat(5, 1fr); gap:12px; }
    @media (max-width:680px){ .grid-avatars{ grid-template-columns: repeat(2, 1fr); } }
    .avatar-card{ background:#0c1118; border:1px solid #1f2635; border-radius:14px; padding:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative; }
    .avatar-card img{ width:60%; height:auto; border-radius:10px; display:block; }
    .avatar-card.selected{ outline:3px solid var(--gold); box-shadow:0 0 0 4px rgba(241,196,15,.25); }
    .avatar-card .badge-g{ position:absolute; left:8px; top:8px; background:#111827; border:1px solid #1f2635; color:#cbd5e1; font-size:.75rem; padding:2px 6px; border-radius:999px; }

    .paginator{ display:flex; align-items:center; justify-content:space-between; margin-top:12px; }
    .paginator .btn{ background:#0c1118; color:#e5e7eb; }
    .paginator .info{ color:#9aa4b2; font-size:.9rem; }
    .btn-link{ background:transparent; border:none; color:#93c5fd; text-decoration:underline; padding:0; cursor:pointer; }
  </style>
</head>
<body>
  <?php include __DIR__.'/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__.'/partials/header.php'; ?>

    <main class="profile-wrapper">
      <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0; padding-left:18px;">
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="profile-grid">
        <!-- Coluna esquerda: Avatar & Segurança -->
        <section class="card-dk">
          <header>
            <h2 class="section-title"><span class="badge">Perfil</span> Avatar & Segurança</h2>
          </header>
          <div class="card-body">
            <div class="avatar-box">
              <div class="img-wrap">
                <img id="currentAvatar" src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar do usuário">
              </div>

              <div class="btn-right" style="gap:8px; flex-wrap:wrap;">
                <button type="button" class="btn btn-outline" id="btnOpenAvatarModal">
                  <i class="fa-solid fa-image"></i> Alterar avatar
                </button>

                <form method="post" style="display:inline" onsubmit="return confirm('Redefinir seu avatar para o padrão?');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="remove_avatar">
                  <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-user-slash"></i> Remover avatar
                  </button>
                </form>
              </div>
            </div>

            <hr style="border:none; border-top:1px solid var(--border); margin:16px 0">

            <form method="post" class="btn-right">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="reset_password">
              <button type="submit" class="btn">
                <i class="fa-solid fa-envelope-circle-check"></i> Enviar e-mail de redefinição
              </button>
            </form>
          </div>
        </section>

        <!-- Coluna direita: Dados -->
        <section class="card-dk">
          <header>
            <h2 class="section-title"><span class="badge">Dados</span> Informações de usuário</h2>
          </header>
          <div class="card-body">
            <form method="post" id="profileForm" novalidate>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="save_profile">

              <div class="form-grid">
                <div class="form-group">
                  <label for="primeiro_nome">Primeiro nome *</label>
                  <input id="primeiro_nome" name="primeiro_nome" class="form-control" required
                         value="<?= htmlspecialchars($user['primeiro_nome'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label for="ultimo_nome">Último nome</label>
                  <input id="ultimo_nome" name="ultimo_nome" class="form-control"
                         value="<?= htmlspecialchars($user['ultimo_nome'] ?? '') ?>">
                </div>
              </div>

              <div class="split">
                <div class="form-group">
                  <label for="telefone">Telefone (WhatsApp)</label>
                  <input id="telefone" name="telefone" class="form-control"
                         value="<?= htmlspecialchars($telFmt) ?>" placeholder="(XX) 9XXXX-XXXX">
                </div>
                <div class="form-group">
                  <label for="empresa">Empresa</label>
                  <input id="empresa" name="empresa" class="form-control"
                         value="<?= htmlspecialchars($user['empresa'] ?? '') ?>">
                </div>
              </div>

              <div class="form-group" style="margin-top:12px;">
                <label for="faixa_qtd_funcionarios">Faixa de funcionários</label>
                <select id="faixa_qtd_funcionarios" name="faixa_qtd_funcionarios" class="form-select">
                  <option value="">Selecione</option>
                  <?php foreach (['1–100','101–500','501–1000','1001+'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= (($user['faixa_qtd_funcionarios'] ?? '') === $opt ? 'selected':'') ?>>
                      <?= $opt ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="btn-right" style="margin-top:8px;">
                <button type="submit" class="btn btn-primary">
                  <i class="fa-solid fa-floppy-disk"></i> Salvar alterações
                </button>
              </div>
            </form>
          </div>
        </section>
      </div>
    </main>
  </div>

  <!-- Modal Alterar Avatar (somente galeria) -->
  <div class="modal-backdrop" id="avatarModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="avatarModalTitle">
      <header>
        <h3 id="avatarModalTitle">Alterar avatar</h3>
        <button type="button" class="btn-link" id="avatarModalClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <form id="avatarModalForm" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="choose_avatar">
        <input type="hidden" name="chosen_id" id="chosen_id" value="">
        <div class="modal-body">

          <div class="icon-filters" role="tablist" aria-label="Filtro gênero">
            <button type="button" class="chip-icon active" data-filter="todos" role="tab" aria-selected="true" title="Todos">
              <i class="fa-solid fa-venus-mars"></i> Todos
            </button>
            <button type="button" class="chip-icon" data-filter="masculino" role="tab" aria-selected="false" title="Masculino">
              <i class="fa-solid fa-mars"></i> Masculino
            </button>
            <button type="button" class="chip-icon" data-filter="feminino" role="tab" aria-selected="false" title="Feminino">
              <i class="fa-solid fa-venus"></i> Feminino
            </button>
          </div>

          <div class="grid-avatars" id="avatarGrid"></div>

          <div class="paginator">
            <button type="button" class="btn" id="pgPrev"><i class="fa-solid fa-angle-left"></i> Anterior</button>
            <div class="info" id="pgInfo">Página 1</div>
            <button type="button" class="btn" id="pgNext">Próxima <i class="fa-solid fa-angle-right"></i></button>
          </div>

        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="avatarCancel">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="avatarSave">Salvar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Telefone mask
    (function(){
      const tel = document.getElementById('telefone');
      if (!tel) return;
      tel.addEventListener('input', function(){
        let d = this.value.replace(/\D/g,'').slice(0,11), f = '';
        if (d.length>0)  f = '(' + d.slice(0,2);
        if (d.length>=3) f += ') ' + d.slice(2,7);
        if (d.length>=8) f += '-' + d.slice(7);
        this.value = f;
      });
    })();

    // ===== Modal Avatar (somente galeria) =====
    let TOTAL = 0; // preenchido pelo AJAX

    const backdrop  = document.getElementById('avatarModal');
    const openBtn   = document.getElementById('btnOpenAvatarModal');
    const closeBtn  = document.getElementById('avatarModalClose');
    const cancelBtn = document.getElementById('avatarCancel');
    const form      = document.getElementById('avatarModalForm');
    const chosenId  = document.getElementById('chosen_id');

    function openAvatarModal(){
      backdrop.classList.add('show');
      backdrop.setAttribute('aria-hidden','false');
      page = 1;
      fetchAndRender();
    }
    function closeAvatarModal(){
      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden','true');
    }

    openBtn  && openBtn.addEventListener('click', openAvatarModal);
    closeBtn && closeBtn.addEventListener('click', closeAvatarModal);
    cancelBtn&& cancelBtn.addEventListener('click', closeAvatarModal);
    backdrop && backdrop.addEventListener('click', (e)=>{ if (e.target === backdrop) closeAvatarModal(); });
    document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && backdrop.classList.contains('show')) closeAvatarModal(); });

    // ===== Filtro & paginação (lazy via AJAX) =====
    const grid   = document.getElementById('avatarGrid');
    const pgPrev = document.getElementById('pgPrev');
    const pgNext = document.getElementById('pgNext');
    const pgInfo = document.getElementById('pgInfo');
    const filterBtns = document.querySelectorAll('.chip-icon');

    let filter = 'todos';
    let page   = 1;
    const PAGE_SIZE = 15;

    function totalPages(){ return Math.max(1, Math.ceil(TOTAL / PAGE_SIZE)); }

    function skeleton(n = PAGE_SIZE){
      const frag = document.createDocumentFragment();
      for (let i=0;i<n;i++){
        const card = document.createElement('div');
        card.className = 'avatar-card';
        card.style.minHeight = '120px';
        card.innerHTML = `<div style="width:60%;height:90px;border-radius:10px;background:#111826;opacity:.35;"></div>`;
        frag.appendChild(card);
      }
      grid.innerHTML = '';
      grid.appendChild(frag);
    }

    function render(items){
      const frag = document.createDocumentFragment();
      items.forEach(item=>{
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'avatar-card';
        card.innerHTML = `
          <span class="badge-g">${item.gender === 'feminino' ? '♀' : (item.gender === 'masculino' ? '♂' : '⚤')}</span>
          <img loading="lazy" decoding="async" fetchpriority="low"
               width="128" height="128"
               src="${item.url}" alt="Avatar ${item.file}">
        `;
        card.addEventListener('click', ()=>{
          chosenId.value = String(item.id);
          document.querySelectorAll('.avatar-card.selected').forEach(el=>el.classList.remove('selected'));
          card.classList.add('selected');
        });
        frag.appendChild(card);
      });
      grid.innerHTML = '';
      grid.appendChild(frag);

      const tp = totalPages();
      pgInfo.textContent = 'Página ' + page + ' de ' + tp;
      pgPrev.disabled = (page<=1);
      pgNext.disabled = (page>=tp);
    }

    async function fetchAndRender(){
      skeleton();
      try{
        const url = `?ajax=avatar_list&filter=${encodeURIComponent(filter)}&page=${page}&page_size=${PAGE_SIZE}`;
        const res = await fetch(url, { credentials: 'same-origin', cache: 'no-cache' });
        const data = await res.json();
        TOTAL = data.total || 0;
        render(data.items || []);
      }catch(e){
        grid.innerHTML = '<div style="color:#fca5a5">Falha ao carregar avatares.</div>';
      }
    }

    filterBtns.forEach(b=>{
      b.addEventListener('click', ()=>{
        filterBtns.forEach(x=>{ x.classList.remove('active'); x.setAttribute('aria-selected','false'); });
        b.classList.add('active'); b.setAttribute('aria-selected','true');
        filter = b.dataset.filter;
        page = 1;
        fetchAndRender();
      });
    });

    pgPrev.addEventListener('click', ()=>{ if (page>1){ page--; fetchAndRender(); } });
    pgNext.addEventListener('click', ()=>{ if (page<totalPages()){ page++; fetchAndRender(); } });

    // seleção + submit (mantém)
    form.addEventListener('submit', function(e){
      if (!chosenId.value) {
        e.preventDefault();
        alert('Selecione um avatar da galeria.');
      }
    });
  </script>
</body>
</html>
