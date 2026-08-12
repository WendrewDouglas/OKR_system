<?php
declare(strict_types=1);

// =============================================================
// Página pública do Briefing CRM.
// Conteúdo (leitura da operação + possibilidades) e formulário em
// blocos, renderizado a partir do catálogo em includes/questions.php —
// front e back leem a MESMA fonte de verdade.
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

bc_session_start();
$csrf = bc_csrf_token();

// Link privado: não indexar em lugar nenhum.
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$blocks    = bc_block_order();
$blockMeta = bc_block_meta();
$questions = bc_questions();

/** Escapa para HTML. */
function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Base da API relativa ao diretório servido pela URL — não ao caminho do
// arquivo no disco. Servido pela ponte /briefing_kauana/index.php isto vira
// "/briefing_kauana/api", que é onde a ponte expõe os endpoints.
$apiBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/api';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="theme-color" content="#0B6B60">
<title>Briefing — sistema de CRM</title>
<style>
:root{
  --paper:#F1F5F6; --surface:#FBFDFD; --surface-2:#E7EEEF;
  --ink:#101F28; --ink-soft:#344A54; --muted:#5E757E;
  --rule:#CFDBDE; --rule-soft:#DFE8EA;
  --accent:#0B6B60; --accent-ink:#075049; --accent-wash:#DDECE9;
  --flag:#A9531F; --flag-wash:#F3E4D9;
  --serif:"Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
  --sans:"Segoe UI Variable Text","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --mono:"Cascadia Mono",Consolas,"SF Mono",ui-monospace,monospace;
}
@media (prefers-color-scheme:dark){
  :root{
    --paper:#0B1317; --surface:#121E23; --surface-2:#182830;
    --ink:#E3EDEF; --ink-soft:#BACBD0; --muted:#8CA3AB;
    --rule:#24373E; --rule-soft:#1B2B32;
    --accent:#4FAFA2; --accent-ink:#7CCBC0; --accent-wash:#12312E;
    --flag:#DB9160; --flag-wash:#2E2018;
  }
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--paper); color:var(--ink);
  font-family:var(--serif); font-size:17px; line-height:1.62;
  padding:clamp(1.5rem,5vw,4.5rem) clamp(1rem,4vw,2rem) 4rem;
  -webkit-font-smoothing:antialiased;
}
.doc{max-width:66ch; margin:0 auto; display:flex; flex-direction:column; gap:3rem}
h1,h2,h3{font-family:var(--sans); margin:0; line-height:1.18; letter-spacing:-.015em; text-wrap:balance}
h1{font-size:clamp(1.9rem,1.3rem+2.5vw,2.75rem); font-weight:650}
h2{font-size:clamp(1.25rem,1.1rem+.7vw,1.55rem); font-weight:620}
h3{font-size:1.05rem; font-weight:640; letter-spacing:-.005em}
p{margin:0}
.eyebrow{font-family:var(--mono); font-size:.6875rem; letter-spacing:.14em; text-transform:uppercase; color:var(--accent); margin:0}

/* ---------- masthead ---------- */
.masthead{display:flex; flex-direction:column; gap:1rem; border-bottom:2px solid var(--ink); padding-bottom:1.4rem}
.standfirst{font-size:1.15rem; line-height:1.55; color:var(--ink-soft)}
.standfirst strong{color:var(--ink); font-weight:600}

section{display:flex; flex-direction:column; gap:1.1rem}
.sec-head{display:flex; flex-direction:column; gap:.4rem}
.lede{color:var(--ink-soft)}

/* ---------- leitura da operação ---------- */
.reading{display:flex; flex-direction:column; gap:0}
.reading .item{padding:1.1rem 0; border-top:1px solid var(--rule-soft); display:flex; flex-direction:column; gap:.4rem}
.reading .item:first-child{border-top:1px solid var(--rule)}
.reading p{color:var(--ink-soft)}

/* ---------- possibilidades ---------- */
.opts{display:flex; flex-direction:column; gap:0}
.opt{display:grid; grid-template-columns:2.4rem 1fr; padding:1.25rem 0; border-top:1px solid var(--rule-soft)}
.opt:first-child{border-top:1px solid var(--rule)}
.opt .n{font-family:var(--mono); font-size:.8125rem; color:var(--accent); padding-top:.2rem; font-variant-numeric:tabular-nums}
.opt .b{display:flex; flex-direction:column; gap:.5rem}
.opt p{color:var(--ink-soft)}
.note{background:var(--surface); border:1px solid var(--rule); border-left:3px solid var(--flag); padding:1.25rem 1.4rem; display:flex; flex-direction:column; gap:.7rem}
.note p{color:var(--ink-soft)}
.note strong{color:var(--ink)}

/* ---------- formulário ---------- */
.form-wrap{background:var(--surface); border:1px solid var(--rule); border-radius:3px; padding:clamp(1.25rem,4vw,2rem); display:flex; flex-direction:column; gap:1.4rem; scroll-margin-top:1rem}
.progress{display:flex; flex-wrap:wrap; gap:.35rem; align-items:center}
.pip{font-family:var(--mono); font-size:.625rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); border:1px solid var(--rule); border-radius:999px; padding:.25rem .6rem; background:transparent}
.pip[data-state="current"]{color:var(--surface); background:var(--accent); border-color:var(--accent)}
.pip[data-state="done"]{color:var(--accent-ink); border-color:var(--accent); background:var(--accent-wash)}
.step{display:none; flex-direction:column; gap:1.4rem}
.step.is-active{display:flex}
.step-head{display:flex; flex-direction:column; gap:.35rem}
.field{display:flex; flex-direction:column; gap:.45rem}
.field > label, .q-label{font-family:var(--sans); font-size:1rem; font-weight:560; line-height:1.4; color:var(--ink)}
.req{color:var(--flag); font-weight:400}
.help{font-size:.9375rem; line-height:1.5; color:var(--muted); font-style:italic}
input[type=text], input[type=email], input[type=tel], textarea, select{
  font-family:var(--sans); font-size:1rem; color:var(--ink);
  background:var(--paper); border:1px solid var(--rule); border-radius:2px;
  padding:.65rem .75rem; width:100%; line-height:1.5;
}
textarea{font-family:var(--serif); font-size:1.0625rem; min-height:6.5rem; resize:vertical}
input:focus-visible, textarea:focus-visible, select:focus-visible{outline:2px solid var(--accent); outline-offset:1px; border-color:var(--accent)}
.choices{display:flex; flex-direction:column; gap:.15rem}
.choice{display:flex; gap:.6rem; align-items:flex-start; padding:.5rem .6rem; border-radius:2px; cursor:pointer; font-family:var(--sans); font-size:.9688rem; line-height:1.45}
.choice:hover{background:var(--surface-2)}
.choice input{margin:.25rem 0 0; accent-color:var(--accent); flex:none; width:1rem; height:1rem}
.choice span{color:var(--ink-soft)}
.choice input:checked + span{color:var(--ink); font-weight:560}
.err{font-family:var(--sans); font-size:.875rem; color:var(--flag); display:none}
.field.has-error .err{display:block}
.field.has-error input, .field.has-error textarea{border-color:var(--flag)}
.hp{position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden}
.actions{display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; padding-top:.4rem; border-top:1px solid var(--rule-soft)}
button{font-family:var(--sans); font-size:.9375rem; font-weight:580; border-radius:2px; padding:.7rem 1.15rem; cursor:pointer; border:1px solid transparent; line-height:1.3}
.btn-primary{background:var(--accent); color:#FBFDFD; border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-ink); border-color:var(--accent-ink)}
.btn-ghost{background:transparent; color:var(--muted); border-color:var(--rule)}
.btn-ghost:hover{color:var(--ink); border-color:var(--muted)}
button:disabled{opacity:.55; cursor:progress}
button:focus-visible{outline:2px solid var(--accent); outline-offset:2px}
.saved{font-family:var(--mono); font-size:.6875rem; letter-spacing:.06em; color:var(--muted); margin-left:auto; opacity:0; transition:opacity .25s}
.saved.show{opacity:1}
.formmsg{font-family:var(--sans); font-size:.9375rem; padding:.7rem .85rem; border-radius:2px; display:none}
.formmsg.show{display:block}
.formmsg.error{background:var(--flag-wash); color:var(--flag)}
.consent{display:flex; gap:.6rem; align-items:flex-start; font-family:var(--sans); font-size:.9063rem; line-height:1.5; color:var(--ink-soft)}
.consent input{margin:.2rem 0 0; accent-color:var(--accent); flex:none; width:1rem; height:1rem}
.done{display:none; flex-direction:column; gap:1rem; text-align:left}
.done.is-active{display:flex}
.done .check{font-family:var(--mono); font-size:.6875rem; letter-spacing:.14em; text-transform:uppercase; color:var(--accent)}
footer{font-family:var(--mono); font-size:.6875rem; letter-spacing:.05em; color:var(--muted); border-top:1px solid var(--rule); padding-top:1rem}
@media (prefers-reduced-motion:reduce){*{transition:none!important; animation:none!important}}
@media (max-width:480px){
  .opt{grid-template-columns:1.9rem 1fr}
  .actions button{flex:1 1 auto}
}
</style>
</head>
<body>
<div class="doc">

  <header class="masthead">
    <p class="eyebrow">Sistema de CRM &middot; documento de trabalho</p>
    <h1>Antes de construir, eu preciso entender o de vocês</h1>
    <p class="standfirst">Coloquei aqui <strong>o que eu entendi da operação de vocês</strong> e algumas possibilidades que eu acho que valem a pena considerar. No fim tem um briefing para você preencher — pode responder em partes, ele salva sozinho e você volta quando puder.</p>
  </header>

  <section>
    <div class="sec-head">
      <p class="eyebrow">Ponto de partida</p>
      <h2>O que eu entendi da operação de vocês</h2>
    </div>
    <p class="lede">Se algo aqui estiver torto, me corrige — é justamente para isso que serve o briefing lá embaixo.</p>
    <div class="reading">
      <div class="item">
        <h3>São duas operações diferentes, não uma</h3>
        <p>Cuidar de quem já é cliente e conquistar cliente novo parecem a mesma coisa, mas se comportam de maneiras opostas. Uma é rotina de relacionamento que não pode esfriar; a outra é um processo com começo, meio e fim. Misturar as duas na mesma tela é o que faz a maioria dos CRMs ficar confuso e acabar abandonado.</p>
      </div>
      <div class="item">
        <h3>O histórico precisa estar à mão antes da ligação</h3>
        <p>Voltar num cliente meses depois só funciona se der para lembrar, em segundos, do que já foi conversado e do que ele demonstrou interesse. Esse é o coração da parte de relacionamento: não é registrar por registrar, é ter contexto na hora da ligação.</p>
      </div>
      <div class="item">
        <h3>O retorno combinado não pode depender da memória</h3>
        <p>Quando o cliente pede para voltar daqui a dois meses, esse compromisso precisa reaparecer sozinho na data certa, com o contexto junto. É simples de descrever e é onde mais se perde negócio.</p>
      </div>
      <div class="item">
        <h3>Você precisa enxergar o time sem ter que perguntar</h3>
        <p>Quem coordena precisa ver em que ponto cada negociação está sem depender de reunião de status ou de mensagem no grupo. Percebi que essa é a sua posição na história: você olha o conjunto, o time opera o dia a dia.</p>
      </div>
      <div class="item">
        <h3>Um mesmo cliente atravessa produtos diferentes</h3>
        <p>Investimentos, consórcio, seguros e internacional convivem na mesma pessoa, em momentos diferentes. O sistema precisa tratar isso como uma relação com várias frentes — não como quatro cadastros soltos da mesma pessoa.</p>
      </div>
    </div>
  </section>

  <section>
    <div class="sec-head">
      <p class="eyebrow">Possibilidades</p>
      <h2>Onde eu acho que dá para ir além</h2>
    </div>
    <p class="lede">Nada disso é obrigatório, e não precisa entrar tudo de uma vez. Estou colocando porque são coisas que custam pouco se forem pensadas agora, e caro se forem lembradas depois. Marque no briefing o que fizer sentido para vocês.</p>

    <div class="opts">
      <div class="opt"><div class="n">01</div><div class="b">
        <h3>Saber quanto vale cada negociação em aberto</h3>
        <p>Se cada negociação carregar o valor que pode ser captado, o painel deixa de mostrar "quantas reuniões" e passa a mostrar "quanto dinheiro". Muda a forma de priorizar o dia: uma conversa inicial com potencial grande passa a aparecer na frente de uma negociação adiantada de valor pequeno.</p>
      </div></div>

      <div class="opt"><div class="n">02</div><div class="b">
        <h3>O dinheiro do cliente que ainda não está com vocês</h3>
        <p>Quase todo cliente mantém parte do patrimônio em outra instituição. Se o cadastro registrar essa diferença, o sistema monta sozinho uma lista de oportunidades dentro da própria base — sem prospecção, sem custo de aquisição, com quem já confia em vocês.</p>
      </div></div>

      <div class="opt"><div class="n">03</div><div class="b">
        <h3>O sistema lembrando no lugar de vocês</h3>
        <p>Boa parte dos bons motivos para ligar já está no dado: aplicação vencendo, consórcio perto da contemplação, seguro renovando, aniversário de aporte, cliente sem contato há muito tempo, saldo caindo. Cada um desses pode virar uma tarefa que aparece sozinha para a pessoa certa, no dia certo — em vez de depender de alguém ter anotado.</p>
      </div></div>

      <div class="opt"><div class="n">04</div><div class="b">
        <h3>Saber de onde vêm os clientes que realmente fecham</h3>
        <p>Registrar a origem de cada cliente novo é uma informação pequena que responde uma pergunta cara: onde vale a pena colocar tempo e dinheiro no semestre que vem. Sem ela, essa decisão continua sendo por impressão.</p>
      </div></div>

      <div class="opt"><div class="n">05</div><div class="b">
        <h3>Enxergar onde o processo trava</h3>
        <p>Contar reuniões mostra atividade, mas não mostra onde as coisas emperram. Uma observação: abrir conta e efetivamente receber o aporte são momentos diferentes, e a distância entre os dois costuma ser onde mais se perde — sem que ninguém veja, porque no quadro os dois viram "fechado".</p>
      </div></div>

      <div class="opt"><div class="n">06</div><div class="b">
        <h3>O que fica com o escritório quando alguém sai</h3>
        <p>Com o histórico centralizado, o relacionamento e o contexto ficam com a casa, e não na cabeça de quem saiu. No mesmo movimento resolve o lado formal: registro de quem falou o quê e quando, que é o tipo de coisa que ninguém quer estar montando às pressas quando for solicitado.</p>
      </div></div>
    </div>

    <div class="note">
      <p><strong>Uma ressalva que eu faço questão de dizer antes:</strong> o maior risco de um projeto desses não é técnico, é de uso. Se registrar der trabalho, o time para de registrar em algumas semanas — e aí o painel que você olha passa a mostrar uma realidade que não existe, o que é pior do que não ter painel nenhum.</p>
      <p>Por isso, se em algum momento eu insistir em cortar funcionalidade para deixar mais simples no celular, é por causa disso. Registrar tem que custar segundos, não minutos.</p>
    </div>
  </section>

  <section id="briefing">
    <div class="sec-head">
      <p class="eyebrow">Briefing</p>
      <h2>Agora me conta como é aí</h2>
    </div>
    <p class="lede">São cinco blocos curtos. Cada bloco salva quando você avança, então dá para parar no meio, fechar, e continuar depois neste mesmo link e neste mesmo aparelho. Onde não souber, pode pular — "não sei" também me diz muita coisa.</p>

    <div class="form-wrap" id="formWrap">

      <div class="progress" id="progress" aria-label="Progresso do briefing">
        <span class="pip" data-step="ident" data-state="current">Você</span>
        <?php foreach ($blocks as $i => $b): ?>
          <span class="pip" data-step="<?= e($b) ?>"><?= e($blockMeta[$b]['title']) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="formmsg error" id="formMsg" role="alert"></div>

      <!-- ---------- passo de identificação ---------- -->
      <div class="step is-active" data-step="ident">
        <div class="step-head">
          <h3>Primeiro, só para eu saber com quem estou falando</h3>
          <p class="help">Seu e-mail é só para te mandar a cópia das respostas.</p>
        </div>

        <div class="field" data-field="nome">
          <label for="f_nome">Seu nome <span class="req">*</span></label>
          <input type="text" id="f_nome" autocomplete="name" maxlength="150">
          <p class="err"></p>
        </div>

        <div class="field" data-field="email">
          <label for="f_email">Seu e-mail <span class="req">*</span></label>
          <input type="email" id="f_email" autocomplete="email" maxlength="150">
          <p class="err"></p>
        </div>

        <div class="field" data-field="whatsapp">
          <label for="f_whats">WhatsApp</label>
          <input type="tel" id="f_whats" autocomplete="tel" maxlength="40" placeholder="com DDD">
          <p class="err"></p>
        </div>

        <div class="field" data-field="escritorio">
          <label for="f_esc">Nome do escritório</label>
          <input type="text" id="f_esc" maxlength="150">
          <p class="err"></p>
        </div>

        <div class="field" data-field="papel">
          <label for="f_papel">Seu papel lá</label>
          <select id="f_papel">
            <option value="">—</option>
            <option>Sócia</option>
            <option>Sócio</option>
            <option>Assessor(a)</option>
            <option>Gestor(a)</option>
            <option>Outro</option>
          </select>
          <p class="err"></p>
        </div>

        <div class="hp" aria-hidden="true">
          <label for="f_site">Não preencha este campo</label>
          <input type="text" id="f_site" tabindex="-1" autocomplete="off">
        </div>

        <div class="field" data-field="consent">
          <label class="consent" for="f_consent">
            <input type="checkbox" id="f_consent">
            <span><?= e(bc_consent_text()) ?></span>
          </label>
          <p class="err"></p>
        </div>

        <div class="actions">
          <button type="button" class="btn-primary" data-action="start">Começar</button>
        </div>
      </div>

      <!-- ---------- blocos de perguntas ---------- -->
      <?php foreach ($blocks as $bi => $block): ?>
        <div class="step" data-step="<?= e($block) ?>">
          <div class="step-head">
            <p class="eyebrow">Bloco <?= $bi + 1 ?> de <?= count($blocks) ?></p>
            <h3><?= e($blockMeta[$block]['title']) ?></h3>
            <?php if (!empty($blockMeta[$block]['intro'])): ?>
              <p class="help"><?= e($blockMeta[$block]['intro']) ?></p>
            <?php endif; ?>
          </div>

          <?php foreach (bc_block_question_keys($block) as $qkey):
              $q    = $questions[$qkey];
              $req  = (bool) ($q['required'] ?? false);
              $type = $q['answer_type'];
              $id   = 'q_' . preg_replace('/[^a-z0-9_]/i', '', $qkey);
          ?>
            <div class="field" data-field="<?= e($qkey) ?>" data-qkey="<?= e($qkey) ?>" data-type="<?= e($type) ?>">
              <?php if (in_array($type, ['single', 'multi'], true)): ?>
                <p class="q-label" id="<?= e($id) ?>_lbl"><?= e($q['question_text']) ?><?= $req ? ' <span class="req">*</span>' : '' ?></p>
                <?php if (!empty($q['help'])): ?><p class="help"><?= e($q['help']) ?></p><?php endif; ?>
                <div class="choices" role="group" aria-labelledby="<?= e($id) ?>_lbl">
                  <?php foreach ($q['options'] as $oi => $opt): ?>
                    <label class="choice">
                      <input type="<?= $type === 'multi' ? 'checkbox' : 'radio' ?>"
                             name="<?= e($qkey) ?>"
                             value="<?= e($opt) ?>">
                      <span><?= e($opt) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($type === 'short'): ?>
                <label for="<?= e($id) ?>"><?= e($q['question_text']) ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
                <?php if (!empty($q['help'])): ?><p class="help"><?= e($q['help']) ?></p><?php endif; ?>
                <input type="text" id="<?= e($id) ?>" maxlength="200">
              <?php else: ?>
                <label for="<?= e($id) ?>"><?= e($q['question_text']) ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
                <?php if (!empty($q['help'])): ?><p class="help"><?= e($q['help']) ?></p><?php endif; ?>
                <textarea id="<?= e($id) ?>" maxlength="4000" rows="4"></textarea>
              <?php endif; ?>
              <p class="err"></p>
            </div>
          <?php endforeach; ?>

          <div class="actions">
            <button type="button" class="btn-ghost" data-action="back">Voltar</button>
            <button type="button" class="btn-primary" data-action="next" data-block="<?= e($block) ?>">
              <?= bc_is_last_block($block) ? 'Enviar briefing' : 'Salvar e continuar' ?>
            </button>
            <span class="saved" data-saved>salvo</span>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- ---------- conclusão ---------- -->
      <div class="done" id="doneStep">
        <p class="check">Recebido</p>
        <h3 id="doneTitle">Prontinho, obrigado!</h3>
        <p class="lede">Acabei de te mandar por e-mail uma cópia de tudo o que você respondeu — dá para levar direto para a conversa com os sócios. Vou ler com calma e te procuro com o desenho da primeira versão.</p>
        <p class="help">Se lembrar de algo depois, é só me chamar que eu complemento aqui.</p>
      </div>

    </div>
  </section>

  <footer>Este link é privado e não é indexado em buscadores &middot; suas respostas ficam salvas para você continuar depois</footer>

</div>

<script>
(function () {
  'use strict';

  var API   = <?= json_encode($apiBase, JSON_UNESCAPED_SLASHES) ?>;
  var CSRF  = <?= json_encode($csrf) ?>;
  var STEPS = <?= json_encode(array_merge(['ident'], $blocks), JSON_UNESCAPED_UNICODE) ?>;
  var LSKEY = 'bc_token_<?= e(BC_FORM_SLUG) ?>';

  var wrap    = document.getElementById('formWrap');
  var msgEl   = document.getElementById('formMsg');
  var doneEl  = document.getElementById('doneStep');
  var pips    = Array.prototype.slice.call(document.querySelectorAll('.pip'));
  var token   = null;
  var current = 'ident';
  var busy    = false;

  /* ---------------- utilidades ---------------- */

  function post(endpoint, payload) {
    payload.csrf = CSRF;
    return fetch(API + '/' + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () {
        throw new Error('Resposta inválida do servidor.');
      }).then(function (j) { return { status: r.status, body: j }; });
    });
  }

  function showMsg(text) {
    msgEl.textContent = text;
    msgEl.classList.add('show');
    msgEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  function clearMsg() { msgEl.classList.remove('show'); msgEl.textContent = ''; }

  function clearErrors(stepEl) {
    (stepEl || wrap).querySelectorAll('.field.has-error').forEach(function (f) {
      f.classList.remove('has-error');
      var e = f.querySelector('.err'); if (e) e.textContent = '';
    });
  }

  function paintErrors(fields) {
    var first = null;
    Object.keys(fields || {}).forEach(function (key) {
      var f = wrap.querySelector('.field[data-field="' + CSS.escape(key) + '"]');
      if (!f) return;
      f.classList.add('has-error');
      var e = f.querySelector('.err');
      if (e) e.textContent = fields[key];
      if (!first) first = f;
    });
    if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function stepEl(name) { return wrap.querySelector('.step[data-step="' + name + '"]'); }

  function go(name) {
    wrap.querySelectorAll('.step').forEach(function (s) { s.classList.remove('is-active'); });
    doneEl.classList.remove('is-active');
    clearMsg();

    if (name === 'done') {
      doneEl.classList.add('is-active');
      pips.forEach(function (p) { p.dataset.state = 'done'; });
    } else {
      var el = stepEl(name);
      if (!el) return;
      el.classList.add('is-active');
      current = name;
      paintPips();
    }
    document.getElementById('briefing').scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  function paintPips(doneList) {
    var idx = STEPS.indexOf(current);
    pips.forEach(function (p) {
      var i = STEPS.indexOf(p.dataset.step);
      if (p.dataset.step === current) p.dataset.state = 'current';
      else if ((doneList && doneList.indexOf(p.dataset.step) >= 0) || i < idx) p.dataset.state = 'done';
      else p.removeAttribute('data-state');
    });
  }

  /* ---------------- leitura / escrita do DOM ---------------- */

  function collect(blockName) {
    var el = stepEl(blockName);
    var out = [];
    el.querySelectorAll('.field[data-qkey]').forEach(function (f) {
      var key = f.dataset.qkey, type = f.dataset.type, value;
      if (type === 'multi') {
        value = Array.prototype.slice
          .call(f.querySelectorAll('input:checked'))
          .map(function (i) { return i.value; });
      } else if (type === 'single') {
        var picked = f.querySelector('input:checked');
        value = picked ? picked.value : '';
      } else {
        var input = f.querySelector('textarea, input[type=text]');
        value = input ? input.value : '';
      }
      out.push({ question_key: key, value: value });
    });
    return out;
  }

  function hydrate(answers) {
    Object.keys(answers || {}).forEach(function (key) {
      var f = wrap.querySelector('.field[data-qkey="' + CSS.escape(key) + '"]');
      if (!f) return;
      var v = answers[key], type = f.dataset.type;
      if (v === null || v === undefined) return;

      if (type === 'multi' && Array.isArray(v)) {
        f.querySelectorAll('input').forEach(function (i) {
          i.checked = v.indexOf(i.value) >= 0;
        });
      } else if (type === 'single') {
        f.querySelectorAll('input').forEach(function (i) { i.checked = (i.value === v); });
      } else {
        var input = f.querySelector('textarea, input[type=text]');
        if (input) input.value = v;
      }
    });
  }

  function flashSaved(blockName) {
    var el = stepEl(blockName);
    var tag = el && el.querySelector('[data-saved]');
    if (!tag) return;
    tag.classList.add('show');
    setTimeout(function () { tag.classList.remove('show'); }, 1800);
  }

  /* ---------------- autosave (rascunho) ---------------- */

  var draftTimer = null;
  function scheduleDraft() {
    if (!token || current === 'ident') return;
    clearTimeout(draftTimer);
    var block = current;
    draftTimer = setTimeout(function () {
      post('save_block.php', {
        session_token: token, block_key: block, answers: collect(block), draft: true
      }).then(function (r) {
        if (r.body && r.body.ok) flashSaved(block);
      }).catch(function () { /* rascunho é best-effort, silencioso */ });
    }, 1200);
  }

  wrap.addEventListener('input', scheduleDraft);
  wrap.addEventListener('change', scheduleDraft);

  // Última chance de salvar ao sair da página.
  window.addEventListener('pagehide', function () {
    if (!token || current === 'ident' || !navigator.sendBeacon) return;
    var payload = JSON.stringify({
      csrf: CSRF, session_token: token, block_key: current,
      answers: collect(current), draft: true
    });
    navigator.sendBeacon(API + '/save_block.php', new Blob([payload], { type: 'application/json' }));
  });

  /* ---------------- ações ---------------- */

  function startBriefing() {
    if (busy) return;
    clearErrors(stepEl('ident'));
    clearMsg();

    var payload = {
      nome:       document.getElementById('f_nome').value,
      email:      document.getElementById('f_email').value,
      whatsapp:   document.getElementById('f_whats').value,
      escritorio: document.getElementById('f_esc').value,
      papel:      document.getElementById('f_papel').value,
      consent:    document.getElementById('f_consent').checked ? 1 : 0,
      website:    document.getElementById('f_site').value
    };

    busy = true;
    var btn = wrap.querySelector('[data-action="start"]');
    btn.disabled = true;

    post('start.php', payload).then(function (r) {
      if (!r.body.ok) {
        if (r.body.error && r.body.error.fields) paintErrors(r.body.error.fields);
        showMsg(r.body.error ? r.body.error.message : 'Não consegui abrir o briefing.');
        return;
      }
      token = r.body.data.session_token;
      try { localStorage.setItem(LSKEY, token); } catch (e) {}
      go(r.body.data.current_block || STEPS[1]);
    }).catch(function () {
      showMsg('Sem conexão com o servidor. Confere a internet e tenta de novo.');
    }).finally(function () {
      busy = false; btn.disabled = false;
    });
  }

  function saveAndAdvance(block, btn) {
    if (busy || !token) return;
    clearErrors(stepEl(block));
    clearMsg();

    busy = true;
    btn.disabled = true;
    clearTimeout(draftTimer); // não deixa o rascunho competir com o envio

    post('save_block.php', {
      session_token: token, block_key: block, answers: collect(block), draft: false
    }).then(function (r) {
      if (!r.body.ok) {
        if (r.body.error && r.body.error.fields) paintErrors(r.body.error.fields);
        showMsg(r.body.error ? r.body.error.message : 'Não consegui salvar.');
        return;
      }
      var d = r.body.data;
      if (d.is_last) return finishBriefing(btn);
      paintPips(d.completed_blocks);
      go(d.current_block);
    }).catch(function () {
      showMsg('Sem conexão com o servidor. Suas respostas continuam aqui na tela — tenta de novo.');
    }).finally(function () {
      busy = false; btn.disabled = false;
    });
  }

  function finishBriefing(btn) {
    return post('finish.php', { session_token: token }).then(function (r) {
      if (!r.body.ok) {
        var err = r.body.error || {};
        if (err.fields && err.fields._block) {
          showMsg(err.message);
          go(err.fields._block);
        } else {
          showMsg(err.message || 'Não consegui enviar.');
        }
        return;
      }
      try { localStorage.removeItem(LSKEY); } catch (e) {}
      var nome = (r.body.data.nome || '').split(' ')[0];
      if (nome) document.getElementById('doneTitle').textContent = 'Prontinho, ' + nome + '. Obrigado!';
      go('done');
    });
  }

  wrap.addEventListener('click', function (ev) {
    var btn = ev.target.closest('button[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;

    if (action === 'start') return startBriefing();
    if (action === 'next')  return saveAndAdvance(btn.dataset.block, btn);
    if (action === 'back') {
      var i = STEPS.indexOf(current);
      if (i > 0) go(STEPS[i - 1]);
    }
  });

  /* ---------------- retomada ---------------- */

  (function resume() {
    var saved = null;
    try { saved = localStorage.getItem(LSKEY); } catch (e) {}
    if (!saved) return;

    post('start.php', { session_token: saved }).then(function (r) {
      if (!r.body.ok || !r.body.data || !r.body.data.session_token) {
        try { localStorage.removeItem(LSKEY); } catch (e) {}
        return;
      }
      var d = r.body.data;
      if (d.status === 'completed') {
        try { localStorage.removeItem(LSKEY); } catch (e) {}
        return;
      }
      token = d.session_token;
      hydrate(d.answers);
      go(d.current_block || STEPS[1]);
      paintPips(d.completed_blocks);
    }).catch(function () { /* falha na retomada: começa do zero, sem alarde */ });
  })();

})();
</script>
</body>
</html>
