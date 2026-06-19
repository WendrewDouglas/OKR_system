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
$trainingLocal    = trim((string) lp_setting($landingId, 'training_location', ''));
$trainingLocalUrl = trim((string) lp_setting($landingId, 'training_location_url', ''));
$spotsText     = trim((string) lp_setting($landingId, 'spots_status_text', 'Turma limitada para garantir prática assistida.'));
$btnOficial    = trim((string) lp_setting($landingId, 'btn_text_oficial', 'Garantir minha vaga'));

// Mapa ilustrativo da sala (gatilho de escassez) — configurável via lp_settings
$spotsTotal     = max(1, lp_setting_int($landingId, 'spots_total', 24));
$spotsAvailable = max(0, min($spotsTotal, lp_setting_int($landingId, 'spots_available', 2)));
$seatCols       = max(1, lp_setting_int($landingId, 'spots_cols', 4));
// distribui as vagas livres de forma espalhada e determinística pelo mapa
$vacantSeats = [];
for ($k = 0; $k < $spotsAvailable; $k++) {
    $idx = ((int) floor($spotsTotal * ($k + 0.5) / max(1, $spotsAvailable)) + $k) % $spotsTotal;
    $vacantSeats[$idx] = true;
}

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
    'Apostila com material didático',
    'Kit de prompts para o financeiro',
    'Coffee break',
    'Acesso à comunidade de IA e Ciência de Dados',
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

<!-- Open Graph / prévia ao compartilhar (WhatsApp, Facebook, LinkedIn) -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="PlanningBI">
<meta property="og:title" content="IA Aplicada ao Dia a Dia Financeiro — Treinamento Presencial">
<meta property="og:description" content="Treinamento presencial e prático (4h) com certificado, apostila e kit de prompts. Valor especial com cupom. Vagas limitadas.">
<meta property="og:url" content="https://planningbi.com.br/OKR_system/LP/lp-ia/public/">
<meta property="og:image" content="https://planningbi.com.br/OKR_system/LP/lp-ia/assets/img/og-treinamento.jpg">
<meta property="og:image:secure_url" content="https://planningbi.com.br/OKR_system/LP/lp-ia/assets/img/og-treinamento.jpg">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="1200">
<meta property="og:image:alt" content="Treinamento IA Aplicada ao Dia a Dia Financeiro — 04/07/2026, Araçatuba/SP">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="IA Aplicada ao Dia a Dia Financeiro — Treinamento Presencial">
<meta name="twitter:description" content="Presencial, 4h, certificado, apostila e kit de prompts. Valor especial com cupom. Vagas limitadas.">
<meta name="twitter:image" content="https://planningbi.com.br/OKR_system/LP/lp-ia/assets/img/og-treinamento.jpg">

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
    <img class="lp-logo__img" src="../assets/img/logo-planning.jpg" alt="PlanningBI — Strategic Solutions">
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
      <?php if ($trainingLocal !== ''): ?><li>📍 <?php if ($trainingLocalUrl !== ''): ?><a href="<?= $e($trainingLocalUrl) ?>" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline"><?= $e($trainingLocal) ?></a><?php else: ?><?= $e($trainingLocal) ?><?php endif; ?></li><?php endif; ?>
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

<!-- ====== BÔNUS LINKEDIN ====== -->
<section class="lp-section">
  <div class="lp-container">
    <div class="lp-bonus">
      <div class="lp-bonus__top">
        <span class="lp-bonus__logo">
          <svg viewBox="0 0 24 24" width="30" height="30" role="img" aria-label="LinkedIn">
            <path fill="#0A66C2" d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.42v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 1 1 0-4.14 2.07 2.07 0 0 1 0 4.14zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.72v20.56C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.72V1.72C24 .77 23.2 0 22.22 0z"/>
          </svg>
        </span>
        <div class="lp-bonus__heading">
          <span class="lp-bonus__badge">🎁 Brinde exclusivo · grátis</span>
          <h2 class="lp-bonus__title">Mineração de dados + IA para alavancar sua carreira no LinkedIn</h2>
        </div>
      </div>
      <div class="lp-bonus__body">
        <p class="lp-bonus__desc">Um treinamento rápido e direto ao ponto para usar <strong>IA e mineração de dados</strong> e se destacar no LinkedIn — ser encontrado por recrutadores e clientes e gerar oportunidades reais.</p>
        <div class="lp-bonus__grid">
          <div class="lp-bonus__item"><span class="lp-bonus__ic">🚀</span><span>Perfil otimizado para atrair recrutadores e clientes</span></div>
          <div class="lp-bonus__item"><span class="lp-bonus__ic">📈</span><span>Mineração de dados para achar vagas e oportunidades</span></div>
          <div class="lp-bonus__item"><span class="lp-bonus__ic">🎯</span><span>IA para mensagens e conexões que realmente convertem</span></div>
          <div class="lp-bonus__item"><span class="lp-bonus__ic">🏆</span><span>Posicionamento de autoridade no seu nicho</span></div>
        </div>
        <div class="lp-bonus__free"><span>✓</span> Incluso <strong>gratuitamente</strong> para todos os inscritos no treinamento</div>
      </div>
    </div>
  </div>
</section>

<!-- ====== O QUE LEVAR ====== -->
<section class="lp-section">
  <div class="lp-container">
    <h2 class="lp-h2">O que levar</h2>
    <p class="lp-prereq__intro">A prática é hands-on: vamos integrar as IAs <strong>direto no navegador e no Office</strong>. Para o <strong>máximo aproveitamento</strong>, recomendamos levar:</p>
    <ul class="lp-prereq">
      <li><span class="lp-prereq__ic">💻</span><div><strong>Notebook</strong><br><small>com carregador</small></div></li>
      <li><span class="lp-prereq__ic">🌐</span><div><strong>Google Chrome</strong><br><small>instalado</small></div></li>
      <li><span class="lp-prereq__ic">📊</span><div><strong>Microsoft Office</strong><br><small>Excel e Word instalados</small></div></li>
    </ul>

    <div class="lp-reassure">
      <div class="lp-reassure__head">
        <span class="lp-reassure__emoji">😌</span>
        <div>
          <strong>Não conseguiu levar tudo? Sem problema — você ainda aproveita!</strong>
          <p>Os itens acima são <strong>recomendados</strong>, mas <strong>não são obrigatórios</strong>. Mesmo sem eles você participa:</p>
        </div>
      </div>
      <div class="lp-reassure__grid">
        <div class="lp-reassure__item"><span>📱</span><div>Faz várias atividades direto pelo <strong>smartphone</strong></div></div>
        <div class="lp-reassure__item"><span>🤝</span><div>Acompanha o hands-on junto com um <strong>colega de turma</strong></div></div>
        <div class="lp-reassure__item"><span>📘</span><div>Leva a <strong>apostila completa</strong>, com todo o conteúdo e o passo a passo para refazer em casa</div></div>
      </div>
    </div>
  </div>
</section>

<!-- ====== SOBRE O INSTRUTOR ====== -->
<section class="lp-section lp-section--alt lp-instr">
  <div class="lp-container">
    <span class="lp-instr__kicker">Quem vai te ensinar</span>
    <div class="lp-instr__grid">
      <div class="lp-instr__photo">
        <img src="../assets/img/instrutor.jpg" alt="Wendrew Gomes, instrutor do treinamento">
        <div class="lp-instr__id">
          <strong>Wendrew Gomes</strong>
          <span>Líder de TI, Dados &amp; IA · Consultor — PlanningBI</span>
        </div>
      </div>
      <div class="lp-instr__body">
        <p class="lp-instr__lead">Há mais de 15 anos transformando <strong>tecnologia, dados e IA em resultado real</strong> — na indústria, em startups e na consultoria.</p>
        <p>Gestor de TI na <strong>Colormaq</strong> e consultor pela <strong>PlanningBI</strong>, lidera projetos de BI, SAP, automação, IA e OKRs com impacto direto em receita, produtividade e governança. Já apresentou <strong>+260 painéis executivos à diretoria</strong> e criou um TI Innovation Lab com <strong>+50 iniciativas</strong> de inovação.</p>
        <p>Como ex-COO, empresário e consultor, apoiou <strong>startups</strong> e captações de <strong>+R$ 2,85 milhões</strong>, e liderou o projeto vencedor do <strong>InnovaTrade Challenge da Ambev</strong>. Agora ensina, na prática, como qualquer profissional pode usar IA para <strong>entregar mais rápido, decidir melhor e se destacar no mercado</strong>.</p>
        <ul class="lp-instr__stats">
          <li><b>+100%</b><span>faturamento B2C com iniciativas digitais</span></li>
          <li><b>+260</b><span>painéis executivos à diretoria</span></li>
          <li><b>+R$ 2,85 Mi</b><span>captados em startups apoiadas</span></li>
          <li><b>15+ anos</b><span>de tecnologia, dados e gestão</span></li>
        </ul>
        <p class="lp-instr__cred-label">Formação e certificações</p>
        <div class="lp-instr__logos">
          <img src="../assets/img/logos/unesp.png" alt="UNESP" title="Graduação — UNESP">
          <img src="../assets/img/logos/mba-usp-esalq.jpg" alt="MBA USP/Esalq" title="MBA Data Science &amp; Analytics — USP/Esalq">
          <img src="../assets/img/logos/pmp.webp" alt="PMI / PMP" title="PMP/CAPM — Fundamentos (PMI)">
          <img class="lp-instr__badge" src="../assets/img/logos/okr-master.webp" alt="OKR Master Professional" title="OKR Master Professional (Certiprof)">
          <img class="lp-instr__badge" src="../assets/img/logos/okr-champion.webp" alt="OKR Champion" title="OKR Champion (Certiprof)">
        </div>
        <a href="#inscricao" class="lp-btn lp-btn--lg lp-cta-scroll lp-instr__cta">Quero aprender com o Wendrew</a>
      </div>
    </div>
  </div>
</section>

<!-- ====== VAGAS (mapa ilustrativo da sala) ====== -->
<section class="lp-section lp-spots">
  <div class="lp-container">
    <h2 class="lp-h2 lp-spots__title">Vagas limitadas</h2>
    <?php if ($spotsAvailable > 0): ?>
      <p class="lp-spots__headline">Restam apenas <strong><?= (int) $spotsAvailable ?></strong> <?= $spotsAvailable === 1 ? 'vaga' : 'vagas' ?></p>
    <?php else: ?>
      <p class="lp-spots__headline">Vagas esgotadas</p>
    <?php endif; ?>

    <div class="lp-classroom">
      <div class="lp-classroom__board">Frente · Lousa</div>
      <div class="lp-seats" style="grid-template-columns:repeat(<?= (int) $seatCols ?>,1fr)">
        <?php for ($i = 0; $i < $spotsTotal; $i++): $free = isset($vacantSeats[$i]); ?>
          <?php if ($free): ?>
            <a class="lp-seat lp-seat--free lp-cta-scroll" href="#inscricao" aria-label="Vaga disponível — garanta a sua">livre</a>
          <?php else: ?>
            <span class="lp-seat" aria-label="Ocupado"></span>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
      <div class="lp-classroom__legend">
        <span><i class="lp-dot lp-dot--taken"></i> Ocupado</span>
        <span><i class="lp-dot lp-dot--free"></i> Vaga disponível</span>
      </div>
    </div>

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
      <h2 class="lp-h2">Garanta sua vaga</h2>

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

<!-- ====== TRANSPARÊNCIA (letras miúdas) ====== -->
<section class="lp-disclaimer">
  <div class="lp-container">
    <p class="lp-disclaimer__title">Aviso de transparência</p>
    <ul class="lp-disclaimer__list">
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
