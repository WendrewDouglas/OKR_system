<?php
declare(strict_types=1);

/**
 * GET /usuarios/:id
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

$isMaster = api_is_admin_master($pdo, $uid);

$st = $pdo->prepare("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo,
         u.id_company, u.telefone, u.dt_cadastro,
         u.id_departamento, u.id_nivel_cargo,
         c.organizacao AS empresa,
         r.role_key, r.role_name,
         a.filename AS avatar_filename
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
    LEFT JOIN rbac_roles r ON r.role_id = ur.role_id
    LEFT JOIN avatars a ON a.id = u.avatar_id
   WHERE u.id_user = ?
");
$st->execute([$id]);
$user = $st->fetch();

if (!$user) {
  api_error('E_NOT_FOUND', 'Usuário não encontrado.', 404);
}

// Tenant: non-master can only see same company
if (!$isMaster && (int)$user['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Usuário não encontrado.', 404);
}

api_json([
  'ok'   => true,
  'user' => [
    'id_user'         => (int)$user['id_user'],
    'primeiro_nome'   => $user['primeiro_nome'],
    'ultimo_nome'     => $user['ultimo_nome'] ?? '',
    'email'           => $user['email_corporativo'],
    'telefone'        => $user['telefone'] ?? '',
    'id_company'      => $user['id_company'] ? (int)$user['id_company'] : null,
    'empresa'         => $user['empresa'] ?? '',
    'id_departamento' => $user['id_departamento'] ? (int)$user['id_departamento'] : null,
    'id_nivel_cargo'  => $user['id_nivel_cargo'] ? (int)$user['id_nivel_cargo'] : null,
    'role_key'        => $user['role_key'] ?? '',
    'role_name'       => $user['role_name'] ?? '',
    'avatar'          => $user['avatar_filename'] ?? null,
    'dt_cadastro'     => $user['dt_cadastro'],
  ],
]);
