<?php
declare(strict_types=1);

function csrf_token(): string
{
  if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['_csrf_token'];
}

function csrf_input_field(): string
{
  return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_validate_request(): bool
{
  $sessionToken = (string)($_SESSION['_csrf_token'] ?? '');
  $requestToken = (string)($_POST['_csrf'] ?? '');
  return $sessionToken !== '' && $requestToken !== '' && hash_equals($sessionToken, $requestToken);
}

function password_gate_key(string $operation, int $policialId): string
{
  return $operation . ':' . $policialId;
}

function password_gate_is_allowed(string $operation, int $policialId): bool
{
  $key = password_gate_key($operation, $policialId);
  $now = time();
  $bucket = $_SESSION['password_gate'][$key] ?? null;

  if (!is_array($bucket)) {
    return true;
  }

  $firstTs = (int)($bucket['first_ts'] ?? 0);
  $count = (int)($bucket['count'] ?? 0);

  if ($firstTs <= 0 || ($now - $firstTs) > PASSWORD_GATE_WINDOW_SECONDS) {
    unset($_SESSION['password_gate'][$key]);
    return true;
  }

  return $count < PASSWORD_GATE_MAX_ATTEMPTS;
}

function password_gate_register_failure(
  PDO $pdo,
  string $operation,
  int $policialId,
  array $context = []
): void {
  $key = password_gate_key($operation, $policialId);
  $now = time();
  $bucket = $_SESSION['password_gate'][$key] ?? ['count' => 0, 'first_ts' => $now];

  $firstTs = (int)($bucket['first_ts'] ?? 0);
  if ($firstTs <= 0 || ($now - $firstTs) > PASSWORD_GATE_WINDOW_SECONDS) {
    $bucket = ['count' => 0, 'first_ts' => $now];
  }

  $bucket['count'] = (int)$bucket['count'] + 1;
  $_SESSION['password_gate'][$key] = $bucket;

  security_log_event($pdo, 'PASSWORD_FAIL', $operation, $policialId, $context);
}

function password_gate_clear_failures(string $operation, int $policialId): void
{
  $key = password_gate_key($operation, $policialId);
  unset($_SESSION['password_gate'][$key]);
}

function verify_policial_password_or_throw(
  PDO $pdo,
  string $operation,
  int $policialId,
  string $plainPassword,
  array $context = []
): void {
  if ($policialId <= 0 || $plainPassword === '') {
    throw new Exception("Senha do policial inválida.");
  }

  if (!password_gate_is_allowed($operation, $policialId)) {
    throw new Exception("Muitas tentativas inválidas. Tente novamente em alguns minutos.");
  }

  $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? AND role=? LIMIT 1");
  $stmt->execute([$policialId, ROLE_POLICIAL]);
  $p = $stmt->fetch();

  if (!$p || !password_verify($plainPassword, (string)$p['password_hash'])) {
    password_gate_register_failure($pdo, $operation, $policialId, $context);
    throw new Exception("Senha do policial inválida.");
  }

  password_gate_clear_failures($operation, $policialId);
}

function security_log_event(
  PDO $pdo,
  string $eventType,
  string $operation,
  int $policialId,
  array $context = []
): void {
  try {
    static $securityEventsTableExists = null;

    if ($securityEventsTableExists === null) {
      $stmt = $pdo->query("SHOW TABLES LIKE 'security_events'");
      $securityEventsTableExists = (bool)$stmt->fetchColumn();
    }

    if (!$securityEventsTableExists) {
      error_log("[SECURITY][$eventType][$operation] policial_id={$policialId}");
      return;
    }

    $stmt = $pdo->prepare("
      INSERT INTO security_events (event_type, operation, policial_id, context_json, ip_address)
      VALUES (?,?,?,?,?)
    ");
    $stmt->execute([
      $eventType,
      $operation,
      $policialId,
      json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
  } catch (Throwable $e) {
    error_log("[SECURITY][LOG_ERROR] " . $e->getMessage());
  }
}
