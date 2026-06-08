<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$msg = "";
$success = false;
$debugLink = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
    app_audit('FORGOT_PASSWORD_CSRF_INVALID', []);
  } else {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
      $msg = "Informe usuário ou e-mail.";
      app_audit('FORGOT_PASSWORD_FAIL', ['reason' => 'empty_identifier']);
    } else {
      $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username=? OR email=? LIMIT 1");
      $stmt->execute([$identifier, $identifier]);
      $user = $stmt->fetch();

      if ($user && !empty($user['email'])) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL")
          ->execute([(int)$user['id']]);

        $stmt = $pdo->prepare("
          INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
          VALUES (?,?,?)
        ");
        $stmt->execute([(int)$user['id'], $tokenHash, $expiresAt]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/forgot_password.php')), '/');
        $resetUrl = $scheme . '://' . $host . $basePath . '/reset_password.php?token=' . urlencode($token);

        $subject = "Recuperação de senha - Armaria";
        $body = "Foi solicitada a recuperação de senha.\r\n\r\n"
          . "Use o link abaixo (válido por 1 hora):\r\n"
          . $resetUrl . "\r\n\r\n"
          . "Se você não solicitou, ignore este e-mail.";
        $headers = "From: nao-responder@armaria.local\r\n";

        $sent = @mail((string)$user['email'], $subject, $body, $headers);
        app_audit('FORGOT_PASSWORD_REQUEST', ['user_id' => (int)$user['id'], 'email_sent' => (bool)$sent]);
        if (!$sent) {
          $isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
          if ($isLocal) {
            $debugLink = $resetUrl;
          }
        }
      }

      // resposta genérica para não expor se o usuário existe
      $success = true;
      $msg = "Se os dados estiverem corretos, você receberá um e-mail com instruções para redefinir a senha.";
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperar senha - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="armaria-auth">
  <header class="auth-topbar">
    <div class="brand">Armaria</div>
  </header>
  <main class="auth-main">
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="header">
        <h2>Recuperar senha</h2>
        <p>Informe seu usuário ou e-mail cadastrado para receber o link de redefinição.</p>
      </div>
      <div class="body">

      <?php if ($msg): ?>
        <div class="auth-alert <?= $success ? 'success' : '' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="post">
        <?= csrf_input_field() ?>
        <label class="auth-label">Usuário ou e-mail</label>
        <input class="auth-input" name="identifier" required>
        <button class="auth-btn" type="submit">Enviar link de recuperação</button>
      </form>
      <?php endif; ?>

      <?php if ($debugLink): ?>
        <div style="margin-top:10px;font-size:12px;word-break:break-all;color:#555;"><b>Ambiente local:</b> link direto: <a href="<?= htmlspecialchars($debugLink) ?>"><?= htmlspecialchars($debugLink) ?></a></div>
      <?php endif; ?>

      <p class="auth-links"><a href="login.php">Voltar ao login</a></p>
      <div class="auth-footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
  </main>
</body>
</html>
