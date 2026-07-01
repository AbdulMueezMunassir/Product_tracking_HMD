<?php
session_start();
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php?tab=messages');
    exit;
}

$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
$reply_message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';

if (empty($reply_message)) {
    $_SESSION['error_message'] = 'Reply message cannot be empty.';
    header('Location: admin_dashboard.php?tab=messages');
    exit;
}

if ($message_id <= 0) {
    $_SESSION['error_message'] = 'Invalid message ID.';
    header('Location: admin_dashboard.php?tab=messages');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT branch_id, subject, message FROM branch_messages WHERE id = ?");
    $stmt->execute([$message_id]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$msg) {
        throw new Exception("Message not found.");
    }
    
    $stmt = $pdo->prepare("UPDATE branch_messages SET 
        admin_reply = ?, 
        admin_reply_date = NOW(), 
        is_read_branch = 0,
        is_read_admin = 1
        WHERE id = ?");
    $stmt->execute([$reply_message, $message_id]);
    
    if ($stmt->rowCount() == 0) {
        throw new Exception("Failed to update message.");
    }
    
    $branch_id = $msg['branch_id'];
    $subject_text = $subject ?: 'Admin Reply';
    
    $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, branch_id, user_role, notification_type, title, message, link, is_read, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
    $stmt->execute([
        null, 
        $branch_id, 
        'branch', 
        'message', 
        "💬 Admin Reply: " . $subject_text, 
        "Admin replied to your message: " . substr($reply_message, 0, 150) . (strlen($reply_message) > 150 ? '...' : ''), 
        "branch_dashboard.php?tab=messages"
    ]);
    
    $_SESSION['success_message'] = "✅ Reply sent to branch successfully!";
    
} catch (Exception $e) {
    error_log("Reply Error: " . $e->getMessage());
    $_SESSION['error_message'] = "Error sending reply: " . $e->getMessage();
}

header('Location: admin_dashboard.php?tab=messages');
exit;
?>