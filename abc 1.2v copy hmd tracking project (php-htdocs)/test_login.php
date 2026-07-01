<?php
require_once 'config.php';

echo "<h2>Testing Database Connection and Login</h2>";

echo "<h3>1. Checking admin_users table:</h3>";
$stmt = $pdo->query("SELECT * FROM admin_users WHERE username = 'admin'");
$admin = $stmt->fetch();

if ($admin) {
    echo "<p style='color:green'>✓ Admin user found</p>";
    echo "Username: " . $admin['username'] . "<br>";
    echo "Role: " . $admin['role'] . "<br>";
    echo "Password hash: " . substr($admin['password'], 0, 30) . "...<br>";
    
    $test_password = 'admin123';
    if (password_verify($test_password, $admin['password'])) {
        echo "<p style='color:green;font-size:18px'>✓✓✓ PASSWORD 'admin123' VERIFIED! ✓✓✓</p>";
    } else {
        echo "<p style='color:red'>✗ Password verification failed</p>";
    }
} else {
    echo "<p style='color:red'>✗ No admin user found</p>";
}

echo "<h3>2. Checking branch_managers table:</h3>";
$stmt = $pdo->query("SELECT * FROM branch_managers");
$branches = $stmt->fetchAll();

if (count($branches) > 0) {
    echo "<p style='color:green'>✓ " . count($branches) . " branch(es) found</p>";
    foreach($branches as $b) {
        echo "- " . $b['username'] . " / " . $b['location'] . "<br>";
    }
} else {
    echo "<p style='color:red'>✗ No branches found</p>";
}

echo "<h3>3. All tables in database:</h3>";
$tables = $pdo->query("SHOW TABLES")->fetchAll();
echo "<ul>";
foreach($tables as $table) {
    $tableName = reset($table);
    echo "<li>$tableName</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p><a href='index.php'>Go to Login Page →</a></p>";
?>