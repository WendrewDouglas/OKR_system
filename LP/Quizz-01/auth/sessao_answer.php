<?php
// .../auth/sessao_answer.php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
$in = json_input();

$token = (string)($in['session_token'] ?? '');
$pid   = (int)($in['id_pergunta'] ?? 0);
$oid   = (int)($in['id_opcao'] ?? 0);
$ms    = (int)($in['tempo_na_tela_ms'] ?? 0);
if (!$token || !$pid || !$oid) fail('Parâmetros inválidos');

$pdo = pdo();

// sessão válida
$ses = $pdo->prepare("SELECT id_sessao, id_versao FROM lp001_quiz_sessoes WHERE session_token=? LIMIT 1");
$ses->execute([$token]);
$S = $ses->fetch();
if (!$S) fail('Sessão não encontrada', 404);
$id_sessao = (int)$S['id_sessao'];

// opção escolhida
$opSt = $pdo->prepare("SELECT id_pergunta, score, ordem, texto, explicacao FROM lp001_quiz_opcoes WHERE id_opcao=? LIMIT 1");
$opSt->execute([$oid]);
$op = $opSt->fetch();
if (!$op) fail('Opção inválida', 400);
if ((int)$op['id_pergunta'] !== $pid) fail('Opção não pertence à pergunta', 400);

// acha a correta (maior score) da pergunta
$best = $pdo->prepare("
  SELECT id_opcao, score, ordem, texto, explicacao
    FROM lp001_quiz_opcoes
   WHERE id_pergunta=?
   ORDER BY score DESC, ordem ASC
   LIMIT 1
");
$best->execute([$pid]);
$correta = $best->fetch();
if (!$correta) fail('Gabarito não encontrado', 400);

// grava/atualiza resposta
$sql = "INSERT INTO lp001_quiz_respostas (id_sessao,id_pergunta,id_opcao,ordem,score_opcao,tempo_na_tela_ms,dt_resposta)
        VALUES (?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE id_opcao=VALUES(id_opcao), ordem=VALUES(ordem), score_opcao=VALUES(score_opcao),
                                tempo_na_tela_ms=VALUES(tempo_na_tela_ms), dt_resposta=NOW()";
$pdo->prepare($sql)->execute([$id_sessao,$pid,$oid,(int)$op['ordem'],(int)$op['score'],$ms]);

ok([
  'ok' => true,
  'score' => (int)$op['score'],
  'correta' => [
    'id_opcao'   => (int)$correta['id_opcao'],
    'score'      => (int)$correta['score'],
    'explicacao' => $correta['explicacao'] ?? null
  ],
  'escolhida' => [
    'id_opcao'   => $oid,
    'score'      => (int)$op['score'],
    'explicacao' => $op['explicacao'] ?? null
  ]
]);
