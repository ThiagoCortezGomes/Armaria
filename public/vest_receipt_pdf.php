<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_login();

function br_date(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    http_response_code(400);
    exit("Código inválido.");
}

$stmt = $pdo->prepare("
  SELECT vr.receipt_code, vm.action, vm.created_at,
         v.tamanho, v.numero_serie,
         up.name AS policial, ua.name AS armeiro
  FROM vest_receipts vr
  JOIN vest_movements vm ON vm.id = vr.movement_id
  JOIN vests v ON v.id = vm.vest_id
  JOIN users up ON up.id = vm.policial_id
  JOIN users ua ON ua.id = vm.armeiro_id
  WHERE vr.receipt_code = ?
");
$stmt->execute([$code]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    exit("Comprovante não encontrado.");
}

$fpdfPath = __DIR__ . "/../vendor/fpdf/fpdf.php";
if (is_file($fpdfPath)) {
    require_once $fpdfPath;
}

if (!class_exists('FPDF')) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head><meta charset="utf-8"><title>Comprovante <?= htmlspecialchars($r['receipt_code']) ?></title></head>
    <body style="font-family:Arial,sans-serif;max-width:820px;margin:24px auto;line-height:1.5;">
      <h2>Comprovante - Colete Balístico</h2>
      <p><b>Código:</b> <?= htmlspecialchars($r['receipt_code']) ?></p>
      <p><b>Ação:</b> <?= htmlspecialchars($r['action']) ?></p>
      <p><b>Data:</b> <?= htmlspecialchars(br_date($r['created_at'])) ?></p>
      <h3>Dados do Colete</h3>
      <p><b>Colete:</b> Tam <?= htmlspecialchars($r['tamanho']) ?> | Série <?= htmlspecialchars($r['numero_serie']) ?></p>
      <h3>Responsáveis</h3>
      <p><b>Policial:</b> <?= htmlspecialchars($r['policial']) ?></p>
      <p><b>Armeiro:</b> <?= htmlspecialchars($r['armeiro']) ?></p>
      <hr>
      <p><small>FPDF não encontrada em <code>vendor/fpdf/fpdf.php</code>. Exibindo versão HTML imprimível.</small></p>
    </body>
    </html>
    <?php
    exit;
}

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, utf8_decode("COMPROVANTE - COLETE BALÍSTICO"), 0, 1, 'C');

$pdf->SetFont('Arial', '', 11);
$pdf->Ln(4);
$pdf->Cell(0, 8, utf8_decode("Código: " . $r['receipt_code']), 0, 1);
$pdf->Cell(0, 8, utf8_decode("Ação: " . $r['action']), 0, 1);
$pdf->Cell(0, 8, utf8_decode("Data: " . br_date($r['created_at'])), 0, 1);
$pdf->Ln(2);
$pdf->Cell(0, 8, utf8_decode("Colete: Tam " . $r['tamanho'] . " | Série " . $r['numero_serie']), 0, 1);
$pdf->Cell(0, 8, utf8_decode("Policial: " . $r['policial']), 0, 1);
$pdf->Cell(0, 8, utf8_decode("Armeiro: " . $r['armeiro']), 0, 1);

$pdf->Output("I", "comprovante_colete.pdf");
