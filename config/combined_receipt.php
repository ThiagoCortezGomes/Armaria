<?php
declare(strict_types=1);

function ensure_combined_receipt_tables(PDO $pdo): bool
{
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS combined_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_code VARCHAR(40) NOT NULL UNIQUE,
        policial_id INT NULL,
        armeiro_id INT NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_combined_receipts_policial (policial_id),
        INDEX idx_combined_receipts_created_at (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS combined_receipt_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        combined_receipt_id INT NOT NULL,
        movement_type ENUM('WEAPON','VEST') NOT NULL,
        movement_id INT NOT NULL,
        UNIQUE KEY uq_combined_receipt_item (combined_receipt_id, movement_type, movement_id),
        INDEX idx_combined_receipt_lookup (movement_type, movement_id),
        CONSTRAINT fk_combined_receipt_items_receipt
          FOREIGN KEY (combined_receipt_id) REFERENCES combined_receipts(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
  } catch (Throwable $e) {
    error_log('[COMBINED_RECEIPT][TABLE_ERROR] ' . $e->getMessage());
    $ready = false;
  }

  return $ready;
}

function create_combined_receipt(
  PDO $pdo,
  int $policialId,
  int $armeiroId,
  string $action,
  array $items
): ?string {
  if ($policialId <= 0 || $armeiroId <= 0 || $action === '' || empty($items)) {
    return null;
  }
  if (!ensure_combined_receipt_tables($pdo)) {
    return null;
  }

  $receiptCode = null;
  for ($attempt = 0; $attempt < 5; $attempt++) {
    $candidate = 'COMP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = $pdo->prepare("
      INSERT INTO combined_receipts (receipt_code, policial_id, armeiro_id, action)
      VALUES (?,?,?,?)
    ");
    try {
      $stmt->execute([$candidate, $policialId, $armeiroId, $action]);
      $receiptCode = $candidate;
      break;
    } catch (Throwable $e) {
      // colisão rara de código; tenta novamente
      continue;
    }
  }

  if ($receiptCode === null) {
    return null;
  }

  $receiptId = (int)$pdo->lastInsertId();
  $stmtItem = $pdo->prepare("
    INSERT INTO combined_receipt_items (combined_receipt_id, movement_type, movement_id)
    VALUES (?,?,?)
  ");
  foreach ($items as $item) {
    $type = strtoupper((string)($item['movement_type'] ?? ''));
    $movementId = (int)($item['movement_id'] ?? 0);
    if (!in_array($type, ['WEAPON', 'VEST'], true) || $movementId <= 0) {
      continue;
    }
    $stmtItem->execute([$receiptId, $type, $movementId]);
  }

  return $receiptCode;
}
