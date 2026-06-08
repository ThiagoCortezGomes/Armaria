<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$error = "";
$logoSrc = "assets/brasao-bpamb.png";

if (($_GET['timeout'] ?? '') === '1') {
  $error = "Sessão encerrada automaticamente após 60 minutos de inatividade.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $error = "Sessão expirada ou token inválido. Recarregue a página.";
    app_audit('LOGIN_CSRF_INVALID', []);
  } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, name, username, email, password_hash, role, must_change_password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
      unset($user['password_hash']);
      $_SESSION['user'] = $user;
      $_SESSION['last_activity_ts'] = time();
      app_audit('LOGIN_SUCCESS', []);

      header("Location: dashboard.php");
      exit;
    }

    app_audit('LOGIN_FAIL', ['username' => $username]);
    $error = "Login ou senha inválidos.";
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
</head>

<body class="armaria-auth">
  <header class="auth-topbar">
    <div class="brand">Armaria</div>
  </header>
  <main class="auth-main">
  <div class="auth-wrap">
    <div class="auth-brand">
      <div aria-hidden="true">
        <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Brasão BPAmb">
      </div>
      <div>
        <h1>BPAmb</h1>
        <p>Sistema de controle de cautelas - BPAmb</p>
      </div>
    </div>

    <div class="auth-card">
      <div class="header">
        <h2>Acessar o sistema</h2>
        <p>Informe seu login e senha cadastrados para entrar.</p>
      </div>

      <div class="body">
        <?php if ($error): ?>
          <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <?= csrf_input_field() ?>
          <label class="auth-label" for="username">Usuário</label>
          <input class="auth-input" id="username" name="username" required autofocus>

          <label class="auth-label" for="password">Senha</label>
          <input class="auth-input" id="password" type="password" name="password" required>

          <div style="margin-top:10px;font-size:12px;color:#777;">
            Dica: use credenciais cadastradas pelo admin.
          </div>

          <button class="auth-btn" type="submit">Entrar</button>
          <p class="auth-links">
            <a href="forgot_password.php">Esqueci minha senha</a>
          </p>
        </form>

        <div class="auth-footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
  </main>
</body>

</html>
