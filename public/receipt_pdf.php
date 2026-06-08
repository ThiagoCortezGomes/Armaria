<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_login();

function br_date(string $value): string {
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
  http_response_code(400);
  die("Comprovante inválido.");
}

$user = $_SESSION['user'];
$role = $user['role'];
$user_id = (int)$user['id'];

$sql = "
  SELECT r.receipt_code, r.created_at,
         m.action, m.action_at, m.note,
         w.tipo, w.modelo, w.calibre, w.numero_serie,
         p.name AS policial, a.name AS armeiro
  FROM receipts r
  JOIN movements m ON m.id = r.movement_id
  LEFT JOIN weapons w ON w.id = m.weapon_id
  LEFT JOIN users p ON p.id = m.policial_id
  JOIN users a ON a.id = m.armeiro_id
  WHERE r.receipt_code = ?
";

$params = [$code];

if ($role === ROLE_POLICIAL) {
  // policial só pode ver comprovantes dele
  $sql .= " AND m.policial_id = ? ";
  $params[] = $user_id;
} elseif (!in_array($role, [ROLE_ADMIN, ROLE_ARMEIRO], true)) {
  http_response_code(403);
  die("Acesso negado.");
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetch();

if (!$data) {
  http_response_code(404);
  die("Comprovante não encontrado.");
}

$ammoInfo = null;
if (!empty($data['note'])) {
  $decoded = json_decode((string)$data['note'], true);
  if (is_array($decoded) && (
      !empty($decoded['ammo_qty'])
      || !empty($decoded['ammo_id'])
      || !empty($decoded['magazine_qty'])
    )) {
    $ammoInfo = [
      'qty' => (int)$decoded['ammo_qty'],
      'calibre' => (string)($decoded['ammo_calibre'] ?? ''),
      'tipo' => (string)($decoded['ammo_tipo'] ?? ''),
      'magazine_qty' => (int)($decoded['magazine_qty'] ?? 0),
    ];
  }
}

$itemTipo = trim((string)($data['tipo'] ?? ''));
$itemModelo = trim((string)($data['modelo'] ?? ''));
$itemCalibre = trim((string)($data['calibre'] ?? ''));
$itemSerie = trim((string)($data['numero_serie'] ?? ''));
$isWithoutWeapon = ($itemTipo === '' && $itemModelo === '' && $itemCalibre === '' && $itemSerie === '');
if ($isWithoutWeapon) {
  $itemTipo = 'MUNICAO/CARREGADOR';
  $itemModelo = '-';
  $itemCalibre = $ammoInfo['calibre'] ?? '-';
  $itemSerie = '-';
}

$fpdfPath = __DIR__ . "/../vendor/fpdf/fpdf.php";
if (is_file($fpdfPath)) {
  require_once $fpdfPath;
}

  if (!class_exists('FPDF')) {
  header('Content-Type: text/html; charset=utf-8');
  $issuedAt = br_date($data['created_at']);
  $actionAt = br_date($data['action_at']);
  ?>
  <!doctype html>
  <html lang="pt-br">
  <head>
    <meta charset="utf-8">
    <title>Comprovante <?= htmlspecialchars($data['receipt_code']) ?></title>
    <style>
      body{font-family:Arial,sans-serif;max-width:820px;margin:24px auto;line-height:1.4}
      h2,h3{text-align:center}
      table{width:100%;border-collapse:collapse;margin:10px 0}
      th,td{border:1px solid #333;padding:8px;vertical-align:top}
      th{background:#f0f0f0;text-align:center}
      .center{text-align:center}
      .small{font-size:12px;color:#444}
    </style>
  </head>
  <body>
    <h2>Comprovante de Movimentação - Armaria</h2>
    <table>
      <tr><th>Comprovante</th><th>Emitido em</th><th>Ação</th><th>Data da Ação</th></tr>
      <tr>
        <td class="center"><?= htmlspecialchars($data['receipt_code']) ?></td>
        <td class="center"><?= htmlspecialchars($issuedAt) ?></td>
        <td class="center"><?= htmlspecialchars($data['action']) ?></td>
        <td class="center"><?= htmlspecialchars($actionAt) ?></td>
      </tr>
    </table>
    <h3><?= $isWithoutWeapon ? 'Dados do Item' : 'Dados da Arma' ?></h3>
    <table>
      <tr><th>Tipo</th><th>Modelo</th><th>Calibre</th><th>Nº de Série</th></tr>
      <tr>
        <td class="center"><?= htmlspecialchars($itemTipo) ?></td>
        <td class="center"><?= htmlspecialchars($itemModelo) ?></td>
        <td class="center"><?= htmlspecialchars($itemCalibre) ?></td>
        <td class="center"><?= htmlspecialchars($itemSerie) ?></td>
      </tr>
    </table>
    <h3>Responsáveis</h3>
    <table>
      <tr><th>Policial</th><th>Armeiro</th></tr>
      <tr>
        <td class="center"><?= htmlspecialchars($data['policial'] ?? '-') ?></td>
        <td class="center"><?= htmlspecialchars($data['armeiro']) ?></td>
      </tr>
    </table>
    <?php if ($ammoInfo): ?>
      <h3>Munição</h3>
      <table>
        <tr><th>Quantidade</th><th>Calibre</th><th>Tipo</th><th>Carregadores</th></tr>
        <tr>
          <td class="center"><?= (int)$ammoInfo['qty'] ?></td>
          <td class="center"><?= htmlspecialchars($ammoInfo['calibre']) ?></td>
          <td class="center"><?= htmlspecialchars($ammoInfo['tipo']) ?></td>
          <td class="center"><?= (int)$ammoInfo['magazine_qty'] ?></td>
        </tr>
      </table>
    <?php endif; ?>
    <hr>
    <p class="small">FPDF não encontrada em <code>vendor/fpdf/fpdf.php</code>. Exibindo versão HTML imprimível.</p>
  </body>
  </html>
  <?php
  exit;
}

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial','B',14);
$pdf->Cell(0,10,utf8_decode("Comprovante de Movimentação - Armaria"),0,1,'C');
$pdf->Ln(2);

$drawHeaderRow = function(array $headers, array $widths) use ($pdf): void {
  $pdf->SetFont('Arial','B',9);
  $pdf->SetFillColor(235,235,235);
  foreach ($headers as $idx => $h) {
    $pdf->Cell($widths[$idx], 7, utf8_decode($h), 1, 0, 'C', true);
  }
  $pdf->Ln();
};

$drawDataRow = function(array $values, array $widths) use ($pdf): void {
  $pdf->SetFont('Arial','',9);
  foreach ($values as $idx => $v) {
    $pdf->Cell($widths[$idx], 7, utf8_decode((string)$v), 1, 0, 'C');
  }
  $pdf->Ln();
};

$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,8,utf8_decode("Dados da Movimentação"),0,1,'C');
$headersMov = ['Comprovante','Emitido em','Ação','Data da Ação'];
$widthsMov = [45, 50, 30, 65];
$drawHeaderRow($headersMov, $widthsMov);
  $issuedAt = br_date($data['created_at']);
  $actionAt = br_date($data['action_at']);

$drawDataRow([
  $data['receipt_code'],
  $issuedAt,
  $data['action'],
  $actionAt,
], $widthsMov);

$pdf->Ln(3);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,8,utf8_decode($isWithoutWeapon ? "Dados do Item" : "Dados da Arma"),0,1,'C');
$headersArm = ['Tipo','Modelo','Calibre','Nº de Série'];
$widthsArm = [35, 55, 30, 70];
$drawHeaderRow($headersArm, $widthsArm);
$drawDataRow([
  $itemTipo,
  $itemModelo,
  $itemCalibre,
  $itemSerie,
], $widthsArm);

$pdf->Ln(3);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,8,utf8_decode("Responsáveis"),0,1,'C');
$headersResp = ['Policial','Armeiro'];
$widthsResp = [95, 95];
$drawHeaderRow($headersResp, $widthsResp);
$drawDataRow([
  $data['policial'] ?? '-',
  $data['armeiro'],
], $widthsResp);

if ($ammoInfo) {
  $pdf->Ln(3);
  $pdf->SetFont('Arial','B',11);
  $pdf->Cell(0,8,utf8_decode("Munição"),0,1,'C');
  $headersAmmo = ['Quantidade','Calibre','Tipo','Carregadores'];
  $widthsAmmo = [35, 45, 70, 40];
  $drawHeaderRow($headersAmmo, $widthsAmmo);
  $drawDataRow([
    (string)$ammoInfo['qty'],
    $ammoInfo['calibre'],
    $ammoInfo['tipo'],
    (string)($ammoInfo['magazine_qty'] ?? 0),
  ], $widthsAmmo);
}

$pdf->Ln(8);
$pdf->SetFont('Arial','I',9);
$pdf->Cell(0,5,utf8_decode("Documento digital interno da armaria."),0,1,'C');

$pdf->Output("I", "comprovante_{$data['receipt_code']}.pdf");
exit;
