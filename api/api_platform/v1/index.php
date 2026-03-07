<?php
declare(strict_types=1);

require_once __DIR__ . '/_core.php';
require_once __DIR__ . '/_middleware.php';

$method = api_method();

/* ===================== CORS PRE-FLIGHT ===================== */
if ($method === 'OPTIONS') {
  api_cors_headers();
  api_no_cache();
  http_response_code(204);
  exit;
}

/* ===================== PATH RESOLUTION ===================== */
$path = (string)($_GET['path'] ?? '');
if ($path === '') {
  $uri  = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
  $base = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  if ($base !== '' && str_starts_with($uri, $base)) {
    $path = substr($uri, strlen($base));
  } else {
    $path = $uri;
  }
}
$path = trim($path, '/');

/* ===================== DEBUG / PING ===================== */
if ($path === 'debug/echo') {
  api_echo_debug();
}
if ($method === 'GET' && $path === 'ping') {
  api_json(['ok' => true, 'api' => 'planningbi-okr', 'version' => 'v1', 'time' => date('c')]);
}

/* ===================== ROUTE TABLE ===================== */
$routes = [
  // Auth
  ['POST',   'auth/login',             'auth/login.php'],
  ['POST',   'auth/register',          'auth/register.php'],
  ['POST',   'auth/forgot-password',   'auth/forgot_password.php'],
  ['POST',   'auth/reset-password',    'auth/reset_password.php'],
  ['POST',   'auth/refresh-token',     'auth/refresh_token.php'],
  ['GET',    'auth/me',                'auth/me.php'],
  ['PUT',    'auth/me',                'auth/me_update.php'],
  ['POST',   'auth/avatar',            'auth/avatar_upload.php'],
  ['DELETE', 'auth/avatar',            'auth/avatar_delete.php'],

  // Company
  ['GET',    'company/me',             'company/me.php'],
  ['PUT',    'company/me',             'company/me_update.php'],
  ['GET',    'company/style',          'company/style.php'],
  ['PUT',    'company/style',          'company/style_update.php'],

  // Minhas Tarefas
  ['GET',    'minhas-tarefas',         'tarefas/minhas.php'],

  // Dashboard
  ['GET',    'dashboard/summary',      'dashboard/summary.php'],
  ['GET',    'dashboard/cascata',      'dashboard/cascata.php'],
  ['GET',    'dashboard/mapa-estrategico', 'dashboard/mapa_estrategico.php'],

  // Objetivos
  ['GET',    'objetivos',              'objetivos/list.php'],
  ['POST',   'objetivos',              'objetivos/create.php'],
  ['GET',    'objetivos/:id',          'objetivos/get.php'],
  ['PUT',    'objetivos/:id',          'objetivos/update.php'],
  ['DELETE', 'objetivos/:id',          'objetivos/delete.php'],

  // Key Results (nested under objetivo for listing)
  ['GET',    'objetivos/:id_objetivo/krs', 'krs/list.php'],
  ['POST',   'krs',                    'krs/create.php'],
  ['GET',    'krs/:id',                'krs/get.php'],
  ['PUT',    'krs/:id',                'krs/update.php'],
  ['DELETE', 'krs/:id',                'krs/delete.php'],
  ['POST',   'krs/:id/cancelar',       'krs/cancel.php'],
  ['POST',   'krs/:id/reativar',       'krs/reactivate.php'],
  ['GET',    'krs/:id/milestones',     'krs/milestones.php'],

  // Apontamentos
  ['GET',    'krs/:id_kr/apontamentos',     'apontamentos/list.php'],
  ['POST',   'krs/:id_kr/apontamentos',     'apontamentos/create.php'],
  ['GET',    'krs/:id_kr/apontamentos/modal-data', 'apontamentos/modal_data.php'],
  ['DELETE', 'apontamentos/:id',             'apontamentos/delete.php'],

  // Iniciativas
  ['GET',    'krs/:id_kr/iniciativas',       'iniciativas/list.php'],
  ['POST',   'iniciativas',                  'iniciativas/create.php'],
  ['GET',    'iniciativas/:id',              'iniciativas/get.php'],
  ['PUT',    'iniciativas/:id',              'iniciativas/update.php'],
  ['DELETE', 'iniciativas/:id',              'iniciativas/delete.php'],
  ['PUT',    'iniciativas/:id/status',       'iniciativas/update_status.php'],

  // Orçamentos
  ['GET',    'orcamentos',                     'orcamentos/resumo.php'],
  ['GET',    'orcamento-resumo',               'orcamentos/resumo.php'],
  ['GET',    'krs/:id_kr/orcamento-dashboard', 'orcamentos/dashboard.php'],
  ['GET',    'iniciativas/:id_ini/orcamentos', 'orcamentos/list.php'],
  ['POST',   'orcamentos',                    'orcamentos/create.php'],
  ['PUT',    'orcamentos/:id',                'orcamentos/update.php'],
  ['POST',   'orcamentos/:id/despesas',       'orcamentos/add_despesa.php'],

  // Aprovações
  ['GET',    'aprovacoes',                     'aprovacoes/list.php'],
  ['POST',   'aprovacoes/decidir',             'aprovacoes/decidir.php'],

  // Notificações
  ['GET',    'notificacoes',                   'notificacoes/list.php'],
  ['GET',    'notificacoes/count',             'notificacoes/count.php'],
  ['PUT',    'notificacoes/:id/lida',          'notificacoes/mark_read.php'],
  ['PUT',    'notificacoes/todas/lida',        'notificacoes/mark_all_read.php'],

  // Usuários
  ['GET',    'usuarios',                       'usuarios/list.php'],
  ['POST',   'usuarios',                       'usuarios/create.php'],
  ['GET',    'usuarios/:id',                   'usuarios/get.php'],
  ['PUT',    'usuarios/:id',                   'usuarios/update.php'],
  ['DELETE', 'usuarios/:id',                   'usuarios/delete.php'],
  ['PUT',    'usuarios/:id/role',              'usuarios/role.php'],
  ['GET',    'usuarios/:id/pre-delete',        'usuarios/pre_delete.php'],
  ['GET',    'usuarios/:id/permissions',       'usuarios/get_permissions.php'],
  ['PUT',    'usuarios/:id/permissions',       'usuarios/save_permissions.php'],

  // Domínios (tabelas de lookup)
  ['GET',    'dominios/:tabela',               'dominios/get.php'],
  ['GET',    'responsaveis',                   'dominios/responsaveis.php'],
];

/* ===================== ROUTE MATCHING ===================== */
foreach ($routes as [$rMethod, $rPattern, $rHandler]) {
  if ($rMethod !== $method) continue;

  // Build regex from pattern
  $paramNames = [];
  $regex = preg_replace_callback('/:([a-zA-Z_]+)/', function ($m) use (&$paramNames) {
    $paramNames[] = $m[1];
    return '([^/]+)';
  }, $rPattern);
  $regex = '#^' . $regex . '$#';

  if (preg_match($regex, $path, $matches)) {
    // Extract named params
    array_shift($matches);
    foreach ($paramNames as $i => $name) {
      $GLOBALS['_route_params'][$name] = $matches[$i] ?? '';
    }

    $handlerFile = __DIR__ . '/' . $rHandler;
    if (!is_file($handlerFile)) {
      api_error('E_SERVER', "Handler não implementado: $rHandler", 501);
    }
    require $handlerFile;
    exit;
  }
}

api_error('E_NOT_FOUND', 'Rota não encontrada.', 404, ['path' => $path, 'method' => $method]);
