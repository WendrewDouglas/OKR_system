<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$in = json_input();
$token = (string)($in['session_token'] ?? '');
$idQ   = (int)($in['id_questao'] ?? 0);
$idA   = (int)($in['id_alternativa'] ?? 0);
$ms    = max(0, (int)($in['tempo_ms'] ?? 0));

if (!$idQ || !$idA) fail('Parâmetros inválidos.');

$pdo = pdo();
$S = sessao_por_token($pdo, $token, true); // bloqueia se ja finalizada
$idSessao = (int)$S['id_sessao'];

// a questao pertence a versao da sessao?
$q = $pdo->prepare("SELECT id_questao FROM okrm_questoes WHERE id_questao=? AND id_versao=? LIMIT 1");
$q->execute([$idQ, (int)$S['id_versao']]);
if (!$q->fetchColumn()) fail('Questão inválida para esta avaliação.', 400);

// alternativa escolhida
$a = $pdo->prepare("SELECT id_alternativa, is_correta FROM okrm_alternativas WHERE id_alternativa=? AND id_questao=? LIMIT 1");
$a->execute([$idA, $idQ]);
$alt = $a->fetch();
if (!$alt) fail('Alternativa inválida.', 400);

// gabarito da questao
$c = $pdo->prepare("SELECT id_alternativa FROM okrm_alternativas WHERE id_questao=? AND is_correta=1 LIMIT 1");
$c->execute([$idQ]);
$idCorreta = (int)$c->fetchColumn();

$acertou = ((int)$alt['is_correta'] === 1) ? 1 : 0;

// grava (uma resposta por questao; re-resposta sobrescreve enquanto aberta)
$pdo->prepare("
  INSERT INTO okrm_respostas (id_sessao, id_questao, id_alternativa, acertou, tempo_ms, dt_resposta)
  VALUES (?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE id_alternativa=VALUES(id_alternativa), acertou=VALUES(acertou),
                          tempo_ms=VALUES(tempo_ms), dt_resposta=NOW()
")->execute([$idSessao, $idQ, $idA, $acertou, $ms]);

// justificativas de TODAS as alternativas: reveladas apenas apos
// responder (nao vao no versao_ativa, para nao entregar o gabarito antes)
$allSt = $pdo->prepare("SELECT id_alternativa, is_correta, justificativa FROM okrm_alternativas WHERE id_questao=? ORDER BY ordem");
$allSt->execute([$idQ]);
$alternativas = array_map(fn($a) => [
    'id_alternativa' => (int)$a['id_alternativa'],
    'is_correta'     => (int)$a['is_correta'] === 1,
    'justificativa'  => $a['justificativa'],
], $allSt->fetchAll());

ok([
  'acertou'      => (bool)$acertou,
  'id_correta'   => $idCorreta,
  'alternativas' => $alternativas,
]);
