<?php
session_start();
require_once 'config.php';

// Only allow admin to access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Handle reset action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = isset($_POST['confirm']) ? trim($_POST['confirm']) : '';
    
    if ($confirm === 'RESET ALL DATA') {
        try {
            $pdo->beginTransaction();
            
            // Delete in correct order (due to foreign keys)
            // First delete product_requests
            $pdo->exec("DELETE FROM product_requests");
            
            // Delete order_products
            $pdo->exec("DELETE FROM order_products");
            
            // Delete shop_orders
            $pdo->exec("DELETE FROM shop_orders");
            
            // Delete notifications related to orders
            $pdo->exec("DELETE FROM system_notifications WHERE notification_type IN ('order_update', 'request')");
            
            // Reset auto-increment counters
            $pdo->exec("ALTER TABLE product_requests AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE order_products AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE shop_orders AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE system_notifications AUTO_INCREMENT = 1");
            
            $pdo->commit();
            
            // Reset notification timestamps in session
            $_SESSION['admin_last_notification_check'] = time();
            if (isset($_SESSION['branch_last_notification_check'])) {
                $_SESSION['branch_last_notification_check'] = time();
            }
            
            // Clear any stored notification counts
            unset($_SESSION['notification_count']);
            
            $message = '<div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> All orders, products, and notifications have been deleted successfully!
                        </div>';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = '<div class="alert alert-danger">
                      <i class="fas fa-exclamation-triangle"></i> Error: ' . $e->getMessage() . '
                     </div>';
        }
    } else {
        $error = '<div class="alert alert-danger">
                  <i class="fas fa-exclamation-triangle"></i> Please type "RESET ALL DATA" to confirm.
                 </div>';
    }
}

// Get current counts
$orders_count = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
$products_count = $pdo->query("SELECT COUNT(*) FROM order_products")->fetchColumn();
$requests_count = $pdo->query("SELECT COUNT(*) FROM product_requests")->fetchColumn();
$notifications_count = $pdo->query("SELECT COUNT(*) FROM system_notifications WHERE user_id = 1 OR branch_id IS NOT NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Data | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364); 
            min-height: 100vh; 
            display: flex; 
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .reset-card { 
            background: white; 
            border-radius: 30px; 
            padding: 40px; 
            max-width: 600px; 
            margin: 0 auto; 
            box-shadow: 0 20px 35px rgba(0,0,0,0.2);
        }
        .stats-box { 
            background: #f8fafc; 
            border-radius: 15px; 
            padding: 20px; 
            margin: 20px 0; 
        }
        .warning-box { 
            background: #fee2e2; 
            border-left: 4px solid #dc2626; 
            padding: 15px; 
            border-radius: 10px; 
            margin: 20px 0; 
        }
        .btn-danger-custom { 
            background: #dc2626; 
            color: white; 
            border: none; 
            padding: 12px 30px; 
            border-radius: 50px; 
            font-weight: 600; 
            transition: all 0.3s;
        }
        .btn-danger-custom:hover { 
            background: #b91c1c; 
            transform: translateY(-2px);
        }
        .btn-secondary-custom { 
            background: #64748b; 
            color: white; 
            border: none; 
            padding: 12px 30px; 
            border-radius: 50px; 
            font-weight: 600; 
            text-decoration: none; 
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-secondary-custom:hover { 
            background: #475569; 
            color: white;
            transform: translateY(-2px);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1e2a3e;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.85rem;
        }
        .brand {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e2a3e;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="reset-card">
        <div class="text-center mb-4">
            <i class="fas fa-trash-alt fa-3x" style="color: #dc2626;"></i>
            <h2 class="brand mt-2">Reset All Data</h2>
            <p class="text-muted">Clear all orders, products, and notifications from the system</p>
        </div>
        
        <?= $message ?>
        <?= $error ?>
        
        <div class="stats-box">
            <h5 class="mb-3"><i class="fas fa-chart-line"></i> Current Data Summary:</h5>
            <div class="row text-center">
                <div class="col-3">
                    <div class="stat-number"><?= $orders_count ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="col-3">
                    <div class="stat-number"><?= $products_count ?></div>
                    <div class="stat-label">Products</div>
                </div>
                <div class="col-3">
                    <div class="stat-number"><?= $requests_count ?></div>
                    <div class="stat-label">Requests</div>
                </div>
                <div class="col-3">
                    <div class="stat-number"><?= $notifications_count ?></div>
                    <div class="stat-label">Notifications</div>
                </div>
            </div>
        </div>
        
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning!</strong> This action cannot be undone.<br>
            All orders, products, product requests, and notifications will be permanently deleted.<br>
            <small class="text-muted">Branch manager accounts and admin accounts will remain.</small>
        </div>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">
                    Type <code class="bg-light p-1">RESET ALL DATA</code> to confirm:
                </label>
                <input type="text" name="confirm" class="form-control" placeholder="RESET ALL DATA" required>
            </div>
            
            <div class="d-flex gap-3">
                <a href="admin_dashboard.php" class="btn-secondary-custom">
                    <i class="fas fa-arrow-left me-2"></i> Cancel
                </a>
                <button type="submit" class="btn-danger-custom">
                    <i class="fas fa-trash-alt me-2"></i> Reset All Data
                </button>
            </div>
        </form>
        
        <hr class="my-4">
        
        <div class="text-center">
            <small class="text-muted">
                <i class="fas fa-info-circle"></i> This will only delete:<br>
                • Shop orders<br>
                • Order products<br>
                • Product requests<br>
                • Order notifications<br><br>
                <strong>Branch accounts and Admin account will NOT be deleted.</strong>
            </small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>