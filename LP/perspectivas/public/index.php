<?php
declare(strict_types=1);

// =============================================================
// Página pública "Perspectivas de Gestão" (FMX).
// Renderiza a trilha por blocos server-side a partir de questions.php
// (mesma fonte da validação de backend) e injeta CSRF + spec para o JS.
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$csrf = pg_csrf_token();

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$blockOrder  = pg_block_order();
$blockTitles = pg_block_titles();
$questions   = pg_questions();

// Rótulos amigáveis para subcampos de perguntas 'json'/'groups'.
$FIELD_LABELS = [
    'nota'                                  => 'Sua nota (0 a 10)',
    'justificativa'                         => 'Justificativa',
    'prioridade_1'                          => 'Prioridade 1',
    'prioridade_2'                          => 'Prioridade 2',
    'prioridade_3'                          => 'Prioridade 3',
    'o_que_falta'                           => 'O que falta para evoluir?',
    'frente'                                => 'Frente',
    'porque'                                => 'Por quê?',
    'cliente_ideal'                         => 'Cliente ideal da FMX hoje',
    'cliente_evitar_ou_reduzir_dependencia' => 'Cliente a evitar ou reduzir dependência',
    'o_que_precisa_mudar'                   => 'O que precisaria mudar?',
    'opcao'                                 => 'Selecione',
    'explicacao'                            => 'Explique',
    'manchete_capa'                         => 'a) Manchete da capa',
    'conquista_justificativa'               => 'b) Conquista que justificaria a capa',
    'animal_atual'                          => 'c) Se a FMX fosse um animal hoje, qual seria?',
    'animal_atual_porque'                   => 'Por quê?',
    'animal_futuro'                         => 'd) Que animal a FMX deveria se tornar no futuro?',
    'mudanca_necessaria'                    => 'O que precisaria mudar para isso acontecer?',
];
$GROUP_LABELS = [
    'aposta_1' => 'Aposta 1',
    'aposta_2' => 'Aposta 2',
    'aposta_3' => 'Aposta 3',
];

/** Componente de escala 0..10 em pílulas. */
function pg_render_scale(string $inputName, array $e): string
{
    $html = '<div class="pg-scale" role="radiogroup" data-field="' . $e['field'] . '"'
        . (isset($e['row']) ? ' data-row="' . $e['row'] . '" data-col="' . $e['col'] . '"' : '')
        . (isset($e['flat']) ? ' data-key="' . $e['flat'] . '"' : '')
        . ' data-scale="1">';
    for ($i = 0; $i <= 10; $i++) {
        $html .= '<button type="button" class="pg-pill" data-val="' . $i . '" role="radio" aria-checked="false">' . $i . '</button>';
    }
    $html .= '</div>';
    return $html;
}

/** Renderiza uma pergunta completa conforme seu shape. */
function pg_render_question(string $qkey, array $q, callable $esc, array $FIELD_LABELS, array $GROUP_LABELS): string
{
    $spec  = $q['spec'] ?? ['shape' => 'open'];
    $shape = $spec['shape'] ?? 'open';

    $h  = '<div class="pg-question" data-qkey="' . $esc($qkey) . '" data-shape="' . $esc($shape) . '" data-atype="' . $esc($q['answer_type']) . '">';
    $h .= '<p class="pg-q-text">' . $esc($q['question_text']) . '</p>';
    $h .= '<div class="pg-q-error" aria-live="polite"></div>';

    $labelOf = static fn(string $k) => $FIELD_LABELS[$k] ?? ucfirst(str_replace('_', ' ', $k));

    switch ($shape) {

        case 'open':
            $h .= '<textarea class="pg-input pg-textarea" data-role="open" rows="3" maxlength="4000" placeholder="Escreva aqui..."></textarea>';
            break;

        case 'scale':
            $h .= pg_render_scale($qkey, ['field' => '_scale']);
            break;

        case 'fields':
            foreach (($spec['fields'] ?? []) as $fname => $rule) {
                $type = $rule['type'] ?? 'text';
                $h .= '<div class="pg-field" data-field="' . $esc($fname) . '" data-ftype="' . $esc($type) . '">';
                $h .= '<label class="pg-sub-label">' . $esc($labelOf($fname)) . '</label>';
                if ($type === 'scale') {
                    $h .= pg_render_scale($qkey, ['field' => $fname]);
                } elseif ($type === 'enum') {
                    $h .= '<div class="pg-options">';
                    foreach (($rule['options'] ?? []) as $i => $opt) {
                        $id = $esc($qkey . '_' . $fname . '_' . $i);
                        $h .= '<label class="pg-radio"><input type="radio" name="' . $id . '_g" value="' . $esc($opt) . '"> <span>' . $esc($opt) . '</span></label>';
                    }
                    $h .= '</div>';
                } else {
                    $h .= '<textarea class="pg-input pg-textarea" rows="2" maxlength="4000" placeholder="Escreva aqui..."></textarea>';
                }
                $h .= '</div>';
            }
            break;

        case 'groups':
            $fields = $spec['group_fields'] ?? [];
            foreach (($spec['groups'] ?? []) as $g) {
                $h .= '<div class="pg-group" data-group="' . $esc($g) . '">';
                $h .= '<div class="pg-group-title">' . $esc($GROUP_LABELS[$g] ?? ucfirst(str_replace('_', ' ', $g))) . '</div>';
                foreach ($fields as $fname => $rule) {
                    $h .= '<div class="pg-field" data-field="' . $esc($fname) . '" data-ftype="text">';
                    $h .= '<label class="pg-sub-label">' . $esc($labelOf($fname)) . '</label>';
                    $h .= '<textarea class="pg-input pg-textarea" rows="2" maxlength="4000" placeholder="Escreva aqui..."></textarea>';
                    $h .= '</div>';
                }
                $h .= '</div>';
            }
            break;

        case 'matrix_flat':
            $labels = $spec['labels'] ?? [];
            $h .= '<div class="pg-matrix pg-matrix-flat">';
            foreach (($spec['keys'] ?? []) as $k) {
                $h .= '<div class="pg-matrix-row" data-key="' . $esc($k) . '">';
                $h .= '<div class="pg-matrix-label">' . $esc($labels[$k] ?? $k) . '</div>';
                $h .= pg_render_scale($qkey, ['field' => '_flat', 'flat' => $k]);
                $h .= '</div>';
            }
            $h .= '</div>';
            break;

        case 'matrix_nested':
            $rows = $spec['rows'] ?? [];
            $cols = $spec['cols'] ?? [];
            $rl   = $spec['row_labels'] ?? [];
            $cl   = $spec['col_labels'] ?? [];
            $h .= '<div class="pg-matrix pg-matrix-nested">';
            foreach ($rows as $r) {
                $h .= '<div class="pg-unit" data-row="' . $esc($r) . '">';
                $h .= '<div class="pg-unit-title">' . $esc($rl[$r] ?? $r) . '</div>';
                foreach ($cols as $c) {
                    $h .= '<div class="pg-unit-crit" data-col="' . $esc($c) . '">';
                    $h .= '<div class="pg-matrix-label">' . $esc($cl[$c] ?? $c) . '</div>';
                    $h .= pg_render_scale($qkey, ['field' => '_nested', 'row' => $r, 'col' => $c]);
                    $h .= '</div>';
                }
                $h .= '</div>';
            }
            $h .= '</div>';
            break;
    }

    $h .= '</div>'; // .pg-question
    return $h;
}

// Total de "passos" na trilha: 1 (identificação) + N blocos.
$totalSteps = count($blockOrder) + 1;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Perspectivas de Gestão — FMX | PlanningBI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/perspectivas.css?v=1.0">
</head>
<body
  data-csrf="<?= $e($csrf) ?>"
  data-api="../api/"
  data-total-steps="<?= (int) $totalSteps ?>">

<main class="pg-shell">

  <!-- HERO -->
  <header class="pg-hero">
    <div class="pg-hero-inner">
      <div class="pg-brand">PlanningBI</div>
      <h1 class="pg-hero-title">Perspectivas de Gestão</h1>
      <p class="pg-hero-sub">Um diagnóstico estratégico da <strong>FMX</strong>. Suas respostas ajudam a alinhar visão, prioridades e o próximo ciclo de gestão.</p>
      <div class="pg-hero-meta">
        <span>⏱️ ~10 a 15 minutos</span>
        <span>🔒 Confidencial</span>
        <span>📊 20 perguntas</span>
      </div>
    </div>
  </header>

  <!-- CARD PRINCIPAL -->
  <section class="pg-card" id="pg-card">

    <!-- PROGRESSO -->
    <div class="pg-progress" aria-hidden="true">
      <div class="pg-progress-bar"><span id="pg-progress-fill" style="width:0%"></span></div>
      <div class="pg-progress-label"><span id="pg-progress-step">1</span> / <?= (int) $totalSteps ?></div>
    </div>

    <form id="pg-form" novalidate>

      <!-- STEP 0 — Identificação -->
      <section class="pg-step is-active" data-step="0" data-block="identificacao">
        <h2 class="pg-step-title">Vamos começar</h2>
        <p class="pg-step-desc">Precisamos de alguns dados para registrar sua perspectiva.</p>

        <div class="pg-field">
          <label class="pg-sub-label" for="pg-nome">Nome completo</label>
          <input type="text" id="pg-nome" class="pg-input" autocomplete="name" maxlength="150" placeholder="Seu nome completo">
          <div class="pg-q-error" data-for="nome" aria-live="polite"></div>
        </div>

        <div class="pg-field">
          <label class="pg-sub-label" for="pg-email">E-mail</label>
          <input type="email" id="pg-email" class="pg-input" autocomplete="email" inputmode="email" maxlength="150" placeholder="voce@fmx.com.br">
          <div class="pg-q-error" data-for="email" aria-live="polite"></div>
        </div>

        <div class="pg-field">
          <label class="pg-sub-label" for="pg-whatsapp">Telefone / WhatsApp</label>
          <input type="tel" id="pg-whatsapp" class="pg-input" autocomplete="tel" inputmode="tel" maxlength="40" placeholder="(00) 00000-0000">
          <div class="pg-q-error" data-for="whatsapp" aria-live="polite"></div>
        </div>

        <label class="pg-consent">
          <input type="checkbox" id="pg-consent">
          <span><?= $e(pg_consent_text()) ?></span>
        </label>
        <div class="pg-q-error" data-for="consent" aria-live="polite"></div>

        <!-- Honeypot (oculto via CSS) -->
        <div class="pg-hp" aria-hidden="true">
          <label>Não preencha este campo
            <input type="text" id="pg-website" name="website" tabindex="-1" autocomplete="off">
          </label>
        </div>

        <div class="pg-actions">
          <span></span>
          <button type="button" class="pg-btn pg-btn-primary" data-action="start">Começar diagnóstico →</button>
        </div>
      </section>

      <?php $stepIdx = 1; foreach ($blockOrder as $bkey): ?>
      <section class="pg-step" data-step="<?= (int) $stepIdx ?>" data-block="<?= $e($bkey) ?>">
        <div class="pg-step-badge">Bloco <?= (int) $stepIdx ?></div>
        <h2 class="pg-step-title"><?= $e($blockTitles[$bkey] ?? $bkey) ?></h2>
        <div class="pg-questions">
          <?php foreach ($questions as $qkey => $q): ?>
            <?php if ($q['block_key'] === $bkey): ?>
              <?= pg_render_question($qkey, $q, $e, $FIELD_LABELS, $GROUP_LABELS) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="pg-actions">
          <button type="button" class="pg-btn pg-btn-ghost" data-action="prev">← Voltar</button>
          <?php if ($stepIdx < count($blockOrder)): ?>
            <button type="button" class="pg-btn pg-btn-primary" data-action="next">Avançar →</button>
          <?php else: ?>
            <button type="button" class="pg-btn pg-btn-primary" data-action="finish">Concluir ✓</button>
          <?php endif; ?>
        </div>
      </section>
      <?php $stepIdx++; endforeach; ?>

      <!-- STEP FINAL — Agradecimento -->
      <section class="pg-step pg-thanks" data-step="thanks">
        <div class="pg-thanks-icon">✓</div>
        <h2 class="pg-step-title">Obrigado por compartilhar sua perspectiva.</h2>
        <p>Suas respostas foram registradas com sucesso e serão utilizadas pela PlanningBI exclusivamente para compor o diagnóstico estratégico da FMX.</p>
        <p>A análise será consolidada buscando identificar convergências, divergências, oportunidades de alinhamento e prioridades para o próximo ciclo de gestão.</p>
      </section>

    </form>
  </section>

  <footer class="pg-footer">
    <span>© <span id="pg-year">2026</span> PlanningBI — Diagnóstico estratégico FMX</span>
  </footer>
</main>

<script src="../assets/js/perspectivas.js?v=1.0"></script>
</body>
</html>
