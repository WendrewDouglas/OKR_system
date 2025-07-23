<?php
// views/profile_user.php

// DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php'; // sendPasswordResetEmail

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}
$id_user = $_SESSION['user_id'];

// Conexão PDO
try {
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: " . $e->getMessage());
}

// Gera token CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Diretório para salvar avatares
$avatarDir = __DIR__ . '/../assets/img/avatars';
if (!is_dir($avatarDir)) {
  mkdir($avatarDir, 0755, true);
}

// Carrega dados do usuário
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id_user = :id");
$stmt->execute([':id' => $id_user]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Valida CSRF
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token inválido.';
  }

  // Reset de senha
  elseif (isset($_POST['reset_password'])) {
    $token = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $ins = $pdo->prepare("
      INSERT INTO usuarios_password_resets (user_id, token, expira_em)
      VALUES (:uid, :tok, :exp)
    ");
    $ins->execute([
      ':uid' => $id_user,
      ':tok' => $token,
      ':exp' => $expires,
    ]);
    $stmt = $pdo->prepare("SELECT email_corporativo FROM usuarios WHERE id_user = :id");
    $stmt->execute([':id' => $id_user]);
    $email = $stmt->fetchColumn();
    if (sendPasswordResetEmail($email, $token)) {
      $success = "E-mail de recuperação enviado para <strong>" .
                 htmlspecialchars($email) .
                 "</strong>.";
    } else {
      $errors[] = 'Não foi possível enviar o e-mail de recuperação.';
    }
  }

  // Avatar
  elseif (!empty($_POST['remove_avatar']) || !empty($_FILES['avatar']['name'])) {
    // apaga antigos
    foreach (['png','jpg','jpeg'] as $e) {
      $f = "$avatarDir/{$id_user}.$e";
      if (file_exists($f)) @unlink($f);
    }
    // salva novo
    if (!empty($_FILES['avatar']['name'])) {
      $up = $_FILES['avatar'];
      $tmp = $up['tmp_name'];
      if ($up['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Erro no upload do avatar.';
      } else {
        $mime = mime_content_type($tmp);
        if (!in_array($mime, ['image/png','image/jpeg'], true)) {
          $errors[] = 'Avatar só JPG ou PNG.';
        } else {
          $ext = $mime === 'image/png' ? 'png' : 'jpg';
          if (move_uploaded_file($tmp, "$avatarDir/{$id_user}.$ext")) {
            $success = 'Avatar atualizado.';
          } else {
            $errors[] = 'Falha ao salvar avatar.';
          }
        }
      }
    } else {
      $success = 'Avatar removido.';
    }
  }

  // Perfil
  else {
    $pn  = trim($_POST['primeiro_nome'] ?? '');
    $un  = trim($_POST['ultimo_nome'] ?? '');
    $tel = trim($_POST['telefone']    ?? '');
    $emp = trim($_POST['empresa']     ?? '');
    $fx  = trim($_POST['faixa_qtd_funcionarios'] ?? '');

    if ($pn === '') {
      $errors[] = 'Primeiro nome é obrigatório.';
    }

    if (empty($errors)) {
      try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("
          UPDATE usuarios SET
            primeiro_nome          = :pn,
            ultimo_nome            = :un,
            telefone               = :tel,
            empresa                = :emp,
            faixa_qtd_funcionarios = :fx,
            dt_alteracao           = NOW(),
            id_user_alteracao      = :u
          WHERE id_user = :id
        ");
        $upd->execute([
          ':pn'  => $pn,
          ':un'  => $un ?: null,
          ':tel' => $tel ?: null,
          ':emp' => $emp ?: null,
          ':fx'  => $fx ?: null,
          ':u'   => $id_user,
          ':id'  => $id_user,
        ]);
        $pdo->commit();
        $success = 'Perfil salvo com sucesso.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
      }
    }
  }

  // Recarrega dados
  $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id_user = :id");
  $stmt->execute([':id' => $id_user]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Formata telefone
$raw = $user['telefone'] ?? '';
$d = preg_replace('/\D+/', '', $raw);
if (strpos($d, '55') === 0 && strlen($d) > 10) {
  $d = substr($d, 2);
}
$telFmt = strlen($d) === 11
  ? sprintf('(%s) %s-%s', substr($d,0,2), substr($d,2,5), substr($d,7,4))
  : $raw;

// Avatar URL
$webDir = '/OKR_system/assets/img/avatars';
foreach (['png','jpg','jpeg'] as $e) {
  if (file_exists("$avatarDir/{$id_user}.$e")) {
    $avatarUrl = "$webDir/{$id_user}.$e";
    break;
  }
}
if (!isset($avatarUrl)) {
  $avatarUrl = '/OKR_system/assets/img/user-avatar.jpeg';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meu Perfil – OKR System</title>
<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css"
  crossorigin="anonymous"/>
<style>
  .form-container {
    max-width: 480px;
    margin: 2rem auto;
    padding: 1rem;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }
  .form-container h1 {
    text-align: center;
    margin-bottom: 1.5rem;
    font-size: 1.5rem;
  }
  .avatar-container {
    width: 120px;
    margin: 0 auto 1.5rem;
  }
  .avatar-container img {
    width: 100%;
    border-radius: 50%;
    display: block;
  }
  .form-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }
  @media (max-width: 600px) {
    .form-two-col {
      grid-template-columns: 1fr;
    }
  }
  .form-group {
    margin-bottom: 1rem;
  }
  label {
    font-weight: 500;
    display: block;
    margin-bottom: 0.25rem;
  }
  input.form-control, select.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ccc;
    border-radius: 4px;
  }
  .btn-reset {
    display: inline-block;
    background: #f5f5f5;
    color: #333;
    border: none;
    padding: 0.5rem 1rem;
    margin-top: 1rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9rem;
  }
  .btn-reset:hover {
    background: #e0e0e0;
  }
  .btn-save {
    display: block;
    margin: 2.5rem auto 0;
    width: 140px;
    padding: 0.5rem;
    font-size: 1rem;
  }
</style>
</head>
<body class="collapsed">
<?php include __DIR__.'/partials/sidebar.php'; ?>
<div class="content">
<?php include __DIR__.'/partials/header.php'; ?>
<main>
  <div class="form-container">
    <h1>Meu Perfil</h1>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="remove_avatar" id="remove_avatar" value="">

      <div class="avatar-container">
        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar">
      </div>

      <div class="form-group">
        <label>Alterar avatar</label>
        <input type="file" name="avatar" accept="image/png,image/jpeg">
      </div>

      <div class="form-two-col">
        <div class="form-group">
          <label for="primeiro_nome">Primeiro Nome *</label>
          <input id="primeiro_nome" name="primeiro_nome" class="form-control" required
                 value="<?= htmlspecialchars($user['primeiro_nome'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="ultimo_nome">Último Nome</label>
          <input id="ultimo_nome" name="ultimo_nome" class="form-control"
                 value="<?= htmlspecialchars($user['ultimo_nome'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="telefone">Telefone</label>
        <input id="telefone" name="telefone" class="form-control"
               value="<?= htmlspecialchars($telFmt) ?>">
      </div>

      <div class="form-two-col">
        <div class="form-group">
          <label for="empresa">Empresa</label>
          <input id="empresa" name="empresa" class="form-control"
                 value="<?= htmlspecialchars($user['empresa'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="faixa_qtd_funcionarios">Faixa de Funcionários</label>
          <select id="faixa_qtd_funcionarios" name="faixa_qtd_funcionarios" class="form-control">
            <option value="">Selecione</option>
            <?php foreach (['1–100','101–500','501–1000','1001+'] as $opt): ?>
              <option value="<?= $opt ?>"
                <?= (( $user['faixa_qtd_funcionarios'] ?? '' ) === $opt ? 'selected':'' )?>>
                <?= $opt ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" name="reset_password" class="btn-reset">
        Enviar e-mail de redefinição de senha
      </button>

      <button type="submit" class="btn btn-primary btn-save">
        Salvar Alterações
      </button>
    </form>
  </div>
</main>
</div>

<script>
document.getElementById('telefone').addEventListener('input', function(){
  let d = this.value.replace(/\D/g,'').slice(0,11), f = '';
  if (d.length>0) f = '(' + d.slice(0,2);
  if (d.length>=3) f += ') ' + d.slice(2,7);
  if (d.length>=8) f += '-' + d.slice(7);
  this.value = f;
});
</script>
</body>
</html>
