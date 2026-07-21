<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$in = json_input();
$nome  = trim((string)($in['nome'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));
$dataAula = trim((string)($in['data_aula'] ?? ''));
$consent  = !empty($in['consent_termos']);

if (mb_strlen($nome) < 3) fail('Informe seu nome completo.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('E-mail inválido.');
if (!$consent) fail('É necessário autorizar o uso dos dados.');

// valida data (nao futura, formato ISO). Fuso de Sao Paulo para o "hoje".
// O "!" no formato zera a hora em 00:00 (sem ele, createFromFormat usa a
// hora atual e um mesmo-dia com hora > 0 seria lido como "futuro").
$tz = new DateTimeZone('America/Sao_Paulo');
$d = DateTime::createFromFormat('!Y-m-d', $dataAula, $tz);
if (!$d || $d->format('Y-m-d') !== $dataAula) fail('Data da aula inválida.');
if ($d > new DateTime('today', $tz)) fail('A data da aula não pode ser futura.');

$pdo = pdo();
$mod = versao_ativa($pdo, 'M1');

// upsert do aluno
$st = $pdo->prepare("
  INSERT INTO okrm_alunos (nome, email, consent_termos, created_at)
  VALUES (?,?,?,NOW())
  ON DUPLICATE KEY UPDATE nome=VALUES(nome), consent_termos=VALUES(consent_termos), updated_at=NOW()
");
$st->execute([$nome, $email, $consent ? 1 : 0]);

$st = $pdo->prepare("SELECT id_aluno FROM okrm_alunos WHERE email=? LIMIT 1");
$st->execute([$email]);
$idAluno = (int)$st->fetchColumn();

// Regra: 1 tentativa por aluno + modulo. Sessao finalizada bloqueia,
// salvo se houver liberacao nao consumida (concedida pelo instrutor).
$st = $pdo->prepare("
  SELECT id_sessao FROM okrm_sessoes
   WHERE id_aluno=? AND id_modulo=? AND status='finalizada'
   ORDER BY id_sessao DESC LIMIT 1
");
$st->execute([$idAluno, (int)$mod['id_modulo']]);
$jaFinalizou = (int)($st->fetchColumn() ?: 0);

if ($jaFinalizou) {
    $lib = $pdo->prepare("
      SELECT id_liberacao FROM okrm_liberacoes
       WHERE id_aluno=? AND id_modulo=? AND consumida=0
       ORDER BY id_liberacao DESC LIMIT 1
    ");
    $lib->execute([$idAluno, (int)$mod['id_modulo']]);
    $idLib = (int)($lib->fetchColumn() ?: 0);
    if (!$idLib) {
        fail('Você já concluiu esta avaliação.', 409);
    }
    // consome a liberacao
    $pdo->prepare("UPDATE okrm_liberacoes SET consumida=1 WHERE id_liberacao=?")->execute([$idLib]);
}

// Reaproveita sessao aberta existente (retomada), senao cria nova
$st = $pdo->prepare("
  SELECT session_token FROM okrm_sessoes
   WHERE id_aluno=? AND id_modulo=? AND status='aberta'
   ORDER BY id_sessao DESC LIMIT 1
");
$st->execute([$idAluno, (int)$mod['id_modulo']]);
$token = $st->fetchColumn();

if (!$token) {
    $token = novo_token();
    $ins = $pdo->prepare("
      INSERT INTO okrm_sessoes (session_token, id_aluno, id_versao, id_modulo, data_aula, status, dt_inicio, ip, user_agent)
      VALUES (?,?,?,?,?, 'aberta', NOW(), ?, ?)
    ");
    $ins->execute([
        $token, $idAluno, (int)$mod['id_versao'], (int)$mod['id_modulo'], $dataAula,
        ip_bin(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
    ]);
}

ok(['session_token' => $token]);
