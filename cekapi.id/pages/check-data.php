<?php
require __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

// Ubah format HTML (T) ke format spasi MySQL
$start = str_replace('T', ' ', $start) . ':00';
$end = str_replace('T', ' ', $end) . ':59';

if ($start !== '' && $end !== '') {
    $st = $pdo->prepare('SELECT COUNT(*) FROM data_sensor WHERE waktu_rekam BETWEEN ? AND ?');
    $st->execute([$start, $end]);
    $count = (int) $st->fetchColumn();
} else {
    $count = 0;
}

header('Content-Type: application/json');
echo json_encode(['count' => $count]);