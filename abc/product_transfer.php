<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['role'];
$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 0;
$message = '';
$error = '';

$showrooms = $pdo->query("SELECT id, branch_code, location, email FROM branch_managers ORDER BY location")->fetchAll();

// Get orders for dropdown
$orders = $pdo->query("SELECT id, order_no, customer_name FROM shop_orders ORDER BY id DESC")->fetchAll();

// Handle product transfer request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_transfer'])) {
    $from_showroom = $_POST['from_showroom'];
    $to_showroom = $_POST['to_showroom'];
    $order_id = $_POST['order_id'];
    $item_description = $_POST['item_description'];
    $sku = $_POST['sku'];
    $nav_document_number = $_POST['nav_document_number'];
    $web_order_number = $_POST['web_order_number'];
    $requestor = $_SESSION['username'];
    
    if ($from_showroom == $to_showroom) {
        $error = 'From showroom and To showroom cannot be the same!';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO product_transfers (requestor, from_showroom, to_showroom, order_id, item_description, sku, nav_document_number, web_order_number, status) VALUES (?,?,?,?,?,?,?,?, 'pending')");
            $stmt->execute([$requestor, $from_showroom, $to_showroom, $order_id, $item_description, $sku, $nav_document_number, $web_order_number]);
            $message = 'Product transfer request submitted successfully!';
            
            // Send email to target branch
            $target_branch = $pdo->prepare("SELECT email, location FROM branch_managers WHERE id = ?");
            $target_branch->execute([$to_showroom]);
            $target = $target_branch->fetch();
            
            if ($target && $target['email']) {
                $order_info = $pdo->prepare("SELECT order_no FROM shop_orders WHERE id = ?");
                $order_info->execute([$order_id]);
                $order_data = $order_info->fetch();
                
                $subject = "Product Transfer Request - Order #" . ($order_data['order_no'] ?? 'N/A');
                $body = "<h2>Product Transfer Request</h2>
                         <p>A product transfer request has been submitted.</p>
                         <p><strong>From:</strong> " . $_SESSION['branch_location'] . "</p>
                         <p><strong>To:</strong> {$target['location']}</p>
                         <p><strong>Order #:</strong> " . ($order_data['order_no'] ?? 'N/A') . "</p>
                         <p><strong>Item Description:</strong> $item_description</p>
                         <p><strong>SKU:</strong> $sku</p>
                         <p>Please login to the system to respond to this request.</p>";
                sendEmailNotification($target['email'], $subject, $body);
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Get transfers
if ($role == 'admin') {
    $transfers = $pdo->query("SELECT pt.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code, o.order_no FROM product_transfers pt LEFT JOIN branch_managers f ON pt.from_showroom = f.id LEFT JOIN branch_managers t ON pt.to_showroom = t.id LEFT JOIN shop_orders o ON pt.order_id = o.id ORDER BY pt.created_at DESC")->fetchAll();
} else {
    $transfers = $pdo->prepare("SELECT pt.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code, o.order_no FROM product_transfers pt LEFT JOIN branch_managers f ON pt.from_showroom = f.id LEFT JOIN branch_managers t ON pt.to_showroom = t.id LEFT JOIN shop_orders o ON pt.order_id = o.id WHERE pt.from_showroom = ? OR pt.to_showroom = ? ORDER BY pt.created_at DESC");
    $transfers->execute([$branch_id, $branch_id]);
    $transfers = $transfers->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Transfer | Hameedia</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Same sidebar styles as before */
        .sidebar { width: 280px; position: fixed; left: -280px; height: 100vh; background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px; transition: left 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar.open { left: 0; }
        .main-content { margin-left: 0; padding: 25px 35px; transition: margin-left 0.3s; }
        @media (min-width: 992px) { .sidebar { left: 0; width: 280px; } .menu-toggle { display: none; } .main-content { margin-left: 280px; } }
        @media (max-width: 991px) { .main-content { padding: 15px; } .menu-toggle { display: block; position: fixed; top: 15px; left: 15px; z-index: 1001; background: #1e2a3e; color: white; border: none; padding: 10px 15px; border-radius: 10px; cursor: pointer; } }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }
        .nav-link { color: #cfdde6; padding: 12px 20px; margin: 5px 0; border-radius: 12px; text-decoration: none; display: block; transition: all 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .nav-link i { width: 28px; }
        .transfer-card, .message-card { background: white; border-radius: 20px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .transfer-header, .message-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .form-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .form-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small>Menu</small></div>
    <hr>
    <?php if($role == 'admin'): ?>
        <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
        <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
        <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
        <a href="product_transfer.php" class="nav-link active"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
        <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
    <?php else: ?>
        <a href="branch_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="product_transfer.php" class="nav-link active"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <?php endif; ?>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2><i class="fas fa-exchange-alt"></i> Product Transfer</h2><p class="text-muted">Request product transfers between showrooms</p></div>
        <div class="text-muted"><i class="fas fa-calendar"></i> <?= date('l, F j, Y') ?></div>
    </div>
    
    <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    
    <div class="form-card">
        <div class="form-header"><h5 class="mb-0"><i class="fas fa-paper-plane"></i> Request Product Transfer</h5></div>
        <div class="p-4">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label fw-bold">From Showroom *</label><select name="from_showroom" class="form-select" required><option value="">Select From</option><?php foreach($showrooms as $s): ?><option value="<?= $s['id'] ?>" <?= ($role != 'admin' && $branch_id == $s['id']) ? 'selected' : '' ?>><?= $s['branch_code'] ?> - <?= $s['location'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label fw-bold">To Showroom *</label><select name="to_showroom" class="form-select" required><option value="">Select To</option><?php foreach($showrooms as $s): ?><option value="<?= $s['id'] ?>"><?= $s['branch_code'] ?> - <?= $s['location'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Order ID *</label><select name="order_id" class="form-select" required><option value="">Select Order</option><?php foreach($orders as $o): ?><option value="<?= $o['id'] ?>">#<?= $o['order_no'] ?> - <?= $o['customer_name'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label fw-bold">Item Description</label><textarea name="item_description" class="form-control" rows="3" placeholder="Describe the product to transfer"></textarea></div>
                    <div class="col-md-4"><label class="form-label fw-bold">SKU</label><input type="text" name="sku" class="form-control" placeholder="Product SKU"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">NAV Document Number</label><input type="text" name="nav_document_number" class="form-control" placeholder="NAV Document #"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Web Order Number</label><input type="text" name="web_order_number" class="form-control" placeholder="Web Order #"></div>
                    <div class="col-12"><button type="submit" name="request_transfer" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Transfer Request</button></div>
                </div>
            </form>
        </div>
    </div>
    
    <h4 class="mt-4"><i class="fas fa-list"></i> Transfer Requests</h4>
    <?php foreach($transfers as $transfer): ?>
    <div class="transfer-card">
        <div class="transfer-header"><div class="d-flex justify-content-between"><div><strong>Request #<?= $transfer['id'] ?></strong> - Order #<?= $transfer['order_no'] ?? 'N/A' ?> - <?= $transfer['from_code'] ?> → <?= $transfer['to_code'] ?></div><span class="status-badge status-<?= $transfer['status'] ?>"><?= ucfirst($transfer['status']) ?></span></div></div>
        <div class="p-3"><div class="row"><div class="col-md-6"><small>Requestor:</small><div><strong><?= htmlspecialchars($transfer['requestor']) ?></strong></div></div><div class="col-md-6"><small>Date:</small><div><?= date('d M Y, h:i A', strtotime($transfer['created_at'])) ?></div></div><div class="col-12 mt-2"><small>Item Description:</small><div><?= nl2br(htmlspecialchars($transfer['item_description'])) ?></div></div><div class="col-md-4 mt-2"><small>SKU:</small><div><?= htmlspecialchars($transfer['sku'] ?: '-') ?></div></div><div class="col-md-4 mt-2"><small>NAV Document:</small><div><?= htmlspecialchars($transfer['nav_document_number'] ?: '-') ?></div></div><div class="col-md-4 mt-2"><small>Web Order #:</small><div><?= htmlspecialchars($transfer['web_order_number'] ?: '-') ?></div></div></div></div>
    </div>
    <?php endforeach; ?>
</div>

<script>
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { if(window.innerWidth<577) toggleSidebar(); }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
</script>
</body>
</html>