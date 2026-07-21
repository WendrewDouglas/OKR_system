<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$sid = isset($_GET['sid']) ? trim((string)$_GET['sid']) : '';
$pdo = pdo();

// A sessao define a versao: garante que o aluno responda a mesma
// versao com que iniciou, mesmo que outra seja ativada no meio.
$S = sessao_por_token($pdo, $sid, true); // exige aberta
$idVersao = (int)$S['id_versao'];

// blocos (para o chip e o radar)
$bs = $pdo->prepare("SELECT id_bloco, nome, nome_curto, ordem FROM okrm_blocos WHERE id_versao=? ORDER BY ordem");
$bs->execute([$idVersao]);
$blocos = $bs->fetchAll();
$blocoNome = [];
foreach ($blocos as $b) $blocoNome[(int)$b['id_bloco']] = $b['nome'];

// questoes
$qs = $pdo->prepare("SELECT id_questao, id_bloco, ordem, enunciado FROM okrm_questoes WHERE id_versao=? ORDER BY ordem");
$qs->execute([$idVersao]);
$questoes = $qs->fetchAll();

if ($questoes) {
    $ids = array_map(fn($q) => (int)$q['id_questao'], $questoes);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    // NAO retorna is_correta ao front. O gabarito fica no servidor;
    // o front so o recebe apos responder, via sessao_answer.
    $as = $pdo->prepare("SELECT id_alternativa, id_questao, ordem, texto FROM okrm_alternativas WHERE id_questao IN ($in) ORDER BY id_questao, ordem");
    $as->execute($ids);
    $porQ = [];
    foreach ($as->fetchAll() as $a) {
        $porQ[(int)$a['id_questao']][] = [
            'id_alternativa' => (int)$a['id_alternativa'],
            'texto'          => $a['texto'],
        ];
    }
    foreach ($questoes as &$q) {
        $q['id_questao']  = (int)$q['id_questao'];
        $q['bloco_nome']  = $blocoNome[(int)$q['id_bloco']] ?? 'Módulo 1';
        $q['alternativas'] = $porQ[(int)$q['id_questao']] ?? [];
    }
    unset($q);
}

ok([
    'id_versao' => $idVersao,
    'blocos'    => $blocos,
    'questoes'  => $questoes,
]);
