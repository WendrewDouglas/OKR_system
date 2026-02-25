<?php
session_start();

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Flash
$error   = $_SESSION['error_message']   ?? '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

$old = $_SESSION['old_inputs'] ?? [];
unset($_SESSION['old_inputs']);

// ==== Lista de avatares (lidos do servidor) ====
// Diretório físico dos modelos
$fsAvatarDir = realpath(__DIR__ . '/../assets/img/avatars/default_avatar');
$webAvatarBase = '/OKR_system/assets/img/avatars/default_avatar/';
$avatars = [];

// Coleta PNGs; regras: default.png (all), prefixo "user" => masculino, "fem" => feminino
if ($fsAvatarDir && is_dir($fsAvatarDir)) {
  foreach (glob($fsAvatarDir . '/*.png') as $abs) {
    $fname = basename($abs);
    $lc = strtolower($fname);
    if ($lc === 'default.png') {
      $gender = 'all';
    } elseif (str_starts_with($lc, 'fem')) {
      $gender = 'feminino';
    } elseif (str_starts_with($lc, 'user')) {
      $gender = 'masculino';
    } else {
      $gender = 'all';
    }
    $avatars[] = ['file' => $fname, 'gender' => $gender];
  }
}
// Garante ao menos default
if (!array_filter($avatars, fn($a)=> strtolower($a['file']) === 'default.png')) {
  $avatars[] = ['file' => 'default.png', 'gender' => 'all'];
}

// Valor inicial do hidden
$initialAvatar = $old['avatar_file'] ?? 'default.png';
if (!preg_match('/^[a-z0-9_.-]+\.png$/i', $initialAvatar)) $initialAvatar = 'default.png';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cadastre-se – OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">

<style>
:root{
  --okr-bg1: var(--bg1, #222222);
  --okr-bg2: var(--bg2, #FDB900);
  --okr-text: #1f2937;
  --okr-muted: #6b7280;
  --okr-border: #e5e7eb;
  --okr-success: #10b981;
  --okr-danger: #ef4444;
  --okr-warning:#f59e0b;
  --okr-focus: #2563eb;
  --okr-surface:#ffffff;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0; padding:2rem 1rem;
  background: linear-gradient(180deg, var(--okr-bg2) 0%, var(--okr-bg1) 100%);
  color:var(--okr-text);
  display:flex; flex-direction:column; align-items:center;
}
.logo-top{ text-align:center; margin-bottom:1rem }
.logo-top .logo{ max-width:280px; height:auto }

.wrap{ width:100%; max-width:1100px; }

.card{
  background:var(--okr-surface);
  border-radius:14px;
  box-shadow: 0 10px 30px rgba(0,0,0,.12);
  border:1px solid #0000;
  display:flex; flex-direction:column;
  overflow: visible;
}
.card-head{ padding:1.25rem 1.25rem .5rem; text-align:center; }
.card-head h2{ margin:.25rem 0 .25rem; font-size:1.6rem }
.card-head p{ margin:0; color:var(--okr-muted) }

.card-body{ padding: 1rem 1.25rem 0.5rem; }

/* GRID 3 painéis */
.grid-3{
  display:grid; grid-template-columns: repeat(3, 1fr); gap:1.25rem;
}
@media (max-width: 1100px){ .grid-3{ grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 760px){ .grid-3{ grid-template-columns: 1fr; } }

.panel{
  border:1px solid var(--okr-border);
  border-radius:12px;
  padding:1rem;
}
.panel h3{ margin:.25rem 0 1rem; font-size:1.05rem }

.field{ margin-bottom:.9rem; }
label{ display:block; font-weight:600; margin-bottom:.35rem }
input[type="text"],
input[type="email"],
input[type="tel"],
input[type="password"],
select{
  width:100%; padding:.7rem .9rem;
  border:1px solid var(--okr-border); border-radius:10px;
  outline:none; transition:border .15s, box-shadow .15s; background:#fff;
}
input:focus, select:focus{
  border-color: var(--okr-focus);
  box-shadow: 0 0 0 3px rgba(37,99,235,.15);
}
.help{ font-size:.8rem; color:var(--okr-muted); margin-top:.25rem }
.inline{ display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media (max-width:640px){ .inline{ grid-template-columns:1fr } }

.input-icon{ position:relative; }
.input-icon .addon{
  position:absolute; right:.5rem; top:50%; transform:translateY(-50%);
  display:inline-flex; align-items:center; gap:.35rem;
  font-size:1rem; color:#6b7280; cursor:pointer; user-select:none;
}
.input-icon input{ padding-right:2.7rem }

.alert{
  width:100%; max-width:1100px; margin:.5rem auto 1rem;
  padding:.8rem 1rem; border-radius:10px; font-size:.95rem; border:1px solid;
}
.alert-error{ background:#fef2f2; color:#991b1b; border-color:#fecaca }
.alert-success{ background:#ecfdf5; color:#065f46; border-color:#a7f3d0 }

/* password */
.ps-meter{ height:8px; background:#f3f4f6; border-radius:999px; overflow:hidden; margin:.5rem 0; }
.ps-meter > span{ display:block; height:100%; width:0%; background:linear-gradient(90deg, #f43f5e, #f59e0b, #10b981); transition:width .25s ease; }
.ps-checks{ display:grid; grid-template-columns:1fr 1fr; gap:.35rem .75rem; margin:.35rem 0 0; font-size:.85rem; }
.ps-checks .ok{ color:var(--okr-success) } .ps-checks .no{ color:var(--okr-muted) }
.caps{ color:var(--okr-warning); font-size:.82rem; display:none }

/* email verification */
.email-actions{ display:flex; gap:.5rem; margin-top:.5rem; flex-wrap:wrap; }
.email-actions .btn-sec{ padding:.55rem .9rem; border-radius:10px; border:1px solid var(--okr-border); background:#fafafa; cursor:pointer }
.email-actions .btn-sec[disabled]{ opacity:.6; cursor:not-allowed }
.code-row{ display:grid; grid-template-columns: 1fr auto; gap:.5rem; align-items:center; margin-top:.5rem; }
.badge{ display:inline-block; font-size:.8rem; padding:.2rem .5rem; border-radius:999px; background:#f3f4f6; color:#374151; border:1px solid var(--okr-border); }
.status-ok{ color:var(--okr-success); }
.status-warn{ color:#b45309; }

/* terms */
.terms-row{ display:flex; align-items:flex-start; gap:.5rem; margin-top:.5rem; }
.terms-row input{ margin-top:.25rem }
.terms-link{ color:#111827; text-decoration:underline; cursor:pointer }

/* footer ESTÁTICO */
.card-foot{
  background: #fff;
  padding:1rem 1.25rem 1.25rem;
  display:flex; flex-direction:column; align-items:center; gap:.5rem;
  border-top: 1px solid var(--okr-border);
}
.btn{
  appearance:none; border:none; border-radius:12px;
  padding:.9rem 1.25rem; font-weight:700; font-size:1rem;
  background: linear-gradient(90deg, var(--okr-bg2), #ffcc33);
  color:#111827; cursor:pointer; min-width:220px;
  box-shadow: 0 6px 20px rgba(0,0,0,.12);
  transition: transform .05s ease;
}
.btn:hover{ transform: translateY(-1px) }
.btn[disabled]{ opacity:.6; cursor:not-allowed }
.link{ color:#111827; text-decoration:underline; }

/* modal */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.55);
  display:none; align-items:center; justify-content:center; padding:1rem; z-index:99;
}
.modal{
  background:#fff; color:#111; width:100%; max-width:860px;
  max-height:80vh; overflow:auto; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.35);
  border:1px solid var(--okr-border);
}
.modal header{ padding:1rem 1.25rem; border-bottom:1px solid var(--okr-border); display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:1; }
.modal .content{ padding:1rem 1.25rem 1.25rem; }
.modal .close{ background:#f3f4f6; border:1px solid var(--okr-border); padding:.4rem .6rem; border-radius:8px; cursor:pointer }
.modal h4{ margin:.25rem 0 0; font-size:1.1rem }
.modal h5{ margin:1rem 0 .25rem; font-size:1rem }
.modal p, .modal li{ line-height:1.55 }
.modal ul{ padding-left:1.25rem }

/* === Avatar picker (grid 3x2 com paginação) === */
.avatar-picker { margin-bottom: 1rem; }
.avatar-toolbar{
  display:flex; gap:.5rem; align-items:center; justify-content:space-between; margin-bottom:.5rem;
}
.avatar-filters { display:flex; gap:.4rem; flex-wrap:wrap; }
.avatar-filters .chip{
  padding:.35rem .7rem; border:1px solid var(--okr-border); background:#fafafa; border-radius:999px; cursor:pointer;
}
.avatar-filters .chip.active{ border-color:var(--okr-focus); box-shadow:0 0 0 2px rgba(37,99,235,.15) inset; }

.avatar-grid-wrap{
  border:1px dashed var(--okr-border);
  border-radius:12px;
  background:#fff;
  padding:.6rem;
}
.avatar-grid{
  display:grid;
  grid-template-columns: repeat(3, 1fr);
  gap:.75rem;
  min-height: 180px;
}
@media (max-width:640px){
  .avatar-grid{ grid-template-columns: repeat(2, 1fr); }
}
.avatar-item{
  border:2px solid transparent;
  border-radius:12px;
  background:#f9fafb;
  display:flex; align-items:center; justify-content:center;
  padding:.5rem; cursor:pointer;
}
.avatar-item img{
  width:80px; height:80px; border-radius:50%; display:block;
}
.avatar-item.selected{
  border-color: var(--okr-focus);
  box-shadow: 0 0 0 3px rgba(37,99,235,.15);
  background:#fff;
}
.avatar-empty{ color:var(--okr-muted); padding:.5rem; }
.avatar-pager{
  display:flex; align-items:center; justify-content:space-between;
  margin-top:.6rem; gap:.5rem; flex-wrap:wrap;
}
.avatar-pager .btn-pg{
  padding:.45rem .8rem; border-radius:10px; border:1px solid var(--okr-border);
  background:#fafafa; cursor:pointer;
}
.avatar-pager .btn-pg[disabled]{ opacity:.6; cursor:not-allowed; }
.avatar-pager .meta{ font-size:.85rem; color:var(--okr-muted); }
</style>
</head>
<body>

  <div class="logo-top">
    <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
      <img class="logo" src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol.png" alt="PlanningBI">
    </a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert" aria-live="assertive"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success" role="status" aria-live="polite">
      <?= htmlspecialchars($success) ?>
      <div style="margin-top:.5rem">
        <a class="link" href="https://planningbi.com.br/OKR_system/login">Fazer login</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="wrap card" role="region" aria-label="Formulário de cadastro">
    <div class="card-head">
      <h2>Crie sua conta</h2>
      <p>Acesse o OKR System para gerir objetivos, KRs e resultados.</p>
    </div>

    <form
      action="/OKR_system/auth/auth_register.php"
      method="POST"
      id="registerForm"
      novalidate
      autocomplete="off"
      aria-describedby="formHelp"
    >
      <!-- Honeypot -->
      <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

      <!-- CSRF -->
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

      <!-- Email verification -->
      <input type="hidden" name="email_verify_token" id="email_verify_token" value="">
      <input type="hidden" name="email_verified" id="email_verified" value="0">

      <!-- Avatar escolhido -->
      <input type="hidden" name="avatar_file" id="avatar_file" value="<?= htmlspecialchars($initialAvatar) ?>">

      <div class="card-body">
        <div class="grid-3">

          <!-- Painel 1: Dados + Avatar + E-mail (com verificação) -->
          <section class="panel" aria-labelledby="sec-pessoal">
            <h3 id="sec-pessoal">1) Seus dados & E-mail</h3>

            <div class="inline">
              <div class="field">
                <label for="primeiro_nome">Primeiro nome *</label>
                <input type="text" id="primeiro_nome" name="primeiro_nome"
                       placeholder="Ex.: Maria"
                       value="<?= htmlspecialchars($old['primeiro_nome'] ?? '') ?>"
                       required aria-describedby="help_primeiro">
                <div id="help_primeiro" class="help">Como você quer ser chamado(a) no sistema.</div>
              </div>

              <div class="field">
                <label for="ultimo_nome">Último nome</label>
                <input type="text" id="ultimo_nome" name="ultimo_nome"
                       placeholder="Ex.: Silva"
                       value="<?= htmlspecialchars($old['ultimo_nome'] ?? '') ?>"
                       aria-describedby="help_ultimo">
                <div id="help_ultimo" class="help">Sobrenome (opcional).</div>
              </div>
            </div>

            <div class="field">
              <label for="email_corporativo">E-mail corporativo *</label>
              <input type="email" id="email_corporativo" name="email_corporativo"
                     placeholder="seu@empresa.com"
                     value="<?= htmlspecialchars($old['email_corporativo'] ?? '') ?>"
                     required
                     pattern="[^@\s]+@[^@\s]+\.[^@\s]+"
                     title="Digite um e-mail no formato nome@domínio.com"
                     aria-describedby="help_email">
              <div id="help_email" class="help">Enviaremos um código de verificação para este e-mail.</div>

              <div class="email-actions">
                <button type="button" id="btnSendEmailCode" class="btn-sec">Enviar código por e-mail</button>
                <span id="emailSendStatus" class="help"></span>
              </div>

              <div id="emailCodeWrapper" class="code-row" style="display:none;">
                <input type="text" id="email_codigo" inputmode="numeric" pattern="[0-9]*" maxlength="5" placeholder="Código de 5 dígitos">
                <button type="button" id="btnCheckEmailCode" class="btn-sec">Validar código</button>
              </div>
              <div id="emailCodeHelp" class="help"></div>
              <div id="emailBadge" class="help"></div>
            </div>

            <!-- Avatar Picker -->
            <div class="avatar-picker" aria-label="Selecionar avatar">
              <div class="avatar-toolbar">
                <div>
                  <strong>Avatar</strong>
                  <div class="help">Escolha um avatar para te representar.</div>
                </div>
                <div class="avatar-filters" role="tablist" aria-label="Filtro de avatares">
                  <button type="button" class="chip active" data-filter="todos" role="tab" aria-selected="true">Todos</button>
                  <button type="button" class="chip" data-filter="masculino" role="tab" aria-selected="false">Masc</button>
                  <button type="button" class="chip" data-filter="feminino" role="tab" aria-selected="false">Fem</button>
                </div>
              </div>

              <div class="avatar-grid-wrap">
                <div id="avatarGrid" class="avatar-grid" aria-live="polite">
                  <div class="avatar-empty">Carregando avatares…</div>
                </div>

                <div class="avatar-pager">
                  <button type="button" id="pgPrev" class="btn-pg" disabled>◀</button>
                  <div class="meta" id="pgMeta">Mostrando 0–0 de 0</div>
                  <button type="button" id="pgNext" class="btn-pg" disabled>▶</button>
                </div>
              </div>
            </div>

          </section>

          <!-- Painel 2: Empresa & Contato -->
          <section class="panel" aria-labelledby="sec-empresa">
            <h3 id="sec-empresa">2) Empresa & Contato</h3>

            <div class="field">
              <label for="empresa">Empresa *</label>
              <input type="text" id="empresa" name="empresa"
                     placeholder="Nome da sua organização"
                     value="<?= htmlspecialchars($old['empresa'] ?? '') ?>"
                     required aria-describedby="help_empresa">
              <div id="help_empresa" class="help">Configuramos seu espaço automaticamente.</div>
            </div>

            <div class="field">
              <label for="faixa_qtd_funcionarios">Faixa de funcionários</label>
              <select id="faixa_qtd_funcionarios" name="faixa_qtd_funcionarios" aria-describedby="help_faixa">
                <option value="">Selecione</option>
                <option value="1–100"   <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='1–100')   ? 'selected' : '' ?>>1–100</option>
                <option value="101–500" <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='101–500') ? 'selected' : '' ?>>101–500</option>
                <option value="501–1000"<?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='501–1000')? 'selected' : '' ?>>501–1000</option>
                <option value="1001+"   <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='1001+')   ? 'selected' : '' ?>>1001+</option>
              </select>
              <div id="help_faixa" class="help">Ajuda a dimensionar a implantação (opcional).</div>
            </div>

            <div class="field">
              <label for="telefone">Telefone (WhatsApp) *</label>
              <input type="tel" id="telefone" name="telefone"
                     placeholder="(XX) 9XXXX-XXXX"
                     title="Formato: (XX) 99999-9999"
                     value="<?= htmlspecialchars($old['telefone'] ?? '') ?>"
                     required aria-describedby="help_tel">
              <div id="help_tel" class="help">
                Diversos alertas e lembretes de apontamentos de OKRs são enviados via WhatsApp
                e podem ser configurados posteriormente nas preferências do usuário.
              </div>
            </div>
          </section>

          <!-- Painel 3: Acesso & Segurança -->
          <section class="panel" aria-labelledby="sec-acesso">
            <h3 id="sec-acesso">3) Acesso & Segurança</h3>

            <div class="field">
              <label for="senha">Senha *</label>
              <div class="input-icon">
                <input type="password"
                       id="senha" name="senha"
                       placeholder="Crie uma senha forte"
                       autocomplete="new-password"
                       required minlength="8"
                       pattern="(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$"
                       aria-describedby="help_senha ps_checks ps_caps">
                <button type="button" class="addon toggle-password" data-target="#senha" aria-label="Mostrar senha">👁️</button>
              </div>
              <div class="caps" id="ps_caps" aria-live="polite">Caps Lock ativado</div>
              <div class="ps-meter" aria-hidden="true"><span id="ps_bar"></span></div>
              <div id="ps_checks" class="ps-checks" aria-live="polite">
                <span id="pc_len" class="no">8+ caracteres</span>
                <span id="pc_up"  class="no">Letra maiúscula</span>
                <span id="pc_lo"  class="no">Letra minúscula</span>
                <span id="pc_num" class="no">Número</span>
                <span id="pc_sym" class="no">Símbolo</span>
              </div>
              <div id="help_senha" class="help">Dica: combine frases, números e símbolos.</div>
            </div>

            <div class="field">
              <label for="senha_confirm">Confirmar senha *</label>
              <div class="input-icon">
                <input type="password"
                       id="senha_confirm" name="senha_confirm"
                       placeholder="Repita a senha"
                       autocomplete="new-password"
                       required aria-describedby="ps_feedback">
                <button type="button" class="addon toggle-password" data-target="#senha_confirm" aria-label="Mostrar senha">👁️</button>
              </div>
              <div id="ps_feedback" class="help" aria-live="polite"></div>
            </div>

            <div class="field terms-row">
              <input type="checkbox" id="terms_accept" required>
              <label for="terms_accept">Li e concordo com os <span class="terms-link" id="openTerms">Termos de Uso</span> *</label>
            </div>
          </section>

        </div>
      </div>

      <div class="card-foot">
        <button type="submit" class="btn" id="submitBtn">Criar conta</button>
        <a class="link" href="https://planningbi.com.br/OKR_system/login">Já tenho conta – Entrar</a>
        <div id="formHelp" class="help" aria-live="polite"></div>
      </div>
    </form>
  </div>

  <!-- Modal Termos -->
  <div class="modal-backdrop" id="modalBackdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
    <div class="modal" role="document">
      <header>
        <h4 id="termsTitle">Termos de Uso da Plataforma ⟨PlanningBI – OKR System⟩</h4>
        <button class="close" id="closeTerms" aria-label="Fechar">Fechar</button>
      </header>
      <div class="content">
        <p><em>Última atualização: 03 de setembro de 2025</em></p>
        <h5>1) Quem somos</h5>
        <p>Estes Termos regulam o uso da plataforma ⟨PlanningBI – OKR System⟩ (“Plataforma”), disponibilizada por ⟨RAZÃO SOCIAL⟩, CNPJ ⟨XX.XXX.XXX/0001-XX⟩ (“Fornecedor”). Ao usar a Plataforma, você concorda com estes Termos e com a Política de Privacidade.</p>
        <h5>2) Definições</h5>
        <ul>
          <li><strong>Cliente</strong>: empresa contratante do serviço.</li>
          <li><strong>Usuário</strong>: pessoa autorizada a acessar a conta do Cliente.</li>
          <li><strong>Conteúdo do Cliente</strong>: dados e documentos inseridos pelo Cliente/Usuários.</li>
          <li><strong>Dados Pessoais</strong>: conforme LGPD (Lei nº 13.709/2018).</li>
        </ul>
        <h5>3) Cadastro e segurança</h5>
        <p>Você deve fornecer informações verdadeiras, manter credenciais em sigilo e nos avisar sobre uso indevido da conta.</p>
        <h5>4) Licença e propriedade</h5>
        <p>Concedemos licença limitada de uso. É vedado burlar limitações técnicas, realizar engenharia reversa, revender acesso sem autorização, ou violar a lei.</p>
        <h5>5) Privacidade e LGPD</h5>
        <p>Tratamos dados conforme a LGPD, respeitando princípios de finalidade, necessidade e segurança. O Cliente é Controlador do Conteúdo do Cliente; atuamos como Operador nesse escopo. Somos Controladores dos dados cadastrais e contratuais. Direitos dos titulares (acesso, correção, eliminação etc.) podem ser exercidos via ⟨e-mail do DPO⟩.</p>
        <h5>6) Suporte e disponibilidade</h5>
        <p>Prestamos suporte em ⟨canal/horário⟩. Podem ocorrer janelas de manutenção e alterações de funcionalidades.</p>
        <h5>7) Limitação de responsabilidade</h5>
        <p>Responsabilidade limitada aos valores pagos nos 12 meses anteriores. Não respondemos por lucros cessantes ou danos indiretos.</p>
        <h5>8) Encerramento</h5>
        <p>O acesso pode ser suspenso por violação dos Termos ou inadimplência. O Cliente pode encerrar conforme política do plano.</p>
        <h5>9) Foro</h5>
        <p>Aplica-se a lei brasileira. Foro eleito: ⟨Araçatuba/SP⟩.</p>
        <p><em>Complete os campos ⟨⟩ e publique a Política de Privacidade e o Aviso de Cookies.</em></p>
      </div>
    </div>
  </div>

<script>
// Disponibiliza a lista de avatares do servidor para o JS
window.AVATAR_DATA = {
  base: <?= json_encode($webAvatarBase, JSON_UNESCAPED_SLASHES) ?>,
  avatars: <?= json_encode($avatars, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
};

(function(){
  const csrf = '<?= $_SESSION['csrf_token'] ?>';
  const form = document.getElementById('registerForm');
  const submitBtn = document.getElementById('submitBtn');

  // Campos senha
  const elSenha   = document.getElementById('senha');
  const elConfirm = document.getElementById('senha_confirm');
  const elFeed    = document.getElementById('ps_feedback');
  const bar       = document.getElementById('ps_bar');
  const caps      = document.getElementById('ps_caps');

  // Toggle senha
  document.querySelectorAll('.toggle-password').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const targetSel = btn.getAttribute('data-target');
      const input = document.querySelector(targetSel);
      if (!input) return;
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      btn.textContent = isPass ? '🙈' : '👁️';
      btn.setAttribute('aria-label', isPass ? 'Esconder senha' : 'Mostrar senha');
    });
  });

  // Máscara telefone (WhatsApp)
  const tel = document.getElementById('telefone');
  if (tel){
    tel.addEventListener('input', function(){
      let d = this.value.replace(/\D/g,'').slice(0,11);
      let f = '';
      if (d.length>0)  f += '(' + d.substring(0,2);
      if (d.length>=3) f += ') ' + d.substring(2,7);
      if (d.length>=8) f += '-' + d.substring(7);
      this.value = f;
    });
  }

  // Caps Lock flag
  function handleCaps(e){
    if (typeof e.getModifierState === 'function') {
      const on = e.getModifierState('CapsLock');
      caps.style.display = on ? 'block' : 'none';
    }
  }
  elSenha.addEventListener('keydown', handleCaps);
  elSenha.addEventListener('keyup', handleCaps);

  // Força da senha + checklist
  const pc_len = document.getElementById('pc_len');
  const pc_up  = document.getElementById('pc_up');
  const pc_lo  = document.getElementById('pc_lo');
  const pc_num = document.getElementById('pc_num');
  const pc_sym = document.getElementById('pc_sym');

  function passScore(s){
    let score = 0;
    const hasLen = s.length >= 8;
    const hasUp  = /[A-Z]/.test(s);
    const hasLo  = /[a-z]/.test(s);
    const hasNum = /\d/.test(s);
    const hasSym = /\W/.test(s);

    pc_len.className = hasLen ? 'ok' : 'no';
    pc_up.className  = hasUp  ? 'ok' : 'no';
    pc_lo.className  = hasLo  ? 'ok' : 'no';
    pc_num.className = hasNum ? 'ok' : 'no';
    pc_sym.className = hasSym ? 'ok' : 'no';

    score = [hasLen,hasUp,hasLo,hasNum,hasSym].filter(Boolean).length;
    return score; // 0..5
  }
  function updateBar(score){ bar.style.width = ((score/5)*100) + '%'; }
  function updateMatch(){
    const s = elSenha.value, c = elConfirm.value;
    if (!c){
      elFeed.textContent = '';
      return;
    }
    if (s === c){
      elFeed.textContent = '✅ Senhas conferem.';
      elFeed.style.color = '#065f46';
    } else {
      elFeed.textContent = '❌ Senhas não coincidem.';
      elFeed.style.color = '#b91c1c';
    }
  }
  elSenha.addEventListener('input', ()=>{ updateBar(passScore(elSenha.value)); updateMatch(); });
  elConfirm.addEventListener('input', updateMatch);

  // ===== Verificação por E-MAIL (5 dígitos) =====
  const btnSendEmailCode = document.getElementById('btnSendEmailCode');
  const btnCheckEmailCode = document.getElementById('btnCheckEmailCode');
  const emailSendStatus = document.getElementById('emailSendStatus');
  const emailCodeWrapper = document.getElementById('emailCodeWrapper');
  const emailCodeInput = document.getElementById('email_codigo');
  const emailCodeHelp = document.getElementById('emailCodeHelp');
  const emailBadge = document.getElementById('emailBadge');
  const emailToken = document.getElementById('email_verify_token');
  const emailVerified = document.getElementById('email_verified');
  const elEmail = document.getElementById('email_corporativo');

  let resendIn = 0, resendTimer = null;

  function setResend(seconds){
    clearInterval(resendTimer);
    resendIn = seconds;
    btnSendEmailCode.disabled = true;
    emailSendStatus.textContent = 'Código enviado. Reenvio em '+resendIn+'s.';
    resendTimer = setInterval(()=>{
      resendIn--;
      if (resendIn <= 0){
        clearInterval(resendTimer);
        btnSendEmailCode.disabled = false;
        emailSendStatus.textContent = 'Você pode reenviar o código.';
      } else {
        emailSendStatus.textContent = 'Código enviado. Reenvio em '+resendIn+'s.';
      }
    }, 1000);
  }

  btnSendEmailCode.addEventListener('click', async ()=>{
    emailBadge.innerHTML = '';
    emailCodeHelp.textContent = '';
    const email = (elEmail.value||'').trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
      emailSendStatus.textContent = 'Informe um e-mail válido.';
      return;
    }
    btnSendEmailCode.disabled = true;
    emailSendStatus.textContent = 'Enviando código...';
    try {
      const resp = await fetch('/OKR_system/auth/email_verify_request.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Content-Type':'application/json',
          'Accept':'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify({ email })
      });
      const data = await resp.json().catch(()=> ({}));
      if (resp.ok && data && data.ok){
        emailToken.value = data.token || '';
        emailCodeWrapper.style.display = 'grid';
        emailCodeInput.value = '';
        emailCodeInput.focus();
        setResend(data.ttl || 120);
        emailSendStatus.textContent = 'Código enviado para seu e-mail.';
      } else {
        btnSendEmailCode.disabled = false;
        emailSendStatus.textContent = (data && data.error) ? data.error : 'Falha ao enviar código. Tente novamente.';
      }
    } catch (e){
      btnSendEmailCode.disabled = false;
      emailSendStatus.textContent = 'Não foi possível contatar o servidor.';
    }
  });

  btnCheckEmailCode.addEventListener('click', async ()=>{
    emailCodeHelp.textContent = '';
    const code = (emailCodeInput.value||'').trim();
    const token = emailToken.value;
    if (!token){
      emailCodeHelp.textContent = 'Solicite o envio do código primeiro.';
      return;
    }
    if (!/^\d{5}$/.test(code)){
      emailCodeHelp.textContent = 'Digite o código numérico de 5 dígitos.';
      return;
    }
    btnCheckEmailCode.disabled = true;
    btnSendEmailCode.disabled = true;
    emailCodeHelp.textContent = 'Validando código...';
    try {
      const resp = await fetch('/OKR_system/auth/email_verify_check.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Content-Type':'application/json',
          'Accept':'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify({ token, code })
      });
      const data = await resp.json().catch(()=> ({}));
      if (resp.ok && data && data.ok){
        emailVerified.value = '1';
        emailCodeHelp.textContent = '';
        emailBadge.innerHTML = '<span class="badge status-ok">✅ E-mail verificado</span>';
        elEmail.readOnly = true;
        emailCodeInput.readOnly = true;
        btnCheckEmailCode.disabled = true;
        btnSendEmailCode.disabled = true;
        emailSendStatus.textContent = 'E-mail verificado com sucesso.';
        clearInterval(resendTimer);
      } else {
        emailVerified.value = '0';
        btnCheckEmailCode.disabled = false;
        btnSendEmailCode.disabled = false;
        emailCodeHelp.textContent = (data && data.error) ? data.error : 'Código inválido. Tente novamente.';
      }
    } catch(e){
      emailVerified.value = '0';
      btnCheckEmailCode.disabled = false;
      btnSendEmailCode.disabled = false;
      emailCodeHelp.textContent = 'Erro de comunicação. Tente novamente.';
    }
  });

  // ===== Avatar Picker (grid 3x2, 6 por página) =====
  const avatarHidden   = document.getElementById('avatar_file');
  const avatarGrid     = document.getElementById('avatarGrid');
  const avatarFilters  = document.querySelectorAll('.avatar-filters .chip');
  const pgPrev         = document.getElementById('pgPrev');
  const pgNext         = document.getElementById('pgNext');
  const pgMeta         = document.getElementById('pgMeta');

  let avatarsData = window.AVATAR_DATA || { base:'', avatars:[] };
  let currentFilter = 'todos';
  const pageSize = 6; // 3 colunas x 2 linhas
  let page = 1;

  function shuffle(arr){
    for(let i=arr.length-1;i>0;i--){
      const j = Math.floor(Math.random()*(i+1));
      [arr[i],arr[j]] = [arr[j],arr[i]];
    }
    return arr;
  }

  // Coloca default.png em primeiro; restante embaralhado
  function prepareAvatars(list){
    const def = list.find(a => a.file.toLowerCase() === 'default.png');
    const rest = list.filter(a => a.file.toLowerCase() !== 'default.png');
    shuffle(rest);
    const out = def ? [def, ...rest] : rest;
    if (!def) out.unshift({file:'default.png', gender:'all'});
    return out;
  }

  function filteredAvatars(){
    if (currentFilter === 'todos') return avatarsData.avatars.slice();
    // em filtros específicos, não inclui "all" (default)
    return avatarsData.avatars.filter(a => a.gender === currentFilter);
  }

  function renderPage(){
    const all = filteredAvatars();
    const total = all.length;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (page > totalPages) page = totalPages;

    const startIdx = (page - 1) * pageSize;
    const slice = all.slice(startIdx, startIdx + pageSize);

    avatarGrid.innerHTML = '';
    if (!slice.length){
      avatarGrid.innerHTML = '<div class="avatar-empty">Nenhum avatar para este filtro.</div>';
    } else {
      slice.forEach(item=>{
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'avatar-item';
        btn.setAttribute('data-file', item.file);
        btn.setAttribute('data-gender', item.gender);
        btn.title = item.file;

        const img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = 'Avatar';
        img.src = avatarsData.base + item.file;

        btn.appendChild(img);

        const selectedFile = (avatarHidden.value || 'default.png').toLowerCase();
        if (selectedFile === item.file.toLowerCase()){
          btn.classList.add('selected');
        }
        btn.addEventListener('click', ()=>{
          avatarGrid.querySelectorAll('.avatar-item.selected').forEach(el=>el.classList.remove('selected'));
          btn.classList.add('selected');
          avatarHidden.value = item.file;
        });

        avatarGrid.appendChild(btn);
      });
    }

    const from = total ? (startIdx + 1) : 0;
    const to   = Math.min(startIdx + slice.length, total);
    pgMeta.textContent = `Mostrando ${from}–${to} de ${total} (página ${page}/${Math.max(1, Math.ceil(total/pageSize))})`;

    pgPrev.disabled = (page <= 1);
    pgNext.disabled = (page >= Math.ceil(total / pageSize));
  }

  function setFilter(f){
    currentFilter = f;
    page = 1;
    avatarFilters.forEach(chip=>{
      const on = chip.getAttribute('data-filter') === f;
      chip.classList.toggle('active', on);
      chip.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    renderPage();
  }

  avatarFilters.forEach(chip=>{
    chip.addEventListener('click', ()=> setFilter(chip.getAttribute('data-filter')));
  });

  pgPrev.addEventListener('click', ()=>{ if (page>1){ page--; renderPage(); }});
  pgNext.addEventListener('click', ()=>{ page++; renderPage(); });

  // prepara e renderiza
  avatarsData = { base: avatarsData.base, avatars: prepareAvatars(avatarsData.avatars || []) };
  if (!avatarHidden.value) avatarHidden.value = 'default.png';
  renderPage();

  // ===== Validações de UX e envio =====
  function validateClient(){
    const first = document.getElementById('primeiro_nome');
    const empresa = document.getElementById('empresa');
    const email = document.getElementById('email_corporativo');
    const phone = document.getElementById('telefone');
    const terms = document.getElementById('terms_accept');

    if (!first.value.trim()){
      first.focus(); return { ok:false, msg:'Informe seu primeiro nome.' };
    }
    if (!empresa.value.trim()){
      empresa.focus(); return { ok:false, msg:'Informe o nome da empresa.' };
    }
    if (!email.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)){
      email.focus(); return { ok:false, msg:'Informe um e-mail válido.' };
    }
    const d = (phone.value||'').replace(/\D/g,'');
    if (d.length !== 11 || !/^([1-9]{2})9\d{8}$/.test(d)){
      phone.focus(); return { ok:false, msg:'Informe um WhatsApp válido no formato (XX) 9XXXX-XXXX.' };
    }
    const complex = /(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/;
    if (!complex.test(elSenha.value)){
      elSenha.focus(); return { ok:false, msg:'Sua senha precisa atender aos requisitos mínimos.' };
    }
    if (elSenha.value !== elConfirm.value){
      elConfirm.focus(); return { ok:false, msg:'As senhas não coincidem.' };
    }
    if (document.getElementById('email_verified').value !== '1'){
      return { ok:false, msg:'Verifique seu e-mail antes de prosseguir.' };
    }
    // avatar hidden sempre terá ao menos default.png
    return { ok:true };
  }

  const formHelp = document.getElementById('formHelp');
  form.addEventListener('submit', (e)=>{
    const v = validateClient();
    if (!v.ok){
      e.preventDefault();
      formHelp.textContent = v.msg;
      formHelp.style.color = '#b91c1c';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Criar conta';
      return;
    }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Criando conta...';
  });

  // Modal Termos
  const openTerms = document.getElementById('openTerms');
  const backdrop = document.getElementById('modalBackdrop');
  const closeTerms = document.getElementById('closeTerms');
  function showTerms(){ backdrop.style.display = 'flex'; backdrop.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function hideTerms(){ backdrop.style.display = 'none'; backdrop.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
  openTerms.addEventListener('click', showTerms);
  closeTerms.addEventListener('click', hideTerms);
  backdrop.addEventListener('click', (e)=>{ if (e.target === backdrop) hideTerms(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') hideTerms(); });
})();
</script>
</body>
</html>
