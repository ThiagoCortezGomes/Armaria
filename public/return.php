<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/combined_receipt.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

function br_date(string $value): string
{
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

$msg = "";
$success = false;
$lastWeaponReceipts = [];
$lastVestReceipts = [];
$lastCombinedReceipt = null;
$ajaxResponse = null;
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST')
  && (
    (($_POST['ajax'] ?? '') === '1')
    || (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
  );

$armeiro_id = (int)($_SESSION['user']['id'] ?? 0);

$loadWeapons = function() use ($pdo): array {
  return $pdo->query("
    SELECT ca.weapon_id, ca.policial_id, w.tipo, w.modelo, w.calibre, w.numero_serie,
           u.name AS policial, ca.assigned_at, ca.ammo_id, ca.ammo_qty,
           m.calibre AS ammo_calibre, m.tipo AS ammo_tipo
    FROM current_assignments ca
    JOIN weapons w ON w.id = ca.weapon_id
    JOIN users u ON u.id = ca.policial_id
    LEFT JOIN munitions m ON m.id = ca.ammo_id
    ORDER BY ca.assigned_at DESC
  ")->fetchAll();
};

$loadVests = function() use ($pdo): array {
  return $pdo->query("
    SELECT cva.vest_id, cva.policial_id, v.tamanho, v.numero_serie, u.name AS policial, cva.assigned_at
    FROM current_vest_assignments cva
    JOIN vests v ON v.id = cva.vest_id
    JOIN users u ON u.id = cva.policial_id
    ORDER BY cva.assigned_at DESC
  ")->fetchAll();
};

$cauteladas = $loadWeapons();
$coletesCautelados = $loadVests();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
  } else {
    $weapon_id = (int)($_POST['weapon_id'] ?? 0);
    $vest_id = (int)($_POST['vest_id'] ?? 0);
    $policial_password = $_POST['policial_password'] ?? '';

    if (($weapon_id > 0 || $vest_id > 0) && $policial_password !== '') {
      try {
        $pdo->beginTransaction();

        $policialIdWeapon = 0;
        $policialIdVest = 0;

        $weaponRow = null;
        if ($weapon_id > 0) {
          $stmt = $pdo->prepare("SELECT policial_id, ammo_id, ammo_qty FROM current_assignments WHERE weapon_id=? FOR UPDATE");
          $stmt->execute([$weapon_id]);
          $weaponRow = $stmt->fetch();
          if (!$weaponRow) {
            throw new Exception("Esta arma não está cautelada.");
          }
          $policialIdWeapon = (int)$weaponRow['policial_id'];
        }

        if ($vest_id > 0) {
          $stmt = $pdo->prepare("SELECT policial_id FROM current_vest_assignments WHERE vest_id=? FOR UPDATE");
          $stmt->execute([$vest_id]);
          $vestRow = $stmt->fetch();
          if (!$vestRow) {
            throw new Exception("Este colete não está cautelado.");
          }
          $policialIdVest = (int)$vestRow['policial_id'];
        }

        $policial_id = $policialIdWeapon ?: $policialIdVest;
        if ($policialIdWeapon > 0 && $policialIdVest > 0 && $policialIdWeapon !== $policialIdVest) {
          throw new Exception("Selecione arma e colete do mesmo policial para devolução conjunta.");
        }
        if ($policial_id <= 0) {
          throw new Exception("Selecione ao menos uma cautela para devolução.");
        }

        verify_policial_password_or_throw($pdo, 'return_combined', $policial_id, $policial_password, [
          'weapon_id' => $weapon_id,
          'vest_id' => $vest_id,
          'armeiro_id' => $armeiro_id,
        ]);

        $combinedItems = [];

        if ($weapon_id > 0) {
          $ammo_id = (int)($weaponRow['ammo_id'] ?? 0);
          $ammo_qty = (int)($weaponRow['ammo_qty'] ?? 0);

          $stmt = $pdo->prepare("DELETE FROM current_assignments WHERE weapon_id=?");
          $stmt->execute([$weapon_id]);

          $stmt = $pdo->prepare("UPDATE weapons SET status=? WHERE id=?");
          $stmt->execute([WEAPON_STATUS_DISPONIVEL, $weapon_id]);

          $note = null;
          if ($ammo_id > 0 && $ammo_qty > 0) {
            $stmt = $pdo->prepare("SELECT calibre, tipo FROM munitions WHERE id=? FOR UPDATE");
            $stmt->execute([$ammo_id]);
            $ammo = $stmt->fetch();
            if ($ammo) {
              $stmt = $pdo->prepare("UPDATE munitions SET quantidade = quantidade + ? WHERE id=?");
              $stmt->execute([$ammo_qty, $ammo_id]);

              $note = json_encode([
                'ammo_id' => $ammo_id,
                'ammo_calibre' => $ammo['calibre'],
                'ammo_tipo' => $ammo['tipo'],
                'ammo_qty' => $ammo_qty,
              ], JSON_UNESCAPED_UNICODE);
            }
          }

          $stmt = $pdo->prepare("INSERT INTO movements (weapon_id, policial_id, armeiro_id, action, note) VALUES (?,?,?, 'DEVOLUCAO', ?)");
          $stmt->execute([$weapon_id, $policial_id, $armeiro_id, $note]);

          $movement_id = (int)$pdo->lastInsertId();
          $receipt_code = "MOV-" . str_pad((string)$movement_id, 9, "0", STR_PAD_LEFT);
          $stmt = $pdo->prepare("INSERT INTO receipts (movement_id, receipt_code) VALUES (?,?)");
          $stmt->execute([$movement_id, $receipt_code]);

          $lastWeaponReceipts[] = $receipt_code;
          $combinedItems[] = ['movement_type' => 'WEAPON', 'movement_id' => $movement_id];
        }

        if ($vest_id > 0) {
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

          $lastVestReceipts[] = $receipt_code;
          $combinedItems[] = ['movement_type' => 'VEST', 'movement_id' => $movement_id];
        }

        if (!empty($combinedItems)) {
          $lastCombinedReceipt = create_combined_receipt($pdo, $policial_id, $armeiro_id, 'DEVOLUCAO', $combinedItems);
        }

        $pdo->commit();

        $success = true;
        $msg = ((count($lastWeaponReceipts) + count($lastVestReceipts)) > 1)
          ? "Devoluções registradas com sucesso."
          : "Devolução registrada com sucesso.";

        app_audit('RETURN_COMBINED_SUCCESS', [
          'weapon_id' => $weapon_id,
          'vest_id' => $vest_id,
          'policial_id' => $policial_id,
          'weapon_receipts' => $lastWeaponReceipts,
          'vest_receipts' => $lastVestReceipts,
          'combined_receipt' => $lastCombinedReceipt,
        ]);

        $cauteladas = $loadWeapons();
        $coletesCautelados = $loadVests();
        $ajaxResponse = [
          'ok' => true,
          'message' => $msg,
          'weapon_id' => $weapon_id,
          'vest_id' => $vest_id,
          'weapon_receipts' => $lastWeaponReceipts,
          'vest_receipts' => $lastVestReceipts,
          'combined_receipt' => $lastCombinedReceipt,
        ];
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        app_audit('RETURN_COMBINED_FAIL', [
          'weapon_id' => $weapon_id,
          'vest_id' => $vest_id,
          'error' => $e->getMessage(),
        ]);
        $ajaxResponse = [
          'ok' => false,
          'message' => $msg,
        ];
      }
    } else {
      $msg = "Selecione arma e/ou colete cautelado e informe a senha do policial.";
      app_audit('RETURN_COMBINED_FAIL', ['reason' => 'missing_fields']);
      $ajaxResponse = [
        'ok' => false,
        'message' => $msg,
      ];
    }
  }
}

if ($isAjax) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($ajaxResponse ?? [
    'ok' => false,
    'message' => 'Não foi possível processar a solicitação.',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Devolver armamento e colete - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
<div class="container">
  <div class="card">
    <div class="card-header">
      <h2 class="title">Devolver armamento e colete</h2>
      <p class="subtitle">Selecione arma e/ou colete cautelado para registrar a devolução em uma única tela.</p>
    </div>

    <div class="card-body">
      <div class="top-actions">
        <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
      </div>

      <div id="msg-box">
        <?php if ($msg): ?>
          <div class="msg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
      </div>

      <div id="receipt-box">
      <?php if (!empty($lastCombinedReceipt)): ?>
        <div class="receipt" id="receipt-content">
          <b>Comprovante unificado:</b>
          <a target="_blank" rel="noopener" href="combined_receipt_pdf.php?code=<?= urlencode($lastCombinedReceipt) ?>">
            <?= htmlspecialchars($lastCombinedReceipt) ?>
          </a>
        </div>
      <?php elseif (!empty($lastWeaponReceipts) || !empty($lastVestReceipts)): ?>
        <div class="receipt" id="receipt-content">
          <b>Comprovantes:</b>
          <?php foreach ($lastWeaponReceipts as $idx => $code): ?>
            <?php if ($idx > 0): ?> | <?php endif; ?>
            <a target="_blank" rel="noopener" href="receipt_pdf.php?code=<?= urlencode($code) ?>"><?= htmlspecialchars($code) ?></a>
          <?php endforeach; ?>
          <?php if (!empty($lastWeaponReceipts) && !empty($lastVestReceipts)): ?> | <?php endif; ?>
          <?php foreach ($lastVestReceipts as $idx => $code): ?>
            <?php if ($idx > 0): ?> | <?php endif; ?>
            <a target="_blank" rel="noopener" href="vest_receipt_pdf.php?code=<?= urlencode($code) ?>"><?= htmlspecialchars($code) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      </div>

      <div class="row">
        <div>
          <h3>Registrar devolução</h3>
          <form method="post" id="return-form">
            <?= csrf_input_field() ?>

            <label>Arma cautelada (opcional)</label>
            <select name="weapon_id">
              <option value="">Não devolver arma nesta operação</option>
              <?php foreach ($cauteladas as $w): ?>
                <option value="<?= (int)$w['weapon_id'] ?>" data-weapon-id="<?= (int)$w['weapon_id'] ?>">
                  <?= htmlspecialchars(
                    $w['tipo'] . " / " . $w['modelo'] . " / " . $w['calibre'] . " / Série " . $w['numero_serie'] .
                    " | Munição: " . ((($w['ammo_qty'] ?? 0) > 0) ? ($w['ammo_qty'] . "x " . $w['ammo_calibre'] . " " . $w['ammo_tipo']) : "sem munição") .
                    " | Com: " . $w['policial'] . " | Desde: " . br_date($w['assigned_at'])
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label>Colete cautelado (opcional)</label>
            <select name="vest_id">
              <option value="">Não devolver colete nesta operação</option>
              <?php foreach ($coletesCautelados as $v): ?>
                <option value="<?= (int)$v['vest_id'] ?>" data-vest-id="<?= (int)$v['vest_id'] ?>">
                  <?= htmlspecialchars("Tam " . $v['tamanho'] . " / Série " . $v['numero_serie'] . " | Com: " . $v['policial'] . " | Desde: " . br_date($v['assigned_at'])) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label>Senha do policial</label>
            <input type="password" name="policial_password" required autocomplete="current-password">

            <button type="submit">Confirmar devolução</button>
          </form>

          <p class="hint">
            * Se selecionar arma e colete juntos, devem ser do mesmo policial.<br>
            * Ao devolver, o item volta para <b>DISPONÍVEL</b> automaticamente.
          </p>
        </div>

        <div>
          <h3>Cautelas ativas (visualização)</h3>
          <?php if (empty($cauteladas) && empty($coletesCautelados)): ?>
            <p class="hint" id="empty-active-hint">Nenhuma cautela ativa no momento.</p>
          <?php else: ?>
            <p class="hint" id="empty-active-hint" style="display:none;">Nenhuma cautela ativa no momento.</p>
            <?php if (!empty($cauteladas)): ?>
              <h4 class="section-title">Armas</h4>
              <table id="active-weapons-table">
                <tr>
                  <th>Arma</th>
                  <th>Policial</th>
                  <th>Desde</th>
                </tr>
                <?php foreach ($cauteladas as $c): ?>
                  <tr data-weapon-id="<?= (int)$c['weapon_id'] ?>">
                    <td><?= htmlspecialchars($c['tipo'] . " / " . $c['modelo'] . " / " . $c['calibre'] . " / Série " . $c['numero_serie']) ?></td>
                    <td><?= htmlspecialchars($c['policial']) ?></td>
                    <td><?= htmlspecialchars(br_date($c['assigned_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>

            <?php if (!empty($coletesCautelados)): ?>
              <h4 class="section-title">Coletes</h4>
              <table id="active-vests-table">
                <tr>
                  <th>Colete</th>
                  <th>Policial</th>
                  <th>Desde</th>
                </tr>
                <?php foreach ($coletesCautelados as $c): ?>
                  <tr data-vest-id="<?= (int)$c['vest_id'] ?>">
                    <td><?= htmlspecialchars("Tam " . $c['tamanho'] . " / Série " . $c['numero_serie']) ?></td>
                    <td><?= htmlspecialchars($c['policial']) ?></td>
                    <td><?= htmlspecialchars(br_date($c['assigned_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
    </div>
  </div>
</div>
<script>
(() => {
  const form = document.getElementById('return-form');
  if (!form) return;

  const weaponSelect = form.querySelector('select[name="weapon_id"]');
  const vestSelect = form.querySelector('select[name="vest_id"]');
  const msgBox = document.getElementById('msg-box');
  const receiptBox = document.getElementById('receipt-box');
  const emptyHint = document.getElementById('empty-active-hint');

  const hasActiveRows = () => {
    const weaponRows = document.querySelectorAll('#active-weapons-table tr[data-weapon-id]');
    const vestRows = document.querySelectorAll('#active-vests-table tr[data-vest-id]');
    return weaponRows.length > 0 || vestRows.length > 0;
  };

  const refreshEmptyHint = () => {
    if (!emptyHint) return;
    emptyHint.style.display = hasActiveRows() ? 'none' : '';
  };

  const removeOptionByValue = (selectEl, value) => {
    if (!selectEl || !value) return;
    const option = selectEl.querySelector(`option[value="${String(value)}"]`);
    if (option) option.remove();
    selectEl.value = '';
  };

  const removeActiveRow = (selector) => {
    const row = document.querySelector(selector);
    if (row) row.remove();
  };

  const buildReceiptsHtml = (data) => {
    if (!data) return '';
    if (data.combined_receipt) {
      const code = String(data.combined_receipt);
      return `<div class="receipt" id="receipt-content"><b>Comprovante unificado:</b> <a target="_blank" rel="noopener" href="combined_receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a></div>`;
    }
    const links = [];
    (data.weapon_receipts || []).forEach((code) => {
      links.push(`<a target="_blank" rel="noopener" href="receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a>`);
    });
    (data.vest_receipts || []).forEach((code) => {
      links.push(`<a target="_blank" rel="noopener" href="vest_receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a>`);
    });
    if (links.length === 0) return '';
    return `<div class="receipt" id="receipt-content"><b>Comprovantes:</b> ${links.join(' | ')}</div>`;
  };

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(form);
    fd.set('ajax', '1');

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const response = await fetch('return.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      const msg = (data && data.message) ? String(data.message) : 'Erro ao processar devolução.';
      msgBox.innerHTML = `<div class="msg">${msg}</div>`;

      if (data && data.ok) {
        if (data.weapon_id) {
          removeOptionByValue(weaponSelect, data.weapon_id);
          removeActiveRow(`#active-weapons-table tr[data-weapon-id="${String(data.weapon_id)}"]`);
        }
        if (data.vest_id) {
          removeOptionByValue(vestSelect, data.vest_id);
          removeActiveRow(`#active-vests-table tr[data-vest-id="${String(data.vest_id)}"]`);
        }
        const pwd = form.querySelector('input[name="policial_password"]');
        if (pwd) pwd.value = '';
        receiptBox.innerHTML = buildReceiptsHtml(data);
        refreshEmptyHint();
      }
    } catch (e) {
      msgBox.innerHTML = '<div class="msg">Falha de comunicação ao registrar devolução.</div>';
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
})();
</script>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
