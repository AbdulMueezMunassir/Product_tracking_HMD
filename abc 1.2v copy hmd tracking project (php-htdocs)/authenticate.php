<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = 'Please enter both username and password';
        header('Location: index.php');
        exit;
    }
    
    // Check admin
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = $admin['username'];
            $_SESSION['admin_last_notification_check'] = time();
            header('Location: admin_dashboard.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Admin check error: " . $e->getMessage());
    }
    
    // Check branch manager
    try {
        $stmt = $pdo->prepare("SELECT * FROM branch_managers WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $branch = $stmt->fetch();
        
        if ($branch && password_verify($password, $branch['password'])) {
            $_SESSION['user_id'] = $branch['id'];
            $_SESSION['role'] = 'branch';
            $_SESSION['branch_id'] = $branch['id'];
            $_SESSION['branch_location'] = $branch['location'];
            $_SESSION['branch_code'] = $branch['branch_code'];
            $_SESSION['username'] = $branch['manager_name'];
            $_SESSION['branch_last_notification_check'] = time();
            header('Location: branch_dashboard.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Branch check error: " . $e->getMessage());
    }
    
    $_SESSION['login_error'] = 'Invalid username or password. Please try again.';
    header('Location: index.php');
    exit;
}
?>