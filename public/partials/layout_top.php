<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'dashboard.php');
$titleMap = [
  'dashboard.php' => 'Dashboard',
  'users.php' => 'Usuários',
  'weapons.php' => 'Armas',
  'vests.php' => 'Coletes',
  'munitions.php' => 'Munições',
  'assign.php' => 'Cautelar arma',
  'return.php' => 'Devolver arma',
  'assign_vest.php' => 'Cautelar colete',
  'return_vest.php' => 'Devolver colete',
  'history.php' => 'Histórico',
  'reports.php' => 'Relatório',
  'report_munitions.php' => 'Relatório de munições',
  'change_password.php' => 'Alterar senha',
  'minha_cautela.php' => 'Minhas cautelas',
  'meus_comprovantes.php' => 'Meus comprovantes',
];
$__armaria_page_title = $page_title ?? ($titleMap[$currentPage] ?? 'Armaria');
$__armaria_page_subtitle = $page_subtitle ?? 'Gestão de armaria';
?>
<?php require __DIR__ . '/header.php'; ?>
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="content-wrapper">
  <section class="content-header">
    <h1><?= htmlspecialchars($__armaria_page_title) ?></h1>
    <div class="breadcrumb">Home &gt; <?= htmlspecialchars($__armaria_page_title) ?></div>
    <p class="subtitle"><?= htmlspecialchars($__armaria_page_subtitle) ?></p>
  </section>
  <section class="content-body">
