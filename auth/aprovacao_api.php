<?php
// auth/aprovacao_api.php — versão com permissões, IDs de aprovador, notificações
declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

date_default_timezone_set('America/Sao_Paulo');

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__.'/../auth/acl.php';

function jexit(int $code, array $payload){ http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['user_id'])) jexit(401,['success'=>false,'error'=>'Não autenticado.']);
$MEU_ID = (string)$_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  $csrf = $_POST['csrf_token'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) jexit(403,['success'=>false,'error'=>'CSRF inválido.']);
}

try {
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) { jexit(500,['success'=>false,'error'=>'Erro ao conectar: '.$e->getMessage()]); }

$st = $pdo->prepare("SELECT primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome FROM usuarios WHERE id_user=?");
$st->execute([$MEU_ID]);
$u = $st->fetch() ?: ['primeiro_nome'=>'Usuário','ultimo_nome'=>''];
$MEU_NOME = trim(($u['primeiro_nome']??'').' '.($u['ultimo_nome']??''));

$st = $pdo->prepare("SELECT tudo, habilitado FROM aprovadores WHERE id_user=? LIMIT 1");
$st->execute([$MEU_ID]);
$aprov = $st->fetch();
$IS_APROVADOR = $aprov && ((int)$aprov['habilitado']===1);
$IS_MASTER    = $IS_APROVADOR && ((int)$aprov['tudo']===1);

/* ===== permissões por módulo ===== */
function allowedModules(PDO $pdo, string $uid, bool $isMaster): array {
  if ($isMaster) return ['objetivo','kr','orcamento'];
  $mods = [];
  $st = $pdo->prepare("SELECT DISTINCT tipo_estrutura FROM permissoes_aprovador WHERE id_user=? AND status_aprovacao='pendente'");
  $st->execute([$uid]);
  foreach ($st->fetchAll() as $r) {
    $m = strtolower(trim($r['tipo_estrutura'] ?? ''));
    if (in_array($m, ['objetivo','kr','orcamento'], true)) $mods[] = $m;
  }
  return array_values(array_unique($mods));
}

/* ===== helper join último movimento ===== */
// [MOV] Subselect que pega o último movimento por referência & módulo
function mov_join_sql(string $mod): string {
  $mod = strtolower($mod);
  return "
    LEFT JOIN (
      SELECT m.*
      FROM aprovacao_movimentos m
      JOIN (
        SELECT MAX(id_movimento) AS id_movimento, tipo_estrutura, id_referencia
        FROM aprovacao_movimentos
        WHERE tipo_estrutura = '{$mod}'
        GROUP BY tipo_estrutura, id_referencia
      ) ult
        ON ult.id_movimento = m.id_movimento
    ) mov ON mov.tipo_estrutura = '{$mod}' AND mov.id_referencia = {ID_COL}
  ";
}

/* ===== consultas ===== */
function q_para_aprovar(PDO $pdo, array $mods): array {
  $rows = [];
  if (in_array('objetivo',$mods,true)) {
    $sql = "
      SELECT 'objetivo' AS module, o.id_objetivo AS id, o.descricao,
             LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
             o.usuario_criador AS usuario_criador_nome, o.id_user_criador AS usuario_criador_id,
             DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao,
             DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
             o.comentarios_aprovacao, NULL AS resumo, NULL AS objetivo_id, NULL AS objetivo_desc,
             NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
             mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
      FROM objetivos o
      ".str_replace('{ID_COL}','o.id_objetivo', mov_join_sql('objetivo'))."
      WHERE LOWER(COALESCE(o.status_aprovacao,''))='pendente'
    ";
    $rows = array_merge($rows, $pdo->query($sql)->fetchAll() ?: []);
  }
  if (in_array('kr',$mods,true)) {
    $sql = "
      SELECT 'kr' AS module, k.id_kr AS id, k.descricao,
             LOWER(COALESCE(k.status_aprovacao,'')) AS status_aprovacao,
             k.usuario_criador AS usuario_criador_nome, k.id_user_criador AS usuario_criador_id,
             DATE_FORMAT(k.dt_criacao,'%d/%m/%Y') AS dt_criacao,
             DATE_FORMAT(k.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
             k.comentarios_aprovacao, NULL AS resumo,
             k.id_objetivo AS objetivo_id, o.descricao AS objetivo_desc,
             NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
             mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
      FROM key_results k
      LEFT JOIN objetivos o ON o.id_objetivo=k.id_objetivo
      ".str_replace('{ID_COL}','k.id_kr', mov_join_sql('kr'))."
      WHERE LOWER(COALESCE(k.status_aprovacao,''))='pendente'
    ";
    $rows = array_merge($rows, $pdo->query($sql)->fetchAll() ?: []);
  }
  if (in_array('orcamento',$mods,true)) {
    $sql = "
      SELECT 'orcamento' AS module, o.id_orcamento AS id, NULL AS descricao,
             LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
             CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS usuario_criador_nome,
             o.id_user_criador AS usuario_criador_id,
             DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao,
             DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
             o.comentarios_aprovacao, CONCAT('Iniciativa: ',COALESCE(o.id_iniciativa,'—')) AS resumo,
             NULL AS objetivo_id, NULL AS objetivo_desc,
             o.id_iniciativa, o.valor, o.justificativa_orcamento AS justificativa,
             mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
      FROM orcamentos o
      LEFT JOIN usuarios u ON u.id_user=o.id_user_criador
      ".str_replace('{ID_COL}','o.id_orcamento', mov_join_sql('orcamento'))."
      WHERE LOWER(COALESCE(o.status_aprovacao,''))='pendente'
    ";
    $rows = array_merge($rows, $pdo->query($sql)->fetchAll() ?: []);
  }
  foreach ($rows as &$r) { $r['scope']='para_aprovar'; }
  return $rows;
}

function q_minhas(PDO $pdo, string $MEU_ID, string $MEU_NOME): array {
  $rows=[];

  $st=$pdo->prepare("
    SELECT 'objetivo' AS module, o.id_objetivo AS id, o.descricao,
           LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
           o.usuario_criador AS usuario_criador_nome, o.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao,
           DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           o.comentarios_aprovacao, NULL AS resumo, NULL AS objetivo_id, NULL AS objetivo_desc,
           NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM objetivos o
    ".str_replace('{ID_COL}','o.id_objetivo', mov_join_sql('objetivo'))."
    WHERE (o.id_user_criador = :id OR (o.id_user_criador IS NULL AND o.usuario_criador=:nome))
  ");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  $st=$pdo->prepare("
    SELECT 'kr' AS module, k.id_kr AS id, k.descricao,
           LOWER(COALESCE(k.status_aprovacao,'')) AS status_aprovacao,
           k.usuario_criador AS usuario_criador_nome, k.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(k.dt_criacao,'%d/%m/%Y') AS dt_criacao,
           DATE_FORMAT(k.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           k.comentarios_aprovacao, NULL AS resumo, k.id_objetivo AS objetivo_id, o.descricao AS objetivo_desc,
           NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM key_results k
    LEFT JOIN objetivos o ON o.id_objetivo=k.id_objetivo
    ".str_replace('{ID_COL}','k.id_kr', mov_join_sql('kr'))."
    WHERE (k.id_user_criador = :id OR (k.id_user_criador IS NULL AND k.usuario_criador=:nome))
  ");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  $st=$pdo->prepare("
    SELECT 'orcamento' AS module, o.id_orcamento AS id, NULL AS descricao,
           LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
           CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS usuario_criador_nome, o.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao,
           DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           o.comentarios_aprovacao, CONCAT('Iniciativa: ',COALESCE(o.id_iniciativa,'—')) AS resumo,
           NULL AS objetivo_id, NULL AS objetivo_desc, o.id_iniciativa, o.valor, o.justificativa_orcamento AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM orcamentos o
    LEFT JOIN usuarios u ON u.id_user=o.id_user_criador
    ".str_replace('{ID_COL}','o.id_orcamento', mov_join_sql('orcamento'))."
    WHERE o.id_user_criador=:id
  ");
  $st->execute([':id'=>$MEU_ID]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  foreach($rows as &$r){ $r['scope']='minhas'; if(!$r['usuario_criador_id']) $r['usuario_criador_id']=$MEU_ID; }
  return $rows;
}

function q_reprovados_do_meu_usuario(PDO $pdo, string $MEU_ID, string $MEU_NOME): array {
  $rows=[];

  $st=$pdo->prepare("
    SELECT 'objetivo' AS module, o.id_objetivo AS id, o.descricao,
           LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
           o.usuario_criador AS usuario_criador_nome, o.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao, DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           o.comentarios_aprovacao, NULL AS resumo, NULL AS objetivo_id, NULL AS objetivo_desc,
           NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM objetivos o
    ".str_replace('{ID_COL}','o.id_objetivo', mov_join_sql('objetivo'))."
    WHERE LOWER(COALESCE(o.status_aprovacao,''))='reprovado'
      AND (o.id_user_criador=:id OR (o.id_user_criador IS NULL AND o.usuario_criador=:nome))
  ");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  $st=$pdo->prepare("
    SELECT 'kr' AS module, k.id_kr AS id, k.descricao,
           LOWER(COALESCE(k.status_aprovacao,'')) AS status_aprovacao,
           k.usuario_criador AS usuario_criador_nome, k.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(k.dt_criacao,'%d/%m/%Y') AS dt_criacao, DATE_FORMAT(k.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           k.comentarios_aprovacao, NULL AS resumo, k.id_objetivo AS objetivo_id, o.descricao AS objetivo_desc,
           NULL AS id_iniciativa, NULL AS valor, NULL AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM key_results k
    LEFT JOIN objetivos o ON o.id_objetivo=k.id_objetivo
    ".str_replace('{ID_COL}','k.id_kr', mov_join_sql('kr'))."
    WHERE LOWER(COALESCE(k.status_aprovacao,''))='reprovado'
      AND (k.id_user_criador=:id OR (k.id_user_criador IS NULL AND k.usuario_criador=:nome))
  ");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  $st=$pdo->prepare("
    SELECT 'orcamento' AS module, o.id_orcamento AS id, NULL AS descricao,
           LOWER(COALESCE(o.status_aprovacao,'')) AS status_aprovacao,
           CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS usuario_criador_nome, o.id_user_criador AS usuario_criador_id,
           DATE_FORMAT(o.dt_criacao,'%d/%m/%Y') AS dt_criacao, DATE_FORMAT(o.dt_aprovacao,'%d/%m/%Y %H:%i') AS dt_aprovacao,
           o.comentarios_aprovacao, CONCAT('Iniciativa: ',COALESCE(o.id_iniciativa,'—')) AS resumo,
           NULL AS objetivo_id, NULL AS objetivo_desc, o.id_iniciativa, o.valor, o.justificativa_orcamento AS justificativa,
           mov.tipo_movimento AS mov_tipo, mov.justificativa AS mov_just, mov.campos_diff_json AS mov_campos_json
    FROM orcamentos o
    LEFT JOIN usuarios u ON u.id_user=o.id_user_criador
    ".str_replace('{ID_COL}','o.id_orcamento', mov_join_sql('orcamento'))."
    WHERE LOWER(COALESCE(o.status_aprovacao,''))='reprovado'
      AND o.id_user_criador=:id
  ");
  $st->execute([':id'=>$MEU_ID]); $rows=array_merge($rows,$st->fetchAll()?:[]);

  foreach($rows as &$r){ $r['scope']='reprovados'; if(!$r['usuario_criador_id']) $r['usuario_criador_id']=$MEU_ID; }
  return $rows;
}

/* ===== stats ===== */
function stats_pills(PDO $pdo, string $MEU_ID, string $MEU_NOME, bool $IS_APROVADOR, array $mods): array {
  $pend=0;
  if ($IS_APROVADOR) {
    if (in_array('objetivo',$mods,true))  $pend += (int)$pdo->query("SELECT COUNT(*) c FROM objetivos  WHERE LOWER(COALESCE(status_aprovacao,''))='pendente'")->fetch()['c'];
    if (in_array('kr',$mods,true))        $pend += (int)$pdo->query("SELECT COUNT(*) c FROM key_results WHERE LOWER(COALESCE(status_aprovacao,''))='pendente'")->fetch()['c'];
    if (in_array('orcamento',$mods,true)) $pend += (int)$pdo->query("SELECT COUNT(*) c FROM orcamentos  WHERE LOWER(COALESCE(status_aprovacao,''))='pendente'")->fetch()['c'];
  }
  $st=$pdo->prepare("SELECT COUNT(*) c FROM objetivos WHERE (id_user_criador=:id OR (id_user_criador IS NULL AND usuario_criador=:nome)) AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $reprob=(int)($st->fetch()['c']??0);
  $st=$pdo->prepare("SELECT COUNT(*) c FROM key_results WHERE (id_user_criador=:id OR (id_user_criador IS NULL AND usuario_criador=:nome)) AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $reprob+=(int)($st->fetch()['c']??0);
  $st=$pdo->prepare("SELECT COUNT(*) c FROM orcamentos WHERE id_user_criador=:id AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
  $st->execute([':id'=>$MEU_ID]); $reprob+=(int)($st->fetch()['c']??0);

  $st=$pdo->prepare("SELECT COUNT(*) c FROM objetivos WHERE (id_user_criador=:id OR (id_user_criador IS NULL AND usuario_criador=:nome)) AND LOWER(COALESCE(status_aprovacao,''))='aprovado' AND dt_aprovacao >= (NOW() - INTERVAL 30 DAY)");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $ap30=(int)($st->fetch()['c']??0);
  $st=$pdo->prepare("SELECT COUNT(*) c FROM key_results WHERE (id_user_criador=:id OR (id_user_criador IS NULL AND usuario_criador=:nome)) AND LOWER(COALESCE(status_aprovacao,''))='aprovado' AND dt_aprovacao >= (NOW() - INTERVAL 30 DAY)");
  $st->execute([':id'=>$MEU_ID, ':nome'=>$MEU_NOME]); $ap30+=(int)($st->fetch()['c']??0);
  $st=$pdo->prepare("SELECT COUNT(*) c FROM orcamentos WHERE id_user_criador=:id AND LOWER(COALESCE(status_aprovacao,''))='aprovado' AND dt_aprovacao >= (NOW() - INTERVAL 30 DAY)");
  $st->execute([':id'=>$MEU_ID]); $ap30+=(int)($st->fetch()['c']??0);

  return ['pendentes'=>$pend, 'reprovados'=>$reprob, 'aprovados30'=>$ap30];
}

/* ===== GET summary ===== */
if ($method==='GET') {
  $action = $_GET['action'] ?? 'summary';
  if ($action!=='summary') jexit(400,['success'=>false,'error'=>'Ação inválida.']);

  $mods = $IS_APROVADOR ? allowedModules($pdo, $MEU_ID, $IS_MASTER) : [];
  $rows = [];
  if ($IS_APROVADOR && $mods) $rows = array_merge($rows, q_para_aprovar($pdo, $mods));
  $rows = array_merge($rows, q_minhas($pdo, $MEU_ID, $MEU_NOME));
  $rows = array_merge($rows, q_reprovados_do_meu_usuario($pdo, $MEU_ID, $MEU_NOME));

  // [MOV] normaliza payload de movimentos (decoda JSON)
  foreach ($rows as &$r) {
    $diff = [];
    if (!empty($r['mov_campos_json'])) {
      $tmp = json_decode($r['mov_campos_json'], true);
      if (is_array($tmp)) $diff = $tmp;
    }
    $r['mov_diffs'] = $diff;
    unset($r['mov_campos_json']);
  }

  $stats = stats_pills($pdo, $MEU_ID, $MEU_NOME, $IS_APROVADOR, $mods);
  jexit(200, ['success'=>true,'stats'=>$stats,'rows'=>$rows]);
}

/* ===== POST actions (inalterado) ===== */
function norm($s){ return strtolower(trim((string)$s)); }
$action = norm($_POST['action'] ?? '');
$module = norm($_POST['module'] ?? '');
$id     = trim((string)($_POST['id'] ?? ''));
$obs    = trim((string)($_POST['comentarios'] ?? ''));

if (!in_array($action,['approve','reject','resubmit'],true)) jexit(400,['success'=>false,'error'=>'Ação inválida.']);
if (!in_array($module,['objetivo','kr','orcamento'],true)) jexit(400,['success'=>false,'error'=>'Módulo inválido.']);
if (in_array($action,['reject','resubmit'],true) && $obs==='') jexit(422,['success'=>false,'error'=>'Justificativa/observações são obrigatórias.']);
if (in_array($action,['approve','reject'],true) && !$IS_APROVADOR) jexit(403,['success'=>false,'error'=>'Sem permissão para aprovar/reprovar.']);

$ip  = $_SERVER['REMOTE_ADDR'] ?? null;
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255);
$now = date('Y-m-d H:i:s');
$decisao = ($action==='approve')?'aprovado':(($action==='reject')?'reprovado':'pendente');

$pdo->beginTransaction();
try {
  $ctx = ['module'=>$module,'id'=>$id,'acao'=>$action,'status'=>$decisao,'obs'=>$obs,'solicitante_id'=>$MEU_ID,'aprovador_id'=>null];

  $insFluxo = $pdo->prepare("
    INSERT INTO fluxo_aprovacoes
      (tipo_estrutura, id_referencia, id_entidade, tipo_operacao, motivo_solicitacao, dados_solicitados,
       id_user_solicitante, status, id_user_aprovador, justificativa, contexto_origem,
       data_solicitacao, data_aprovacao, ip, user_agent)
    VALUES
      (:tipo, :id_ref, NULL, :op, NULL, '',
       :sol, :st, :aprov, :just, 'aprovacao_ui',
       :ds, :da, :ip, :ua)
  ");

  if ($module==='objetivo') {
    if ($action==='resubmit') {
      $st=$pdo->prepare("UPDATE objetivos SET status_aprovacao='pendente', aprovador=NULL, id_user_aprovador=NULL, dt_aprovacao=NULL
                         WHERE id_objetivo=? AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
      $st->execute([(int)$id]);
      if ($st->rowCount()===0) throw new Exception('Só é possível reenviar itens reprovados.');
    } else {
      $st=$pdo->prepare("UPDATE objetivos SET status_aprovacao=:st, aprovador=:nome_ap,
                         id_user_aprovador=:id_ap, dt_aprovacao=:dt, comentarios_aprovacao=:obs
                         WHERE id_objetivo=:id AND LOWER(COALESCE(status_aprovacao,''))='pendente'");
      $st->execute([':st'=>$decisao, ':nome_ap'=>$MEU_NOME, ':id_ap'=>$MEU_ID, ':dt'=>$now, ':obs'=>$obs, ':id'=>(int)$id]);
      if ($st->rowCount()===0) throw new Exception('Estado não é mais pendente.');
      $ctx['aprovador_id'] = (int)$MEU_ID;
    }
  } elseif ($module==='kr') {
    if ($action==='resubmit') {
      $st=$pdo->prepare("UPDATE key_results SET status_aprovacao='pendente', aprovador=NULL, id_user_aprovador=NULL, dt_aprovacao=NULL
                         WHERE id_kr=? AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
      $st->execute([$id]);
      if ($st->rowCount()===0) throw new Exception('Só é possível reenviar itens reprovados.');
    } else {
      $st=$pdo->prepare("UPDATE key_results SET status_aprovacao=:st, aprovador=:nome_ap,
                         id_user_aprovador=:id_ap, dt_aprovacao=:dt, comentarios_aprovacao=:obs
                         WHERE id_kr=:id AND LOWER(COALESCE(status_aprovacao,''))='pendente'");
      $st->execute([':st'=>$decisao, ':nome_ap'=>$MEU_NOME, ':id_ap'=>$MEU_ID, ':dt'=>$now, ':obs'=>$obs, ':id'=>$id]);
      if ($st->rowCount()===0) throw new Exception('Estado não é mais pendente.');
      $ctx['aprovador_id'] = (int)$MEU_ID;
    }
  } else { // orcamento
    if ($action==='resubmit') {
      $st=$pdo->prepare("UPDATE orcamentos SET status_aprovacao='pendente', id_user_aprovador=NULL, dt_aprovacao=NULL
                         WHERE id_orcamento=? AND LOWER(COALESCE(status_aprovacao,''))='reprovado'");
      $st->execute([(int)$id]);
      if ($st->rowCount()===0) throw new Exception('Só é possível reenviar itens reprovados.');
    } else {
      $st=$pdo->prepare("UPDATE orcamentos SET status_aprovacao=:st, id_user_aprovador=:id_ap, dt_aprovacao=:dt, comentarios_aprovacao=:obs
                         WHERE id_orcamento=:id AND LOWER(COALESCE(status_aprovacao,''))='pendente'");
      $st->execute([':st'=>$decisao, ':id_ap'=>$MEU_ID, ':dt'=>$now, ':obs'=>$obs, ':id'=>(int)$id]);
      if ($st->rowCount()===0) throw new Exception('Estado não é mais pendente.');
      $ctx['aprovador_id'] = (int)$MEU_ID;
    }
  }

  $insFluxo->execute([
    ':tipo'=>$module, ':id_ref'=>(string)$id, ':op'=>($action==='resubmit'?'reenvio':$action),
    ':sol'=>$MEU_ID, ':st'=>$decisao, ':aprov'=>($action==='resubmit'? null : $MEU_ID),
    ':just'=>$obs, ':ds'=>$now, ':da'=>($action==='resubmit'? null : $now), ':ip'=>$ip, ':ua'=>$ua
  ]);

  $pdo->commit();

  notify_event($pdo, $ctx);

  jexit(200,['success'=>true]);

} catch (Exception $e) {
  $pdo->rollBack();
  jexit(400,['success'=>false,'error'=>$e->getMessage()]);
}
