<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

function br_date(string $value): string {
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$valid_from = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from);
$valid_to = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);

$sqlCauteladas = "
  SELECT w.tipo, w.modelo, w.calibre, w.numero_serie, u.name AS policial, ca.assigned_at
  FROM current_assignments ca
  JOIN weapons w ON w.id = ca.weapon_id
  JOIN users u ON u.id = ca.policial_id
  WHERE 1=1
";
$paramsCauteladas = [];

if ($valid_from) {
  $sqlCauteladas .= " AND ca.assigned_at >= ? ";
  $paramsCauteladas[] = $date_from . " 00:00:00";
}
if ($valid_to) {
  $sqlCauteladas .= " AND ca.assigned_at <= ? ";
  $paramsCauteladas[] = $date_to . " 23:59:59";
}
$sqlCauteladas .= " ORDER BY ca.assigned_at DESC ";
$stmt = $pdo->prepare($sqlCauteladas);
$stmt->execute($paramsCauteladas);
$cauteladas = $stmt->fetchAll();

$sqlDisponiveis = "
  SELECT tipo, modelo, calibre, numero_serie, created_at
  FROM weapons
  WHERE status='" . WEAPON_STATUS_DISPONIVEL . "'
";
$paramsDisponiveis = [];
if ($valid_from) {
  $sqlDisponiveis .= " AND created_at >= ? ";
  $paramsDisponiveis[] = $date_from . " 00:00:00";
}
if ($valid_to) {
  $sqlDisponiveis .= " AND created_at <= ? ";
  $paramsDisponiveis[] = $date_to . " 23:59:59";
}
$sqlDisponiveis .= " ORDER BY id DESC ";
$stmt = $pdo->prepare($sqlDisponiveis);
$stmt->execute($paramsDisponiveis);
$disponiveis = $stmt->fetchAll();

$sqlColetesCautelados = "
  SELECT v.tamanho, v.numero_serie, u.name AS policial, cva.assigned_at
  FROM current_vest_assignments cva
  JOIN vests v ON v.id = cva.vest_id
  JOIN users u ON u.id = cva.policial_id
  WHERE 1=1
";
$paramsColetesCautelados = [];
if ($valid_from) {
  $sqlColetesCautelados .= " AND cva.assigned_at >= ? ";
  $paramsColetesCautelados[] = $date_from . " 00:00:00";
}
if ($valid_to) {
  $sqlColetesCautelados .= " AND cva.assigned_at <= ? ";
  $paramsColetesCautelados[] = $date_to . " 23:59:59";
}
$sqlColetesCautelados .= " ORDER BY cva.assigned_at DESC ";
$stmt = $pdo->prepare($sqlColetesCautelados);
$stmt->execute($paramsColetesCautelados);
$coletesCautelados = $stmt->fetchAll();

$sqlColetesDisponiveis = "
  SELECT tamanho, numero_serie, created_at
  FROM vests
  WHERE status='" . VEST_STATUS_DISPONIVEL . "'
";
$paramsColetesDisponiveis = [];
if ($valid_from) {
  $sqlColetesDisponiveis .= " AND created_at >= ? ";
  $paramsColetesDisponiveis[] = $date_from . " 00:00:00";
}
if ($valid_to) {
  $sqlColetesDisponiveis .= " AND created_at <= ? ";
  $paramsColetesDisponiveis[] = $date_to . " 23:59:59";
}
$sqlColetesDisponiveis .= " ORDER BY id DESC ";
$stmt = $pdo->prepare($sqlColetesDisponiveis);
$stmt->execute($paramsColetesDisponiveis);
$coletesDisponiveis = $stmt->fetchAll();

$periodLabel = 'Todos os períodos';
if ($valid_from && $valid_to) {
  $periodLabel = br_date($date_from) . ' até ' . br_date($date_to);
} elseif ($valid_from) {
  $periodLabel = 'A partir de ' . br_date($date_from);
} elseif ($valid_to) {
  $periodLabel = 'Até ' . br_date($date_to);
}

$filename = 'relatorio_armaria_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";
?>
<html>
<head>
  <meta charset="utf-8">
</head>
<body>
  <table border="1">
    <tr><th colspan="6">Relatório de Armamento</th></tr>
    <tr><td colspan="6">Emitido em: <?= htmlspecialchars(date('d/m/Y')) ?></td></tr>
    <tr><td colspan="6">Período: <?= htmlspecialchars($periodLabel) ?></td></tr>
  </table>
  <br>
  <table border="1">
    <tr><th colspan="6">Armas Cauteladas</th></tr>
    <tr>
      <th>Tipo</th><th>Modelo</th><th>Calibre</th><th>Nº de Série</th><th>Policial</th><th>Desde</th>
    </tr>
    <?php if (empty($cauteladas)): ?>
      <tr><td colspan="6">Nenhuma arma cautelada no momento.</td></tr>
    <?php else: ?>
      <?php foreach ($cauteladas as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['tipo']) ?></td>
          <td><?= htmlspecialchars($c['modelo']) ?></td>
          <td><?= htmlspecialchars($c['calibre']) ?></td>
          <td><?= htmlspecialchars($c['numero_serie']) ?></td>
          <td><?= htmlspecialchars($c['policial']) ?></td>
          <td><?= htmlspecialchars(br_date($c['assigned_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
  <br>
  <table border="1">
    <tr><th colspan="5">Armas Disponíveis</th></tr>
    <tr>
      <th>Tipo</th><th>Modelo</th><th>Calibre</th><th>Nº de Série</th><th>Cadastrada em</th>
    </tr>
    <?php if (empty($disponiveis)): ?>
      <tr><td colspan="5">Nenhuma arma disponível no momento.</td></tr>
    <?php else: ?>
      <?php foreach ($disponiveis as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['tipo']) ?></td>
          <td><?= htmlspecialchars($d['modelo']) ?></td>
          <td><?= htmlspecialchars($d['calibre']) ?></td>
          <td><?= htmlspecialchars($d['numero_serie']) ?></td>
          <td><?= htmlspecialchars(br_date($d['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
  <br>
  <table border="1">
    <tr><th colspan="4">Coletes Cautelados</th></tr>
    <tr>
      <th>Tamanho</th><th>Nº de Série</th><th>Policial</th><th>Desde</th>
    </tr>
    <?php if (empty($coletesCautelados)): ?>
      <tr><td colspan="4">Nenhum colete cautelado no momento.</td></tr>
    <?php else: ?>
      <?php foreach ($coletesCautelados as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['tamanho']) ?></td>
          <td><?= htmlspecialchars($c['numero_serie']) ?></td>
          <td><?= htmlspecialchars($c['policial']) ?></td>
          <td><?= htmlspecialchars(br_date($c['assigned_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
  <br>
  <table border="1">
    <tr><th colspan="3">Coletes Disponíveis</th></tr>
    <tr>
      <th>Tamanho</th><th>Nº de Série</th><th>Cadastrado em</th>
    </tr>
    <?php if (empty($coletesDisponiveis)): ?>
      <tr><td colspan="3">Nenhum colete disponível no momento.</td></tr>
    <?php else: ?>
      <?php foreach ($coletesDisponiveis as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['tamanho']) ?></td>
          <td><?= htmlspecialchars($d['numero_serie']) ?></td>
          <td><?= htmlspecialchars(br_date($d['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
</body>
</html>
