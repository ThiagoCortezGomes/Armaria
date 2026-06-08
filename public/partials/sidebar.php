<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$user = $_SESSION['user'] ?? ['name' => 'Usuário', 'role' => 'POLICIAL'];
$role = (string)($user['role'] ?? 'POLICIAL');
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'dashboard.php');

$menu = [];
if ($role === 'ADMIN') {
  $menu = [
    'MOVIMENTAÇÃO' => [
      ['label' => 'Cautelas', 'href' => 'assign.php', 'icon' => 'fa-right-left'],
      ['label' => 'Devolver arma', 'href' => 'return.php', 'icon' => 'fa-rotate-left'],
    ],
    'CADASTROS' => [
      ['label' => 'Armas', 'href' => 'weapons.php', 'icon' => 'fa-gun'],
      ['label' => 'Coletes balísticos', 'href' => 'vests.php', 'icon' => 'fa-shield-halved'],
      ['label' => 'Munições', 'href' => 'munitions.php', 'icon' => 'fa-bullseye'],
      ['label' => 'Usuários', 'href' => 'users.php', 'icon' => 'fa-users'],
    ],
    'CONSULTAS' => [
      ['label' => 'Histórico', 'href' => 'history.php', 'icon' => 'fa-clock-rotate-left'],
      ['label' => 'Relatório', 'href' => 'reports.php', 'icon' => 'fa-file-lines'],
      ['label' => 'Logs de usuários', 'href' => 'user_logs.php', 'icon' => 'fa-list-check'],
    ],
    'CONTA' => [
      ['label' => 'Alterar senha', 'href' => 'change_password.php', 'icon' => 'fa-key'],
      ['label' => 'Sair', 'href' => 'logout.php', 'icon' => 'fa-right-from-bracket'],
    ],
  ];
} elseif ($role === 'ARMEIRO') {
  $menu = [
    'MOVIMENTAÇÃO' => [
      ['label' => 'Cautelas', 'href' => 'assign.php', 'icon' => 'fa-right-left'],
      ['label' => 'Devolver arma', 'href' => 'return.php', 'icon' => 'fa-rotate-left'],
    ],
    'CADASTROS' => [
      ['label' => 'Armas', 'href' => 'weapons.php?list_weapons=1', 'icon' => 'fa-gun'],
      ['label' => 'Munições', 'href' => 'munitions.php', 'icon' => 'fa-bullseye'],
    ],
    'CONSULTAS' => [
      ['label' => 'Histórico', 'href' => 'history.php', 'icon' => 'fa-clock-rotate-left'],
      ['label' => 'Relatório', 'href' => 'reports.php', 'icon' => 'fa-file-lines'],
    ],
    'CONTA' => [
      ['label' => 'Alterar senha', 'href' => 'change_password.php', 'icon' => 'fa-key'],
      ['label' => 'Sair', 'href' => 'logout.php', 'icon' => 'fa-right-from-bracket'],
    ],
  ];
} else {
  $menu = [
    'CONSULTAS' => [
      ['label' => 'Minhas cautelas', 'href' => 'minha_cautela.php', 'icon' => 'fa-shield-halved'],
      ['label' => 'Meus comprovantes', 'href' => 'meus_comprovantes.php', 'icon' => 'fa-file-lines'],
    ],
    'CONTA' => [
      ['label' => 'Alterar senha', 'href' => 'change_password.php', 'icon' => 'fa-key'],
      ['label' => 'Sair', 'href' => 'logout.php', 'icon' => 'fa-right-from-bracket'],
    ],
  ];
}
?>
<aside class="main-sidebar">
  <div class="sidebar-logo">
    <img src="assets/brasao-bpamb.png" alt="Brasão" />
    <span>BPAmb</span>
  </div>
  <div class="sidebar-user">
    <div class="name"><?= htmlspecialchars((string)($user['name'] ?? 'Usuário')) ?></div>
    <div class="role"><?= htmlspecialchars($role) ?></div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($menu as $section => $items): ?>
      <div class="menu-section-title"><?= htmlspecialchars($section) ?></div>
      <ul class="menu-list">
        <?php foreach ($items as $it): ?>
          <?php $path = basename(parse_url($it['href'], PHP_URL_PATH) ?: ''); ?>
          <?php $active = ($path !== '' && $path === $currentPage); ?>
          <li>
            <a class="menu-link <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars($it['href']) ?>">
              <i class="fa-solid <?= htmlspecialchars($it['icon']) ?>" aria-hidden="true"></i>
              <span><?= htmlspecialchars($it['label']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </nav>
</aside>
