<?php
declare(strict_types=1);

// =============================================================
// Whitelist server-side do "Briefing CRM".
// Fonte ÚNICA da verdade: blocos, perguntas, tipos, obrigatoriedade,
// opções e regras de validação.
//
// O front NUNCA é confiável: toda question_key / block_key /
// answer_type e o shape de cada valor são validados aqui.
// =============================================================

require_once __DIR__ . '/security.php'; // bc_clean_str

const BC_FORM_SLUG    = 'briefing-crm-kauana';
const BC_FORM_VERSION = '1.0';
const BC_TEXT_MIN     = 4;
const BC_TEXT_MAX     = 4000;
const BC_SHORT_MAX    = 200;

/**
 * Ordem dos blocos. O primeiro é o current_block devolvido por start.php.
 */
function bc_block_order(): array
{
    return ['b1_escritorio', 'b2_operacao', 'b3_numeros', 'b4_dores', 'b5_prioridades'];
}

function bc_block_meta(): array
{
    return [
        'b1_escritorio' => [
            'title' => 'O escritório hoje',
            'intro' => 'Quatro respostas rápidas para eu entender o terreno.',
        ],
        'b2_operacao' => [
            'title' => 'Como o trabalho acontece',
            'intro' => 'Aqui é onde eu mais aprendo — é o retrato do dia a dia de vocês.',
        ],
        'b3_numeros' => [
            'title' => 'Números',
            'intro' => 'Estimativa serve, e "não sei" é uma resposta legítima e útil. Este bloco é inteiro opcional.',
        ],
        'b4_dores' => [
            'title' => 'O que mais pesa',
            'intro' => 'O que dói costuma definir o que a primeira versão precisa resolver.',
        ],
        'b5_prioridades' => [
            'title' => 'Prioridades',
            'intro' => 'Último bloco. É aqui que você define o que entra primeiro.',
        ],
    ];
}

/**
 * Catálogo completo de perguntas, indexado por question_key.
 * Chaves: block, question_text, answer_type, required, help, options,
 *         multi_min, multi_max.
 */
function bc_questions(): array
{
    static $q = null;
    if ($q !== null) {
        return $q;
    }

    $q = [

        /* ---------------- B1 — O escritório hoje ---------------- */

        'q_plataforma' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'O escritório trabalha vinculado a qual plataforma?',
            'help'          => 'Isso define se o sistema pode puxar posição e patrimônio automaticamente ou se alguém vai digitar à mão. É a resposta que mais muda o projeto.',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['XP', 'BTG', 'Órama', 'Ágora', 'Guide', 'Warren', 'Trabalhamos com mais de uma', 'Outra'],
        ],
        'q_plataforma_obs' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'O que a plataforma já entrega de relatório hoje?',
            'help'          => 'Posição do cliente, extrato de movimentação, receita por assessor… O que vocês conseguem exportar já pronto.',
            'answer_type'   => 'open',
            'required'      => false,
        ],
        'q_assessores' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'Quantas pessoas atendem ou prospectam clientes hoje?',
            'help'          => 'Contando sócios que também atendem.',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['Só eu', '2 a 3', '4 a 6', '7 a 10', '11 a 20', 'Mais de 20'],
        ],
        'q_assessores_meta' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'E quantas vocês querem ter em 12 meses?',
            'help'          => 'Se o time vai crescer, o sistema já nasce com hierarquia e metas por pessoa.',
            'answer_type'   => 'short',
            'required'      => false,
        ],
        'q_produtos' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'Quais produtos mais pesam na receita hoje?',
            'help'          => 'Marque quantos quiser.',
            'answer_type'   => 'multi',
            'required'      => true,
            'multi_min'     => 1,
            'options'       => ['Investimentos', 'Consórcio', 'Seguros', 'Investimento internacional', 'Previdência', 'Câmbio', 'Crédito', 'Outros'],
        ],
        'q_remuneracao' => [
            'block'         => 'b1_escritorio',
            'question_text' => 'Como o time é remunerado?',
            'help'          => 'Define o que cada pessoa vai querer ver no sistema — e o que teria incentivo para não registrar.',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['Comissão pura', 'Fixo + variável', 'Split com o escritório', 'Varia por pessoa', 'Prefiro falar disso pessoalmente'],
        ],

        /* ---------------- B2 — Como o trabalho acontece ---------------- */

        'q_onde_vive' => [
            'block'         => 'b2_operacao',
            'question_text' => 'Hoje, onde fica registrada a informação de cliente e de negociação?',
            'help'          => 'Sem filtro — o retrato real ajuda muito mais que o ideal.',
            'answer_type'   => 'multi',
            'required'      => true,
            'multi_min'     => 1,
            'options'       => ['Planilha', 'WhatsApp', 'Caderno ou agenda de papel', 'Sistema da plataforma', 'Um CRM que já usamos', 'Na memória de cada um'],
        ],
        'q_planilha' => [
            'block'         => 'b2_operacao',
            'question_text' => 'Se existe planilha ou sistema em uso, consigo dar uma olhada?',
            'help'          => 'O que já está em uso é a melhor especificação que existe: mostra o que as pessoas de fato preenchem.',
            'answer_type'   => 'single',
            'required'      => false,
            'options'       => ['Sim, posso te enviar', 'Sim, mas preciso tirar dados de cliente antes', 'Prefiro te mostrar em uma call', 'Não existe nada estruturado'],
        ],
        'q_lead_chega' => [
            'block'         => 'b2_operacao',
            'question_text' => 'Como um cliente novo chega até vocês, e quem decide quem vai atender?',
            'help'          => 'Existe regra, escala, ou é por disponibilidade? Dois assessores no mesmo cliente já aconteceu?',
            'answer_type'   => 'open',
            'required'      => true,
        ],
        'q_origem' => [
            'block'         => 'b2_operacao',
            'question_text' => 'De onde vêm os clientes novos hoje?',
            'answer_type'   => 'multi',
            'required'      => true,
            'multi_min'     => 1,
            'options'       => ['Indicação de cliente', 'Base ou leads da plataforma', 'Rede pessoal dos sócios', 'Evento ou palestra', 'LinkedIn e redes sociais', 'Tráfego pago', 'Prospecção fria', 'Não sabemos dizer'],
        ],
        'q_acompanhamento' => [
            'block'         => 'b2_operacao',
            'question_text' => 'Como funciona hoje o acompanhamento de quem já é cliente?',
            'help'          => 'Existe rotina de contato, ou acontece quando dá? Como vocês lembram de voltar em alguém?',
            'answer_type'   => 'open',
            'required'      => true,
        ],
        'q_trava' => [
            'block'         => 'b2_operacao',
            'question_text' => 'O que mais trava no dia a dia?',
            'help'          => 'O que faz você perder tempo, ou perder negócio.',
            'answer_type'   => 'open',
            'required'      => true,
        ],

        /* ---------------- B3 — Números (bloco opcional) ---------------- */

        'q_ticket' => [
            'block'         => 'b3_numeros',
            'question_text' => 'Captação média de um cliente novo',
            'answer_type'   => 'single',
            'required'      => false,
            'options'       => ['Até R$ 50 mil', 'R$ 50 a 250 mil', 'R$ 250 mil a 1 milhão', 'R$ 1 a 5 milhões', 'Acima de R$ 5 milhões', 'Varia muito', 'Não sei dizer'],
        ],
        'q_ciclo' => [
            'block'         => 'b3_numeros',
            'question_text' => 'Do primeiro contato até o primeiro aporte, quanto tempo costuma levar?',
            'answer_type'   => 'single',
            'required'      => false,
            'options'       => ['Até 15 dias', '15 a 30 dias', '1 a 3 meses', '3 a 6 meses', 'Mais de 6 meses', 'Não sei dizer'],
        ],
        'q_conversao' => [
            'block'         => 'b3_numeros',
            'question_text' => 'De cada 10 pessoas que chegam a uma reunião, quantas viram cliente com dinheiro aportado?',
            'help'          => 'Note que "abriu conta" e "aportou" são coisas diferentes — e a diferença entre as duas costuma ser o vazamento mais caro.',
            'answer_type'   => 'single',
            'required'      => false,
            'options'       => ['1 a 2', '3 a 4', '5 a 6', '7 ou mais', 'Não medimos isso'],
        ],
        'q_share' => [
            'block'         => 'b3_numeros',
            'question_text' => 'Do patrimônio total de um cliente típico, quanto está com vocês?',
            'help'          => 'Se ninguém souber estimar, isso por si só já é um achado importante.',
            'answer_type'   => 'single',
            'required'      => false,
            'options'       => ['Quase tudo', 'Mais da metade', 'Cerca da metade', 'Menos da metade', 'Uma parte pequena', 'Não temos como saber'],
        ],
        'q_numeros_obs' => [
            'block'         => 'b3_numeros',
            'question_text' => 'Quer comentar algum desses números?',
            'answer_type'   => 'open',
            'required'      => false,
        ],

        /* ---------------- B4 — O que mais pesa ---------------- */

        'q_dor_agora' => [
            'block'         => 'b4_dores',
            'question_text' => 'O que fez vocês decidirem procurar um CRM agora?',
            'help'          => 'Aconteceu algo específico? Essa resposta costuma reordenar todo o projeto.',
            'answer_type'   => 'open',
            'required'      => true,
        ],
        'q_assessor_sai' => [
            'block'         => 'b4_dores',
            'question_text' => 'Quando alguém do time sai, o que fica com o escritório?',
            'help'          => 'Carteira, histórico de conversa, contatos.',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['Tudo fica documentado', 'Parte fica, parte se perde', 'Quase nada — sai com a pessoa', 'Nunca aconteceu ainda', 'Não sei dizer'],
        ],
        'q_perdas' => [
            'block'         => 'b4_dores',
            'question_text' => 'Lembra de algum cliente ou negócio perdido por falta de acompanhamento?',
            'help'          => 'Um caso concreto vale mais que uma lista de requisitos.',
            'answer_type'   => 'open',
            'required'      => false,
        ],
        'q_compliance' => [
            'block'         => 'b4_dores',
            'question_text' => 'Existe exigência de registro, suitability ou retenção que a plataforma ou a regulação impõem a vocês?',
            'help'          => 'E onde isso fica guardado hoje. Compliance embutido no desenho custa pouco; adicionado depois custa uma refatoração.',
            'answer_type'   => 'open',
            'required'      => false,
        ],

        /* ---------------- B5 — Prioridades ---------------- */

        'q_prioridades' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Das possibilidades que descrevi acima, quais você quer que existam?',
            'help'          => 'Marque tudo que faz sentido — a ordem vem na próxima pergunta.',
            'answer_type'   => 'multi',
            'required'      => true,
            'multi_min'     => 1,
            'options'       => [
                'Ver quanto vale cada negociação em aberto',
                'Saber quanto do patrimônio do cliente está fora da casa',
                'Lembretes automáticos de vencimento, renovação e contemplação',
                'Alerta de cliente sem contato há muito tempo',
                'Origem de cada cliente novo',
                'Funil por etapas com taxa de conversão',
                'Histórico que fica com o escritório',
                'Metas por assessor e ranking',
                'Registro rápido pelo celular',
                'Registro a partir do WhatsApp',
            ],
        ],
        'q_top3' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Se só três pudessem existir na primeira entrega, quais seriam — e por quê?',
            'help'          => 'Essa é a pergunta mais importante do briefing.',
            'answer_type'   => 'open',
            'required'      => true,
        ],
        'q_quem_usa' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Quem vai usar o sistema todo dia, e quem só vai olhar relatório?',
            'help'          => 'Quem usa e quem consulta precisam de telas diferentes.',
            'answer_type'   => 'open',
            'required'      => true,
        ],
        'q_prazo' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Que horizonte vocês têm em mente?',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['Já era para estar rodando', 'Próximos 3 meses', '3 a 6 meses', 'Ainda estamos estudando'],
        ],
        'q_socios' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Como está o alinhamento com os sócios?',
            'help'          => 'Sem julgamento — só para eu saber com quem estou falando e o que ainda precisa ser construído junto.',
            'answer_type'   => 'single',
            'required'      => true,
            'options'       => ['Já conversamos e estamos alinhados', 'Vou levar essa conversa agora', 'Por enquanto é só uma ideia minha'],
        ],
        'q_livre' => [
            'block'         => 'b5_prioridades',
            'question_text' => 'Alguma coisa que eu não perguntei e você acha importante?',
            'answer_type'   => 'open',
            'required'      => false,
        ],
    ];

    return $q;
}

/* ------------------------------------------------------------------ */
/* Índices derivados                                                  */
/* ------------------------------------------------------------------ */

/**
 * question_keys de um bloco, na ordem em que aparecem no catálogo.
 */
function bc_block_question_keys(string $blockKey): array
{
    $keys = [];
    foreach (bc_questions() as $qkey => $q) {
        if ($q['block'] === $blockKey) {
            $keys[] = $qkey;
        }
    }
    return $keys;
}

function bc_next_block(string $blockKey): ?string
{
    $order = bc_block_order();
    $i = array_search($blockKey, $order, true);
    if ($i === false || $i === count($order) - 1) {
        return null;
    }
    return $order[$i + 1];
}

function bc_is_last_block(string $blockKey): bool
{
    return bc_next_block($blockKey) === null;
}

/* ------------------------------------------------------------------ */
/* Validação de resposta                                              */
/* ------------------------------------------------------------------ */

/**
 * Valida e normaliza UMA resposta contra a definição da pergunta.
 *
 * Retorno:
 *   ['ok' => true,  'store' => ['answer_text','answer_number','answer_json']]
 *   ['ok' => false, 'error' => 'mensagem para o respondente']
 *
 * Pergunta opcional com valor vazio é válida e grava NULL — mantém a
 * linha na tabela para registrar que foi vista e deixada em branco.
 */
function bc_validate_answer(array $q, mixed $value): array
{
    $type     = $q['answer_type'];
    $required = (bool) ($q['required'] ?? false);
    $empty    = ['answer_text' => null, 'answer_number' => null, 'answer_json' => null];

    switch ($type) {

        case 'open':
        case 'short':
            $max = $type === 'short' ? BC_SHORT_MAX : BC_TEXT_MAX;
            $v = is_string($value) ? bc_clean_str($value, $max) : '';
            if ($v === '') {
                if ($required) {
                    return ['ok' => false, 'error' => 'Essa não pode ficar em branco.'];
                }
                return ['ok' => true, 'store' => $empty];
            }
            if ($required && mb_strlen($v) < BC_TEXT_MIN) {
                return ['ok' => false, 'error' => 'Escreve um pouco mais, por favor.'];
            }
            return ['ok' => true, 'store' => ['answer_text' => $v, 'answer_number' => null, 'answer_json' => null]];

        case 'single':
            $v = is_string($value) ? bc_clean_str($value, 255) : '';
            if ($v === '') {
                if ($required) {
                    return ['ok' => false, 'error' => 'Escolhe uma opção.'];
                }
                return ['ok' => true, 'store' => $empty];
            }
            if (!in_array($v, $q['options'] ?? [], true)) {
                return ['ok' => false, 'error' => 'Opção inválida.'];
            }
            return ['ok' => true, 'store' => ['answer_text' => $v, 'answer_number' => null, 'answer_json' => null]];

        case 'multi':
            if (!is_array($value)) {
                $value = [];
            }
            $allowed = $q['options'] ?? [];
            $picked  = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $item = bc_clean_str($item, 255);
                // Descarta silenciosamente o que não está na whitelist.
                if (in_array($item, $allowed, true) && !in_array($item, $picked, true)) {
                    $picked[] = $item;
                }
            }
            $min = (int) ($q['multi_min'] ?? 0);
            if ($picked === []) {
                if ($required || $min > 0) {
                    return ['ok' => false, 'error' => 'Marca pelo menos uma opção.'];
                }
                return ['ok' => true, 'store' => $empty];
            }
            if (count($picked) < $min) {
                return ['ok' => false, 'error' => sprintf('Marca pelo menos %d.', $min)];
            }
            $max = (int) ($q['multi_max'] ?? 0);
            if ($max > 0 && count($picked) > $max) {
                return ['ok' => false, 'error' => sprintf('Marca no máximo %d.', $max)];
            }
            return ['ok' => true, 'store' => [
                'answer_text'   => implode(' · ', $picked),
                'answer_number' => null,
                'answer_json'   => json_encode($picked, JSON_UNESCAPED_UNICODE),
            ]];

        case 'scale':
            if ($value === '' || $value === null) {
                if ($required) {
                    return ['ok' => false, 'error' => 'Escolhe um valor.'];
                }
                return ['ok' => true, 'store' => $empty];
            }
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'Valor inválido.'];
            }
            $n = (int) $value;
            if ($n < 1 || $n > 5) {
                return ['ok' => false, 'error' => 'Valor fora da escala.'];
            }
            return ['ok' => true, 'store' => ['answer_text' => (string) $n, 'answer_number' => $n, 'answer_json' => null]];
    }

    return ['ok' => false, 'error' => 'Tipo de resposta desconhecido.'];
}
