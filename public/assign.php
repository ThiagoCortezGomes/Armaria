<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/combined_receipt.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

// AJAX: cautelas ativas do policial selecionado
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'policial_info') {
  $pid = (int)($_GET['policial_id'] ?? 0);
  $result = ['weapons' => [], 'vest' => null];
  if ($pid > 0) {
    $stmt = $pdo->prepare("
      SELECT w.tipo, w.modelo, w.calibre, w.numero_serie,
             m.calibre AS ammo_calibre, m.tipo AS ammo_tipo, ca.ammo_qty
      FROM current_assignments ca
      JOIN weapons w ON w.id = ca.weapon_id
      LEFT JOIN munitions m ON m.id = ca.ammo_id
      WHERE ca.policial_id = ?
    ");
    $stmt->execute([$pid]);
    $result['weapons'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("
      SELECT v.tamanho, v.numero_serie
      FROM current_vest_assignments cva
      JOIN vests v ON v.id = cva.vest_id
      WHERE cva.policial_id = ?
      LIMIT 1
    ");
    $stmt->execute([$pid]);
    $result['vest'] = $stmt->fetch() ?: null;
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($result, JSON_UNESCAPED_UNICODE);
  exit;
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

// listas
$policiais = $pdo->query("SELECT id, name FROM users WHERE role='" . ROLE_POLICIAL . "' ORDER BY name")->fetchAll();
$munitions = $pdo->query("
  SELECT id, calibre, tipo, quantidade
  FROM munitions
  WHERE quantidade > 0
    AND UPPER(tipo) NOT LIKE '%TREINAMENTO%'
  ORDER BY calibre, tipo
")->fetchAll();
$disponiveis = $pdo->query("
  SELECT id, tipo, modelo, calibre, numero_serie
  FROM weapons
  WHERE status='" . WEAPON_STATUS_DISPONIVEL . "'
  ORDER BY id DESC
")->fetchAll();
$vestsDisponiveis = $pdo->query("
  SELECT id, tamanho, numero_serie
  FROM vests
  WHERE status='" . VEST_STATUS_DISPONIVEL . "'
  ORDER BY id DESC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
  } else {
  $policial_id = (int)($_POST['policial_id'] ?? 0);
  $policial_password = $_POST['policial_password'] ?? '';
  $weapon_ids = $_POST['weapon_id'] ?? [];
  $ammo_ids = $_POST['ammo_id'] ?? [];
  $ammo_qties = $_POST['ammo_qty'] ?? [];
  $mag_qties = $_POST['mag_qty'] ?? [];
  $vest_id = (int)($_POST['vest_id'] ?? 0);

  if (!is_array($weapon_ids) || !is_array($ammo_ids) || !is_array($ammo_qties) || !is_array($mag_qties)) {
    $weapon_ids = [];
    $ammo_ids = [];
    $ammo_qties = [];
    $mag_qties = [];
    $vest_id = 0;
  }

  $items = [];
  $inputError = '';
  $usedWeapons = [];
  $lineCount = max(count($weapon_ids), count($ammo_ids), count($ammo_qties), count($mag_qties));
  for ($i = 0; $i < $lineCount; $i++) {
    $weaponId = (int)($weapon_ids[$i] ?? 0);
    $ammoId = (int)($ammo_ids[$i] ?? 0);
    $ammoQty = (int)($ammo_qties[$i] ?? 0);
    $magQty = (int)($mag_qties[$i] ?? 0);
    if ($weaponId <= 0 && $ammoId <= 0 && $ammoQty <= 0 && $magQty <= 0) {
      continue;
    }
    if ($weaponId > 0) {
      if ($ammoId <= 0 || $ammoQty <= 0) {
        $inputError = "Para linhas com arma, preencha munição e quantidade.";
        break;
      }
      if (isset($usedWeapons[$weaponId])) {
        $inputError = "A mesma arma foi selecionada mais de uma vez.";
        break;
      }
      $usedWeapons[$weaponId] = true;
    } else {
      if ($ammoId <= 0 && $ammoQty > 0) {
        $inputError = "Para informar quantidade de munição sem arma, selecione a munição.";
        break;
      }
      if ($ammoId > 0 && $ammoQty <= 0 && $magQty <= 0) {
        $inputError = "Para cautela apenas de munição, informe quantidade maior que zero.";
        break;
      }
    }
    $items[] = [
      'weapon_id' => $weaponId,
      'ammo_id' => $ammoId,
      'ammo_qty' => $ammoQty,
      'mag_qty' => max(0, $magQty),
    ];
  }

  $vestItems = [];
  if ($vest_id > 0) {
    $vestItems[] = $vest_id;
  }

  if ($inputError !== '') {
    $msg = $inputError;
    app_audit('WEAPON_ASSIGN_FAIL', ['reason' => 'invalid_lines', 'error' => $inputError]);
  } elseif ($policial_id && $policial_password !== '' && (!empty($items) || !empty($vestItems))) {
    try {
      $pdo->beginTransaction();

      verify_policial_password_or_throw($pdo, 'weapon_assign', $policial_id, $policial_password, [
        'total_items' => count($items) + count($vestItems),
        'total_weapons' => count($items),
        'total_vests' => count($vestItems),
        'armeiro_id' => $armeiro_id,
      ]);
      $combinedItems = [];

      if (!empty($vestItems)) {
        $stmt = $pdo->prepare("SELECT vest_id FROM current_vest_assignments WHERE policial_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$policial_id]);
        $existingVest = $stmt->fetchColumn();
        if ($existingVest) {
          throw new Exception("Este policial já possui um colete cautelado. Só é permitido 1 colete por policial.");
        }
      }

      foreach ($items as $item) {
        $weapon_id = (int)$item['weapon_id'];
        $ammo_id = (int)$item['ammo_id'];
        $ammo_qty = (int)$item['ammo_qty'];
        $mag_qty = (int)$item['mag_qty'];

        $w = null;
        $ammo = null;

        if ($weapon_id > 0) {
          // trava arma
          $stmt = $pdo->prepare("SELECT status, calibre, tipo FROM weapons WHERE id=? FOR UPDATE");
          $stmt->execute([$weapon_id]);
          $w = $stmt->fetch();

          if (!$w) throw new Exception("Arma não encontrada.");
          if ($w['status'] !== WEAPON_STATUS_DISPONIVEL) {
            if ($w['status'] === WEAPON_STATUS_INATIVA) {
              throw new Exception("Não é possível cautelar: arma está INATIVA.");
            }
            throw new Exception("Não é possível cautelar: arma já está CAUTELADA.");
          }

          $stmt = $pdo->prepare("SELECT 1 FROM current_assignments WHERE weapon_id=? LIMIT 1");
          $stmt->execute([$weapon_id]);
          if ($stmt->fetchColumn()) {
            throw new Exception("Não é possível cautelar: arma já está CAUTELADA.");
          }
        }

        if ($ammo_id > 0) {
          $stmt = $pdo->prepare("SELECT calibre, tipo, quantidade FROM munitions WHERE id=? FOR UPDATE");
          $stmt->execute([$ammo_id]);
          $ammo = $stmt->fetch();
          if (!$ammo) {
            throw new Exception("Munição não encontrada.");
          }
          if ($ammo_qty > 0 && (int)$ammo['quantidade'] < $ammo_qty) {
            throw new Exception("Quantidade de munição insuficiente no estoque.");
          }
          if ($weapon_id > 0 && strcasecmp((string)$ammo['calibre'], (string)$w['calibre']) !== 0) {
            throw new Exception("Calibre da munição incompatível com a arma selecionada.");
          }
        }

        if ($weapon_id > 0) {
          $isEspingarda12 = (
            stripos((string)$w['tipo'], 'espingarda') !== false
            && preg_match('/(^|\\D)12(\\D|$)/', (string)$w['calibre'])
          );
          if (!$isEspingarda12 && $mag_qty <= 0) {
            throw new Exception("Informe quantidade de carregadores para armas que não sejam espingarda calibre 12.");
          }
          if ($isEspingarda12) {
            $mag_qty = 0;
          }

          // cria cautela atual (weapon_id UNIQUE impede duplicidade)
          $stmt = $pdo->prepare("INSERT INTO current_assignments (weapon_id, policial_id, armeiro_id, ammo_id, ammo_qty) VALUES (?,?,?,?,?)");
          $stmt->execute([$weapon_id, $policial_id, $armeiro_id, $ammo_id > 0 ? $ammo_id : null, max(0, $ammo_qty)]);

          // atualiza status
          $stmt = $pdo->prepare("UPDATE weapons SET status=? WHERE id=?");
          $stmt->execute([WEAPON_STATUS_CAUTELADA, $weapon_id]);
        }

        if ($ammo_id > 0 && $ammo_qty > 0) {
          $stmt = $pdo->prepare("UPDATE munitions SET quantidade = quantidade - ? WHERE id=?");
          $stmt->execute([$ammo_qty, $ammo_id]);
        }

        $note = json_encode([
          'assignment_scope' => ($weapon_id > 0 ? 'WEAPON' : 'NO_WEAPON'),
          'ammo_id' => ($ammo_id > 0 ? $ammo_id : null),
          'ammo_calibre' => (string)($ammo['calibre'] ?? ''),
          'ammo_tipo' => (string)($ammo['tipo'] ?? ''),
          'ammo_qty' => max(0, $ammo_qty),
          'magazine_qty' => max(0, $mag_qty),
        ], JSON_UNESCAPED_UNICODE);

        // registra movimento (com ou sem arma)
        $stmt = $pdo->prepare("INSERT INTO movements (weapon_id, policial_id, armeiro_id, action, note) VALUES (?,?,?, 'CAUTELA', ?)");
        $stmt->execute([$weapon_id > 0 ? $weapon_id : null, $policial_id, $armeiro_id, $note]);

        $movement_id = (int)$pdo->lastInsertId();
        $receipt_code = "MOV-" . str_pad((string)$movement_id, 9, "0", STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO receipts (movement_id, receipt_code) VALUES (?,?)");
        $stmt->execute([$movement_id, $receipt_code]);
        $lastWeaponReceipts[] = $receipt_code;
        $combinedItems[] = ['movement_type' => 'WEAPON', 'movement_id' => $movement_id];
      }

      foreach ($vestItems as $vest_id) {
        $stmt = $pdo->prepare("SELECT status FROM vests WHERE id=? FOR UPDATE");
        $stmt->execute([$vest_id]);
        $vest = $stmt->fetch();

        if (!$vest) {
          throw new Exception("Colete não encontrado.");
        }
        if ($vest['status'] !== VEST_STATUS_DISPONIVEL) {
          if ($vest['status'] === VEST_STATUS_INATIVO) {
            throw new Exception("Não é possível cautelar: colete está INATIVO.");
          }
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
        $lastVestReceipts[] = $receipt_code;
        $combinedItems[] = ['movement_type' => 'VEST', 'movement_id' => $movement_id];
      }

      $lastCombinedReceipt = create_combined_receipt(
        $pdo,
        $policial_id,
        $armeiro_id,
        'CAUTELA',
        $combinedItems
      );

      $pdo->commit();

      $success = true;
      $msg = "Cautela realizada com sucesso.";
      if ((count($lastWeaponReceipts) + count($lastVestReceipts)) > 1) {
        $msg = "Cautelas realizadas com sucesso.";
      }
      app_audit('WEAPON_ASSIGN_SUCCESS', [
        'weapon_ids' => array_column($items, 'weapon_id'),
        'vest_ids' => $vestItems,
        'policial_id' => $policial_id,
        'total_items' => count($items) + count($vestItems),
        'total_weapons' => count($items),
        'total_vests' => count($vestItems),
        'magazines' => array_column($items, 'mag_qty'),
        'weapon_receipt_codes' => $lastWeaponReceipts,
        'vest_receipt_codes' => $lastVestReceipts,
        'combined_receipt_code' => $lastCombinedReceipt,
      ]);

      // recarrega disponíveis
      $disponiveis = $pdo->query("
        SELECT id, tipo, modelo, calibre, numero_serie
        FROM weapons
        WHERE status='" . WEAPON_STATUS_DISPONIVEL . "'
        ORDER BY id DESC
      ")->fetchAll();
      $munitions = $pdo->query("
        SELECT id, calibre, tipo, quantidade
        FROM munitions
        WHERE quantidade > 0
          AND UPPER(tipo) NOT LIKE '%TREINAMENTO%'
        ORDER BY calibre, tipo
      ")->fetchAll();
      $vestsDisponiveis = $pdo->query("
        SELECT id, tamanho, numero_serie
        FROM vests
        WHERE status='" . VEST_STATUS_DISPONIVEL . "'
        ORDER BY id DESC
      ")->fetchAll();
      $ajaxResponse = [
        'ok' => true,
        'message' => $msg,
        'weapon_receipts' => $lastWeaponReceipts,
        'vest_receipts' => $lastVestReceipts,
        'combined_receipt' => $lastCombinedReceipt,
        'disponiveis' => array_values($disponiveis),
        'munitions' => array_values($munitions),
        'vests_disponiveis' => array_values($vestsDisponiveis),
      ];

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = $e->getMessage();
      app_audit('WEAPON_ASSIGN_FAIL', ['policial_id' => $policial_id, 'error' => $e->getMessage()]);
      $ajaxResponse = [
        'ok' => false,
        'message' => $msg,
      ];
    }
  } else {
      $msg = "Selecione policial, pelo menos uma linha de cautela (arma, munição e/ou carregador) ou colete, e informe a senha do policial.";
    app_audit('WEAPON_ASSIGN_FAIL', ['reason' => 'missing_fields']);
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
  <title>Cautelar armamento e colete - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <style>
    .assign-vest-box {
      margin-top: 12px;
      padding: 12px;
      border: 1px solid #d2d6de;
      border-radius: 3px;
      background: #f9fafc;
    }
    .assign-vest-box h4 {
      margin: 0 0 8px;
      font-size: 15px;
      color: #2f3a45;
    }
    .assign-vest-tools {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 8px;
    }
    .assign-vest-tools .pill {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 10px;
      border-radius: 20px;
      border: 1px solid #c7d6e2;
      background: #eef5fb;
      color: #2f3a45;
      font-size: 12px;
      font-weight: 700;
    }
    .assign-vest-tools button {
      min-height: 32px;
      padding: 4px 10px;
      font-size: 12px;
    }
    .assign-vest-select {
      min-height: 190px;
      width: 100%;
      background: #fff !important;
    }
    .assign-vest-help {
      margin-top: 8px;
      font-size: 12px;
      color: #6f7480;
    }
    #policial-info {
      margin-top: 8px;
      margin-bottom: 4px;
    }
    .policial-info-block {
      padding: 8px 10px;
      background: #f0f4f8;
      border-left: 3px solid #3a8fcb;
      border-radius: 2px;
      font-size: 13px;
      color: #2f3a45;
      margin-bottom: 4px;
    }
    .policial-info-block ul {
      margin: 4px 0 0 16px;
      padding: 0;
    }
    .policial-info-ok {
      border-left-color: #27ae60;
    }
  </style>
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
<div class="container">

  <div class="card">
    <div class="card-header">
      <h2 class="title">Cautelas</h2>
      <p class="subtitle">Selecione o policial e registre cautela de armas, munições/carregadores e colete na mesma tela.</p>
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
        <div class="receipt">
          <b>Comprovante unificado:</b>
          <a target="_blank" rel="noopener" href="combined_receipt_pdf.php?code=<?= urlencode($lastCombinedReceipt) ?>">
            <?= htmlspecialchars($lastCombinedReceipt) ?>
          </a>
        </div>
      <?php endif; ?>

      <?php if (empty($lastCombinedReceipt) && (!empty($lastWeaponReceipts) || !empty($lastVestReceipts))): ?>
        <div class="receipt">
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
          <h3>Registrar cautela</h3>
          <form method="post" id="assign-form">
            <?= csrf_input_field() ?>
            <label>Policial</label>
            <input type="text" id="policial-search" placeholder="Filtrar pelo nome..." autocomplete="off" style="margin-bottom:4px;">
            <select id="policial_id" name="policial_id" required>
              <option value="">Selecione</option>
              <?php foreach ($policiais as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="policial-info"></div>

            <label>Armas, munições e carregadores</label>
            <div id="assign-items"></div>
            <button id="add-item-btn" type="button">Adicionar linha</button>

            <div class="assign-vest-box">
              <h4>Colete balístico (opcional)</h4>
              <div class="assign-vest-tools">
                <span class="pill" id="vest-selected-pill">Selecionados: 0</span>
                <button type="button" id="vest-clear" <?= empty($vestsDisponiveis) ? 'disabled' : '' ?>>Limpar seleção</button>
              </div>

              <select id="vest_id" class="assign-vest-select" name="vest_id" size="8" <?= empty($vestsDisponiveis) ? 'disabled' : '' ?>>
                <?php if (empty($vestsDisponiveis)): ?>
                  <option value="">Nenhum colete disponível</option>
                <?php else: ?>
                  <?php foreach ($vestsDisponiveis as $v): ?>
                    <option value="<?= (int)$v['id'] ?>">
                      <?= htmlspecialchars("Tam " . $v['tamanho'] . " / Série " . $v['numero_serie']) ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <div class="assign-vest-help">Cada policial pode cautelar apenas <b>1 colete</b> por vez.</div>
            </div>

            <label>Senha do policial</label>
            <input type="password" name="policial_password" required autocomplete="current-password">

            <button type="submit">Confirmar cautela</button>
          </form>

          <p class="hint">
            * Você pode lançar linha apenas com <b>munição</b> e/ou <b>carregador</b>, sem arma.<br>
            * Armas <b>INATIVAS</b> não aparecem aqui.<br>
            * Se a arma já estiver cautelada, o sistema bloqueará automaticamente.<br>
            * Coletes <b>INATIVOS</b> ou já cautelados não entram na cautela.<br>
            * Em espingarda calibre 12, carregador não é obrigatório.
          </p>
        </div>

        <div>
          <h3>Observações</h3>
      <p class="hint">
            - Para movimentações de armas/munição/carregadores, o comprovante segue padrão MOV-xxxxxxxxx.<br>
            - Para coletes, o comprovante segue padrão VEST-xxxxxxxxx.<br>
            - Todos os comprovantes ficam disponíveis para consulta do policial.
          </p>
        </div>
      </div>

      <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
    </div>
  </div>

</div>
<script>
(() => {
  let weapons = <?= json_encode(array_values($disponiveis), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let ammos = <?= json_encode(array_values($munitions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const container = document.getElementById('assign-items');
  const addBtn = document.getElementById('add-item-btn');
  const form = document.getElementById('assign-form');
  const msgBox = document.getElementById('msg-box');
  const receiptBox = document.getElementById('receipt-box');
  const vestSelect = document.getElementById('vest_id');
  const vestPill = document.getElementById('vest-selected-pill');
  const vestClear = document.getElementById('vest-clear');
  const policialSelect = document.getElementById('policial_id');
  const policialSearch = document.getElementById('policial-search');
  const policialInfo = document.getElementById('policial-info');

  if (!container || !addBtn || !form) return;

  // Melhoria 4: filtro de busca no dropdown de policial
  if (policialSearch && policialSelect) {
    policialSearch.addEventListener('input', () => {
      const q = policialSearch.value.toLowerCase();
      Array.from(policialSelect.options).forEach(opt => {
        if (!opt.value) return;
        opt.hidden = q !== '' && !opt.textContent.toLowerCase().includes(q);
      });
    });
  }

  // Melhoria 1: exibir cautelas ativas ao selecionar policial
  const loadPolicialInfo = async (policialId) => {
    if (!policialInfo) return;
    if (!policialId) { policialInfo.innerHTML = ''; return; }
    try {
      const res = await fetch(`assign.php?action=policial_info&policial_id=${encodeURIComponent(policialId)}`);
      const data = await res.json();
      let html = '';
      if (data.weapons && data.weapons.length > 0) {
        html += '<div class="policial-info-block"><b>Armas cauteladas:</b><ul>';
        data.weapons.forEach(w => {
          html += `<li>${w.tipo} / ${w.modelo} / ${w.calibre} — Série ${w.numero_serie}`;
          if (w.ammo_calibre) html += ` | Mun: ${w.ammo_calibre} ${w.ammo_tipo} (${w.ammo_qty}un)`;
          html += '</li>';
        });
        html += '</ul></div>';
      }
      if (data.vest) {
        html += `<div class="policial-info-block"><b>Colete cautelado:</b> Tam ${data.vest.tamanho} / Série ${data.vest.numero_serie}</div>`;
      }
      if (!html) {
        html = '<div class="policial-info-block policial-info-ok">Nenhuma cautela ativa.</div>';
      }
      policialInfo.innerHTML = html;
    } catch (e) {
      policialInfo.innerHTML = '';
    }
  };

  if (policialSelect) {
    policialSelect.addEventListener('change', () => loadPolicialInfo(policialSelect.value));
  }

  const createSelect = (name, placeholder) => {
    const select = document.createElement('select');
    select.name = name;
    select.required = false;
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    select.appendChild(opt);
    return select;
  };

  const buildWeaponOptions = (select) => {
    weapons.forEach((w) => {
      const opt = document.createElement('option');
      opt.value = String(w.id);
      opt.dataset.calibre = String(w.calibre || '');
      opt.textContent = `${w.tipo} / ${w.modelo} / ${w.calibre} / Série ${w.numero_serie}`;
      select.appendChild(opt);
    });
  };

  const fillAmmoOptions = (select, calibre) => {
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = 'Selecione munição';
    select.appendChild(first);
    ammos.forEach((m) => {
      const c = String(m.calibre || '');
      if (calibre && c.toLowerCase() !== calibre.toLowerCase()) return;
      const opt = document.createElement('option');
      opt.value = String(m.id);
      opt.dataset.quantidade = String(m.quantidade);
      opt.textContent = `${m.calibre} / ${m.tipo} / Estoque: ${m.quantidade}`;
      select.appendChild(opt);
    });
  };

  // Melhoria 2: mostrar estoque máximo ao selecionar munição
  const updateQtyHint = (ammoSelect, qtyInput, hintEl) => {
    const selected = ammoSelect.options[ammoSelect.selectedIndex];
    if (selected && selected.value && selected.dataset.quantidade !== undefined) {
      const max = parseInt(selected.dataset.quantidade, 10);
      qtyInput.max = String(max);
      hintEl.textContent = `máx: ${max}`;
      hintEl.style.display = 'inline';
    } else {
      qtyInput.removeAttribute('max');
      hintEl.textContent = '';
      hintEl.style.display = 'none';
    }
  };

  const addItemRow = () => {
    const row = document.createElement('div');
    row.className = 'assign-item-row';
    row.style.display = 'grid';
    row.style.gridTemplateColumns = '2fr 2fr 1fr 1fr auto';
    row.style.gap = '8px';
    row.style.marginBottom = '8px';

    const weaponSelect = createSelect('weapon_id[]', 'Selecione arma');
    buildWeaponOptions(weaponSelect);

    const ammoSelect = createSelect('ammo_id[]', 'Selecione munição');
    fillAmmoOptions(ammoSelect, '');

    const qtyWrapper = document.createElement('div');
    qtyWrapper.style.display = 'flex';
    qtyWrapper.style.flexDirection = 'column';
    qtyWrapper.style.gap = '2px';

    const qty = document.createElement('input');
    qty.type = 'number';
    qty.name = 'ammo_qty[]';
    qty.min = '1';
    qty.placeholder = 'Qtd';
    qty.required = false;

    const qtyHint = document.createElement('span');
    qtyHint.style.fontSize = '11px';
    qtyHint.style.color = '#6f7480';
    qtyHint.style.display = 'none';

    qtyWrapper.appendChild(qty);
    qtyWrapper.appendChild(qtyHint);

    const magQty = document.createElement('input');
    magQty.type = 'number';
    magQty.name = 'mag_qty[]';
    magQty.min = '0';
    magQty.placeholder = 'Carreg.';
    magQty.required = false;

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = 'Remover';
    removeBtn.addEventListener('click', () => row.remove());

    ammoSelect.addEventListener('change', () => updateQtyHint(ammoSelect, qty, qtyHint));

    weaponSelect.addEventListener('change', () => {
      const selected = weaponSelect.options[weaponSelect.selectedIndex];
      const calibre = selected ? (selected.dataset.calibre || '') : '';
      const text = selected ? (selected.textContent || '') : '';
      const isEsp12 = /espingarda/i.test(text) && /(^|\D)12(\D|$)/.test(calibre);
      fillAmmoOptions(ammoSelect, calibre);
      updateQtyHint(ammoSelect, qty, qtyHint);
      if (isEsp12) {
        magQty.value = '0';
        magQty.readOnly = true;
        magQty.title = 'Espingarda calibre 12 não usa carregador.';
      } else {
        magQty.readOnly = false;
        if (!magQty.value || magQty.value === '0') magQty.value = '1';
        magQty.title = '';
      }
    });

    row.appendChild(weaponSelect);
    row.appendChild(ammoSelect);
    row.appendChild(qtyWrapper);
    row.appendChild(magQty);
    row.appendChild(removeBtn);
    container.appendChild(row);
  };

  const resetRows = () => {
    container.innerHTML = '';
    addItemRow();
  };

  addBtn.addEventListener('click', addItemRow);
  addItemRow();

  const refreshVestCounter = () => {
    if (!vestSelect || !vestPill) return;
    const selected = (vestSelect.value && vestSelect.value !== '') ? 1 : 0;
    vestPill.textContent = `Selecionados: ${selected}`;
  };
  if (vestSelect) {
    vestSelect.addEventListener('change', refreshVestCounter);
    refreshVestCounter();
  }
  if (vestClear && vestSelect) {
    vestClear.addEventListener('click', () => {
      vestSelect.value = '';
      refreshVestCounter();
    });
  }

  const fillVestOptions = (vests) => {
    if (!vestSelect) return;
    vestSelect.innerHTML = '';
    if (!Array.isArray(vests) || vests.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Nenhum colete disponível';
      vestSelect.appendChild(opt);
      vestSelect.disabled = true;
      if (vestClear) vestClear.disabled = true;
    } else {
      vests.forEach((v) => {
        const opt = document.createElement('option');
        opt.value = String(v.id);
        opt.textContent = `Tam ${v.tamanho} / Série ${v.numero_serie}`;
        vestSelect.appendChild(opt);
      });
      vestSelect.disabled = false;
      if (vestClear) vestClear.disabled = false;
    }
    vestSelect.value = '';
    refreshVestCounter();
  };

  const buildReceiptsHtml = (data) => {
    if (!data) return '';
    if (data.combined_receipt) {
      const code = String(data.combined_receipt);
      return `<div class="receipt"><b>Comprovante unificado:</b> <a target="_blank" rel="noopener" href="combined_receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a></div>`;
    }
    const links = [];
    (data.weapon_receipts || []).forEach((code) => {
      links.push(`<a target="_blank" rel="noopener" href="receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a>`);
    });
    (data.vest_receipts || []).forEach((code) => {
      links.push(`<a target="_blank" rel="noopener" href="vest_receipt_pdf.php?code=${encodeURIComponent(code)}">${code}</a>`);
    });
    if (links.length === 0) return '';
    return `<div class="receipt"><b>Comprovantes:</b> ${links.join(' | ')}</div>`;
  };

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(form);
    fd.set('ajax', '1');
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
      const response = await fetch('assign.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      const message = (data && data.message) ? String(data.message) : 'Erro ao processar cautela.';
      msgBox.innerHTML = `<div class="msg">${message}</div>`;
      if (data && data.ok) {
        receiptBox.innerHTML = buildReceiptsHtml(data);
        weapons = Array.isArray(data.disponiveis) ? data.disponiveis : weapons;
        ammos = Array.isArray(data.munitions) ? data.munitions : ammos;
        fillVestOptions(Array.isArray(data.vests_disponiveis) ? data.vests_disponiveis : []);
        resetRows();
        // Melhoria 3: limpar policial após cautela bem-sucedida
        if (policialSelect) policialSelect.value = '';
        if (policialSearch) {
          policialSearch.value = '';
          Array.from(policialSelect.options).forEach(opt => { opt.hidden = false; });
        }
        if (policialInfo) policialInfo.innerHTML = '';
        const pwd = form.querySelector('input[name="policial_password"]');
        if (pwd) pwd.value = '';
      }
    } catch (e) {
      msgBox.innerHTML = '<div class="msg">Falha de comunicação ao registrar cautela.</div>';
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
})();
</script>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>

