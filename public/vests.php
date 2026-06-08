<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN]);

$msg = "";
$success = false;
$csrf_ok = true;
$show_vest_list = (($_GET['list_vests'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate_request()) {
    $csrf_ok = false;
    $msg = "Sessão expirada ou token inválido. Recarregue a página.";
}

function status_chip_class(string $status): string
{
    return match ($status) {
        VEST_STATUS_CAUTELADO => 'admin',
        VEST_STATUS_INATIVO   => 'danger',
        default     => 'armeiro',
    };
}

// INATIVAR / REATIVAR
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_vest_id'])) {
    $vest_id = (int)($_POST['toggle_vest_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';

    if ($vest_id > 0 && in_array($newStatus, [VEST_STATUS_INATIVO, VEST_STATUS_DISPONIVEL], true)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status FROM vests WHERE id=? FOR UPDATE");
            $stmt->execute([$vest_id]);
            $v = $stmt->fetch();

            if (!$v) throw new Exception("Colete não encontrado.");
            if ($newStatus === VEST_STATUS_INATIVO && $v['status'] === VEST_STATUS_CAUTELADO) {
                throw new Exception("Não é possível inativar: colete está cautelado.");
            }

            $stmt = $pdo->prepare("UPDATE vests SET status=? WHERE id=?");
            $stmt->execute([$newStatus, $vest_id]);

            $pdo->commit();
            $success = true;
            $msg = ($newStatus === VEST_STATUS_INATIVO) ? "Colete inativado com sucesso." : "Colete reativado com sucesso.";
            app_audit('VEST_STATUS_UPDATE_SUCCESS', ['vest_id' => $vest_id, 'new_status' => $newStatus]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e->getMessage();
        }
    } else {
        $msg = "Requisição inválida.";
    }
}

// CADASTRAR
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_vest'])) {
    $tamanho = trim($_POST['tamanho'] ?? '');
    $numero_serie = trim($_POST['numero_serie'] ?? '');
    $validade = trim($_POST['validade'] ?? '');
    $validadeSql = null;
    if ($validade !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade)) {
            $msg = "Data de validade inválida.";
        } else {
            $validadeSql = $validade;
        }
    }

    if ($msg === '' && $tamanho && $numero_serie) {
        try {
            $stmt = $pdo->prepare("INSERT INTO vests (tamanho, numero_serie, validade) VALUES (?,?,?)");
            $stmt->execute([$tamanho, $numero_serie, $validadeSql]);
            $success = true;
            $msg = "Colete cadastrado com sucesso.";
            app_audit('VEST_CREATE_SUCCESS', ['numero_serie' => $numero_serie, 'tamanho' => $tamanho, 'validade' => $validadeSql]);
        } catch (Throwable $e) {
            $msg = "Erro ao cadastrar (nº de série pode já existir).";
        }
    } elseif ($msg === '') {
        $msg = "Preencha todos os campos.";
    }
}

if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vest_validade_id'])) {
    $vest_id = (int)($_POST['update_vest_validade_id'] ?? 0);
    $new_validade = trim($_POST['new_validade'] ?? '');
    $validadeSql = null;
    if ($new_validade !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_validade)) {
            $msg = "Data de validade inválida.";
        } else {
            $validadeSql = $new_validade;
        }
    }

    if ($msg === '' && $vest_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE vests SET validade=? WHERE id=?");
            $stmt->execute([$validadeSql, $vest_id]);
            $success = true;
            $msg = "Validade atualizada com sucesso.";
            app_audit('VEST_VALIDADE_UPDATE_SUCCESS', ['vest_id' => $vest_id, 'validade' => $validadeSql]);
        } catch (Throwable $e) {
            $msg = "Erro ao atualizar validade.";
        }
    }
}

$vests = $pdo->query("SELECT id, tamanho, numero_serie, validade, status, created_at FROM vests ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coletes - Armaria</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .vests-list-panel .vests-table-wrap {
            overflow-x: auto;
        }

        .vests-list-panel .vests-table {
            table-layout: auto !important;
            width: 100%;
        }

        .vests-list-panel .vests-table th.col-validade,
        .vests-list-panel .vests-table td.col-validade {
            min-width: 220px;
            width: 250px;
            text-align: center;
        }

        .vests-list-panel .vests-table th,
        .vests-list-panel .vests-table td {
            text-align: center !important;
            vertical-align: middle !important;
            overflow: visible !important;
            text-overflow: clip !important;
            white-space: normal !important;
        }

        .vests-list-panel .vest-validade-form {
            display: grid;
            grid-template-columns: minmax(200px, 1fr) auto;
            align-items: center;
            justify-content: center;
            column-gap: 8px;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }

        .vests-list-panel .vest-validade-form input[type="date"] {
            width: 100% !important;
            min-width: 200px !important;
            text-align: center !important;
        }

        .vests-list-panel .vest-validade-form input[type="date"]::-webkit-datetime-edit {
            text-align: center;
        }

        @media (max-width: 900px) {

            .vests-list-panel .vests-table th.col-validade,
            .vests-list-panel .vests-table td.col-validade {
                min-width: 220px;
                width: 250px;
            }

            .vests-list-panel .vest-validade-form {
                grid-template-columns: minmax(170px, 1fr) auto;
                max-width: 270px;
            }

            .vests-list-panel .vest-validade-form input[type="date"] {
                min-width: 170px !important;
            }
        }
    </style>

</head>

<body class="armaria-admin">
    <?php require_once __DIR__ . "/partials/layout_top.php"; ?>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="title">Coletes balísticos</h2>
                <p class="subtitle">Cadastro e gerenciamento (tamanho e nº de série)</p>
            </div>
            <div class="card-body">
                <div class="top-actions">
                    <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
                    <?php if ($show_vest_list): ?>
                        <a class="backbtn" href="vests.php">Ocultar coletes</a>
                    <?php else: ?>
                        <a class="backbtn" href="vests.php?list_vests=1">Listar coletes</a>
                    <?php endif; ?>
                </div>

                <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

                <div class="row <?= $show_vest_list ? 'row-single' : '' ?>">
                    <?php if (!$show_vest_list): ?>
                        <div>
                            <h3>Novo colete</h3>
                            <form method="post">
                                <?= csrf_input_field() ?>
                                <input type="hidden" name="create_vest" value="1">
                                <label>Tamanho</label>
                                <input name="tamanho" placeholder="Ex: P, M, G, GG, 42, 44..." required>
                                <label>Nº de Série</label>
                                <input name="numero_serie" required>
                                <label>Validade</label>
                                <input type="date" name="validade">
                                <button type="submit">Cadastrar colete</button>
                            </form>
                            <p class="hint">
                                * Em caso de erro no cadastro, use <b>Inativar</b> (preserva auditoria).<br>
                                * Não é possível inativar colete <b>CAUTELADO</b>.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="<?= $show_vest_list ? 'vests-list-panel' : '' ?>">
                        <h3>Coletes cadastrados</h3>
                        <?php if (!$show_vest_list): ?>
                            <p class="hint">Clique em <b>Listar coletes</b> para exibir a relação.</p>
                        <?php else: ?>
                            <div class="vests-table-wrap">
                                <table class="vests-table">
                                    <colgroup>
                                        <col style="width:12%">
                                        <col style="width:24%">
                                        <col style="width:28%">
                                        <col style="width:16%">
                                        <col style="width:20%">
                                    </colgroup>
                                    <tr>
                                        <th>Tamanho</th>
                                        <th>Série</th>
                                        <th class="col-validade">Validade</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                    <?php foreach ($vests as $v): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($v['tamanho']) ?></td>
                                            <td><?= htmlspecialchars($v['numero_serie']) ?></td>
                                            <td class="col-validade">
                                                <form class="inline vest-validade-form" method="post">
                                                    <?= csrf_input_field() ?>
                                                    <input type="hidden" name="update_vest_validade_id" value="<?= (int)$v['id'] ?>">
                                                    <input type="date" name="new_validade" value="<?= htmlspecialchars((string)($v['validade'] ?? '')) ?>">
                                                    <button class="btn-small btn-teal" type="submit">Salvar</button>
                                                </form>
                                            </td>
                                            <td><span class="chip <?= status_chip_class($v['status']) ?>"><?= htmlspecialchars($v['status']) ?></span></td>
                                            <td>
                                                <?php if ($v['status'] === VEST_STATUS_CAUTELADO): ?>
                                                    <span class="hint">Em uso</span>
                                                <?php else: ?>
                                                    <form class="inline" method="post" onsubmit="return confirm('Confirma esta ação?');">
                                                        <?= csrf_input_field() ?>
                                                        <input type="hidden" name="toggle_vest_id" value="<?= (int)$v['id'] ?>">
                                                        <?php if ($v['status'] === VEST_STATUS_INATIVO): ?>
                                                            <input type="hidden" name="new_status" value="<?= VEST_STATUS_DISPONIVEL ?>">
                                                            <button class="btn-small btn-teal" type="submit">Reativar</button>
                                                        <?php else: ?>
                                                            <input type="hidden" name="new_status" value="<?= VEST_STATUS_INATIVO ?>">
                                                            <button class="btn-small btn-danger" type="submit">Inativar</button>
                                                        <?php endif; ?>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                            <?php if (empty($vests)): ?><p class="hint">Nenhum colete cadastrado ainda.</p><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>

</html>
