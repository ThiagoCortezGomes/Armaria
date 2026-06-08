<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$msg = "";
$success = false;
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$tokenValid = (bool)preg_match('/^[a-f0-9]{64}$/', $token);
$tokenHash = $tokenValid ? hash('sha256', $token) : '';
$resetRow = null;

if ($tokenHash !== '') {
  $stmt = $pdo->prepare("
    SELECT prt.id, prt.user_id
    FROM password_reset_tokens prt
    WHERE prt.token_hash = ?
      AND prt.used_at IS NULL
      AND prt.expires_at >= NOW()
    LIMIT 1
  ");
  $stmt->execute([$tokenHash]);
  $resetRow = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
    app_audit('RESET_PASSWORD_CSRF_INVALID', []);
  } elseif (!$resetRow) {
    $msg = "Token inválido ou expirado. Solicite uma nova recuperação.";
    app_audit('RESET_PASSWORD_FAIL', ['reason' => 'invalid_or_expired_token']);
  } else {
    $newpass = $_POST['newpass'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($newpass === '' || $confirm === '') {
      $msg = "Preencha todos os campos.";
    } elseif ($newpass !== $confirm) {
      $msg = "A nova senha e a confirmação não conferem.";
    } elseif (strlen($newpass) < 8) {
      $msg = "A nova senha deve ter no mínimo 8 caracteres.";
    } else {
      $hash = password_hash($newpass, PASSWORD_DEFAULT);

      $pdo->beginTransaction();
      try {
        $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->execute([$hash, (int)$resetRow['user_id']]);

        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?");
        $stmt->execute([(int)$resetRow['id']]);

        $pdo->commit();
        $success = true;
        $msg = "Senha redefinida com sucesso.";
        app_audit('RESET_PASSWORD_SUCCESS', ['target_user_id' => (int)$resetRow['user_id']]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $msg = "Erro ao redefinir senha.";
        app_audit('RESET_PASSWORD_FAIL', ['reason' => 'db_error']);
      }
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redefinir senha - Armaria</title>
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
        <h2>Redefinir senha</h2>
        <p>Defina sua nova senha de acesso.</p>
      </div>
      <div class="body">

      <?php if ($msg): ?>
        <div class="auth-alert <?= $success ? 'success' : '' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <?php if (!$success && $resetRow): ?>
      <form method="post">
        <?= csrf_input_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label class="auth-label">Nova senha</label>
        <input class="auth-input" type="password" name="newpass" required>
        <label class="auth-label">Confirmar nova senha</label>
        <input class="auth-input" type="password" name="confirm" required>
        <button class="auth-btn" type="submit">Salvar nova senha</button>
      </form>
      <?php elseif (!$resetRow): ?>
        <p style="color:#555;">Token inválido ou expirado. Solicite uma nova recuperação.</p>
      <?php endif; ?>

      <p class="auth-links"><a href="login.php">Voltar ao login</a></p>
      <div class="auth-footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
  </main>
</body>
</html>
