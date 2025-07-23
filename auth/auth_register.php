<?php
// auth/auth_register.php
session_start();

// Função de redirecionamento com mensagem
function redirectWithError($msg) {
    $_SESSION['error_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}
function redirectWithSuccess($msg) {
    $_SESSION['success_message'] = $msg;
    header('Location: /OKR_system/views/cadastro_site.php');
    exit;
}

function storeOldInputs() {
    $_SESSION['old_inputs'] = [
        'primeiro_nome'         => $_POST['primeiro_nome']         ?? '',
        'ultimo_nome'           => $_POST['ultimo_nome']           ?? '',
        'email_corporativo'     => $_POST['email_corporativo']     ?? '',
        'telefone'              => $_POST['telefone']              ?? '',
        'empresa'               => $_POST['empresa']               ?? '',
        'faixa_qtd_funcionarios'=> $_POST['faixa_qtd_funcionarios']?? '',
        // NÃO armazene senha nem senha_confirm
    ];
}


// 1) CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    storeOldInputs();
    redirectWithError('Requisição inválida (CSRF).');
}

// 2) Honeypot
if (!empty($_POST['website'])) {
    http_response_code(400);
    exit;
}

// 3) HTTPS obrigatório
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    storeOldInputs();
    redirectWithError('Conexão insegura. Use HTTPS.');
}

// 4) Rate-limit simples por sessão (máx 5 tentativas)
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = 0;
}
if ($_SESSION['reg_attempts']++ >= 20) {
    storeOldInputs();
    redirectWithError('Muitas tentativas. Aguarde alguns minutos.');
}

// 5) Sanitização e validação server-side
$primeiro = trim($_POST['primeiro_nome'] ?? '');
$ultimo   = trim($_POST['ultimo_nome']    ?? '');
$email    = trim($_POST['email_corporativo'] ?? '');
$telefone = trim($_POST['telefone']       ?? '');
$empresa  = trim($_POST['empresa']        ?? '');
$faixa    = trim($_POST['faixa_qtd_funcionarios'] ?? '');
$senha    = $_POST['senha']               ?? '';
$senha_cf = $_POST['senha_confirm']       ?? '';

if ($primeiro === '') {
    storeOldInputs();
    redirectWithError('Primeiro nome é obrigatório.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    storeOldInputs();
    redirectWithError('E-mail inválido.');
}
[, $dominio] = explode('@', $email);
if (!checkdnsrr($dominio, 'MX')) {
    storeOldInputs();
    redirectWithError('Domínio de e-mail não existe.');
}

$senhaRegex = '/(?=^.{8,}$)(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*\W).*$/';
if (!preg_match($senhaRegex, $senha)) {
    storeOldInputs();
    redirectWithError('Senha não atende aos requisitos mínimos.');
}
if ($senha !== $senha_cf) {
    storeOldInputs();
    redirectWithError('Senhas não conferem.');
}

if ($telefone !== '' && !preg_match('/^\(\d{2}\)\s?\d{4,5}-\d{4}$/', $telefone)) {
    storeOldInputs();
    redirectWithError('Formato de telefone inválido.');
}

// 6) Conexão ao banco via PDO
require_once __DIR__ . '/config.php';
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // **Adicione esta linha:**
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log($e->getMessage());
    storeOldInputs();
    redirectWithError('Erro ao conectar ao banco.');
}

// 7) Checagem de unicidade de e-mail
$stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email_corporativo = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    storeOldInputs();
    redirectWithError('E-mail já cadastrado.');
}

// 8) Inserção atômica
try {
    $pdo->beginTransaction();

    // 8.1) usuarios
    $sql1 = 'INSERT INTO usuarios
      (primeiro_nome, ultimo_nome, telefone, empresa, faixa_qtd_funcionarios,
       email_corporativo, dt_cadastro, ip_criacao, id_user_criador, id_permissao)
     VALUES
      (:primeiro, :ultimo, :telefone, :empresa, :faixa,
       :email, NOW(), :ip, :criador, :perm)';
    $stmt1 = $pdo->prepare($sql1);
    $stmt1->execute([
        'primeiro' => $primeiro,
        'ultimo'   => $ultimo ?: null,
        'telefone' => $telefone ?: null,
        'empresa'  => $empresa ?: null,
        'faixa'    => $faixa ?: null,
        'email'    => $email,
        'ip'       => $_SERVER['REMOTE_ADDR'],
        'criador'  => $_SESSION['user_id'] ?? null,
        'perm'     => 2
    ]);
    $newId = $pdo->lastInsertId();

    // 8.2) usuarios_credenciais
    $hash = password_hash($senha, PASSWORD_ARGON2ID);
    $stmt2 = $pdo->prepare(
        'INSERT INTO usuarios_credenciais (id_user, senha_hash)
         VALUES (:id, :hash)'
    );
    $stmt2->execute(['id' => $newId, 'hash' => $hash]);

    // 8.3) usuarios_permissoes
    $stmt3 = $pdo->prepare(
        'INSERT INTO usuarios_permissoes (id_user, id_permissao)
         VALUES (:id, :perm)'
    );
    $stmt3->execute(['id' => $newId, 'perm' => 'user_admin']);

    $pdo->commit();
    unset($_SESSION['old_inputs']);
    redirectWithSuccess('Cadastro realizado com sucesso! Faça login para começar.');
} catch (Exception $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    storeOldInputs();
    redirectWithError('Falha no cadastro. Tente novamente mais tarde.');
}