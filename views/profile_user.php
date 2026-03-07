<?php
// views/profile_user.php
declare(strict_types=1);

/* ==================== BOOT ==================== */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}
$id_user = (int)$_SESSION['user_id'];

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

// Config (carrega .env e defines DB_*)
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Polyfill p/ PHP 7.x
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' ? true : (strpos($haystack, $needle) !== false);
  }
}

/* ==================== GATES (opcionais) ==================== */
$mode = $_GET['mode'] ?? '';
if ($mode === 'edit') {
  if (function_exists('require_cap')) {
    require_cap('W:objetivo@ORG');
  }
}
if (function_exists('gate_page_by_path')) {
  gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
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
  error_log("profile_user.php: Falha ao conectar no banco: " . $e->getMessage());
  http_response_code(500);
  echo "Erro de conexão. Tente novamente mais tarde.";
  exit;
}

/* ==================== AVATARES: paths ==================== */
$defaultsDir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar') ?: (__DIR__ . '/../assets/img/avatars/default_avatar');
$defaultsWeb = '/OKR_system/assets/img/avatars/default_avatar/';
$defaultFile = 'default.png';
if (!is_dir($defaultsDir)) @mkdir($defaultsDir, 0755, true);

/* ==================== HELPERS ==================== */
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
  foreach ($rows as $r) {
    if (isset($r[$k], $r[$v])) $out[(string)$r[$k]] = (string)$r[$v];
  }
  return $out;
}
function table_exists(PDO $pdo, string $table): bool {
  try {
    $q = $pdo->quote($table);
    $st = $pdo->query("SHOW TABLES LIKE {$q}");
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    error_log("profile_user.php: table_exists falhou para {$table}: " . $e->getMessage());
    return false;
  }
}function table_columns(PDO $pdo, string $table): array {
  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($st->fetchAll() as $r) $cols[] = (string)$r['Field'];
  } catch (Throwable $e) {}
  return $cols;
}
function pick_col(array $cols, array $cands, ?string $fallback = null): ?string {
  foreach ($cands as $c) {
    if (in_array($c, $cols, true)) return $c;
  }
  return $fallback;
}

/**
 * Detecta automaticamente a tabela/colunas do usuário e devolve um "map" padronizado.
 * Saída:
 *  - table, id_col, cols[primeiro_nome, ultimo_nome, telefone, email, dep, nivel, avatar_id]
 */
function detect_user_model(PDO $pdo): array {
  // 1) tabela principal
  $table = null;
  if (table_exists($pdo, 'usuarios')) $table = 'usuarios';
  elseif (table_exists($pdo, 'users')) $table = 'users';

  if (!$table) {
    return ['table' => null, 'id_col' => null, 'cols' => []];
  }

  $cols = table_columns($pdo, $table);

  // 2) coluna do ID
  $idCol = pick_col($cols, ['id_user','user_id','id','id_usuario'], null);

  // 3) nomes (candidatos comuns)
  $colPrimeiro = pick_col($cols, ['primeiro_nome','first_name','nome','name'], null);
  $colUltimo   = pick_col($cols, ['ultimo_nome','last_name','sobrenome','surname'], null);
  $colTel      = pick_col($cols, ['telefone','phone','celular','mobile'], null);
  $colEmail    = pick_col($cols, ['email_corporativo','email','mail','email_address'], null);

  $colDep      = pick_col($cols, ['id_departamento','departamento_id','department_id'], null);
  $colNivel    = pick_col($cols, ['id_nivel_cargo','nivel_id','cargo_id','role_level_id'], null);

  $colAvatarId = pick_col($cols, ['avatar_id','id_avatar','avatar'], null);

  return [
    'table'  => $table,
    'id_col' => $idCol,
    'cols'   => [
      'primeiro_nome' => $colPrimeiro,
      'ultimo_nome'   => $colUltimo,
      'telefone'      => $colTel,
      'email'         => $colEmail,
      'dep'           => $colDep,
      'nivel'         => $colNivel,
      'avatar_id'     => $colAvatarId,
    ],
  ];
}

/**
 * Carrega o usuário devolvendo sempre o array com chaves esperadas pelo HTML.
 */
function load_user(PDO $pdo, int $id_user, array $um): array {
  if (empty($um['table']) || empty($um['id_col'])) return [];

  $t  = $um['table'];
  $id = $um['id_col'];

  $c = $um['cols'];

  // Monta SELECT com aliases padronizados (se a coluna existir)
  $select = [];
  $select[] = "`u`.`{$id}` AS `id_user`";

  if (!empty($c['primeiro_nome'])) $select[] = "`u`.`{$c['primeiro_nome']}` AS `primeiro_nome`";
  if (!empty($c['ultimo_nome']))   $select[] = "`u`.`{$c['ultimo_nome']}` AS `ultimo_nome`";
  if (!empty($c['telefone']))      $select[] = "`u`.`{$c['telefone']}` AS `telefone`";
  if (!empty($c['email']))         $select[] = "`u`.`{$c['email']}` AS `email_corporativo`";
  if (!empty($c['dep']))           $select[] = "`u`.`{$c['dep']}` AS `id_departamento`";
  if (!empty($c['nivel']))         $select[] = "`u`.`{$c['nivel']}` AS `id_nivel_cargo`";
  if (!empty($c['avatar_id']))     $select[] = "`u`.`{$c['avatar_id']}` AS `avatar_id`";

  // Avatar filename (se tiver avatar_id)
  $join = "";
  $selAvatar = "";
  if (!empty($c['avatar_id']) && table_exists($pdo, 'avatars')) {
    $join = "LEFT JOIN avatars a ON a.id = u.`{$c['avatar_id']}`";
    $selAvatar = ", a.filename AS avatar_filename";
  }

  $sql = "SELECT " . implode(", ", $select) . $selAvatar . " FROM `{$t}` u {$join} WHERE u.`{$id}` = :id LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute([':id' => $id_user]);
  return $st->fetch() ?: [];
}

/**
 * Atualiza o usuário (save_profile) respeitando o modelo detectado.
 */
function update_user_profile(PDO $pdo, int $id_user, array $um, array $data): void {
  if (empty($um['table']) || empty($um['id_col'])) {
    throw new RuntimeException('Modelo de usuário não detectado.');
  }

  $t  = $um['table'];
  $id = $um['id_col'];
  $c  = $um['cols'];

  $sets = [];
  $params = [':id' => $id_user];

  if (!empty($c['primeiro_nome'])) {
    $sets[] = "`{$c['primeiro_nome']}` = :pn";
    $params[':pn'] = $data['primeiro_nome'];
  }
  if (!empty($c['ultimo_nome'])) {
    $sets[] = "`{$c['ultimo_nome']}` = :un";
    $params[':un'] = $data['ultimo_nome'];
  }
  if (!empty($c['telefone'])) {
    $sets[] = "`{$c['telefone']}` = :tel";
    $params[':tel'] = $data['telefone'];
  }
  if (!empty($c['dep'])) {
    $sets[] = "`{$c['dep']}` = :dep";
    $params[':dep'] = $data['id_departamento'];
  }
  if (!empty($c['nivel'])) {
    $sets[] = "`{$c['nivel']}` = :niv";
    $params[':niv'] = $data['id_nivel_cargo'];
  }

  // Campos de auditoria (se existirem)
  $cols = table_columns($pdo, $t);
  if (in_array('dt_alteracao', $cols, true)) $sets[] = "`dt_alteracao` = NOW()";
  if (in_array('id_user_alteracao', $cols, true)) {
    $sets[] = "`id_user_alteracao` = :alt";
    $params[':alt'] = $id_user;
  }

  if (!$sets) return; // nada pra atualizar

  $sql = "UPDATE `{$t}` SET " . implode(", ", $sets) . " WHERE `{$id}` = :id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

/* ==================== AJAX: lista de avatares ==================== */
if (($_GET['ajax'] ?? '') === 'avatar_list') {
  if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
  header('Content-Type: application/json; charset=utf-8');

  $filter = $_GET['filter'] ?? 'todos';
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
    error_log("profile_user.php: avatar_list AJAX falhou: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['items' => [], 'total' => 0, 'error' => 'Falha ao consultar avatares']);
  }
  exit;
}

/* ==================== MODELO DO USUÁRIO ==================== */
$um = detect_user_model($pdo);

/* ==================== DADOS DO USUÁRIO ==================== */
try {
  $user = load_user($pdo, $id_user, $um);
} catch (Throwable $e) {
  error_log("profile_user.php: Falha ao carregar usuário {$id_user}: " . $e->getMessage());
  $user = [];
}
$maskedEmail = mask_email_local((string)($user['email_corporativo'] ?? ''));

/* ========= Dicionários (Departamentos / Níveis) ========= */
function dict_options(PDO $pdo, string $table, array $idCandidates, array $labelCandidates, array $orderCandidates = ['ordem','posicao','nome','descricao','titulo','label'], string $where = '1') : array {
  $cacheKey = 'dict_v2:' . (defined('DB_NAME') ? DB_NAME : '') . ':' . $table . ':' . md5(json_encode([$idCandidates,$labelCandidates,$orderCandidates,$where]));
  if (function_exists('apcu_fetch')) {
    $cached = apcu_fetch($cacheKey);
    if ($cached !== false) return $cached;
  }

  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = array_map(function($r){ return $r['Field']; }, $st->fetchAll());
  } catch (Throwable $e) {}

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
    return [];
  }
}

$optsDepartamentos = table_exists($pdo, 'dom_departamentos')
  ? dict_options($pdo, 'dom_departamentos', ['id_departamento','id','codigo','cod'], ['nome','descricao','titulo','label','departamento','nome_departamento'])
  : [];

$optsNiveis = table_exists($pdo, 'dom_niveis_cargo')
  ? dict_options($pdo, 'dom_niveis_cargo', ['id_nivel_cargo','id_nivel','id_funcao','id_cargo','id'], ['descricao','nome','nivel','titulo','label','funcao','cargo','nome_nivel'])
  : [];

/* ==================== FLASH (PRG) ==================== */
$success = $_SESSION['success_message'] ?? '';
$errors  = $_SESSION['error_messages'] ?? [];
unset($_SESSION['success_message'], $_SESSION['error_messages']);

/* ==================== POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $postedToken)) {
    $_SESSION['error_messages'][] = 'Falha de segurança (CSRF). Recarregue a página.';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'reset_password') {
    try {
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

      try {
        if (function_exists('rateLimitResetRequestOrFail')) {
          rateLimitResetRequestOrFail($pdo, $id_user, $ipReq);
        }
      } catch (Throwable $e) {}

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

      // e-mail do user
      $to = (string)($user['email_corporativo'] ?? '');
      if (!$to) {
        // tenta recarregar (caso $user tenha vindo vazio)
        $tmp = load_user($pdo, $id_user, $um);
        $to = (string)($tmp['email_corporativo'] ?? '');
      }

      if ($to && sendPasswordResetEmail($to, $selector, $verifier)) {
        $_SESSION['success_message'] =
          "Enviamos um link de <strong>alteração de senha</strong> para <strong>" .
          htmlspecialchars(mask_email_local($to), ENT_QUOTES, 'UTF-8') . "</strong>.";
      } else {
        $_SESSION['error_messages'][] = 'Não foi possível enviar o e-mail de alteração de senha.';
      }
    } catch (Throwable $e) {
      error_log("profile_user.php: reset_password falhou: " . $e->getMessage());
      $_SESSION['error_messages'][] = 'Erro ao solicitar alteração de senha.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  if ($action === 'remove_avatar') {
    try {
      // Só faz algo se o modelo tem avatar_id e a tabela avatars existe
      if (!empty($um['cols']['avatar_id']) && table_exists($pdo, 'avatars')) {
        $defaultAvatarId = null;
        $q = $pdo->prepare("SELECT id FROM avatars WHERE filename = :fn AND active = 1 LIMIT 1");
        $q->execute([':fn' => 'default.png']);
        $tmp = $q->fetchColumn();
        if ($tmp !== false) $defaultAvatarId = (int)$tmp;

        $t  = $um['table'];
        $id = $um['id_col'];
        $avCol = $um['cols']['avatar_id'];

        if ($defaultAvatarId !== null) {
          $st = $pdo->prepare("UPDATE `{$t}` SET `{$avCol}` = :defid WHERE `{$id}` = :id");
          $st->execute([':defid' => $defaultAvatarId, ':id' => $id_user]);
        } else {
          $st = $pdo->prepare("UPDATE `{$t}` SET `{$avCol}` = NULL WHERE `{$id}` = :id");
          $st->execute([':id' => $id_user]);
        }
      }

      $_SESSION['avatar_filename'] = 'default.png';
      $_SESSION['success_message'] = 'Avatar redefinido para o padrão.';
    } catch (Throwable $e) {
      error_log("profile_user.php: remove_avatar falhou: " . $e->getMessage());
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

      if (!empty($um['cols']['avatar_id'])) {
        $t  = $um['table'];
        $id = $um['id_col'];
        $avCol = $um['cols']['avatar_id'];

        $st = $pdo->prepare("UPDATE `{$t}` SET `{$avCol}` = :aid WHERE `{$id}` = :id");
        $st->execute([':aid' => (int)$row['id'], ':id' => $id_user]);
      }

      $_SESSION['avatar_filename'] = $filename;
      $_SESSION['success_message'] = 'Avatar aplicado com sucesso.';
    } catch (Throwable $e) {
      error_log("profile_user.php: choose_avatar falhou: " . $e->getMessage());
      $_SESSION['error_messages'][] = 'Falha ao aplicar avatar.';
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  if ($action === 'save_profile') {
    $pn  = trim((string)($_POST['primeiro_nome'] ?? ''));
    $un  = trim((string)($_POST['ultimo_nome'] ?? ''));
    $tel = trim((string)($_POST['telefone'] ?? ''));

    $idDept  = isset($_POST['id_departamento']) && $_POST['id_departamento'] !== '' ? (int)$_POST['id_departamento'] : null;
    $idNivel = isset($_POST['id_nivel_cargo']) && $_POST['id_nivel_cargo'] !== '' ? (int)$_POST['id_nivel_cargo'] : null;

    if ($pn === '') {
      $_SESSION['error_messages'][] = 'Primeiro nome é obrigatório.';
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
      exit;
    }

    // valida contra dicionários (se existirem)
    if ($optsDepartamentos && $idDept !== null && !isset($optsDepartamentos[(string)$idDept])) $idDept = null;
    if ($optsNiveis && $idNivel !== null && !isset($optsNiveis[(string)$idNivel])) $idNivel = null;

    try {
      $pdo->beginTransaction();

      update_user_profile($pdo, $id_user, $um, [
        'primeiro_nome'    => $pn,
        'ultimo_nome'      => ($un !== '' ? $un : null),
        'telefone'         => ($tel !== '' ? $tel : null),
        'id_departamento'  => $idDept,
        'id_nivel_cargo'   => $idNivel,
      ]);

      $pdo->commit();

      $_SESSION['success_message'] = 'Perfil salvo com sucesso.';
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log("profile_user.php: save_profile falhou: " . $e->getMessage());
      $_SESSION['error_messages'][] = 'Erro ao salvar perfil.';
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
    exit;
  }

  $_SESSION['error_messages'][] = 'Ação inválida.';
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#?'));
  exit;
}

/* ==================== RECARREGA (GET pós-PRG) ==================== */
try {
  $user = load_user($pdo, $id_user, $um);
} catch (Throwable $e) {
  $user = [];
}

/* ==================== FORMATA TELEFONE ==================== */
$raw = (string)($user['telefone'] ?? '');
$d = preg_replace('/\D+/', '', $raw);
if (strpos($d, '55') === 0 && strlen($d) > 10) $d = substr($d, 2);
$telFmt = (strlen($d) === 11)
  ? sprintf('(%s) %s-%s', substr($d,0,2), substr($d,2,5), substr($d,7,4))
  : $raw;

/* ==================== AVATAR ATUAL ==================== */
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
$maskedEmail = mask_email_local((string)($user['email_corporativo'] ?? ''));
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
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{ --soft:#0d1117; }
    body{ background:#fff !important; color:#111; }
    .content{ background: transparent; }
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
    .form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:700px){ .form-grid{ grid-template-columns:1fr; } }
    .form-group{ display:flex; flex-direction:column; gap:6px; }
    .form-group label{ font-weight:700; color:#d1d5db; }
    .form-control, .form-select{ background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:12px; padding:10px 12px; outline:none; }
    .form-control:focus, .form-select:focus{ border-color:#334155; box-shadow:0 0 0 3px rgba(148,163,184,.15); }
    .split{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:900px){ .split{ grid-template-columns:1fr; } }
    .security-block{ background:#0c1118; border:1px solid #1f2635; border-radius:14px; padding:16px; }
    .security-content{ display:flex; flex-direction:column; gap:10px; align-items:flex-start; }
    .security-desc{ color:#cbd5e1; font-size:.95rem; line-height:1.45; }
    .security-desc strong{ color:#fff; display:block; margin-bottom:4px; }
    .security-actions .btn{ width:auto; }
    @media (max-width:700px){ .security-actions .btn{ width:100%; justify-content:center; } }
    .form-actions{ margin-top:16px; display:flex; justify-content:flex-end; }
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
              <li><?= htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="profile-grid">
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
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="remove_avatar">
                  <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-user-slash"></i> Remover avatar
                  </button>
                </form>
              </div>
            </div>

            <hr style="border:none; border-top:1px solid var(--border);">

            <div class="security-block" role="group" aria-label="Alterar senha">
              <div class="security-content">
                <div class="security-desc">
                  <strong>Alterar senha</strong>
                  <span>Enviaremos um link de alteração para o seu e-mail corporativo <?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8') ?>.</span>
                </div>
                <div class="security-actions">
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
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

        <section class="card-dk">
          <header>
            <h2 class="section-title"><span class="badge">Dados</span> Informações de usuário</h2>
          </header>
          <div class="card-body">
            <form method="post" id="profileForm" novalidate>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save_profile">

              <div class="form-grid">
                <div class="form-group">
                  <label for="primeiro_nome">Primeiro nome *</label>
                  <input id="primeiro_nome" name="primeiro_nome" class="form-control" required
                         value="<?= htmlspecialchars((string)($user['primeiro_nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                  <label for="ultimo_nome">Último nome</label>
                  <input id="ultimo_nome" name="ultimo_nome" class="form-control"
                         value="<?= htmlspecialchars((string)($user['ultimo_nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>

              <div class="split">
                <div class="form-group">
                  <label for="telefone">Telefone (WhatsApp)</label>
                  <input id="telefone" name="telefone" class="form-control"
                         value="<?= htmlspecialchars((string)$telFmt, ENT_QUOTES, 'UTF-8') ?>" placeholder="(XX) 9XXXX-XXXX">
                </div>
                <div class="form-group">
                  <label>E-mail corporativo</label>
                  <input class="form-control" value="<?= htmlspecialchars((string)($user['email_corporativo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                </div>
              </div>

              <div class="form-grid">
                <div class="form-group">
                  <label for="id_departamento">Departamento</label>
                  <select id="id_departamento" name="id_departamento" class="form-select">
                    <option value="">Selecione</option>
                    <?php foreach ($optsDepartamentos as $id=>$nome): ?>
                      <option value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>" <?= ((string)($user['id_departamento'] ?? '') === (string)$id ? 'selected':'') ?>>
                        <?= htmlspecialchars((string)$nome, ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label for="id_nivel_cargo">Nível/Cargo</label>
                  <select id="id_nivel_cargo" name="id_nivel_cargo" class="form-select">
                    <option value="">Selecione</option>
                    <?php foreach ($optsNiveis as $id=>$label): ?>
                      <option value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>" <?= ((string)($user['id_nivel_cargo'] ?? '') === (string)$id ? 'selected':'') ?>>
                        <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
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

  <div class="modal-backdrop" id="avatarModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="avatarModalTitle">
      <header>
        <h3 id="avatarModalTitle">Alterar avatar</h3>
        <button type="button" class="btn-link" id="avatarModalClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <form id="avatarModalForm" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
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