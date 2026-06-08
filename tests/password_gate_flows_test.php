<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

function test_assert(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$suffix = bin2hex(random_bytes(4));
$username = 'tpol_' . $suffix;
$matricula = 'M' . $suffix;
$password = 'Senha#Teste123';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
  INSERT INTO users (name, matricula, username, email, password_hash, role, posto_grad)
  VALUES (?,?,?,?,?,?,?)
");
$stmt->execute([
  'Teste Policial ' . $suffix,
  $matricula,
  $username,
  null,
  $hash,
  ROLE_POLICIAL,
  'Soldado',
]);
$policialId = (int)$pdo->lastInsertId();

try {
  $ops = ['weapon_assign', 'weapon_return', 'vest_assign', 'vest_return'];

  foreach ($ops as $op) {
    $failed = false;
    try {
      verify_policial_password_or_throw($pdo, $op, $policialId, 'senha-errada');
    } catch (Throwable $e) {
      $failed = str_contains($e->getMessage(), 'Senha do policial inválida');
    }
    test_assert($failed, "Deveria falhar com senha inválida em {$op}");

    verify_policial_password_or_throw($pdo, $op, $policialId, $password);
  }

  for ($i = 0; $i < PASSWORD_GATE_MAX_ATTEMPTS; $i++) {
    try {
      verify_policial_password_or_throw($pdo, 'weapon_assign', $policialId, 'errada');
    } catch (Throwable $e) {
      // esperado
    }
  }

  $rateLimited = false;
  try {
    verify_policial_password_or_throw($pdo, 'weapon_assign', $policialId, 'errada');
  } catch (Throwable $e) {
    $rateLimited = str_contains($e->getMessage(), 'Muitas tentativas inválidas');
  }
  test_assert($rateLimited, 'Rate limit de senha deveria bloquear após limite');

  password_gate_clear_failures('weapon_assign', $policialId);
  verify_policial_password_or_throw($pdo, 'weapon_assign', $policialId, $password);

  echo "OK: testes de senha nos 4 fluxos concluídos." . PHP_EOL;
} finally {
  $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
  $stmt->execute([$policialId]);
}
