<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_POLICIAL]);

function br_date(string $value): string {
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$policial_id = (int)$_SESSION['user']['id'];
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$valid_from = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from);
$valid_to = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);

$sql = "
  SELECT r.receipt_code, r.created_at, m.action,
         w.tipo, w.modelo, w.calibre, w.numero_serie
  FROM receipts r
  JOIN movements m ON m.id = r.movement_id
  LEFT JOIN weapons w ON w.id = m.weapon_id
  WHERE m.policial_id = ?
";
$params = [$policial_id];

if ($valid_from) {
  $sql .= " AND r.created_at >= ? ";
  $params[] = $date_from . " 00:00:00";
}

if ($valid_to) {
  $sql .= " AND r.created_at <= ? ";
  $params[] = $date_to . " 23:59:59";
}

$sql .= " ORDER BY r.created_at DESC ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meus comprovantes - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Meus comprovantes</h2>
        <p class="subtitle">Consulta de comprovantes vinculados ao seu usuário.</p>
      </div>
      <div class="card-body">
        <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>

        <form method="get" class="form-row">
          <div>
            <label>Data inicial</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($valid_from ? $date_from : '') ?>">
          </div>
          <div>
            <label>Data final</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($valid_to ? $date_to : '') ?>">
          </div>
          <button type="submit">Filtrar</button>
          <a class="backbtn" href="meus_comprovantes.php">Limpar filtro</a>
        </form>

        <table>
          <tr>
            <th>Comprovante</th><th>Data</th><th>Ação</th><th>Arma</th><th>PDF</th>
          </tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['receipt_code']) ?></td>
              <td><?= htmlspecialchars(br_date($r['created_at'])) ?></td>
              <td><?= htmlspecialchars($r['action']) ?></td>
              <td>
                <?php
                  $hasWeapon = !empty($r['tipo']) || !empty($r['modelo']) || !empty($r['calibre']) || !empty($r['numero_serie']);
                  echo htmlspecialchars($hasWeapon
                    ? ($r['tipo']." / ".$r['modelo']." / ".$r['calibre']." / Série ".$r['numero_serie'])
                    : "MUNIÇÃO/CARREGADOR (sem arma)");
                ?>
              </td>
              <td><a target="_blank" rel="noopener" href="receipt_pdf.php?code=<?= urlencode($r['receipt_code']) ?>">Abrir</a></td>
            </tr>
          <?php endforeach; ?>
        </table>

        <?php if (empty($rows)): ?>
          <p class="hint">Nenhum comprovante encontrado.</p>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
