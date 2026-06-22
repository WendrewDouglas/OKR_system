<?php
// auth/salvar_company.php
// Insere ou atualiza uma organização. Se houver CNPJ, valida e consulta a BrasilAPI.

ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__.'/../auth/acl.php';

// --------- Guardas ----------
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'error'=>'Método não permitido']); exit;
}
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'CSRF inválido']); exit;
}

// --------- Helpers ----------
function only_digits($s){ return preg_replace('/\D+/', '', (string)$s); }
function validaCNPJ($cnpj) {
  $cnpj = only_digits($cnpj);
  if (strlen($cnpj) != 14) return false;
  if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
  $b = array_map('intval', str_split($cnpj));
  $peso1 = [5,4,3,2,9,8,7,6,5,4,3,2];
  $peso2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s1=0; for($i=0;$i<12;$i++) $s1 += $b[$i]*$peso1[$i];
  $d1 = ($s1%11<2)?0:11-$s1%11;
  if ($b[12] !== $d1) return false;
  $s2=0; for($i=0;$i<13;$i++) $s2 += $b[$i]*$peso2[$i];
  $d2 = ($s2%11<2)?0:11-$s2%11;
  return $b[13] === $d2;
}

function consultaCNPJ($cnpj) {
  $url = "https://brasilapi.com.br/api/cnpj/v1/" . $cnpj;
  $attempts = 3;
  $err = ''; $code = 0;
  for ($i = 0; $i < $attempts; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
      CURLOPT_USERAGENT => 'OKR-System/1.0',
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp !== false && $code >= 200 && $code < 300) {
      $json = json_decode($resp, true);
      if (is_array($json)) return [$json, null, $code];
      return [null, 'Resposta inválida da API', $code];
    }

    // Só repete em erros transitórios (429 rate-limit, 5xx, falha de rede).
    $transitorio = ($code === 429 || $code >= 500 || $code === 0);
    if (!$transitorio) {
      return [null, ($err ?: "HTTP $code"), $code]; // ex.: 404 = CNPJ inexistente
    }
    if ($i < $attempts - 1) sleep($i + 1); // backoff: 1s, 2s
  }
  return [null, ($err ?: "HTTP $code"), $code];
}

function mapearCNPJ(array $j): array {
  $razao  = $j['razao_social'] ?? $j['nome'] ?? $j['nome_empresarial'] ?? null;

  $nj_code = null; $nj_desc = null;
  if (isset($j['natureza_juridica'])) {
    if (is_string($j['natureza_juridica'])) {
      if (preg_match('/^(\d{3,4}-?\d?)\s*-\s*(.+)$/u', $j['natureza_juridica'], $m)) {
        $nj_code = $m[1]; $nj_desc = $m[2];
      } else $nj_desc = $j['natureza_juridica'];
    } elseif (is_array($j['natureza_juridica'])) {
      $nj_code = $j['natureza_juridica']['codigo'] ?? null;
      $nj_desc = $j['natureza_juridica']['descricao'] ?? null;
    }
  }

  $logradouro = trim(($j['descricao_tipo_de_logradouro'] ?? $j['descricao_tipo_logradouro'] ?? '') . ' ' . ($j['logradouro'] ?? ''));
  $logradouro = trim($logradouro) ?: ($j['logradouro'] ?? null);

  $numero = $j['numero'] ?? ($j['numero_endereco'] ?? null);
  $complemento = $j['complemento'] ?? null;

  $cep = only_digits($j['cep'] ?? '') ?: null;
  $bairro    = $j['bairro'] ?? ($j['bairro_distrito'] ?? null);
  $municipio = $j['municipio'] ?? ($j['cidade'] ?? null);
  $uf        = $j['uf'] ?? ($j['estado'] ?? null);

  $email = $j['email'] ?? null;

  $tel = $j['telefone'] ?? ($j['ddd_telefone_1'] ?? null);
  if (!$tel && isset($j['ddd'], $j['telefone'])) $tel = "({$j['ddd']}) {$j['telefone']}";

  $sit    = $j['situacao_cadastral'] ?? ($j['descricao_situacao_cadastral'] ?? null);
  $dt_sit = $j['data_situacao_cadastral'] ?? null;
  if ($dt_sit) {
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dt_sit)) {
      [$d,$m,$y] = explode('/',$dt_sit); $dt_sit = "$y-$m-$d";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt_sit)) {
      $ts = strtotime($dt_sit); $dt_sit = $ts ? date('Y-m-d', $ts) : null;
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

// --------- Entrada ----------
$organizacao = trim($_POST['organizacao'] ?? '');
$id_company  = (int)($_POST['id_company'] ?? 0);
$cnpj_in     = trim($_POST['cnpj'] ?? '');
$cnpj_digits = only_digits($cnpj_in);
$userId      = (int)$_SESSION['user_id'];

if ($organizacao === '') {
  http_response_code(422);
  echo json_encode(['success'=>false,'error'=>'O campo Organização (Nome Fantasia) é obrigatório.']); exit;
}

// --------- Conexão ----------
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  error_log('salvar_company conexão: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']); exit;
}

// --------- Permissões (update só da própria empresa, a não ser admin) ----------
$isAdmin = false;
try {
  $stAdm = $pdo->prepare("
    SELECT 1 FROM usuarios_permissoes WHERE id_user=:u
      AND id_permissao IN ('admin','user_admin','sys_admin','super_admin') LIMIT 1
  ");
  $stAdm->execute([':u'=>$userId]);
  $isAdmin = (bool)$stAdm->fetchColumn();
} catch (PDOException $e) { /* silencioso */ }

try {
  $pdo->beginTransaction();

  $fezConsulta  = false;
  $consultaOk   = true;  // a consulta à Receita teve sucesso? (degrada se a API falhar)
  $avisoReceita = null;
  $dadosOficiais = [
    'cnpj' => null, 'razao_social'=>null,
    'natureza_juridica_code'=>null, 'natureza_juridica_desc'=>null,
    'logradouro'=>null,'numero'=>null,'complemento'=>null,
    'cep'=>null,'bairro'=>null,'municipio'=>null,'uf'=>null,
    'email'=>null,'telefone'=>null,
    'situacao_cadastral'=>null,'data_situacao_cadastral'=>null
  ];

  // Se veio CNPJ, valida e consulta
  if ($cnpj_digits !== '') {
    if (!validaCNPJ($cnpj_digits)) {
      $pdo->rollBack();
      http_response_code(422);
      echo json_encode(['success'=>false,'error'=>'CNPJ inválido.']); exit;
    }

    // Evita CNPJ duplicado em outra empresa
    $dupSql = "SELECT id_company FROM company WHERE cnpj = :cnpj";
    $dupParams = [':cnpj' => $cnpj_digits];
    if ($id_company > 0) {
      $dupSql .= " AND id_company <> :id"; $dupParams[':id'] = $id_company;
    }
    $dup = $pdo->prepare($dupSql);
    $dup->execute($dupParams);
    if ($dup->fetch()) {
      $pdo->rollBack();
      http_response_code(409);
      echo json_encode(['success'=>false,'error'=>'CNPJ já cadastrado em outra organização.']); exit;
    }

    // Consulta BrasilAPI (com retry em erros transitórios: 429/5xx/rede)
    [$json, $err, $httpCode] = consultaCNPJ($cnpj_digits);
    if ($err === null) {
      $dadosOficiais = array_merge($dadosOficiais, mapearCNPJ($json));
      $dadosOficiais['cnpj'] = $cnpj_digits;
    } elseif ($httpCode === 404) {
      // CNPJ não existe na Receita → erro real
      $pdo->rollBack();
      http_response_code(422);
      echo json_encode(['success'=>false,'error'=>'CNPJ não encontrado na base da Receita.']); exit;
    } else {
      // Receita indisponível (ex.: 429 rate-limit). NÃO bloqueia: o CNPJ já foi
      // validado localmente (checksum). Salva o CNPJ sem atualizar os dados
      // oficiais e avisa o usuário para sincronizar depois.
      $consultaOk = false;
      $dadosOficiais['cnpj'] = $cnpj_digits;
      $avisoReceita = "Não foi possível consultar a Receita agora ($err). O CNPJ foi salvo, mas os dados oficiais (razão social, endereço…) não foram atualizados — salve novamente mais tarde para sincronizar.";
    }
    $fezConsulta = true;
  }

  // Caminhos: UPDATE (com id_company) ou INSERT (sem id_company)
  if ($id_company > 0) {
    // --- UPDATE ---
    // Checa vinculação do usuário
    if (!$isAdmin) {
      $stUserComp = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
      $stUserComp->execute([':u'=>$userId]);
      $myComp = (int)($stUserComp->fetchColumn() ?: 0);
      if ($myComp !== $id_company) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Sem permissão para alterar esta organização.']); exit;
      }
    }

    // Garante que a company existe
    $stChk = $pdo->prepare("SELECT * FROM company WHERE id_company = :id LIMIT 1");
    $stChk->execute([':id'=>$id_company]);
    $current = $stChk->fetch();
    if (!$current) {
      $pdo->rollBack();
      http_response_code(404);
      echo json_encode(['success'=>false,'error'=>'Organização não encontrada.']); exit;
    }

    // Monta SET dinâmico
    $set = ['organizacao = :organizacao'];
    $params = [':organizacao'=>$organizacao, ':id'=>$id_company];

    if ($cnpj_digits !== '' && $consultaOk) {
      // Substitui CNPJ e todos os dados oficiais (consulta à Receita OK)
      $set = array_merge($set, [
        'cnpj = :cnpj',
        'razao_social = :razao_social',
        'natureza_juridica_code = :nj_code',
        'natureza_juridica_desc = :nj_desc',
        'logradouro = :logradouro',
        'numero = :numero',
        'complemento = :complemento',
        'cep = :cep',
        'bairro = :bairro',
        'municipio = :municipio',
        'uf = :uf',
        'email = :email',
        'telefone = :telefone',
        'situacao_cadastral = :situacao',
        'data_situacao_cadastral = :data_situacao'
      ]);
      $params += [
        ':cnpj'        => $dadosOficiais['cnpj'],
        ':razao_social'=> $dadosOficiais['razao_social'],
        ':nj_code'     => $dadosOficiais['natureza_juridica_code'],
        ':nj_desc'     => $dadosOficiais['natureza_juridica_desc'],
        ':logradouro'  => $dadosOficiais['logradouro'],
        ':numero'      => $dadosOficiais['numero'],
        ':complemento' => $dadosOficiais['complemento'],
        ':cep'         => $dadosOficiais['cep'],
        ':bairro'      => $dadosOficiais['bairro'],
        ':municipio'   => $dadosOficiais['municipio'],
        ':uf'          => $dadosOficiais['uf'],
        ':email'       => $dadosOficiais['email'],
        ':telefone'    => $dadosOficiais['telefone'],
        ':situacao'    => $dadosOficiais['situacao_cadastral'],
        ':data_situacao'=> $dadosOficiais['data_situacao_cadastral'],
      ];
    } elseif ($cnpj_digits !== '') {
      // Receita indisponível: atualiza só o CNPJ e preserva os dados oficiais atuais.
      $set[] = 'cnpj = :cnpj';
      $params[':cnpj'] = $cnpj_digits;
    }
    // Se não veio CNPJ, mantém o atual e só atualiza o nome fantasia.

    $sql = "UPDATE company SET ".implode(', ',$set)." WHERE id_company = :id";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    // Retorna o registro atualizado
    $get = $pdo->prepare("SELECT * FROM company WHERE id_company = :id");
    $get->execute([':id'=>$id_company]);
    $record = $get->fetch();

  } else {
    // --- INSERT ---
    // Prepara dados para insert
    $cols = ['organizacao','created_by'];
    $vals = [':organizacao',':created_by'];
    $params = [':organizacao'=>$organizacao, ':created_by'=>$userId];

    if ($cnpj_digits !== '' && $consultaOk) {
      $cols = array_merge($cols, [
        'cnpj','razao_social','natureza_juridica_code','natureza_juridica_desc',
        'logradouro','numero','complemento','cep','bairro','municipio','uf',
        'email','telefone','situacao_cadastral','data_situacao_cadastral'
      ]);
      $vals = array_merge($vals, [
        ':cnpj',':razao_social',':nj_code',':nj_desc',
        ':logradouro',':numero',':complemento',':cep',':bairro',':municipio',':uf',
        ':email',':telefone',':situacao',':data_situacao'
      ]);
      $params += [
        ':cnpj'        => $dadosOficiais['cnpj'],
        ':razao_social'=> $dadosOficiais['razao_social'],
        ':nj_code'     => $dadosOficiais['natureza_juridica_code'],
        ':nj_desc'     => $dadosOficiais['natureza_juridica_desc'],
        ':logradouro'  => $dadosOficiais['logradouro'],
        ':numero'      => $dadosOficiais['numero'],
        ':complemento' => $dadosOficiais['complemento'],
        ':cep'         => $dadosOficiais['cep'],
        ':bairro'      => $dadosOficiais['bairro'],
        ':municipio'   => $dadosOficiais['municipio'],
        ':uf'          => $dadosOficiais['uf'],
        ':email'       => $dadosOficiais['email'],
        ':telefone'    => $dadosOficiais['telefone'],
        ':situacao'    => $dadosOficiais['situacao_cadastral'],
        ':data_situacao'=> $dadosOficiais['data_situacao_cadastral'],
      ];
    } elseif ($cnpj_digits !== '') {
      // Receita indisponível: grava apenas o CNPJ (dados oficiais ficam vazios).
      $cols[] = 'cnpj';
      $vals[] = ':cnpj';
      $params[':cnpj'] = $cnpj_digits;
    }

    $sql = "INSERT INTO company (".implode(', ',$cols).") VALUES (".implode(', ',$vals).")";
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
    $newId = (int)$pdo->lastInsertId();

    $get = $pdo->prepare("SELECT * FROM company WHERE id_company = :id");
    $get->execute([':id'=>$newId]);
    $record = $get->fetch();
  }

  $pdo->commit();

  echo json_encode([
    'success'      => true,
    'fez_consulta' => $fezConsulta,
    'aviso'        => $avisoReceita,
    'record'       => $record
  ]);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if ((int)$e->getCode() === 23000) {
    http_response_code(409);
    echo json_encode(['success'=>false,'error'=>'Violação de unicidade (provável CNPJ já cadastrado).']); exit;
  }
  error_log('salvar_company: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Falha ao processar. Tente novamente ou contate o administrador.']);
}
