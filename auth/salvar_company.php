<?php
// auth/salvar_company.php
// Salva organização. Se houver CNPJ, valida e consulta Receita (BrasilAPI) e grava todos os campos.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start(); // captura saída acidental
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Autenticação
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Não autorizado']); exit;
}

// Método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Método não permitido']); exit;
}

// CSRF
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'CSRF inválido']); exit;
}

// Helpers
function only_digits($s){ return preg_replace('/\D+/', '', (string)$s); }
function validaCNPJ($cnpj) {
  $cnpj = only_digits($cnpj);
  if (strlen($cnpj) != 14) return false;
  if (preg_match('/^(\\d)\\1{13}$/', $cnpj)) return false; // todos iguais

  $calc = function($base) {
    $peso = [5,4,3,2,9,8,7,6,5,4,3,2];
    $soma = 0;
    for ($i=0; $i<12; $i++) $soma += intval($base[$i]) * $peso[$i];
    $resto = $soma % 11;
    return ($resto < 2) ? 0 : (11 - $resto);
  };
  $b12 = substr($cnpj, 0, 12);
  $d1 = $calc($b12);
  $calc2 = function($base12, $d1) {
    $peso = [6,5,4,3,2,9,8,7,6,5,4,3,2];
    $soma = 0;
    $seq = $base12 . $d1;
    for ($i=0; $i<13; $i++) $soma += intval($seq[$i]) * $peso[$i];
    $resto = $soma % 11;
    return ($resto < 2) ? 0 : (11 - $resto);
  };
  $d2 = $calc2($b12, $d1);

  return ($cnpj[12] == (string)$d1 && $cnpj[13] == (string)$d2);
}

// Consulta CNPJ (BrasilAPI por padrão)
function consultaCNPJ($cnpj) {
  $url = "https://brasilapi.com.br/api/cnpj/v1/" . $cnpj;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $code < 200 || $code >= 300) {
    return [null, $err ?: "HTTP $code"];
  }

  $json = json_decode($resp, true);
  if (!is_array($json)) {
    return [null, 'Resposta inválida da API'];
  }
  return [$json, null];
}

// Mapeia campos da API para nosso modelo
function mapearCNPJ(array $j): array {
  // Normalizações: alguns campos variam por provedor
  $razao  = $j['razao_social'] ?? $j['nome'] ?? $j['nome_empresarial'] ?? null;

  // natureza_juridica pode vir como "206-2 - Sociedade Empresária Limitada" ou separado
  $nj_raw = $j['natureza_juridica'] ?? ($j['natureza_juridica']['descricao'] ?? null);
  $nj_code = null; $nj_desc = null;
  if (is_string($nj_raw)) {
    if (preg_match('/^(\d{3,4}-?\d?)\s*-\s*(.+)$/u', $nj_raw, $m)) {
      $nj_code = $m[1];
      $nj_desc = $m[2];
    } else {
      $nj_desc = $nj_raw;
    }
  } elseif (is_array($j['natureza_juridica'] ?? null)) {
    $nj_code = $j['natureza_juridica']['codigo'] ?? null;
    $nj_desc = $j['natureza_juridica']['descricao'] ?? null;
  }

  $logradouro = trim(($j['descricao_tipo_de_logradouro'] ?? $j['descricao_tipo_logradouro'] ?? '') . ' ' . ($j['logradouro'] ?? ''));
  $logradouro = trim($logradouro) ?: ($j['logradouro'] ?? null);

  $numero = $j['numero'] ?? ($j['numero_endereco'] ?? null);
  $complemento = $j['complemento'] ?? null;

  $cep = only_digits($j['cep'] ?? '');
  $cep = $cep ?: null;

  $bairro   = $j['bairro'] ?? ($j['bairro_distrito'] ?? null);
  $municipio= $j['municipio'] ?? ($j['cidade'] ?? null);
  $uf       = $j['uf'] ?? ($j['estado'] ?? null);

  $email    = $j['email'] ?? null;

  // telefone pode vir como ddd + telefone, ou campos diferentes
  $tel = $j['telefone'] ?? ($j['ddd_telefone_1'] ?? null);
  if (!$tel && isset($j['ddd'], $j['telefone'])) $tel = "({$j['ddd']}) {$j['telefone']}";

  $sit      = $j['situacao_cadastral'] ?? ($j['descricao_situacao_cadastral'] ?? null);
  $dt_sit   = $j['data_situacao_cadastral'] ?? null;
  if ($dt_sit) {
    // normaliza para YYYY-MM-DD
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dt_sit)) {
      [$d,$m,$y] = explode('/',$dt_sit);
      $dt_sit = "$y-$m-$d";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt_sit)) {
      $ts = strtotime($dt_sit);
      $dt_sit = $ts ? date('Y-m-d', $ts) : null;
    }
  }

  return [
    'razao_social'            => $razao,
    'natureza_juridica_code'  => $nj_code,
    'natureza_juridica_desc'  => $nj_desc,
    'logradouro'              => $logradouro,
    'numero'                  => $numero,
    'complemento'             => $complemento,
    'cep'                     => $cep,
    'bairro'                  => $bairro,
    'municipio'               => $municipio,
    'uf'                      => $uf,
    'email'                   => $email,
    'telefone'                => $tel,
    'situacao_cadastral'      => $sit,
    'data_situacao_cadastral' => $dt_sit,
  ];
}

try {
  // Conexão
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  $organizacao = trim($_POST['organizacao'] ?? '');
  $cnpj_in     = trim($_POST['cnpj'] ?? '');
  $cnpj        = only_digits($cnpj_in);
  $userId      = (int)$_SESSION['user_id'];

  if ($organizacao === '') {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'O campo Organização (Nome Fantasia) é obrigatório.']); exit;
  }

  $dados = [
    'organizacao' => $organizacao,
    'cnpj'        => null,
    'razao_social'=> null,
    'natureza_juridica_code'=> null,
    'natureza_juridica_desc'=> null,
    'logradouro'=> null, 'numero'=> null, 'complemento'=> null,
    'cep'=> null, 'bairro'=> null, 'municipio'=> null, 'uf'=> null,
    'email'=> null, 'telefone'=> null,
    'situacao_cadastral'=> null, 'data_situacao_cadastral'=> null
  ];

  $fezConsulta = false;

  if ($cnpj !== '') {
    if (!validaCNPJ($cnpj)) {
      http_response_code(422);
      echo json_encode(['success'=>false,'error'=>'CNPJ inválido.']); exit;
    }

    // Consulta Receita via BrasilAPI
    [$json, $err] = consultaCNPJ($cnpj);
    if ($err) {
      http_response_code(502);
      echo json_encode(['success'=>false,'error'=>"Falha ao consultar CNPJ na Receita: $err"]); exit;
    }

    $map = mapearCNPJ($json);
    $dados = array_merge($dados, $map);
    $dados['cnpj'] = $cnpj;
    $fezConsulta = true;
  }

  // Insert
  $sql = "INSERT INTO company
          (organizacao, cnpj, razao_social, natureza_juridica_code, natureza_juridica_desc,
           logradouro, numero, complemento, cep, bairro, municipio, uf, email, telefone,
           situacao_cadastral, data_situacao_cadastral, created_by)
          VALUES
          (:organizacao, :cnpj, :razao_social, :nj_code, :nj_desc,
           :logradouro, :numero, :complemento, :cep, :bairro, :municipio, :uf, :email, :telefone,
           :situacao, :data_situacao, :created_by)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':organizacao'  => $dados['organizacao'],
    ':cnpj'         => $dados['cnpj'],
    ':razao_social' => $dados['razao_social'],
    ':nj_code'      => $dados['natureza_juridica_code'],
    ':nj_desc'      => $dados['natureza_juridica_desc'],
    ':logradouro'   => $dados['logradouro'],
    ':numero'       => $dados['numero'],
    ':complemento'  => $dados['complemento'],
    ':cep'          => $dados['cep'],
    ':bairro'       => $dados['bairro'],
    ':municipio'    => $dados['municipio'],
    ':uf'           => $dados['uf'],
    ':email'        => $dados['email'],
    ':telefone'     => $dados['telefone'],
    ':situacao'     => $dados['situacao_cadastral'],
    ':data_situacao'=> $dados['data_situacao_cadastral'],
    ':created_by'   => $userId,
  ]);
  $id = (int)$pdo->lastInsertId();

  $record = array_merge($dados, ['id_company' => $id]);
  echo json_encode([
    'success'     => true,
    'fez_consulta'=> $fezConsulta,
    'record'      => $record
  ]);
} catch (PDOException $e) {
  if ((int)$e->getCode() === 23000) {
    // violação de unique (cnpj duplicado)
    http_response_code(409);
    echo json_encode(['success'=>false,'error'=>'CNPJ já cadastrado.']); exit;
  }
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro ao salvar: '.$e->getMessage()]);
}
