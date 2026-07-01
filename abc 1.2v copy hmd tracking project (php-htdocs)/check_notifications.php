<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not logged in', 'count' => 0]);
    exit;
}

$last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
$role = $_SESSION['role'];
$response = ['count' => 0, 'notifications' => [], 'timestamp' => time()];

try {
    if ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT id, title, message, link, created_at, is_read FROM system_notifications WHERE user_id = 1 AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
        $stmt->execute();
        $notifications = $stmt->fetchAll();
        
        $msg_count = $pdo->query("SELECT COUNT(*) FROM showroom_messages WHERE from_showroom IS NOT NULL AND is_read_admin = '0'")->fetchColumn();
        
        $total_count = count($notifications) + intval($msg_count);
        
        $response['count'] = $total_count;
        $response['notifications'] = $notifications;
        $response['branch_messages'] = intval($msg_count);
        
    } elseif ($role === 'branch') {
        $branch_id = $_SESSION['branch_id'];
        
        $stmt = $pdo->prepare("SELECT id, title, message, link, created_at, is_read FROM system_notifications WHERE branch_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$branch_id]);
        $notifications = $stmt->fetchAll();
        
        $response['count'] = count($notifications);
        $response['notifications'] = $notifications;
    }
} catch (PDOException $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>