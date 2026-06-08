<?php
require_once __DIR__ . "/../config/auth.php";
require_login();
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';
