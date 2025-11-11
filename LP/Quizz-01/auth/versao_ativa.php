<?php
// .../auth/versao_ativa.php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pdo  = pdo();
$slug = $_GET['slug'] ?? 'diagnostico-executivo-okrs';
$id_cargo = isset($_GET['id_cargo']) ? (int)$_GET['id_cargo'] : 0;

/** 1) Encontra o id_quiz + versão ativa global */
$st = $pdo->prepare("SELECT id_quiz, versao_ativa FROM lp001_quiz WHERE slug=? AND status='active' LIMIT 1");
$st->execute([$slug]);
$row = $st->fetch();
if (!$row) fail('Quiz não encontrado', 404);

$id_quiz = (int)$row['id_quiz'];
$id_versao_global = (int)($row['versao_ativa'] ?? 0);

/** 2) Se veio cargo, tenta mapear versão específica por cargo */
$id_versao = 0;
if ($id_cargo > 0) {
  $map = $pdo->prepare("
    SELECT v.id_versao
      FROM lp001_quiz_cargo_map m
      JOIN lp001_quiz_versao v ON v.id_versao = m.id_versao
     WHERE m.id_cargo = ? LIMIT 1
  ");
  $map->execute([$id_cargo]);
  $id_versao = (int)$map->fetchColumn();
}

/** 3) Fallback: usa versão ativa global se não houver mapeada */
if ($id_versao <= 0) {
  if ($id_versao_global <= 0) fail('Nenhuma versão ativa encontrada', 404);
  $id_versao = $id_versao_global;
}

/** 4) Domínios */
$doms = $pdo->prepare("SELECT id_dominio, nome, peso, ordem FROM lp001_quiz_dominios WHERE id_versao=? ORDER BY ordem");
$doms->execute([$id_versao]);
$dominios = $doms->fetchAll();

/** 5) Perguntas (incluindo glossário) */
$pergs = $pdo->prepare("
  SELECT id_pergunta, id_dominio, ordem, texto, glossario_json
    FROM lp001_quiz_perguntas
   WHERE id_versao=?
   ORDER BY ordem
");
$pergs->execute([$id_versao]);
$perguntas = $pergs->fetchAll();

/** 6) Opções (inclui explicação; mantém ordem canônica) */
if ($perguntas) {
  $ids = implode(',', array_map('intval', array_column($perguntas, 'id_pergunta')));
  $ops = $pdo->query("
    SELECT id_opcao, id_pergunta, ordem, texto, explicacao, score
      FROM lp001_quiz_opcoes
     WHERE id_pergunta IN ($ids)
     ORDER BY ordem
  ")->fetchAll();

  $byQ = [];
  foreach ($ops as $o) { $byQ[$o['id_pergunta']][] = $o; }
  foreach ($perguntas as &$p) { $p['opcoes'] = $byQ[$p['id_pergunta']] ?? []; }
}

ok([
  'id_quiz'   => $id_quiz,
  'id_versao' => $id_versao,
  'dominios'  => $dominios,
  'perguntas' => $perguntas
]);
