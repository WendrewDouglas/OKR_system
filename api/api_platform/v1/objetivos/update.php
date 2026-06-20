<?php
declare(strict_types=1);

/**
 * PUT /objetivos/:id
 * Atualiza um objetivo existente.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

// Verify exists + tenant
$st = $pdo->prepare("SELECT id_objetivo, id_company, observacoes FROM objetivos WHERE id_objetivo = ?");
$st->execute([$id]);
$obj = $st->fetch();
if (!$obj || (int)$obj['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

if (!api_has_cap($pdo, $uid, $cid, 'W:objetivo@ORG', ['id_objetivo' => $id])) {
  api_error('E_FORBIDDEN', 'Sem permissão para editar este objetivo.', 403);
}

$in = api_input();

// Edição exige justificativa (paridade com o web): registra o motivo e reenvia
// o objetivo para aprovação.
$justificativa = trim(api_str($in['justificativa'] ?? ''));
if ($justificativa === '') {
  api_error('E_INPUT', 'A justificativa de edição é obrigatória.', 422);
}

// Valida status contra o domínio (objetivos.status → dom_status_kr); 422 em vez de 500 do FK
if (array_key_exists('status', $in)) {
  api_assert_domain($pdo, 'dom_status_kr', 'id_status', api_str($in['status']), 'status');
}

// Nome do editor (para o bloco de justificativa e usuario_ult_alteracao).
$stNome = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(primeiro_nome,''),' ',COALESCE(ultimo_nome,''))) FROM usuarios WHERE id_user = ? LIMIT 1");
$stNome->execute([$uid]);
$editorName = (string)($stNome->fetchColumn() ?: $uid);

$sets   = [];
$params = [];

// Mapeamento chave-de-entrada → coluna (espelha create.php: a API recebe
// 'tipo_objetivo' e 'ciclo_tipo', mas as colunas são 'tipo' e 'tipo_ciclo').
$map = [
  'descricao'     => 'descricao',
  'pilar_bsc'     => 'pilar_bsc',
  'tipo_objetivo' => 'tipo',
  'ciclo_tipo'    => 'tipo_ciclo',
  'status'        => 'status',
  'qualidade'     => 'qualidade',
];
foreach ($map as $key => $col) {
  if (array_key_exists($key, $in)) {
    $sets[]   = "$col = ?";
    $params[] = api_str($in[$key]);
  }
}

if (array_key_exists('dono', $in)) {
  $sets[]   = "dono = ?";
  $params[] = api_int($in['dono'], 'dono');
}

// Observações: anexa o bloco de justificativa ao texto (espelha o web).
$obsBase = array_key_exists('observacoes', $in)
  ? trim(api_str($in['observacoes']))
  : trim((string)($obj['observacoes'] ?? ''));
$bloco = "\n\n---\n[Justificativa de edição em " . date('Y-m-d H:i') . " por {$editorName}]\n{$justificativa}";
$sets[]   = "observacoes = ?";
$params[] = ($obsBase !== '' ? $obsBase : '[Histórico de Observações]') . $bloco;

// Datas: recalcula a partir do detalhe do ciclo (paridade com create.php) quando
// vier 'ciclo_tipo' + parâmetros. Aceita ciclo personalizado com data precisa.
$cicloTipo = api_str($in['ciclo_tipo'] ?? '');
$dtPrazoSet = false;
if ($cicloTipo !== '') {
  api_load_helper('auth/helpers/cycle_calc.php');
  [$dtIni, $dtFim] = calcularDatasCiclo($cicloTipo, $in);
  if ($dtIni !== '' && $dtFim !== '') {
    $sets[]   = "dt_inicio = ?";
    $params[] = $dtIni;
    $sets[]   = "dt_prazo = ?";
    $params[] = $dtFim;
    $dtPrazoSet = true;
  }
}
// Fallback: dt_prazo enviado diretamente (quando não recalculado pelo ciclo).
if (!$dtPrazoSet && array_key_exists('dt_prazo', $in)) {
  $sets[]   = "dt_prazo = ?";
  $params[] = api_date_or_null($in['dt_prazo']);
}

// Edição reenvia para aprovação (espelha o web): zera o estado de aprovação.
$sets[] = "status_aprovacao = 'pendente'";
$sets[] = "aprovador = NULL";
$sets[] = "dt_aprovacao = NULL";
$sets[] = "comentarios_aprovacao = NULL";
$sets[]   = "usuario_ult_alteracao = ?";
$params[] = $editorName;
$sets[]   = "dt_ultima_atualizacao = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE objetivos SET " . implode(', ', $sets) . " WHERE id_objetivo = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Objetivo atualizado e reenviado para aprovação.']);
