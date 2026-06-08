<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

$msg = "";
$success = false;
$csrf_ok = true;
$can_manage = (($_SESSION['user']['role'] ?? '') === ROLE_ADMIN);
$show_list = isset($_GET['list_munitions']) && $_GET['list_munitions'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate_request()) {
  $csrf_ok = false;
  $msg = "Sessão expirada ou token inválido. Recarregue a página.";
}

if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_munition'])) {
  if (!$can_manage) {
    $msg = "Somente ADMIN pode cadastrar munição.";
    app_audit('MUNITION_CREATE_DENIED', []);
  } else {
    $calibre = trim($_POST['calibre'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $quantidade = (int)($_POST['quantidade'] ?? 0);

    if ($calibre !== '' && $tipo !== '' && $quantidade >= 0) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO munitions (calibre, tipo, quantidade)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)
        ");
        $stmt->execute([$calibre, $tipo, $quantidade]);
        $success = true;
        $msg = "Munição cadastrada/atualizada com sucesso.";
        app_audit('MUNITION_CREATE_OR_INCREMENT_SUCCESS', ['calibre' => $calibre, 'tipo' => $tipo, 'quantidade' => $quantidade]);
      } catch (Throwable $e) {
        $msg = "Erro ao cadastrar munição.";
      }
    } else {
      $msg = "Preencha calibre, tipo e quantidade válida.";
    }
  }
}

if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_munition_id'])) {
  if (!$can_manage) {
    $msg = "Somente ADMIN pode ajustar quantidade.";
    app_audit('MUNITION_ADJUST_DENIED', []);
  } else {
    $id = (int)($_POST['adjust_munition_id'] ?? 0);
    $newQty = (int)($_POST['new_qty'] ?? -1);
    if ($id > 0 && $newQty >= 0) {
      try {
        $stmt = $pdo->prepare("UPDATE munitions SET quantidade=? WHERE id=?");
        $stmt->execute([$newQty, $id]);
        $success = true;
        $msg = "Quantidade atualizada com sucesso.";
        app_audit('MUNITION_ADJUST_SUCCESS', ['munition_id' => $id, 'new_qty' => $newQty]);
      } catch (Throwable $e) {
        $msg = "Erro ao atualizar quantidade.";
      }
    } else {
      $msg = "Quantidade inválida.";
    }
  }
}

$munitions = $pdo->query("
  SELECT id, calibre, tipo, quantidade, created_at
  FROM munitions
  ORDER BY calibre, tipo
")->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Munições - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
<div class="container">
  <div class="card">
    <div class="card-header">
      <h2 class="title">Controle de munições</h2>
      <p class="subtitle">Cadastro de munições por calibre/tipo e controle de quantidade em estoque.</p>
    </div>
    <div class="card-body">
      <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
      <a class="backbtn" href="munitions.php?list_munitions=1">Listar munições</a>
      <a class="backbtn" href="munitions.php">Ocultar munições</a>
      <a class="backbtn" href="report_munitions.php" target="_blank" rel="noopener">Relatório de munições</a>
      <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <div class="row">
        <div>
          <h3>Nova munição</h3>
          <form method="post">
            <?= csrf_input_field() ?>
            <input type="hidden" name="create_munition" value="1">
            <label>Calibre</label>
            <input name="calibre" placeholder="Ex: .40, 9mm, 5.56" required>
            <label>Tipo</label>
            <input name="tipo" placeholder="Ex: Treinamento, Operacional" required>
            <label>Quantidade</label>
            <input type="number" min="0" name="quantidade" required>
            <button type="submit">Salvar munição</button>
          </form>
          <p class="hint">* Somente ADMIN pode cadastrar/ajustar quantidade.</p>
        </div>
        <div>
          <h3>Estoque de munições</h3>
          <?php if (!$show_list): ?>
            <p class="hint">A relação está oculta. Clique em <b>Listar munições</b> para visualizar.</p>
          <?php else: ?>
            <table>
              <tr><th>Calibre</th><th>Tipo</th><th>Quantidade</th><th>Ação</th></tr>
              <?php foreach ($munitions as $m): ?>
                <tr>
                  <td><?= htmlspecialchars($m['calibre']) ?></td>
                  <td><?= htmlspecialchars($m['tipo']) ?></td>
                  <td><?= (int)$m['quantidade'] ?></td>
                  <td>
                    <?php if ($can_manage): ?>
                      <form class="inline" method="post">
                        <?= csrf_input_field() ?>
                        <input type="hidden" name="adjust_munition_id" value="<?= (int)$m['id'] ?>">
                        <input type="number" min="0" name="new_qty" value="<?= (int)$m['quantidade'] ?>" required>
                        <button type="submit">Atualizar</button>
                      </form>
                    <?php else: ?>
                      <span class="hint">Consulta</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
            <?php if (empty($munitions)): ?><p class="hint">Nenhuma munição cadastrada.</p><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
