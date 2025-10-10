<?php
// auth/salvar_iniciativas.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__.'/../auth/acl.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
}

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro de conexão']); exit;
}

// ---------- Entrada ----------
$uid        = (string)($_SESSION['user_id']);              // id_user logado (varchar em sua tabela)
$id_kr      = trim($_POST['id_kr'] ?? '');
$desc       = trim($_POST['descricao'] ?? '');
$id_resp    = trim($_POST['id_user_responsavel'] ?? '');   // pode vir vazio → assume logado
$dt_prazo   = trim($_POST['dt_prazo'] ?? '');              // 'YYYY-MM-DD' ou vazio
$status     = trim($_POST['status_iniciativa'] ?? '');     // deve vir dos dom_status_kr
$incl_orc   = isset($_POST['incluir_orcamento']);
$valor_orc  = isset($_POST['valor_orcamento']) ? (float)$_POST['valor_orcamento'] : 0.0;
$just_orc   = trim($_POST['justificativa_orcamento'] ?? '');
$prev_json  = trim($_POST['desembolsos_json'] ?? '[]');    // [{"competencia":"2025-09","valor":1234.56},...]

if ($id_kr === '' || $desc === '' || $status === '') {
  echo json_encode(['success'=>false,'error'=>'Preencha os campos obrigatórios.']); exit;
}

// por segurança, adequa ao tamanho do campo (iniciativas.status = varchar(20))
if (mb_strlen($status) > 20) $status = mb_substr($status, 0, 20);

// normaliza dt_prazo (ou deixa NULL)
if ($dt_prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt_prazo)) {
  // tenta converter formatos comuns
  $ts = strtotime($dt_prazo);
  $dt_prazo = $ts ? date('Y-m-d', $ts) : '';
}

try {
  $pdo->beginTransaction();

  // ---------- Valida KR e impede criação em KR cancelado ----------
  $st = $pdo->prepare("SELECT status FROM key_results WHERE id_kr=:id LIMIT 1");
  $st->execute(['id'=>$id_kr]);
  $rowKr = $st->fetch();
  if (!$rowKr) {
    throw new RuntimeException('KR inexistente.');
  }
  $krStatus = mb_strtolower((string)($rowKr['status'] ?? ''));
  if (strpos($krStatus, 'cancel') !== false) {
    throw new RuntimeException('Não é possível criar iniciativa em KR cancelado.');
  }

  // ---------- Resolve Responsável ----------
  // default: se vazio, usa o usuário logado
  if ($id_resp === '' || $id_resp === null) {
    $id_resp = $uid;
  }

  // valida que o responsável existe
  $st = $pdo->prepare("SELECT id_user, id_company FROM usuarios WHERE id_user=:u LIMIT 1");
  $st->execute(['u'=>$id_resp]);
  $respRow = $st->fetch();
  if (!$respRow) {
    throw new RuntimeException('Responsável informado não existe.');
  }

  // valida que o logado existe e compara company
  $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
  $st->execute(['u'=>$uid]);
  $userCompany = $st->fetchColumn();
  $respCompany = $respRow['id_company'] ?? null;

  // se ambas as companies existem, exigimos igualdade
  if ($userCompany !== null && $respCompany !== null && (string)$userCompany !== (string)$respCompany) {
    throw new RuntimeException('Responsável não pertence à mesma organização.');
  }

  // ---------- Próximo num_iniciativa ----------
  $st = $pdo->prepare("SELECT COALESCE(MAX(num_iniciativa),0)+1 FROM iniciativas WHERE id_kr=:id");
  $st->execute(['id'=>$id_kr]);
  $num_ini = (int)$st->fetchColumn();

  // ---------- Gera id_iniciativa (varchar 100) ----------
  $id_ini = bin2hex(random_bytes(12)); // 24 chars; suficiente para sua PK varchar(100)

  // ---------- Insere Iniciativa ----------
  $sqlI = "INSERT INTO iniciativas
    (id_iniciativa, id_kr, num_iniciativa, descricao, status,
     id_user_criador, dt_criacao, id_user_responsavel, dt_prazo, status_aprovacao)
  VALUES
    (:id_ini, :id_kr, :num, :desc, :status,
     :criador, CURDATE(), :id_resp, :dt_prazo, NULL)";
  $st = $pdo->prepare($sqlI);
  $st->execute([
    'id_ini'   => $id_ini,
    'id_kr'    => $id_kr,
    'num'      => $num_ini,
    'desc'     => $desc,
    'status'   => $status,        // códigos equivalentes aos de dom_status_kr
    'criador'  => $uid,
    'id_resp'  => $id_resp,
    'dt_prazo' => ($dt_prazo !== '' ? $dt_prazo : null),
  ]);

  // ---------- Apontamento inicial de status (histórico) ----------
  try {
    $sqlA = "INSERT INTO apontamentos_status_iniciativas
      (id_iniciativa, status, data_hora, id_user, observacao)
      VALUES (:ini, :st, NOW(), :u, :obs)";
    $stA = $pdo->prepare($sqlA);
    $stA->execute([
      'ini' => $id_ini,
      'st'  => $status,
      'u'   => $uid,
      'obs' => 'Status inicial ao criar a iniciativa.'
    ]);
  } catch (Throwable $e) {
    // tabela pode não existir; segue sem bloquear
  }

  // ---------- Orçamento por competências (opcional) ----------
  if ($incl_orc) {
    if ($valor_orc <= 0) {
      throw new RuntimeException('Informe um valor total de orçamento maior que zero.');
    }

    $parcelas = json_decode($prev_json, true);
    if (!is_array($parcelas) || !count($parcelas)) {
      throw new RuntimeException('Informe ao menos uma competência na previsão de desembolso.');
    }

    // soma e valida competências + datas
    $soma = 0.0;
    foreach ($parcelas as $p) {
      $valorParc = (float)($p['valor'] ?? 0);
      $comp      = (string)($p['competencia'] ?? ''); // "YYYY-MM" ou "MM/YYYY"

      if ($valorParc <= 0) {
        throw new RuntimeException('Há uma competência com valor menor ou igual a zero.');
      }

      // normaliza competência para "YYYY-MM-01"
      $data = null;
      if (preg_match('/^\d{4}\-\d{2}$/', $comp)) {               // 2025-09
        $data = $comp . '-01';
      } elseif (preg_match('/^\d{2}\/\d{4}$/', $comp)) {         // 09/2025
        [$m, $y] = explode('/', $comp, 2);
        $data = sprintf('%04d-%02d-01', (int)$y, (int)$m);
      } else {
        throw new RuntimeException('Competência inválida: '.$comp);
      }

      // armazena linha em orcamentos (uma por competência)
      $sqlO = "INSERT INTO orcamentos
        (id_iniciativa, valor, data_desembolso, status_aprovacao,
         id_user_criador, dt_criacao, justificativa_orcamento)
        VALUES (:ini, :valor, :dt, 'pendente', :u, CURDATE(), :just)";
      $stO = $pdo->prepare($sqlO);
      $stO->execute([
        'ini'   => $id_ini,
        'valor' => $valorParc,
        'dt'    => $data,
        'u'     => $uid,
        'just'  => ($just_orc !== '' ? $just_orc : null),
      ]);

      $soma += $valorParc;
    }

    // confere soma == total (2 casas)
    if (round($soma, 2) !== round($valor_orc, 2)) {
      throw new RuntimeException('Soma das competências ('.
        number_format($soma,2,',','.') .
        ') diferente do total informado ('.
        number_format($valor_orc,2,',','.') .
        ').');
    }
  }

  $pdo->commit();
  echo json_encode([
    'success'         => true,
    'id_iniciativa'   => $id_ini,
    'num_iniciativa'  => $num_ini,
    'id_user_resp'    => $id_resp,
  ]); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('salvar_iniciativas: '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
}
