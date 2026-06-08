<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

chdir(dirname(__DIR__));
require_once 'config/db.php';
require_once 'config/auth.php';

function test_assert(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function set_test_session(?array $user): void
{
  $_SESSION = [];
  if ($user !== null) {
    $_SESSION['user'] = $user;
    $_SESSION['last_activity_ts'] = time();
  }
  $_SESSION['_csrf_token'] = 'csrf-test-token';
}

function run_page(string $path, string $method = 'GET', ?array $user = null, array $get = [], array $post = []): string
{
  global $pdo;

  $_GET = $get;
  $_POST = $post;
  $_FILES = [];
  $_COOKIE = [];
  $_SERVER['REQUEST_METHOD'] = $method;
  $_SERVER['HTTP_HOST'] = 'localhost';
  $_SERVER['SERVER_NAME'] = 'localhost';
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
  $_SERVER['HTTP_USER_AGENT'] = 'ArmariaSmoke/1.0';
  $_SERVER['SCRIPT_NAME'] = '/Armaria/public/' . basename($path);
  set_test_session($user);
  ob_start();
  include $path;
  return (string)ob_get_clean();
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$policialUsername = 'hml_pol_' . strtolower($suffix);
$policialEmail = 'hml_' . strtolower($suffix) . '@local.test';
$policialMatricula = 'HML-' . $suffix;
$policialSenha = 'Senha#Policial123';
$policialNovaSenha = 'Senha#Nova123';
$policialResetSenha = 'Senha#Reset123';
$weaponSerial = 'HML-W-' . $suffix;
$vestSerial = 'HML-V-' . $suffix;

$pdo->exec("DELETE FROM combined_receipt_items");
$pdo->exec("DELETE FROM combined_receipts");
$pdo->exec("DELETE FROM receipts");
$pdo->exec("DELETE FROM vest_receipts");
$pdo->exec("DELETE FROM movements");
$pdo->exec("DELETE FROM vest_movements");
$pdo->exec("DELETE FROM current_assignments");
$pdo->exec("DELETE FROM current_vest_assignments");
$pdo->exec("DELETE FROM password_reset_tokens");
$pdo->exec("DELETE FROM security_events");
$pdo->exec("DELETE FROM user_logs");
$pdo->prepare("DELETE FROM munitions WHERE calibre=? AND tipo=?")->execute(['9MM', 'OPERACIONAL']);
$pdo->prepare("DELETE FROM weapons WHERE numero_serie=?")->execute([$weaponSerial]);
$pdo->prepare("DELETE FROM vests WHERE numero_serie=?")->execute([$vestSerial]);
$pdo->prepare("DELETE FROM users WHERE username=?")->execute([$policialUsername]);

$admin = $pdo->query("SELECT id, name, username, email, role FROM users WHERE username='admin' LIMIT 1")->fetch();
test_assert((bool)$admin, 'Usuario admin nao encontrado.');

run_page('public/users.php', 'POST', $admin, ['list_users' => '1'], [
  '_csrf' => 'csrf-test-token',
  'create_user' => '1',
  'posto_grad' => 'Soldado',
  'name' => 'Policial Homologacao ' . $suffix,
  'matricula' => $policialMatricula,
  'username' => $policialUsername,
  'email' => $policialEmail,
  'password' => $policialSenha,
  'role' => 'POLICIAL',
]);

$stmt = $pdo->prepare("SELECT id, name, username, email, role FROM users WHERE username=? LIMIT 1");
$stmt->execute([$policialUsername]);
$policial = $stmt->fetch();
test_assert((bool)$policial, 'Falha ao cadastrar policial.');
echo "OK user_create" . PHP_EOL;

$stmt = $pdo->prepare("
  INSERT INTO weapons (tipo, modelo, calibre, numero_serie, status)
  VALUES (?,?,?,?,?)
");
$stmt->execute(['PISTOLA', 'GLOCK 17', '9MM', $weaponSerial, 'DISPONIVEL']);
$weaponId = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("SELECT id, status FROM weapons WHERE id=? LIMIT 1");
$stmt->execute([$weaponId]);
$weapon = $stmt->fetch();
test_assert((bool)$weapon, 'Falha ao preparar arma de teste.');
test_assert($weapon['status'] === 'DISPONIVEL', 'Arma nao iniciou como DISPONIVEL.');
echo "OK weapon_seed" . PHP_EOL;

$stmt = $pdo->prepare("
  INSERT INTO vests (tamanho, numero_serie, validade, status)
  VALUES (?,?,?,?)
");
$stmt->execute(['M', $vestSerial, '2027-12-31', 'DISPONIVEL']);
$vestId = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("SELECT id, status FROM vests WHERE id=? LIMIT 1");
$stmt->execute([$vestId]);
$vest = $stmt->fetch();
test_assert((bool)$vest, 'Falha ao preparar colete de teste.');
test_assert($vest['status'] === 'DISPONIVEL', 'Colete nao iniciou como DISPONIVEL.');
echo "OK vest_seed" . PHP_EOL;

$stmt = $pdo->prepare("
  INSERT INTO munitions (calibre, tipo, quantidade)
  VALUES (?,?,?)
");
$stmt->execute(['9MM', 'OPERACIONAL', 50]);
$ammoId = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("SELECT id, quantidade FROM munitions WHERE id=? LIMIT 1");
$stmt->execute([$ammoId]);
$ammo = $stmt->fetch();
test_assert((bool)$ammo, 'Falha ao preparar municao de teste.');
test_assert((int)$ammo['quantidade'] === 50, 'Estoque de municao nao foi criado corretamente.');
echo "OK munition_seed" . PHP_EOL;

run_page('public/assign.php', 'POST', $admin, [], [
  '_csrf' => 'csrf-test-token',
  'policial_id' => (string)$policial['id'],
  'weapon_id' => [(string)$weaponId],
  'ammo_id' => [(string)$ammoId],
  'ammo_qty' => ['10'],
  'mag_qty' => ['3'],
  'vest_id' => (string)$vestId,
  'policial_password' => $policialSenha,
]);

$stmt = $pdo->prepare("SELECT status FROM weapons WHERE id=?");
$stmt->execute([$weaponId]);
test_assert($stmt->fetchColumn() === 'CAUTELADA', 'Arma nao ficou cautelada.');
$stmt = $pdo->prepare("SELECT status FROM vests WHERE id=?");
$stmt->execute([$vestId]);
test_assert($stmt->fetchColumn() === 'CAUTELADO', 'Colete nao ficou cautelado.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM current_assignments")->fetchColumn() === 1, 'Cautela de arma nao foi registrada.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM current_vest_assignments")->fetchColumn() === 1, 'Cautela de colete nao foi registrada.');
test_assert((int)$pdo->query("SELECT quantidade FROM munitions WHERE calibre='9MM' AND tipo='OPERACIONAL'")->fetchColumn() === 40, 'Estoque de municao nao reduziu na cautela.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM combined_receipts WHERE action='CAUTELA'")->fetchColumn() === 1, 'Comprovante combinado de cautela nao foi criado.');
echo "OK assign_flow" . PHP_EOL;

run_page('public/return.php', 'POST', $admin, [], [
  '_csrf' => 'csrf-test-token',
  'weapon_id' => (string)$weaponId,
  'vest_id' => (string)$vestId,
  'policial_password' => $policialSenha,
]);

$stmt = $pdo->prepare("SELECT status FROM weapons WHERE id=?");
$stmt->execute([$weaponId]);
test_assert($stmt->fetchColumn() === 'DISPONIVEL', 'Arma nao voltou para DISPONIVEL.');
$stmt = $pdo->prepare("SELECT status FROM vests WHERE id=?");
$stmt->execute([$vestId]);
test_assert($stmt->fetchColumn() === 'DISPONIVEL', 'Colete nao voltou para DISPONIVEL.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM current_assignments")->fetchColumn() === 0, 'Cautela de arma nao foi baixada na devolucao.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM current_vest_assignments")->fetchColumn() === 0, 'Cautela de colete nao foi baixada na devolucao.');
test_assert((int)$pdo->query("SELECT quantidade FROM munitions WHERE calibre='9MM' AND tipo='OPERACIONAL'")->fetchColumn() === 50, 'Estoque de municao nao retornou na devolucao.');
test_assert((int)$pdo->query("SELECT COUNT(*) FROM combined_receipts WHERE action='DEVOLUCAO'")->fetchColumn() === 1, 'Comprovante combinado de devolucao nao foi criado.');
echo "OK return_flow" . PHP_EOL;

run_page('public/change_password.php', 'POST', [
  'id' => (int)$policial['id'],
  'name' => $policial['name'],
  'username' => $policial['username'],
  'email' => $policial['email'],
  'role' => 'POLICIAL',
], [], [
  '_csrf' => 'csrf-test-token',
  'current_password' => $policialSenha,
  'new_password' => $policialNovaSenha,
  'confirm_password' => $policialNovaSenha,
]);
$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
$stmt->execute([(int)$policial['id']]);
test_assert(password_verify($policialNovaSenha, (string)$stmt->fetchColumn()), 'Troca de senha pelo usuario falhou.');
echo "OK change_password" . PHP_EOL;

$forgotOutput = run_page('public/forgot_password.php', 'POST', null, [], [
  '_csrf' => 'csrf-test-token',
  'identifier' => $policialEmail,
]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL");
$stmt->execute([(int)$policial['id']]);
test_assert((int)$stmt->fetchColumn() === 1, 'Solicitacao de reset nao criou token.');
preg_match('/reset_password\\.php\\?token=([a-f0-9]{64})/', $forgotOutput, $matches);
test_assert(!empty($matches[1]), 'Nao foi possivel obter o token bruto de reset no ambiente local.');
$rawToken = $matches[1];
echo "OK forgot_password" . PHP_EOL;

run_page('public/reset_password.php', 'POST', null, ['token' => $rawToken], [
  '_csrf' => 'csrf-test-token',
  'token' => $rawToken,
  'newpass' => $policialResetSenha,
  'confirm' => $policialResetSenha,
]);
$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
$stmt->execute([(int)$policial['id']]);
test_assert(password_verify($policialResetSenha, (string)$stmt->fetchColumn()), 'Reset de senha nao atualizou a senha do usuario.');
$stmt = $pdo->prepare("SELECT used_at FROM password_reset_tokens WHERE user_id=? ORDER BY id DESC LIMIT 1");
$stmt->execute([(int)$policial['id']]);
test_assert((string)$stmt->fetchColumn() !== '', 'Token de reset nao foi marcado como utilizado.');
echo "OK reset_password" . PHP_EOL;

echo "ALL_OK homologacao funcional concluida" . PHP_EOL;
