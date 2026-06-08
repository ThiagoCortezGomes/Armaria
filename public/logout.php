<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
app_audit('LOGOUT', []);
session_destroy();
header("Location: login.php");
exit;

