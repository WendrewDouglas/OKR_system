<?php
/**
 * Import OKRs — Crescer com IA (company 10)
 * Completa o objetivo existente (id=39) com dados do documento v2.
 * Usa as mesmas funções e padrões do sistema real.
 *
 * Execução via HTTP: /OKR_system/tools/import_crescer.php?token=XXXX
 * Token protegido para segurança.
 */
declare(strict_types=1);

// Invalidar OPcache
if (function_exists('opcache_invalidate')) {
  opcache_invalidate(__FILE__, true);
}

// Proteção por token
$IMPORT_TOKEN = 'crescer_okr_2026_import_x9k3';
if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  if (($_GET['token'] ?? '') !== $IMPORT_TOKEN) {
    http_response_code(403);
    die('Forbidden');
  }
}

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/helpers/iniciativa_envolvidos.php';

// ======================== CONEXÃO ========================
$pdo = new PDO(
  "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ======================== CONSTANTES ========================
$OBJ_ID      = 39;
$COMPANY_ID  = 10;
$WENDREW     = 267;
$BRUNA       = 268;
$MARCELA     = 25;
$CREATOR     = $WENDREW; // quem "cria" no sistema
$CREATOR_NAME = 'WENDREW GOMES';
$TODAY       = date('Y-m-d');
$NOW         = date('Y-m-d H:i:s');

$counters = ['obj_updated' => 0, 'kr_updated' => 0, 'kr_created' => 0, 'ini_created' => 0, 'ini_skipped' => 0, 'ms_created' => 0];

echo "=== Import Crescer com IA — OKR v2 ===\n\n";

// ======================== 1) CORRIGIR OBJETIVO ========================
echo "[1] Corrigindo Objetivo #$OBJ_ID...\n";
$pdo->prepare("
  UPDATE objetivos SET
    dono = :dono,
    pilar_bsc = 'Clientes',
    dt_prazo = '2026-06-30',
    dt_inicio = '2026-03-01',
    ciclo = '2026-03 a 2026-06',
    tipo_ciclo = 'personalizado',
    tipo = 'estrategico',
    dt_ultima_atualizacao = :now
  WHERE id_objetivo = :id
")->execute([':dono' => $WENDREW, ':now' => $NOW, ':id' => $OBJ_ID]);
$counters['obj_updated']++;
echo "    ✓ dono→Wendrew, pilar→Clientes, prazo→2026-06-30, ciclo→Mar-Jun\n";

// ======================== 2) CORRIGIR KR2 ========================
echo "\n[2] Corrigindo KR 002-39 (data_inicio 05→04)...\n";
$pdo->prepare("
  UPDATE key_results SET
    data_inicio = '2026-04-01',
    dt_ultima_atualizacao = :now
  WHERE id_kr = '002-39'
")->execute([':now' => $NOW]);
$counters['kr_updated']++;

// Regenerar milestones do KR2 (data_inicio mudou)
$pdo->prepare("DELETE FROM milestones_kr WHERE id_kr = '002-39'")->execute();
$ms2 = generateMilestones('002-39', 12.0, 18.0, '2026-04-01', '2026-05-31', 'semanal', 'acumulativo', $COMPANY_ID);
foreach ($ms2 as $m) { insertMilestone($pdo, $m); $counters['ms_created']++; }
echo "    ✓ data_inicio→2026-04-01, natureza→acumulativo_constante, " . count($ms2) . " milestones regenerados\n";

// ======================== 3) CRIAR KRs FALTANTES (3, 4, 5) ========================
echo "\n[3] Criando Key Results faltantes...\n";

$newKRs = [
  [
    'id_kr' => '003-39', 'num' => 3, 'id_objetivo' => $OBJ_ID,
    'descricao' => '22 alunos ativos, mín 10/turma, até 30/jun',
    'baseline' => 18.0, 'meta' => 22.0,
    'unidade_medida' => 'pessoas', 'natureza_kr' => 'acumulativo',
    'tipo_frequencia_milestone' => 'semanal',
    'data_inicio' => '2026-06-01', 'data_fim' => '2026-06-30',
    'responsavel' => $MARCELA, 'status' => 'nao iniciado',
  ],
  [
    'id_kr' => '004-39', 'num' => 4, 'id_objetivo' => $OBJ_ID,
    'descricao' => 'Retenção ≥ 85% entre abril e junho (máx 2-3 cancelamentos)',
    'baseline' => 0.0, 'meta' => 85.0,
    'unidade_medida' => '%', 'natureza_kr' => 'pontual',
    'tipo_frequencia_milestone' => 'mensal',
    'data_inicio' => '2026-04-01', 'data_fim' => '2026-06-30',
    'responsavel' => $MARCELA, 'status' => 'nao iniciado',
  ],
  [
    'id_kr' => '005-39', 'num' => 5, 'id_objetivo' => $OBJ_ID,
    'descricao' => 'NPS médio ≥ 8,5/10 nas pesquisas de maio e junho',
    'baseline' => 0.0, 'meta' => 8.50,
    'unidade_medida' => 'nota', 'natureza_kr' => 'pontual',
    'tipo_frequencia_milestone' => 'mensal',
    'data_inicio' => '2026-05-01', 'data_fim' => '2026-06-30',
    'responsavel' => $MARCELA, 'status' => 'nao iniciado',
  ],
];

$stInsKR = $pdo->prepare("
  INSERT INTO key_results
    (id_kr, id_objetivo, key_result_num, descricao, baseline, meta,
     unidade_medida, natureza_kr, tipo_frequencia_milestone,
     data_inicio, data_fim, responsavel, status, status_aprovacao,
     usuario_criador, id_user_criador, dt_criacao)
  VALUES
    (:id_kr, :id_obj, :num, :desc, :bl, :meta,
     :um, :nat, :freq,
     :di, :df, :resp, :st, 'pendente',
     :ucr, :ucr_id, :dt)
");

foreach ($newKRs as $kr) {
  // Verificar se já existe
  $exists = $pdo->prepare("SELECT 1 FROM key_results WHERE id_kr = :id");
  $exists->execute([':id' => $kr['id_kr']]);
  if ($exists->fetch()) {
    echo "    → {$kr['id_kr']} já existe, pulando\n";
    continue;
  }

  $stInsKR->execute([
    ':id_kr'  => $kr['id_kr'],
    ':id_obj' => $kr['id_objetivo'],
    ':num'    => $kr['num'],
    ':desc'   => $kr['descricao'],
    ':bl'     => $kr['baseline'],
    ':meta'   => $kr['meta'],
    ':um'     => $kr['unidade_medida'],
    ':nat'    => $kr['natureza_kr'],
    ':freq'   => $kr['tipo_frequencia_milestone'],
    ':di'     => $kr['data_inicio'],
    ':df'     => $kr['data_fim'],
    ':resp'   => $kr['responsavel'],
    ':st'     => $kr['status'],
    ':ucr'    => $CREATOR_NAME,
    ':ucr_id' => $CREATOR,
    ':dt'     => $TODAY,
  ]);
  $counters['kr_created']++;

  // Gerar milestones
  $ms = generateMilestones(
    $kr['id_kr'], $kr['baseline'], $kr['meta'],
    $kr['data_inicio'], $kr['data_fim'],
    $kr['tipo_frequencia_milestone'], $kr['natureza_kr'], $COMPANY_ID
  );
  foreach ($ms as $m) { insertMilestone($pdo, $m); $counters['ms_created']++; }
  echo "    ✓ {$kr['id_kr']}: {$kr['descricao']} (+" . count($ms) . " milestones)\n";
}

// ======================== 4) CRIAR INICIATIVAS ========================
echo "\n[4] Criando Iniciativas...\n";

$W = [$WENDREW];
$B = [$BRUNA];
$M = [$MARCELA];
$WB = [$WENDREW, $BRUNA];
$MB = [$MARCELA, $BRUNA];
$ALL = [$WENDREW, $BRUNA, $MARCELA];

// Todas as iniciativas por KR
$allIniciativas = [
  // =================== KR1 (001-39) ===================
  // 1.1 e 1.2 já existem
  ['kr' => '001-39', 'num' =>  3, 'desc' => 'Produzir e-book "5 coisas que seu filho já faz com IA" (8-10 págs)', 'resp' => $WB, 'prazo' => '2026-03-04'],
  ['kr' => '001-39', 'num' =>  4, 'desc' => 'Criar panfleto com QR code → e-book. Design simples', 'resp' => $W, 'prazo' => '2026-03-05'],
  ['kr' => '001-39', 'num' =>  5, 'desc' => 'Imprimir panfletos (500-1.000 unidades)', 'resp' => $M, 'prazo' => '2026-03-06'],
  ['kr' => '001-39', 'num' =>  6, 'desc' => 'Gravar vídeo 60s: celular, luz natural, W + B falando com naturalidade', 'resp' => $WB, 'prazo' => '2026-03-05'],
  ['kr' => '001-39', 'num' =>  7, 'desc' => 'Montar planilha matrículas + financeira (Google Sheets)', 'resp' => $M, 'prazo' => '2026-03-03'],
  ['kr' => '001-39', 'num' =>  8, 'desc' => 'Preparar conteúdo da aula experimental (roteiro 1h, atividades IA)', 'resp' => $WB, 'prazo' => '2026-03-07'],
  ['kr' => '001-39', 'num' =>  9, 'desc' => 'Mapear 3-5 escolas particulares de Araçatuba (perfil família compatível)', 'resp' => $M, 'prazo' => '2026-03-08'],
  ['kr' => '001-39', 'num' => 10, 'desc' => 'Panfletagem na saída das escolas — 1ª onda. Conversa com pais, entrega panfleto com QR → e-book', 'resp' => $M, 'prazo' => '2026-03-12'],
  ['kr' => '001-39', 'num' => 11, 'desc' => 'Deixar panfletos em pontos estratégicos: pediatras, escolas de inglês, lojas infantis', 'resp' => $M, 'prazo' => '2026-03-10'],
  ['kr' => '001-39', 'num' => 12, 'desc' => 'Bruna: mensagens individuais para mães de pacientes e colegas (vídeo + link e-book)', 'resp' => $B, 'prazo' => '2026-03-12'],
  ['kr' => '001-39', 'num' => 13, 'desc' => 'Bruna: 1º post no Instagram pessoal (crianças e IA, como psicóloga)', 'resp' => $B, 'prazo' => '2026-03-08'],
  ['kr' => '001-39', 'num' => 14, 'desc' => 'Publicar vídeo 60s no Instagram Crescer com IA + impulsionar R$ 150', 'resp' => $M, 'prazo' => '2026-03-08'],
  ['kr' => '001-39', 'num' => 15, 'desc' => 'Mapear e entrar em grupos WhatsApp de mães (escolas, bairros)', 'resp' => $M, 'prazo' => '2026-03-08'],
  ['kr' => '001-39', 'num' => 16, 'desc' => 'Distribuir e-book gratuitamente nos grupos de WhatsApp', 'resp' => $M, 'prazo' => '2026-03-09'],
  ['kr' => '001-39', 'num' => 17, 'desc' => 'Bruna: participar organicamente nos grupos, responder dúvidas como profissional', 'resp' => $B, 'prazo' => '2026-03-14'],
  ['kr' => '001-39', 'num' => 18, 'desc' => 'Identificar e convidar 3-5 mães influentes (vaga gratuita na experimental)', 'resp' => $MB, 'prazo' => '2026-03-10'],
  ['kr' => '001-39', 'num' => 19, 'desc' => 'Organizar logística do 1º evento (local, lanche, fichas, cronograma)', 'resp' => $M, 'prazo' => '2026-03-13'],
  ['kr' => '001-39', 'num' => 20, 'desc' => 'Confirmar inscritos por WhatsApp. Meta: 15-20 crianças', 'resp' => $M, 'prazo' => '2026-03-14'],
  ['kr' => '001-39', 'num' => 21, 'desc' => '1º EVENTO EXPERIMENTAL (sáb). 1h atividade IA. Pais nos 15min finais. Matrícula R$ 80', 'resp' => $ALL, 'prazo' => '2026-03-15'],
  ['kr' => '001-39', 'num' => 22, 'desc' => 'Follow-up WhatsApp: fotos do evento + vagas limitadas + deadline Fundadora', 'resp' => $M, 'prazo' => '2026-03-20'],
  ['kr' => '001-39', 'num' => 23, 'desc' => 'Publicar fotos/vídeos do evento como prova social (Instagram + grupos)', 'resp' => $M, 'prazo' => '2026-03-17'],
  ['kr' => '001-39', 'num' => 24, 'desc' => 'Bruna: 2º post Instagram pessoal (bastidores do evento, visão profissional)', 'resp' => $B, 'prazo' => '2026-03-17'],
  ['kr' => '001-39', 'num' => 25, 'desc' => 'Panfletagem na saída das escolas — 2ª onda. Com fotos do evento no panfleto', 'resp' => $M, 'prazo' => '2026-03-26'],
  ['kr' => '001-39', 'num' => 26, 'desc' => 'Bruna: 3º e 4º posts Instagram pessoal (manter frequência 2x/semana)', 'resp' => $B, 'prazo' => '2026-03-25'],
  ['kr' => '001-39', 'num' => 27, 'desc' => 'Prospectar pais via DMs do Instagram (quem curtiu/comentou conteúdos)', 'resp' => $M, 'prazo' => '2026-03-31'],
  ['kr' => '001-39', 'num' => 28, 'desc' => '2º EVENTO EXPERIMENTAL (se < 12 matrículas)', 'resp' => $ALL, 'prazo' => '2026-03-29'],
  ['kr' => '001-39', 'num' => 29, 'desc' => 'Fechar matrículas: contrato + pagamento. Pipeline atualizado', 'resp' => $M, 'prazo' => '2026-03-31'],
  ['kr' => '001-39', 'num' => 30, 'desc' => 'DEADLINE Turma Fundadora (R$ 397). Após 31/mar: R$ 497', 'resp' => $M, 'prazo' => '2026-03-31'],

  // =================== KR2 (002-39) ===================
  ['kr' => '002-39', 'num' =>  1, 'desc' => 'Preparar conteúdo técnico aulas 1-4', 'resp' => $W, 'prazo' => '2026-04-08'],
  ['kr' => '002-39', 'num' =>  2, 'desc' => 'Preparar conteúdo pedagógico aulas 1-4', 'resp' => $B, 'prazo' => '2026-04-08'],
  ['kr' => '002-39', 'num' =>  3, 'desc' => 'PRIMEIRA AULA OFICIAL. Turma A 8h-9h30. Turma B 10h-11h30', 'resp' => $WB, 'prazo' => '2026-04-11'],
  ['kr' => '002-39', 'num' =>  4, 'desc' => 'Aulas 2, 3 e 4 (sábados seguintes)', 'resp' => $WB, 'prazo' => '2026-05-02'],
  ['kr' => '002-39', 'num' =>  5, 'desc' => 'Marcela: receber famílias, fotografar, filmar aos sábados', 'resp' => $M, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' =>  6, 'desc' => 'Bruna: manter 2 posts/semana no Instagram pessoal (autoridade)', 'resp' => $B, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' =>  7, 'desc' => 'Marcela: calendário editorial Instagram Crescer com IA (3+/semana)', 'resp' => $M, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' =>  8, 'desc' => 'Marcela: impulsionamento mensal Instagram (R$ 150)', 'resp' => $M, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' =>  9, 'desc' => 'Marcela: prospectar novos pais via WhatsApp + Instagram + panfletagem', 'resp' => $M, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' => 10, 'desc' => 'Realizar 2º evento experimental se < 18 alunos em 25/abr', 'resp' => $ALL, 'prazo' => '2026-05-03'],
  ['kr' => '002-39', 'num' => 11, 'desc' => 'Controlar cobranças mensais (lembrete, confirmação, inadimplência)', 'resp' => $M, 'prazo' => '2026-05-31'],
  ['kr' => '002-39', 'num' => 12, 'desc' => 'Gerenciar perfis pessoais Wendrew (LI/Insta) e Bruna (Insta)', 'resp' => $M, 'prazo' => '2026-05-31'],

  // =================== KR3 (003-39) ===================
  ['kr' => '003-39', 'num' =>  1, 'desc' => 'Preparar conteúdo técnico aulas 5-12', 'resp' => $W, 'prazo' => '2026-05-30'],
  ['kr' => '003-39', 'num' =>  2, 'desc' => 'Preparar conteúdo pedagógico aulas 5-12', 'resp' => $B, 'prazo' => '2026-05-30'],
  ['kr' => '003-39', 'num' =>  3, 'desc' => 'Aulas 5 a 12. Projeto final nas aulas 9-12', 'resp' => $WB, 'prazo' => '2026-06-21'],
  ['kr' => '003-39', 'num' =>  4, 'desc' => 'Convidar famílias externas para apresentação (meta: 10)', 'resp' => $M, 'prazo' => '2026-06-15'],
  ['kr' => '003-39', 'num' =>  5, 'desc' => 'Panfletagem escolar 3ª onda: com fotos reais das aulas + convite apresentação', 'resp' => $M, 'prazo' => '2026-06-13'],
  ['kr' => '003-39', 'num' =>  6, 'desc' => 'APRESENTAÇÃO PROJETOS FINAIS. Pais assistem. Relatórios + certificados', 'resp' => $ALL, 'prazo' => '2026-06-27'],
  ['kr' => '003-39', 'num' =>  7, 'desc' => 'Abrir matrículas Módulo 2 no evento (R$ 497 novos)', 'resp' => $M, 'prazo' => '2026-06-27'],
  ['kr' => '003-39', 'num' =>  8, 'desc' => 'Filmar/publicar trechos da apresentação (peça #1 de marketing)', 'resp' => $M, 'prazo' => '2026-06-29'],
  ['kr' => '003-39', 'num' =>  9, 'desc' => 'Follow-up com pais não-alunos pós-apresentação', 'resp' => $M, 'prazo' => '2026-06-30'],

  // =================== KR4 (004-39) ===================
  ['kr' => '004-39', 'num' =>  1, 'desc' => 'Criar regras do tablet (embasamento pedagógico)', 'resp' => $B, 'prazo' => '2026-03-03'],
  ['kr' => '004-39', 'num' =>  2, 'desc' => 'Documentar processo inscrição + cobrança', 'resp' => $M, 'prazo' => '2026-03-07'],
  ['kr' => '004-39', 'num' =>  3, 'desc' => 'Lista de presença atualizada todo sábado', 'resp' => $M, 'prazo' => '2026-06-30'],
  ['kr' => '004-39', 'num' =>  4, 'desc' => 'Identificar alunos com risco de evasão (faltas, desinteresse)', 'resp' => $M, 'prazo' => '2026-06-30'],
  ['kr' => '004-39', 'num' =>  5, 'desc' => 'Conversar individualmente com pais de alunos em risco', 'resp' => $B, 'prazo' => '2026-06-30'],
  ['kr' => '004-39', 'num' =>  6, 'desc' => 'Acompanhamento emocional e cognitivo individual', 'resp' => $B, 'prazo' => '2026-06-30'],
  ['kr' => '004-39', 'num' =>  7, 'desc' => 'Ponto de contato dos pais (WhatsApp) + comunicados semanais', 'resp' => $M, 'prazo' => '2026-06-30'],
  ['kr' => '004-39', 'num' =>  8, 'desc' => 'Elaborar relatório individual por aluno (assinado CRP)', 'resp' => $B, 'prazo' => '2026-06-22'],
  ['kr' => '004-39', 'num' =>  9, 'desc' => 'Preparar certificados de conclusão', 'resp' => $M, 'prazo' => '2026-06-22'],

  // =================== KR5 (005-39) ===================
  ['kr' => '005-39', 'num' =>  1, 'desc' => 'Criar formulário Google NPS (5 perguntas, 0-10)', 'resp' => $M, 'prazo' => '2026-05-10'],
  ['kr' => '005-39', 'num' =>  2, 'desc' => 'Enviar 1ª pesquisa NPS (pós aula 4)', 'resp' => $M, 'prazo' => '2026-05-17'],
  ['kr' => '005-39', 'num' =>  3, 'desc' => 'Analisar NPS #1 e identificar melhorias', 'resp' => $ALL, 'prazo' => '2026-05-20'],
  ['kr' => '005-39', 'num' =>  4, 'desc' => 'Implementar ajustes com base no feedback', 'resp' => $WB, 'prazo' => '2026-05-24'],
  ['kr' => '005-39', 'num' =>  5, 'desc' => 'Coletar feedback informal nos sábados', 'resp' => $M, 'prazo' => '2026-06-30'],
  ['kr' => '005-39', 'num' =>  6, 'desc' => 'Enviar 2ª pesquisa NPS (pós aula 12)', 'resp' => $M, 'prazo' => '2026-06-28'],
  ['kr' => '005-39', 'num' =>  7, 'desc' => 'Coletar 3-5 depoimentos em vídeo de pais', 'resp' => $M, 'prazo' => '2026-06-29'],
  ['kr' => '005-39', 'num' =>  8, 'desc' => 'Comparar NPS #1 vs #2 na reunião final', 'resp' => $M, 'prazo' => '2026-06-30'],
];

$stInsIni = $pdo->prepare("
  INSERT INTO iniciativas
    (id_iniciativa, id_kr, num_iniciativa, descricao, status,
     id_user_criador, dt_criacao, id_user_responsavel, dt_prazo)
  VALUES
    (:id, :kr, :num, :desc, 'nao iniciado',
     :criador, :dt, :resp1, :prazo)
");

// Buscar iniciativas existentes para evitar duplicação
$existingInis = [];
$stExist = $pdo->prepare("SELECT id_kr, num_iniciativa FROM iniciativas WHERE id_kr IN ('001-39','002-39','003-39','004-39','005-39')");
$stExist->execute();
foreach ($stExist->fetchAll() as $row) {
  $existingInis[$row['id_kr'] . '-' . $row['num_iniciativa']] = true;
}

$pdo->beginTransaction();
try {
  foreach ($allIniciativas as $ini) {
    $key = $ini['kr'] . '-' . $ini['num'];
    if (isset($existingInis[$key])) {
      echo "    → {$ini['kr']} #{$ini['num']} já existe, pulando\n";
      $counters['ini_skipped']++;
      continue;
    }

    $idIni = bin2hex(random_bytes(12)); // 24-char hex, mesmo padrão do sistema
    $resp1 = $ini['resp'][0]; // primeiro responsável = denormalized

    $stInsIni->execute([
      ':id'      => $idIni,
      ':kr'      => $ini['kr'],
      ':num'     => $ini['num'],
      ':desc'    => $ini['desc'],
      ':criador' => $CREATOR,
      ':dt'      => $TODAY,
      ':resp1'   => $resp1,
      ':prazo'   => $ini['prazo'],
    ]);

    // Sync envolvidos (mesma função usada pelo sistema)
    sync_iniciativa_envolvidos($pdo, $idIni, $ini['resp']);

    // Registrar histórico de status (mesmo que detalhe_okr.php faz)
    try {
      $pdo->prepare("
        INSERT INTO apontamentos_status_iniciativas
          (id_iniciativa, status, data_hora, id_user, observacao)
        VALUES (:id, 'nao iniciado', :dt, :uid, 'Importado via script')
      ")->execute([':id' => $idIni, ':dt' => $NOW, ':uid' => $CREATOR]);
    } catch (Throwable $e) { /* tabela pode não ter todas as colunas */ }

    $counters['ini_created']++;
  }

  $pdo->commit();
  echo "    ✓ Iniciativas processadas\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  echo "    ✗ ERRO: " . $e->getMessage() . "\n";
  exit(1);
}

// ======================== RESUMO ========================
echo "\n========================================\n";
echo "RESUMO DA IMPORTAÇÃO\n";
echo "========================================\n";
echo "Objetivo atualizado:    {$counters['obj_updated']}\n";
echo "KRs atualizados:       {$counters['kr_updated']}\n";
echo "KRs criados:           {$counters['kr_created']}\n";
echo "Milestones criados:    {$counters['ms_created']}\n";
echo "Iniciativas criadas:   {$counters['ini_created']}\n";
echo "Iniciativas ignoradas: {$counters['ini_skipped']} (já existiam)\n";
echo "========================================\n";
echo "Importação concluída com sucesso!\n";


// ======================== FUNÇÕES AUXILIARES ========================

function generateMilestones(string $idKr, float $baseline, float $meta, string $inicio, string $fim, string $freq, string $natureza, int $companyId): array {
  $dates = generateDateSeries($inicio, $fim, $freq);
  if (empty($dates)) return [];

  $n = count($dates);
  $milestones = [];

  foreach ($dates as $i => $date) {
    $pos = ($i + 1) / $n; // 0..1

    if ($natureza === 'pontual' || $natureza === 'binario') {
      // Pontual/Binário: 0 até o último ponto = meta
      $val = ($i === $n - 1) ? $meta : $baseline;
    } elseif ($natureza === 'acumulativo') {
      // Linear (acumulativo constante)
      $val = $baseline + ($meta - $baseline) * $pos;
    } elseif (strpos($natureza, 'exponenc') !== false) {
      // Exponential
      $val = $baseline + ($meta - $baseline) * pow($pos, 2);
    } else {
      $val = $baseline + ($meta - $baseline) * $pos;
    }

    $milestones[] = [
      'id_kr'          => $idKr,
      'num_ordem'      => $i + 1,
      'data_ref'       => $date,
      'valor_esperado' => round($val, 2),
      'id_company'     => $companyId,
    ];
  }

  return $milestones;
}

function generateDateSeries(string $inicio, string $fim, string $freq): array {
  $start = new DateTimeImmutable($inicio);
  $end   = new DateTimeImmutable($fim);
  $dates = [];

  $intervals = [
    'diario'    => 'P1D',
    'semanal'   => 'P7D',
    'quinzenal' => 'P14D',
    'mensal'    => 'P1M',
    'bimestral' => 'P2M',
    'trimestral'=> 'P3M',
    'semestral' => 'P6M',
    'anual'     => 'P1Y',
  ];
  $intv = $intervals[$freq] ?? 'P7D';

  $current = $start->add(new DateInterval($intv));
  while ($current <= $end) {
    $dates[] = $current->format('Y-m-d');
    $current = $current->add(new DateInterval($intv));
  }

  // Garante que a data final está na lista
  $fimStr = $end->format('Y-m-d');
  if (empty($dates) || end($dates) !== $fimStr) {
    $dates[] = $fimStr;
  }

  return $dates;
}

function insertMilestone(PDO $pdo, array $m): void {
  $pdo->prepare("
    INSERT INTO milestones_kr
      (id_kr, num_ordem, data_ref, valor_esperado, gerado_automatico, id_company)
    VALUES
      (:kr, :num, :dt, :val, 1, :cid)
  ")->execute([
    ':kr'  => $m['id_kr'],
    ':num' => $m['num_ordem'],
    ':dt'  => $m['data_ref'],
    ':val' => $m['valor_esperado'],
    ':cid' => $m['id_company'],
  ]);
}
