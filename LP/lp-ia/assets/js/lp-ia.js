/* =============================================================
   LP_IA — comportamento da landing.
   Cupom (validação server-side), formulário de lead e checkout.
   Endpoints relativos a /public/  ->  ../api/
   ============================================================= */
(function () {
  'use strict';

  var body = document.body;
  var CFG = {
    csrf: body.getAttribute('data-csrf') || '',
    utm_source: body.getAttribute('data-utm-source') || '',
    utm_medium: body.getAttribute('data-utm-medium') || '',
    utm_campaign: body.getAttribute('data-utm-campaign') || '',
    officialCents: parseInt(body.getAttribute('data-official-cents') || '0', 10),
  };
  var API = '../api/';

  var $ = function (id) { return document.getElementById(id); };

  // ano no rodapé
  try { $('lp-year').textContent = String(new Date().getFullYear()); } catch (e) {}

  function utmPayload() {
    return {
      utm_source: CFG.utm_source,
      utm_medium: CFG.utm_medium,
      utm_campaign: CFG.utm_campaign,
      referrer: document.referrer || ''
    };
  }

  function postJSON(endpoint, data) {
    var payload = Object.assign({ csrf: CFG.csrf }, data);
    return fetch(API + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        return { status: r.status, body: j };
      });
    });
  }

  /* ---------- Estado do cupom ---------- */
  var appliedCoupon = null; // código aplicado com sucesso

  /* ---------- Cupom ---------- */
  var couponInput = $('lp-coupon-input');
  var couponBtn = $('lp-coupon-apply');
  var couponFeedback = $('lp-coupon-feedback');
  var priceValue = $('lp-price-value');
  var priceNote = $('lp-price-note');

  function setCouponFeedback(msg, type) {
    couponFeedback.textContent = msg || '';
    couponFeedback.className = 'lp-coupon__feedback' + (type ? ' is-' + type : '');
  }

  function applyCoupon() {
    var code = (couponInput.value || '').trim();
    if (!code) { setCouponFeedback('Digite um cupom.', 'error'); return; }

    couponBtn.disabled = true;
    setCouponFeedback('Validando...', '');

    postJSON('coupon_apply.php', Object.assign({ coupon: code }, utmPayload()))
      .then(function (res) {
        var b = res.body || {};
        if (b.ok && b.valid) {
          appliedCoupon = b.code || code;
          // preço com desconto
          priceValue.innerHTML =
            '<span class="lp-price__old">' + formatCents(CFG.officialCents) + '</span>' +
            '<span class="lp-price__value is-discount">' + (b.price_formatted || '') + '</span>';
          priceNote.textContent = b.message || 'Cupom aplicado.';
          priceNote.className = 'lp-price-box__note is-success';
          setCouponFeedback('Cupom aplicado com sucesso!', 'success');
          // reflete no formulário
          ensureHiddenCoupon(appliedCoupon);
        } else {
          appliedCoupon = null;
          ensureHiddenCoupon('');
          resetPrice();
          setCouponFeedback((b && b.message) || 'Cupom inválido ou expirado.', 'error');
        }
      })
      .catch(function () {
        setCouponFeedback('Não foi possível validar agora. Tente novamente.', 'error');
      })
      .finally(function () { couponBtn.disabled = false; });
  }

  function resetPrice() {
    priceValue.innerHTML = '<span class="lp-price__value">' + formatCents(CFG.officialCents) + '</span>';
    priceNote.textContent = 'Aplique seu cupom para liberar o valor especial.';
    priceNote.className = 'lp-price-box__note';
  }

  function formatCents(cents) {
    var v = (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return 'R$ ' + v;
  }

  function ensureHiddenCoupon(code) {
    var form = $('lp-lead-form');
    var hidden = form.querySelector('input[name="coupon"][type="hidden"]');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'coupon';
      form.appendChild(hidden);
    }
    hidden.value = code || '';
  }

  if (couponBtn) couponBtn.addEventListener('click', applyCoupon);
  if (couponInput) couponInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
  });

  /* ---------- Formulário de lead ---------- */
  var form = $('lp-lead-form');
  var submitBtn = $('lp-submit');
  var formFeedback = $('lp-form-feedback');
  var checkoutBox = $('lp-checkout');
  var checkoutMsg = $('lp-checkout-msg');
  var payBtn = $('lp-pay-btn');

  function clearFieldErrors() {
    ['lp-nome', 'lp-email', 'lp-whatsapp'].forEach(function (id) {
      var el = $(id); if (el) el.parentElement.classList.remove('has-error');
    });
    var consent = $('lp-consent');
    if (consent) consent.closest('.lp-consent').classList.remove('has-error');
  }

  function markErrors(fields) {
    var map = { nome: 'lp-nome', email: 'lp-email', whatsapp: 'lp-whatsapp' };
    Object.keys(fields || {}).forEach(function (k) {
      if (k === 'consent') {
        var c = $('lp-consent'); if (c) c.closest('.lp-consent').classList.add('has-error');
      } else if (map[k]) {
        var el = $(map[k]); if (el) el.parentElement.classList.add('has-error');
      }
    });
  }

  function setFormFeedback(msg, type) {
    formFeedback.textContent = msg || '';
    formFeedback.className = 'lp-form__feedback' + (type ? ' is-' + type : '');
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clearFieldErrors();
      setFormFeedback('Enviando...', '');
      submitBtn.disabled = true;

      var data = {
        nome: ($('lp-nome').value || '').trim(),
        email: ($('lp-email').value || '').trim(),
        whatsapp: ($('lp-whatsapp').value || '').trim(),
        cidade: ($('lp-cidade').value || '').trim(),
        area_atuacao: ($('lp-area').value || '').trim(),
        coupon: appliedCoupon || '',
        consent: $('lp-consent').checked,
        website: (form.querySelector('input[name="website"]') || {}).value || ''
      };
      data = Object.assign(data, utmPayload());

      postJSON('lead_submit.php', data)
        .then(function (res) {
          var b = res.body || {};
          if (b.ok && b.checkout_url) {
            setFormFeedback(b.message || 'Dados recebidos!', 'success');
            payBtn.setAttribute('href', b.checkout_url);
            checkoutMsg.textContent = b.message || '';
            checkoutBox.hidden = false;
            submitBtn.hidden = true;
            checkoutBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
          } else if (b.silent) {
            setFormFeedback('Dados recebidos!', 'success');
          } else if (res.status === 422 && b.fields) {
            markErrors(b.fields);
            setFormFeedback(b.message || 'Revise os campos destacados.', 'error');
            submitBtn.disabled = false;
          } else {
            setFormFeedback((b && b.message) || 'Não foi possível enviar agora. Tente novamente.', 'error');
            submitBtn.disabled = false;
          }
        })
        .catch(function () {
          setFormFeedback('Falha de conexão. Tente novamente.', 'error');
          submitBtn.disabled = false;
        });
    });
  }

  // O clique no botão de pagamento navega para checkout_redirect.php,
  // que registra o evento server-side ANTES de redirecionar ao PagBank.
})();
