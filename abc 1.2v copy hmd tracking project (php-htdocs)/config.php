<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'hameedia_tracking';
$user = 'root';
$pass = '';

$email_config = [
    'host' => 'smtp.gmail.com',
    'username' => 'your_email@gmail.com',
    'password' => 'your_app_password',
    'port' => 587,
    'from_email' => 'noreply@hameedia.com',
    'from_name' => 'Hameedia Order System'
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function addNotification($pdo, $user_id, $branch_id, $user_role, $type, $title, $msg, $link) {
    try {
        $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, branch_id, user_role, notification_type, title, message, link, is_read, created_at) VALUES (?,?,?,?,?,?,?,0, NOW())");
        $stmt->execute([$user_id, $branch_id, $user_role, $type, $title, $msg, $link]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("addNotification error: " . $e->getMessage());
        return false;
    }
}

function sendEmailNotification($to_email, $subject, $message_body) {
    global $email_config;
    
    if (empty($to_email)) return false;
    
    $is_localhost = ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_ADDR'] == '127.0.0.1');
    
    if ($is_localhost) {
        error_log("📧 EMAIL (localhost - not sent): To: $to_email, Subject: $subject");
        return true;
    }
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . $email_config['from_email'] . "\r\n";
    
    return @mail($to_email, $subject, $message_body, $headers);
}
?>