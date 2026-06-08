<?php
declare(strict_types=1);

$DB_HOST = "127.0.0.1";
$DB_NAME = "armaria";
$DB_USER = "root";
$DB_PASS = "";

$pdo = new PDO(
  "mysql:host={$DB_HOST};charset=utf8mb4",
  $DB_USER,
  $DB_PASS,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]
);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$DB_NAME}`");
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

$dropTables = [
  'combined_receipt_items',
  'combined_receipts',
  'current_assignments',
  'current_vest_assignments',
  'movements',
  'munitions',
  'password_reset_tokens',
  'receipts',
  'schema_migrations',
  'security_events',
  'user_logs',
  'users',
  'vest_movements',
  'vest_receipts',
  'vests',
  'weapons',
];

foreach ($dropTables as $table) {
  $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

$sqlStatements = [
  "USE `{$DB_NAME}`",
  <<<SQL
CREATE TABLE users (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  matricula VARCHAR(30) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  recovery_hash VARCHAR(255) NULL,
  role ENUM('ADMIN','ARMEIRO','POLICIAL') NOT NULL DEFAULT 'POLICIAL',
  posto_grad VARCHAR(50) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_users_matricula (matricula),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE weapons (
  id INT NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(60) NOT NULL,
  modelo VARCHAR(80) NOT NULL,
  calibre VARCHAR(40) NOT NULL,
  numero_serie VARCHAR(80) NOT NULL,
  status ENUM('DISPONIVEL','CAUTELADA','INATIVA') NOT NULL DEFAULT 'DISPONIVEL',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY numero_serie (numero_serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE vests (
  id INT NOT NULL AUTO_INCREMENT,
  tamanho VARCHAR(20) NOT NULL,
  numero_serie VARCHAR(80) NOT NULL,
  validade DATE NULL,
  status ENUM('DISPONIVEL','CAUTELADO','INATIVO') NOT NULL DEFAULT 'DISPONIVEL',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY numero_serie (numero_serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE munitions (
  id INT NOT NULL AUTO_INCREMENT,
  calibre VARCHAR(40) NOT NULL,
  tipo VARCHAR(60) NOT NULL,
  quantidade INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_munitions_calibre_tipo (calibre, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE current_assignments (
  id INT NOT NULL AUTO_INCREMENT,
  weapon_id INT NOT NULL,
  policial_id INT NOT NULL,
  armeiro_id INT NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ammo_id INT NULL,
  ammo_qty INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY weapon_id (weapon_id),
  KEY policial_id (policial_id),
  KEY armeiro_id (armeiro_id),
  KEY idx_current_assignments_ammo_id (ammo_id),
  CONSTRAINT fk_current_assignments_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id),
  CONSTRAINT fk_current_assignments_policial FOREIGN KEY (policial_id) REFERENCES users(id),
  CONSTRAINT fk_current_assignments_armeiro FOREIGN KEY (armeiro_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE current_vest_assignments (
  vest_id INT NOT NULL,
  policial_id INT NOT NULL,
  armeiro_id INT NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vest_id),
  UNIQUE KEY vest_id (vest_id),
  KEY fk_cva_policial (policial_id),
  KEY fk_cva_armeiro (armeiro_id),
  CONSTRAINT fk_cva_vest FOREIGN KEY (vest_id) REFERENCES vests(id),
  CONSTRAINT fk_cva_policial FOREIGN KEY (policial_id) REFERENCES users(id),
  CONSTRAINT fk_cva_armeiro FOREIGN KEY (armeiro_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE movements (
  id INT NOT NULL AUTO_INCREMENT,
  weapon_id INT NULL,
  policial_id INT NULL,
  armeiro_id INT NOT NULL,
  action ENUM('CAUTELA','DEVOLUCAO') NOT NULL,
  action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY movements_ibfk_1 (weapon_id),
  KEY policial_id (policial_id),
  KEY armeiro_id (armeiro_id),
  KEY idx_movements_action_at (action_at),
  CONSTRAINT movements_ibfk_1 FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE SET NULL,
  CONSTRAINT fk_movements_policial FOREIGN KEY (policial_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_movements_armeiro FOREIGN KEY (armeiro_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE receipts (
  id INT NOT NULL AUTO_INCREMENT,
  movement_id INT NOT NULL,
  receipt_code VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY receipt_code (receipt_code),
  KEY movement_id (movement_id),
  CONSTRAINT fk_receipts_movement FOREIGN KEY (movement_id) REFERENCES movements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE vest_movements (
  id INT NOT NULL AUTO_INCREMENT,
  vest_id INT NOT NULL,
  policial_id INT NOT NULL,
  armeiro_id INT NOT NULL,
  action ENUM('CAUTELA','DEVOLUCAO') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_vm_vest (vest_id),
  KEY fk_vm_policial (policial_id),
  KEY fk_vm_armeiro (armeiro_id),
  KEY idx_vest_movements_created_at (created_at),
  CONSTRAINT fk_vm_vest FOREIGN KEY (vest_id) REFERENCES vests(id),
  CONSTRAINT fk_vm_policial FOREIGN KEY (policial_id) REFERENCES users(id),
  CONSTRAINT fk_vm_armeiro FOREIGN KEY (armeiro_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE vest_receipts (
  movement_id INT NOT NULL,
  receipt_code VARCHAR(40) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (movement_id),
  UNIQUE KEY receipt_code (receipt_code),
  UNIQUE KEY movement_id (movement_id),
  CONSTRAINT fk_vest_receipts_movement FOREIGN KEY (movement_id) REFERENCES vest_movements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE combined_receipts (
  id INT NOT NULL AUTO_INCREMENT,
  receipt_code VARCHAR(40) NOT NULL,
  policial_id INT NULL,
  armeiro_id INT NOT NULL,
  action VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY receipt_code (receipt_code),
  KEY idx_combined_receipts_policial (policial_id),
  KEY idx_combined_receipts_created_at (created_at),
  CONSTRAINT fk_combined_receipts_policial FOREIGN KEY (policial_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_combined_receipts_armeiro FOREIGN KEY (armeiro_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE combined_receipt_items (
  id INT NOT NULL AUTO_INCREMENT,
  combined_receipt_id INT NOT NULL,
  movement_type ENUM('WEAPON','VEST') NOT NULL,
  movement_id INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_combined_receipt_item (combined_receipt_id, movement_type, movement_id),
  KEY idx_combined_receipt_lookup (movement_type, movement_id),
  CONSTRAINT fk_combined_receipt_items_receipt FOREIGN KEY (combined_receipt_id) REFERENCES combined_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_tokens_token_hash (token_hash),
  KEY idx_password_reset_tokens_user_id (user_id),
  KEY idx_password_reset_tokens_expires_at (expires_at),
  CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(40) NOT NULL,
  operation VARCHAR(60) NOT NULL,
  policial_id INT NOT NULL,
  context_json LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_security_events_policial_id (policial_id),
  KEY idx_security_events_created_at (created_at),
  CONSTRAINT fk_security_events_policial FOREIGN KEY (policial_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE user_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  username VARCHAR(120) NULL,
  role VARCHAR(20) NULL,
  action VARCHAR(120) NOT NULL,
  context_json LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_logs_user_id (user_id),
  KEY idx_user_logs_action (action),
  KEY idx_user_logs_created_at (created_at),
  CONSTRAINT fk_user_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
  <<<SQL
CREATE TABLE schema_migrations (
  version VARCHAR(255) NOT NULL,
  executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];

foreach ($sqlStatements as $sql) {
  $pdo->exec($sql);
}

$adminPassword = 'Admin@123';
$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("
  INSERT INTO users (name, matricula, username, email, password_hash, role, posto_grad)
  VALUES (?,?,?,?,?,?,?)
");
$stmt->execute([
  'Administrador Local',
  'ADMIN-LOCAL',
  'admin',
  'admin@local.test',
  $adminHash,
  'ADMIN',
  'Administrador',
]);

echo "Banco reconstruido com sucesso." . PHP_EOL;
echo "Usuario: admin" . PHP_EOL;
echo "Senha: {$adminPassword}" . PHP_EOL;
