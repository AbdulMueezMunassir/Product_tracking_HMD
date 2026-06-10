<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not logged in', 'success' => false]);
    exit;
}

$role = $_SESSION['role'];
$last_update = isset($_GET['last_update']) ? intval($_GET['last_update']) : 0;

if ($role === 'admin') {
    $total_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
    $pending_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE packing_status = 'No' OR packing_status IS NULL OR dispatch_status = 'No' OR dispatch_status IS NULL")->fetchColumn();
    $completed_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE packing_status = 'Yes' AND dispatch_status = 'Yes'")->fetchColumn();
    $branches_count = $pdo->query("SELECT COUNT(*) FROM branch_managers")->fetchColumn();
    
    $stmt = $pdo->query("SELECT o.id, o.order_no, o.customer_name, o.total_amount, o.packing_status, o.dispatch_status, o.created_at, 
                                o.branch_comment, o.barcode_number, o.product_availability, o.unavailability_reason, o.partial_comment, o.invoice_no,
                                b.location as branch_loc 
                         FROM shop_orders o 
                         LEFT JOIN branch_managers b ON o.branch_id = b.id 
                         ORDER BY o.created_at DESC LIMIT 10");
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $last_check = $_SESSION['admin_last_notification_check'] ?? time();
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shop_orders 
                           WHERE updated_at > FROM_UNIXTIME(?) 
                           AND branch_id IS NOT NULL");
    $stmt->execute([$last_check]);
    $notification_count = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_orders' => intval($total_orders),
            'pending_orders' => intval($pending_orders),
            'completed_orders' => intval($completed_orders),
            'branches_count' => intval($branches_count)
        ],
        'recent_orders' => $recent_orders,
        'notification_count' => intval($notification_count),
        'timestamp' => time()
    ]);
    
} elseif ($role === 'branch') {
    $branch_id = $_SESSION['branch_id'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM shop_orders WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $total_orders = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM shop_orders WHERE branch_id = ? AND (packing_status = 'No' OR packing_status IS NULL)");
    $stmt->execute([$branch_id]);
    $pending_orders = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as completed FROM shop_orders WHERE branch_id = ? AND packing_status = 'Yes' AND dispatch_status = 'Yes'");
    $stmt->execute([$branch_id]);
    $completed_orders = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as not_available FROM shop_orders WHERE branch_id = ? AND product_availability = 'not_available'");
    $stmt->execute([$branch_id]);
    $not_available_orders = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT o.id, o.order_no, o.customer_name, o.total_amount, o.packing_status, o.dispatch_status, o.product_availability, o.status, o.created_at,
                                  (SELECT COUNT(*) FROM order_products WHERE order_id = o.id) as items_count 
                           FROM shop_orders o 
                           WHERE o.branch_id = ? 
                           ORDER BY o.created_at DESC LIMIT 20");
    $stmt->execute([$branch_id]);
    $orders_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $last_check = $_SESSION['branch_last_notification_check'] ?? time();
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shop_orders 
                           WHERE branch_id = ? 
                           AND created_at > FROM_UNIXTIME(?)
                           AND (packing_status IS NULL OR packing_status = 'No')");
    $stmt->execute([$branch_id, $last_check]);
    $notification_count = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_orders' => intval($total_orders),
            'pending_orders' => intval($pending_orders),
            'completed_orders' => intval($completed_orders),
            'not_available_orders' => intval($not_available_orders)
        ],
        'orders_list' => $orders_list,
        'notification_count' => intval($notification_count),
        'timestamp' => time()
    ]);
}

exit;
?>