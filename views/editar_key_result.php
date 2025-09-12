<?php
// views/editar_key_result.php — Edição simples (datas + novo prazo)
// - Sem campo de ciclo; usuário informa data_inicio, data_fim e (opcional) dt_novo_prazo
// - Se período mudar: apaga milestones, apontamentos e anexos; recria milestones
// - Qualquer alteração -> status_aprovacao = 'pendente'

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/diff_helpers.php';

if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

/* ===================== ENDPOINT AJAX (update) ===================== */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');

  // Conexão
  try {
    $pdo = new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
      DB_USER, DB_PASS,
      [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
    );
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Erro de conexão.']);
    exit;
  }

  $userId = (int)($_SESSION['user_id'] ?? 0);
  if ($userId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit; }

  // Company do usuário
  $st = $pdo->prepare("SELECT id_company, primeiro_nome, ultimo_nome FROM usuarios WHERE id_user = :u LIMIT 1");
  $st->execute([':u'=>$userId]);
  $uRow = $st->fetch();
  if (!$uRow) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Usuário inválido']); exit; }
  $companyId = (int)($uRow['id_company'] ?? 0);
  $userName  = trim(($uRow['primeiro_nome'] ?? '').' '.($uRow['ultimo_nome'] ?? ''));

  $action = $_GET['action'] ?? '';
  if ($action !== 'update' || $_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Ação inválida.']);
    exit;
  }

  /* ------- Helpers ------- */
  function normalizarFrequencia(string $raw): string {
    $raw = strtolower(trim($raw));
    $map = ['semanal','quinzenal','mensal','bimestral','trimestral','semestral','anual'];
    return in_array($raw,$map,true) ? $raw : 'mensal';
  }
  function ensureFrequenciaDominio(PDO $pdo, string $slug): void {
    $slug = strtolower(trim($slug));
    if ($slug==='') return;
    $st=$pdo->prepare("SELECT 1 FROM dom_tipo_frequencia_milestone WHERE id_frequencia = ? LIMIT 1");
    $st->execute([$slug]);
    if ($st->fetchColumn()) return;
    $labels=[
      'semanal'=>'Semanal','quinzenal'=>'Quinzenal (15 dias)','mensal'=>'Mensal','bimestral'=>'Bimestral',
      'trimestral'=>'Trimestral','semestral'=>'Semestral','anual'=>'Anual'
    ];
    $desc=$labels[$slug] ?? ucfirst($slug);
    $pdo->prepare("INSERT INTO dom_tipo_frequencia_milestone (id_frequencia, descricao_exibicao) VALUES (?,?)")->execute([$slug,$desc]);
  }
  function unidadeRequerInteiro(?string $u): bool {
    $u = strtolower(trim((string)$u));
    $ints = ['unid','itens','pcs','ord','proc','contratos','processos','pessoas','casos','tickets','visitas'];
    return in_array($u, $ints, true);
  }
  function gerarSerieDatas(string $data_inicio, string $data_fim, string $freq): array {
    $out=[]; $start=new DateTime($data_inicio); $end=new DateTime($data_fim);
    if ($end < $start) $end = clone $start;
    $push=function(array &$a,DateTime $d){ $iso=$d->format('Y-m-d'); if(empty($a)||end($a)!==$iso) $a[]=$iso; };
    $freq=strtolower($freq);
    if ($freq==='semanal' || $freq==='quinzenal') {
      $step = $freq==='semanal' ? 7 : 15;
      $d=(clone $start)->modify("+{$step} days");
      while ($d < $end) { $push($out,$d); $d->modify("+{$step} days"); }
      $push($out,$end);
    } else {
      $step = ['mensal'=>1,'bimestral'=>2,'trimestral'=>3,'semestral'=>6,'anual'=>12][$freq] ?? 1;
      $firstEnd=(clone $start)->modify('last day of this month');
      if ($step>1){ $tmp=(clone $start)->modify('first day of this month')->modify('+'.($step-1).' months'); $firstEnd=$tmp->modify('last day of this month'); }
      if ($firstEnd > $end) { $push($out,$end); }
      else {
        $push($out,$firstEnd);
        $d=(clone $firstEnd)->modify('+'.$step.' months')->modify('last day of this month');
        while ($d < $end){ $push($out,$d); $d=$d->modify('+'.$step.' months')->modify('last day of this month'); }
        $push($out,$end);
      }
    }
    if (count($out)===0) $out[]=$end->format('Y-m-d');
    return $out;
  }
  function recriarMilestones(PDO $pdo, string $id_kr, string $data_inicio, string $data_fim, string $freqSlug, float $baseline, float $meta, string $natureza, ?string $direcao, ?string $unidade): int {
    $pdo->prepare("DELETE FROM milestones_kr WHERE id_kr = ?")->execute([$id_kr]);
    $datas = gerarSerieDatas($data_inicio, $data_fim, $freqSlug);
    $N = count($datas);
    $ins = $pdo->prepare("
      INSERT INTO milestones_kr (id_kr, num_ordem, data_ref, valor_esperado, gerado_automatico, editado_manual, bloqueado_para_edicao)
      VALUES (:id_kr, :ord, :data, :valor, 1, 0, 0)
    ");
    $isInt = unidadeRequerInteiro($unidade);
    $round = fn($v)=> $isInt ? (int)round($v,0) : round($v,2);
    $nat = strtolower($natureza);
    if ($nat==='acumulativo') $nat='acumulativa';
    $acum = ($nat==='acumulativa');
    $bin  = in_array($nat, ['binaria','binário','binario','binária'], true);
    $maior = (strtoupper((string)$direcao) !== 'MENOR_MELHOR');

    for ($i=1; $i<=$N; $i++) {
      if ($bin)        $esp = ($i===$N)?1:0;
      elseif ($acum)   $esp = $maior ? ($baseline + ($meta-$baseline)*($i/$N)) : ($baseline - ($baseline-$meta)*($i/$N));
      else             $esp = ($i===$N)?$meta:0;

      $ins->execute([
        ':id_kr'=>$id_kr, ':ord'=>$i, ':data'=>$datas[$i-1], ':valor'=>$round($esp)
      ]);
    }
    return $N;
  }

  try {
    // CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
      http_response_code(400);
      echo json_encode(['success'=>false,'error'=>'CSRF inválido']);
      exit;
    }

    $id_kr = trim((string)($_POST['id_kr'] ?? ''));
    if ($id_kr===''){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'ID do KR ausente']); exit; }

    // Carrega KR do escopo da company
    $q = $pdo->prepare("
      SELECT kr.*, o.id_company, o.descricao AS objetivo_desc
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE kr.id_kr = :id AND o.id_company = :c
      LIMIT 1
    ");
    $q->execute([':id'=>$id_kr, ':c'=>$companyId]);
    $KR = $q->fetch();
    if (!$KR) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'KR não encontrado ou sem permissão.']); exit; }

    // Inputs
    $descricao       = trim((string)($_POST['descricao'] ?? ''));
    $tipo_kr         = ($_POST['tipo_kr'] ?? '') !== '' ? (string)$_POST['tipo_kr'] : null;
    $natureza_kr     = ($_POST['natureza_kr'] ?? '') !== '' ? (string)$_POST['natureza_kr'] : null;
    $status          = ($_POST['status'] ?? '') !== '' ? (string)$_POST['status'] : null;
    $freqIn          = ($_POST['tipo_frequencia_milestone'] ?? '') !== '' ? (string)$_POST['tipo_frequencia_milestone'] : (string)($KR['tipo_frequencia_milestone'] ?? '');
    $freqSlug        = normalizarFrequencia($freqIn);

    $baseline        = isset($_POST['baseline']) ? (float)$_POST['baseline'] : (float)($KR['baseline'] ?? 0);
    $meta            = isset($_POST['meta'])     ? (float)$_POST['meta']     : (float)($KR['meta'] ?? 0);
    $unidade_medida  = trim((string)($_POST['unidade_medida'] ?? ($KR['unidade_medida'] ?? '')));
    $direcao_metrica = trim((string)($_POST['direcao_metrica'] ?? ($KR['direcao_metrica'] ?? '')));
    $margem_conf     = ($_POST['margem_confianca'] ?? '') !== '' ? (float)$_POST['margem_confianca'] : null;

    $responsavel     = ($_POST['responsavel'] ?? '') !== '' ? (string)$_POST['responsavel'] : ($KR['responsavel'] ?? null);
    $observacoes_new = trim((string)($_POST['observacoes'] ?? ($KR['observacoes'] ?? '')));
    $justEdit        = trim((string)($_POST['justificativa_edicao'] ?? ''));

    $data_inicio     = trim((string)($_POST['data_inicio'] ?? ''));
    $data_fim        = trim((string)($_POST['data_fim'] ?? ''));
    $dt_novo_prazo   = trim((string)($_POST['dt_novo_prazo'] ?? ''));

    // Validação básica
    $errs=[];
    if ($descricao==='') $errs[]='Descrição é obrigatória.';
    if ($justEdit==='')  $errs[]='A justificativa de edição é obrigatória.';
    if (!is_numeric($_POST['baseline'] ?? null)) $errs[]='Baseline é obrigatória.';
    if (!is_numeric($_POST['meta'] ?? null))     $errs[]='Meta é obrigatória.';
    if ($freqSlug==='') $errs[]='Frequência de apontamento é obrigatória.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) $errs[]='Data de início inválida.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))    $errs[]='Data de fim inválida.';
    if ($dt_novo_prazo!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt_novo_prazo)) $errs[]='Novo prazo inválido.';
    if (!$errs) {
      if (strtotime($data_fim) < strtotime($data_inicio)) $errs[]='Data de fim deve ser maior ou igual à data de início.';
    }
    if ($errs){ http_response_code(422); echo json_encode(['success'=>false,'error'=>implode(' ', $errs)]); exit; }

    // Responsável precisa ser da mesma company (se informado)
    if ($responsavel) {
      $chk = $pdo->prepare("SELECT 1 FROM usuarios WHERE id_user = :u AND id_company = :c LIMIT 1");
      $chk->execute([':u'=>(int)$responsavel, ':c'=>$companyId]);
      if (!$chk->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'Responsável inválido para sua empresa.']);
        exit;
      }
    } else {
      $responsavel = null;
    }

    // Garante a frequência no domínio (FK)
    ensureFrequenciaDominio($pdo, $freqSlug);

    // Observações + histórico
    $obsAnt = (string)($KR['observacoes'] ?? '');
    $stamp  = date('Y-m-d H:i');
    $bloco  = "\n\n---\n[Justificativa de edição em {$stamp} por {$userName}]\n{$justEdit}";
    $observacoes = trim($obsAnt) !== '' ? ($obsAnt.$bloco) : ("[Histórico de Observações]\n{$bloco}");
    if (trim($observacoes_new) !== '' && trim($observacoes_new) !== trim($obsAnt)) {
      $observacoes = trim($observacoes_new).$bloco;
    }

    $datesChanged = ($data_inicio !== (string)$KR['data_inicio'] || $data_fim !== (string)$KR['data_fim']);

    $pdo->beginTransaction();

    // Se período mudou: apaga apontamentos + anexos + milestones
    if ($datesChanged) {
      $idsAp = $pdo->prepare("SELECT id_apontamento FROM apontamentos_kr WHERE id_kr = ?");
      $idsAp->execute([$id_kr]);
      $rows = $idsAp->fetchAll(PDO::FETCH_COLUMN);
      if ($rows) {
        $in = implode(',', array_fill(0, count($rows), '?'));
        $pdo->prepare("DELETE FROM apontamentos_kr_anexos WHERE id_apontamento IN ($in)")->execute($rows);
      }
      $pdo->prepare("DELETE FROM apontamentos_kr WHERE id_kr = ?")->execute([$id_kr]);
      $pdo->prepare("DELETE FROM milestones_kr  WHERE id_kr = ?")->execute([$id_kr]);
    }


    // ==== [MOV] Registrar movimento de aprovação (alteração de KR) ====
    // Monte a lista de campos relevantes para auditoria
    // lista de campos auditados
    $camposAudit = [
      'descricao','baseline','meta','unidade_medida','direcao_metrica','margem_confianca',
      'tipo_kr','natureza_kr','status','tipo_frequencia_milestone',
      'data_inicio','data_fim','dt_novo_prazo','responsavel'
    ];

    $diff = [];
    foreach($camposAudit as $c){
      $antes  = $KR[$c] ?? null;   // valor do banco ANTES
      $depois = null;              // valor recebido DEPOIS
      switch($c){
        case 'descricao':                 $depois = $descricao; break;
        case 'baseline':                  $depois = $baseline; break;
        case 'meta':                      $depois = $meta; break;
        case 'unidade_medida':            $depois = $unidade_medida ?: null; break;
        case 'direcao_metrica':           $depois = $direcao_metrica ?: null; break;
        case 'margem_confianca':          $depois = $margem_conf; break;
        case 'tipo_kr':                   $depois = $tipo_kr; break;
        case 'natureza_kr':               $depois = $natureza_kr; break;
        case 'status':                    $depois = $status; break;
        case 'tipo_frequencia_milestone': $depois = $freqSlug; break;
        case 'data_inicio':               $depois = $data_inicio; break;
        case 'data_fim':                  $depois = $data_fim; break;
        case 'dt_novo_prazo':             $depois = $dt_novo_prazo ?: null; break;
        case 'responsavel':               $depois = $responsavel ?: null; break;
      }
      // ⚠️ só registra se for diferente DEPOIS de normalizar
      if (!equal_field($c, $antes, $depois)) {
        $diff[] = [
          'campo'  => $c,
          'antes'  => $antes,
          'depois' => $depois
        ];
      }
    }

    // Só grava movimento "alteracao" se houver diferenças reais
    if (count($diff) > 0) {
      $insMov = $pdo->prepare("
        INSERT INTO aprovacao_movimentos
          (tipo_estrutura, id_referencia, tipo_movimento, campos_diff_json, justificativa, id_user_criador)
        VALUES ('kr', :id_ref, 'alteracao', :diff, :just, :uid)
      ");
      $insMov->execute([
        ':id_ref'=>$id_kr,
        ':diff'=>json_encode($diff, JSON_UNESCAPED_UNICODE),
        ':just'=>$justEdit ?: null,
        ':uid'=>$userId
      ]);
    }
    // Se o usuário apenas reenviou sem mudar nada, você pode:
    // - não criar registro em aprovacao_movimentos
    // - e ainda assim deixar o fluxo registrar "reenvio" (já é feito na sua API)
    // ==== [/MOV] ====


    // Update KR (sempre volta para pendente)
    $st = $pdo->prepare("
      UPDATE key_results
         SET descricao                 = :desc,
             tipo_kr                   = :tipo_kr,
             natureza_kr               = :natureza_kr,
             status                    = :status,
             tipo_frequencia_milestone = :freq,
             baseline                  = :baseline,
             meta                      = :meta,
             unidade_medida            = :unidade,
             direcao_metrica           = :direcao,
             margem_confianca          = :margem,
             data_inicio               = :dt_ini,
             data_fim                  = :dt_fim,
             dt_novo_prazo             = :dt_novo_prazo,
             responsavel               = :resp,
             observacoes               = :obs,
             status_aprovacao          = 'pendente',
             aprovador                 = NULL,
             id_user_aprovador         = NULL,
             dt_aprovacao              = NULL,
             comentarios_aprovacao     = NULL,
             dt_ultima_atualizacao     = NOW(),
             usuario_ult_alteracao     = :user
       WHERE id_kr = :id
       LIMIT 1
    ");
    $st->execute([
      ':desc'=>$descricao, ':tipo_kr'=>$tipo_kr, ':natureza_kr'=>$natureza_kr, ':status'=>$status,
      ':freq'=>$freqSlug,   ':baseline'=>$baseline, ':meta'=>$meta, ':unidade'=>($unidade_medida?:null),
      ':direcao'=>($direcao_metrica?:null), ':margem'=>$margem_conf,
      ':dt_ini'=>$data_inicio, ':dt_fim'=>$data_fim, ':dt_novo_prazo'=>($dt_novo_prazo?:null),
      ':resp'=>$responsavel, ':obs'=>$observacoes, ':user'=>$userId, ':id'=>$id_kr
    ]);

    // Recria milestones se mudou
    $qtde = 0;
    if ($datesChanged) {
      $qtde = recriarMilestones($pdo, $id_kr, $data_inicio, $data_fim, $freqSlug, (float)$baseline, (float)$meta, (string)$natureza_kr, $direcao_metrica ?: null, $unidade_medida ?: null);
    }

    $pdo->commit();
    echo json_encode(['success'=>true, 'id_kr'=>$id_kr, 'periodo_mudou'=>$datesChanged?1:0, 'milestones_recriados'=>$qtde]);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Falha ao atualizar: '.$e->getMessage()]);
    exit;
  }
}

/* ===================== RENDER DA PÁGINA ===================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Injetar tema (uma vez)
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

// Conexão
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Company & KR
$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :u LIMIT 1");
$st->execute([':u'=>$userId]);
$companyId = (int)($st->fetchColumn() ?: 0);
if ($companyId<=0) { http_response_code(403); die('Usuário sem company vinculada.'); }

$id_kr = isset($_GET['id']) ? trim((string)$_GET['id']) : (isset($_GET['id_kr']) ? trim((string)$_GET['id_kr']) : '');
if ($id_kr===''){ http_response_code(400); die('ID do KR não informado.'); }

$q = $pdo->prepare("
  SELECT kr.*, o.descricao AS objetivo_desc
  FROM key_results kr
  JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
  WHERE kr.id_kr = :id AND o.id_company = :c
  LIMIT 1
");
$q->execute([':id'=>$id_kr, ':c'=>$companyId]);
$KR = $q->fetch();
if (!$KR) { http_response_code(404); die('KR não encontrado ou sem permissão.'); }

// Listas
$users    = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios WHERE id_company = :c ORDER BY primeiro_nome, ultimo_nome");
$users->execute([':c'=>$companyId]);
$users = $users->fetchAll();

$tiposKr   = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_kr ORDER BY descricao_exibicao")->fetchAll();
$naturezas = $pdo->query("SELECT id_natureza, descricao_exibicao FROM dom_natureza_kr ORDER BY descricao_exibicao")->fetchAll();
$freqs     = $pdo->query("SELECT id_frequencia, descricao_exibicao FROM dom_tipo_frequencia_milestone ORDER BY descricao_exibicao")->fetchAll();
$statusKr  = $pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_kr ORDER BY 1")->fetchAll();

// Garante "Quinzenal" no front
$hasQuinz=false; foreach($freqs as $f){ $id=strtolower((string)$f['id_frequencia']); $lbl=strtolower((string)$f['descricao_exibicao']); if($id==='quinzenal'||strpos($lbl,'quinzen')!==false){$hasQuinz=true;break;} }
if(!$hasQuinz){ $freqs[] = ['id_frequencia'=>'quinzenal','descricao_exibicao'=>'Quinzenal (15 dias)']; }

// Bind
$desc           = (string)($KR['descricao'] ?? '');
$baseline       = (string)($KR['baseline'] ?? '');
$meta           = (string)($KR['meta'] ?? '');
$uni            = (string)($KR['unidade_medida'] ?? '');
$dir            = (string)($KR['direcao_metrica'] ?? '');
$tipoSel        = (string)($KR['tipo_kr'] ?? '');
$natSel         = (string)($KR['natureza_kr'] ?? '');
$statusSel      = (string)($KR['status'] ?? '');
$freqSel        = (string)($KR['tipo_frequencia_milestone'] ?? '');
$margem         = (string)($KR['margem_confianca'] ?? '');
$responsavelSel = (string)($KR['responsavel'] ?? '');
$obs            = (string)($KR['observacoes'] ?? '');
$dt_ini         = (string)($KR['data_inicio'] ?? '');
$dt_fim         = (string)($KR['data_fim'] ?? '');
$novoPrazo      = (string)($KR['dt_novo_prazo'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar Key Result – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <style>
    /* Overlays SEMPRE começam ocultas, não importa o resto do CSS */
    [hidden]{ display:none !important; }
    .overlay{ position:fixed; inset:0; display:none !important; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid !important; }
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.ekr{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }
    :root{ --card:#222; --border:#222733; --text:#eaeef6; --muted:#a6adbb; --gold:#F1C40F; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a; }
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .head-card{ background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }
    .head-meta{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill-gold{ border-color: var(--gold); color: var(--gold); background: rgba(246,195,67,.10); }
    .form-card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); }
    .form-card h2{ font-size:1.05rem; margin:0 0 12px; color:#e5e7eb; }
    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }
    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    input[type="text"],input[type="number"],input[type="date"],textarea,select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px;
    }
    textarea{ resize:vertical; min-height:90px; }
    .helper{ color:#9aa4b2; font-size:.85rem; }
    .save-row{ display:flex; justify-content:center; gap:10px; margin-top:16px; flex-wrap:wrap; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{ width:min(820px,94vw); background:#0b1020; color:#e6e9f2; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; border:1px solid #223047; }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800; background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); }
    .ai-title{ font-size:.95rem; opacity:.9; }
    .ai-subtle{ font-size:.85rem; opacity:.7; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:16px; margin:8px 0 14px; }
    .warning{ color:#fbbf24; font-size:.85rem; margin-top:6px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="ekr">
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-regular fa-pen-to-square"></i> Editar Key Result</span>
      </div>

      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-bullseye"></i>Editar Key Result <small style="opacity:.7;font-weight:700;">(<?= h($id_kr) ?>)</small></h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-circle-info"></i>Qualquer alteração reenviará o KR para aprovação (pendente).</span>
          <span class="pill pill-gold"><i class="fa-solid fa-bullseye"></i><strong><?= h($KR['objetivo_desc'] ?? 'Objetivo associado') ?></strong></span>
        </div>
      </section>

      <section class="form-card">
        <h2><i class="fa-regular fa-rectangle-list"></i> Dados do Key Result</h2>
        <form id="editKrForm" action="?ajax=1&action=update" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id_kr" value="<?= h($id_kr) ?>">

          <div>
            <label for="descricao"><i class="fa-regular fa-pen-to-square"></i> Descrição <span class="helper">(obrigatório)</span></label>
            <textarea id="descricao" name="descricao" required><?= h($desc) ?></textarea>
          </div>

          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="baseline"><i class="fa-solid fa-gauge"></i> Baseline <span class="helper">(obrigatório)</span></label>
              <input type="number" step="0.01" id="baseline" name="baseline" value="<?= h($baseline) ?>" required>
            </div>
            <div>
              <label for="meta"><i class="fa-solid fa-bullseye"></i> Meta <span class="helper">(obrigatório)</span></label>
              <input type="number" step="0.01" id="meta" name="meta" value="<?= h($meta) ?>" required>
            </div>
            <div>
              <label for="unidade_medida"><i class="fa-solid fa-ruler"></i> Unidade de medida</label>
              <input type="text" id="unidade_medida" name="unidade_medida" value="<?= h($uni) ?>" placeholder="ex.: %, unid, R$, h...">
            </div>
          </div>

          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="direcao_metrica"><i class="fa-solid fa-arrow-up-wide-short"></i> Direção da métrica</label>
              <select id="direcao_metrica" name="direcao_metrica">
                <option value="">Selecione...</option>
                <option value="MAIOR_MELHOR" <?= strtoupper($dir)==='MAIOR_MELHOR'?'selected':'' ?>>Maior Melhor</option>
                <option value="MENOR_MELHOR" <?= strtoupper($dir)==='MENOR_MELHOR'?'selected':'' ?>>Menor Melhor</option>
                <option value="INTERVALO_IDEAL" <?= strtoupper($dir)==='INTERVALO_IDEAL'?'selected':'' ?>>Intervalo Ideal</option>
              </select>
            </div>
            <div>
              <label for="natureza_kr"><i class="fa-solid fa-shapes"></i> Natureza do KR</label>
              <select id="natureza_kr" name="natureza_kr">
                <option value="">Selecione...</option>
                <?php foreach($naturezas as $n): ?>
                  <option value="<?= h($n['id_natureza']) ?>" <?= (string)$n['id_natureza']===$natSel?'selected':'' ?>>
                    <?= h($n['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="tipo_kr"><i class="fa-regular fa-square-check"></i> Tipo de KR</label>
              <select id="tipo_kr" name="tipo_kr">
                <option value="">Selecione...</option>
                <?php foreach($tiposKr as $t): ?>
                  <option value="<?= h($t['id_tipo']) ?>" <?= (string)$t['id_tipo']===$tipoSel?'selected':'' ?>>
                    <?= h($t['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="tipo_frequencia_milestone"><i class="fa-solid fa-clock-rotate-left"></i> Frequência de apontamento <span class="helper">(obrigatório)</span></label>
              <select id="tipo_frequencia_milestone" name="tipo_frequencia_milestone" required>
                <option value="">Selecione...</option>
                <?php foreach($freqs as $f): ?>
                  <option value="<?= h($f['id_frequencia']) ?>" <?= strtolower((string)$f['id_frequencia'])===strtolower((string)$freqSel)?'selected':'' ?>>
                    <?= h($f['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="status"><i class="fa-solid fa-list-check"></i> Status do KR</label>
              <select id="status" name="status">
                <option value="">Selecione...</option>
                <?php foreach($statusKr as $s): ?>
                  <option value="<?= h($s['id_status']) ?>" <?= (string)$s['id_status']===$statusSel?'selected':'' ?>>
                    <?= h($s['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="responsavel"><i class="fa-regular fa-user"></i> Responsável</label>
              <select id="responsavel" name="responsavel">
                <option value="">Selecione...</option>
                <?php foreach($users as $u): ?>
                  <option value="<?= (int)$u['id_user'] ?>" <?= (string)$u['id_user']===$responsavelSel?'selected':'' ?>>
                    <?= h(trim(($u['primeiro_nome'] ?? '').' '.($u['ultimo_nome'] ?? ''))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="margem_confianca"><i class="fa-regular fa-percent"></i> Margem de confiança (%)</label>
              <input type="number" step="0.01" id="margem_confianca" name="margem_confianca" value="<?= h($margem) ?>" placeholder="%">
            </div>
            <div>
              <label for="data_inicio"><i class="fa-regular fa-calendar"></i> Data início <span class="helper">(obrigatório)</span></label>
              <input type="date" id="data_inicio" name="data_inicio" value="<?= h($dt_ini) ?>" required>
            </div>
            <div>
              <label for="data_fim"><i class="fa-regular fa-calendar"></i> Data fim <span class="helper">(obrigatório)</span></label>
              <input type="date" id="data_fim" name="data_fim" value="<?= h($dt_fim) ?>" required>
            </div>
          </div>

          <div class="grid-2" style="margin-top:12px;">
            <div>
              <label for="dt_novo_prazo"><i class="fa-regular fa-calendar-plus"></i> Novo prazo (extensão) — opcional</label>
              <input type="date" id="dt_novo_prazo" name="dt_novo_prazo" value="<?= h($novoPrazo) ?>">
              <small class="helper">Não altera <strong>data_fim</strong>; apenas registra a extensão em <code>dt_novo_prazo</code>.</small>
            </div>
            <div>
              <label for="observacoes"><i class="fa-regular fa-note-sticky"></i> Observações</label>
              <textarea id="observacoes" name="observacoes" rows="4" placeholder="Observações gerais (opcional)"><?= h($obs) ?></textarea>
            </div>
          </div>

          <div class="warning">⚠️ Se você alterar <strong>data início</strong> ou <strong>data fim</strong>, todos os milestones serão recriados e <strong>todos os apontamentos e evidências serão apagados</strong> deste KR.</div>

          <div class="save-row">
            <button type="button" id="btnSalvar" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Salvar Alterações</button>
            <a href="/OKR_system/meus_okrs" class="btn"><i class="fa-regular fa-circle-left"></i> Voltar</a>
          </div>
        </form>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal Justificativa -->
  <div id="justifyOverlay" class="overlay" aria-hidden="true" hidden>
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OKR</div>
        <div>
          <div class="ai-title">Justificativa da Edição</div>
          <div class="ai-subtle">Explique objetivamente o motivo das alterações. O aprovador verá este texto.</div>
        </div>
      </div>
      <div class="ai-bubble">
        <label for="justificativa_edicao" style="display:block;margin-bottom:6px;color:#cbd5e1;font-size:.9rem;">
          <i class="fa-regular fa-comment"></i> Justificativa <span class="helper">(obrigatório)</span>
        </label>
        <textarea id="justificativa_edicao" rows="5" style="width:100%;background:#0c1118;color:#e5e7eb;border:1px solid #1f2635;border-radius:10px;padding:10px;"></textarea>
      </div>
      <div class="save-row" style="margin-top:0;">
        <button id="cancelJust" class="btn"><i class="fa-regular fa-circle-xmark"></i> Cancelar</button>
        <button id="confirmJust" class="btn btn-primary"><i class="fa-regular fa-paper-plane"></i> Confirmar e Enviar para Aprovação</button>
      </div>
    </div>
  </div>

  <!-- Sucesso -->
  <div id="successOverlay" class="overlay" aria-hidden="true" hidden>
    <div class="ai-card" role="alertdialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OKR</div>
        <div>
          <div class="ai-title">Alterações salvas ✅</div>
          <div class="ai-subtle">KR reenviado para aprovação (status: pendente).</div>
        </div>
      </div>
      <div class="ai-bubble">
        <div class="ai-subtle" id="successText" style="font-size:1rem;opacity:.9">
          Tudo certo. Você será notificado quando o aprovador decidir.
        </div>
      </div>
      <div class="save-row" style="margin-top:0;">
        <a href="/OKR_system/meus_okrs" class="btn btn-primary">Ir para Meus OKRs</a>
      </div>
    </div>
  </div>

  <!-- Loading -->
  <div id="loadingOverlay" class="overlay" aria-hidden="true" hidden>
    <div class="ai-card" role="dialog" aria-live="polite">
      <div class="ai-header">
        <div class="ai-avatar">...</div>
        <div>
          <div class="ai-title">Salvando…</div>
          <div class="ai-subtle">Aplicando alterações e reenviando para aprovação.</div>
        </div>
      </div>
      <div class="ai-bubble" style="display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-spinner fa-spin"></i>
        <span>Aguarde um instante…</span>
      </div>
    </div>
  </div>

  <script>
    const $  = (s, r=document)=>r.querySelector(s);
    const show = el => { el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); };
    const hide = el => { el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); };

    // Ajuste layout com chat lateral (se houver)
    (function setupChatObservers(){
      const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
      const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
      function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
      function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
      function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
      const chat=findChatEl(); if(chat){ const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }
    })();

    document.addEventListener('DOMContentLoaded', () => {
      const form    = $('#editKrForm');
      const loading = $('#loadingOverlay');
      const justOvr = $('#justifyOverlay');
      const succOvr = $('#successOverlay');

      function setLoading(on){ on ? show(loading) : hide(loading); }

      // Regras de front: validar mínimos
      function validarBasico(){
        const req = ['#descricao','#baseline','#meta','#tipo_frequencia_milestone','#data_inicio','#data_fim'];
        for (const sel of req){
          const el=$(sel);
          if (!el || !el.value){ return false; }
        }
        const di=$('#data_inicio').value, df=$('#data_fim').value;
        if (new Date(df) < new Date(di)) { alert('Data de fim deve ser maior ou igual à data de início.'); return false; }
        return true;
      }

      $('#btnSalvar')?.addEventListener('click', () => {
        if (!validarBasico()) { alert('Preencha os campos obrigatórios.'); return; }
        show(justOvr);
      });

      $('#cancelJust')?.addEventListener('click', () => hide(justOvr));

      $('#confirmJust')?.addEventListener('click', async () => {
        const just = ($('#justificativa_edicao')?.value || '').trim();
        if (!just) { alert('A justificativa de edição é obrigatória.'); return; }

        hide(justOvr);
        setLoading(true);

        try {
          const fd = new FormData(form);
          fd.append('justificativa_edicao', just);

          const res  = await fetch(form.action, { method:'POST', body:fd });
          const data = await res.json();

          setLoading(false);

          if (data?.success) {
            if (data.periodo_mudou) {
              $('#successText').innerHTML = 'Período alterado: milestones foram recriados e todos os apontamentos/evidências foram apagados. KR reenviado para aprovação.';
            }
            show(succOvr);
          } else {
            alert(data?.error || 'Falha ao salvar alterações.');
          }
        } catch (err) {
          console.error(err);
          setLoading(false);
          alert('Erro de rede ao salvar alterações.');
        }
      });
    });
  </script>
</body>
</html>
