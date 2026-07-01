<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$last_update = isset($_GET['last_update']) ? intval($_GET['last_update']) : 0;

$stmt = $pdo->prepare("SELECT id, UNIX_TIMESTAMP(updated_at) as updated FROM shop_orders WHERE updated_at > FROM_UNIXTIME(?)");
$stmt->execute([$last_update]);
$updated_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'orders' => $updated_orders,
    'timestamp' => time()
]);
?>