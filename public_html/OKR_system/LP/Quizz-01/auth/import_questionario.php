<?php
// /OKR_system/LP/Quizz-01/auth/import_questionario.php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__, 3); // .../OKR_system
require_once $root . '/auth/config.php'; // carrega PDO/ENV

function pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
  $opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => sprintf("SET NAMES %s COLLATE %s", DB_CHARSET, DB_COLLATION)
  ];
  return $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
}

function norm($s){ return trim((string)$s); }

// ====== PARÂMETROS ======
$versao = isset($_GET['versao']) ? (int)$_GET['versao'] : 0;
if (!$versao) { http_response_code(400); exit("Parâmetro ?versao= obrigatório.\n"); }

// Caminho do CSV (já existe no servidor)
$csvPath = $root . '/LP/Quizz-01/documents/questionario.csv';
if (!is_file($csvPath)) { http_response_code(404); exit("CSV não encontrado em: $csvPath\n"); }

// ====== MYSQL ======
$pdo = pdo();

// Confere versão existente
$st = $pdo->prepare("SELECT 1 FROM lp001_quiz_versao WHERE id_versao = ? LIMIT 1");
$st->execute([$versao]);
if (!$st->fetchColumn()) { http_response_code(400); exit("id_versao=$versao não existe em lp001_quiz_versao.\n"); }

// ====== CSV ======
$delim = ';';
$sample = file_get_contents($csvPath, false, null, 0, 4096);
if ($sample !== false) {
  $cntP = substr_count($sample, ';'); $cntC = substr_count($sample, ',');
  $delim = ($cntP > $cntC) ? ';' : ',';
}

$fh = fopen($csvPath, 'r');
if (!$fh) { http_response_code(500); exit("Falha ao abrir CSV.\n"); }

$header = fgetcsv($fh, 0, $delim);
if (!$header) { exit("CSV vazio.\n"); }
$lower = array_map(fn($h)=>mb_strtolower(norm($h)), $header);

$map = function(array $aliases) use ($lower){
  foreach ($aliases as $a) {
    $i = array_search($a, $lower, true);
    if ($i !== false) return $i;
  }
  return null;
};

$idx = [
  'dominio'  => $map(['dominio','domínio','categoria','area','área']),
  'ordem'    => $map(['ordem','ordem_pergunta','n','numero','número']),
  'contexto' => $map(['contexto','enunciado','intro']),
  'pergunta' => $map(['pergunta','texto','questao','questão']),
  'A'        => $map(['a','alt_a','alternativa_a']),
  'B'        => $map(['b','alt_b','alternativa_b']),
  'C'        => $map(['c','alt_c','alternativa_c']),
  'D'        => $map(['d','alt_d','alternativa_d']),
  'gabarito' => $map(['correta','gabarito','letra_correta','resp'])
];

foreach (['dominio','ordem','pergunta','A','B','C','D','gabarito'] as $k) {
  if ($idx[$k] === null) exit("CSV sem coluna obrigatória: $k\n");
}

$pdo->beginTransaction();

try {
  // cache da ordem de domínios
  $maxOrdem = (int)$pdo->query("SELECT COALESCE(MAX(ordem),0) FROM lp001_quiz_dominios WHERE id_versao=".$pdo->quote($versao))->fetchColumn();

  $selDom = $pdo->prepare("SELECT id_dominio FROM lp001_quiz_dominios WHERE id_versao=? AND nome=? LIMIT 1");
  $insDom = $pdo->prepare("INSERT INTO lp001_quiz_dominios (id_versao, nome, peso, ordem) VALUES (?,?,0.0,?)");

  $selPerg = $pdo->prepare("SELECT id_pergunta FROM lp001_quiz_perguntas WHERE id_versao=? AND id_dominio=? AND ordem=? LIMIT 1");
  $insPerg = $pdo->prepare("INSERT INTO lp001_quiz_perguntas (id_versao, id_dominio, ordem, texto) VALUES (?,?,?,?)");
  $updPerg = $pdo->prepare("UPDATE lp001_quiz_perguntas SET texto=? WHERE id_pergunta=?");

  $delOp   = $pdo->prepare("DELETE FROM lp001_quiz_opcoes WHERE id_pergunta=?");
  $insOp   = $pdo->prepare("
    INSERT INTO lp001_quiz_opcoes (id_pergunta, ordem, texto, explicacao, score, categoria_resposta)
    VALUES (?,?,?,?,?,?)
  ");

  $lin = 1;
  $novas=0; $atual=0;

  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $lin++;
    if (!array_filter($row, fn($v)=>$v!==null && $v!=="")) continue;

    $dominio = norm($row[$idx['dominio']]);
    $ordem   = norm($row[$idx['ordem']]);
    $perg    = norm($row[$idx['pergunta']]);

    if ($dominio==='' || $ordem==='' || $perg==='') {
      echo "[WARN] Linha $lin ignorada (faltou domínio/ordem/pergunta)\n";
      continue;
    }
    $ordem = (int)$ordem;

    $contexto = $idx['contexto']!==null ? norm($row[$idx['contexto']]) : '';
    $textoFinal = $contexto ? ($contexto."\n\n".$perg) : $perg;

    $alts = [
      1 => norm($row[$idx['A']]),
      2 => norm($row[$idx['B']]),
      3 => norm($row[$idx['C']]),
      4 => norm($row[$idx['D']]),
    ];

    $gab = mb_strtoupper(norm($row[$idx['gabarito']]));
    if (str_starts_with($gab, 'ALTERNATIVA ')) $gab = substr($gab, 12);
    $mapLetra = ['A'=>1,'B'=>2,'C'=>3,'D'=>4];
    $ordemCorreta = $mapLetra[$gab] ?? 0;

    // Dominio
    $selDom->execute([$versao, $dominio]);
    $id_dominio = $selDom->fetchColumn();
    if (!$id_dominio) {
      $maxOrdem++;
      $insDom->execute([$versao, $dominio, $maxOrdem]);
      $id_dominio = (int)$pdo->lastInsertId();
    }

    // Pergunta (upsert por versao+dominio+ordem)
    $selPerg->execute([$versao, $id_dominio, $ordem]);
    $id_pergunta = $selPerg->fetchColumn();
    if ($id_pergunta) {
      $updPerg->execute([$textoFinal, $id_pergunta]);
      $atual++;
    } else {
      $insPerg->execute([$versao, $id_dominio, $ordem, $textoFinal]);
      $id_pergunta = (int)$pdo->lastInsertId();
      $novas++;
    }

    // Opções (reset e reinsere)
    $delOp->execute([$id_pergunta]);
    foreach ($alts as $ordemOpc => $textoOpc) {
      if ($textoOpc==='') continue;
      $categoria = ($ordemOpc === $ordemCorreta) ? 'correta' : 'menos_errada';
      // score/explicacao opcionais
      $insOp->execute([$id_pergunta, $ordemOpc, $textoOpc, null, 0, $categoria]);
    }
  }

  $pdo->commit();
  fclose($fh);
  echo "[OK] Perguntas novas: $novas | atualizadas: $atual\n";

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (is_resource($fh)) fclose($fh);
  http_response_code(500);
  echo "ERRO: ".$e->getMessage()."\n";
}
