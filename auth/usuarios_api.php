<?php
// auth/usuarios_api.php — CRUD de usuários + RBAC (roles/overrides) + avatar
// Endpoints aceitos pelo front: options, list, get_user, save_user, delete,
// capabilities, get_permissions, save_permissions, roles_matrix (stub),
// departamentos (por company), niveis_cargo (catálogo)
// (aliases para compat: get/save + departamentos_by_company/cargos_niveis/niveis)

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../auth/acl.php';

/* ----------------------- Helpers ----------------------- */
function jexit(int $code, array $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  try {
    $pdo = new PDO(
      'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
      DB_USER, DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
    return $pdo;
  } catch (Throwable $e) {
    jexit(500, ['success'=>false,'error'=>'DB: '.$e->getMessage()]);
  }
}

function table_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$name]);
  return (bool)$st->fetchColumn();
}
function view_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$name]);
  return (bool)$st->fetchColumn();
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table,$column]);
  return (bool)$st->fetchColumn();
}
function first_existing_table(PDO $pdo, array $candidates): ?string {
  foreach ($candidates as $t) if (table_exists($pdo,$t)) return $t;
  return null;
}
function first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (column_exists($pdo,$table,$c)) return $c;
  return null;
}

function get_my_company(PDO $pdo, int $uid): ?int {
  $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
  $st->execute([$uid]);
  $v = $st->fetchColumn();
  return $v !== false && $v !== null ? (int)$v : null;
}

/* Admin master via tabela aprovadores (habilitado=1, tudo=1) */
function is_master(PDO $pdo, int $uid): bool {
  if (!table_exists($pdo, 'aprovadores')) return false;
  $st = $pdo->prepare("SELECT tudo, habilitado FROM aprovadores WHERE id_user=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch();
  return $row && (int)$row['habilitado']===1 && (int)$row['tudo']===1;
}

function company_name_expr(): string {
  // usa alias "c" quando a tabela company existir
  return "COALESCE(c.organizacao, c.razao_social, CONCAT('Empresa #', c.id_company))";
}

function avatar_public_path(int $id): ?string {
  $base = __DIR__ . '/../assets/img/avatars/';
  foreach (['png','jpg','jpeg'] as $ext) {
    $p = $base . $id . '.' . $ext;
    if (file_exists($p)) return '/OKR_system/assets/img/avatars/'.$id.'.'.$ext;
  }
  return null;
}

/* v_user_access_summary: retorna ['consulta_R'=>..., 'edicao_W'=>...] ou '—' */
function fetch_access_summary(PDO $pdo, int $userId): array {
  if (view_exists($pdo, 'v_user_access_summary')) {
    $s = $pdo->prepare("SELECT consulta_R, edicao_W FROM v_user_access_summary WHERE user_id=?");
    $s->execute([$userId]);
    $row = $s->fetch();
    if ($row) return ['consulta_R'=>$row['consulta_R'] ?: '—', 'edicao_W'=>$row['edicao_W'] ?: '—'];
  }
  return ['consulta_R'=>'—','edicao_W'=>'—'];
}

/* Resolve roles[] podendo vir como role_id (int) ou role_key (string) */
function resolve_role_ids(PDO $pdo, array $rolesAny): array {
  if (!$rolesAny) return [];
  $nums = []; $keys = [];
  foreach ($rolesAny as $r) {
    if (is_numeric($r)) $nums[] = (int)$r; else $keys[] = (string)$r;
  }
  $ids = [];
  if ($nums) {
    $in = implode(',', array_fill(0, count($nums), '?'));
    $st = $pdo->prepare("SELECT role_id FROM rbac_roles WHERE role_id IN ($in)");
    $st->execute($nums);
    $ids = array_merge($ids, array_map('intval', array_column($st->fetchAll(), 'role_id')));
  }
  if ($keys) {
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT role_id FROM rbac_roles WHERE role_key IN ($in)");
    $st->execute($keys);
    $ids = array_merge($ids, array_map('intval', array_column($st->fetchAll(), 'role_id')));
  }
  return array_values(array_unique($ids));
}

/* Busca roles do usuário (keys para chips) */
function fetch_user_role_keys(PDO $pdo, int $userId): array {
  if (!table_exists($pdo,'rbac_user_role') || !table_exists($pdo,'rbac_roles')) return [];
  $sql = "SELECT r.role_key
          FROM rbac_user_role ur
          JOIN rbac_roles r ON r.role_id = ur.role_id
          WHERE ur.user_id = ?
          ORDER BY r.role_key";
  $st = $pdo->prepare($sql);
  $st->execute([$userId]);
  return array_values(array_map('strval', array_column($st->fetchAll(), 'role_key')));
}

/* Busca roles do usuário (ids para formulário/perms) */
function fetch_user_role_ids(PDO $pdo, int $userId): array {
  if (!table_exists($pdo,'rbac_user_role')) return [];
  $st = $pdo->prepare("SELECT role_id FROM rbac_user_role WHERE user_id=? ORDER BY role_id");
  $st->execute([$userId]);
  return array_values(array_map('intval', array_column($st->fetchAll(), 'role_id')));
}

/* Overrides do usuário */
function fetch_user_overrides(PDO $pdo, int $userId): array {
  if (!table_exists($pdo,'rbac_user_capability')) return [];
  $st = $pdo->prepare("SELECT capability_id, effect FROM rbac_user_capability WHERE user_id=? ORDER BY capability_id");
  $st->execute([$userId]);
  $rows = $st->fetchAll();
  return array_map(fn($r)=> ['capability_id'=>(int)$r['capability_id'], 'effect'=>$r['effect']], $rows ?: []);
}

/* ===== Helpers para DELETE “inteligente” (detectar FK do usuário) ===== */
function user_fk_column(PDO $pdo, string $table): ?string {
  $candidates = ['id_user','user_id','id_usuario','usuario_id'];
  $in = implode(',', array_fill(0, count($candidates), '?'));
  $st = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME IN ($in)
    LIMIT 1
  ");
  $st->execute(array_merge([$table], $candidates));
  $col = $st->fetchColumn();
  return $col ? (string)$col : null;
}
function safe_delete_user_rows(PDO $pdo, string $table, int $uid): void {
  if (!table_exists($pdo, $table)) return;
  $col = user_fk_column($pdo, $table);
  if (!$col) return;
  $sql = "DELETE FROM `$table` WHERE `$col` = ?";
  $pdo->prepare($sql)->execute([$uid]);
}

/* ----------------------- Auth ----------------------- */
if (empty($_SESSION['user_id'])) jexit(401, ['success'=>false,'error'=>'Não autenticado']);
$MEU_ID = (int)$_SESSION['user_id'];
$pdo    = pdo();
$IS_MASTER = is_master($pdo, $MEU_ID);

/* ----------------------- Router ----------------------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

/* Aliases aceitos pelo front */
$alias = [
  'get_user'                => 'get',
  'save_user'               => 'save',
  'user_permissions'        => 'get_permissions',
  'get_user_acl'            => 'get_permissions',
  // aliases para os catálogos usados no modal
  'departamentos_by_company'=> 'departamentos',
  'cargos_niveis'           => 'niveis_cargo',
  'niveis'                  => 'niveis_cargo',
];
if (isset($alias[$action])) $action = $alias[$action];

/* CSRF em POST */
if ($method === 'POST') {
  $sess = $_SESSION['csrf_token'] ?? '';
  $sent = $_POST['csrf_token'] ?? '';
  if (!$sess || !$sent || !hash_equals($sess, $sent)) {
    jexit(403, ['success'=>false,'error'=>'CSRF inválido']);
  }
}

/* ======================================================
 * OPTIONS — empresas, papéis, capacidades (para UI)
 * ====================================================*/
if ($method==='GET' && $action==='options') {
  $myCompanyId = get_my_company($pdo, $MEU_ID);
  $hasCompany  = table_exists($pdo,'company');
  $nameExpr    = $hasCompany ? company_name_expr() : "CONCAT('Empresa #', COALESCE(id_company,'—'))";

  // companies
  try {
    if ($IS_MASTER) {
      $companies = $hasCompany
        ? $pdo->query("SELECT c.id_company, $nameExpr AS nome FROM company c ORDER BY nome")->fetchAll()
        : [];
    } else {
      if ($myCompanyId && $hasCompany) {
        $st = $pdo->prepare("SELECT c.id_company, $nameExpr AS nome FROM company c WHERE c.id_company=?");
        $st->execute([$myCompanyId]);
        $companies = $st->fetchAll();
      } else {
        $companies = [];
      }
    }
  } catch (Throwable $e) { $companies = []; }

  // roles (RBAC)
  $roles = (table_exists($pdo,'rbac_roles'))
    ? $pdo->query("
        SELECT
          role_id,
          role_id   AS id,                          -- compat com UI
          role_key,
          role_name,
          COALESCE(role_desc, role_name) AS role_desc,
          COALESCE(role_desc, role_name) AS descricao
        FROM rbac_roles
        WHERE is_active=1
        ORDER BY role_name
      ")->fetchAll()
    : [];

  // capabilities (RBAC)
  $capabilities = table_exists($pdo,'rbac_capabilities')
    ? $pdo->query("SELECT capability_id, resource, action, scope, cap_key
                   FROM rbac_capabilities
                   ORDER BY resource, action, scope")->fetchAll()
    : [];

  jexit(200, [
    'success'      => true,
    'companies'    => $companies,
    'roles'        => $roles,
    'capabilities' => $capabilities,
    'is_master'    => $IS_MASTER,
    'my_company'   => $myCompanyId,
    'my_id'        => $MEU_ID,
    'my_roles'     => fetch_user_role_keys($pdo, $MEU_ID),
  ]);
}

/* ======================================================
 * LIST — filtros + papéis (chips) + resumo de acesso
 * Parâmetros: q, role, company, page, per_page, include_access=1
 * ====================================================*/
if ($method==='GET' && $action==='list') {
  try {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    $q       = trim((string)($_GET['q'] ?? ''));
    $roleIn  = trim((string)($_GET['role'] ?? 'all'));
    $compIn  = trim((string)($_GET['company'] ?? 'all'));
    $wantAcc = (isset($_GET['include_access']) && $_GET['include_access'] !== '0');

    $params = [];
    $conds  = [];

    // escopo de org
    if (!$IS_MASTER) {
      $mc = get_my_company($pdo, $MEU_ID);
      if ($mc) { $conds[] = "u.id_company = ?"; $params[] = (int)$mc; }
      else     { $conds[] = "1=0"; } // sem org vinculada → nada listado
    } else {
      if ($compIn !== '' && $compIn !== 'all') {
        $conds[] = "u.id_company = ?"; $params[] = (int)$compIn;
      }
    }

    // busca
    if ($q !== '') {
      $conds[] = "(u.primeiro_nome LIKE ? OR u.ultimo_nome LIKE ? OR u.email_corporativo LIKE ? OR u.telefone LIKE ?)";
      $like = "%{$q}%"; array_push($params, $like,$like,$like,$like);
    }

    // por papel (aceita id ou key)
    if ($roleIn !== '' && strtolower($roleIn) !== 'all') {
      if (is_numeric($roleIn)) {
        $conds[] = "EXISTS (SELECT 1 FROM rbac_user_role ur WHERE ur.user_id=u.id_user AND ur.role_id = ?)";
        $params[] = (int)$roleIn;
      } else {
        $conds[] = "EXISTS (SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id
                             WHERE ur.user_id=u.id_user AND r.role_key = ?)";
        $params[] = $roleIn;
      }
    }

    $whereSql = $conds ? 'WHERE '.implode(' AND ', $conds) : '';

    $hasCompany   = table_exists($pdo,'company');
    $companyName  = $hasCompany ? company_name_expr()
                                : "CONCAT('Empresa #', COALESCE(u.id_company,'—'))";
    $joinCompany  = $hasCompany ? "LEFT JOIN company c ON c.id_company = u.id_company" : "";

    // total
    $sqlCount = "SELECT COUNT(*) FROM usuarios u $joinCompany $whereSql";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // lista
    $selectAccess = $wantAcc && view_exists($pdo,'v_user_access_summary')
      ? ", COALESCE(v.consulta_R,'—') AS consulta_R, COALESCE(v.edicao_W,'—') AS edicao_W"
      : ", NULL AS consulta_R, NULL AS edicao_W";

    $joinAccess = $wantAcc && view_exists($pdo,'v_user_access_summary')
      ? "LEFT JOIN v_user_access_summary v ON v.user_id = u.id_user"
      : "";

    // >>> ESSENCIAL: acrescenta IDs de departamento e nível/cargo (ou função) <<<
    $hasDep   = column_exists($pdo,'usuarios','id_departamento');
    $hasNivel = column_exists($pdo,'usuarios','id_nivel_cargo');
    $hasFunc  = column_exists($pdo,'usuarios','id_funcao');

    $selectExtra  = $hasDep   ? ", u.id_departamento" : ", NULL AS id_departamento";
    $selectExtra .= $hasNivel ? ", u.id_nivel_cargo"  : ($hasFunc ? ", u.id_funcao AS id_nivel_cargo" : ", NULL AS id_nivel_cargo");
    // <<< ESSENCIAL

    $sqlList = "
      SELECT
        u.id_user, u.primeiro_nome, COALESCE(u.ultimo_nome,'') AS ultimo_nome,
        u.email_corporativo, u.telefone, u.id_company, $companyName AS company_name,
        (SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ',')
           FROM rbac_user_role ur
           JOIN rbac_roles r ON r.role_id=ur.role_id
          WHERE ur.user_id=u.id_user) AS roles_csv
        $selectExtra
        $selectAccess
      FROM usuarios u
      $joinCompany
      $joinAccess
      $whereSql
      ORDER BY u.id_user DESC
      LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sqlList);
    $pos=1; foreach($params as $p){ $stmt->bindValue($pos++, $p); }
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos++, $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // fallback (vazio com filtros) — mostra últimos 100
    if ($total===0) {
      $rows = $pdo->query("
        SELECT u.id_user, u.primeiro_nome, COALESCE(u.ultimo_nome,'') AS ultimo_nome,
               u.email_corporativo, u.telefone, u.id_company, $companyName AS company_name,
               (SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ',')
                  FROM rbac_user_role ur
                  JOIN rbac_roles r ON r.role_id=ur.role_id
                 WHERE ur.user_id=u.id_user) AS roles_csv
               $selectExtra,
               NULL AS consulta_R, NULL AS edicao_W
        FROM usuarios u
        $joinCompany
        ORDER BY u.id_user DESC
        LIMIT 100
      ")->fetchAll();
      $total = is_array($rows) ? count($rows) : 0;
    }

    $users = array_map(function($r) use($IS_MASTER, $MEU_ID){
      $roles = array_values(array_filter(array_map('trim', explode(',', (string)($r['roles_csv'] ?? '')))));
      $u = [
        'id_user'           => (int)$r['id_user'],
        'primeiro_nome'     => $r['primeiro_nome'],
        'ultimo_nome'       => $r['ultimo_nome'],
        'email_corporativo' => $r['email_corporativo'],
        'telefone'          => $r['telefone'],
        'id_company'        => $r['id_company'] !== null ? (int)$r['id_company'] : null,
        'company_name'      => $r['company_name'],
        'roles'             => $roles,
        'avatar'            => avatar_public_path((int)$r['id_user']),
        'can_edit'          => $IS_MASTER || ((int)$r['id_user']===$MEU_ID),
        'can_delete'        => $IS_MASTER && (int)$r['id_user']!==$MEU_ID && (int)$r['id_user']!==1,

        // >>> ESSENCIAL: devolver IDs para o front mapear nomes nos chips <<<
        'id_departamento'   => array_key_exists('id_departamento',$r) && $r['id_departamento'] !== null ? (int)$r['id_departamento'] : null,
        'id_nivel_cargo'    => array_key_exists('id_nivel_cargo',$r) && $r['id_nivel_cargo'] !== null ? (int)$r['id_nivel_cargo'] : null,
        // <<< ESSENCIAL
      ];
      if (isset($r['consulta_R']) || isset($r['edicao_W'])) {
        $u['access'] = [
          'consulta_R' => $r['consulta_R'] ?: '—',
          'edicao_W'   => $r['edicao_W']   ?: '—',
        ];
      }
      return $u;
    }, $rows ?: []);

    jexit(200, [
      'success'=>true,
      'users'=>$users,
      'items'=>$users,
      'total'=>$total,
      'page'=>$page,
      'per_page'=>$perPage
    ]);
  } catch (Throwable $e) {
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* ======================================================
 * GET — dados do usuário + roles(ids) + overrides + resumo (+ departamento/função)
 * ====================================================*/
if ($method==='GET' && $action==='get') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);

  if (!$IS_MASTER) {
    $myc = get_my_company($pdo, $MEU_ID);
    $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
    $st->execute([$id]);
    $targetCompany = $st->fetchColumn();
    if ($targetCompany === false || ($myc !== null && (int)$targetCompany !== (int)$myc)) {
      jexit(403, ['success'=>false,'error'=>'Sem permissão para consultar este usuário.']);
    }
  }

  // incluir campos opcionais se existirem
  $hasDep   = column_exists($pdo,'usuarios','id_departamento');
  $hasNivel = column_exists($pdo,'usuarios','id_nivel_cargo');
  $hasFunc  = column_exists($pdo,'usuarios','id_funcao');

  $extra = '';
  $extra .= $hasDep   ? ", id_departamento" : ", NULL AS id_departamento";
  $extra .= $hasNivel ? ", id_nivel_cargo"  : ", NULL AS id_nivel_cargo";
  $extra .= $hasFunc  ? ", id_funcao"       : ", NULL AS id_funcao";

  $st=$pdo->prepare("
      SELECT id_user, primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome,
             email_corporativo, telefone, id_company
             $extra
        FROM usuarios WHERE id_user=?
  ");
  $st->execute([$id]);
  $u=$st->fetch();
  if (!$u) jexit(404, ['success'=>false,'error'=>'Usuário não encontrado']);

  $rolesIds   = fetch_user_role_ids($pdo, $id);
  $overrides  = fetch_user_overrides($pdo, $id);
  $summary    = fetch_access_summary($pdo, $id);
  $avatarPath = avatar_public_path($id);

  jexit(200, ['success'=>true,'user'=>$u,'roles'=>$rolesIds,'overrides'=>$overrides,'summary'=>$summary,'avatar'=>$avatarPath]);
}

/* ======================================================
 * GET_PERMISSIONS — roles(ids) + overrides + resumo
 * ====================================================*/
if ($method==='GET' && $action==='get_permissions') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);
  if (!$IS_MASTER && $id !== $MEU_ID) jexit(403, ['success'=>false,'error'=>'Sem permissão para ver permissões deste usuário.']);

  $rolesIds  = fetch_user_role_ids($pdo, $id);
  $overrides = fetch_user_overrides($pdo, $id);
  $summary   = fetch_access_summary($pdo, $id);

  jexit(200, ['success'=>true,'roles'=>$rolesIds,'overrides'=>$overrides,'summary'=>$summary]);
}

/* ======================================================
 * GET_ACCESS — resumo de acesso (R/W) — opcional
 * ====================================================*/
if ($method==='GET' && $action==='get_access') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);
  if (!$IS_MASTER) {
    $myc = get_my_company($pdo, $MEU_ID);
    $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=?");
    $st->execute([$id]);
    $targetCompany = $st->fetchColumn();
    if ($targetCompany === false || ($myc !== null && (int)$targetCompany !== (int)$myc)) {
      jexit(403, ['success'=>false,'error'=>'Sem permissão.']);
    }
  }
  $summary = fetch_access_summary($pdo, $id);
  jexit(200, ['success'=>true,'summary'=>$summary]);
}

/* ======================================================
 * CAPABILITIES — lista para montar os overrides
 * ====================================================*/
if ($method==='GET' && $action==='capabilities') {
  if (!table_exists($pdo,'rbac_capabilities')) jexit(200, ['success'=>true,'capabilities'=>[]]);
  $caps = $pdo->query("SELECT capability_id, resource, action, scope, cap_key
                       FROM rbac_capabilities
                       ORDER BY resource, action, scope")->fetchAll();
  jexit(200, ['success'=>true,'capabilities'=>$caps]);
}

/* ======================================================
 * DEPARTAMENTOS — catálogo por organização (para o modal)
 * Query params aceitos: cid | company | company_id | id_company
 * ====================================================*/
if ($method==='GET' && $action==='departamentos') {
  // aceita 'cid' (é o que o front envia)
  $cid = (int)($_GET['cid'] ?? $_GET['company'] ?? $_GET['company_id'] ?? $_GET['id_company'] ?? 0);

  // inclui dom_departamentos (primeira opção)
  $table = first_existing_table($pdo, ['dom_departamentos','departamentos','okr_departamentos']);
  if (!$table) jexit(200, ['success'=>true, 'items'=>[]]); // sem tabela → retorna vazio

  // sinônimos de colunas
  $idCol     = first_existing_column($pdo,$table, ['id_departamento','departamento_id','id']) ?? 'id';
  $nameCol   = first_existing_column($pdo,$table, ['nome','nome_departamento','descricao','descricao_departamento','departamento','name','titulo']) ?? $idCol;
  $compCol   = first_existing_column($pdo,$table, ['id_company','company_id','id_empresa','empresa_id','id_organizacao','organizacao_id','empresa']);
  $activeCol = first_existing_column($pdo,$table, ['ativo','is_active','habilitado','status']);
  $ordCol    = first_existing_column($pdo,$table, ['display_order','ordem','order','sort','pos','position']);
  $codeCol   = first_existing_column($pdo,$table, ['codigo','sigla','cod']);

  $select = "SELECT $idCol AS id, $nameCol AS nome"
          . ($codeCol ? ", $codeCol AS codigo" : "")
          . ($compCol ? ", $compCol AS id_company" : "")
          . " FROM `$table`";

  $baseConds = [];
  if ($activeCol) $baseConds[] = "$activeCol = 1";
  $orderBy = " ORDER BY " . ($ordCol ?: $nameCol);

  // 1) Tentativa com filtro por empresa
  $sql  = $select;
  $args = [];
  $conds = $baseConds;
  if ($cid > 0 && $compCol) { $conds[] = "$compCol = ?"; $args[] = $cid; }
  if ($conds) $sql .= " WHERE " . implode(' AND ', $conds);
  $sql .= $orderBy;

  $st = $pdo->prepare($sql);
  $st->execute($args);
  $items = $st->fetchAll();

  // 2) Fallback: se não encontrou para a empresa escolhida, retorna catálogo global (sem filtrar company)
  if ($cid > 0 && $compCol && empty($items)) {
    $sql2 = $select;
    if ($baseConds) $sql2 .= " WHERE " . implode(' AND ', $baseConds);
    $sql2 .= $orderBy;
    $items = $pdo->query($sql2)->fetchAll();
  }

  jexit(200, ['success'=>true, 'items'=>$items]);
}

/* ======================================================
 * NIVEIS_CARGO — catálogo de funções/níveis (para o modal)
 * ====================================================*/
if ($method==='GET' && $action==='niveis_cargo') {
  // inclui dom_niveis_cargo (primeira opção)
  $table = first_existing_table($pdo, ['dom_niveis_cargo','niveis_cargo','cargos_niveis','niveis','funcoes','cargos','okr_funcoes','okr_niveis']);
  if (!$table) jexit(200, ['success'=>true, 'items'=>[]]);

  $idCol     = first_existing_column($pdo,$table, ['id_nivel','nivel_id','id','id_funcao','id_cargo']) ?? 'id';
  $nameCol   = first_existing_column($pdo,$table, ['nome','nome_nivel','descricao','descricao_nivel','nivel','funcao','cargo','name','titulo']) ?? $idCol;
  $ordCol    = first_existing_column($pdo,$table, ['ordem','order','sort','pos','position']);
  $activeCol = first_existing_column($pdo,$table, ['ativo','is_active','habilitado','status']);

  $selOrd = $ordCol ? "$ordCol AS ordem" : "0 AS ordem";
  $sql = "SELECT $idCol AS id, $nameCol AS nome, $selOrd FROM `$table`";
  $conds = [];
  if ($activeCol) $conds[] = "$activeCol = 1";
  if ($conds) $sql .= " WHERE ".implode(' AND ', $conds);
  $sql .= " ORDER BY ".($ordCol ?: $nameCol);

  $items = $pdo->query($sql)->fetchAll();
  jexit(200, ['success'=>true, 'items'=>$items]);
}

/* ======================================================
 * SAVE — cria/edita usuário + roles + overrides (+ depto/função)
 * ====================================================*/
if ($method==='POST' && $action==='save') {
  $id         = (int)($_POST['id_user'] ?? 0);
  $primeiro   = trim((string)($_POST['primeiro_nome'] ?? ''));
  $ultimo     = trim((string)($_POST['ultimo_nome'] ?? ''));
  $email      = trim((string)($_POST['email'] ?? $_POST['email_corporativo'] ?? ''));
  $tel        = trim((string)($_POST['telefone'] ?? ''));
  $rawCompany = $_POST['id_company'] ?? null;

  $rolesAny   = $_POST['roles'] ?? [];
  $overAssoc  = $_POST['overrides'] ?? []; // overrides[cap_id] = ALLOW|DENY

  // Departamento / Função (nível)
  $in_dep   = (int)($_POST['id_departamento']  ?? 0);
  $in_nivel = (int)($_POST['id_nivel_cargo']   ?? ($_POST['id_funcao'] ?? 0)); // aceita alias

  if ($primeiro==='' || $email==='') jexit(422, ['success'=>false,'error'=>'Nome e e-mail são obrigatórios.']);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jexit(422, ['success'=>false,'error'=>'E-mail inválido.']);
  if (mb_strlen($primeiro)>100 || mb_strlen($ultimo)>100) jexit(422, ['success'=>false,'error'=>'Nome/Sobrenome muito longos.']);
  if (mb_strlen($email)>150) jexit(422, ['success'=>false,'error'=>'E-mail excede 150 caracteres.']);
  if ($tel!=='' && mb_strlen($tel)>30) jexit(422, ['success'=>false,'error'=>'Telefone excede 30 caracteres.']);

  $id_company = (int)($rawCompany ?? 0);
  $id_company = $id_company > 0 ? $id_company : null;

  if (!$IS_MASTER) {
    $myCompanyId = get_my_company($pdo, $MEU_ID);
    if ($myCompanyId > 0) $id_company = $myCompanyId;
    else jexit(422, ['success'=>false,'error'=>'Seu usuário não está vinculado a nenhuma organização.']);
    // não-master não concede admin_master
    if (is_array($rolesAny)) {
      $rolesAny = array_values(array_filter($rolesAny, fn($r)=> strtolower((string)$r) !== 'admin_master'));
    }
  }

  // e-mail duplicado
  $chk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email_corporativo = ? AND id_user <> ?");
  $chk->execute([$email, $id]);
  if ((int)$chk->fetchColumn() > 0) jexit(422, ['success'=>false,'error'=>'Já existe um usuário com este e-mail.']);

  // normaliza roles (para ids)
  $roleIds = resolve_role_ids($pdo, is_array($rolesAny)?$rolesAny:[]);

  // normaliza overrides
  $over = [];
  if (is_array($overAssoc)) {
    foreach ($overAssoc as $capId => $eff) {
      $eff = strtoupper(trim((string)$eff));
      if ($eff==='ALLOW' || $eff==='DENY') $over[(int)$capId] = $eff;
    }
  }

  // colunas opcionais no schema
  $hasDep   = column_exists($pdo,'usuarios','id_departamento');
  $hasNivel = column_exists($pdo,'usuarios','id_nivel_cargo');
  $hasFunc  = column_exists($pdo,'usuarios','id_funcao');

  $pdo->beginTransaction();
  try {
    if ($id > 0) {
      // escopo para não-master
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

      $canChangeAcl = $IS_MASTER || $id===$MEU_ID;

      // UPDATE dinâmico com campos opcionais
      $sets = [
        "primeiro_nome = :p",
        "ultimo_nome = :u",
        "email_corporativo = :e",
        "telefone = :t",
        "id_company = :c",
        "dt_alteracao = NOW()",
        "id_user_alteracao = :me"
      ];
      if ($hasDep)   $sets[] = "id_departamento = :dep";
      if ($hasNivel) $sets[] = "id_nivel_cargo = :nivel";
      elseif ($hasFunc) $sets[] = "id_funcao = :nivel"; // mapeia para id_funcao se não existir id_nivel_cargo

      $sql = "UPDATE usuarios SET ".implode(', ',$sets)." WHERE id_user = :id";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':p'=>$primeiro, ':u'=>$ultimo, ':e'=>$email, ':t'=>$tel,
        ':c'=>$id_company, ':me'=>$MEU_ID, ':id'=>$id,
        ...($hasDep   ? [':dep'=>$in_dep] : []),
        ...(($hasNivel || $hasFunc) ? [':nivel'=>$in_nivel] : []),
      ]);

      if ($canChangeAcl) {
        if (table_exists($pdo,'rbac_user_role')) {
          $pdo->prepare("DELETE FROM rbac_user_role WHERE user_id=?")->execute([$id]);
          if ($roleIds) {
            $ins = $pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id, valid_from) VALUES (?,?,NOW())");
            foreach ($roleIds as $rid) $ins->execute([$id, (int)$rid]);
          }
        }
        if (table_exists($pdo,'rbac_user_capability')) {
          $pdo->prepare("DELETE FROM rbac_user_capability WHERE user_id=?")->execute([$id]);
          if ($over) {
            $ins = $pdo->prepare("INSERT INTO rbac_user_capability (user_id, capability_id, effect) VALUES (?,?,?)");
            foreach ($over as $capId=>$eff) $ins->execute([$id, (int)$capId, $eff]);
          }
        }
      }
    } else {
      // INSERT dinâmico
      $cols = ['primeiro_nome','ultimo_nome','email_corporativo','telefone','id_company','dt_cadastro','ip_criacao','id_user_criador'];
      $ph   = [':p',':u',':e',':t',':c','NOW()',':ip',':me'];
      $params = [
        ':p'=>$primeiro, ':u'=>$ultimo, ':e'=>$email, ':t'=>$tel,
        ':c'=>$id_company, ':ip'=>($_SERVER['REMOTE_ADDR'] ?? null), ':me'=>$MEU_ID
      ];
      if ($hasDep)   { $cols[]='id_departamento'; $ph[]=':dep'; $params[':dep']=$in_dep; }
      if ($hasNivel) { $cols[]='id_nivel_cargo';  $ph[]=':nivel'; $params[':nivel']=$in_nivel; }
      elseif ($hasFunc) { $cols[]='id_funcao';   $ph[]=':nivel'; $params[':nivel']=$in_nivel; }

      $sql = "INSERT INTO usuarios (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
      $st  = $pdo->prepare($sql);
      $st->execute($params);
      $id = (int)$pdo->lastInsertId();

      if (table_exists($pdo,'rbac_user_role') && $roleIds) {
        $ins = $pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id, valid_from) VALUES (?,?,NOW())");
        foreach ($roleIds as $rid) $ins->execute([$id, (int)$rid]);
      }
      if (table_exists($pdo,'rbac_user_capability') && $over) {
        $ins = $pdo->prepare("INSERT INTO rbac_user_capability (user_id, capability_id, effect) VALUES (?,?,?)");
        foreach ($over as $capId=>$eff) $ins->execute([$id, (int)$capId, $eff]);
      }
    }

    $pdo->commit();
    jexit(200, ['success'=>true,'id_user'=>$id]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* ======================================================
 * SAVE_PERMISSIONS — apenas papéis e overrides
 * ====================================================*/
if ($method==='POST' && $action==='save_permissions') {
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);

  if (!$IS_MASTER && $id !== $MEU_ID) jexit(403, ['success'=>false,'error'=>'Sem permissão para alterar ACL deste usuário.']);

  $rolesAny  = $_POST['roles'] ?? [];
  $overAssoc = $_POST['overrides'] ?? [];

  if (!$IS_MASTER) {
    if (is_array($rolesAny)) {
      $rolesAny = array_values(array_filter($rolesAny, fn($r)=> strtolower((string)$r) !== 'admin_master'));
    }
  }

  $roleIds = resolve_role_ids($pdo, is_array($rolesAny)?$rolesAny:[]);

  $over = [];
  if (is_array($overAssoc)) {
    foreach ($overAssoc as $capId => $eff) {
      $eff = strtoupper(trim((string)$eff));
      if ($eff==='ALLOW' || $eff==='DENY') $over[(int)$capId] = $eff;
    }
  }

  $pdo->beginTransaction();
  try {
    if (table_exists($pdo,'rbac_user_role')) {
      $pdo->prepare("DELETE FROM rbac_user_role WHERE user_id=?")->execute([$id]);
      if ($roleIds) {
        $ins = $pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id, valid_from) VALUES (?,?,NOW())");
        foreach ($roleIds as $rid) $ins->execute([$id, (int)$rid]);
      }
    }
    if (table_exists($pdo,'rbac_user_capability')) {
      $pdo->prepare("DELETE FROM rbac_user_capability WHERE user_id=?")->execute([$id]);
      if ($over) {
        $ins = $pdo->prepare("INSERT INTO rbac_user_capability (user_id, capability_id, effect) VALUES (?,?,?)");
        foreach ($over as $capId=>$eff) $ins->execute([$id, (int)$capId, $eff]);
      }
    }

    $pdo->commit();
    jexit(200, ['success'=>true]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* ======================================================
 * DELETE — master; não pode excluir a si mesmo nem #1
 * ====================================================*/
if ($method==='POST' && $action==='delete') {
  if (!$IS_MASTER) jexit(403, ['success'=>false,'error'=>'Apenas admin master pode excluir usuários.']);
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);
  if ($id === $MEU_ID) jexit(422, ['success'=>false,'error'=>'Você não pode excluir a si mesmo.']);
  if ($id === 1) jexit(422, ['success'=>false,'error'=>'Usuário #1 não pode ser excluído.']);

  $pdo->beginTransaction();
  try {
    // RBAC
    safe_delete_user_rows($pdo, 'rbac_user_capability', $id);
    safe_delete_user_rows($pdo, 'rbac_user_role',       $id);

    // Tabelas legadas comuns (se existirem)
    safe_delete_user_rows($pdo, 'usuarios_permissoes',       $id);
    safe_delete_user_rows($pdo, 'usuarios_paginas',          $id);
    safe_delete_user_rows($pdo, 'usuarios_planos',           $id);
    safe_delete_user_rows($pdo, 'usuarios_credenciais',      $id);
    safe_delete_user_rows($pdo, 'usuarios_password_resets',  $id);

    // Usuário
    $pdo->prepare("DELETE FROM usuarios WHERE id_user=?")->execute([$id]);

    // Apaga avatar (png/jpg/jpeg), se houver
    $base = __DIR__ . '/../assets/img/avatars/';
    foreach (['png','jpg','jpeg'] as $ext) {
      $f = $base.$id.'.'.$ext;
      if (is_file($f)) @unlink($f);
    }

    $pdo->commit();
    jexit(200, ['success'=>true]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jexit(400, ['success'=>false,'error'=>$e->getMessage()]);
  }
}

/* ======================================================
 * UPLOAD AVATAR — arquivo
 * ====================================================*/
if ($method==='POST' && $action==='upload_avatar') {
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

  foreach(['png','jpg','jpeg'] as $e){
    $p=$real.DIRECTORY_SEPARATOR.$id.'.'.$e; if (file_exists($p)) @unlink($p);
  }
  $dest = $real.DIRECTORY_SEPARATOR.$id.'.'.$ext;

  if (!move_uploaded_file($tmp,$dest)) jexit(500, ['success'=>false,'error'=>'Não foi possível salvar o avatar']);
  jexit(200, ['success'=>true,'path'=>'/OKR_system/assets/img/avatars/'.$id.'.'.$ext]);
}

/* ======================================================
 * SAVE AVATAR CANVAS — dataURL PNG
 * ====================================================*/
if ($method==='POST' && $action==='save_avatar_canvas') {
  $id = (int)($_POST['id_user'] ?? 0);
  $data = (string)($_POST['data_url'] ?? '');
  if ($id<=0 || strpos($data,'data:image/png;base64,')!==0) jexit(400, ['success'=>false,'error'=>'Dados inválidos']);
  $bin = base64_decode(substr($data, strlen('data:image/png;base64,')));
  if ($bin===false) jexit(400, ['success'=>false,'error'=>'Base64 inválido']);

  $dir = __DIR__.'/../assets/img/avatars';
  if (!is_dir($dir)) @mkdir($dir,0775,true);
  $real = realpath($dir);
  if (!$real) jexit(500, ['success'=>false,'error'=>'Diretório de avatars indisponível']);

  foreach(['png','jpg','jpeg'] as $e){
    $p=$real.DIRECTORY_SEPARATOR.$id.'.'.$e; if (file_exists($p)) @unlink($p);
  }
  $dest = $real.DIRECTORY_SEPARATOR.$id.'.png';
  if (file_put_contents($dest,$bin)===false) jexit(500, ['success'=>false,'error'=>'Falha ao gravar PNG']);

  jexit(200, ['success'=>true,'path'=>'/OKR_system/assets/img/avatars/'.$id.'.png']);
}

/* ======================================================
 * ROLES_MATRIX — stub (somente leitura; evita "Ação inválida")
 * ====================================================*/
if ($method==='GET' && $action==='roles_matrix') {
  jexit(200, [
    'success'   => true,
    'pages'     => [],
    'roles'     => [],
    'role_caps' => new stdClass()
  ]);
}

/* ----------------------- Fallback ----------------------- */
jexit(400, ['success'=>false,'error'=>'Ação inválida']);
