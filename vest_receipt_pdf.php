<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'public/vest_receipt_pdf.php' . ($query !== '' ? ('?' . $query) : '');
header('Location: ' . $target);
exit;
