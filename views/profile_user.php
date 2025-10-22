<?php
// views/profile_user.php

/* ==================== LOG & DEBUG ==================== */
// Todos os erros vão para views/error_log (sem exibir na tela em prod)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$__logFile = __DIR__ . '/error_log';
if (!file_exists($__logFile)) { @touch($__logFile); }
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@ini_set('log_errors', 1);
@ini_set('error_log', $__logFile);

// Handlers p/ warnings, exceptions e fatais
set_error_handler(function($severity, $message, $file, $line) {
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  $uid = $_SESSION['user_id'] ?? '-';
  error_log("[PHP:$severity] $message in $file:$line | URI=$uri | UID=$uid");
  return false;
});
set_exception_handler(function($ex){
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  $uid = $_SESSION['user_id'] ?? '-';
  error_log("[EXCEPTION] ".$ex->getMessage()." @ ".$ex->getFile().":".$ex->getLine()." | URI=$uri | UID=$uid");
  http_response_code(500);
  echo "Ocorreu um erro interno. Já registramos no log.";
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $uid = $_SESSION['user_id'] ?? '-';
    error_log("[FATAL] {$e['message']} in {$e['file']}:{$e['line']} | URI=$uri | UID=$uid");
  }
});

/* ==================== BOOT ==================== */
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}
$id_user = (int)$_SESSION['user_id'];

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Config
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php'; // sendPasswordResetEmail

// Polyfill p/ PHP 7.x
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' ? true : (strpos($haystack, $needle) !== false);
  }
}

// Gates opcionais
$mode = $_GET['mode'] ?? '';
if ($mode === 'edit') {
  if (function_exists('require_cap')) {
    require_cap('W:objetivo@ORG');
  } else {
    error_log('require_cap() não definido; ignorando verificação.');
  }
}
if (function_exists('gate_page_by_path')) {
  gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
} else {
  error_log('gate_page_by_path() não definido; ignorando gate.');
}

/* ==================== DB ==================== */
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
  error_log("Falha ao conectar: ".$e->getMessage());
  http_response_code(500);
  echo "Erro de conexão. Verifique o log.";
  exit;
}

/* ==================== AVATARES: paths ==================== */
$defaultsDir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar') ?: (__DIR__ . '/../assets/img/avatars/default_avatar');
$defaultsWeb = '/OKR_system/assets/img/avatars/default_avatar/';
$defaultFile = 'default.png';
if (!is_dir($defaultsDir)) @mkdir($defaultsDir, 0755, true);

/* ==================== Helpers ==================== */
function mask_email_local(string $email): string {
  if (!str_contains($email, '@')) return $email;
  [$u, $d] = explode('@', $email, 2);
  $sub = function_exists('mb_substr') ? 'mb_substr' : 'substr';
  $len = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
  $uMasked = $sub($u, 0, 1) . str_repeat('*', max(0, $len($u)-1));
  return $uMasked . '@' . $d;
}
function gallery_file_exists(string $dir, string $file): bool {
  if ($file === '') return false;
  return is_file(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file);
}
function arr_pluck(array $rows, string $k, string $v): array {
  $out = [];
  foreach ($rows as $r) $out[(string)$r[$k]] = (string)$r[$v];
  return $out;
}

/**
 * Retorna pares id=>label de uma tabela dicionário via SHOW COLUMNS (sem INFORMATION_SCHEMA).
 */
function dict_options(PDO $pdo, string $table, array $idCandidates, array $labelCandidates, array $orderCandidates = ['ordem','posicao','nome','descricao','titulo','label'], string $where = '1') : array {
  $cacheKey = 'dict_v2:' . DB_NAME . ':' . $table . ':' . md5(json_encode([$idCandidates,$labelCandidates,$orderCandidates,$where]));
  if (function_exists('apcu_fetch')) {
    $cached = apcu_fetch($cacheKey);
    if ($cached !== false) return $cached;
  }

  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = array_map(function($r){ return $r['Field']; }, $st->fetchAll());
  } catch (Throwable $e) {
    error_log("SHOW COLUMNS falhou em {$table}: ".$e->getMessage());
  }

  $pick = function(array $cands, array $cols, $default=null){
    foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
    return $default;
  };
  $idCol    = $pick($idCandidates, $cols, 'id');
  $labelCol = $pick($labelCandidates, $cols, $idCol);
  $orderCol = $pick($orderCandidates, $cols, $labelCol);

  try {
    $sql = sprintf(
      "SELECT `%s` AS id, `%s` AS label FROM `%s` WHERE %s ORDER BY `%s`",
      str_replace('`','',$idCol),
      str_replace('`','',$labelCol),
      str_replace('`','',$table),
      $where,
      str_replace('`','',$orderCol)
    );
    $rows = $pdo->query($sql)->fetchAll();
    $opts = arr_pluck($rows, 'id', 'label');
    if (function_exists('apcu_store')) apcu_store($cacheKey, $opts, 300);
    return $opts;
  } catch (Throwable $e) {
    error_log("dict_options falhou em {$table}: ".$e->getMessage());
    return [];
  }
}

/* ==================== Dados do usuário ==================== */
try {
  $stmt = $pdo->prepare("
    SELECT u.*, a.filename AS avatar_filename
    FROM usuarios u
    LEFT JOIN avatars a ON a.id = u.avatar_id
    WHERE u.id_user = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $id_user]);
  $user = $stmt->fetch() ?: [];
} catch (Throwable $e) {
  error_log("Falha ao carregar usuário {$id_user}: ".$e->getMessage());
  $user = [];
}
$maskedEmail = mask_email_local((string)($user['email_corporativo'] ?? ''));

/* ========= Dicionários (Departamentos / Níveis) ========= */
$optsDepartamentos = dict_options(
  $pdo, 'dom_departamentos',
  ['id_departamento','id','codigo','cod'],
  ['nome','descricao','titulo','label','departamento','nome_departamento']
);
$optsNiveis = dict_options(
  $pdo, 'dom_niveis_cargo',
  // candidatos para a COLUNA ID
  ['id_nivel_cargo','id_nivel','id_funcao','id_cargo','id'],
  // candidatos para a COLUNA LABEL
  ['descricao','nome','nivel','titulo','label','funcao','cargo','nome_nivel']
);

if (!$optsNiveis) {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM `dom_niveis_cargo`")->fetchAll(PDO::FETCH_ASSOC);
    error_log('dom_niveis_cargo sem opções. Colunas encontradas: '.json_encode(array_column($cols,'Field')));
  } catch (Throwable $e) {
    error_log('Falha ao inspecionar dom_niveis_cargo: '.$e->getMessage());
  }
}

/* ==================== Flash (PRG) ==================== */
$success = $_SESSION['success_message'] ?? '';
$errors  = $_SESSION['error_messages'] ?? [];
unset($_SESSION['success_message'], $_SESSION['error_messages']);

/* ==================== POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
    $_SESSION['error_messages'][] = 'Falha de segurança (CSRF). Recarregue a página.';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  $action = $_POST['action'] ?? '';

  // SUBSTITUA todo o bloco if ($action === 'reset_password') { ... } por:
  if ($action === 'reset_password') {
    try {
      // Gera selector/verifier e hash, conforme functions.php
      if (!function_exists('generateSelectorVerifier')) {
        throw new RuntimeException('generateSelectorVerifier() não encontrada.');
      }
      [$selector, $verifier] = generateSelectorVerifier();
      $verifierHash = function_exists('hashVerifier')
        ? hashVerifier($verifier)
        : hash_hmac('sha256', $verifier, APP_TOKEN_PEPPER);

      $expires = date('Y-m-d H:i:s', time() + 3600);
      $ipReq   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
      $uaReq   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 255);

      // (Opcional) Rate limit — só chama se existir e não falhar
      try {
        if (function_exists('rateLimitResetRequestOrFail')) {
          rateLimitResetRequestOrFail($pdo, $id_user, $ipReq);
        }
      } catch (Throwable $e) {
        error_log('RESET_RATELIMIT_WARN: '.$e->getMessage());
      }

      // Persiste o pedido no formato esperado por password_reset.php
      $ins = $pdo->prepare("
        INSERT INTO usuarios_password_resets
          (user_id, selector, verifier_hash, expira_em, ip_request, user_agent_request, created_at)
        VALUES
          (:uid, :sel, :vh, :exp, :ip, :ua, NOW())
      ");
      $ins->execute([
        ':uid' => $id_user,
        ':sel' => $selector,
        ':vh'  => $verifierHash,
        ':exp' => $expires,
        ':ip'  => $ipReq,
        ':ua'  => $uaReq,
      ]);

      // Busca e-mail do usuário e envia o link
      $emailStmt = $pdo->prepare("SELECT email_corporativo FROM usuarios WHERE id_user = :id");
      $emailStmt->execute([':id' => $id_user]);
      $to = (string)$emailStmt->fetchColumn();

      if ($to && sendPasswordResetEmail($to, $selector, $verifier)) {
        $_SESSION['success_message'] =
          "Enviamos um link de <strong>alteração de senha</strong> para <strong>" .
          htmlspecialchars(mask_email_local($to)) . "</strong>.";
      } else {
        $_SESSION['error_messages'][] = 'Não foi possível enviar o e-mail de alteração de senha.';
      }
    } catch (Throwable $e) {
      error_log("reset_password falhou: ".$e->getMessage());
      $_SESSION['error_messages'][] = 'Erro ao solicitar alteração de senha.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  if ($action === 'remove_avatar') {
    try {
      // Descobre ID do default
      $defaultAvatarId = null;
      $q = $pdo->prepare("SELECT id FROM avatars WHERE filename = :fn AND active = 1 LIMIT 1");
      $q->execute([':fn' => 'default.png']);
      $tmp = $q->fetchColumn();
      if ($tmp !== false) $defaultAvatarId = (int)$tmp;

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
        $st = $pdo->prepare("
          UPDATE usuarios
             SET avatar_id = NULL,
                 dt_alteracao = NOW(),
                 id_user_alteracao = :u
           WHERE id_user = :id
        ");
        $st->execute([':u' => $id_user, ':id' => $id_user]);
      }

      $_SESSION['avatar_filename'] = 'default.png';
      $_SESSION['success_message'] = 'Avatar redefinido para o padrão.';
    } catch (Throwable $e) {
      error_log("remove_avatar falhou: ".$e->getMessage());
      $_SESSION['error_messages'][] = 'Falha ao aplicar avatar padrão.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  if ($action === 'choose_avatar') {
    $chosenId = (int)($_POST['chosen_id'] ?? 0);
    if ($chosenId <= 0) {
      $_SESSION['error_messages'][] = 'Selecione um avatar.';
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
      exit;
    }
    try {
      $av = $pdo->prepare("SELECT id, filename FROM avatars WHERE id = :id AND active = 1 LIMIT 1");
      $av->execute([':id' => $chosenId]);
      $row = $av->fetch();
      if (!$row) {
        $_SESSION['error_messages'][] = 'Avatar inválido ou inativo.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
        exit;
      }
      $filename = (string)$row['filename'];
      if ($filename === '' || !gallery_file_exists($defaultsDir, $filename)) {
        $_SESSION['error_messages'][] = 'Arquivo de avatar não encontrado no servidor.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
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

      $_SESSION['avatar_filename'] = $filename;
      $_SESSION['success_message'] = 'Avatar aplicado com sucesso.';
    } catch (Throwable $e) {
      error_log("choose_avatar falhou: ".$e->getMessage());
      $_SESSION['error_messages'][] = 'Falha ao aplicar avatar.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  if ($action === 'save_profile') {
    $pn  = trim($_POST['primeiro_nome'] ?? '');
    $un  = trim($_POST['ultimo_nome'] ?? '');
    $tel = trim($_POST['telefone'] ?? '');

    $idDept  = isset($_POST['id_departamento']) ? (int)$_POST['id_departamento'] : null;
    $idNivel = isset($_POST['id_nivel_cargo']) ? (int)$_POST['id_nivel_cargo'] : null;

    if ($pn === '') {
      $_SESSION['error_messages'][] = 'Primeiro nome é obrigatório.';
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
      exit;
    }

    // Nulifica IDs que não existirem nos dicionários carregados
    if ($idDept !== null  && !isset($optsDepartamentos[(string)$idDept])) $idDept  = null;
    if ($idNivel !== null && !isset($optsNiveis[(string)$idNivel]))      $idNivel = null;

    try {
      $pdo->beginTransaction();
      $pdo->prepare("
        UPDATE usuarios SET
          primeiro_nome     = :pn,
          ultimo_nome       = :un,
          telefone          = :tel,
          id_departamento   = :dep,
          id_nivel_cargo    = :niv,
          dt_alteracao      = NOW(),
          id_user_alteracao = :u
        WHERE id_user = :id
      ")->execute([
        ':pn'  => $pn,
        ':un'  => ($un !== '' ? $un : null),
        ':tel' => ($tel !== '' ? $tel : null),
        ':dep' => $idDept,
        ':niv' => $idNivel,
        ':u'   => $id_user,
        ':id'  => $id_user,
      ]);
      $pdo->commit();

      $_SESSION['success_message'] = 'Perfil salvo com sucesso.';
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // novo token
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log("save_profile falhou: ".$e->getMessage());
      $_SESSION['error_messages'][] = 'Erro ao salvar perfil.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }
}

/* ==================== Recarrega (GET pós-PRG) ==================== */
try {
  $stmt = $pdo->prepare("
    SELECT u.*, a.filename AS avatar_filename
    FROM usuarios u
    LEFT JOIN avatars a ON a.id = u.avatar_id
    WHERE u.id_user = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $id_user]);
  $user = $stmt->fetch() ?: [];
} catch (Throwable $e) {
  error_log("Falha ao recarregar usuário {$id_user}: ".$e->getMessage());
  $user = [];
}

/* ==================== Formata Telefone ==================== */
$raw = (string)($user['telefone'] ?? '');
$d = preg_replace('/\D+/', '', $raw);
if (strpos($d, '55') === 0 && strlen($d) > 10) $d = substr($d, 2);
$telFmt = strlen($d) === 11
  ? sprintf('(%s) %s-%s', substr($d,0,2), substr($d,2,5), substr($d,7,4))
  : $raw;

/* ==================== Avatar atual ==================== */
$avatarFilename = $defaultFile;
if (!empty($_SESSION['avatar_filename']) && gallery_file_exists($defaultsDir, (string)$_SESSION['avatar_filename'])) {
  $avatarFilename = (string)$_SESSION['avatar_filename'];
} elseif (!empty($user['avatar_filename']) && gallery_file_exists($defaultsDir, (string)$user['avatar_filename'])) {
  $avatarFilename = (string)$user['avatar_filename'];
  $_SESSION['avatar_filename'] = $avatarFilename;
}
if (!gallery_file_exists($defaultsDir, $avatarFilename)) {
  $avatarFilename = $defaultFile;
  $_SESSION['avatar_filename'] = $defaultFile;
}
$avatarUrl = $defaultsWeb . rawurlencode($avatarFilename);

/* ==================== AJAX: lista de avatares ==================== */
if (($_GET['ajax'] ?? '') === 'avatar_list') {
  if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
  header('Content-Type: application/json; charset=utf-8');

  $filter   = $_GET['filter'] ?? 'todos';
  if (!in_array($filter, ['todos','masculino','feminino'], true)) $filter = 'todos';

  $page     = max(1, (int)($_GET['page'] ?? 1));
  $pageSize = min(60, max(5, (int)($_GET['page_size'] ?? 15)));
  $offset   = ($page - 1) * $pageSize;

  try {
    if ($filter === 'todos') {
      $total = (int)$pdo->query("SELECT COUNT(*) FROM avatars WHERE active = 1 AND filename <> 'default.png'")->fetchColumn();
      $listStmt = $pdo->prepare("
        SELECT id, filename, gender
          FROM avatars
         WHERE active = 1
           AND filename <> 'default.png'
         ORDER BY id
         LIMIT :limit OFFSET :offset
      ");
    } else {
      $countStmt = $pdo->prepare("SELECT COUNT(*) FROM avatars WHERE active = 1 AND gender = :g AND filename <> 'default.png'");
      $countStmt->execute([':g' => $filter]);
      $total = (int)$countStmt->fetchColumn();

      $listStmt = $pdo->prepare("
        SELECT id, filename, gender
          FROM avatars
         WHERE active = 1
           AND gender = :g
           AND filename <> 'default.png'
         ORDER BY id
         LIMIT :limit OFFSET :offset
      ");
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
    error_log("avatar_list AJAX falhou: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['items' => [], 'total' => 0, 'error' => 'Falha ao consultar avatares']);
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

  <?php if (!defined('PB_THEME_LINK_EMITTED')) { define('PB_THEME_LINK_EMITTED', true); ?>
    <link rel="stylesheet" href="/OKR_system/assets/company_theme.php">
  <?php } ?>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{ --card: var(--bg1, #222222); --border:#222733; --soft:#0d1117; --text:#eaeef6; --muted:#a6adbb; --gold: var(--bg2, #F1C40F); --shadow: 0 10px 30px rgba(0,0,0,.20); }
    body{ background:#fff !important; color:#111; }
    .content{ background: transparent; }

    /* menos espaço no fim da página */
    main.profile-wrapper{ padding:24px 24px 10px; display:grid; grid-template-columns:1fr; gap:24px; }

    .profile-grid{ display:grid; grid-template-columns:360px 1fr; gap:20px; align-items:start; }
    @media (max-width:1000px){ .profile-grid{ grid-template-columns:1fr; } }

    .card-dk{ background: linear-gradient(180deg, var(--card), var(--soft)); border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow); color:var(--text); overflow:hidden; }
    .card-dk header{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px 16px; border-bottom:1px solid var(--border); background:#0b101a; }
    .card-dk header h2{ margin:0; font-size:1.05rem; letter-spacing:.2px; }
    .card-dk .card-body{ padding:16px; display:flex; flex-direction:column; gap:12px; }

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
    .btn-right{ display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .btn-full{ width:100%; justify-content:center; }

    .form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:700px){ .form-grid{ grid-template-columns:1fr; } }
    .form-group{ display:flex; flex-direction:column; gap:6px; }
    .form-group label{ font-weight:700; color:#d1d5db; }
    .form-control, .form-select{ background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:12px; padding:10px 12px; outline:none; }
    .form-control:focus, .form-select:focus{ border-color:#334155; box-shadow:0 0 0 3px rgba(148,163,184,.15); }
    .split{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:900px){ .split{ grid-template-columns:1fr; } }

    /* ===== Bloco “Alterar senha” (sem ícone) ===== */
    .security-block{
      background:#0c1118;
      border:1px solid #1f2635;
      border-radius:14px;
      padding:16px;
    }
    .security-content{
      display:flex;
      flex-direction:column;
      gap:10px;
      align-items:flex-start; /* alinhado à esquerda, limpo e consistente */
    }
    .security-desc{
      color:#cbd5e1;
      font-size:.95rem;
      line-height:1.45;
    }
    .security-desc strong{
      color:#fff;
      display:block;
      margin-bottom:4px;
    }
    .security-actions .btn{ width:auto; }
    @media (max-width:700px){
      .security-actions .btn{ width:100%; justify-content:center; }
    }

    /* Espaço entre formulário e botões na coluna direita */
    .form-actions{ margin-top:16px; display:flex; justify-content:flex-end; }
    /* -------- Modal Avatar -------- */
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
  <?php @include __DIR__.'/partials/sidebar.php'; ?>
  <div class="content">
    <?php @include __DIR__.'/partials/header.php'; ?>

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

              <div class="btn-right">
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

            <hr style="border:none; border-top:1px solid var(--border);">

            <!-- Bloco de Alterar Senha (sem ícone, botão abaixo do texto) -->
            <div class="security-block" role="group" aria-label="Alterar senha">
              <div class="security-content">
                <div class="security-desc">
                  <strong>Alterar senha</strong>
                  <span>Enviaremos um link de alteração para o seu e-mail corporativo <?= htmlspecialchars($maskedEmail) ?>.</span>
                </div>
                <div class="security-actions">
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn btn-primary">
                      <i class="fa-solid fa-paper-plane"></i> Enviar link
                    </button>
                  </form>
                </div>
              </div>
            </div>

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
                  <label>E-mail corporativo</label>
                  <input class="form-control" value="<?= htmlspecialchars($user['email_corporativo'] ?? '') ?>" readonly>
                </div>
              </div>

              <div class="form-grid">
                <div class="form-group">
                  <label for="id_departamento">Departamento</label>
                  <select id="id_departamento" name="id_departamento" class="form-select">
                    <option value="">Selecione</option>
                    <?php foreach ($optsDepartamentos as $id=>$nome): ?>
                      <option value="<?= htmlspecialchars($id) ?>" <?= ((string)($user['id_departamento'] ?? '') === (string)$id ? 'selected':'') ?>>
                        <?= htmlspecialchars($nome) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label for="id_nivel_cargo">Nível/Cargo</label>
                  <select id="id_nivel_cargo" name="id_nivel_cargo" class="form-select">
                    <option value="">Selecione</option>
                    <?php foreach ($optsNiveis as $id=>$label): ?>
                      <option value="<?= htmlspecialchars($id) ?>" <?= ((string)($user['id_nivel_cargo'] ?? '') === (string)$id ? 'selected':'') ?>>
                        <?= htmlspecialchars($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-actions">
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

    // ===== Modal Avatar =====
    let TOTAL = 0;

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

    form.addEventListener('submit', function(e){
      if (!chosenId.value) {
        e.preventDefault();
        alert('Selecione um avatar da galeria.');
      }
    });
  </script>
</body>
</html>
