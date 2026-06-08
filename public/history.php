<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

function br_date(string $value): string {
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$weapons = $pdo->query("SELECT id, numero_serie, tipo, modelo FROM weapons ORDER BY id DESC")->fetchAll();

$weapon_id = (int)($_GET['weapon_id'] ?? 0);
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$valid_from = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from);
$valid_to = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);
$history = [];

if ($weapon_id) {
  $sql = "
    SELECT m.action_at, m.action, u.name AS policial, a.name AS armeiro, r.receipt_code
    FROM movements m
    LEFT JOIN users u ON u.id = m.policial_id
    JOIN users a ON a.id = m.armeiro_id
    LEFT JOIN receipts r ON r.movement_id = m.id
    WHERE m.weapon_id = ?
  ";
  $params = [$weapon_id];

  if ($valid_from) {
    $sql .= " AND m.action_at >= ? ";
    $params[] = $date_from . " 00:00:00";
  }

  if ($valid_to) {
    $sql .= " AND m.action_at <= ? ";
    $params[] = $date_to . " 23:59:59";
  }

  $sql .= " ORDER BY m.action_at DESC ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $history = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Histórico - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Histórico de movimentação</h2>
        <p class="subtitle">Consulta por arma com comprovantes emitidos.</p>
      </div>
      <div class="card-body">
        <div class="top-actions">
          <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
        </div>

        <form method="get" class="form-row">
          <div>
            <label>Arma</label>
            <select name="weapon_id" required>
              <option value="">Selecione a arma</option>
              <?php foreach ($weapons as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= $weapon_id===(int)$w['id']?'selected':'' ?>>
                  <?= htmlspecialchars($w['tipo']." / ".$w['modelo']." / Série ".$w['numero_serie']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Data inicial</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($valid_from ? $date_from : '') ?>">
          </div>
          <div>
            <label>Data final</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($valid_to ? $date_to : '') ?>">
          </div>
          <button type="submit">Ver histórico</button>
          <a class="backbtn" href="history.php">Limpar filtro</a>
        </form>

        <?php if ($weapon_id): ?>
          <table>
            <tr><th>Data</th><th>Ação</th><th>Policial</th><th>Armeiro</th><th>Comprovante</th></tr>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><?= htmlspecialchars(br_date($h['action_at'])) ?></td>
                <td><?= htmlspecialchars($h['action']) ?></td>
                <td><?= htmlspecialchars($h['policial'] ?? '-') ?></td>
                <td><?= htmlspecialchars($h['armeiro']) ?></td>
                <td>
                  <?php if (!empty($h['receipt_code'])): ?>
                    <?= htmlspecialchars($h['receipt_code']) ?> |
                    <a target="_blank" rel="noopener" href="receipt_pdf.php?code=<?= urlencode($h['receipt_code']) ?>">PDF</a>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php if (empty($history)): ?>
            <p class="hint">Nenhuma movimentação registrada para esta arma.</p>
          <?php endif; ?>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
