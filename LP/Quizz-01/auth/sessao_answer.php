<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();

$token = (string)($in['session_token'] ?? '');
$pid   = (int)($in['id_pergunta'] ?? 0);
$oid   = (int)($in['id_opcao'] ?? 0);
$ms    = (int)($in['tempo_na_tela_ms'] ?? 0);
if (!$token || !$pid || !$oid) fail('Parâmetros inválidos');

$pdo = pdo();
$ses = $pdo->prepare("SELECT id_sessao FROM lp001_quiz_sessoes WHERE session_token=? LIMIT 1");
$ses->execute([$token]);
$id_sessao = (int)$ses->fetchColumn();
if (!$id_sessao) fail('Sessão não encontrada', 404);

$st = $pdo->prepare("SELECT score, ordem FROM lp001_quiz_opcoes WHERE id_opcao=?");
$st->execute([$oid]);
$op = $st->fetch();
if (!$op) fail('Opção inválida', 400);

$sql = "INSERT INTO lp001_quiz_respostas (id_sessao,id_pergunta,id_opcao,ordem,score_opcao,tempo_na_tela_ms,dt_resposta)
        VALUES (?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE id_opcao=VALUES(id_opcao), ordem=VALUES(ordem), score_opcao=VALUES(score_opcao),
                                tempo_na_tela_ms=VALUES(tempo_na_tela_ms), dt_resposta=NOW()";
$pdo->prepare($sql)->execute([$id_sessao,$pid,$oid,(int)$op['ordem'],(int)$op['score'],$ms]);

ok(['ok'=>true]);
