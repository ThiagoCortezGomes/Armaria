<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);

$msg = "";
$success = false;
$csrf_ok = true;
$my_role = (string)($_SESSION['user']['role'] ?? '');
$can_manage_users = ($my_role === ROLE_ADMIN);
$can_create_users = ($my_role === ROLE_ADMIN || $my_role === ROLE_ARMEIRO);
$can_update_rank = ($my_role === ROLE_ADMIN);
$show_user_list = (($_GET['list_users'] ?? '') === '1');
$show_all_users = $can_manage_users && (($_GET['show_all'] ?? '') === '1');
$search_policial = trim($_GET['search_policial'] ?? '');
if ($search_policial !== '') {
  $show_user_list = true;
  $show_all_users = false; // consulta é focada em policial
}
$postos = [
  'ADMIN',
  'Coronel',
  'Tenente Coronel',
  'Major',
  'Capitão',
  '1º Tenente',
  '2º Tenente',
  'Subtenente',
  '1º Sargento',
  '2º Sargento',
  '3º Sargento',
  'Cabo',
  'Soldado',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate_request()) {
  $csrf_ok = false;
  $msg = "Sessão expirada ou token inválido. Recarregue a página.";
}

function chip_class(string $role): string
{
  return strtolower($role);
}

function to_upper(string $value): string
{
  return function_exists('mb_strtoupper')
    ? mb_strtoupper($value, 'UTF-8')
    : strtoupper($value);
}

/* =========================
   EXCLUIR USUÁRIO
========================= */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
  if (!$can_manage_users) {
    $msg = "Somente ADMIN pode excluir usuários.";
    app_audit('USER_DELETE_DENIED', []);
  } else {
    $delete_id = (int)($_POST['delete_user_id'] ?? 0);
    $my_id = (int)($_SESSION['user']['id'] ?? 0);

    if ($delete_id <= 0) {
      $msg = "ID inválido.";
    } elseif ($delete_id === $my_id) {
      $msg = "Você não pode excluir o próprio usuário.";
    } else {
      try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT role FROM users WHERE id=? FOR UPDATE");
        $stmt->execute([$delete_id]);
        $target = $stmt->fetch();

        if (!$target) throw new Exception("Usuário não encontrado.");

        if ($target['role'] === ROLE_ADMIN) {
          $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='" . ROLE_ADMIN . "'")->fetchColumn();
          if ($adminCount <= 1) {
            throw new Exception("Não é possível excluir o último ADMIN.");
          }
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
        $stmt->execute([$delete_id]);

        $pdo->commit();
        $success = true;
        $msg = "Usuário excluído com sucesso.";
        app_audit('USER_DELETE_SUCCESS', ['target_user_id' => $delete_id]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
      }
    }
  }
}

/* =========================
   CADASTRAR USUÁRIO
========================= */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
  if (!$can_create_users) {
    $msg = "Somente ADMIN ou ARMEIRO pode cadastrar usuários.";
    app_audit('USER_CREATE_DENIED', []);
  } else {
    $posto_grad = trim($_POST['posto_grad'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $matricula = trim($_POST['matricula'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? ROLE_POLICIAL;

    if ($my_role === ROLE_ARMEIRO && $role !== ROLE_POLICIAL) {
      $role = ROLE_POLICIAL;
    }

    if ($role === ROLE_POLICIAL) {
      $name = to_upper($name);
      $matricula = to_upper($matricula);
    }

    if ($posto_grad && $name && $matricula && $username && $password) {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      try {
        $stmt = $pdo->prepare(
          "INSERT INTO users (name, matricula, username, email, password_hash, role, posto_grad)
         VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$name, $matricula, $username, ($email ?: null), $hash, $role, $posto_grad]);
        $success = true;
        $msg = "Usuário cadastrado com sucesso.";
        app_audit('USER_CREATE_SUCCESS', [
          'username' => $username,
          'role' => $role,
          'matricula' => $matricula,
        ]);
      } catch (Throwable $e) {
        $msg = "Erro ao cadastrar (login ou matrícula já existente).";
      }
    } else {
      $msg = "Preencha todos os campos obrigatórios.";
    }
  }
}

/* =========================
   ATUALIZAR E-MAIL + POSTO/GRAD
========================= */
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile_user_id'])) {
  if (!$can_manage_users || !$can_update_rank) {
    $msg = "Somente ADMIN pode atualizar dados do policial.";
    app_audit('USER_PROFILE_UPDATE_DENIED', []);
  } else {
    $target_id = (int)($_POST['update_profile_user_id'] ?? 0);
    $new_email = trim($_POST['new_email'] ?? '');
    $new_posto = trim($_POST['new_posto_grad'] ?? '');

    if ($target_id <= 0) {
      $msg = "Selecione um policial válido.";
    } elseif ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $msg = "Informe um e-mail válido.";
    } elseif (!in_array($new_posto, $postos, true)) {
      $msg = "Posto/graduação inválido.";
    } else {
      try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$target_id]);
        $u = $stmt->fetch();
        if (!$u || $u['role'] !== ROLE_POLICIAL) {
          throw new Exception("Selecione um policial válido.");
        }

        $stmt = $pdo->prepare("UPDATE users SET email=?, posto_grad=? WHERE id=?");
        $stmt->execute([$new_email, $new_posto, $target_id]);

        $success = true;
        $msg = "Dados do policial atualizados com sucesso.";
        app_audit('USER_PROFILE_UPDATE_SUCCESS', [
          'target_user_id' => $target_id,
          'email' => $new_email,
          'posto_grad' => $new_posto,
        ]);
      } catch (Throwable $e) {
        $msg = $e->getMessage() ?: "Erro ao atualizar dados do policial.";
      }
    }
  }
}

$users = [];
if ($show_user_list) {
  $sqlUsers = "
    SELECT id, posto_grad, name, matricula, username, email, role, created_at
    FROM users
    WHERE 1=1
  ";
  $paramsUsers = [];

  if (!$show_all_users || $search_policial !== '') {
    $sqlUsers .= " AND role = ? ";
    $paramsUsers[] = ROLE_POLICIAL;
  }

  if ($search_policial !== '') {
    $sqlUsers .= " AND (name LIKE ? OR matricula LIKE ? OR username LIKE ?) ";
    $like = '%' . $search_policial . '%';
    $paramsUsers[] = $like;
    $paramsUsers[] = $like;
    $paramsUsers[] = $like;
  }

  $sqlUsers .= "
    ORDER BY
      CASE posto_grad
        WHEN 'ADMIN' THEN 0
        WHEN 'Coronel' THEN 1
        WHEN 'Tenente Coronel' THEN 2
        WHEN 'Major' THEN 3
        WHEN 'Capitão' THEN 4
        WHEN '1º Tenente' THEN 5
        WHEN '2º Tenente' THEN 6
        WHEN 'Subtenente' THEN 7
        WHEN '1º Sargento' THEN 8
        WHEN '2º Sargento' THEN 9
        WHEN '3º Sargento' THEN 10
        WHEN 'Cabo' THEN 11
        WHEN 'Soldado' THEN 12
        ELSE 99
      END ASC,
      name ASC
  ";

  $stmt = $pdo->prepare($sqlUsers);
  $stmt->execute($paramsUsers);
  $users = $stmt->fetchAll();
}

$policiais_for_profile = $pdo->query("
  SELECT id, name, matricula, email, posto_grad
  FROM users
  WHERE role='" . ROLE_POLICIAL . "'
  ORDER BY name ASC
")->fetchAll();


$my_id = (int)($_SESSION['user']['id'] ?? 0);
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuários - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>

<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Usuários</h2>
        <p class="subtitle">Cadastro e gerenciamento de usuários</p>
      </div>

<div class="card-body">

<a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
<?php if ($can_manage_users): ?>
  <?php if ($show_user_list): ?>
    <?php if ($show_all_users): ?>
      <a class="backbtn" href="users.php?list_users=1">Ver apenas policiais</a>
    <?php else: ?>
      <a class="backbtn" href="users.php?list_users=1&show_all=1">Listar todos os usuários</a>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php if ($show_user_list): ?>
  <?php if ($can_manage_users): ?>
    <a class="backbtn" href="users.php">Ocultar usuários</a>
  <?php else: ?>
    <a class="backbtn" href="users.php">Ocultar usuários</a>
  <?php endif; ?>
<?php else: ?>
  <a class="backbtn" href="users.php?list_users=1">Listar usuários</a>
<?php endif; ?>

<form method="get" class="searchbar">
  <input type="hidden" name="list_users" value="1">
  <input name="search_policial" value="<?= htmlspecialchars($search_policial) ?>" placeholder="Consultar policial por nome, matrícula ou CPF/login">
  <button type="submit">Consultar policial</button>
  <?php if ($search_policial !== ''): ?>
    <a class="backbtn" href="users.php?list_users=1">Limpar consulta</a>
  <?php endif; ?>
</form>
<p class="search-note">A consulta rápida filtra apenas policiais cadastrados.</p>

<?php if ($msg): ?>
<div class="msg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="row <?= $show_user_list ? 'row-single' : '' ?>">
          <?php if (!$show_user_list): ?>
            <div class="panel">
              <?php if ($can_create_users): ?>
                <h3>Novo usuário</h3>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="create_user" value="1">

                  <label>Posto/Graduação</label>
                  <select name="posto_grad" required>
                    <option value="">Selecione</option>
                    <?php foreach ($postos as $p): ?>
                      <option><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <label>Nome</label>
                  <input id="name" name="name" required>

                  <label>Matrícula</label>
                  <input id="matricula" name="matricula" required>

                  <label>Login</label>
                  <input name="username" required>

                  <label>E-mail (opcional)</label>
                  <input name="email">

                  <label>Senha</label>
                  <input type="password" name="password" required>

                  <label>Perfil</label>
                  <select id="role" name="role">
                    <option value="POLICIAL">POLICIAL</option>
                    <?php if ($can_manage_users): ?>
                      <option value="ARMEIRO">ARMEIRO</option>
                      <option value="ADMIN">ADMIN</option>
                    <?php endif; ?>
                  </select>

                  <button type="submit">Cadastrar usuário</button>
                </form>
              <?php endif; ?>

              <?php if ($can_manage_users): ?>
                <hr style="margin:18px 0; border-color: rgba(255,255,255,.12);">
                <h3>Atualizar e-mail e posto/graduação</h3>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="update_profile_user_id" id="profile_target_id_hidden" value="">

                  <label>Policial</label>
                  <select id="profile_target_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($policiais_for_profile as $p): ?>
                      <option
                        value="<?= (int)$p['id'] ?>"
                        data-email="<?= htmlspecialchars((string)($p['email'] ?? ''), ENT_QUOTES) ?>"
                        data-posto="<?= htmlspecialchars((string)($p['posto_grad'] ?? ''), ENT_QUOTES) ?>"
                      >
                        <?= htmlspecialchars($p['name'] . " | Matrícula " . $p['matricula']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <label>E-mail</label>
                  <input id="new_email" name="new_email" type="email" placeholder="exemplo@dominio.com" required>

                  <label>Posto/graduação</label>
                  <select id="new_posto_grad_sidebar" name="new_posto_grad" required>
                    <option value="">Selecione</option>
                    <?php foreach ($postos as $p): ?>
                      <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <button type="submit">Salvar atualização</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="panel <?= $show_user_list ? 'users-list-panel' : '' ?>">
            <h3><?= $can_manage_users ? 'Usuários cadastrados' : 'Militares cadastrados' ?></h3>
            <?php if (!$show_user_list): ?>
              <p class="subtitle">Clique em <b>Listar usuários</b> para exibir a relação cadastrada.</p>
            <?php else: ?>
              <div class="table-wrap">
                <table class="users-table">
                  <colgroup>
                    <col style="width:5%">
                    <col style="width:12%">
                    <col style="width:22%">
                    <col style="width:11%">
                    <col style="width:11%">
                    <col style="width:18%">
                    <col style="width:8%">
                    <col style="width:13%">
                  </colgroup>
                  <tr>
                    <th>Ord.</th>
                    <th>Posto/Grad</th>
                    <th>Nome</th>
                    <th>Matrícula</th>
                    <th>Login</th>
                    <th>E-mail</th>
                    <th>Perfil</th>
                    <th>Ações</th>
                  </tr>

                  <?php foreach ($users as $idx => $u): ?>
                    <tr>
                      <td><?= (int)$idx + 1 ?></td>
                      <td><?= htmlspecialchars($u['posto_grad']) ?></td>
                      <td class="name-col"><?= htmlspecialchars($u['name']) ?></td>
                      <td><?= htmlspecialchars($u['matricula']) ?></td>
                      <td><?= htmlspecialchars($u['username']) ?></td>
                      <td><?= htmlspecialchars((string)($u['email'] ?? '-')) ?></td>
                      <td>
                        <span class="chip <?= chip_class($u['role']) ?>">
                          <?= htmlspecialchars($u['role']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="actions">
                          <?php if ($can_manage_users && (int)$u['id'] !== $my_id): ?>
                            <form method="post" onsubmit="return confirm('Excluir este usuário?');">
                              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                              <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                              <button class="btn-danger" type="submit">Excluir</button>
                            </form>
                          <?php elseif ((int)$u['id'] === $my_id): ?>
                            <span class="muted">Você</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                </table>
              </div>
            <?php endif; ?>
          </div>

        </div>

        <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const role = document.getElementById('role');
      const name = document.getElementById('name');
      const matricula = document.getElementById('matricula');
      const profileTarget = document.getElementById('profile_target_id');
      const profileTargetHidden = document.getElementById('profile_target_id_hidden');
      const emailInput = document.getElementById('new_email');
      const rankSelect = document.getElementById('new_posto_grad_sidebar');

      if (role && name && matricula) {
        const apply = () => {
          const isPolicial = role.value === 'POLICIAL';
          name.style.textTransform = isPolicial ? 'uppercase' : '';
          matricula.style.textTransform = isPolicial ? 'uppercase' : '';
        };

        const toUpperWhenPolicial = (el) => {
          el.addEventListener('input', () => {
            if (role.value === 'POLICIAL') el.value = el.value.toUpperCase();
          });
        };

        role.addEventListener('change', apply);
        toUpperWhenPolicial(name);
        toUpperWhenPolicial(matricula);
        apply();
      }

      if (profileTarget && profileTargetHidden && emailInput && rankSelect) {
        profileTarget.addEventListener('change', () => {
          const selected = profileTarget.options[profileTarget.selectedIndex];
          profileTargetHidden.value = selected?.value || '';
          emailInput.value = selected?.dataset?.email || '';
          rankSelect.value = selected?.dataset?.posto || '';
        });
      }
    })();
  </script>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>

</html>
