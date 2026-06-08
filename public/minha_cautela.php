<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_POLICIAL]);

function br_date(string $value): string {
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$policial_id = (int)$_SESSION['user']['id'];

$stmt = $pdo->prepare("
  SELECT w.tipo, w.modelo, w.calibre, w.numero_serie, ca.assigned_at, a.name AS armeiro
  FROM current_assignments ca
  JOIN weapons w ON w.id = ca.weapon_id
  JOIN users a ON a.id = ca.armeiro_id
  WHERE ca.policial_id = ?
  ORDER BY ca.assigned_at DESC
");
$stmt->execute([$policial_id]);
$currentWeapons = $stmt->fetchAll();

$stmt = $pdo->prepare("
  SELECT v.tamanho, v.numero_serie, cva.assigned_at, a.name AS armeiro
  FROM current_vest_assignments cva
  JOIN vests v ON v.id = cva.vest_id
  JOIN users a ON a.id = cva.armeiro_id
  WHERE cva.policial_id = ?
  ORDER BY cva.assigned_at DESC
");
$stmt->execute([$policial_id]);
$currentVests = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Minhas cautelas - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Minhas cautelas ativas</h2>
        <p class="subtitle">Visualização de armas e coletes atualmente cautelados em seu nome.</p>
      </div>
      <div class="card-body">
        <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>

        <?php if (!empty($currentWeapons)): ?>
          <h3 class="section-title">Armas cauteladas</h3>
          <table>
            <tr><th>Arma</th><th>Calibre</th><th>Série</th><th>Desde</th><th>Armeiro</th></tr>
            <?php foreach ($currentWeapons as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['tipo']." / ".$item['modelo']) ?></td>
                <td><?= htmlspecialchars($item['calibre']) ?></td>
                <td><?= htmlspecialchars($item['numero_serie']) ?></td>
                <td><?= htmlspecialchars(br_date($item['assigned_at'])) ?></td>
                <td><?= htmlspecialchars($item['armeiro']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <p class="hint">Você não possui arma cautelada no momento.</p>
        <?php endif; ?>

        <?php if (!empty($currentVests)): ?>
          <h3 class="section-title">Coletes cautelados</h3>
          <table>
            <tr><th>Tamanho</th><th>Série</th><th>Desde</th><th>Armeiro</th></tr>
            <?php foreach ($currentVests as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['tamanho']) ?></td>
                <td><?= htmlspecialchars($item['numero_serie']) ?></td>
                <td><?= htmlspecialchars(br_date($item['assigned_at'])) ?></td>
                <td><?= htmlspecialchars($item['armeiro']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <p class="hint">Você não possui colete cautelado no momento.</p>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
