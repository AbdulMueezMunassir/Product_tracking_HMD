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
$reset_completed = false;

// Handle reset action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = isset($_POST['confirm']) ? trim($_POST['confirm']) : '';
    
    if ($confirm === 'RESET ALL DATA') {
        try {
            $pdo->beginTransaction();
            
            // Delete in correct order (due to foreign keys)
            
            // 1. Delete product_requests (requests between branches)
            $pdo->exec("DELETE FROM product_requests");
            
            // 2. Delete product_transfers
            $pdo->exec("DELETE FROM product_transfers");
            
            // 3. Delete showroom_messages
            $pdo->exec("DELETE FROM showroom_messages");
            
            // 4. Delete system_notifications (order notifications)
            $pdo->exec("DELETE FROM system_notifications WHERE notification_type IN ('order_update', 'request', 'message', 'transfer')");
            
            // 5. Delete order_products
            $pdo->exec("DELETE FROM order_products");
            
            // 6. Delete shop_orders
            $pdo->exec("DELETE FROM shop_orders");
            
            // Reset auto-increment counters
            $pdo->exec("ALTER TABLE product_requests AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE product_transfers AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE showroom_messages AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE system_notifications AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE order_products AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE shop_orders AUTO_INCREMENT = 1");
            
            $pdo->commit();
            
            // Reset notification timestamps in session
            $_SESSION['admin_last_notification_check'] = time();
            if (isset($_SESSION['branch_last_notification_check'])) {
                $_SESSION['branch_last_notification_check'] = time();
            }
            
            $reset_completed = true;
            $message = '<div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 
                        <strong>All data has been reset successfully!</strong><br>
                        • All orders and products deleted<br>
                        • All messages and notifications cleared<br>
                        • All product requests and transfers cleared<br>
                        • Admin and branch manager accounts remain intact
                        </div>';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = '<div class="alert alert-danger">
                      <i class="fas fa-exclamation-triangle"></i> Error: ' . $e->getMessage() . '
                     </div>';
        }
    } else {
        $error = '<div class="alert alert-danger">
                  <i class="fas fa-exclamation-triangle"></i> Please type <strong>"RESET ALL DATA"</strong> exactly to confirm.
                 </div>';
    }
}

// Get current counts
$orders_count = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
$products_count = $pdo->query("SELECT COUNT(*) FROM order_products")->fetchColumn();
$requests_count = $pdo->query("SELECT COUNT(*) FROM product_requests")->fetchColumn();
$transfers_count = $pdo->query("SELECT COUNT(*) FROM product_transfers")->fetchColumn();
$messages_count = $pdo->query("SELECT COUNT(*) FROM showroom_messages")->fetchColumn();
$notifications_count = $pdo->query("SELECT COUNT(*) FROM system_notifications WHERE user_id = 1 OR branch_id IS NOT NULL")->fetchColumn();
$branches_count = $pdo->query("SELECT COUNT(*) FROM branch_managers")->fetchColumn();

// Get branches list
$branches = $pdo->query("SELECT id, branch_code, manager_name, location FROM branch_managers")->fetchAll();
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
            max-width: 750px; 
            margin: 0 auto; 
            box-shadow: 0 20px 35px rgba(0,0,0,0.2);
        }
        .stats-box { 
            background: #f8fafc; 
            border-radius: 15px; 
            padding: 20px; 
            margin: 20px 0; 
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 10px;
        }
        .stats-grid .stat-item {
            text-align: center;
            padding: 10px;
            background: white;
            border-radius: 10px;
        }
        .stats-grid .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e2a3e;
        }
        .stats-grid .stat-label {
            font-size: 0.65rem;
            color: #64748b;
            text-transform: uppercase;
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
        .btn-success-custom {
            background: #22c55e;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-success-custom:hover {
            background: #16a34a;
            color: white;
            transform: translateY(-2px);
        }
        .brand {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e2a3e;
        }
        .branch-list {
            max-height: 150px;
            overflow-y: auto;
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px;
            margin-top: 10px;
        }
        .branch-list .badge {
            margin: 2px;
            padding: 5px 10px;
        }
        .reset-success {
            background: #dcfce7;
            border: 2px solid #22c55e;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
        }
        .reset-success i {
            font-size: 4rem;
            color: #22c55e;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="reset-card">
        <div class="text-center mb-4">
            <i class="fas fa-trash-alt fa-3x" style="color: #dc2626;"></i>
            <h2 class="brand mt-2">Reset System Data</h2>
            <p class="text-muted">Clear all orders, products, and communications</p>
        </div>
        
        <?php if ($reset_completed): ?>
            <!-- Success Message -->
            <div class="reset-success">
                <i class="fas fa-check-circle"></i>
                <h3 class="mt-3">Reset Complete!</h3>
                <p class="text-muted">All data has been cleared successfully.</p>
                <p><strong>Admin account:</strong> admin / admin123</p>
                <p><strong>Branch managers:</strong> <?= $branches_count ?> account(s) remain</p>
                <div class="mt-3">
                    <a href="admin_dashboard.php" class="btn-success-custom">
                        <i class="fas fa-arrow-left me-2"></i> Go to Dashboard
                    </a>
                </div>
            </div>
        <?php else: ?>
        
        <?= $message ?>
        <?= $error ?>
        
        <div class="stats-box">
            <h5 class="mb-3"><i class="fas fa-chart-line"></i> Current Data Summary:</h5>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= $orders_count ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $products_count ?></div>
                    <div class="stat-label">Products</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $requests_count ?></div>
                    <div class="stat-label">Requests</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $transfers_count ?></div>
                    <div class="stat-label">Transfers</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $messages_count ?></div>
                    <div class="stat-label">Messages</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $notifications_count ?></div>
                    <div class="stat-label">Notifications</div>
                </div>
            </div>
            
            <div class="mt-3">
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted">Branches:</small>
                        <div class="fw-bold text-success"><?= $branches_count ?> account(s)</div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Admin:</small>
                        <div class="fw-bold text-primary">1 account</div>
                    </div>
                </div>
                <?php if(count($branches) > 0): ?>
                <div class="branch-list">
                    <?php foreach($branches as $b): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($b['branch_code']) ?> - <?= htmlspecialchars($b['manager_name']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning!</strong> This action cannot be undone.<br>
            The following data will be permanently deleted:
            <ul class="mt-2 mb-0">
                <li>All shop orders and products</li>
                <li>All product requests and transfers</li>
                <li>All showroom messages</li>
                <li>All order notifications</li>
            </ul>
            <small class="text-muted mt-2 d-block">
                <i class="fas fa-check-circle text-success"></i> 
                <strong>Kept:</strong> Admin account, Branch manager accounts, Payment modes, Showroom settings
            </small>
        </div>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">
                    Type <code class="bg-light p-1">RESET ALL DATA</code> to confirm:
                </label>
                <input type="text" name="confirm" class="form-control form-control-lg" placeholder="RESET ALL DATA" required>
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
                <i class="fas fa-info-circle"></i> 
                <strong>What stays:</strong><br>
                • Admin account (<strong>admin</strong>)<br>
                • All branch manager accounts<br>
                • Payment modes<br>
                • Showroom settings<br><br>
                <strong>What gets deleted:</strong><br>
                • Orders, products, requests, transfers, messages, notifications
            </small>
        </div>
        
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>