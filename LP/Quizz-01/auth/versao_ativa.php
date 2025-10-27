<?php
require __DIR__ . '/_bootstrap.php';

$pdo = pdo();

// pega o quiz ativo (você pode fixar pelo slug)
$slug = $_GET['slug'] ?? 'diagnostico-executivo-okrs';
$st = $pdo->prepare("SELECT id_quiz, versao_ativa FROM lp001_quiz WHERE slug=? AND status='active' LIMIT 1");
$st->execute([$slug]);
$row = $st->fetch();
if (!$row || !$row['versao_ativa']) fail('Nenhuma versão ativa encontrada', 404);

$id_versao = (int)$row['versao_ativa'];

$doms = $pdo->prepare("SELECT id_dominio, nome, peso, ordem FROM lp001_quiz_dominios WHERE id_versao=? ORDER BY ordem");
$doms->execute([$id_versao]);
$dominios = $doms->fetchAll();

$pergs = $pdo->prepare("SELECT id_pergunta, id_dominio, ordem, texto FROM lp001_quiz_perguntas WHERE id_versao=? ORDER BY ordem");
$pergs->execute([$id_versao]);
$perguntas = $pergs->fetchAll();

if ($perguntas) {
    $ids = implode(',', array_map('intval', array_column($perguntas, 'id_pergunta')));
    $ops = $pdo->query("SELECT id_opcao, id_pergunta, ordem, texto FROM lp001_quiz_opcoes WHERE id_pergunta IN ($ids) ORDER BY ordem")->fetchAll();
    $byQ = [];
    foreach ($ops as $o) { $byQ[$o['id_pergunta']][] = $o; }
    foreach ($perguntas as &$p) { $p['opcoes'] = $byQ[$p['id_pergunta']] ?? []; }
}

ok([
  'id_versao' => $id_versao,
  'dominios'  => $dominios,
  'perguntas' => $perguntas
]);
