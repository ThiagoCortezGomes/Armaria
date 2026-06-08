<?php
require_once __DIR__ . "/../config/auth.php";
require_role([ROLE_ADMIN, ROLE_ARMEIRO]);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Relatório - Armaria</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
</head>
<body class="armaria-admin">
<?php require_once __DIR__ . "/partials/layout_top.php"; ?>
  <div class="container">
    <div class="card">
      <div class="card-header">
        <h2 class="title">Relatório</h2>
        <p class="subtitle">Selecione o formato para geração do relatório.</p>
      </div>
      <div class="card-body">
        <div class="top-actions">
          <a class="backbtn" href="dashboard.php">← Voltar ao painel</a>
        </div>
        <div class="grid">
          <a class="tile" href="report_pdf.php" target="_blank" rel="noopener">
            <p class="label">Relatório armas</p>
            <p class="desc">Abrir relatório de armas em PDF.</p>
          </a>
          <a class="tile" href="report_excel.php">
            <p class="label">Relatório Excel</p>
            <p class="desc">Baixar relatório de armamento em Excel.</p>
          </a>
          <a class="tile" href="report_vests_pdf.php" target="_blank" rel="noopener">
            <p class="label">Relatório de coletes</p>
            <p class="desc">Abrir relatório de coletes em PDF.</p>
          </a>
        </div>
        <div class="footer">© <?= date('Y') ?> Armaria • Uso interno</div>
      </div>
    </div>
  </div>
<?php require_once __DIR__ . "/partials/layout_bottom.php"; ?>
</body>
</html>
