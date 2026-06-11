<?php
require_once 'config.php';

echo "<h2>Password Reset Tool</h2>";

// Create new password hash
$new_password = password_hash('admin123', PASSWORD_DEFAULT);
echo "<p>New hash created: " . $new_password . "</p>";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'branch') DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✓ admin_users table ready</p>";
    
    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password, role) VALUES (?, ?, 'admin') ON DUPLICATE KEY UPDATE password = ?");
    $stmt->execute(['admin', $new_password, $new_password]);
    echo "<p style='color:green'>✓ Admin user updated (admin / admin123)</p>";
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_managers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_code VARCHAR(50) UNIQUE NOT NULL,
        manager_name VARCHAR(150) NOT NULL,
        location VARCHAR(200) NOT NULL,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✓ branch_managers table ready</p>";
    
    $stmt = $pdo->prepare("INSERT INTO branch_managers (branch_code, manager_name, location, username, password) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password = ?");
    $stmt->execute(['COL-01', 'Sample Manager', 'Colombo Showroom', 'branch1', $new_password, $new_password]);
    echo "<p style='color:green'>✓ Branch user updated (branch1 / admin123)</p>";
    
    echo "<hr>";
    echo "<h3 style='color:green'>✅ Setup Complete!</h3>";
    echo "<p>You can now login:</p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username: <code>admin</code> | password: <code>admin123</code></li>";
    echo "<li><strong>Branch:</strong> username: <code>branch1</code> | password: <code>admin123</code></li>";
    echo "</ul>";
    echo "<a href='index.php' class='btn btn-primary'>Go to Login →</a>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>