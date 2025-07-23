<?php
session_start();

// Gera token CSRF se não existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Exibe mensagens de erro/sucesso, se existirem
$error   = $_SESSION['error_message']   ?? '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

$old = $_SESSION['old_inputs'] ?? [];
unset($_SESSION['old_inputs']);

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
    body {
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(to bottom, #FDB900 0%, #222222 100%);
    }
    .login-card {
      width: 100%;
      max-width: 80%;
      background: #fff;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border-radius: 8px;
      position: relative;
    }
    .login-card-content {
      transform: scale(0.85);
      transform-origin: center;
      width: 117.65%;
      margin: 0 auto;
    }
    .login-header {
      width: 100%;
      padding: 0rem 2rem 1rem 2rem;
      text-align: center;
    }
    .login-header h2,
    .login-header p {
      margin: 0;
      line-height: 1.2;
    }
    .login-body {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: flex-start;
      gap: 1rem;
      margin: 0;
    }
    .login-illustration {
      flex: 1 1 300px;
      padding: 1rem 2rem;
      background-image: none !important;
      background-color: #fff;
      display: flex;
      flex-direction: column;
    }
    .login-form-wrapper {
      flex: 1 1 300px;
      padding: 1rem 2rem;
      background-color: #fff;
      display: flex;
      flex-direction: column;
    }
    /* novo rodapé */
    .login-footer {
      width: 100%;
      padding: 1rem 2rem;
      text-align: center;
    }
    .login-footer .btn {
      display: inline-block;
      padding: 0.5rem 2rem;
    }
    .login-footer .link-container {
      margin-top: 0.5rem;
    }
    .logo-top {
      text-align: center;
      width: 100%;
      margin-bottom: 0;
    }
    .logo-top .logo {
      max-width: 300px;
      height: auto;
    }
    .error-message,
    .success-message {
      margin-bottom: 1rem;
      text-align: center;
      width: 100%;
      max-width: 800px;
    }
    /* feedback de senha */
    #senha_feedback {
      font-size: 0.9rem;
      margin-top: 0.25rem;
    }
    /* Mensagens de erro em campo de senha */
    #senha_error {
      width: 100%;
      box-sizing: border-box;
      white-space: normal;
      overflow-wrap: break-word;  /* ou word-break: break-word; */
      font-size: 0.8rem;
      color: #d9534f;
      margin-top: 0.25rem;
    }
    .password-field {
      width: 100%;
      position: relative;
    }
    .password-field .toggle-password {
      position: absolute;
      top: 50%;
      right: 1rem;
      transform: translateY(-50%);
      background: transparent;
      border: none;
      cursor: pointer;
      font-size: 1.2rem;
    }
    .password-field .toggle-password:focus {
      outline: none;
    }
    .password-field input {
      width: 100%;
      padding-right: 4rem;
      box-sizing: border-box;
    }

    /* ===== Responsividade Mobile ===== */
    @media (max-width: 768px) {
      body {
        justify-content: flex-start;  /* empurra para o topo */
        padding: 1rem 0;             /* dá um respiro em cima/baixo */
        overflow-y: auto;            /* garante scroll da página */
      }
      .login-card {
        max-width: 100%;
        border-radius: 0;
        box-shadow: none;
        margin: 0 1rem;
        max-height: none;      /* deixa crescer conforme o conteúdo */
        overflow: visible;     /* não esconde nada */
        margin: 1rem;          /* espaçamento nas laterais */
      }
      .login-card-content {
        transform: none;
        width: 100%;
        padding: 0;
      }
      .login-body {
        flex-direction: column;
        align-items: center;    /* <— centraliza todos os filhos */
        padding: 0;
      }
      .login-illustration,
      .login-form-wrapper {
        display: flex;           /* já tem, mas só pra reforçar */
        flex-direction: column;  /* mantém coluna */
        align-items: center;     /* <— aqui */
        margin: 0 auto;          /* garante bloco centralizado */
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        /* remove qualquer limitação de altura/scroll que restou */
        max-height: none !important;
        overflow: visible !important;
      }
        /* 3) limita a largura dos campos pra não esticar até a borda */
      .login-illustration .mb-3,
      .login-form-wrapper .mb-3 {
        width: 100%;
        max-width: 400px;        /* ajuste conforme preferir */
      }
      .login-header {
        padding: 1rem;
      }
      .login-header h2 {
        font-size: 1.5rem;
      }
      .login-header p {
        font-size: 1rem;
      }
      .login-footer {
        padding: 1rem;
      }
      .login-footer .btn {
        width: 100%;
        padding: 0.75rem;
      }
      .login-footer .link-container {
        margin-top: 1rem;
      }
      .logo-top .logo {
        max-width: 200px;
      }
      /* 1) transforma o password-field num grid com 2 colunas */
      .password-field {
        display: grid;
        grid-template-columns: 1fr auto;
        column-gap: 0.5rem;
        align-items: center;
        width: 100%;            /* já estava, só reforçando */
      }

      /* 2) o input ocupa toda a primeira coluna */
      .password-field input {
        padding-right: 1rem;    /* só pra não grudar muito no botão */
        box-sizing: border-box;
      }

      /* 3) o toggle vira estático, no fim da segunda coluna */
      .password-field .toggle-password {
        position: static;
        transform: none;
        margin: 0;
        font-size: 1.2rem;
        cursor: pointer;
      }
      /* remove qualquer scroll e altura fixa da segunda coluna */
      .login-form-wrapper {
        max-height: none !important;
        height: auto !important;
        overflow: visible !important;
      }
      .login-form-wrapper *, 
      .login-form-wrapper {
        overflow: visible !important;
      }
    }
  </style>
    </head>
<body>

  <div class="logo-top">
    <a href="https://planningbi.com.br/" aria-label="Ir para página inicial">
      <img src="https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol.png"
           alt="Logo" class="logo">
    </a>
  </div>

  <?php if ($error): ?>
    <div class="error-message" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($success): ?>
    <div class="success-message" role="status">
      <?= htmlspecialchars($success) ?>
      <div style="margin-top:1rem;">
        <a href="https://planningbi.com.br/OKR_system/login" class="btn btn-success">
          Fazer login
        </a>
      </div>
    </div>
  <?php endif; ?>

  <form action="/OKR_system/auth/auth_register.php"
        method="POST"
        onsubmit="return validarSenhas()"
        style="width:100%; max-width:800px;"
        novalidate>

    <!-- honeypot -->
    <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

    <!-- CSRF -->
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="login-card">
      <div class="login-card-content">

        <div class="login-header">
          <h2>Crie sua conta</h2>
          <p>Preencha os dados para acesso ao sistema de OKRs</p>
        </div>

        <div class="login-body">
          <div class="login-illustration">
            <div class="mb-3">
              <label for="primeiro_nome" class="form-label">Primeiro Nome <span aria-hidden="true">*</span></label>
              <input type="text"
                     id="primeiro_nome"
                     name="primeiro_nome"
                     autocomplete="off"
                     class="form-control"
                     placeholder="Seu primeiro nome"
                     value="<?= htmlspecialchars($old['primeiro_nome'] ?? '') ?>"
                     required
                     aria-describedby="error_primeiro_nome">
              <div id="error_primeiro_nome" class="field-error" role="alert"></div>
            </div>
            <div class="mb-3">
              <label for="ultimo_nome" class="form-label">Último Nome</label>
              <input type="text"
                     id="ultimo_nome"
                     name="ultimo_nome"
                     autocomplete="off"
                     class="form-control"
                     placeholder="Seu sobrenome"
                     value="<?= htmlspecialchars($old['ultimo_nome'] ?? '') ?>"
                     aria-describedby="error_ultimo_nome">
              <div id="error_ultimo_nome" class="field-error" role="alert"></div>
            </div>
            <div class="mb-3">
              <label for="email_corporativo" class="form-label">E-mail Corporativo <span aria-hidden="true">*</span></label>
              <input type="email"
                     id="email_corporativo"
                     name="email_corporativo"
                     autocomplete="off"
                     class="form-control"
                     placeholder="seu@empresa.com"
                     value="<?= htmlspecialchars($old['email_corporativo'] ?? '') ?>"
                     required
                     pattern="[^@\s]+@[^@\s]+\.[^@\s]+"
                     title="Digite um e-mail no formato nome@domínio.com"
                     aria-describedby="error_email">
              <div id="error_email" class="field-error" role="alert"></div>
            </div>
            <div class="mb-3">
              <label for="telefone" class="form-label">Telefone</label>
              <input type="tel"
                     id="telefone"
                     name="telefone"
                     autocomplete="off"
                     class="form-control"
                     placeholder="(XX) 99999-9999"
                     title="Formato: (XX) 99999-9999"
                     value="<?= htmlspecialchars($old['telefone'] ?? '') ?>"
                     aria-describedby="error_telefone">
              <div id="error_telefone" class="field-error" role="alert"></div>
            </div>
          </div>

          <div class="login-form-wrapper">
            <div class="mb-3">
              <label for="empresa" class="form-label">Empresa</label>
              <input type="text"
                     id="empresa"
                     autocomplete="off"
                     name="empresa"
                     class="form-control"
                     placeholder="Nome da sua empresa"
                     value="<?= htmlspecialchars($old['empresa'] ?? '') ?>"
                     aria-describedby="error_empresa">
              <div id="error_empresa" class="field-error" role="alert"></div>
            </div>
            <div class="mb-3">
              <label for="faixa_qtd_funcionarios" class="form-label">Faixa de Funcionários</label>
              <select id="faixa_qtd_funcionarios"
                      name="faixa_qtd_funcionarios"
                      class="form-control"
                      aria-describedby="error_faixa">
                  <option value="">Selecione</option>
                  <option value="1–100" <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='1–100') ? 'selected' : '' ?>>1–100</option>
                  <option value="101–500" <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='101–500') ? 'selected' : '' ?>>101–500</option>
                  <option value="501–1000" <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='501–1000') ? 'selected' : '' ?>>501–1000</option>
                  <option value="1001+"    <?= (isset($old['faixa_qtd_funcionarios']) && $old['faixa_qtd_funcionarios']==='1001+')    ? 'selected' : '' ?>>1001+</option>
              </select>
              <div id="error_faixa" class="field-error" role="alert"></div>
            </div>
<!-- Dentro de .login-form-wrapper, substitua os blocos de senha por este: -->

<div class="mb-3">
  <label for="senha" class="form-label">
    Senha <span aria-hidden="true">*</span>
  </label>
  <div class="password-field">
    <input type="password"
           id="senha"
           name="senha"
           autocomplete="new-password"
           class="form-control"
           placeholder="Crie uma senha"
           required
           minlength="8"
           pattern="(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$"
           title="Mínimo 8 caracteres, com letra maiúscula, minúscula, número e símbolo"
           aria-describedby="senha_feedback senha_error">
    <button type="button" class="toggle-password" aria-label="Mostrar senha">
      👁️
    </button>
  </div>
  <div id="senha_error" class="field-error" role="alert"></div>
</div>

            <div class="mb-3">
            <label for="senha_confirm" class="form-label">
                Confirmar Senha <span aria-hidden="true">*</span>
            </label>
            <div class="password-field">
                <input type="password"
                    id="senha_confirm"
                    name="senha_confirm"
                    autocomplete="new-password"
                    class="form-control"
                    placeholder="Confirme a senha"
                    required
                    minlength="8"
                    aria-describedby="senha_feedback">
                <button type="button" class="toggle-password" aria-label="Mostrar senha">
                👁️
                </button>
            </div>
            <small id="senha_feedback"></small>
            </div>
          </div>
        </div> <!-- .login-body -->

        <div class="login-footer">
          <button type="submit" class="btn btn-primary">Criar Conta</button>
          <div class="link-container">
            <a href="https://planningbi.com.br/OKR_system/login">Já tenho conta – Entrar</a>
          </div>
        </div>

      </div> <!-- .login-card-content -->
    </div> <!-- .login-card -->
  </form>

  <script>
    const senhaInput   = document.getElementById('senha');
    const confirmInput = document.getElementById('senha_confirm');
    const feedback     = document.getElementById('senha_feedback');
    const errorSenha   = document.getElementById('senha_error');

    function checkSenhas() {
      const s = senhaInput.value;
      // Verifica complexidade
      const complex = /(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/;
      if (!complex.test(s)) {
        errorSenha.textContent = 'Senha fraca. A senha deve conter no mínimo 8 caracteres, ' +
  'incluindo ao menos uma letra maiúscula, uma letra minúscula, um número e um símbolo.';
      } else {
        errorSenha.textContent = '';
      }
      if (!confirmInput.value) {
        feedback.textContent = '';
        return;
      }
      if (s === confirmInput.value) {
        feedback.textContent = '✅ Senhas conferem.';
        feedback.style.color = 'green';
      } else {
        feedback.textContent = '❌ Senhas não coincidem.';
        feedback.style.color = 'red';
      }
    }

    senhaInput.addEventListener('input', checkSenhas);
    confirmInput.addEventListener('input', checkSenhas);

    function validarSenhas() {
      if (senhaInput.value !== confirmInput.value) {
        alert('As senhas não coincidem.');
        return false;
      }
      return true;
    }

  const telefoneInput = document.getElementById('telefone');

  telefoneInput.addEventListener('input', function(e) {
    // Remove tudo que não for dígito
    let digits = this.value.replace(/\D/g, '');

    // Limita a 11 dígitos (2 de DDD + 9 do número)
    if (digits.length > 11) digits = digits.slice(0, 11);

    // Começa a montar a máscara
    let formatted = '';
    if (digits.length > 0) {
      formatted += '(' + digits.substring(0, 2);
    }
    if (digits.length >= 3) {
      formatted += ') ' + digits.substring(2, 7);
    }
    if (digits.length >= 8) {
      formatted += '-' + digits.substring(7);
    }

    this.value = formatted;
  });


// Seleciona todos os botões de toggle
document.querySelectorAll('.toggle-password').forEach(btn => {
  btn.addEventListener('click', () => {
    // Encontra o input associado (irmão dentro de .password-field)
    const container = btn.closest('.password-field');
    const input = container.querySelector('input');

    // Alterna tipo
    if (input.type === 'password') {
      input.type = 'text';
      btn.textContent = '🙈'; // ou outro ícone para “esconder”
      btn.setAttribute('aria-label', 'Esconder senha');
    } else {
      input.type = 'password';
      btn.textContent = '👁️';
      btn.setAttribute('aria-label', 'Mostrar senha');
    }
  });
});


  </script>

</body>
</html>