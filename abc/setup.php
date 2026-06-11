<?php
require_once 'config.php';

echo "<h1>Hameedia System Setup</h1>";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'branch') DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✓ admin_users table created</p>";
    
    $pdo->exec("INSERT INTO admin_users (username, password, role) 
                VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')
                ON DUPLICATE KEY UPDATE password = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'");
    echo "<p>✓ Admin user created (admin / admin123)</p>";
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_managers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_code VARCHAR(50) UNIQUE NOT NULL,
        manager_name VARCHAR(150) NOT NULL,
        location VARCHAR(200) NOT NULL,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✓ branch_managers table created</p>";
    
    $pdo->exec("INSERT INTO branch_managers (branch_code, manager_name, location, username, password) 
                VALUES ('COL-01', 'Sample Manager', 'Colombo Showroom', 'branch1', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
                ON DUPLICATE KEY UPDATE password = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'");
    echo "<p>✓ Sample branch created (branch1 / admin123)</p>";
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        address TEXT,
        contact_no VARCHAR(30),
        remark_id VARCHAR(100),
        koko_online_id VARCHAR(100),
        payment_mode VARCHAR(50),
        shipping_charges DECIMAL(12,2) DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL,
        branch_id INT,
        packing_status ENUM('Yes','No') DEFAULT 'No',
        dispatch_status ENUM('Yes','No') DEFAULT 'No',
        invoice_no VARCHAR(100),
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "<p>✓ shop_orders table created</p>";
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_name VARCHAR(200),
        color VARCHAR(50),
        sku VARCHAR(100),
        size VARCHAR(20),
        discount_percent DECIMAL(5,2) DEFAULT 0,
        price DECIMAL(12,2),
        after_discount_price DECIMAL(12,2),
        FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
    )");
    echo "<p>✓ order_products table created</p>";
    
    echo "<hr>";
    echo "<h2 style='color:green'>✅ Setup Complete!</h2>";
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