<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/combined_receipt.php";
require_login();

function br_date_time(string $value): string
{
  $ts = strtotime($value);
  return $ts ? date('d/m/Y H:i', $ts) : $value;
}

if (!ensure_combined_receipt_tables($pdo)) {
  http_response_code(500);
  exit("Estrutura de comprovante combinado indisponível.");
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
  http_response_code(400);
  exit("Comprovante inválido.");
}

$user = $_SESSION['user'] ?? [];
$role = (string)($user['role'] ?? '');
$userId = (int)($user['id'] ?? 0);

$sql = "
  SELECT cr.id, cr.receipt_code, cr.policial_id, cr.armeiro_id, cr.action, cr.created_at,
         up.name AS policial, ua.name AS armeiro
  FROM combined_receipts cr
  LEFT JOIN users up ON up.id = cr.policial_id
  LEFT JOIN users ua ON ua.id = cr.armeiro_id
  WHERE cr.receipt_code = ?
";
$params = [$code];
if ($role === ROLE_POLICIAL) {
  $sql .= " AND cr.policial_id = ? ";
  $params[] = $userId;
} elseif (!in_array($role, [ROLE_ADMIN, ROLE_ARMEIRO], true)) {
  http_response_code(403);
  exit("Acesso negado.");
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$header = $stmt->fetch();
if (!$header) {
  http_response_code(404);
  exit("Comprovante não encontrado.");
}

$stmt = $pdo->prepare("
  SELECT movement_type, movement_id
  FROM combined_receipt_items
  WHERE combined_receipt_id = ?
  ORDER BY id ASC
");
$stmt->execute([(int)$header['id']]);
$items = $stmt->fetchAll();

$weaponMovementIds = [];
$vestMovementIds = [];
foreach ($items as $it) {
  $type = (string)($it['movement_type'] ?? '');
  $movementId = (int)($it['movement_id'] ?? 0);
  if ($movementId <= 0) {
    continue;
  }
  if ($type === 'WEAPON') {
    $weaponMovementIds[] = $movementId;
  } elseif ($type === 'VEST') {
    $vestMovementIds[] = $movementId;
  }
}

$weapons = [];
if (!empty($weaponMovementIds)) {
  $ph = implode(',', array_fill(0, count($weaponMovementIds), '?'));
  $stmt = $pdo->prepare("
    SELECT m.id AS movement_id, r.receipt_code,
           w.tipo, w.modelo, w.calibre, w.numero_serie,
           m.action_at, m.note
    FROM movements m
    LEFT JOIN weapons w ON w.id = m.weapon_id
    LEFT JOIN receipts r ON r.movement_id = m.id
    WHERE m.id IN ($ph)
    ORDER BY m.id ASC
  ");
  $stmt->execute($weaponMovementIds);
  $weapons = $stmt->fetchAll();
}

$vests = [];
if (!empty($vestMovementIds)) {
  $ph = implode(',', array_fill(0, count($vestMovementIds), '?'));
  $stmt = $pdo->prepare("
    SELECT vm.id AS movement_id, vr.receipt_code,
           v.tamanho, v.numero_serie, vm.created_at
    FROM vest_movements vm
    JOIN vests v ON v.id = vm.vest_id
    LEFT JOIN vest_receipts vr ON vr.movement_id = vm.id
    WHERE vm.id IN ($ph)
    ORDER BY vm.id ASC
  ");
  $stmt->execute($vestMovementIds);
  $vests = $stmt->fetchAll();
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
  <head><meta charset="utf-8"><title>Comprovante unificado</title></head>
  <body style="font-family:Arial,sans-serif;max-width:980px;margin:24px auto;line-height:1.5;">
    <h2>Comprovante Unificado de Cautela</h2>
    <p><b>Código:</b> <?= htmlspecialchars($header['receipt_code']) ?></p>
    <p><b>Emitido em:</b> <?= htmlspecialchars(br_date_time((string)$header['created_at'])) ?></p>
    <p><b>Policial:</b> <?= htmlspecialchars((string)($header['policial'] ?? '-')) ?></p>
    <p><b>Armeiro:</b> <?= htmlspecialchars((string)($header['armeiro'] ?? '-')) ?></p>

    <h3>Armas</h3>
    <?php if (empty($weapons)): ?>
      <p>Nenhuma arma neste comprovante.</p>
    <?php else: ?>
      <table border="1" cellpadding="6" cellspacing="0" width="100%">
        <tr><th>Comprovante</th><th>Arma</th><th>Série</th><th>Munição</th></tr>
        <?php foreach ($weapons as $w): ?>
          <?php
            $ammoText = '-';
            if (!empty($w['note'])) {
              $decoded = json_decode((string)$w['note'], true);
              if (is_array($decoded) && (
                !empty($decoded['ammo_qty'])
                || !empty($decoded['ammo_id'])
                || !empty($decoded['magazine_qty'])
              )) {
                $ammoText = ((int)$decoded['ammo_qty'] > 0)
                  ? ((int)$decoded['ammo_qty'] . 'x ' . (string)($decoded['ammo_calibre'] ?? '') . ' ' . (string)($decoded['ammo_tipo'] ?? ''))
                  : ((string)($decoded['ammo_calibre'] ?? '') . ' ' . (string)($decoded['ammo_tipo'] ?? ''));
                if (isset($decoded['magazine_qty'])) {
                  $ammoText .= ' | Carreg.: ' . (int)$decoded['magazine_qty'];
                }
              }
            }
            $weaponText = trim((string)($w['tipo'] ?? '')) !== ''
              ? ((string)$w['tipo'] . ' / ' . (string)$w['modelo'] . ' / ' . (string)$w['calibre'])
              : 'MUNICAO/CARREGADOR (sem arma)';
          ?>
          <tr>
            <td><?= htmlspecialchars((string)($w['receipt_code'] ?? '-')) ?></td>
            <td><?= htmlspecialchars($weaponText) ?></td>
            <td><?= htmlspecialchars((string)($w['numero_serie'] ?? '-')) ?></td>
            <td><?= htmlspecialchars($ammoText) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <h3>Coletes</h3>
    <?php if (empty($vests)): ?>
      <p>Nenhum colete neste comprovante.</p>
    <?php else: ?>
      <table border="1" cellpadding="6" cellspacing="0" width="100%">
        <tr><th>Comprovante</th><th>Tamanho</th><th>Série</th></tr>
        <?php foreach ($vests as $v): ?>
          <tr>
            <td><?= htmlspecialchars((string)($v['receipt_code'] ?? '-')) ?></td>
            <td><?= htmlspecialchars((string)$v['tamanho']) ?></td>
            <td><?= htmlspecialchars((string)$v['numero_serie']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </body>
  </html>
  <?php
  exit;
}

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 9, utf8_decode("Comprovante Unificado de Cautela"), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, utf8_decode("Código: " . (string)$header['receipt_code']), 0, 1, 'C');
$pdf->Cell(0, 6, utf8_decode("Emitido em: " . br_date_time((string)$header['created_at'])), 0, 1, 'C');
$pdf->Cell(0, 6, utf8_decode("Policial: " . (string)($header['policial'] ?? '-')), 0, 1, 'C');
$pdf->Cell(0, 6, utf8_decode("Armeiro: " . (string)($header['armeiro'] ?? '-')), 0, 1, 'C');
$pdf->Ln(2);

$section = function(string $title) use ($pdf): void {
  $pdf->SetFont('Arial', 'B', 10);
  $pdf->SetFillColor(230, 230, 230);
  $pdf->Cell(190, 7, utf8_decode($title), 1, 1, 'C', true);
};

$section('Armas');
if (empty($weapons)) {
  $pdf->SetFont('Arial', '', 9);
  $pdf->Cell(190, 7, utf8_decode("Nenhuma arma neste comprovante."), 1, 1, 'C');
} else {
  $pdf->SetFont('Arial', 'B', 8);
  $pdf->Cell(34, 7, 'Comprovante', 1, 0, 'C');
  $pdf->Cell(64, 7, 'Arma', 1, 0, 'C');
  $pdf->Cell(32, 7, utf8_decode('Série'), 1, 0, 'C');
  $pdf->Cell(60, 7, utf8_decode('Munição'), 1, 1, 'C');
  $pdf->SetFont('Arial', '', 8);
  foreach ($weapons as $w) {
    $ammoText = '-';
    if (!empty($w['note'])) {
      $decoded = json_decode((string)$w['note'], true);
      if (is_array($decoded) && (
        !empty($decoded['ammo_qty'])
        || !empty($decoded['ammo_id'])
        || !empty($decoded['magazine_qty'])
      )) {
        $ammoText = ((int)$decoded['ammo_qty'] > 0)
          ? ((int)$decoded['ammo_qty'] . 'x ' . (string)($decoded['ammo_calibre'] ?? '') . ' ' . (string)($decoded['ammo_tipo'] ?? ''))
          : ((string)($decoded['ammo_calibre'] ?? '') . ' ' . (string)($decoded['ammo_tipo'] ?? ''));
        if (isset($decoded['magazine_qty'])) {
          $ammoText .= ' | Carreg.: ' . (int)$decoded['magazine_qty'];
        }
      }
    }
    $weaponText = trim((string)($w['tipo'] ?? '')) !== ''
      ? ((string)$w['tipo'] . ' / ' . (string)$w['modelo'] . ' / ' . (string)$w['calibre'])
      : 'MUNICAO/CARREGADOR';
    $pdf->Cell(34, 7, utf8_decode((string)($w['receipt_code'] ?? '-')), 1, 0, 'C');
    $pdf->Cell(64, 7, utf8_decode($weaponText), 1, 0, 'C');
    $pdf->Cell(32, 7, utf8_decode((string)($w['numero_serie'] ?? '-')), 1, 0, 'C');
    $pdf->Cell(60, 7, utf8_decode($ammoText), 1, 1, 'C');
  }
}

$pdf->Ln(2);
$section('Coletes');
if (empty($vests)) {
  $pdf->SetFont('Arial', '', 9);
  $pdf->Cell(190, 7, utf8_decode("Nenhum colete neste comprovante."), 1, 1, 'C');
} else {
  $pdf->SetFont('Arial', 'B', 8);
  $pdf->Cell(50, 7, 'Comprovante', 1, 0, 'C');
  $pdf->Cell(40, 7, 'Tamanho', 1, 0, 'C');
  $pdf->Cell(100, 7, utf8_decode('Série'), 1, 1, 'C');
  $pdf->SetFont('Arial', '', 8);
  foreach ($vests as $v) {
    $pdf->Cell(50, 7, utf8_decode((string)($v['receipt_code'] ?? '-')), 1, 0, 'C');
    $pdf->Cell(40, 7, utf8_decode((string)$v['tamanho']), 1, 0, 'C');
    $pdf->Cell(100, 7, utf8_decode((string)$v['numero_serie']), 1, 1, 'C');
  }
}

$pdf->Output('I', 'comprovante_unificado_' . (string)$header['receipt_code'] . '.pdf');
exit;
