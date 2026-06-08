<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

$msg = "";
$success = false;
$csrf_ok = true;
$my_role = (string)($_SESSION['user']['role'] ?? '');
$can_manage_weapons = ($my_role === ROLE_ADMIN);
$show_weapon_list = (($_GET['list_weapons'] ?? '') === '1');
$search_weapon = trim($_GET['search_weapon'] ?? '');
if ($search_weapon !== '') {
  $show_weapon_list = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate_request()) {
  $csrf_ok = false;
  $msg = "Sessão expirada ou token inválido. Recarregue a página.";
}

function status_chip_class(string $status): string {
  return match ($status) {
    WEAPON_STATUS_CAUTELADA => 'admin',
    WEAPON_STATUS_INATIVA   => 'danger',
    default     => 'armeiro',
  };
}

/**
 * INATIVAR / REATIVAR
 * Regras:
 * - Só ADMIN (garantido pelo require_role)
 * - Não permite inativar se estiver CAUTELADA
 * - Permite reativar para DISPONIVEL
 */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_weapon_id'])) {
  if (!$can_manage_weapons) {
    $msg = "Somente ADMIN pode alterar status de arma.";
    app_audit('WEAPON_STATUS_UPDATE_DENIED', []);
  } else {
  $weapon_id = (int)($_POST['toggle_weapon_id'] ?? 0);
  $newStatus = $_POST['new_status'] ?? '';

  if ($weapon_id > 0 && in_array($newStatus, [WEAPON_STATUS_INATIVA, WEAPON_STATUS_DISPONIVEL], true)) {
    try {
      $pdo->beginTransaction();

      // trava a linha
      $stmt = $pdo->prepare("SELECT status FROM weapons WHERE id=? FOR UPDATE");
      $stmt->execute([$weapon_id]);
      $w = $stmt->fetch();

      if (!$w) {
        throw new Exception("Arma não encontrada.");
      }

      if ($newStatus === WEAPON_STATUS_INATIVA && $w['status'] === WEAPON_STATUS_CAUTELADA) {
        throw new Exception("Não é possível inativar: arma está cautelada.");
      }

      $stmt = $pdo->prepare("UPDATE weapons SET status=? WHERE id=?");
      $stmt->execute([$newStatus, $weapon_id]);

      $pdo->commit();

      $success = true;
      $msg = ($newStatus === WEAPON_STATUS_INATIVA)
        ? "Arma inativada com sucesso."
        : "Arma reativada com sucesso.";
      app_audit('WEAPON_STATUS_UPDATE_SUCCESS', ['weapon_id' => $weapon_id, 'new_status' => $newStatus]);

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = $e->getMessage();
    }
  } else {
    $msg = "Requisição inválida.";
  }
  }
}

/**
 * CADASTRAR ARMA
 */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_weapon'])) {
  if (!$can_manage_weapons) {
    $msg = "Somente ADMIN pode cadastrar arma.";
    app_audit('WEAPON_CREATE_DENIED', []);
  } else {
  $tipo = trim($_POST['tipo'] ?? '');
  $modelo = trim($_POST['modelo'] ?? '');
  $calibre = trim($_POST['calibre'] ?? '');
  $numero_serie = trim($_POST['numero_serie'] ?? '');

  if ($tipo && $modelo && $calibre && $numero_serie) {
    try {
      $stmt = $pdo->prepare("INSERT INTO weapons (tipo, modelo, calibre, numero_serie) VALUES (?,?,?,?)");
      $stmt->execute([$tipo, $modelo, $calibre, $numero_serie]);
      $success = true;
      $msg = "Arma cadastrada com sucesso.";
      app_audit('WEAPON_CREATE_SUCCESS', ['numero_serie' => $numero_serie, 'tipo' => $tipo]);
    } catch (Throwable $e) {
      $msg = "Erro ao cadastrar (nº de série pode já existir).";
    }
  } else {
    $msg = "Preencha todos os campos.";
  }
  }
}

/**
 * EDITAR MODELO
 */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_model_weapon_id'])) {
  if (!$can_manage_weapons) {
    $msg = "Somente ADMIN pode editar modelo.";
    app_audit('WEAPON_MODEL_UPDATE_DENIED', []);
  } else {
  $weapon_id = (int)($_POST['edit_model_weapon_id'] ?? 0);
  $new_model = trim($_POST['new_model'] ?? '');

  if ($weapon_id > 0 && $new_model !== '') {
    try {
      $stmt = $pdo->prepare("UPDATE weapons SET modelo=? WHERE id=?");
      $stmt->execute([$new_model, $weapon_id]);
      $success = true;
      $msg = "Modelo da arma atualizado com sucesso.";
      app_audit('WEAPON_MODEL_UPDATE_SUCCESS', ['weapon_id' => $weapon_id, 'new_model' => $new_model]);
    } catch (Throwable $e) {
      $msg = "Erro ao atualizar modelo da arma.";
    }
  } else {
    $msg = "Informe um modelo válido para atualizar.";
  }
  }
}

$weapons = [];
if ($show_weapon_list) {
  $sql = "
    SELECT id, tipo, modelo, calibre, numero_serie, status, created_at
    FROM weapons
    WHERE 1=1
  ";
  $params = [];
  if ($search_weapon !== '') {
    $sql .= " AND (tipo LIKE ? OR modelo LIKE ? OR calibre LIKE ? OR numero_serie LIKE ?) ";
    $like = '%' . $search_weapon . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }
  $sql .= " ORDER BY id DESC ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $weapons = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Armas - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
<div class="container">

  <div class="card">
    <div class="card-header">
      <h2 class="title">Armas</h2>
      <p class="subtitle">Cadastro e gerenciamento de armamento (tipo, modelo, calibre e nº de série)</p>
    </div>

    <div class="card-body">
      <div class="top-actions">
        <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
        <?php if ($show_weapon_list): ?>
          <a class="backbtn" href="weapons.php">Ocultar armas</a>
        <?php else: ?>
          <a class="backbtn" href="weapons.php?list_weapons=1">Listar armas</a>
        <?php endif; ?>
      </div>

      <form method="get" class="searchbar">
        <input type="hidden" name="list_weapons" value="1">
        <input name="search_weapon" value="<?= htmlspecialchars($search_weapon) ?>" placeholder="Consultar arma por tipo, modelo, calibre ou série">
        <button type="submit">Consultar arma</button>
        <?php if ($search_weapon !== ''): ?>
          <a class="backbtn" href="weapons.php?list_weapons=1">Limpar consulta</a>
        <?php endif; ?>
      </form>

      <?php if ($msg): ?>
        <div class="msg"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="row <?= $show_weapon_list ? 'row-single' : '' ?>">
        <?php if (!$show_weapon_list): ?>
          <!-- Cadastro/consulta -->
          <?php if ($can_manage_weapons): ?>
            <div>
              <h3>Nova arma</h3>
              <form method="post">
                <input type="hidden" name="create_weapon" value="1">
                <?= csrf_input_field() ?>

                <label>Tipo</label>
                <input name="tipo" required>

                <label>Modelo</label>
                <input name="modelo" required>

                <label>Calibre</label>
                <input name="calibre" required>

                <label>Nº de Série</label>
                <input name="numero_serie" required>

                <button type="submit">Cadastrar arma</button>
              </form>

              <p class="hint">
                * Em caso de erro no cadastro, use <b>Inativar</b>. Isso mantém o histórico e auditoria.
                <br>* Não é possível inativar arma <b>CAUTELADA</b>.
              </p>
            </div>
          <?php else: ?>
            <div>
              <h3>Consulta de armas</h3>
              <p class="hint">Perfil ARMEIRO possui acesso somente para consulta nesta tela.</p>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Lista -->
        <div class="<?= $show_weapon_list ? 'weapons-list-panel' : '' ?>">
          <h3>Armas cadastradas</h3>
          <?php if (!$show_weapon_list): ?>
            <p class="hint">Clique em <b>Listar armas</b> ou use a consulta para exibir resultados.</p>
          <?php else: ?>
            <table>
              <tr>
                <th>Tipo</th>
                <th>Modelo</th>
                <th>Calibre</th>
                <th>Série</th>
                <th>Status</th>
                <th>Ações</th>
              </tr>

              <?php foreach ($weapons as $w): ?>
                <tr>
                  <td><?= htmlspecialchars($w['tipo']) ?></td>
                  <td>
                    <?php if ($can_manage_weapons): ?>
                      <form class="model-form" method="post">
                        <?= csrf_input_field() ?>
                        <input type="hidden" name="edit_model_weapon_id" value="<?= (int)$w['id'] ?>">
                        <input class="model-input" name="new_model" value="<?= htmlspecialchars($w['modelo']) ?>" required>
                        <button class="btn-small btn-teal" type="submit">salvar</button>
                      </form>
                    <?php else: ?>
                      <?= htmlspecialchars($w['modelo']) ?>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($w['calibre']) ?></td>
                  <td><?= htmlspecialchars($w['numero_serie']) ?></td>
                  <td>
                    <span class="chip <?= status_chip_class($w['status']) ?>">
                      <?= htmlspecialchars($w['status']) ?>
                    </span>
                  </td>
                  <td class="actions-cell">
                    <?php if (!$can_manage_weapons): ?>
                      <span class="hint">Consulta</span>
                    <?php elseif ($w['status'] === WEAPON_STATUS_CAUTELADA): ?>
                      <span class="hint">Em uso</span>
                    <?php else: ?>
                      <form class="inline" method="post"
                            onsubmit="return confirm('Confirma esta ação?');">
                        <input type="hidden" name="toggle_weapon_id" value="<?= (int)$w['id'] ?>">
                        <?= csrf_input_field() ?>

                        <?php if ($w['status'] === WEAPON_STATUS_INATIVA): ?>
                          <input type="hidden" name="new_status" value="<?= WEAPON_STATUS_DISPONIVEL ?>">
                          <button class="btn-small btn-teal" type="submit">Reativar</button>
                        <?php else: ?>
                          <input type="hidden" name="new_status" value="<?= WEAPON_STATUS_INATIVA ?>">
                          <button class="btn-small btn-danger" type="submit">Inativar</button>
                        <?php endif; ?>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

            </table>

            <?php if (empty($weapons)): ?>
              <p class="hint">Nenhuma arma encontrada para o filtro informado.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
    </div>
  </div>

</div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>



