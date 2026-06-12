<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not logged in', 'notifications' => [], 'count' => 0]);
    exit;
}

$role = $_SESSION['role'];
$last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;

if ($role === 'admin') {
    $stmt = $pdo->prepare("SELECT o.id, o.order_no, o.customer_name, o.updated_at, o.packing_status, o.dispatch_status, o.invoice_no, b.location as branch_loc FROM shop_orders o LEFT JOIN branch_managers b ON o.branch_id = b.id WHERE o.updated_at > FROM_UNIXTIME(?) AND o.branch_id IS NOT NULL AND (o.packing_status = 'Yes' OR o.dispatch_status = 'Yes' OR o.invoice_no IS NOT NULL) ORDER BY o.updated_at DESC LIMIT 20");
    $stmt->execute([$last_check]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shop_orders WHERE updated_at > FROM_UNIXTIME(?) AND branch_id IS NOT NULL AND (packing_status = 'Yes' OR dispatch_status = 'Yes' OR invoice_no IS NOT NULL)");
    $stmt->execute([$last_check]);
    $count = $stmt->fetchColumn();
    
    echo json_encode(['count' => intval($count), 'notifications' => $notifications, 'role' => 'admin']);
    
} elseif ($role === 'branch') {
    $branch_id = $_SESSION['branch_id'];
    
    $stmt = $pdo->prepare("SELECT o.id, o.order_no, o.customer_name, o.total_amount, o.created_at, o.status FROM shop_orders o WHERE o.branch_id = ? AND o.created_at > FROM_UNIXTIME(?) AND (o.packing_status IS NULL OR o.packing_status = 'No') ORDER BY o.created_at DESC LIMIT 20");
    $stmt->execute([$branch_id, $last_check]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shop_orders WHERE branch_id = ? AND created_at > FROM_UNIXTIME(?) AND (packing_status IS NULL OR packing_status = 'No')");
    $stmt->execute([$branch_id, $last_check]);
    $count = $stmt->fetchColumn();
    
    echo json_encode(['count' => intval($count), 'notifications' => $notifications, 'role' => 'branch']);
}

exit;
?>