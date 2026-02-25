<?php
// auth/usuarios_api.php — CRUD de usuários + RBAC (roles/overrides) + avatar
// Endpoints aceitos pelo front: options, list, get_user, save_user, delete,
// capabilities, get_permissions, save_permissions, roles_matrix (stub),
// departamentos (por company), niveis_cargo (catálogo)
// (aliases para compat: get/save + departamentos_by_company/cargos_niveis/niveis)

declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/functions.php'; // <<< ADICIONADO: helpers de e-mail/reset

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
    error_log('usuarios_api DB: '.$e->getMessage());
    jexit(500, ['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
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

/* Admin master via RBAC role (único que vê cross-company) */
function is_master(PDO $pdo, int $uid): bool {
  if (!table_exists($pdo, 'rbac_user_role') || !table_exists($pdo, 'rbac_roles')) return false;
  $st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_key='admin_master' AND r.is_active=1 LIMIT 1");
  $st->execute([$uid]);
  return (bool)$st->fetchColumn();
}

/* Admin de company (user_admin) — pode gerenciar usuários da própria empresa */
function is_company_admin(PDO $pdo, int $uid): bool {
  if (!table_exists($pdo, 'rbac_user_role') || !table_exists($pdo, 'rbac_roles')) return false;
  $st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_key='user_admin' AND r.is_active=1 LIMIT 1");
  $st->execute([$uid]);
  return (bool)$st->fetchColumn();
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

/* Resolve a URL pública do avatar, priorizando avatars.filename (como no header) */
function avatar_url_from_filename(?string $fn): ?string {
  if (is_string($fn) && preg_match('/^[a-z0-9_.-]+\.png$/i', $fn)) {
    return '/OKR_system/assets/img/avatars/default_avatar/'.$fn;
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

/* Resolve role ids a partir de ids/keys */
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

/* ===== Helpers para DELETE com cascade/reatribuição ===== */

/** Conta quantos usuários pertencem à company */
function count_company_users(PDO $pdo, int $companyId): int {
  $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_company = ?");
  $st->execute([$companyId]);
  return (int)$st->fetchColumn();
}

/** Encontra o user_admin da company (excluindo $excludeUserId). Retorna id_user ou null. */
function find_company_admin(PDO $pdo, int $companyId, int $excludeUserId): ?int {
  if (!table_exists($pdo, 'rbac_user_role') || !table_exists($pdo, 'rbac_roles')) return null;
  $st = $pdo->prepare("
    SELECT u.id_user
    FROM usuarios u
    JOIN rbac_user_role ur ON ur.user_id = u.id_user
    JOIN rbac_roles r ON r.role_id = ur.role_id
    WHERE u.id_company = ?
      AND u.id_user <> ?
      AND r.role_key = 'user_admin'
      AND r.is_active = 1
    ORDER BY u.id_user ASC
    LIMIT 1
  ");
  $st->execute([$companyId, $excludeUserId]);
  $v = $st->fetchColumn();
  return $v !== false ? (int)$v : null;
}

/**
 * Reatribui todos os itens OKR do usuário $from para $to.
 * Retorna array com contagem de itens por tipo.
 */
function reassign_user_items(PDO $pdo, int $from, int $to): array {
  $counts = [];

  // Helper para UPDATE seguro
  $upd = function(string $table, string $col, string $label) use ($pdo, $from, $to, &$counts) {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, $col)) return;
    $st = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?");
    $st->execute([$to, $from]);
    $n = $st->rowCount();
    if ($n > 0) $counts[$label] = ($counts[$label] ?? 0) + $n;
  };

  // Helper para tabelas com PK composta (envolvidos): deletar duplicatas antes de reatribuir
  $updComposite = function(string $table, string $userCol, string $parentCol, string $label) use ($pdo, $from, $to, &$counts) {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, $userCol)) return;
    // Deletar linhas onde $to já existe como envolvido do mesmo parent
    $pdo->prepare("
      DELETE a FROM `$table` a
      INNER JOIN `$table` b ON b.`$parentCol` = a.`$parentCol` AND b.`$userCol` = ?
      WHERE a.`$userCol` = ?
    ")->execute([$to, $from]);
    // Reatribuir restantes
    $st = $pdo->prepare("UPDATE `$table` SET `$userCol` = ? WHERE `$userCol` = ?");
    $st->execute([$to, $from]);
    $n = $st->rowCount();
    if ($n > 0) $counts[$label] = ($counts[$label] ?? 0) + $n;
  };

  // 1. CRITICO: objetivos.dono (FK RESTRICT)
  $upd('objetivos', 'dono', 'objetivos');
  // 2. objetivos.id_user_criador
  $upd('objetivos', 'id_user_criador', 'objetivos');

  // 3. key_results.responsavel
  $upd('key_results', 'responsavel', 'key_results');
  $upd('key_results', 'id_user_criador', 'key_results');

  // 4. CRITICO: iniciativas (CASCADE FK deletaria iniciativas!)
  $upd('iniciativas', 'id_user_criador', 'iniciativas');
  // 5. iniciativas.id_user_responsavel
  $upd('iniciativas', 'id_user_responsavel', 'iniciativas');

  // 6. Tabelas legadas/auxiliares
  $upd('apontamentos_kr', 'usuario_id', 'apontamentos');
  $upd('apontamentos_status_iniciativas', 'id_user', 'apontamentos');
  $upd('fluxo_aprovacoes', 'id_user_solicitante', 'aprovacoes');
  $upd('fluxo_aprovacoes', 'id_user_aprovador', 'aprovacoes');
  $upd('permissoes_aprovador', 'id_user', 'aprovacoes');
  $upd('aprovacao_movimentos', 'id_user_criador', 'aprovacoes');
  $upd('aprovacao_movimentos', 'id_user_aprovador', 'aprovacoes');
  $upd('objetivo_links', 'criado_por', 'objetivo_links');
  $upd('orcamentos', 'id_user_criador', 'orcamentos');
  $upd('notificacoes', 'id_user', 'notificacoes');
  $upd('chat_conversas', 'id_user', 'chat');
  $upd('kr_comentarios', 'id_user', 'comentarios');
  if (table_exists($pdo, 'comentarios_kr')) $upd('comentarios_kr', 'id_user', 'comentarios');
  $upd('milestones_kr', 'id_user', 'milestones');

  // 7. Tabelas com PK composta (envolvidos)
  if (column_exists($pdo, 'iniciativas_envolvidos', 'id_iniciativa')) {
    $updComposite('iniciativas_envolvidos', 'id_user', 'id_iniciativa', 'envolvidos');

    // Sincroniza id_user_responsavel denormalizado com o 1º envolvido restante
    $pdo->prepare("
      UPDATE `iniciativas` i
      SET i.`id_user_responsavel` = (
        SELECT ie.`id_user`
        FROM `iniciativas_envolvidos` ie
        WHERE ie.`id_iniciativa` = i.`id_iniciativa`
        ORDER BY ie.`id_user` ASC
        LIMIT 1
      )
      WHERE EXISTS (
        SELECT 1 FROM `iniciativas_envolvidos` ie2
        WHERE ie2.`id_iniciativa` = i.`id_iniciativa`
      )
    ")->execute();
  }
  if (table_exists($pdo, 'okr_kr_envolvidos') && column_exists($pdo, 'okr_kr_envolvidos', 'id_kr')) {
    $updComposite('okr_kr_envolvidos', 'id_user', 'id_kr', 'envolvidos');
  }
  if (table_exists($pdo, 'orcamentos_envolvidos') && column_exists($pdo, 'orcamentos_envolvidos', 'id_orcamento')) {
    $updComposite('orcamentos_envolvidos', 'id_user', 'id_orcamento', 'envolvidos');
  }

  return $counts;
}

/**
 * Cenário 1: único usuário da company — deleta a company inteira e todos os dados.
 * Ordem: folhas primeiro para respeitar FKs.
 */
function delete_company_cascade(PDO $pdo, int $companyId, int $userId): void {
  // Helper para deletar via sub-select (quando a FK é indireta)
  $delWhere = function(string $table, string $cond, array $params=[]) use ($pdo) {
    if (!table_exists($pdo, $table)) return;
    $pdo->prepare("DELETE FROM `$table` WHERE $cond")->execute($params);
  };

  // 1-3. Orçamentos (detalhes → envolvidos → orcamentos)
  if (table_exists($pdo, 'orcamentos_detalhes')) {
    $pdo->prepare("
      DELETE od FROM orcamentos_detalhes od
      JOIN orcamentos o ON o.id_orcamento = od.id_orcamento
      JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }
  if (table_exists($pdo, 'orcamentos_envolvidos')) {
    $pdo->prepare("
      DELETE oe FROM orcamentos_envolvidos oe
      JOIN orcamentos o ON o.id_orcamento = oe.id_orcamento
      JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }
  if (table_exists($pdo, 'orcamentos')) {
    $pdo->prepare("
      DELETE o FROM orcamentos o
      JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 4. apontamentos_status_iniciativas
  if (table_exists($pdo, 'apontamentos_status_iniciativas')) {
    $pdo->prepare("
      DELETE a FROM apontamentos_status_iniciativas a
      JOIN iniciativas i ON i.id_iniciativa = a.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 5. iniciativas_envolvidos
  if (table_exists($pdo, 'iniciativas_envolvidos')) {
    $pdo->prepare("
      DELETE ie FROM iniciativas_envolvidos ie
      JOIN iniciativas i ON i.id_iniciativa = ie.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 6. iniciativas
  if (table_exists($pdo, 'iniciativas')) {
    $pdo->prepare("
      DELETE i FROM iniciativas i
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 7. apontamentos_kr (e anexos via CASCADE)
  if (table_exists($pdo, 'apontamentos_kr')) {
    $pdo->prepare("
      DELETE a FROM apontamentos_kr a
      JOIN key_results k ON k.id_kr = a.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 8. milestones_kr
  if (table_exists($pdo, 'milestones_kr')) {
    $pdo->prepare("
      DELETE m FROM milestones_kr m
      JOIN key_results k ON k.id_kr = m.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 9. okr_kr_envolvidos
  if (table_exists($pdo, 'okr_kr_envolvidos')) {
    $pdo->prepare("
      DELETE ke FROM okr_kr_envolvidos ke
      JOIN key_results k ON k.id_kr = ke.id_kr
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 10. kr_comentarios / comentarios_kr
  foreach (['kr_comentarios', 'comentarios_kr'] as $tbl) {
    if (table_exists($pdo, $tbl) && column_exists($pdo, $tbl, 'id_kr')) {
      $pdo->prepare("
        DELETE c FROM `$tbl` c
        JOIN key_results k ON k.id_kr = c.id_kr
        JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
        WHERE ob.id_company = ?
      ")->execute([$companyId]);
    }
  }

  // 11. key_results
  if (table_exists($pdo, 'key_results')) {
    $pdo->prepare("
      DELETE k FROM key_results k
      JOIN objetivos ob ON ob.id_objetivo = k.id_objetivo
      WHERE ob.id_company = ?
    ")->execute([$companyId]);
  }

  // 12. objetivo_links (tem id_src e id_dst, nao id_objetivo)
  if (table_exists($pdo, 'objetivo_links')) {
    // por id_company direto (se a tabela tiver)
    if (column_exists($pdo, 'objetivo_links', 'id_company')) {
      $pdo->prepare("DELETE FROM objetivo_links WHERE id_company = ?")->execute([$companyId]);
    } else {
      // via JOIN nos dois lados
      $pdo->prepare("
        DELETE ol FROM objetivo_links ol
        JOIN objetivos ob ON ob.id_objetivo = ol.id_src
        WHERE ob.id_company = ?
      ")->execute([$companyId]);
      $pdo->prepare("
        DELETE ol FROM objetivo_links ol
        JOIN objetivos ob ON ob.id_objetivo = ol.id_dst
        WHERE ob.id_company = ?
      ")->execute([$companyId]);
    }
  }

  // 13. objetivos
  $delWhere('objetivos', 'id_company = ?', [$companyId]);

  // 14. dom_cargos, dom_departamentos
  $delWhere('dom_cargos', 'id_company = ?', [$companyId]);
  $delWhere('dom_departamentos', 'id_company = ?', [$companyId]);

  // 15. fluxo_aprovacoes, aprovacao_movimentos, permissoes_aprovador, aprovadores
  //     Colunas de user variam por tabela
  foreach (['permissoes_aprovador', 'aprovadores'] as $tbl) {
    if (table_exists($pdo, $tbl) && column_exists($pdo, $tbl, 'id_user')) {
      $pdo->prepare("DELETE FROM `$tbl` WHERE id_user = ?")->execute([$userId]);
    }
  }
  if (table_exists($pdo, 'fluxo_aprovacoes')) {
    foreach (['id_user_solicitante','id_user_aprovador'] as $col) {
      if (column_exists($pdo, 'fluxo_aprovacoes', $col)) {
        $pdo->prepare("DELETE FROM fluxo_aprovacoes WHERE `$col` = ?")->execute([$userId]);
      }
    }
  }
  if (table_exists($pdo, 'aprovacao_movimentos')) {
    foreach (['id_user_criador','id_user_aprovador'] as $col) {
      if (column_exists($pdo, 'aprovacao_movimentos', $col)) {
        $pdo->prepare("DELETE FROM aprovacao_movimentos WHERE `$col` = ?")->execute([$userId]);
      }
    }
  }

  // 16. notificacoes, chat_conversas
  $delWhere('notificacoes', 'id_user = ?', [$userId]);
  $delWhere('chat_conversas', 'id_user = ?', [$userId]);

  // 17. company_style
  $delWhere('company_style', 'id_company = ?', [$companyId]);

  // 18. RBAC + tabelas legadas do usuario
  safe_delete_user_rows($pdo, 'rbac_user_capability', $userId);
  safe_delete_user_rows($pdo, 'rbac_user_role',       $userId);
  safe_delete_user_rows($pdo, 'usuarios_permissoes',       $userId);
  safe_delete_user_rows($pdo, 'usuarios_paginas',          $userId);
  safe_delete_user_rows($pdo, 'usuarios_planos',           $userId);
  safe_delete_user_rows($pdo, 'usuarios_credenciais',      $userId);
  safe_delete_user_rows($pdo, 'usuarios_password_resets',  $userId);

  // 19. Desvincula FKs RESTRICT no usuario antes de deletar
  //     avatar_id é NOT NULL DEFAULT 1, então reseta para 1 em vez de NULL
  $setCols = [];
  foreach (['id_departamento','id_nivel_cargo','id_permissao'] as $col) {
    if (column_exists($pdo, 'usuarios', $col)) $setCols[] = "`$col` = NULL";
  }
  if (column_exists($pdo, 'usuarios', 'avatar_id')) $setCols[] = "`avatar_id` = 1";
  if ($setCols) {
    $pdo->prepare("UPDATE usuarios SET " . implode(', ', $setCols) . " WHERE id_user = ?")->execute([$userId]);
  }
  $pdo->prepare("DELETE FROM usuarios WHERE id_user = ?")->execute([$userId]);

  // 20. company
  $delWhere('company', 'id_company = ?', [$companyId]);
}

/**
 * Conta itens OKR vinculados a um usuario (para preview).
 */
function count_user_items(PDO $pdo, int $userId): int {
  $total = 0;
  $tables = [
    ['objetivos', 'dono'],
    ['objetivos', 'id_user_criador'],
    ['key_results', 'responsavel'],
    ['key_results', 'id_user_criador'],
    ['iniciativas', 'id_user_criador'],
    ['iniciativas', 'id_user_responsavel'],
  ];
  foreach ($tables as [$table, $col]) {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, $col)) continue;
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?");
    $st->execute([$userId]);
    $total += (int)$st->fetchColumn();
  }
  return $total;
}

/* ----------------------- Auth ----------------------- */
if (empty($_SESSION['user_id'])) jexit(401, ['success'=>false,'error'=>'Não autenticado']);
$MEU_ID = (int)$_SESSION['user_id'];
$pdo    = pdo();
$IS_MASTER        = is_master($pdo, $MEU_ID);
$IS_COMPANY_ADMIN = !$IS_MASTER && is_company_admin($pdo, $MEU_ID);
$CAN_MANAGE_USERS = $IS_MASTER || $IS_COMPANY_ADMIN;

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

    // >>> NOVO (AVATAR): join com avatars e filename
    $joinAv = "LEFT JOIN avatars a ON a.id = u.avatar_id";
    // <<< NOVO

    $sqlList = "
      SELECT
        u.id_user, u.primeiro_nome, COALESCE(u.ultimo_nome,'') AS ultimo_nome,
        u.email_corporativo, u.telefone, u.id_company, $companyName AS company_name,
        (SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ',')
           FROM rbac_user_role ur
           JOIN rbac_roles r ON r.role_id=ur.role_id
          WHERE ur.user_id=u.id_user) AS roles_csv
        $selectExtra
        $selectAccess,
        a.filename AS avatar_filename
      FROM usuarios u
      $joinCompany
      $joinAccess
      $joinAv
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
               NULL AS consulta_R, NULL AS edicao_W,
               a.filename AS avatar_filename
        FROM usuarios u
        $joinCompany
        $joinAv
        ORDER BY u.id_user DESC
        LIMIT 100
      ")->fetchAll();
      $total = is_array($rows) ? count($rows) : 0;
    }

    $users = array_map(function($r) use($IS_MASTER, $CAN_MANAGE_USERS, $MEU_ID){
      $roles = array_values(array_filter(array_map('trim', explode(',', (string)($r['roles_csv'] ?? '')))));

      // NOVO: montar URL do avatar pela nova ordem de precedência
      $fn = $r['avatar_filename'] ?? null;
      $avatar = avatar_url_from_filename($fn);
      if (!$avatar) $avatar = avatar_public_path((int)$r['id_user']);
      if (!$avatar) $avatar = '/OKR_system/assets/img/avatars/default_avatar/default.png';

      $u = [
        'id_user'           => (int)$r['id_user'],
        'primeiro_nome'     => $r['primeiro_nome'],
        'ultimo_nome'       => $r['ultimo_nome'],
        'email_corporativo' => $r['email_corporativo'],
        'telefone'          => $r['telefone'],
        'id_company'        => $r['id_company'] !== null ? (int)$r['id_company'] : null,
        'company_name'      => $r['company_name'],
        'roles'             => $roles,
        'avatar'            => $avatar, // <<< NOVO
        'can_edit'          => $CAN_MANAGE_USERS || ((int)$r['id_user']===$MEU_ID),
        'can_delete'        => $CAN_MANAGE_USERS && (int)$r['id_user']!==$MEU_ID && (int)$r['id_user']!==1,

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
    error_log('usuarios_api list: '.$e->getMessage());
    jexit(400, ['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
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
  $extra .= $hasDep   ? ", u.id_departamento" : ", NULL AS id_departamento";
  $extra .= $hasNivel ? ", u.id_nivel_cargo"  : ", NULL AS id_nivel_cargo";
  $extra .= $hasFunc  ? ", u.id_funcao"       : ", NULL AS id_funcao";

  // NOVO: trazer filename do avatar via join
  $st=$pdo->prepare("
      SELECT u.id_user, u.primeiro_nome, COALESCE(u.ultimo_nome,'') AS ultimo_nome,
             u.email_corporativo, u.telefone, u.id_company
             $extra,
             a.filename AS avatar_filename
        FROM usuarios u
        LEFT JOIN avatars a ON a.id = u.avatar_id
       WHERE u.id_user=?
  ");
  $st->execute([$id]);
  $u=$st->fetch();
  if (!$u) jexit(404, ['success'=>false,'error'=>'Usuário não encontrado']);

  $rolesIds   = fetch_user_role_ids($pdo, $id);
  $overrides  = fetch_user_overrides($pdo, $id);
  $summary    = fetch_access_summary($pdo, $id);

  // NOVO: montar URL final do avatar (filename -> arquivo por id -> default)
  $fn = $u['avatar_filename'] ?? null;
  $avatarUrl = avatar_url_from_filename($fn);
  if (!$avatarUrl) $avatarUrl = avatar_public_path($id);
  if (!$avatarUrl) $avatarUrl = '/OKR_system/assets/img/avatars/default_avatar/default.png';

  // manter o avatar dentro do objeto user (mesmo padrão do list)
  $u['avatar'] = $avatarUrl;

  jexit(200, ['success'=>true,'user'=>$u,'roles'=>$rolesIds,'overrides'=>$overrides,'summary'=>$summary,'avatar'=>$avatarUrl]);
}

/* ======================================================
 * GET_PERMISSIONS — roles(ids) + overrides + resumo
 * ====================================================*/
if ($method==='GET' && $action==='get_permissions') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(400, ['success'=>false,'error'=>'ID inválido']);
  if (!$CAN_MANAGE_USERS && $id !== $MEU_ID) jexit(403, ['success'=>false,'error'=>'Sem permissão para ver permissões deste usuário.']);

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
  $isNewUser  = ($id <= 0); // <<< ADICIONADO: flag para saber se é INSERT

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

      $canChangeAcl = $CAN_MANAGE_USERS || $id===$MEU_ID;

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

    /* -----------------------------------------------------------------
     * NOVO: e-mail de boas-vindas com link para criar a senha
     * Somente quando for INSERT (novo usuário)
     * ----------------------------------------------------------------- */
    $emailStatus = null; // null = não aplicável (edição), true = enviado, false = falhou
    $emailError  = null;

    if ($isNewUser && $id > 0) {
      try {
        $ip = $_SERVER['REMOTE_ADDR']     ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Nome da organização para o e-mail (se existir tabela company)
        $orgName = null;
        if ($id_company && table_exists($pdo,'company')) {
          $stmtOrg = $pdo->prepare("SELECT ".company_name_expr()." AS nome FROM company c WHERE c.id_company=?");
          $stmtOrg->execute([$id_company]);
          $orgName = $stmtOrg->fetchColumn() ?: null;
        }

        // Gera o reset (1h) e envia boas-vindas com link de criação de senha
        [$selector, $verifier, $expiraEm] = createPasswordReset($pdo, $id, $ip, $ua, 3600);

        // Template de boas-vindas (ou use sendPasswordResetEmail se preferir)
        $sent = sendWelcomeEmailWithReset(
          $email,
          trim($primeiro.' '.$ultimo),
          $orgName,
          $selector,
          $verifier
        );

        $emailStatus = (bool)$sent;

        app_log('WELCOME_RESET_SENT', [
          'user_id' => $id,
          'email'   => mask_email($email),
          'sent'    => $emailStatus,
          'expires' => $expiraEm
        ]);
      } catch (Throwable $e) {
        $emailStatus = false;
        $emailError  = $e->getMessage();
        app_log('WELCOME_RESET_FAIL', ['user_id'=>$id, 'error'=>$emailError]);
      }
    }

    $resp = ['success'=>true, 'id_user'=>$id];
    if ($emailStatus === true) {
      $resp['email_sent'] = true;
      $resp['email_msg']  = 'E-mail de boas-vindas enviado para ' . $email;
    } elseif ($emailStatus === false) {
      $resp['email_sent'] = false;
      $resp['email_msg']  = 'Usuário criado, mas o e-mail de boas-vindas falhou ao enviar.';
      if ($emailError) $resp['email_error'] = $emailError;
    }
    jexit(200, $resp);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('usuarios_api save: '.$e->getMessage());
    jexit(400, ['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
  }
}

/* ======================================================
 * SAVE_PERMISSIONS — apenas papéis e overrides
 * ====================================================*/
if ($method==='POST' && $action==='save_permissions') {
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);

  if (!$CAN_MANAGE_USERS) jexit(403, ['success'=>false,'error'=>'Sem permissão para alterar papéis e permissões.']);


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
    error_log('usuarios_api save_permissions: '.$e->getMessage());
    jexit(400, ['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
  }
}

/* ======================================================
 * PRE_DELETE_CHECK — preview do que vai acontecer (GET)
 * ====================================================*/
if ($method==='GET' && $action==='pre_delete_check') {
  if (!$CAN_MANAGE_USERS) jexit(403, ['success'=>false,'error'=>'Sem permissão.']);
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);
  if ($id === $MEU_ID) jexit(422, ['success'=>false,'error'=>'Você não pode excluir a si mesmo.']);
  if ($id === 1) jexit(422, ['success'=>false,'error'=>'Usuário #1 não pode ser excluído.']);

  $st = $pdo->prepare("SELECT id_company, primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome FROM usuarios WHERE id_user=?");
  $st->execute([$id]);
  $target = $st->fetch();
  if (!$target) jexit(404, ['success'=>false,'error'=>'Usuário não encontrado.']);

  $targetCompanyId = $target['id_company'] ? (int)$target['id_company'] : null;
  $targetName = trim($target['primeiro_nome'].' '.$target['ultimo_nome']);

  // company_admin scope check
  if (!$IS_MASTER && $targetCompanyId) {
    $myc = get_my_company($pdo, $MEU_ID);
    if ($myc && $targetCompanyId !== (int)$myc) jexit(403, ['success'=>false,'error'=>'Sem permissão para excluir usuários de outra empresa.']);
  }

  $result = [
    'success'    => true,
    'user_name'  => $targetName,
    'user_id'    => $id,
  ];

  if (!$targetCompanyId) {
    // Sem company: cenario reassign simples (reatribui para quem pediu)
    $result['scenario'] = 'reassign';
    $result['item_count'] = count_user_items($pdo, $id);
    $result['reassign_to_name'] = 'você (solicitante)';
    $result['reassign_to_id'] = $MEU_ID;
  } else {
    $companyUsers = count_company_users($pdo, $targetCompanyId);
    if ($companyUsers <= 1) {
      // Cenario solo: deleta tudo
      $companyName = null;
      if (table_exists($pdo, 'company')) {
        $stc = $pdo->prepare("SELECT ".company_name_expr()." AS nome FROM company c WHERE c.id_company=?");
        $stc->execute([$targetCompanyId]);
        $companyName = $stc->fetchColumn() ?: ('Empresa #'.$targetCompanyId);
      }
      $result['scenario'] = 'solo';
      $result['company_name'] = $companyName;
      $result['company_id'] = $targetCompanyId;
    } else {
      // Cenario reassign
      $adminId = find_company_admin($pdo, $targetCompanyId, $id);
      $reassignTo = $adminId ?? $MEU_ID;
      $stAdm = $pdo->prepare("SELECT primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome FROM usuarios WHERE id_user=?");
      $stAdm->execute([$reassignTo]);
      $admRow = $stAdm->fetch();
      $admName = $admRow ? trim($admRow['primeiro_nome'].' '.$admRow['ultimo_nome']) : ('Usuário #'.$reassignTo);

      $result['scenario'] = 'reassign';
      $result['item_count'] = count_user_items($pdo, $id);
      $result['reassign_to_name'] = $admName;
      $result['reassign_to_id'] = $reassignTo;
    }
  }

  jexit(200, $result);
}

/* ======================================================
 * DELETE — exclusão inteligente com cascade/reatribuição
 * Cenário 1 (solo): único usuário da company → deleta tudo
 * Cenário 2 (reassign): múltiplos → reatribui itens ao admin
 * ====================================================*/
if ($method==='POST' && $action==='delete') {
  if (!$CAN_MANAGE_USERS) jexit(403, ['success'=>false,'error'=>'Sem permissão para excluir usuários.']);
  $id = (int)($_POST['id_user'] ?? 0);
  if ($id<=0) jexit(422, ['success'=>false,'error'=>'ID inválido']);
  if ($id === $MEU_ID) jexit(422, ['success'=>false,'error'=>'Você não pode excluir a si mesmo.']);
  if ($id === 1) jexit(422, ['success'=>false,'error'=>'Usuário #1 não pode ser excluído.']);

  // Buscar dados do usuario alvo
  $st = $pdo->prepare("SELECT id_company, primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome FROM usuarios WHERE id_user=?");
  $st->execute([$id]);
  $target = $st->fetch();
  if (!$target) jexit(404, ['success'=>false,'error'=>'Usuário não encontrado.']);

  $targetCompanyId = $target['id_company'] ? (int)$target['id_company'] : null;
  $targetName = trim($target['primeiro_nome'].' '.$target['ultimo_nome']);

  // company_admin só exclui da própria empresa
  if (!$IS_MASTER && $targetCompanyId) {
    $myc = get_my_company($pdo, $MEU_ID);
    if ($myc && $targetCompanyId !== (int)$myc) jexit(403, ['success'=>false,'error'=>'Sem permissão para excluir usuários de outra empresa.']);
  }

  // Determinar cenario
  $scenario = 'reassign';
  if ($targetCompanyId) {
    $companyUsers = count_company_users($pdo, $targetCompanyId);
    if ($companyUsers <= 1) $scenario = 'solo';
  }

  $pdo->beginTransaction();
  try {
    if ($scenario === 'solo') {
      // ========== CENARIO 1: deletar tudo ==========
      delete_company_cascade($pdo, $targetCompanyId, $id);
    } else {
      // ========== CENARIO 2: reatribuir + deletar usuario ==========
      $reassignTo = $MEU_ID; // fallback
      if ($targetCompanyId) {
        $adminId = find_company_admin($pdo, $targetCompanyId, $id);
        if ($adminId) $reassignTo = $adminId;
      }

      // Reatribuir itens OKR
      $counts = reassign_user_items($pdo, $id, $reassignTo);

      // Limpar dados pessoais/RBAC do usuario
      safe_delete_user_rows($pdo, 'rbac_user_capability', $id);
      safe_delete_user_rows($pdo, 'rbac_user_role',       $id);
      safe_delete_user_rows($pdo, 'usuarios_permissoes',       $id);
      safe_delete_user_rows($pdo, 'usuarios_paginas',          $id);
      safe_delete_user_rows($pdo, 'usuarios_planos',           $id);
      safe_delete_user_rows($pdo, 'usuarios_credenciais',      $id);
      safe_delete_user_rows($pdo, 'usuarios_password_resets',  $id);

      // Desvincula FKs RESTRICT antes de deletar o usuario
      //   avatar_id é NOT NULL DEFAULT 1, reseta para 1
      $setCols = [];
      foreach (['id_departamento','id_nivel_cargo','id_permissao'] as $col) {
        if (column_exists($pdo, 'usuarios', $col)) $setCols[] = "`$col` = NULL";
      }
      if (column_exists($pdo, 'usuarios', 'avatar_id')) $setCols[] = "`avatar_id` = 1";
      if ($setCols) {
        $pdo->prepare("UPDATE usuarios SET " . implode(', ', $setCols) . " WHERE id_user = ?")->execute([$id]);
      }
      $pdo->prepare("DELETE FROM usuarios WHERE id_user=?")->execute([$id]);

      // Notificar o admin destino (se tem itens reatribuidos)
      if (!empty($counts) && table_exists($pdo, 'notificacoes')) {
        require_once __DIR__ . '/notify.php';
        $parts = [];
        foreach ($counts as $tipo => $n) {
          $parts[] = "{$n} {$tipo}";
        }
        $body = "Os seguintes itens do usuário excluído \"{$targetName}\" foram reatribuídos para você: " . implode(', ', $parts) . '.';
        notify_inapp($pdo, $reassignTo, 'Itens reatribuídos — usuário excluído', $body);
      }
    }

    $pdo->commit();

    // Fora da transação: limpar avatars
    $base = __DIR__ . '/../assets/img/avatars/';
    foreach (['png','jpg','jpeg'] as $ext) {
      $f = $base.$id.'.'.$ext;
      if (is_file($f)) @unlink($f);
    }

    jexit(200, ['success'=>true, 'scenario'=>$scenario]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('usuarios_api delete: '.$e->getMessage());
    jexit(400, ['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
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
