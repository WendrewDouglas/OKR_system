<?php
declare(strict_types=1);

// =============================================================
// Whitelist server-side do formulário "Perspectivas de Gestão" (FMX).
// Fonte ÚNICA da verdade: blocos, perguntas, tipos, obrigatoriedade,
// analysis_tags e regras de validação/normalização de cada resposta.
//
// O front NUNCA é confiável: toda question_key / block_key / answer_type
// e o shape do JSON são validados aqui.
// =============================================================

require_once __DIR__ . '/security.php'; // pg_clean_str

const PG_FORM_SLUG    = 'perspectivas-gestao';
const PG_FORM_VERSION = '1.0';
const PG_TEXT_MIN     = 5;   // mínimo p/ respostas abertas longas (shape 'open')
const PG_SUBTEXT_MIN  = 2;   // mínimo p/ subcampos estruturados (nome de animal, frente, etc.)
const PG_TEXT_MAX     = 4000;

/**
 * Ordem dos blocos estratégicos (após a identificação).
 * O primeiro bloco é o retornado por start.php como current_block.
 */
function pg_block_order(): array
{
    return ['alinhamento', 'mercado', 'ia_modelo', 'portfolio', 'clientes', 'gestao_okr', 'futuro'];
}

function pg_block_titles(): array
{
    return [
        'alinhamento' => 'Visão geral e alinhamento estratégico',
        'mercado'     => 'Mercado e competitividade',
        'ia_modelo'   => 'IA, modelo de negócio e escala',
        'portfolio'   => 'Portfólio e unidades de negócio',
        'clientes'    => 'Clientes, receita e crescimento',
        'gestao_okr'  => 'Gestão, cultura, OKRs e execução',
        'futuro'      => 'Futuro e identidade da FMX',
    ];
}

/**
 * Catálogo de animais (pergunta 20 — identidade da FMX).
 * name => [slug (arquivo de imagem), carac (característica exibida no balão)].
 * O `name` é o valor gravado (whitelist do enum). Ordem = ordem de exibição.
 */
function pg_animals(): array
{
    return [
        'Castor'    => ['slug' => 'castor',    'emoji' => '🦫', 'carac' => 'constrói com método, processo e organização'],
        'Águia'     => ['slug' => 'aguia',     'emoji' => '🦅', 'carac' => 'enxerga de cima, escolhe o alvo e busca visão estratégica'],
        'Camaleão'  => ['slug' => 'camaleao',  'emoji' => '🦎', 'carac' => 'adapta-se rapidamente às mudanças do ambiente'],
        'Polvo'     => ['slug' => 'polvo',     'emoji' => '🐙', 'carac' => 'conecta muitas frentes ao mesmo tempo, com inteligência distribuída'],
        'Formiga'   => ['slug' => 'formiga',   'emoji' => '🐜', 'carac' => 'trabalha muito, em equipe, com disciplina e constância'],
        'Lobo'      => ['slug' => 'lobo',      'emoji' => '🐺', 'carac' => 'atua em matilha, com liderança, estratégia e senso de território'],
        'Golfinho'  => ['slug' => 'golfinho',  'emoji' => '🐬', 'carac' => 'cria relações fortes, colabora e entrega inteligência com proximidade'],
        'Elefante'  => ['slug' => 'elefante',  'emoji' => '🐘', 'carac' => 'é sólido, confiável, experiente e carrega memória construída'],
        'Falcão'    => ['slug' => 'falcao',    'emoji' => '🐦', 'carac' => 'mira oportunidades com velocidade, foco e precisão'],
        'Tartaruga' => ['slug' => 'tartaruga', 'emoji' => '🐢', 'carac' => 'avança com cautela, consistência e resistência'],
        'Leão'      => ['slug' => 'leao',      'emoji' => '🦁', 'carac' => 'busca protagonismo, autoridade e reconhecimento no mercado'],
        'Abelha'    => ['slug' => 'abelha',    'emoji' => '🐝', 'carac' => 'produz valor coletivo com organização, especialização e colaboração'],
    ];
}

/**
 * Definição completa das 20 perguntas obrigatórias.
 *
 * Cada item:
 *   block_key     — bloco a que pertence
 *   question_text — enunciado (também gravado em pg_form_answers.question_text)
 *   answer_type   — open | scale | single | multi | matrix | json
 *   required      — sempre true neste formulário
 *   analysis_tags — rótulos para análises futuras (Porter/SWOT/etc.)
 *   spec          — regra de validação/normalização (ver pg_validate_answer)
 */
function pg_questions(): array
{
    return [

        /* ---------- Bloco 1 — Visão geral e alinhamento ---------- */
        'alinhamento.momento_atual' => [
            'block_key'     => 'alinhamento',
            'question_text' => 'Em uma frase, como você definiria o momento atual da FMX?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['sinergia_marcela', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'open'],
        ],
        'alinhamento.clareza_rumo' => [
            'block_key'     => 'alinhamento',
            'question_text' => 'De 0 a 10, quão claro está para você o rumo estratégico da FMX para os próximos anos? Justifique sua nota.',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['sinergia_marcela', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'nota'          => ['type' => 'scale'],
                'justificativa' => ['type' => 'text'],
            ]],
        ],
        'alinhamento.prioridades_estrategicas' => [
            'block_key'     => 'alinhamento',
            'question_text' => 'Na sua visão, quais são hoje as 3 prioridades estratégicas mais importantes da FMX?',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['sinergia_marcela', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'prioridade_1' => ['type' => 'text'],
                'prioridade_2' => ['type' => 'text'],
                'prioridade_3' => ['type' => 'text'],
            ]],
        ],
        'alinhamento.principal_obstaculo' => [
            'block_key'     => 'alinhamento',
            'question_text' => 'Qual é hoje o principal obstáculo que impede a FMX de crescer ou mudar de patamar?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['sinergia_marcela', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'open'],
        ],

        /* ---------- Bloco 2 — Mercado e competitividade ---------- */
        'mercado.motivo_perda_oportunidade' => [
            'block_key'     => 'mercado',
            'question_text' => 'Quando a FMX perde uma oportunidade ou deixa de avançar com um cliente, qual costuma ser o principal motivo?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['porter', 'swot', 'mercado', 'posicionamento'],
            'spec'          => ['shape' => 'open'],
        ],
        'mercado.comparacao_clientes' => [
            'block_key'     => 'mercado',
            'question_text' => 'Com quais tipos de empresas, soluções ou alternativas a FMX costuma ser comparada pelos clientes?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['porter', 'swot', 'mercado', 'posicionamento'],
            'spec'          => ['shape' => 'open'],
        ],
        'mercado.porter.intensidade_forcas' => [
            'block_key'     => 'mercado',
            'question_text' => 'Avalie de 0 a 10 os riscos abaixo para a FMX nos próximos 12 a 24 meses.',
            'answer_type'   => 'matrix',
            'required'      => true,
            'analysis_tags' => ['porter', 'swot', 'mercado', 'posicionamento'],
            'spec'          => ['shape' => 'matrix_flat', 'keys' => [
                'rivalidade_concorrentes',
                'novos_entrantes',
                'substitutos',
                'poder_clientes',
                'poder_fornecedores_recursos',
            ], 'labels' => [
                'rivalidade_concorrentes'     => 'Concorrentes atuais ficarem mais fortes',
                'novos_entrantes'             => 'Novos concorrentes entrarem no mercado',
                'substitutos'                 => 'Clientes substituírem a FMX por IA, SaaS, no-code ou equipe interna',
                'poder_clientes'              => 'Clientes pressionarem preço, prazo ou escopo',
                'poder_fornecedores_recursos' => 'Falta de talentos, parceiros, caixa ou recursos críticos limitar o crescimento',
            ]],
        ],
        'mercado.protecao_competitiva' => [
            'block_key'     => 'mercado',
            'question_text' => 'O que hoje realmente protege a FMX contra concorrentes, substitutos ou empresas mais baratas?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['porter', 'swot', 'mercado', 'posicionamento'],
            'spec'          => ['shape' => 'open'],
        ],
        'mercado.valor_entregue_comunicado_mal' => [
            'block_key'     => 'mercado',
            'question_text' => 'O que a FMX entrega muito bem, mas ainda comunica mal ao mercado?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['porter', 'swot', 'mercado', 'posicionamento'],
            'spec'          => ['shape' => 'open'],
        ],

        /* ---------- Bloco 3 — IA, modelo de negócio e escala ---------- */
        'ia.impacto_modelo_negocio' => [
            'block_key'     => 'ia_modelo',
            'question_text' => 'Como a IA pode mudar a forma como a FMX vende, precifica, entrega e gera valor para clientes?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['ia', 'modelo_negocio', 'swot', 'sinergia_marcela'],
            'spec'          => ['shape' => 'open'],
        ],
        'ia.preparo_area' => [
            'block_key'     => 'ia_modelo',
            'question_text' => 'De 0 a 10, quão preparada sua área está para usar IA de forma estratégica? O que falta para evoluir?',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['ia', 'modelo_negocio', 'swot', 'sinergia_marcela'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'nota'        => ['type' => 'scale'],
                'o_que_falta' => ['type' => 'text'],
            ]],
        ],
        'modelo_negocio.limitacao_escala' => [
            'block_key'     => 'ia_modelo',
            'question_text' => 'O que mais limita a FMX hoje para escalar sem aumentar estrutura na mesma proporção?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['ia', 'modelo_negocio', 'swot', 'sinergia_marcela'],
            'spec'          => ['shape' => 'open'],
        ],

        /* ---------- Bloco 4 — Portfólio e unidades de negócio ---------- */
        'portfolio.avaliacao_unidades' => [
            'block_key'     => 'portfolio',
            'question_text' => 'Avalie cada unidade de negócio da FMX de 0 a 10 nos critérios abaixo.',
            'answer_type'   => 'matrix',
            'required'      => true,
            'analysis_tags' => ['portfolio', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'matrix_nested',
                'rows' => ['smart', 'pro', 'way', 'academy', 'cx'],
                'cols' => ['potencial_futuro', 'capacidade_execucao', 'clareza_proposta_valor', 'potencial_escala'],
                'row_labels' => [
                    'smart'   => 'FMX Smart',
                    'pro'     => 'FMX Pro',
                    'way'     => 'FMX Way',
                    'academy' => 'FMX Academy',
                    'cx'      => 'FMX CX',
                ],
                'col_labels' => [
                    'potencial_futuro'       => 'Potencial futuro',
                    'capacidade_execucao'    => 'Capacidade atual de execução',
                    'clareza_proposta_valor' => 'Clareza da proposta de valor',
                    'potencial_escala'       => 'Potencial de escala',
                ],
            ],
        ],
        'portfolio.tres_apostas' => [
            'block_key'     => 'portfolio',
            'question_text' => 'Se a FMX tivesse que apostar em apenas 3 frentes para crescer nos próximos anos, quais seriam e por quê?',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['portfolio', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'groups',
                'groups'       => ['aposta_1', 'aposta_2', 'aposta_3'],
                'group_fields' => ['frente' => ['type' => 'text'], 'porque' => ['type' => 'text']],
            ],
        ],
        'portfolio.repensar_reposicionar' => [
            'block_key'     => 'portfolio',
            'question_text' => 'Que solução, serviço ou unidade da FMX deveria ser simplificada, reposicionada, reduzida ou repensada?',
            'answer_type'   => 'open',
            'required'      => true,
            'analysis_tags' => ['portfolio', 'swot', 'estrategia'],
            'spec'          => ['shape' => 'open'],
        ],

        /* ---------- Bloco 5 — Clientes, receita e crescimento ---------- */
        'clientes.icp_dependencia' => [
            'block_key'     => 'clientes',
            'question_text' => 'Quem é o cliente ideal da FMX hoje e que tipo de cliente a empresa deveria evitar ou reduzir dependência?',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['clientes', 'crescimento', 'porter', 'swot'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'cliente_ideal'                          => ['type' => 'text'],
                'cliente_evitar_ou_reduzir_dependencia'  => ['type' => 'text'],
            ]],
        ],
        'clientes.previsibilidade_crescimento' => [
            'block_key'     => 'clientes',
            'question_text' => 'De 0 a 10, quão previsível é hoje o crescimento comercial da FMX? O que precisaria mudar para aumentar essa previsibilidade?',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['clientes', 'crescimento', 'porter', 'swot'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'nota'                => ['type' => 'scale'],
                'o_que_precisa_mudar' => ['type' => 'text'],
            ]],
        ],

        /* ---------- Bloco 6 — Gestão, cultura, OKRs e execução ---------- */
        'gestao.maturidade_crescimento' => [
            'block_key'     => 'gestao_okr',
            'question_text' => 'De 0 a 10, quão madura está a gestão da FMX para sustentar o próximo ciclo de crescimento? Justifique.',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['gestao', 'cultura', 'okr', 'sinergia_marcela'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'nota'          => ['type' => 'scale'],
                'justificativa' => ['type' => 'text'],
            ]],
        ],
        'okr.qualidade_medicao' => [
            'block_key'     => 'gestao_okr',
            'question_text' => 'Na sua visão, os OKRs atuais da FMX medem mais resultado de negócio, eficiência interna ou ações/tarefas? Explique.',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['gestao', 'cultura', 'okr', 'sinergia_marcela'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'opcao'      => ['type' => 'enum', 'options' => [
                    'Resultado de negócio',
                    'Eficiência interna',
                    'Ações/tarefas',
                    'Mistura dos três',
                    'Não sei avaliar',
                ]],
                'explicacao' => ['type' => 'text'],
            ]],
        ],

        /* ---------- Bloco 7 — Futuro, capa de revista e animal ---------- */
        'futuro.capa_animal' => [
            'block_key'     => 'futuro',
            'question_text' => 'Imagine a FMX em dezembro de 2027. Responda aos itens abaixo.',
            'answer_type'   => 'json',
            'required'      => true,
            'analysis_tags' => ['futuro', 'capa_revista', 'animal', 'cultura', 'sinergia_marcela'],
            'spec'          => ['shape' => 'fields', 'fields' => [
                'manchete_capa'           => ['type' => 'text'],
                'conquista_justificativa' => ['type' => 'text'],
                'animal_atual'            => ['type' => 'enum', 'render' => 'animal', 'options' => array_keys(pg_animals())],
                'animal_atual_porque'     => ['type' => 'text'],
                'animal_futuro'           => ['type' => 'enum', 'render' => 'animal', 'options' => array_keys(pg_animals())],
                'mudanca_necessaria'      => ['type' => 'text'],
            ]],
        ],
    ];
}

/**
 * Retorna as question_keys de um bloco, na ordem de definição.
 */
function pg_block_question_keys(string $blockKey): array
{
    $keys = [];
    foreach (pg_questions() as $qkey => $q) {
        if ($q['block_key'] === $blockKey) {
            $keys[] = $qkey;
        }
    }
    return $keys;
}

/**
 * Bloco seguinte na ordem, ou null se for o último.
 */
function pg_next_block(string $blockKey): ?string
{
    $order = pg_block_order();
    $i = array_search($blockKey, $order, true);
    if ($i === false || $i + 1 >= count($order)) {
        return null;
    }
    return $order[$i + 1];
}

/* ------------------------------------------------------------------ */
/* Validação/normalização de UMA resposta                              */
/* ------------------------------------------------------------------ */

/**
 * Valida e normaliza o valor de uma pergunta segundo seu spec.
 *
 * @return array{ok:bool, error?:string, store?:array}
 *   store = ['answer_text'=>?string, 'answer_number'=>?int, 'answer_json'=>?string]
 */
function pg_validate_answer(array $question, $value): array
{
    $spec = $question['spec'] ?? ['shape' => 'open'];
    $shape = $spec['shape'] ?? 'open';

    switch ($shape) {

        case 'open':
            $text = is_string($value) ? pg_clean_str($value, PG_TEXT_MAX) : '';
            if (mb_strlen($text) < PG_TEXT_MIN) {
                return ['ok' => false, 'error' => 'Resposta muito curta (mínimo ' . PG_TEXT_MIN . ' caracteres).'];
            }
            return ['ok' => true, 'store' => ['answer_text' => $text, 'answer_number' => null, 'answer_json' => null]];

        case 'scale':
            $n = pg_parse_scale($value);
            if ($n === null) {
                return ['ok' => false, 'error' => 'Informe uma nota inteira de 0 a 10.'];
            }
            return ['ok' => true, 'store' => ['answer_text' => null, 'answer_number' => $n, 'answer_json' => null]];

        case 'fields':
            return pg_validate_fields($spec['fields'] ?? [], $value);

        case 'groups':
            return pg_validate_groups($spec, $value);

        case 'matrix_flat':
            return pg_validate_matrix_flat($spec['keys'] ?? [], $value);

        case 'matrix_nested':
            return pg_validate_matrix_nested($spec['rows'] ?? [], $spec['cols'] ?? [], $value);
    }

    return ['ok' => false, 'error' => 'Tipo de resposta não suportado.'];
}

/** Nota inteira 0..10 ou null. */
function pg_parse_scale($value): ?int
{
    if (is_bool($value) || is_array($value)) {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $f = (float) $value;
    if (floor($f) != $f) {
        return null;
    }
    $n = (int) $f;
    return ($n >= 0 && $n <= 10) ? $n : null;
}

/** Objeto plano de campos: cada campo é text | scale | enum. */
function pg_validate_fields(array $fields, $value): array
{
    if (!is_array($value)) {
        return ['ok' => false, 'error' => 'Resposta em formato inválido.'];
    }
    $out = [];
    foreach ($fields as $name => $rule) {
        $type = $rule['type'] ?? 'text';
        $raw  = $value[$name] ?? null;

        if ($type === 'scale') {
            $n = pg_parse_scale($raw);
            if ($n === null) {
                return ['ok' => false, 'error' => 'Informe uma nota inteira de 0 a 10.'];
            }
            $out[$name] = $n;
        } elseif ($type === 'enum') {
            $opt = is_string($raw) ? trim($raw) : '';
            if (!in_array($opt, $rule['options'] ?? [], true)) {
                return ['ok' => false, 'error' => 'Selecione uma das opções disponíveis.'];
            }
            $out[$name] = $opt;
        } else { // text
            $t = is_string($raw) ? pg_clean_str($raw, PG_TEXT_MAX) : '';
            if (mb_strlen($t) < PG_SUBTEXT_MIN) {
                return ['ok' => false, 'error' => 'Preencha todos os campos solicitados.'];
            }
            $out[$name] = $t;
        }
    }
    return pg_json_store($out);
}

/** Grupos repetidos (ex.: 3 apostas), cada grupo com os mesmos campos. */
function pg_validate_groups(array $spec, $value): array
{
    if (!is_array($value)) {
        return ['ok' => false, 'error' => 'Resposta em formato inválido.'];
    }
    $out = [];
    foreach (($spec['groups'] ?? []) as $g) {
        $grp = $value[$g] ?? null;
        if (!is_array($grp)) {
            return ['ok' => false, 'error' => 'Preencha todos os itens solicitados.'];
        }
        $res = pg_validate_fields($spec['group_fields'] ?? [], $grp);
        if (!$res['ok']) {
            return $res;
        }
        $out[$g] = json_decode($res['store']['answer_json'], true);
    }
    return pg_json_store($out);
}

/** Matriz plana: cada chave é uma nota 0..10. */
function pg_validate_matrix_flat(array $keys, $value): array
{
    if (!is_array($value)) {
        return ['ok' => false, 'error' => 'Preencha todas as notas.'];
    }
    $out = [];
    foreach ($keys as $k) {
        $n = pg_parse_scale($value[$k] ?? null);
        if ($n === null) {
            return ['ok' => false, 'error' => 'Preencha todas as notas de 0 a 10.'];
        }
        $out[$k] = $n;
    }
    return pg_json_store($out);
}

/** Matriz aninhada: linhas x colunas, cada célula uma nota 0..10. */
function pg_validate_matrix_nested(array $rows, array $cols, $value): array
{
    if (!is_array($value)) {
        return ['ok' => false, 'error' => 'Preencha todas as notas.'];
    }
    $out = [];
    foreach ($rows as $r) {
        $rowVal = $value[$r] ?? null;
        if (!is_array($rowVal)) {
            return ['ok' => false, 'error' => 'Preencha todas as notas de todas as unidades.'];
        }
        $out[$r] = [];
        foreach ($cols as $c) {
            $n = pg_parse_scale($rowVal[$c] ?? null);
            if ($n === null) {
                return ['ok' => false, 'error' => 'Preencha todas as notas de 0 a 10.'];
            }
            $out[$r][$c] = $n;
        }
    }
    return pg_json_store($out);
}

/** Empacota um array como JSON válido para a coluna answer_json. */
function pg_json_store(array $data): array
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Falha ao processar a resposta.'];
    }
    return ['ok' => true, 'store' => ['answer_text' => null, 'answer_number' => null, 'answer_json' => $json]];
}
