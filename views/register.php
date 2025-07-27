    <?php
    // views/register.php
    session_start();

    // Exibe mensagens de erro/sucesso, se existirem
    $error   = $_SESSION['error_message']   ?? '';
    $success = $_SESSION['success_message'] ?? '';
    unset($_SESSION['error_message'], $_SESSION['success_message']);
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
    <meta charset="utf-8">
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
        max-height: 450px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-radius: 8px;
        overflow: hidden;
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
        /* feedback de senha (permanece igual) */
        #senha_feedback {
        font-size: 0.9rem;
        margin-top: 0.25rem;
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
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="success-message"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form action="/OKR_system/auth/auth_register.php" method="POST" onsubmit="return validarSenhas()" style="width:100%; max-width:800px;">
        <div class="login-card">
        <div class="login-card-content">

            <div class="login-header">
            <h2>Crie sua conta</h2>
            <p>Preencha os dados para acesso ao sistema de OKRs</p>
            </div>

            <div class="login-body">
            <div class="login-illustration">
                <div class="mb-3">
                <label for="primeiro_nome" class="form-label">Primeiro Nome</label>
                <input type="text" id="primeiro_nome" name="primeiro_nome"
                        class="form-control" placeholder="Seu primeiro nome" required>
                </div>
                <div class="mb-3">
                <label for="ultimo_nome" class="form-label">Último Nome</label>
                <input type="text" id="ultimo_nome" name="ultimo_nome"
                        class="form-control" placeholder="Seu sobrenome">
                </div>
                <div class="mb-3">
                <label for="email_corporativo" class="form-label">E-mail Corporativo</label>
                <input type="email" id="email_corporativo" name="email_corporativo"
                        class="form-control" placeholder="seu@empresa.com" required>
                </div>
                <div class="mb-3">
                <label for="telefone" class="form-label">Telefone</label>
                <input type="tel" id="telefone" name="telefone"
                        class="form-control" placeholder="(XX) XXXXX-XXXX">
                </div>
            </div>

            <div class="login-form-wrapper">
                <div class="mb-3">
                <label for="empresa" class="form-label">Empresa</label>
                <input type="text" id="empresa" name="empresa"
                        class="form-control" placeholder="Nome da sua empresa">
                </div>
                <div class="mb-3">
                <label for="faixa_qtd_funcionarios" class="form-label">Faixa de Funcionários</label>
                <select id="faixa_qtd_funcionarios" name="faixa_qtd_funcionarios"
                        class="form-control">
                    <option value="">Selecione</option>
                    <option value="1-10">1–10</option>
                    <option value="11-50">11–50</option>
                    <option value="51-200">51–200</option>
                    <option value="201+">201+</option>
                </select>
                </div>
                <div class="mb-3">
                <label for="senha" class="form-label">Senha</label>
                <input type="password" id="senha" name="senha"
                        class="form-control" placeholder="Crie uma senha" required minlength="6">
                </div>
                <div class="mb-3">
                <label for="senha_confirm" class="form-label">Confirmar Senha</label>
                <input type="password" id="senha_confirm" name="senha_confirm"
                        class="form-control" placeholder="Confirme a senha" required minlength="6">
                <small id="senha_feedback"></small>
                </div>
            </div>
            </div> <!-- .login-body -->

            <div class="login-footer">
            <button type="submit" class="btn btn-primary">Cadastrar</button>
            <div class="link-container">
                <a href="/OKR_system/views/login.php">Já tenho conta – Entrar</a>
            </div>
            </div>

        </div> <!-- .login-card-content -->
        </div> <!-- .login-card -->
    </form>

    <script>
        const senhaInput    = document.getElementById('senha');
        const confirmInput  = document.getElementById('senha_confirm');
        const feedback      = document.getElementById('senha_feedback');

        function checkSenhas() {
        if (!confirmInput.value) {
            feedback.textContent = '';
            return;
        }
        if (senhaInput.value === confirmInput.value) {
            feedback.textContent = '✅ Senhas conferem.';
            feedback.style.color   = 'green';
        } else {
            feedback.textContent = '❌ Senhas não coincidem.';
            feedback.style.color   = 'red';
        }
        }

        senhaInput.addEventListener('input',  checkSenhas);
        confirmInput.addEventListener('input', checkSenhas);

        function validarSenhas() {
        if (senhaInput.value !== confirmInput.value) {
            alert('As senhas não coincidem.');
            return false;
        }
        return true;
        }
    </script>


    </body>
    </html>
