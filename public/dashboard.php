<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_login();

$role = (string)($_SESSION['user']['role'] ?? ROLE_POLICIAL);

$weaponStats = ['disponiveis' => 0, 'cauteladas' => 0];
$vestStats = ['disponiveis' => 0, 'cauteladas' => 0];

if (in_array($role, [ROLE_ADMIN, ROLE_ARMEIRO], true)) {
  $weaponStats = $pdo->query("
    SELECT
      SUM(CASE WHEN status='" . WEAPON_STATUS_DISPONIVEL . "' THEN 1 ELSE 0 END) AS disponiveis,
      SUM(CASE WHEN status='" . WEAPON_STATUS_CAUTELADA . "' THEN 1 ELSE 0 END) AS cauteladas
    FROM weapons
  ")->fetch() ?: $weaponStats;

  $vestStats = $pdo->query("
    SELECT
      SUM(CASE WHEN status='" . VEST_STATUS_DISPONIVEL . "' THEN 1 ELSE 0 END) AS disponiveis,
      SUM(CASE WHEN status='" . VEST_STATUS_CAUTELADO . "' THEN 1 ELSE 0 END) AS cauteladas
    FROM vests
  ")->fetch() ?: $vestStats;
}

if ($role === ROLE_POLICIAL) {
  $stmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM current_assignments WHERE policial_id = ?) AS armas,
      (SELECT COUNT(*) FROM current_vest_assignments WHERE policial_id = ?) AS coletes
  ");
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $stmt->execute([$uid, $uid]);
  $mine = $stmt->fetch() ?: ['armas' => 0, 'coletes' => 0];
  $weaponStats['cauteladas'] = (int)$mine['armas'];
  $vestStats['cauteladas'] = (int)$mine['coletes'];
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Painel - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="armaria-admin">
  <?php require_once __DIR__ . "/partials/layout_top.php"; ?>

  <div class="info-grid">
    <?php if ($role !== ROLE_POLICIAL): ?>
      <article class="info-box">
        <div class="icon bg-blue"><i class="fa-solid fa-gun"></i></div>
        <div class="content">
          <div class="label">Armas disponíveis</div>
          <div class="number"><?= (int)($weaponStats['disponiveis'] ?? 0) ?></div>
        </div>
      </article>
    <?php endif; ?>

    <article class="info-box">
      <div class="icon bg-red"><i class="fa-solid fa-right-left"></i></div>
      <div class="content">
        <div class="label"><?= $role === ROLE_POLICIAL ? 'Minhas armas cauteladas' : 'Armas cauteladas' ?></div>
        <div class="number"><?= (int)($weaponStats['cauteladas'] ?? 0) ?></div>
      </div>
    </article>

    <?php if ($role !== ROLE_POLICIAL): ?>
      <article class="info-box">
        <div class="icon bg-green"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="content">
          <div class="label">Coletes disponíveis</div>
          <div class="number"><?= (int)($vestStats['disponiveis'] ?? 0) ?></div>
        </div>
      </article>
    <?php endif; ?>

    <article class="info-box">
      <div class="icon bg-yellow"><i class="fa-solid fa-user-shield"></i></div>
      <div class="content">
        <div class="label"><?= $role === ROLE_POLICIAL ? 'Meus coletes cautelados' : 'Coletes cautelados' ?></div>
        <div class="number"><?= (int)($vestStats['cauteladas'] ?? 0) ?></div>
      </div>
    </article>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="title">Painel do sistema</h2>
      <p class="subtitle">Seja bem vindo</p>
    </div>
    <div class="card-body">
      <p class="subtitle">Use o menu lateral para acessar cadastros, movimentações e consultas.</p>
      <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
    </div>
  </div>

  <?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>

</html>
