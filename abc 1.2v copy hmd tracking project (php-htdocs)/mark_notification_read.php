<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    if (isset($_POST['id']) && intval($_POST['id']) > 0) {
        $id = intval($_POST['id']);
        $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $response['success'] = true;
        $response['message'] = 'Notification marked as read';
    } elseif (isset($_POST['all']) && $_POST['all'] == 1) {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
            $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE user_id = 1 AND is_read = 0");
            $stmt->execute();
            $response['success'] = true;
            $response['message'] = 'All notifications marked as read';
        } elseif (isset($_SESSION['role']) && $_SESSION['role'] == 'branch') {
            $stmt = $pdo->prepare("UPDATE system_notifications SET is_read = 1 WHERE branch_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['branch_id']]);
            $response['success'] = true;
            $response['message'] = 'All notifications marked as read';
        } else {
            $response['message'] = 'Invalid role';
        }
    } else {
        $response['message'] = 'No valid action specified';
    }
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>