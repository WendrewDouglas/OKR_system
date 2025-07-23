<?php
// para desenvolvimento só — retire em produção
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1. Valida entrada
$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
if (!$email || !$password) {
    $_SESSION['error_message'] = 'E-mail ou senha inválidos.';
    header('Location: /OKR_system/views/login.php');
    exit;
}

// 2. Conecta ao DB
require __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    $options
);

// 3. Busca usuário unindo usuarios + usuarios_credenciais
$stmt = $pdo->prepare(
  'SELECT
     u.id_user           AS id,
     u.primeiro_nome     AS nome,
     u.email_corporativo AS email,
     c.senha_hash        AS password_hash
   FROM usuarios AS u
   INNER JOIN usuarios_credenciais AS c
     ON u.id_user = c.id_user
   WHERE u.email_corporativo = :email
   LIMIT 1'
);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['error_message'] = 'E-mail ou senha incorretos.';
    header('Location: /OKR_system/views/login.php');
    exit;
}
if (isset($user['ativo']) && !$user['ativo']) {
    $_SESSION['error_message'] = 'Conta inativa. Contate o administrador.';
    header('Location: /OKR_system/views/login.php');
    exit;
}

// 4. Login bem-sucedido
session_regenerate_id(true);
$_SESSION['user_id']    = $user['id'];
$_SESSION['user_name']  = $user['nome'];
$_SESSION['user_email'] = $user['email'];

// 5. Redireciona para dashboard
header('Location: https://planningbi.com.br/OKR_system/dashboard');
exit;
