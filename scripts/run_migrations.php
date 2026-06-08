<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo->exec("
  CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) NOT NULL PRIMARY KEY,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  )
");

$migrationDir = __DIR__ . '/../database/migrations';
$files = glob($migrationDir . '/*.sql') ?: [];
sort($files, SORT_NATURAL);

$applied = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$appliedSet = array_flip($applied);

foreach ($files as $file) {
  $version = basename($file);
  if (isset($appliedSet[$version])) {
    echo "[SKIP] {$version}" . PHP_EOL;
    continue;
  }

  $sql = trim((string)file_get_contents($file));
  if ($sql === '') {
    echo "[SKIP] {$version} (vazio)" . PHP_EOL;
    continue;
  }

  try {
    $pdo->exec($sql);
    $stmt = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
    $stmt->execute([$version]);
    echo "[OK] {$version}" . PHP_EOL;
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, '1061') || str_contains($msg, 'already exists')) {
      $stmt = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
      $stmt->execute([$version]);
      echo "[OK] {$version} (já existia)" . PHP_EOL;
      continue;
    }
    throw $e;
  }
}
