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

$periodLabel = 'Todos os períodos';
if ($valid_from && $valid_to) {
  $periodLabel = br_date($date_from) . ' até ' . br_date($date_to);
} elseif ($valid_from) {
  $periodLabel = 'A partir de ' . br_date($date_from);
} elseif ($valid_to) {
  $periodLabel = 'Até ' . br_date($date_to);
}

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

$fpdfPath = __DIR__ . "/../vendor/fpdf/fpdf.php";
if (is_file($fpdfPath)) {
  require_once $fpdfPath;
}

if (!class_exists('FPDF')) {
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html lang="pt-br">
  <head><meta charset="utf-8"><title>Relatório de Armamento</title></head>
  <body style="font-family:Arial,sans-serif;max-width:980px;margin:24px auto;line-height:1.5;">
    <h2>Relatório de Armamento</h2>
    <p><b>Emitido em:</b> <?= date('d/m/Y') ?></p>
    <p><b>Período:</b> <?= htmlspecialchars($periodLabel) ?></p>
    <h3>Armas Cauteladas</h3>
    <?php if (empty($cauteladas)): ?>
      <p>Nenhuma arma cautelada no momento.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($cauteladas as $c): ?>
        <li><?= htmlspecialchars("{$c['tipo']} / {$c['modelo']} / {$c['calibre']} / Série {$c['numero_serie']} | Com {$c['policial']} | Desde " . br_date($c['assigned_at'])) ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p style="text-align:center;"><b>Total cauteladas:</b> <?= (int)count($cauteladas) ?></p>
    <h3>Armas Disponíveis</h3>
    <?php if (empty($disponiveis)): ?>
      <p>Nenhuma arma disponível no momento.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($disponiveis as $d): ?>
        <li><?= htmlspecialchars("{$d['tipo']} / {$d['modelo']} / {$d['calibre']} / Série {$d['numero_serie']}") ?></li>
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
$pdf->SetFont('Arial','B',14);
$pdf->Cell(0,10,utf8_decode("Relatório de Armamento"),0,1,'C');

$pdf->SetFont('Arial','',10);
$pdf->Cell(0,7,utf8_decode("Emitido em: ".date('d/m/Y')),0,1,'C');
$pdf->Cell(0,7,utf8_decode("Período: ".$periodLabel),0,1,'C');
$pdf->Ln(2);

$ensureSpace = function(int $height) use ($pdf): void {
  if ($pdf->GetY() + $height > 275) {
    $pdf->AddPage();
  }
};

$drawSectionTitle = function(string $title) use ($pdf, $ensureSpace): void {
  $ensureSpace(10);
  $pdf->Ln(2);
  $pdf->SetFont('Arial','B',11);
  $pdf->SetFillColor(230, 230, 230);
  $pdf->Cell(190, 8, utf8_decode($title), 1, 1, 'C', true);
};

$drawHeader = function(array $cols) use ($pdf): void {
  $pdf->SetFont('Arial', 'B', 7.5);
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

  $countLines = function(float $w, string $txt) use ($pdf): int {
    $available = max(1.0, $w - 2.0); // margem interna aproximada da célula
    $lines = 1;
    $current = '';
    $parts = preg_split("/(\s+)/", str_replace("\r", '', $txt), -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
      return 1;
    }

    foreach ($parts as $part) {
      if ($part === "\n") {
        $lines++;
        $current = '';
        continue;
      }

      $candidate = $current . $part;
      if ($pdf->GetStringWidth($candidate) <= $available) {
        $current = $candidate;
        continue;
      }

      if (trim($part) === '') {
        $lines++;
        $current = '';
        continue;
      }

      if ($current !== '') {
        $lines++;
      }
      $current = '';

      $chars = str_split($part);
      foreach ($chars as $ch) {
        $candidateChar = $current . $ch;
        if ($pdf->GetStringWidth($candidateChar) <= $available) {
          $current = $candidateChar;
        } else {
          $lines++;
          $current = $ch;
        }
      }
    }
    return max(1, $lines);
  };

  $drawHeader($cols);
  $pdf->SetFont('Arial', '', 7.5);
  $lineHeight = 4.5;
  $pageBreakY = 275;
  $tableStartX = $pdf->GetX();
  $i = 1;
  foreach ($rows as $row) {
    $cells = $mapFn($row, $i);

    $maxLines = 1;
    foreach ($cols as $idx => $col) {
      $txt = utf8_decode((string)($cells[$idx] ?? ''));
      $maxLines = max($maxLines, $countLines($col['w'], $txt));
    }
    $rowHeight = $lineHeight * $maxLines;

    if ($pdf->GetY() + $rowHeight > $pageBreakY) {
      $pdf->AddPage();
      $drawHeader($cols);
      $pdf->SetFont('Arial', '', 8);
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();
    foreach ($cols as $idx => $col) {
      $txt = utf8_decode((string)($cells[$idx] ?? ''));
      // Draw a fixed-height cell border so all columns stay aligned
      $pdf->Rect($x, $y, $col['w'], $rowHeight);
      $textLines = max(1, $countLines($col['w'], $txt));
      $textHeight = $textLines * $lineHeight;
      $textY = $y + (($rowHeight - $textHeight) / 2);
      $pdf->SetXY($x, $textY);
      $pdf->MultiCell($col['w'], $lineHeight, $txt, 0, 'C');
      $x += $col['w'];
    }
    $pdf->SetXY($tableStartX, $y + $rowHeight);
    $i++;
  }
};

$drawTotal = function(string $label, int $total) use ($pdf, $ensureSpace): void {
  $ensureSpace(8);
  $pdf->SetFont('Arial', 'B', 8.5);
  $pdf->SetFillColor(245, 245, 245);
  $pdf->Cell(190, 7, utf8_decode($label . ': ' . $total), 1, 1, 'C', true);
};

$colsCauteladas = [
  ['t' => 'Nº',      'w' => 8],
  ['t' => 'Tipo',    'w' => 22],
  ['t' => 'Modelo',  'w' => 28],
  ['t' => 'Calibre', 'w' => 18],
  ['t' => 'Série',   'w' => 30],
  ['t' => 'Policial','w' => 46],
  ['t' => 'Desde',   'w' => 38],
];

$colsDisponiveis = [
  ['t' => 'Nº',      'w' => 8],
  ['t' => 'Tipo',    'w' => 30],
  ['t' => 'Modelo',  'w' => 48],
  ['t' => 'Calibre', 'w' => 24],
  ['t' => 'Série',   'w' => 80],
];

$drawSectionTitle('Armas Cauteladas');
$drawRows(
  $cauteladas,
  $colsCauteladas,
  fn(array $r, int $n): array => [
    $n,
    $r['tipo'],
    $r['modelo'],
    $r['calibre'],
    $r['numero_serie'],
    $r['policial'],
    br_date($r['assigned_at']),
  ]
);
$drawTotal('Total cauteladas', count($cauteladas));

$drawSectionTitle('Armas Disponíveis');
$drawRows(
  $disponiveis,
  $colsDisponiveis,
  fn(array $r, int $n): array => [
    $n,
    $r['tipo'],
    $r['modelo'],
    $r['calibre'],
    $r['numero_serie'],
  ]
);
$drawTotal('Total disponíveis', count($disponiveis));

$pdf->Output("I", "relatorio_armaria.pdf");
exit;
