<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

$msg = "";
$success = false;
$lastReceipt = null;
$armeiro_id = (int)($_SESSION['user']['id'] ?? 0);

$policiais = $pdo->query("SELECT id, posto_grad, name FROM users WHERE role='" . ROLE_POLICIAL . "' ORDER BY name")->fetchAll();
$disponiveis = $pdo->query("SELECT id, tamanho, numero_serie FROM vests WHERE status='" . VEST_STATUS_DISPONIVEL . "' ORDER BY id DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request()) {
        $msg = "Sessão expirada ou token inválido. Recarregue a página.";
    } else {
    $vest_id = (int)($_POST['vest_id'] ?? 0);
    $policial_id = (int)($_POST['policial_id'] ?? 0);
    $policial_password = $_POST['policial_password'] ?? '';

    if ($vest_id && $policial_id && $policial_password !== '') {
        try {
            $pdo->beginTransaction();

            verify_policial_password_or_throw($pdo, 'vest_assign', $policial_id, $policial_password, [
                'vest_id' => $vest_id,
                'armeiro_id' => $armeiro_id,
            ]);

            $stmt = $pdo->prepare("SELECT vest_id FROM current_vest_assignments WHERE policial_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$policial_id]);
            $existingVest = $stmt->fetchColumn();
            if ($existingVest) {
                throw new Exception("Este policial já possui um colete cautelado. Só é permitido 1 colete por policial.");
            }

            $stmt = $pdo->prepare("SELECT status FROM vests WHERE id=? FOR UPDATE");
            $stmt->execute([$vest_id]);
            $v = $stmt->fetch();

            if (!$v) throw new Exception("Colete não encontrado.");
            if ($v['status'] !== VEST_STATUS_DISPONIVEL) {
                if ($v['status'] === VEST_STATUS_INATIVO) throw new Exception("Não é possível cautelar: colete está INATIVO.");
                throw new Exception("Não é possível cautelar: colete já está CAUTELADO.");
            }

            $stmt = $pdo->prepare("SELECT 1 FROM current_vest_assignments WHERE vest_id=? LIMIT 1");
            $stmt->execute([$vest_id]);
            if ($stmt->fetchColumn()) {
                throw new Exception("Não é possível cautelar: colete já está CAUTELADO.");
            }

            $stmt = $pdo->prepare("INSERT INTO current_vest_assignments (vest_id, policial_id, armeiro_id) VALUES (?,?,?)");
            $stmt->execute([$vest_id, $policial_id, $armeiro_id]);

            $stmt = $pdo->prepare("UPDATE vests SET status=? WHERE id=?");
            $stmt->execute([VEST_STATUS_CAUTELADO, $vest_id]);

            $stmt = $pdo->prepare("INSERT INTO vest_movements (vest_id, policial_id, armeiro_id, action) VALUES (?,?,?, 'CAUTELA')");
            $stmt->execute([$vest_id, $policial_id, $armeiro_id]);

            $movement_id = (int)$pdo->lastInsertId();
            $receipt_code = "VEST-" . str_pad((string)$movement_id, 9, "0", STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO vest_receipts (movement_id, receipt_code) VALUES (?,?)");
            $stmt->execute([$movement_id, $receipt_code]);

            $pdo->commit();

            $success = true;
            $msg = "Cautela de colete realizada com sucesso.";
            $lastReceipt = $receipt_code;
            app_audit('VEST_ASSIGN_SUCCESS', [
                'vest_id' => $vest_id,
                'policial_id' => $policial_id,
                'receipt_code' => $receipt_code,
            ]);

            $disponiveis = $pdo->query("SELECT id, tamanho, numero_serie FROM vests WHERE status='" . VEST_STATUS_DISPONIVEL . "' ORDER BY id DESC")->fetchAll();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e->getMessage();
            app_audit('VEST_ASSIGN_FAIL', ['vest_id' => $vest_id, 'policial_id' => $policial_id, 'error' => $e->getMessage()]);
        }
    } else {
        $msg = "Selecione o policial, o colete e informe a senha do policial.";
        app_audit('VEST_ASSIGN_FAIL', ['reason' => 'missing_fields']);
    }
    }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cautelar colete - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
</head>

<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="title">Cautelar colete</h2>
                <p class="subtitle">Selecione o policial e um colete disponível.</p>
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
                        <h3>Registrar cautela</h3>
                        <form method="post">
                            <?= csrf_input_field() ?>
                            <label>Policial</label>
                            <select name="policial_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($policiais as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars(($p['posto_grad'] ?? '') . ' ' . $p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label>Colete (somente DISPONÍVEL)</label>
                            <select name="vest_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($disponiveis as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>">
                                        <?= htmlspecialchars("Tam " . $v['tamanho'] . " / Série " . $v['numero_serie']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label>Senha do policial</label>
                            <input type="password" name="policial_password" required autocomplete="current-password">

                            <button type="submit">Confirmar cautela</button>
                        </form>

                        <p class="hint">
                            * Coletes <b>INATIVOS</b> não aparecem aqui.<br>
                            * Se já estiver cautelado, o sistema bloqueia automaticamente.<br>
                            * Cada policial pode cautelar apenas <b>1 colete</b> por vez.
                        </p>
                    </div>

                    <div>
                        <h3>Observações</h3>
                        <p class="hint">Após registrar a cautela, será gerado um comprovante (VEST-xxxxxxxxx).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>

</html>
