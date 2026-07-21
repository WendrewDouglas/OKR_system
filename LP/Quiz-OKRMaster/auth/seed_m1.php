<?php
/**
 * Seed do Modulo 1 (BSC) da Avaliacao OKR Master.
 * Uso: acesse no navegador com ?token=SEED_TOKEN.
 * Idempotente por versao: recria a versao 'v1' do modulo M1.
 *
 * Executar UMA vez apos rodar sql/schema.sql.
 */
declare(strict_types=1);

// Somente linha de comando (SSH). Nunca executavel por HTTP:
// evita que a semeadura seja disparada por qualquer visitante.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/_bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = pdo();
$pdo->beginTransaction();

try {
    // ---- Modulo ----
    $pdo->prepare("INSERT INTO okrm_modulos (codigo,titulo,subtitulo,ordem,ativo)
                   VALUES ('M1','Balanced Scorecard','A arquitetura da estratégia',1,1)
                   ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), subtitulo=VALUES(subtitulo), ativo=1")
        ->execute();
    $idModulo = (int)$pdo->query("SELECT id_modulo FROM okrm_modulos WHERE codigo='M1'")->fetchColumn();

    // ---- Limpa versao anterior 'v1' (recriacao limpa) ----
    $old = $pdo->prepare("SELECT id_versao FROM okrm_versao WHERE id_modulo=? AND label='v1'");
    $old->execute([$idModulo]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $iv) {
        // FKs em cascata cuidam de blocos/questoes/alternativas/faixas
        $pdo->prepare("DELETE FROM okrm_versao WHERE id_versao=?")->execute([$iv]);
    }

    // ---- Nova versao ativa ----
    $pdo->prepare("UPDATE okrm_versao SET is_ativa=0 WHERE id_modulo=?")->execute([$idModulo]);
    $pdo->prepare("INSERT INTO okrm_versao (id_modulo,label,is_ativa) VALUES (?, 'v1', 1)")->execute([$idModulo]);
    $idVersao = (int)$pdo->lastInsertId();

    // ---- Blocos ----
    $blocos = [
        ['Conduzir a redação de objetivos', 'Objetivos', 1],
        ['Conduzir o mapa e as relações causais', 'Mapa & causa-efeito', 2],
        ['Conduzir indicadores e metas', 'Indicadores & metas', 3],
        ['Conduzir iniciativas, orçamento e governança', 'Iniciativas & governança', 4],
    ];
    $idBloco = [];
    $stB = $pdo->prepare("INSERT INTO okrm_blocos (id_versao,nome,nome_curto,ordem) VALUES (?,?,?,?)");
    foreach ($blocos as $i => $b) {
        $stB->execute([$idVersao, $b[0], $b[1], $b[2]]);
        $idBloco[$i+1] = (int)$pdo->lastInsertId();
    }

    // ---- Faixas (20 questoes) ----
    $faixas = [
        [85,100,'Apto a facilitar com autonomia',
         'Domínio consolidado da aplicação prática do BSC. Você está apto a conduzir sessões de construção de objetivos, mapa, indicadores e iniciativas com autonomia.','verde'],
        [65,84,'Apto a facilitar com acompanhamento',
         'Bom entendimento do método. Revise os temas das questões que errou antes de conduzir sessões, e conte com acompanhamento de um instrutor nas primeiras rodadas.','verde'],
        [45,64,'Recomenda-se observação prévia',
         'Entendimento parcial. Recomenda-se participar como observador em pelo menos uma rodada completa de facilitação antes de conduzir sozinho.','amarelo'],
        [0,44,'Requer revisão do módulo',
         'É recomendável revisar o Módulo 1 integralmente — fundamentos, perspectivas, mapa e indicadores — antes de avançar para o Módulo 2.','vermelho'],
    ];
    $stF = $pdo->prepare("INSERT INTO okrm_faixas (id_versao,pct_min,pct_max,rotulo,leitura,cor) VALUES (?,?,?,?,?,?)");
    foreach ($faixas as $f) $stF->execute([$idVersao, $f[0], $f[1], $f[2], $f[3], $f[4]]);

    // ---- Questoes ----
    // Estrutura: [bloco, enunciado, [ [texto, is_correta, justificativa], ... ] ]
    // A ordem das alternativas aqui NAO importa: o front embaralha.
    $Q = [];

    // ===================== BLOCO 1 — OBJETIVOS =====================
    $Q[] = [1,
      'Ao revisar o mapa de uma área, você encontra o seguinte objetivo cadastrado: "Logística e atendimento". Qual é a conduta correta?',
      [
        ['Aprovar o texto e suprir a falta de especificidade na definição dos indicadores.', 0,
         'Transferir a especificidade para o indicador inverte a ordem do método. Se o objetivo não declara qual resultado se busca, o indicador passaria a definir a direção que o objetivo deveria ter dado — e a estratégia acabaria determinada pela métrica disponível.'],
        ['Reescrever você mesmo o objetivo e apresentá-lo pronto na reunião seguinte.', 0,
         'Reescrever no lugar do responsável produz um texto tecnicamente correto e organizacionalmente órfão. A construção com quem executa é o que gera compromisso; o texto pronto entregue de fora costuma ser cumprido apenas formalmente.'],
        ['Devolver ao responsável para que o reescreva com verbo de ação e resultado desejado.', 1,
         'Um objetivo estratégico é uma frase curta composta por verbo de ação mais resultado desejado. "Logística e atendimento" nomeia dois temas e não expressa resultado algum: não pode ser medido, avaliado nem conectado a outros objetivos. A conduta adequada é devolver com o critério explicado, perguntando o que precisa melhorar em cada frente — o que geralmente revela dois objetivos distintos.'],
        ['Desdobrar o mesmo texto em dois objetivos, um em Processos e outro em Cliente.', 0,
         'Duplicar um texto vago em duas perspectivas multiplica o defeito. A alocação em perspectiva só pode ser decidida depois que o resultado pretendido estiver claro — antes disso, não há o que alocar.'],
      ]];

    $Q[] = [1,
      'Um gestor cadastra como objetivo estratégico: "Implantar um sistema de CRM até dezembro". Qual é a conduta correta?',
      [
        ['Reclassificar como iniciativa e identificar com o gestor qual objetivo ela sustenta.', 1,
         'Objetivo descreve o resultado pretendido; iniciativa descreve o projeto executado para alcançá-lo, com início, fim e recursos dedicados. "Implantar um CRM" é uma iniciativa. A conduta correta é recuperar a intenção por trás dela — o que o gestor espera que melhore depois que o sistema estiver funcionando — e transformar essa resposta no objetivo, mantendo o CRM como iniciativa vinculada.'],
        ['Aprovar, pois o texto traz verbo de ação, prazo definido e é plenamente verificável.', 0,
         'Verbo e prazo não distinguem objetivo de iniciativa: toda iniciativa tem os dois. O critério é outro — se a frase expressa um resultado pretendido ou um meio para obtê-lo.'],
        ['Manter como objetivo estratégico, retirando apenas o prazo, que não lhe cabe.', 0,
         'Remover a data não muda a natureza do item, que continua sendo um projeto. Além disso, ausência de prazo não caracteriza objetivo: objetivos podem ter horizonte de tempo associado.'],
        ['Reclassificar o item como indicador, apurado pelo percentual de conclusão do projeto.', 0,
         'Indicador é a métrica que afere o avanço de um objetivo. Percentual de conclusão afere o andamento de um projeto — é controle de execução da iniciativa, não medição de resultado estratégico.'],
      ]];

    $Q[] = [1,
      'Um diretor propõe o objetivo: "Aumentar o faturamento para 22 milhões com margem EBITDA de 18%". Qual é a conduta correta?',
      [
        ['Manter o objetivo único e criar dois indicadores distintos para acompanhá-lo.', 0,
         'Dois indicadores para um objetivo único apenas deslocam o impasse para a apuração. Continua sem haver regra para o caso em que um indicador avança e o outro não.'],
        ['Aprovar, pois a presença dos dois números reduz o risco de interpretação equivocada.', 0,
         'Quantificação dá aparência de precisão, mas o defeito aqui é estrutural, não numérico. O problema é o acúmulo de resultados distintos em uma frase, e ele permanece com ou sem números.'],
        ['Retirar os números e manter "aumentar o faturamento com margem saudável".', 0,
         'Retirar os números piora a redação, porque introduz um termo subjetivo — "margem saudável" — sem resolver o acúmulo de resultados. A quantificação pertence à meta do indicador, e não é ela que causa o problema.'],
        ['Separar em dois objetivos, um de crescimento de receita e outro de margem.', 1,
         'A regra é um resultado por objetivo. Receita e margem são movidas por alavancas diferentes — crescimento e produtividade — e podem evoluir em sentidos opostos. Se a receita for atingida e a margem não, não há critério para declarar o objetivo cumprido. Separar preserva a avaliação de cada resultado e permite iniciativas distintas.'],
      ]];

    $Q[] = [1,
      'Ao validar o objetivo "Atingir 22 milhões de faturamento no exercício", qual verificação deve ser feita antes da aprovação?',
      [
        ['Se a empresa tem capacidade produtiva instalada para sustentar esse volume.', 0,
         'Viabilidade produtiva é discussão pertinente, mas pertence à definição do nível de ambição da meta e ao desenho das iniciativas. Não é a verificação de redação que autoriza o cadastro do objetivo.'],
        ['Qual grandeza será apurada — receita bruta ou líquida — com o registro da definição.', 1,
         'Termos aparentemente óbvios são a origem mais frequente de disputa no encerramento do ciclo. "Faturamento" admite ao menos duas leituras, e a diferença entre bruto e líquido costuma ser expressiva. Sem essa definição registrada junto à fórmula e à fonte de apuração, a avaliação final vira discussão sobre o que estava escrito.'],
        ['Se o valor pretendido já foi aprovado formalmente pelo conselho de administração.', 0,
         'Aprovação formal é etapa de governança posterior. Um objetivo ambíguo continua ambíguo depois de aprovado por qualquer instância — a aprovação apenas oficializa a ambiguidade.'],
        ['Nenhuma: valor e prazo definidos já tornam o objetivo plenamente verificável.', 0,
         'Número e prazo não garantem verificabilidade. Verificável é o objetivo que admite uma única leitura do que será medido, e este admite duas.'],
      ]];

    // ===================== BLOCO 2 — MAPA & CAUSA-EFEITO =====================
    $Q[] = [2,
      'Uma área registrou "Capacitar a equipe comercial em venda consultiva" na perspectiva de Processos Internos. Qual é a conduta correta?',
      [
        ['Realocar para Aprendizado e criar objetivo próprio de processo ligado a ele.', 1,
         'Desenvolvimento de competências é capital humano, componente da perspectiva de Aprendizado e Crescimento. O erro de alocação esconde uma oportunidade: há dois objetivos distintos — capacitar a equipe e padronizar a venda consultiva — e a relação entre eles é exatamente uma seta de causa e efeito. Ao conduzir a separação, o facilitador corrige a alocação e torna explícita uma hipótese que estava implícita.'],
        ['Manter em Processos, já que a capacitação ocorre por meio de um processo de treinamento.', 0,
         'O critério de alocação é a natureza do resultado, não o meio de obtê-lo. Praticamente todo objetivo se realiza por meio de algum processo; adotar esse critério faria todas as perspectivas colapsarem em Processos Internos.'],
        ['Realocar para Cliente, pois a venda consultiva se destina a atendê-lo melhor.', 0,
         'O benefício ao cliente é o efeito final da cadeia, situado duas perspectivas acima. Alocar objetivos na perspectiva de seu efeito, e não de sua natureza, dissolve a lógica causal do mapa.'],
        ['Duplicar o objetivo em Aprendizado e em Processos, preenchendo as duas perspectivas.', 0,
         'Duplicar para preencher perspectivas inverte a finalidade do mapa, que deve refletir a estratégia e não aparentar completude. Objetivo duplicado ainda gera duas apurações para o mesmo resultado.'],
      ]];

    $Q[] = [2,
      'O mapa resultante de um workshop tem oito objetivos em Financeira, cinco em Cliente, dois em Processos e nenhum em Aprendizado e Crescimento. Qual é a conduta correta?',
      [
        ['Aprovar, pois a concentração em Financeira reflete a prioridade de resultado da empresa.', 0,
         'Concentração na perspectiva financeira não demonstra foco em resultado; demonstra ausência das causas que o produzem. O balanceamento entre as quatro perspectivas é o que dá nome ao método.'],
        ['Criar você mesmo objetivos de Aprendizado, como "desenvolver pessoas", para preencher.', 0,
         'Objetivos genéricos criados pelo facilitador preenchem o mapa sem preencher a estratégia. "Desenvolver pessoas" não indica qual competência, para sustentar qual processo, a serviço de qual resultado — e ninguém se reconhece como responsável por ele.'],
        ['Aprovar e registrar a ausência de Aprendizado como ponto de atenção do próximo ciclo.', 0,
         'Postergar significa operar um ciclo inteiro com a base do mapa vazia. Os objetivos de processo e de cliente já definidos tendem a não se sustentar, e a falha só aparecerá quando os resultados não vierem.'],
        ['Percorrer com o grupo cada cadeia causal, do topo até a base do mapa.', 1,
         'Um scorecard concentrado na perspectiva financeira reproduz o problema que o BSC foi criado para resolver: medir resultados sem as causas que os produzem. A condução correta usa a própria lógica causal como ferramenta — partindo dos objetivos já definidos, pergunta-se quais processos os sustentam e quais competências e sistemas sustentam esses processos. Os objetivos ausentes emergem do grupo, com dono e sentido.'],
      ]];

    $Q[] = [2,
      'Dois donos de objetivo precisam validar a seta que liga o objetivo de Aprendizado ao objetivo de Processos. Qual é a conduta correta?',
      [
        ['Confirmar com ambos que os dois objetivos são relevantes e registrar a conexão.', 0,
         'Concordar que ambos os objetivos importam não valida a relação entre eles. Dois objetivos podem ser legítimos e ainda assim não guardar vínculo causal — e nesse caso a seta afirma algo falso sobre a estratégia.'],
        ['Avaliar você mesmo a plausibilidade da conexão e comunicar a decisão aos dois.', 0,
         'A hipótese pertence a quem executa. O facilitador domina o método; os donos dominam a operação. Decidir por eles retira o compromisso de ambos e insere no mapa uma suposição que ninguém assumiu.'],
        ['Obter dos dois donos acordo explícito sobre o que será entregue e o que será recebido.', 1,
         'Cada seta é uma hipótese estratégica que será cobrada dos dois lados, e por isso precisa de acordo explícito entre quem entrega e quem recebe. Quem está acima declara se o que receberá é suficiente para alcançar seu resultado; quem está abaixo declara se consegue entregar. Havendo lacuna, ela é negociada antes do início do ciclo, pilar por pilar até a perspectiva financeira.'],
        ['Registrar a seta e validá-la na primeira reunião de acompanhamento, já com dados.', 0,
         'A validação prévia existe para evitar que um ciclo seja executado sobre hipótese sem sustentação. Descobrir a falha quando os dados chegarem significa ter perdido o período.'],
      ]];

    $Q[] = [2,
      'Em um mapa, praticamente todos os objetivos de cada perspectiva estão ligados a todos os objetivos da perspectiva acima. Qual é a conduta correta?',
      [
        ['Submeter cada seta ao teste da explicação causal e remover as que não se sustentam.', 1,
         'Quando tudo se liga a tudo, o mapa deixa de informar: nenhuma cadeia específica é comunicada e a narrativa se torna ilegível. O critério de depuração é lógico, não estético — se o grupo não consegue explicar por que a melhoria do objetivo inferior contribui para o superior, a seta não deveria existir. Setas que sobrevivem ao teste são hipóteses que poderão ser confrontadas com o desempenho real.'],
        ['Elogiar o resultado, pois a densidade indica forte integração entre as áreas.', 0,
         'Densidade não equivale a integração. Excesso de setas costuma indicar que o grupo evitou escolher, e não que a estratégia esteja fortemente encadeada.'],
        ['Remover setas até que o mapa fique visualmente legível na apresentação.', 0,
         'Remoção por critério visual elimina conexões válidas junto com as inválidas, e substitui um problema de lógica por um problema de diagramação.'],
        ['Manter as conexões e orientar a leitura do mapa apenas por perspectiva.', 0,
         'As setas são o que distingue o mapa estratégico de uma lista de objetivos agrupados. Orientar que sejam ignoradas equivale a descartar o instrumento e manter apenas seu formato.'],
      ]];

    $Q[] = [2,
      'A diretoria prefere construir o mapa em reunião fechada e depois comunicá-lo às equipes para execução. Qual é a conduta correta?',
      [
        ['Concordar: a construção fechada é mais rápida e evita discussões dispersas.', 0,
         'A economia de tempo na construção é paga na execução. A barreira das pessoas — trabalho e metas desconectados da estratégia — é precisamente o que a participação previne.'],
        ['Concordar, desde que o mapa seja posteriormente aprovado em votação por todos.', 0,
         'Votação posterior não substitui participação. Aprovar um texto pronto não produz o entendimento nem o compromisso que decorrem de participar da construção.'],
        ['Recusar a condução do trabalho enquanto todos os colaboradores não forem incluídos.', 0,
         'O método não prevê a presença de todos os colaboradores no workshop, o que seria inviável. A exigência desproporcional bloqueia o trabalho sem corresponder à recomendação técnica.'],
        ['Incluir no workshop a liderança e representantes-chave das áreas envolvidas.', 1,
         'O método prevê a participação da liderança e de representantes-chave das áreas, e não por cortesia: a construção coletiva é o mecanismo que produz comprometimento com a execução. Há ainda um ganho técnico — quem opera cada área detém informação que a diretoria isolada não possui, o que melhora a qualidade dos objetivos e das hipóteses causais.'],
      ]];

    $Q[] = [2,
      'Você irá conduzir um workshop de mapa estratégico. Qual sequência de trabalho corresponde ao método?',
      [
        ['Definir indicadores e metas, deduzir os objetivos e agrupá-los por perspectiva.', 0,
         'Inverte a lógica do método. Indicadores existem para medir o avanço de um resultado já declarado; partir da métrica leva a empresa a perseguir o que é fácil medir em vez do que é estratégico.'],
        ['Definir temas, construir objetivos da Financeira até a base, ligar e priorizar.', 1,
         'Com a preparação pronta — missão, visão e diagnóstico disponíveis — definem-se de dois a quatro temas estratégicos e constroem-se os objetivos de cima para baixo, começando pela Financeira. Essa ordem responde primeiro "para quê?", permitindo que cada perspectiva inferior seja definida em função do que precisa sustentar. Depois vêm as conexões, o teste da narrativa em voz alta e, por fim, a priorização e a comunicação.'],
        ['Construir objetivos de Aprendizado até a Financeira, seguindo a leitura do mapa.', 0,
         'Confunde construção com leitura. O mapa é lido de baixo para cima, para verificar a cadeia causal, mas é construído de cima para baixo, para que cada nível seja definido em função do que precisa habilitar.'],
        ['Consolidar em um mapa único os objetivos enviados previamente por cada área.', 0,
         'Consolidar envios individuais produz uma soma de agendas de área, não uma estratégia. As conexões causais, principal valor do mapa, só podem ser construídas e validadas com os responsáveis reunidos.'],
      ]];

    $Q[] = [2,
      'O grupo encerra o workshop com 38 objetivos e resiste a reduzir a quantidade, alegando que todos são relevantes. Qual é a conduta correta?',
      [
        ['Priorizar com critérios explícitos e registrar os objetivos preteridos para ciclos futuros.', 1,
         'A resistência diminui quando o corte deixa de ser arbitrário e passa a seguir critérios declarados: contribuição aos temas estratégicos, existência de dono, viabilidade de medição e capacidade real de execução no período. Isso desloca a conversa do gosto pessoal para o método. Registrar os preteridos, em vez de descartá-los, reduz a sensação de perda e preserva o material levantado.'],
        ['Aceitar os 38, já que cortar objetivos relevantes empobreceria a estratégia da empresa.', 0,
         'Relevância não é critério suficiente — todo objetivo proposto é relevante para quem o propôs. Um mapa que a empresa não consegue executar nem acompanhar deixa de cumprir sua função, por melhor que seja cada item isoladamente.'],
        ['Reduzir a lista você mesmo e apresentar ao grupo o resultado já depurado na sessão seguinte.', 0,
         'Corte unilateral devolve ao grupo um mapa que ele não reconhece e transfere ao facilitador a responsabilidade por exclusões que são decisão da organização.'],
        ['Manter os 38 e atribuir indicadores apenas àqueles considerados prioritários pelo grupo.', 0,
         'Objetivo sem indicador não é acompanhado e, na prática, não existe para a gestão. Manter itens apenas para evitar o desconforto do corte cria a ilusão de abrangência.'],
      ]];

    // ===================== BLOCO 3 — INDICADORES & METAS =====================
    $Q[] = [3,
      'Uma área apresenta o indicador "Índice de satisfação do cliente", informando apenas o nome e a meta. O que deve ser exigido antes do cadastro?',
      [
        ['A meta, o prazo e o nome do responsável pela área que apresentou o indicador.', 0,
         'São dados de responsabilidade e prazo, úteis para governança, mas não permitem apurar o indicador. Nenhum deles informa como o número será calculado nem de onde virão os dados.'],
        ['O histórico de cinco anos, a amostra de clientes e o roteiro da pesquisa aplicada.', 0,
         'Amostra e roteiro são decisões metodológicas que decorrem da fórmula, e histórico longo é desejável mas não obrigatório — na sua falta, registra-se a baseline com a melhor medição disponível.'],
        ['Fórmula, unidade, fonte, responsável, frequência, baseline, sentido e objetivo associado.', 1,
         'Cada indicador deve ter ficha padronizada, e a ausência de qualquer campo compromete a gestão. Sem fórmula e unidade, duas pessoas apuram valores diferentes; sem fonte e responsável, o dado não chega; sem frequência, não há ritmo; sem baseline, não se avalia a ambição; sem sentido definido, não se sabe se a variação é boa ou ruim; sem objetivo associado, o indicador mede algo que não pertence à estratégia.'],
        ['A fórmula de cálculo e a meta, suficientes para apurar e comparar o resultado obtido.', 0,
         'Fórmula e meta permitem calcular um número, mas não garantem que ele chegue com regularidade nem que signifique algo. Sem fonte, responsável, frequência e baseline, o indicador não se sustenta após o primeiro ciclo.'],
      ]];

    $Q[] = [3,
      'Uma área reporta 99% em um indicador de entregas "no prazo e completas", mas entregas parciais são contabilizadas como atendidas, com o saldo remetido depois. Qual é a conduta correta?',
      [
        ['Aceitar o valor apurado, já que o cliente acabou recebendo todos os itens.', 0,
         'Aceitar valida uma medição divergente da própria definição do indicador. O impacto real da entrega parcial sobre o cliente permanece invisível para a gestão.'],
        ['Corrigir a fórmula e recalcular a baseline pelo critério revisado.', 1,
         'O defeito está na fórmula, não no indicador. Uma medida que se propõe a aferir entregas completas e no prazo, mas contabiliza entregas parciais como atendidas, produz um número que não corresponde ao que declara medir — e falsa sensação de controle é pior do que a ausência do indicador. Corrigida a fórmula, a baseline precisa ser recalculada pelo critério novo, sob pena de a meta ser comparada a um ponto de partida apurado por outra regra.'],
        ['Substituir por indicador mais simples, dada a complexidade da apuração.', 0,
         'Simplificar por causa de um erro de fórmula elimina uma medição estratégica em vez de corrigi-la. Dificuldade de apuração não justifica trocar o que importa medir pelo que é fácil medir.'],
        ['Manter a fórmula e reduzir a meta, compensando a inconsistência identificada.', 0,
         'Calibrar a meta sobre fórmula incorreta perpetua e institucionaliza o erro. A meta passa a ser definida a partir de um número que não representa a realidade da operação.'],
      ]];

    $Q[] = [3,
      'Em um scorecard, todos os indicadores medem resultados já consolidados: receita realizada, índice de retenção, índice de refugo. Qual é a conduta correta?',
      [
        ['Aprovar, pois indicadores de resultado são os que comprovam objetivamente o desempenho.', 0,
         'Indicadores de resultado são necessários, porém insuficientes. Comprovar o desempenho é função distinta de permitir que ele seja influenciado a tempo.'],
        ['Substituir todos por indicadores de tendência, que permitem ação durante o período.', 0,
         'Eliminar os indicadores de resultado remove a comprovação de que a estratégia funcionou. Os dois tipos são complementares: um orienta a ação, o outro valida a hipótese.'],
        ['Manter o conjunto atual e elevar a frequência de apuração de trimestral para semanal.', 0,
         'Aumentar a frequência antecipa a constatação do efeito, mas não converte efeito em causa. Apurar receita realizada semanalmente continua sem informar o que fazer para alterá-la.'],
        ['Acrescentar a cada objetivo ao menos um indicador de tendência.', 1,
         'Indicadores de resultado (lagging) confirmam o efeito depois de ocorrido; indicadores de tendência (leading) medem causas e permitem agir enquanto o resultado ainda se forma. Um scorecard só com indicadores de resultado transforma a gestão em constatação. A correção é aditiva: mantém-se o que já existe e acrescenta-se, por objetivo, ao menos uma medida de causa acompanhável no ciclo.'],
      ]];

    $Q[] = [3,
      'Um responsável apresenta a meta "aumentar as vendas em 20%" sem informar o valor atual do indicador. Qual é a conduta correta?',
      [
        ['Devolver para complementação com o levantamento do valor atual.', 1,
         'A baseline é o ponto de partida do indicador e a referência que dá sentido à meta. Sem ela não se sabe se 20% representa a manutenção de uma tendência já em curso ou uma ruptura de patamar, e o acompanhamento durante o ciclo fica sem parâmetro. A devolução deve vir com a orientação de como levantar o valor — a partir de dados históricos, na fonte definida na ficha do indicador.'],
        ['Estimar você mesmo a baseline para não travar o cronograma de cadastro.', 0,
         'Baseline estimada pelo facilitador introduz um número que ninguém sustenta e que será contestado no encerramento do ciclo. O valor de partida deve vir da fonte de dados, não de inferência.'],
        ['Aceitar, pois o percentual já define com clareza o esforço esperado da área.', 0,
         'Percentual sem referência não comunica esforço. Vinte por cento sobre base estagnada e sobre base em crescimento acelerado são metas radicalmente diferentes.'],
        ['Converter a meta percentual em valor absoluto, o que dispensa a baseline.', 0,
         'Meta em valor absoluto também exige baseline. Saber aonde se quer chegar sem saber de onde se parte impede igualmente avaliar o nível de ambição.'],
      ]];

    $Q[] = [3,
      'A diretoria fixa uma meta que a área responsável considera inatingível e comunica o número como decisão fechada. Qual é a conduta correta?',
      [
        ['Não intervir: a definição de metas é prerrogativa exclusiva da diretoria.', 0,
         'A decisão do valor é da organização, mas o especialista responde pelo método — e o método estabelece critérios de ambição e recomenda envolver quem vai perseguir a meta.'],
        ['Propor uma rodada de negociação sobre condições e iniciativas necessárias.', 1,
         'O critério técnico é a meta esticada porém crível: exige esforço real, mas o time acredita ser possível alcançá-la. Metas impossíveis levam à desistência ou à manipulação de números; metas fáceis não mobilizam. O papel do especialista não é arbitrar o valor, e sim trazer o critério e estruturar a conversa — qual patamar é sustentável, sob quais condições e com quais iniciativas.'],
        ['Reduzir a meta ao patamar que a área responsável considerar confortável.', 0,
         'Rebaixar ao nível de conforto da área elimina o caráter desafiador, que é parte da função da meta. O ponto adequado não é o mais confortável nem o mais agressivo.'],
        ['Registrar a meta e anotar a divergência em ata, sem propor tratativa.', 0,
         'Registrar a divergência sem tratá-la preserva a formalidade e ignora o problema. A meta seguirá no scorecard sem adesão de quem deve executá-la.'],
      ]];

    // ===================== BLOCO 4 — INICIATIVAS & GOVERNANCA =====================
    $Q[] = [4,
      'Ao discutir iniciativas, a área de marketing propõe acompanhar o número de curtidas nas publicações como justificativa de uma ação. Qual é a conduta correta?',
      [
        ['Aceitar, pois engajamento em redes sociais é indicador reconhecido na área.', 0,
         'Ser usual em uma área não torna a métrica estratégica. A pertinência depende da relação demonstrável com o objetivo, e não da popularidade do indicador.'],
        ['Recusar a proposta e determinar qual métrica será usada em substituição.', 0,
         'Determinar a métrica no lugar do responsável encerra a discussão sem produzir entendimento e tende a gerar resistência. O especialista conduz o raciocínio; a escolha permanece com quem responde pelo resultado.'],
        ['Aceitar a métrica, desde que ela não seja a única utilizada pela área.', 0,
         'Uma métrica sem vínculo com o resultado não se torna adequada por estar acompanhada de outras. Ela segue consumindo atenção e produzindo leitura equivocada de desempenho.'],
        ['Pedir a demonstração do vínculo entre a métrica e a conversão do funil.', 1,
         'Toda métrica proposta precisa demonstrar relação com o resultado que se quer produzir; sem isso, mede visibilidade e não desempenho. Pedir a demonstração é mais eficaz do que negar: se houver conversão mensurável, a métrica se justifica; se não houver, o próprio responsável conclui que ela não serve, sem que a decisão pareça imposta.'],
      ]];

    $Q[] = [4,
      'Uma iniciativa vem sendo executada conforme o planejado há dois períodos de apuração, mas o indicador do objetivo não avança. Qual é a conduta correta?',
      [
        ['Rever a meta para baixo, ajustando-a ao desempenho efetivamente observado.', 0,
         'Rebaixar a meta diante da primeira dificuldade transforma o compromisso de resultado em variável de ajuste e retira do ciclo sua função de tensionar a execução.'],
        ['Encerrar o objetivo, cuja hipótese estratégica não se confirmou na prática.', 0,
         'Resposta desproporcional. O que ainda não se comprovou é a eficácia daquela iniciativa específica, e não necessariamente a validade da hipótese causal do mapa — essa conclusão exige mais evidência.'],
        ['Substituir a iniciativa, mantendo a meta do ciclo inalterada.', 1,
         'Meta e iniciativa têm naturezas distintas. A meta é o compromisso de resultado e permanece estável durante o ciclo; a iniciativa é a aposta sobre como alcançá-la e deve mudar sempre que os dados mostrarem que não produz efeito. Insistir na mesma ação esperando resultado diferente é o desvio mais comum nessa etapa — a iniciativa, não a meta, é o elemento projetado para ser revisto.'],
        ['Aguardar mais dois períodos antes de qualquer decisão sobre o plano de ação.', 0,
         'Aguardar sem agir consome o ciclo. O propósito de acompanhar com frequência definida é permitir correção de rota enquanto ainda há tempo hábil.'],
      ]];

    $Q[] = [4,
      'Ao encerrar o levantamento das iniciativas de uma área, qual verificação deve obrigatoriamente ser conduzida antes de considerar o plano fechado?',
      [
        ['Se cada iniciativa envolve custo e se ele está previsto no orçamento aprovado.', 1,
         'A desconexão entre orçamento e estratégia é uma das quatro barreiras clássicas da execução e se manifesta exatamente aqui: a iniciativa é planejada, o ciclo começa e, na hora de executar, descobre-se que o recurso não foi previsto. A verificação correta é perguntar, iniciativa por iniciativa, se há custo, quantificar e encaminhar para aprovação — e substituir a iniciativa antes do início do ciclo caso o recurso seja negado.'],
        ['Se as iniciativas foram registradas no sistema com data de início e de fim.', 0,
         'Registro com datas é controle de execução necessário, porém insuficiente. Uma iniciativa datada e sem recurso permanece inexequível.'],
        ['Se o número de iniciativas por objetivo é equivalente entre as áreas da empresa.', 0,
         'Não há recomendação de equivalência numérica entre áreas. A quantidade decorre da lacuna entre baseline e meta de cada objetivo, e áreas diferentes legitimamente terão quantidades diferentes.'],
        ['Se todas as iniciativas poderão ser concluídas dentro do mês corrente.', 0,
         'Iniciativas estratégicas têm durações variadas, em geral superiores a um mês. O critério relevante é a compatibilidade entre o prazo da iniciativa e o prazo da meta que ela sustenta.'],
      ]];

    $Q[] = [4,
      'Ao cascatear "reduzir o custo por pedido em 10%", as áreas de compras, logística e atendimento apresentam todas o mesmo indicador de custo por pedido. Qual é a conduta correta?',
      [
        ['Aprovar, pois o indicador comum garante alinhamento entre as três áreas envolvidas.', 0,
         'Indicador idêntico não produz alinhamento e sim diluição de responsabilidade. Quando o resultado não vem, não há como identificar em que ponto da cadeia a falha ocorreu.'],
        ['Atribuir o objetivo apenas a compras, área de maior influência sobre o custo unitário.', 0,
         'Concentrar em uma área ignora que o custo por pedido se forma ao longo de várias etapas. Compras influencia o preço de aquisição, mas não a quilometragem rodada nem o retrabalho por erro.'],
        ['Definir para cada área um indicador sobre o que ela de fato controla.', 1,
         'O cascateamento traduz o scorecard corporativo em scorecards de área, e cada nível deve medir aquilo que efetivamente controla — por exemplo, renegociação de contratos em compras, quilometragem por entrega em logística e retrabalho por erro em atendimento. Indicadores próprios tornam visível a contribuição específica de cada área e preservam a linha de visada até o objetivo corporativo.'],
        ['Manter o indicador único e dividir a meta em partes iguais entre as três áreas.', 0,
         'Divisão em partes iguais pressupõe potencial de contribuição idêntico entre as áreas, o que raramente se verifica. A distribuição deve refletir a alavanca real de cada uma, apurada com indicadores próprios.'],
      ]];

    // ---- Insere questoes + alternativas ----
    $stQ = $pdo->prepare("INSERT INTO okrm_questoes (id_versao,id_bloco,ordem,enunciado) VALUES (?,?,?,?)");
    $stA = $pdo->prepare("INSERT INTO okrm_alternativas (id_questao,ordem,texto,is_correta,justificativa) VALUES (?,?,?,?,?)");
    $ordem = 0;
    foreach ($Q as $q) {
        $ordem++;
        $stQ->execute([$idVersao, $idBloco[$q[0]], $ordem, $q[1]]);
        $idQ = (int)$pdo->lastInsertId();
        $oa = 0;
        foreach ($q[2] as $alt) {
            $oa++;
            $stA->execute([$idQ, $oa, $alt[0], (int)$alt[1], $alt[2]]);
        }
    }

    $pdo->commit();

    // Validacao rapida
    $nQ = (int)$pdo->query("SELECT COUNT(*) FROM okrm_questoes WHERE id_versao=$idVersao")->fetchColumn();
    $nA = (int)$pdo->query("SELECT COUNT(*) FROM okrm_alternativas a JOIN okrm_questoes q ON q.id_questao=a.id_questao WHERE q.id_versao=$idVersao")->fetchColumn();
    $nC = (int)$pdo->query("SELECT COUNT(*) FROM okrm_alternativas a JOIN okrm_questoes q ON q.id_questao=a.id_questao WHERE q.id_versao=$idVersao AND a.is_correta=1")->fetchColumn();

    echo "OK — Modulo 1 semeado.\n";
    echo "id_modulo={$idModulo} id_versao={$idVersao}\n";
    echo "questoes={$nQ} alternativas={$nA} corretas={$nC}\n";
    echo ($nQ===20 && $nA===80 && $nC===20) ? "VALIDACAO: OK (20/80/20)\n" : "VALIDACAO: CONFERIR CONTAGENS\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "ERRO: " . $e->getMessage() . "\n";
}
