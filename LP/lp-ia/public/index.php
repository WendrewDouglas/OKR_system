<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    http_response_code(503);
    echo 'Landing temporariamente indisponível.';
    exit;
}

$csrf = lp_csrf_token();

// Dados configuráveis
$officialCents = lp_setting_int($landingId, 'official_price_cents', 29700);
$discountCents = lp_setting_int($landingId, 'discount_price_cents', 14700);
$trainingDate  = trim((string) lp_setting($landingId, 'training_date', ''));
$trainingTime  = trim((string) lp_setting($landingId, 'training_time', ''));
$trainingLocal = trim((string) lp_setting($landingId, 'training_location', ''));
$spotsText     = trim((string) lp_setting($landingId, 'spots_status_text', 'Turma limitada para garantir prática assistida.'));
$btnOficial    = trim((string) lp_setting($landingId, 'btn_text_oficial', 'Garantir minha vaga'));

// UTM / origem
$utmSource   = lp_clean_param($_GET['utm_source'] ?? null);
$utmMedium   = lp_clean_param($_GET['utm_medium'] ?? null);
$utmCampaign = lp_clean_param($_GET['utm_campaign'] ?? null);

// Registra page view (server-side, confiável)
lp_log_event($landingId, 'page_view', [
    'utm_source' => $utmSource,
    'utm_medium' => $utmMedium,
    'utm_campaign' => $utmCampaign,
    'metadata'   => ['path' => $_SERVER['REQUEST_URI'] ?? ''],
]);

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$datetimeParts = array_filter([$trainingDate, $trainingTime, $trainingLocal], static fn($v) => $v !== '');
$hasDatetime = !empty($datetimeParts);

$paraQuem = [
    'Profissionais financeiros', 'Administrativo financeiro', 'Contas a pagar',
    'Contas a receber', 'Faturamento', 'Cobrança',
    'Profissionais em recolocação', 'Quem quer se diferenciar no mercado',
];
$aprende = [
    ['IA para contas a pagar', 'Automatize conferências, lançamentos e organização.'],
    ['IA para contas a receber', 'Acelere baixas, conciliações e acompanhamento.'],
    ['IA para cobrança', 'Mensagens e réguas de cobrança mais eficientes.'],
    ['IA para e-mails profissionais', 'Escreva e responda mais rápido e melhor.'],
    ['IA para Excel e planilhas', 'Fórmulas, tabelas e análises sem travar.'],
    ['IA para fluxo de caixa simples', 'Monte e leia um fluxo de caixa com apoio da IA.'],
    ['IA para checklist e POP', 'Padronize processos do dia a dia financeiro.'],
    ['IA para currículo e entrevista', 'Prepare-se para recolocação e processos seletivos.'],
];
$agenda = [
    ['Hora 1', 'IA sem medo e com aplicação real', 'Fundamentos práticos e mentalidade para usar IA no trabalho.'],
    ['Hora 2', 'IA para o financeiro do dia a dia', 'Contas a pagar, receber, cobrança e e-mails.'],
    ['Hora 3', 'IA com Excel, controles e relatórios', 'Planilhas, fluxo de caixa e relatórios.'],
    ['Hora 4', 'IA para carreira, currículo e entrevista', 'Recolocação e diferenciação no mercado.'],
];
$entregaveis = [
    'Certificado de participação',
    'Kit de prompts para financeiro',
    'Modelo de checklist de contas a pagar',
    'Modelo de régua de cobrança',
    'Modelo simples de fluxo de caixa',
    'Modelo de prompt para currículo e entrevista',
];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IA Aplicada ao Dia a Dia Financeiro — Treinamento Presencial</title>
<meta name="description" content="Treinamento presencial e prático de IA para profissionais financeiros e administrativos. 4 horas, hands-on, certificado e turma limitada.">
<meta name="robots" content="index,follow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/lp-ia.css">
</head>
<body
  data-csrf="<?= $e($csrf) ?>"
  data-utm-source="<?= $e($utmSource) ?>"
  data-utm-medium="<?= $e($utmMedium) ?>"
  data-utm-campaign="<?= $e($utmCampaign) ?>"
  data-official-cents="<?= $officialCents ?>">

<!-- ====== HEADER ====== -->
<header class="lp-header">
  <div class="lp-container lp-header__inner">
    <span class="lp-logo">Planning<strong>BI</strong></span>
    <a href="#inscricao" class="lp-btn lp-btn--sm lp-cta-scroll">Quero participar</a>
  </div>
</header>

<!-- ====== HERO ====== -->
<section class="lp-hero">
  <div class="lp-container">
    <div class="lp-badges">
      <span class="lp-badge">Presencial</span>
      <span class="lp-badge">4 horas</span>
      <span class="lp-badge">Hands-on</span>
      <span class="lp-badge">Certificado</span>
      <span class="lp-badge">Turma limitada</span>
    </div>
    <h1 class="lp-hero__title">IA Aplicada ao Dia a Dia Financeiro</h1>
    <p class="lp-hero__subtitle">
      Treinamento presencial e prático para profissionais financeiros e administrativos
      que querem ganhar produtividade, melhorar controles e se diferenciar no mercado.
    </p>

    <?php if ($hasDatetime): ?>
    <ul class="lp-hero__meta">
      <?php if ($trainingDate !== ''): ?><li>📅 <?= $e($trainingDate) ?></li><?php endif; ?>
      <?php if ($trainingTime !== ''): ?><li>⏰ <?= $e($trainingTime) ?></li><?php endif; ?>
      <?php if ($trainingLocal !== ''): ?><li>📍 <?= $e($trainingLocal) ?></li><?php endif; ?>
    </ul>
    <?php else: ?>
    <p class="lp-hero__meta lp-hero__meta--tbd">Data, horário e local serão divulgados em breve.</p>
    <?php endif; ?>

    <a href="#inscricao" class="lp-btn lp-btn--lg lp-cta-scroll">Garantir minha vaga</a>
  </div>
</section>

<!-- ====== POSICIONAMENTO ====== -->
<section class="lp-section lp-quote">
  <div class="lp-container">
    <blockquote>
      “A IA não substitui bons profissionais. Mas profissionais que sabem usar IA tendem
      a entregar mais rápido, organizar melhor informações e se destacar sobre quem
      continua fazendo tudo da forma antiga.”
    </blockquote>
  </div>
</section>

<!-- ====== PARA QUEM É ====== -->
<section class="lp-section">
  <div class="lp-container">
    <h2 class="lp-h2">Para quem é</h2>
    <ul class="lp-chips">
      <?php foreach ($paraQuem as $p): ?>
        <li class="lp-chip"><?= $e($p) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ====== O QUE SERÁ APRENDIDO ====== -->
<section class="lp-section lp-section--alt">
  <div class="lp-container">
    <h2 class="lp-h2">O que você vai aprender</h2>
    <div class="lp-grid">
      <?php foreach ($aprende as $a): ?>
        <div class="lp-card">
          <h3><?= $e($a[0]) ?></h3>
          <p><?= $e($a[1]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ====== AGENDA ====== -->
<section class="lp-section">
  <div class="lp-container">
    <h2 class="lp-h2">Agenda das 4 horas</h2>
    <div class="lp-timeline">
      <?php foreach ($agenda as $h): ?>
        <div class="lp-timeline__item">
          <span class="lp-timeline__tag"><?= $e($h[0]) ?></span>
          <div>
            <h3><?= $e($h[1]) ?></h3>
            <p><?= $e($h[2]) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ====== ENTREGÁVEIS ====== -->
<section class="lp-section lp-section--alt">
  <div class="lp-container">
    <h2 class="lp-h2">O que você leva</h2>
    <ul class="lp-deliverables">
      <?php foreach ($entregaveis as $d): ?>
        <li><span class="lp-check">✓</span> <?= $e($d) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ====== VAGAS ====== -->
<section class="lp-section lp-spots">
  <div class="lp-container">
    <p class="lp-spots__text"><?= $e($spotsText) ?></p>
  </div>
</section>

<!-- ====== PREÇO + CUPOM + FORMULÁRIO ====== -->
<section class="lp-section lp-offer" id="inscricao">
  <div class="lp-container lp-offer__grid">

    <div class="lp-price-box">
      <span class="lp-price-box__label">Valor do treinamento</span>
      <div class="lp-price" id="lp-price-display">
        <span class="lp-price__value" id="lp-price-value"><?= $e(lp_money_br($officialCents)) ?></span>
      </div>
      <p class="lp-price-box__note" id="lp-price-note">Aplique seu cupom para liberar o valor especial.</p>

      <div class="lp-coupon">
        <label for="lp-coupon-input">Tem um cupom?</label>
        <div class="lp-coupon__row">
          <input type="text" id="lp-coupon-input" name="coupon" placeholder="Digite seu cupom" autocomplete="off" maxlength="60">
          <button type="button" id="lp-coupon-apply" class="lp-btn lp-btn--ghost">Aplicar</button>
        </div>
        <p class="lp-coupon__feedback" id="lp-coupon-feedback" role="status" aria-live="polite"></p>
      </div>
    </div>

    <form id="lp-lead-form" class="lp-form" novalidate>
      <h2 class="lp-h2">Garanta seu interesse</h2>

      <div class="lp-field">
        <label for="lp-nome">Nome completo *</label>
        <input type="text" id="lp-nome" name="nome" required maxlength="180" autocomplete="name">
      </div>
      <div class="lp-field">
        <label for="lp-email">E-mail *</label>
        <input type="email" id="lp-email" name="email" required maxlength="190" autocomplete="email">
      </div>
      <div class="lp-field">
        <label for="lp-whatsapp">WhatsApp (com DDD) *</label>
        <input type="tel" id="lp-whatsapp" name="whatsapp" required maxlength="40" autocomplete="tel" placeholder="(00) 00000-0000">
      </div>
      <div class="lp-field-row">
        <div class="lp-field">
          <label for="lp-cidade">Cidade</label>
          <input type="text" id="lp-cidade" name="cidade" maxlength="120" autocomplete="address-level2">
        </div>
        <div class="lp-field">
          <label for="lp-area">Área de atuação</label>
          <input type="text" id="lp-area" name="area_atuacao" maxlength="120">
        </div>
      </div>

      <!-- honeypot (oculto via CSS) -->
      <div class="lp-hp" aria-hidden="true">
        <label>Não preencha este campo <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="lp-field lp-consent">
        <label>
          <input type="checkbox" id="lp-consent" name="consent" value="1" required>
          <span><?= $e(lp_consent_text()) ?></span>
        </label>
      </div>

      <p class="lp-form__feedback" id="lp-form-feedback" role="status" aria-live="polite"></p>

      <button type="submit" id="lp-submit" class="lp-btn lp-btn--lg lp-btn--block">Quero garantir minha vaga</button>

      <!-- Área de pagamento revelada após o cadastro -->
      <div class="lp-checkout" id="lp-checkout" hidden>
        <p class="lp-checkout__msg" id="lp-checkout-msg"></p>
        <a href="#" id="lp-pay-btn" class="lp-btn lp-btn--lg lp-btn--block lp-btn--pay"><?= $e($btnOficial) ?></a>
      </div>
    </form>
  </div>
</section>

<!-- ====== TRANSPARÊNCIA ====== -->
<section class="lp-section lp-transparency">
  <div class="lp-container">
    <h2 class="lp-h2">Aviso de transparência</h2>
    <ul>
      <?php foreach (lp_transparency_points() as $tp): ?>
        <li><?= $e($tp) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<footer class="lp-footer">
  <div class="lp-container">
    <p>© <span id="lp-year">2026</span> · Iniciativa independente · Treinamento profissional</p>
  </div>
</footer>

<script src="../assets/js/lp-ia.js" defer></script>
</body>
</html>
