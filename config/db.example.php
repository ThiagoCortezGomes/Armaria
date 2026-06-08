<?php
declare(strict_types=1);

// Copie este arquivo para config/db.php e preencha com suas credenciais.
// Copy this file to config/db.php and fill in your credentials.

$DB_HOST = "127.0.0.1";   // ex: localhost ou o host MySQL do seu provedor
$DB_NAME = "armaria";      // nome do banco de dados
$DB_USER = "root";         // usuário do MySQL
$DB_PASS = "";             // senha do MySQL

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  die("Erro ao conectar ao banco.");
}
