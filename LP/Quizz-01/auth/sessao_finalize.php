<?php
require __DIR__ . '/_bootstrap.php';
$in = json_input();
$token = (string)($in['session_token'] ?? '');
if (!$token) fail('Token ausente');

$pdo = pdo();
$ses = $pdo->prepare("SELECT id_sessao, id_versao FROM lp001_quiz_sessoes WHERE session_token=? LIMIT 1");
$ses->execute([$token]);
$S = $ses->fetch();
if (!$S) fail('Sessão não encontrada', 404);
$id_sessao = (int)$S['id_sessao']; $id_versao = (int)$S['id_versao'];

// respostas + perguntas (domínio)
$q = $pdo->prepare("
  SELECT r.id_pergunta, r.score_opcao, p.id_dominio
  FROM lp001_quiz_respostas r
  JOIN lp001_quiz_perguntas p ON p.id_pergunta=r.id_pergunta
  WHERE r.id_sessao=?
");
$q->execute([$id_sessao]);
$resps = $q->fetchAll();

// domínios e pesos
$d = $pdo->prepare("SELECT id_dominio, nome, peso FROM lp001_quiz_dominios WHERE id_versao=?");
$d->execute([$id_versao]);
$doms = $d->fetchAll();
if (!$doms) fail('Domínios não encontrados', 400);

// map estrutural
$domNome = []; $peso = [];
foreach($doms as $dx){ $domNome[$dx['id_dominio']]=$dx['nome']; $peso[$dx['id_dominio']] = (float)$dx['peso']; }

// conta perguntas por domínio (para normalizar 0..100 considerando máx=10 por pergunta)
$cnt = []; $sum = [];
foreach($resps as $r){ $cnt[$r['id_dominio']] = ($cnt[$r['id_dominio']] ?? 0) + 1; $sum[$r['id_dominio']] = ($sum[$r['id_dominio']] ?? 0) + (int)$r['score_opcao']; }

$scoreDom = [];
foreach($domNome as $id=>$nm){
  if (!isset($cnt[$id]) || $cnt[$id]==0){ $scoreDom[$nm]=0; continue; }
  $max = $cnt[$id]*10;
  $scoreDom[$nm] = (int)round(($sum[$id]/$max)*100);
}

// score total ponderado
$total = 0;
foreach($domNome as $id=>$nm){ $total += ($scoreDom[$nm] ?? 0) * ($peso[$id] ?? 0); }
$total = (int)round($total);

// semáforo
$class = ($total>=70) ? 'verde' : (($total>=40)? 'amarelo' : 'vermelho');

// menor domínio = oportunidade
asort($scoreDom); $low3 = array_slice($scoreDom, 0, 3, true);
$bullets = [];
$alavancas = [];
foreach($low3 as $nm=>$sc){
  $bullets[] = "Maior oportunidade: {$nm} ({$sc}%).";
  $alavancas[] = "<b>{$nm}:</b> defina marcos mensais, donos e ‘planejado vs. realizado’ por iniciativa.";
}

// perfil por faixa
$p = $pdo->prepare("SELECT id_profile, nome FROM lp001_quiz_result_profiles WHERE id_versao=? AND ? BETWEEN intervalo_score_min AND intervalo_score_max LIMIT 1");
$p->execute([$id_versao, $total]);
$prof = $p->fetch();
$id_profile = $prof['id_profile'] ?? null;

// grava score
$payloadJson = json_encode($scoreDom, JSON_UNESCAPED_UNICODE);
$ins = $pdo->prepare("INSERT INTO lp001_quiz_scores (id_sessao,score_total,classificacao_global,score_por_dominio,id_profile,dt_calculo)
                      VALUES (?,?,?,?,?,NOW())
                      ON DUPLICATE KEY UPDATE score_total=VALUES(score_total), classificacao_global=VALUES(classificacao_global),
                                              score_por_dominio=VALUES(score_por_dominio), id_profile=VALUES(id_profile), dt_calculo=NOW()");
$ins->execute([$id_sessao,$total,$class,$payloadJson,$id_profile]);

ok([
  'score_total' => $total,
  'classificacao_global' => $class,
  'score_por_dominio' => $scoreDom,
  'resumo' => ['bullets'=>$bullets],
  'alavancas' => $alavancas,
  'id_profile' => $id_profile
]);
