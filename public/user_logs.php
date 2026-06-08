<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN]);

function br_date_time(string $value): string
{
  $ts = strtotime($value);
  return $ts ? date('d/m/Y H:i:s', $ts) : $value;
}

$userFilter = trim($_GET['user'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$validFrom = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom);
$validTo = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

$sql = "
  SELECT id, user_id, username, role, action, context_json, ip_address, created_at
  FROM user_logs
  WHERE 1=1
";
$params = [];

if ($userFilter !== '') {
  $sql .= " AND (username LIKE ? OR CAST(user_id AS CHAR) LIKE ?) ";
  $likeUser = '%' . $userFilter . '%';
  $params[] = $likeUser;
  $params[] = $likeUser;
}
if ($actionFilter !== '') {
  $sql .= " AND action LIKE ? ";
  $params[] = '%' . $actionFilter . '%';
}
if ($validFrom) {
  $sql .= " AND created_at >= ? ";
  $params[] = $dateFrom . " 00:00:00";
}
if ($validTo) {
  $sql .= " AND created_at <= ? ";
  $params[] = $dateTo . " 23:59:59";
}

$sql .= " ORDER BY id DESC LIMIT 300 ";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Logs de usuários - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Logs de usuários</h2>
        <p class="subtitle">Rastreamento das ações executadas no sistema (últimos 300 registros).</p>
      </div>
      <div class="card-body">
        <div class="top-actions">
          <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
        </div>

        <form method="get" class="form-row">
          <div>
            <label>Usuário (login ou ID)</label>
            <input name="user" value="<?= htmlspecialchars($userFilter) ?>" placeholder="Ex: admin ou 12">
          </div>
          <div>
            <label>Ação</label>
            <input name="action" value="<?= htmlspecialchars($actionFilter) ?>" placeholder="Ex: WEAPON_ASSIGN">
          </div>
          <div>
            <label>Data inicial</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($validFrom ? $dateFrom : '') ?>">
          </div>
          <div>
            <label>Data final</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($validTo ? $dateTo : '') ?>">
          </div>
          <button type="submit">Filtrar logs</button>
          <a class="backbtn" href="user_logs.php">Limpar</a>
        </form>

        <table>
          <tr>
            <th>ID</th>
            <th>Data/Hora</th>
            <th>Usuário</th>
            <th>Perfil</th>
            <th>Ação</th>
            <th>Contexto</th>
            <th>IP</th>
          </tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars(br_date_time($r['created_at'])) ?></td>
              <td><?= htmlspecialchars((string)($r['username'] ?? '-')) ?></td>
              <td><?= htmlspecialchars((string)($r['role'] ?? '-')) ?></td>
              <td><?= htmlspecialchars((string)$r['action']) ?></td>
              <td><code><?= htmlspecialchars((string)($r['context_json'] ?? '{}')) ?></code></td>
              <td><?= htmlspecialchars((string)($r['ip_address'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if (empty($rows)): ?>
          <p class="hint">Nenhum log encontrado para os filtros informados.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
