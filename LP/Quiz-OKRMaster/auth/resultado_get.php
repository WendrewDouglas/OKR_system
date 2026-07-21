<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$sid  = isset($_GET['sid']) ? trim((string)$_GET['sid']) : '';
$full = isset($_GET['full']) && $_GET['full'] === '1'; // inclui revisao das questoes

$pdo = pdo();
$S = sessao_por_token($pdo, $sid, false);
$idSessao = (int)$S['id_sessao'];
$idVersao = (int)$S['id_versao'];

$r = $pdo->prepare("
  SELECT res.*, f.rotulo, f.leitura, f.cor
    FROM okrm_resultados res
    LEFT JOIN okrm_faixas f ON f.id_faixa = res.id_faixa
   WHERE res.id_sessao=? LIMIT 1
");
$r->execute([$idSessao]);
$R = $r->fetch();
if (!$R) fail('Resultado ainda não calculado.', 404);

$out = [
    'nome'            => $S['aluno_nome'],
    'data_aula'       => $S['data_aula'],
    'acertos'         => (int)$R['acertos'],
    'total'           => (int)$R['total'],
    'percentual'      => (int)$R['percentual'],
    'faixa'           => $R['rotulo'] ?: '—',
    'leitura'         => $R['leitura'] ?: '',
    'cor'             => $R['cor'] ?: 'verde',
    'score_por_bloco' => json_decode($R['score_por_bloco'] ?: '{}', true),
    'tempo_total_ms'  => (int)$R['tempo_total_ms'],
    'tempo_medio_ms'  => (int)$R['tempo_medio_ms'],
];

if ($full) {
    // revisao completa: questao, alternativas, o que marcou, gabarito e justificativas
    $qs = $pdo->prepare("
      SELECT q.id_questao, q.ordem, q.enunciado, b.nome AS bloco
        FROM okrm_questoes q
        JOIN okrm_blocos b ON b.id_bloco = q.id_bloco
       WHERE q.id_versao=? ORDER BY q.ordem
    ");
    $qs->execute([$idVersao]);
    $questoes = $qs->fetchAll();

    $rp = $pdo->prepare("SELECT id_questao, id_alternativa, acertou, tempo_ms FROM okrm_respostas WHERE id_sessao=?");
    $rp->execute([$idSessao]);
    $respMap = [];
    foreach ($rp->fetchAll() as $x) $respMap[(int)$x['id_questao']] = $x;

    $rev = [];
    foreach ($questoes as $q) {
        $idQ = (int)$q['id_questao'];
        $as = $pdo->prepare("SELECT id_alternativa, ordem, texto, is_correta, justificativa FROM okrm_alternativas WHERE id_questao=? ORDER BY ordem");
        $as->execute([$idQ]);
        $alts = $as->fetchAll();
        $marc = $respMap[$idQ]['id_alternativa'] ?? null;
        $rev[] = [
            'ordem'     => (int)$q['ordem'],
            'bloco'     => $q['bloco'],
            'enunciado' => $q['enunciado'],
            'acertou'   => isset($respMap[$idQ]) ? (bool)$respMap[$idQ]['acertou'] : false,
            'escolhida' => $marc !== null ? (int)$marc : null,
            'alternativas' => array_map(fn($a) => [
                'id_alternativa' => (int)$a['id_alternativa'],
                'texto'          => $a['texto'],
                'is_correta'     => (int)$a['is_correta'] === 1,
                'justificativa'  => $a['justificativa'],
            ], $alts),
        ];
    }
    $out['revisao'] = $rev;
}

ok($out);
