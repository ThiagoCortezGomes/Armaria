<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

$rows = $pdo->query("
  SELECT
    m.calibre,
    SUM(m.quantidade) AS disponivel,
    COALESCE(SUM(ca_total.cautelada), 0) AS cautelada
  FROM munitions m
  LEFT JOIN (
    SELECT ammo_id, SUM(ammo_qty) AS cautelada
    FROM current_assignments
    WHERE ammo_id IS NOT NULL
    GROUP BY ammo_id
  ) ca_total ON ca_total.ammo_id = m.id
  GROUP BY m.calibre
  ORDER BY m.calibre
")->fetchAll();

$total_disponivel = 0;
$total_cautelada = 0;
foreach ($rows as $r) {
  $total_disponivel += (int)$r['disponivel'];
  $total_cautelada += (int)$r['cautelada'];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Relatório de munições - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Relatório de munições</h2>
        <p class="subtitle">Resumo por calibre: munições disponíveis em estoque e munições cauteladas no momento.</p>
      </div>
      <div class="card-body">
        <div class="top-actions">
          <a class="backbtn" href="munitions.php">← Voltar para munições</a>
          <button class="backbtn" type="button" onclick="window.print()">Imprimir</button>
        </div>

        <div class="totals">
          <div class="pill">Total disponível<br><b><?= (int)$total_disponivel ?></b></div>
          <div class="pill">Total cautelada<br><b><?= (int)$total_cautelada ?></b></div>
        </div>

        <table>
          <tr>
            <th>Calibre</th>
            <th>Disponível</th>
            <th>Cautelada</th>
            <th>Total Geral</th>
          </tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['calibre']) ?></td>
              <td><?= (int)$r['disponivel'] ?></td>
              <td><?= (int)$r['cautelada'] ?></td>
              <td><?= (int)$r['disponivel'] + (int)$r['cautelada'] ?></td>
            </tr>
          <?php endforeach; ?>
        </table>

        <?php if (empty($rows)): ?>
          <p>Nenhuma munição cadastrada.</p>
        <?php endif; ?>

        <div class="footer">Emitido em <?= date('d/m/Y') ?> • Armaria</div>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
