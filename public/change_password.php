<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

require_login();

$userId       = (int)$_SESSION['user']['id'];
$isForced     = !empty($_SESSION['user']['must_change_password']);
$msg          = "";
$success      = false;

if ($isForced && isset($_GET['force'])) {
  $msg = "Por segurança, você deve definir uma nova senha antes de continuar.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
    app_audit('CHANGE_PASSWORD_CSRF_INVALID', []);
  } else {
    $current = $_POST['current_password'] ?? '';
    $new1    = $_POST['new_password'] ?? '';
    $new2    = $_POST['confirm_password'] ?? '';

    if (!$current || !$new1 || !$new2) {
      $msg = "Preencha todos os campos.";
    } elseif ($new1 !== $new2) {
      $msg = "A nova senha e a confirmação não conferem.";
    } elseif (strlen($new1) < 8) {
      $msg = "A nova senha deve ter no mínimo 8 caracteres.";
    } else {
      $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
      $stmt->execute([$userId]);
      $row = $stmt->fetch();

      if (!$row || !password_verify($current, $row['password_hash'])) {
        $msg = "Senha atual incorreta." . ($isForced ? " Use seu CPF como senha atual." : "");
        app_audit('CHANGE_PASSWORD_FAIL', ['reason' => 'current_password_invalid']);
      } else {
        $newHash = password_hash($new1, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
        $stmt->execute([$newHash, $userId]);

        $_SESSION['user']['must_change_password'] = 0;
        $success = true;
        $msg = "Senha alterada com sucesso.";
        app_audit('CHANGE_PASSWORD_SUCCESS', ['forced' => $isForced]);
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
  <title>Alterar senha - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="card">
    <h2><?= $isForced ? 'Primeiro acesso — defina sua senha' : 'Alterar senha' ?></h2>

    <?php if ($isForced && !$success): ?>
      <div class="msg" style="background:#fff3cd;border-left:4px solid #f0ad4e;color:#7d5a00;">
        Seu acesso foi criado com a senha padrão (CPF).<br>
        <strong>Crie uma senha pessoal para continuar.</strong>
      </div>
    <?php endif; ?>

    <?php if ($msg && (!$isForced || $success || str_contains($msg, 'incorreta') || str_contains($msg, 'campo') || str_contains($msg, 'conferem') || str_contains($msg, 'mínimo'))): ?>
      <div class="msg <?= $success ? 'msg-success' : '' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post">
      <?= csrf_input_field() ?>
      <label><?= $isForced ? 'Senha atual (seu CPF, sem pontos e traços)' : 'Senha atual' ?></label>
      <input type="password" name="current_password" required autocomplete="current-password">

      <label>Nova senha <small style="color:#888;">(mínimo 8 caracteres)</small></label>
      <input type="password" name="new_password" required autocomplete="new-password">

      <label>Confirmar nova senha</label>
      <input type="password" name="confirm_password" required autocomplete="new-password">

      <button type="submit">Salvar nova senha</button>
    </form>
    <?php else: ?>
      <p style="text-align:center;color:#2e7d32;font-weight:600;">Senha atualizada! Redirecionando...</p>
      <script>setTimeout(function(){ window.location.href='dashboard.php'; }, 2000);</script>
    <?php endif; ?>

    <?php if (!$isForced): ?>
    <p style="margin-top:14px;text-align:center;">
      <a href="dashboard.php">Voltar ao painel</a>
    </p>
    <?php endif; ?>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>

