<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = isset($_POST['confirm']) ? $_POST['confirm'] : '';
    
    if ($confirm === 'DELETE ALL DATA') {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM order_products");
            $pdo->exec("ALTER TABLE order_products AUTO_INCREMENT = 1");
            $pdo->exec("DELETE FROM shop_orders");
            $pdo->exec("ALTER TABLE shop_orders AUTO_INCREMENT = 1");
            $pdo->commit();
            $message = '<div class="alert alert-success">All orders and products deleted!</div>';
            $_SESSION['admin_last_notification_check'] = time();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    } else {
        $error = '<div class="alert alert-danger">Type "DELETE ALL DATA" to confirm.</div>';
    }
}

$orders_count = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
$products_count = $pdo->query("SELECT COUNT(*) FROM order_products")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Data | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1e2a3e, #15232e); min-height: 100vh; display: flex; align-items: center; }
        .reset-card { background: white; border-radius: 30px; padding: 40px; max-width: 600px; margin: 0 auto; }
        .warning-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; border-radius: 10px; margin: 20px 0; }
        .btn-danger-custom { background: #dc2626; color: white; border: none; padding: 12px 30px; border-radius: 50px; }
        .btn-danger-custom:hover { background: #b91c1c; }
    </style>
</head>
<body>
<div class="container"><div class="reset-card">
    <div class="text-center"><i class="fas fa-trash-alt fa-3x text-danger"></i><h2 class="mt-2">Reset All Data</h2><p class="text-muted">Delete all orders and products</p></div>
    <?= $message ?><?= $error ?>
    <div class="alert alert-secondary"><strong>Current Data:</strong> <?= $orders_count ?> Orders | <?= $products_count ?> Products</div>
    <div class="warning-box"><i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong> This cannot be undone.</div>
    <form method="POST"><div class="mb-3"><label>Type <code>DELETE ALL DATA</code> to confirm:</label><input type="text" name="confirm" class="form-control" placeholder="DELETE ALL DATA" required></div>
    <div class="d-flex gap-3"><a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a><button type="submit" class="btn-danger-custom"><i class="fas fa-trash-alt me-2"></i> Delete All Data</button></div></form>
</div></div>
</body>
</html>