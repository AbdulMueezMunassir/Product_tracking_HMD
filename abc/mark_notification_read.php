<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$all = isset($_POST['all']) ? true : false;

if ($all) {
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
        $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE user_id = 1 AND is_read = 0");
        $stmt->execute();
        $_SESSION['admin_last_notification_check'] = time();
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] == 'branch') {
        $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE branch_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['branch_id']]);
        $_SESSION['branch_last_notification_check'] = time();
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid role']);
    }
} elseif ($id > 0) {
    $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
} else {
    echo json_encode(['success' => false, 'error' => 'No ID provided']);
}
?>