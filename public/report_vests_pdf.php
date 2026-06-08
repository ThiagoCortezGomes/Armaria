<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

function br_date(string $value): string
{
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$valid_from = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from);
$valid_to = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);
$hasValidade = false;
try {
  $check = $pdo->query("SHOW COLUMNS FROM vests LIKE 'validade'");
  $hasValidade = (bool)$check->fetchColumn();
} catch (Throwable $e) {
  $hasValidade = false;
}

$periodLabel = 'Todos os períodos';
if ($valid_from && $valid_to) {
  $periodLabel = br_date($date_from) . ' até ' . br_date($date_to);
} elseif ($valid_from) {
  $periodLabel = 'A partir de ' . br_date($date_from);
} elseif ($valid_to) {
  $periodLabel = 'Até ' . br_date($date_to);
}

$sqlCautelados = "
  SELECT v.tamanho, v.numero_serie, " . ($hasValidade ? "v.validade" : "NULL") . " AS validade, u.name AS policial, cva.assigned_at
  FROM current_vest_assignments cva
  JOIN vests v ON v.id = cva.vest_id
  JOIN users u ON u.id = cva.policial_id
  WHERE 1=1
";
$paramsCautelados = [];
if ($valid_from) {
  $sqlCautelados .= " AND cva.assigned_at >= ? ";
  $paramsCautelados[] = $date_from . " 00:00:00";
}
if ($valid_to) {
  $sqlCautelados .= " AND cva.assigned_at <= ? ";
  $paramsCautelados[] = $date_to . " 23:59:59";
}
$sqlCautelados .= " ORDER BY cva.assigned_at DESC ";
$stmt = $pdo->prepare($sqlCautelados);
$stmt->execute($paramsCautelados);
$cautelados = $stmt->fetchAll();

$sqlDisponiveis = "
  SELECT tamanho, numero_serie, " . ($hasValidade ? "validade" : "NULL") . " AS validade, created_at
  FROM vests
  WHERE status='" . VEST_STATUS_DISPONIVEL . "'
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

$fpdfPath = __DIR__ . "/../vendor/fpdf/fpdf.php";
if (is_file($fpdfPath)) {
  require_once $fpdfPath;
}

if (!class_exists('FPDF')) {
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html lang="pt-br">
  <head><meta charset="utf-8"><title>Relatório de Coletes</title></head>
  <body style="font-family:Arial,sans-serif;max-width:980px;margin:24px auto;line-height:1.5;">
    <h2>Relatório de Coletes</h2>
    <p><b>Emitido em:</b> <?= date('d/m/Y') ?></p>
    <p><b>Período:</b> <?= htmlspecialchars($periodLabel) ?></p>
    <h3>Coletes Cautelados</h3>
    <?php if (empty($cautelados)): ?>
      <p>Nenhum colete cautelado no momento.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($cautelados as $c): ?>
        <li><?= htmlspecialchars("Série {$c['numero_serie']} / Tam {$c['tamanho']} / Validade " . ($c['validade'] ? br_date((string)$c['validade']) : '-')) ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p style="text-align:center;"><b>Total cautelados:</b> <?= (int)count($cautelados) ?></p>
    <h3>Coletes Disponíveis</h3>
    <?php if (empty($disponiveis)): ?>
      <p>Nenhum colete disponível no momento.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($disponiveis as $d): ?>
        <li><?= htmlspecialchars("Série {$d['numero_serie']} / Tam {$d['tamanho']} / Validade " . ($d['validade'] ? br_date((string)$d['validade']) : '-')) ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p style="text-align:center;"><b>Total disponíveis:</b> <?= (int)count($disponiveis) ?></p>
    <hr>
    <p><small>FPDF não encontrada em <code>vendor/fpdf/fpdf.php</code>. Exibindo versão HTML imprimível.</small></p>
  </body>
  </html>
  <?php
  exit;
}

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, utf8_decode("Relatório de Coletes"), 0, 1, 'C');

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, utf8_decode("Emitido em: " . date('d/m/Y')), 0, 1, 'C');
$pdf->Cell(0, 7, utf8_decode("Período: " . $periodLabel), 0, 1, 'C');
$pdf->Ln(2);

$drawSectionTitle = function(string $title) use ($pdf): void {
  $pdf->Ln(2);
  $pdf->SetFont('Arial', 'B', 11);
  $pdf->SetFillColor(230, 230, 230);
  $pdf->Cell(190, 8, utf8_decode($title), 1, 1, 'C', true);
};

$drawHeader = function(array $cols) use ($pdf): void {
  $pdf->SetFont('Arial', 'B', 8);
  $pdf->SetFillColor(245, 245, 245);
  foreach ($cols as $col) {
    $pdf->Cell($col['w'], 7, utf8_decode($col['t']), 1, 0, 'C', true);
  }
  $pdf->Ln();
};

$drawRows = function(array $rows, array $cols, callable $mapFn) use ($pdf, $drawHeader): void {
  if (empty($rows)) {
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(190, 7, utf8_decode("Nenhum registro encontrado no período."), 1, 1, 'C');
    return;
  }

  $drawHeader($cols);
  $pdf->SetFont('Arial', '', 8);
  $i = 1;
  foreach ($rows as $row) {
    $cells = $mapFn($row, $i);
    foreach ($cols as $idx => $col) {
      $pdf->Cell($col['w'], 7, utf8_decode((string)($cells[$idx] ?? '')), 1, 0, 'C');
    }
    $pdf->Ln();
    $i++;
  }
};

$drawTotal = function(string $label, int $total) use ($pdf): void {
  $pdf->SetFont('Arial', 'B', 8.5);
  $pdf->SetFillColor(245, 245, 245);
  $pdf->Cell(190, 7, utf8_decode($label . ': ' . $total), 1, 1, 'C', true);
};

$colsCautelados = [
  ['t' => 'Ordem', 'w' => 20],
  ['t' => 'Número de série', 'w' => 70],
  ['t' => 'Tamanho', 'w' => 40],
  ['t' => 'Validade', 'w' => 60],
];

$colsDisponiveis = [
  ['t' => 'Ordem', 'w' => 20],
  ['t' => 'Número de série', 'w' => 70],
  ['t' => 'Tamanho', 'w' => 40],
  ['t' => 'Validade', 'w' => 60],
];

$drawSectionTitle('Coletes Cautelados');
$drawRows(
  $cautelados,
  $colsCautelados,
  fn(array $r, int $n): array => [
    $n,
    $r['numero_serie'],
    $r['tamanho'],
    !empty($r['validade']) ? br_date((string)$r['validade']) : '-',
  ]
);
$drawTotal('Total cautelados', count($cautelados));

$drawSectionTitle('Coletes Disponíveis');
$drawRows(
  $disponiveis,
  $colsDisponiveis,
  fn(array $r, int $n): array => [
    $n,
    $r['numero_serie'],
    $r['tamanho'],
    !empty($r['validade']) ? br_date((string)$r['validade']) : '-',
  ]
);
$drawTotal('Total disponíveis', count($disponiveis));

$pdf->Output("I", "relatorio_coletes.pdf");
exit;
