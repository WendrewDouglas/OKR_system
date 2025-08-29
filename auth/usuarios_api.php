<?php
// auth/usuarios_api.php — CRUD de usuários + opções + avatar (upload e canvas)
// Regras:
// - Master (aprovadores.tudo=1 e habilitado=1) pode escolher qualquer company e editar tudo
// - Não master: só pode atribuir sua própria company e não altera papéis/páginas de outros
// - Upload de avatar (arquivo) OU receber PNG via canvas (dataURL)

declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/* ---------- helpers ---------- */
function jexit(int $code, array $payload){
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $q->execute([$table]);
  return (bool)$q->fetchColumn();
}
function get_my_company(PDO $pdo, int $uid): ?int {
  $s=$pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
  $s->execute([$uid]);
  $r=$s->fetch();
  return $r && $r['id_company']!==null ? (int)$r['id_company'] : null;
}

/* ---------- auth ---------- */
if (!isset($_SESSION['user_id'])) jexit(401, ['success'=>false,'error'=>'Não autenticado']);
$MEU_ID = (int)$_SESSION['user_id'];

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  jexit(500, ['success'=>false,'error'=>$e->getMessage()]);
}

/* ---------- master? ---------- */
$st = $pdo->prepare("SELECT tudo, habilitado FROM aprovadores WHERE id_user=? LIMIT 1");
$st->execute([$MEU_ID]);
$row = $st->fetch();
$IS_MASTER = $row && (int)$row['habilitado']===1 && (int)$row['tudo']===1;

/* ---------- roteamento ---------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

/* CSRF para POST */
if ($method==='POST') {
  $sess = $_SESSION['csrf_token'] ?? '';
  $sent = $_POST['csrf_token'] ?? '';
  if (!$sess || !$sent || !hash_equals($sess, $sent)) jexit(403, ['success'=>false,'error'=>'CSRF inválido']);
}

/* =========================================================
 * OPTIONS — empresas, papéis e páginas
 * =======================================================*/
if ($method === 'GET' && $action === 'options') {
  $myCompanyId = get_my_company($pdo, $MEU_ID);
  $nameExpr = "COALESCE(organizacao, razao_social, CONCAT('Empresa #', id_company))";

  try {
    if ($IS_MASTER) {
      $companies = $pdo->query("SELECT id_company, $nameExpr AS nome FROM company ORDER BY nome")->fetchAll();
    } else {
      $st = $pdo->prepare("SELECT id_company, $nameExpr AS nome FROM company WHERE id_company=? ORDER BY nome");
      $st->execute([(int)($myCompanyId ?? 0)]);
      $companies = $st->fetchAll();
    }
  } catch (Throwable $e) {
    $companies = [];
  }

  if ((!$companies || !count($companies)) && $myCompanyId) {
    $companies = [[ 'id_company'=>(int)$myCompanyId, 'nome'=>'Minha organização (#'.$myCompanyId.')' ]];
  }

  $roles = table_exists($pdo,'dom_permissoes')
    ? $pdo->query("SELECT chave_dominio AS id, descricao FROM dom_permissoes ORDER BY id_dominio")->fetchAll()
    : [];

  $pages = table_exists($pdo,'dom_paginas')
    ? $pdo->query("SELECT id_pagina AS id, descricao, path FROM dom_paginas WHERE ativo=1 ORDER BY descricao")->fetchAll()
    : [];

  jexit(200, [
    'success'    => true,
    'companies'  => $companies,
    'roles'      => $roles,
    'pages'      => $pages,
    'is_master'  => $IS_MASTER,
    'my_company' => $myCompanyId
  ]);
}

/* =========================================================
 * LIST — listagem com filtros, paginação e segurança
 * Compatível com ONLY_FULL_GROUP_BY (sem GROUP BY/HAVING)
 * Retorna 'users' e também 'items' (compat)
 * Parâmetros: q, role, company, page, per_page
 * =======================================================*/
if ($method === 'GET' && $action === 'list') {
  try {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    $q       = trim((string)($_GET['q'] ?? ''));
    $roleIn  = strtolower(trim((string)($_GET['role'] ?? 'all')));
    $compIn  = strtolower(trim((string)($_GET['company'] ?? 'all')));
    $debug   = ($_GET['debug'] ?? '') === '1';

    $params = [];
    $conds  = [];

    // Escopo de org para não-master
    if (!$IS_MASTER) {
      $mc = get_my_company($pdo, $MEU_ID);
      if ($mc) { $conds[] = "u.id_company = ?"; $params[] = (int)$mc; }
    } else {
      if ($compIn !== '' && $compIn !== 'all') {
        $conds[] = "u.id_company = ?"; $params[] = (int)$compIn;
      }
    }

    if ($q !== '') {
      $conds[] = "(u.primeiro_nome LIKE ? OR u.ultimo_nome LIKE ? OR u.email_corporativo LIKE ? OR u.telefone LIKE ?)";
      $like = "%{$q}%"; array_push($params, $like,$like,$like,$like);
    }

    if ($roleIn !== '' && $roleIn !== 'all') {
      $conds[] = "EXISTS (SELECT 1 FROM usuarios_permissoes upf WHERE upf.id_user = u.id_user AND upf.id_permissao = ?)";
      $params[] = $_GET['role']; // mantém o valor original
    }

    $whereSql = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';
    $companyName = "COALESCE(c.organizacao, c.razao_social, CONCAT('Empresa #', c.id_company))";

    // ----- total
    $sqlCount = "SELECT COUNT(*) FROM usuarios u LEFT JOIN company c ON c.id_company=u.id_company $whereSql";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // ----- lista
    $sqlList = "
      SELECT
        u.id_user,
        u.primeiro_nome,
        COALESCE(u.ultimo_nome,'') AS ultimo_nome,
        u.email_corporativo,
        u.telefone,
        u.id_company,
        $companyName AS company_name,
        (SELECT GROUP_CONCAT(up.id_permissao ORDER BY up.id_permissao SEPARATOR ',')
           FROM usuarios_permissoes up WHERE up.id_user = u.id_user) AS roles_csv
      FROM usuarios u
      LEFT JOIN company c ON c.id_company = u.id_company
      $whereSql
      ORDER BY u.id_user DESC
      LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sqlList);
    // bind dos parâmetros do WHERE (posicionais)
    $pos = 1;
    foreach ($params as $p) {
      $stmt->bindValue($pos++, $p);
    }
    // bind LIMIT/OFFSET como inteiros (ESSENCIAL)
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos++, $offset,  PDO::PARAM_INT);

    $stmt->execute();
    $rows = $stmt->fetchAll();

    // ----- fallback: se total==0, traz 100 sem filtro
    if ($total === 0) {
      $stmt2 = $pdo->query("
        SELECT
          u.id_user,
          u.primeiro_nome,
          COALESCE(u.ultimo_nome,'') AS ultimo_nome,
          u.email_corporativo,
          u.telefone,
          u.id_company,
          COALESCE(c.organizacao, c.razao_social, CONCAT('Empresa #', c.id_company)) AS company_name,
          (SELECT GROUP_CONCAT(up.id_permissao ORDER BY up.id_permissao SEPARATOR ',')
             FROM usuarios_permissoes up WHERE up.id_user = u.id_user) AS roles_csv
        FROM usuarios u
        LEFT JOIN company c ON c.id_company = u.id_company
        ORDER BY u.id_user DESC
        LIMIT 100
      ");
      $rows  = $stmt2->fetchAll();
      $total = is_array($rows) ? count($rows) : 0;
    }

    $users = array_map(function($r) use ($IS_MASTER, $MEU_ID){
      $roles = array_values(array_filter(array_map('trim', explode(',', (string)($r['roles_csv'] ?? '')))));
      return [
        'id_user'           => (int)$r['id_user'],
        'primeiro_nome'     => $r['primeiro_nome'],
        'ultimo_nome'       => $r['ultimo_nome'],
        'email_corporativo' => $r['email_corporativo'],
        'telefone'          => $r['telefone'],
        'id_company'        => $r['id_company'] !== null ? (int)$r['id_company'] : null,
        'company_name'      => $r['company_name'],
        'roles'             => $roles,
        'can_edit'          => $IS_MASTER || ((int)$r['id_user'] === $MEU_ID),
        'can_delete'        => $IS_MASTER && (int)$r['id_user'] !== $MEU_ID && (int)$r['id_user'] !== 1,
      ];
    }, $rows ?: []);

    $out = [
      'success'=>true,
      'users'=>$users,
      'items'=>$users,
      'total'=>$total,
      'page'=>$page,
      'per_page'=>$perPage
    ];
    if ($debug) {
      $out['_debug'] = [
        'sql_count'=>$sqlCount,
        'sql_list'=>$sqlList,
        'params_where'=>$params,
        'limit'=>$perPage, 'offset'=>$offset
      ];
    }
    jexit(200, $out);

  } catch (Throwable $e) {
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* =========================================================
 * GET — buscar um usuário por ID (com roles/pages)
 * =======================================================*/
if ($method === 'GET' && $action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);

  // Restrição de escopo para não-master
  if (!$IS_MASTER) {
    $myc = get_my_company($pdo, $MEU_ID);
    $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
    $st->execute([$id]);
    $targetCompany = $st->fetchColumn();
    if ($targetCompany === false || ($myc !== null && (int)$targetCompany !== (int)$myc)) {
      jexit(403, ['success'=>false,'error'=>'Sem permissão para consultar este usuário.']);
    }
  }

  $st=$pdo->prepare("SELECT id_user, primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome,
                            email_corporativo, telefone, empresa, id_company
                     FROM usuarios WHERE id_user=?");
  $st->execute([$id]);
  $u=$st->fetch();
  if (!$u) jexit(404, ['success'=>false,'error'=>'Usuário não encontrado']);

  $avatarPath = null;
  foreach(['png','jpg','jpeg'] as $ext){
    $p = __DIR__.'/../assets/img/avatars/'.$id.'.'.$ext;
    if (file_exists($p)){ $avatarPath = '/OKR_system/assets/img/avatars/'.$id.'.'.$ext; break; }
  }

  $roles = [];
  if (table_exists($pdo,'usuarios_permissoes')) {
    $qq = $pdo->prepare("SELECT id_permissao AS id FROM usuarios_permissoes WHERE id_user=?");
    $qq->execute([$id]);
    $roles = array_column($qq->fetchAll()?:[], 'id');
  }

  $pages = [];
  if (table_exists($pdo,'usuarios_paginas')) {
    $qq = $pdo->prepare("SELECT id_pagina FROM usuarios_paginas WHERE id_user=?");
    $qq->execute([$id]);
    $pages = array_column($qq->fetchAll()?:[], 'id_pagina');
  }

  jexit(200, ['success'=>true,'user'=>$u,'roles'=>$roles,'pages'=>$pages,'avatar'=>$avatarPath]);
}

/* =========================================================
 * SAVE — create/update (com regras de permissão)
 * =======================================================*/
if ($method === 'POST' && $action === 'save') {
  // ---------- Input ----------
  $id         = (int)($_POST['id_user'] ?? 0);
  $primeiro   = trim((string)($_POST['primeiro_nome'] ?? ''));
  $ultimo     = trim((string)($_POST['ultimo_nome'] ?? ''));
  $email      = trim((string)($_POST['email_corporativo'] ?? ''));
  $tel        = trim((string)($_POST['telefone'] ?? ''));
  $rawCompany = $_POST['id_company'] ?? null;

  // arrays
  $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? array_values(array_unique(array_map('strval', $_POST['roles']))) : [];
  $pages = isset($_POST['pages']) && is_array($_POST['pages']) ? array_values(array_unique(array_map('strval', $_POST['pages']))) : [];

  // ---------- Validações ----------
  if ($primeiro === '' || $email === '') jexit(422, ['success'=>false,'error'=>'Nome e e-mail são obrigatórios.']);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jexit(422, ['success'=>false,'error'=>'E-mail inválido.']);
  if (mb_strlen($primeiro) > 100 || mb_strlen($ultimo) > 100) jexit(422, ['success'=>false,'error'=>'Nome/Sobrenome muito longos.']);
  if (mb_strlen($email) > 150) jexit(422, ['success'=>false,'error'=>'E-mail excede 150 caracteres.']);
  if ($tel !== '' && mb_strlen($tel) > 30) jexit(422, ['success'=>false,'error'=>'Telefone excede 30 caracteres.']);

  // ---------- Normalização de company + regra de permissão ----------
  $id_company = (int)($rawCompany ?? 0);
  $id_company = $id_company > 0 ? $id_company : null;

  if (!$IS_MASTER) {
    $myCompanyId = get_my_company($pdo, $MEU_ID);
    if ($myCompanyId > 0) {
      $id_company = $myCompanyId;
    } else {
      jexit(422, ['success'=>false,'error'=>'Seu usuário não está vinculado a nenhuma organização. Peça a um administrador para associá-lo antes de prosseguir.']);
    }
    // Não-master não pode conceder admin_master
    $roles = array_values(array_filter($roles, fn($r)=> strtolower($r) !== 'admin_master'));
  }

  // ---------- Evita e-mail duplicado ----------
  $chk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email_corporativo = ? AND id_user <> ?");
  $chk->execute([$email, $id]);
  if ((int)$chk->fetchColumn() > 0) jexit(422, ['success'=>false,'error'=>'Já existe um usuário com este e-mail.']);

  // ---------- Persistência ----------
  $pdo->beginTransaction();
  try {
    if ($id > 0) {
      // Escopo para não-master
      if (!$IS_MASTER) {
        $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
        $st->execute([$id]);
        $targetCompany = (int)($st->fetchColumn() ?: 0);
        $myc = (int)(get_my_company($pdo,$MEU_ID) ?: 0);
        if ($targetCompany !== $myc && $id !== $MEU_ID) {
          $pdo->rollBack();
          jexit(403,['success'=>false,'error'=>'Sem permissão para editar este usuário.']);
        }
      }

      $canChangeAcl = $IS_MASTER || $id === (int)$MEU_ID;

      $st = $pdo->prepare("
        UPDATE usuarios
           SET primeiro_nome = :p,
               ultimo_nome   = :u,
               email_corporativo = :e,
               telefone      = :t,
               id_company    = :c,
               dt_alteracao  = NOW(),
               id_user_alteracao = :me
         WHERE id_user = :id
      ");
      $st->execute([
        ':p'=>$primeiro, ':u'=>$ultimo, ':e'=>$email, ':t'=>$tel,
        ':c'=>$id_company, ':me'=>$MEU_ID, ':id'=>$id
      ]);

      if ($canChangeAcl) {
        if (table_exists($pdo,'usuarios_permissoes')) {
          $pdo->prepare("DELETE FROM usuarios_permissoes WHERE id_user=?")->execute([$id]);
          if (!empty($roles)) {
            $ins=$pdo->prepare("INSERT INTO usuarios_permissoes (id_user,id_permissao) VALUES (?,?)");
            foreach($roles as $r){ $ins->execute([$id,(string)$r]); }
          }
        }
        if (table_exists($pdo,'usuarios_paginas')) {
          $pdo->prepare("DELETE FROM usuarios_paginas WHERE id_user=?")->execute([$id]);
          if (!empty($pages)) {
            $ins=$pdo->prepare("INSERT INTO usuarios_paginas (id_user,id_pagina) VALUES (?,?)");
            foreach($pages as $p){ $ins->execute([$id,(string)$p]); }
          }
        }
      }
    } else {
      // INSERT
      $st = $pdo->prepare("
        INSERT INTO usuarios
          (primeiro_nome, ultimo_nome, email_corporativo, telefone, id_company, dt_cadastro, ip_criacao, id_user_criador)
        VALUES
          (:p, :u, :e, :t, :c, NOW(), :ip, :me)
      ");
      $ip = $_SERVER['REMOTE_ADDR'] ?? null;
      $st->execute([
        ':p'=>$primeiro, ':u'=>$ultimo, ':e'=>$email, ':t'=>$tel,
        ':c'=>$id_company, ':ip'=>$ip, ':me'=>$MEU_ID
      ]);
      $id = (int)$pdo->lastInsertId();

      if (table_exists($pdo,'usuarios_permissoes') && !empty($roles)) {
        $ins=$pdo->prepare("INSERT INTO usuarios_permissoes (id_user,id_permissao) VALUES (?,?)");
        foreach($roles as $r){ $ins->execute([$id,(string)$r]); }
      }
      if (table_exists($pdo,'usuarios_paginas') && !empty($pages)) {
        $ins=$pdo->prepare("INSERT INTO usuarios_paginas (id_user,id_pagina) VALUES (?,?)");
        foreach($pages as $p){ $ins->execute([$id,(string)$p]); }
      }
    }

    $pdo->commit();
    jexit(200, ['success'=>true,'id_user'=>$id]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* =========================================================
 * DELETE — somente master (não pode excluir a si mesmo nem #1)
 * =======================================================*/
if ($method === 'POST' && $action === 'delete') {
  if (!$IS_MASTER) jexit(403, ['success'=>false,'error'=>'Apenas admin master pode excluir usuários.']);
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);
  if ($id === $MEU_ID) jexit(422, ['success'=>false,'error'=>'Você não pode excluir a si mesmo.']);
  if ($id === 1) jexit(422, ['success'=>false,'error'=>'Usuário #1 não pode ser excluído.']);

  $pdo->beginTransaction();
  try {
    if (table_exists($pdo,'usuarios_permissoes'))      $pdo->prepare("DELETE FROM usuarios_permissoes      WHERE id_user=?")->execute([$id]);
    if (table_exists($pdo,'usuarios_paginas'))         $pdo->prepare("DELETE FROM usuarios_paginas         WHERE id_user=?")->execute([$id]);
    if (table_exists($pdo,'usuarios_planos'))          $pdo->prepare("DELETE FROM usuarios_planos          WHERE id_user=?")->execute([$id]);
    if (table_exists($pdo,'usuarios_credenciais'))     $pdo->prepare("DELETE FROM usuarios_credenciais     WHERE id_user=?")->execute([$id]);
    if (table_exists($pdo,'usuarios_password_resets')) $pdo->prepare("DELETE FROM usuarios_password_resets WHERE id_user=?")->execute([$id]);

    $st = $pdo->prepare("DELETE FROM usuarios WHERE id_user=?");
    $st->execute([$id]);

    $pdo->commit();
    jexit(200, ['success'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* =========================================================
 * UPLOAD AVATAR — arquivo
 * =======================================================*/
if ($method === 'POST' && $action === 'upload_avatar') {
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);
  if (!isset($_FILES['avatar']) || $_FILES['avatar']['error']!==UPLOAD_ERR_OK) jexit(400, ['success'=>false,'error'=>'Falha no upload']);

  $tmp  = $_FILES['avatar']['tmp_name'];
  $info = @getimagesize($tmp);
  if (!$info) jexit(415, ['success'=>false,'error'=>'Arquivo não é imagem']);
  $ext = image_type_to_extension($info[2], false);
  if (!in_array(strtolower($ext), ['png','jpg','jpeg'])) $ext='png';

  $dir = __DIR__.'/../assets/img/avatars';
  if (!is_dir($dir)) @mkdir($dir,0775,true);
  $real = realpath($dir);
  if (!$real) jexit(500, ['success'=>false,'error'=>'Diretório de avatars indisponível']);

  foreach(['png','jpg','jpeg'] as $e){ $p=$real.DIRECTORY_SEPARATOR.$id.'.'.$e; if (file_exists($p)) @unlink($p); }
  $dest = $real.DIRECTORY_SEPARATOR.$id.'.'.$ext;

  if (!move_uploaded_file($tmp,$dest)) jexit(500, ['success'=>false,'error'=>'Não foi possível salvar o avatar']);
  jexit(200, ['success'=>true,'path'=>'/OKR_system/assets/img/avatars/'.$id.'.'.$ext]);
}

/* =========================================================
 * SAVE AVATAR CANVAS — recebe dataURL PNG
 * =======================================================*/
if ($method === 'POST' && $action === 'save_avatar_canvas') {
  $id = (int)($_POST['id_user'] ?? 0);
  $data = (string)($_POST['data_url'] ?? '');
  if ($id<=0 || strpos($data,'data:image/png;base64,')!==0) jexit(400, ['success'=>false,'error'=>'Dados inválidos']);
  $bin = base64_decode(substr($data, strlen('data:image/png;base64,')));
  if ($bin===false) jexit(400, ['success'=>false,'error'=>'Base64 inválido']);

  $dir = __DIR__.'/../assets/img/avatars';
  if (!is_dir($dir)) @mkdir($dir,0775,true);
  $real = realpath($dir);
  if (!$real) jexit(500, ['success'=>false,'error'=>'Diretório de avatars indisponível']);

  foreach(['png','jpg','jpeg'] as $e){ $p=$real.DIRECTORY_SEPARATOR.$id.'.'.$e; if (file_exists($p)) @unlink($p); }
  $dest = $real.DIRECTORY_SEPARATOR.$id.'.png';
  if (file_put_contents($dest,$bin)===false) jexit(500, ['success'=>false,'error'=>'Falha ao gravar PNG']);

  jexit(200, ['success'=>true,'path'=>'/OKR_system/assets/img/avatars/'.$id.'.png']);
}

/* fallback */
jexit(400, ['success'=>false,'error'=>'Ação inválida']);
