<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
  $sessionDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'sessions';
  if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0777, true);
  }
  if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
  }
}

session_start();
require_once __DIR__ . "/constants.php";
require_once __DIR__ . "/security.php";
require_once __DIR__ . "/audit.php";

function require_login(): void {
  $now = time();

  if (!empty($_SESSION['user'])) {
    $lastActivity = (int)($_SESSION['last_activity_ts'] ?? 0);
    if ($lastActivity > 0 && ($now - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
      session_unset();
      session_destroy();
      header("Location: login.php?timeout=1");
      exit;
    }
    $_SESSION['last_activity_ts'] = $now;
  }

  if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
  }

  if (!empty($_SESSION['user']['must_change_password'])) {
    $currentScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!in_array($currentScript, ['change_password.php', 'logout.php', 'ping.php'], true)) {
      header("Location: change_password.php?force=1");
      exit;
    }
  }
}

function require_role(array $roles): void {
  require_login();
  $role = $_SESSION['user']['role'] ?? '';
  if (!in_array($role, $roles, true)) {
    http_response_code(403);
    die("Acesso negado.");
  }
}

