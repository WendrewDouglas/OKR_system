<?php
// views/profile_user.php
session_start();
require_once __DIR__ . '/../auth/config.php';

// Conexão PDO com exceções
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die('Erro ao conectar ao banco.');
}

// Verifica login
if (!isset($_SESSION['id_user'])) {
    header('Location: login.php');
    exit;
}
$id = $_SESSION['id_user'];

// Gera CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors  = [];
$success = '';

// Trata submissão do form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token inválido. Recarregue a página e tente novamente.';
    } else {
        // Sanitização
        $primeiro_nome  = trim($_POST['primeiro_nome'] ?? '');
        $ultimo_nome    = trim($_POST['ultimo_nome'] ?? '');
        $telefone       = trim($_POST['telefone'] ?? '');
        $empresa        = trim($_POST['empresa'] ?? '');
        $faixa          = trim($_POST['faixa_qtd_funcionarios'] ?? '');
        $login_novo     = trim($_POST['login'] ?? '');
        $senha_nova     = $_POST['senha_nova'] ?? '';
        $senha_conf     = $_POST['senha_confirma'] ?? '';

        if ($primeiro_nome === '') {
            $errors[] = 'O primeiro nome é obrigatório.';
        }
        if ($login_novo === '') {
            $errors[] = 'O login não pode ficar em branco.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Upload de avatar
                $imagemUrl = null;
                if (!empty($_FILES['avatar']['name'])) {
                    $file = $_FILES['avatar'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime  = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);
                        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png'];
                        if (!isset($allowed[$mime])) {
                            throw new Exception('Avatar deve ser JPG ou PNG.');
                        }
                        if ($file['size'] > 2*1024*1024) {
                            throw new Exception('Avatar deve ter no máximo 2MB.');
                        }
                        $ext      = $allowed[$mime];
                        $novoNome = "avatar_{$id}_" . time() . ".{$ext}";
                        $destino  = __DIR__ . "/uploads/avatars/{$novoNome}";
                        if (!move_uploaded_file($file['tmp_name'], $destino)) {
                            throw new Exception('Falha ao mover arquivo.');
                        }
                        $imagemUrl = "/uploads/avatars/{$novoNome}";
                    } else {
                        throw new Exception('Erro no upload do avatar.');
                    }
                }

                // Atualiza usuários
                $sql = "UPDATE usuarios SET
                            primeiro_nome = :primeiro_nome,
                            ultimo_nome   = :ultimo_nome,
                            telefone      = :telefone,
                            empresa       = :empresa,
                            faixa_qtd_funcionarios = :faixa,
                            dt_alteracao  = NOW(),
                            id_user_alteracao = :alterador
                        " . ($imagemUrl ? ", imagem_url = :imagem_url" : "") . "
                        WHERE id_user = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':primeiro_nome', $primeiro_nome);
                $stmt->bindValue(':ultimo_nome',   $ultimo_nome ?: null);
                $stmt->bindValue(':telefone',      $telefone ?: null);
                $stmt->bindValue(':empresa',       $empresa ?: null);
                $stmt->bindValue(':faixa',         $faixa ?: null);
                if ($imagemUrl) {
                    $stmt->bindValue(':imagem_url', $imagemUrl);
                }
                $stmt->bindValue(':alterador', $id, PDO::PARAM_INT);
                $stmt->bindValue(':id',        $id, PDO::PARAM_INT);
                $stmt->execute();

                // Verifica unicidade de login
                $chk = $pdo->prepare("
                    SELECT COUNT(*) FROM usuarios_credenciais
                     WHERE login = :login AND id_user <> :id
                ");
                $chk->execute([':login'=>$login_novo, ':id'=>$id]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception('Login já em uso por outro usuário.');
                }
                // Atualiza login
                $upd = $pdo->prepare("
                    UPDATE usuarios_credenciais
                       SET login = :login
                     WHERE id_user = :id
                ");
                $upd->execute([':login'=>$login_novo, ':id'=>$id]);

                // Atualiza senha, se informada
                if ($senha_nova !== '') {
                    if ($senha_nova !== $senha_conf) {
                        throw new Exception('A confirmação da senha não confere.');
                    }
                    $hash = password_hash($senha_nova, PASSWORD_BCRYPT);
                    $upw  = $pdo->prepare("
                        UPDATE usuarios_credenciais
                           SET senha_hash = :hash
                         WHERE id_user = :id
                    ");
                    $upw->execute([':hash'=>$hash, ':id'=>$id]);
                }

                $pdo->commit();
                $success = 'Perfil atualizado com sucesso.';
                // Regenera token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Busca dados do usuário
$stmt = $pdo->prepare("
    SELECT u.*, uc.login, uc.dt_ultimo_login
      FROM usuarios u
      JOIN usuarios_credenciais uc ON uc.id_user = u.id_user
     WHERE u.id_user = :id
");
$stmt->execute([':id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Meu Perfil – OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="content">
    <div class="container p-4">
      <h1>Meu Perfil</h1>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger"><ul>
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <div class="row">
          <div class="col-md-8">
            <!-- Dados Pessoais -->
            <div class="card mb-4">
              <div class="card-header">Dados Pessoais</div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label">Primeiro Nome *</label>
                  <input type="text" name="primeiro_nome" class="form-control" required
                         value="<?= htmlspecialchars($user['primeiro_nome']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Último Nome</label>
                  <input type="text" name="ultimo_nome" class="form-control"
                         value="<?= htmlspecialchars($user['ultimo_nome']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Telefone</label>
                  <input type="text" name="telefone" class="form-control"
                         value="<?= htmlspecialchars($user['telefone']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Empresa</label>
                  <input type="text" name="empresa" class="form-control"
                         value="<?= htmlspecialchars($user['empresa']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Faixa de Funcionários</label>
                  <input type="text" name="faixa_qtd_funcionarios" class="form-control"
                         value="<?= htmlspecialchars($user['faixa_qtd_funcionarios']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">E-mail Corporativo</label>
                  <input type="email" class="form-control" readonly
                         value="<?= htmlspecialchars($user['email_corporativo']) ?>">
                </div>
              </div>
            </div>

            <!-- Credenciais -->
            <div class="card mb-4">
              <div class="card-header">Credenciais</div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label">Login *</label>
                  <input type="text" name="login" class="form-control" required
                         value="<?= htmlspecialchars($user['login']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Senha Nova</label>
                  <input type="password" name="senha_nova" class="form-control">
                </div>
                <div class="mb-3">
                  <label class="form-label">Confirmar Senha</label>
                  <input type="password" name="senha_confirma" class="form-control">
                </div>
                <small class="text-muted">Deixe em branco para não alterar.</small>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <!-- Avatar -->
            <div class="card mb-4">
              <div class="card-header">Foto de Perfil</div>
              <div class="card-body text-center">
                <img src="<?= htmlspecialchars($user['imagem_url'] ?: '/assets/img/default-avatar.png') ?>"
                     class="img-fluid rounded-circle mb-3" style="max-width:150px;" alt="Avatar">
                <div class="mb-3">
                  <label class="form-label">Alterar Avatar</label>
                  <input type="file" name="avatar" class="form-control">
                </div>
              </div>
            </div>
            <!-- Metadados -->
            <div class="card">
              <div class="card-header">Último Acesso</div>
              <div class="card-body">
                <p><strong>Data:</strong> <?= htmlspecialchars($user['dt_ultimo_login']) ?></p>
              </div>
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
      </form>
    </div>
  </main>

  <script>
    // Toggle senha
    document.querySelectorAll('input[type="password"]').forEach(input => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = '👁️';
      btn.classList.add('toggle-password');
      input.parentNode.appendChild(btn);
      btn.addEventListener('click', () => {
        input.type = input.type === 'password' ? 'text' : 'password';
        btn.textContent = input.type === 'password' ? '👁️' : '🙈';
      });
    });
  </script>
</body>
</html>
