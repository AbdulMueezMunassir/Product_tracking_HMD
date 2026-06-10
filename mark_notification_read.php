<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not logged in', 'success' => false]);
    exit;
}

$role = $_SESSION['role'];

if ($role === 'admin') {
    $_SESSION['admin_last_notification_check'] = time();
    
    echo json_encode([
        'success' => true,
        'message' => 'Notifications marked as read',
        'new_timestamp' => $_SESSION['admin_last_notification_check']
    ]);
    
} elseif ($role === 'branch') {
    $_SESSION['branch_last_notification_check'] = time();
    
    echo json_encode([
        'success' => true,
        'message' => 'Notifications marked as read',
        'new_timestamp' => $_SESSION['branch_last_notification_check']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
}

exit;
?>