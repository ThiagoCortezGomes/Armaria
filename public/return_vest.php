<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

function br_date(string $value): string {
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

$msg = "";
$success = false;
$lastReceipt = null;
$armeiro_id = (int)($_SESSION['user']['id'] ?? 0);

$cautelados = $pdo->query("
  SELECT cva.vest_id, v.tamanho, v.numero_serie, u.name AS policial, cva.assigned_at
  FROM current_vest_assignments cva
  JOIN vests v ON v.id = cva.vest_id
  JOIN users u ON u.id = cva.policial_id
  ORDER BY cva.assigned_at DESC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request()) {
        $msg = "Sessão expirada ou token inválido. Recarregue a página.";
    } else {
    $vest_id = (int)($_POST['vest_id'] ?? 0);
    $policial_password = $_POST['policial_password'] ?? '';

    if ($vest_id && $policial_password !== '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT policial_id FROM current_vest_assignments WHERE vest_id=? FOR UPDATE");
            $stmt->execute([$vest_id]);
            $ca = $stmt->fetch();

            if (!$ca) throw new Exception("Este colete não está cautelado.");
            $policial_id = (int)$ca['policial_id'];

            verify_policial_password_or_throw($pdo, 'vest_return', $policial_id, $policial_password, [
                'vest_id' => $vest_id,
                'armeiro_id' => $armeiro_id,
            ]);

            $stmt = $pdo->prepare("DELETE FROM current_vest_assignments WHERE vest_id=?");
            $stmt->execute([$vest_id]);

            $stmt = $pdo->prepare("UPDATE vests SET status=? WHERE id=?");
            $stmt->execute([VEST_STATUS_DISPONIVEL, $vest_id]);

            $stmt = $pdo->prepare("INSERT INTO vest_movements (vest_id, policial_id, armeiro_id, action) VALUES (?,?,?, 'DEVOLUCAO')");
            $stmt->execute([$vest_id, $policial_id, $armeiro_id]);

            $movement_id = (int)$pdo->lastInsertId();
            $receipt_code = "VEST-" . str_pad((string)$movement_id, 9, "0", STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO vest_receipts (movement_id, receipt_code) VALUES (?,?)");
            $stmt->execute([$movement_id, $receipt_code]);

            $pdo->commit();

            $success = true;
            $msg = "Devolução do colete registrada com sucesso.";
            $lastReceipt = $receipt_code;
            app_audit('VEST_RETURN_SUCCESS', [
                'vest_id' => $vest_id,
                'policial_id' => $policial_id,
                'receipt_code' => $receipt_code,
            ]);

            $cautelados = $pdo->query("
        SELECT cva.vest_id, v.tamanho, v.numero_serie, u.name AS policial, cva.assigned_at
        FROM current_vest_assignments cva
        JOIN vests v ON v.id = cva.vest_id
        JOIN users u ON u.id = cva.policial_id
        ORDER BY cva.assigned_at DESC
      ")->fetchAll();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e->getMessage();
            app_audit('VEST_RETURN_FAIL', ['vest_id' => $vest_id, 'error' => $e->getMessage()]);
        }
    } else {
        $msg = "Selecione um colete cautelado e informe a senha do policial.";
        app_audit('VEST_RETURN_FAIL', ['reason' => 'missing_fields']);
    }
    }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Devolver colete - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
</head>

<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="title">Devolver colete</h2>
                <p class="subtitle">Selecione um colete cautelado para registrar a devolução.</p>
            </div>
            <div class="card-body">
                <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>

                <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

                <?php if ($lastReceipt): ?>
                    <div class="receipt">
                        <b>Comprovante:</b> <?= htmlspecialchars($lastReceipt) ?>
                        <a target="_blank" rel="noopener" href="vest_receipt_pdf.php?code=<?= urlencode($lastReceipt) ?>">Abrir PDF</a>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div>
                        <h3>Registrar devolução</h3>
                        <form method="post">
                            <?= csrf_input_field() ?>
                            <label>Colete cautelado</label>
                            <select name="vest_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($cautelados as $c): ?>
                                    <option value="<?= (int)$c['vest_id'] ?>">
                                        <?= htmlspecialchars("Tam " . $c['tamanho'] . " / Série " . $c['numero_serie'] . " | Com: " . $c['policial'] . " | Desde: " . br_date($c['assigned_at'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label>Senha do policial</label>
                            <input type="password" name="policial_password" required autocomplete="current-password">
                            <button type="submit">Confirmar devolução</button>
                        </form>

                        <p class="hint">
                            * Ao devolver, o colete volta para <b>DISPONÍVEL</b>.<br>
                            * Para retirar de uso, inative o colete na tela <b>Coletes</b>.
                        </p>
                    </div>

                    <div>
                        <h3>Cautelas ativas</h3>
                        <?php if (empty($cautelados)): ?>
                            <p class="hint">Nenhum colete cautelado no momento.</p>
                        <?php else: ?>
                            <table>
                                <tr>
                                    <th>Colete</th>
                                    <th>Policial</th>
                                    <th>Desde</th>
                                </tr>
                                <?php foreach ($cautelados as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars("Tam " . $c['tamanho'] . " / Série " . $c['numero_serie']) ?></td>
                                        <td><?= htmlspecialchars($c['policial']) ?></td>
                                        <td><?= htmlspecialchars(br_date($c['assigned_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>

</html>
