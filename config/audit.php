<?php
declare(strict_types=1);

function audit_ensure_table(PDO $pdo): bool
{
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS user_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        role VARCHAR(20) NULL,
        action VARCHAR(120) NOT NULL,
        context_json LONGTEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_logs_created_at (created_at),
        INDEX idx_user_logs_user_id (user_id),
        INDEX idx_user_logs_action (action)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
  } catch (Throwable $e) {
    error_log('[AUDIT][TABLE_ERROR] ' . $e->getMessage());
    $ready = false;
  }

  return $ready;
}

function audit_log(PDO $pdo, string $action, array $context = []): void
{
  if ($action === '') {
    return;
  }
  if (!audit_ensure_table($pdo)) {
    return;
  }

  $user = $_SESSION['user'] ?? [];
  $userId = isset($user['id']) ? (int)$user['id'] : null;
  $username = isset($user['username']) ? (string)$user['username'] : null;
  $role = isset($user['role']) ? (string)$user['role'] : null;
  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

  try {
    $stmt = $pdo->prepare("
      INSERT INTO user_logs (user_id, username, role, action, context_json, ip_address, user_agent)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([
      $userId,
      $username,
      $role,
      $action,
      json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $ip,
      $ua,
    ]);
  } catch (Throwable $e) {
    error_log('[AUDIT][LOG_ERROR] ' . $e->getMessage());
  }
}

function app_audit(string $action, array $context = []): void
{
  $pdo = $GLOBALS['pdo'] ?? null;
  if ($pdo instanceof PDO) {
    audit_log($pdo, $action, $context);
  }
}
