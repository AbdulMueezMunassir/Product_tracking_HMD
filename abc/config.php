<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'hameedia_tracking';
$user = 'root';
$pass = '';

// Email configuration for PHPMailer
$email_config = [
    'host' => 'smtp.gmail.com',      // Your SMTP server
    'username' => 'your_email@gmail.com', // Your email (update this)
    'password' => 'your_app_password',    // Your app password (update this)
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

// Email sending function with PHPMailer
function sendEmailNotification($to_email, $subject, $message_body) {
    global $email_config;
    
    // If no email provided, return false
    if (empty($to_email)) return false;
    
    // Try using PHPMailer if available
    if (file_exists('mail/PHPMailerAutoload.php')) {
        require_once 'mail/PHPMailerAutoload.php';
        
        $mail = new PHPMailer();
        try {
            $mail->isSMTP();
            $mail->Host = $email_config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $email_config['username'];
            $mail->Password = $email_config['password'];
            $mail->SMTPSecure = 'tls';
            $mail->Port = $email_config['port'];
            
            $mail->setFrom($email_config['from_email'], $email_config['from_name']);
            $mail->addAddress($to_email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message_body;
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $e->getMessage());
            // Fallback to mail() function
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: " . $email_config['from_email'] . "\r\n";
            return mail($to_email, $subject, $message_body, $headers);
        }
    } else {
        // Fallback to mail() function
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $email_config['from_email'] . "\r\n";
        return mail($to_email, $subject, $message_body, $headers);
    }
}
?>