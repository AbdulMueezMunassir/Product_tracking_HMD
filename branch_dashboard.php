<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'branch') {
    header('Location: index.php');
    exit;
}

$branch_id = $_SESSION['branch_id'];
$view_order_id = isset($_GET['view_order']) ? $_GET['view_order'] : null;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$message = '';

if (!isset($_SESSION['branch_last_notification_check'])) {
    $_SESSION['branch_last_notification_check'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $order_id = $_POST['order_id'];
    $product_availability = $_POST['product_availability'];
    
    if ($product_availability == 'not_available') {
        $packing = 'No';
        $dispatch = 'No';
    } else {
        $packing = $_POST['packing_status'];
        $dispatch = $_POST['dispatch_status'];
    }
    
    $invoice = $_POST['invoice_no'];
    $branch_comment = $_POST['branch_comment'];
    $barcode_number = $_POST['barcode_number'];
    $unavailability_reason = isset($_POST['unavailability_reason']) ? $_POST['unavailability_reason'] : null;
    $partial_comment = isset($_POST['partial_comment']) ? $_POST['partial_comment'] : null;
    
    $product_ids = $_POST['product_id'] ?? [];
    $product_availabilities = $_POST['product_avail'] ?? [];
    $product_reasons = $_POST['product_reason'] ?? [];
    
    $updateProduct = $pdo->prepare("UPDATE order_products SET product_availability = ?, product_availability_reason = ? WHERE id = ?");
    for ($i = 0; $i < count($product_ids); $i++) {
        $updateProduct->execute([$product_availabilities[$i], $product_reasons[$i], $product_ids[$i]]);
    }
    
    $stmt = $pdo->prepare("UPDATE shop_orders SET 
        packing_status = ?, 
        dispatch_status = ?, 
        invoice_no = ?, 
        branch_comment = ?,
        barcode_number = ?,
        product_availability = ?,
        unavailability_reason = ?,
        partial_comment = ?,
        status = 'Confirmed', 
        updated_at = NOW() 
        WHERE id = ? AND branch_id = ?");
    $stmt->execute([$packing, $dispatch, $invoice, $branch_comment, $barcode_number, $product_availability, $unavailability_reason, $partial_comment, $order_id, $branch_id]);
    $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> Order status updated successfully! Admin has been notified.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
    
    $_SESSION['branch_last_notification_check'] = time();
    $view_order_id = $order_id;
}

$selected_order = null;
$selected_products = [];
if ($view_order_id) {
    $stmt = $pdo->prepare("SELECT * FROM shop_orders WHERE id = ? AND branch_id = ?");
    $stmt->execute([$view_order_id, $branch_id]);
    $selected_order = $stmt->fetch();
    
    if ($selected_order) {
        $stmt = $pdo->prepare("SELECT * FROM order_products WHERE order_id = ?");
        $stmt->execute([$view_order_id]);
        $selected_products = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Branch Dashboard | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .top-nav {
            background: linear-gradient(135deg, #1e2a3e, #15232e);
            color: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .brand { font-size: 1.5rem; font-weight: 700; }
        .brand i { margin-right: 10px; }
        .user-info { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .user-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 30px; font-size: 0.9rem; }
        .logout-btn { background: #dc2626; color: white; border: none; padding: 8px 20px; border-radius: 30px; text-decoration: none; transition: all 0.3s; }
        .logout-btn:hover { background: #b91c1c; transform: translateY(-2px); }
        
        .notification-container { position: relative; cursor: pointer; }
        .notification-bell { background: rgba(255,255,255,0.15); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; }
        .notification-bell:hover { background: rgba(255,255,255,0.25); transform: scale(1.05); }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 10px; display: flex; align-items: center; justify-content: center; animation: pulse 1.5s infinite; }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
        
        .notification-dropdown { position: absolute; top: 50px; right: 0; width: 380px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 1000; display: none; max-height: 450px; overflow-y: auto; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 700; background: #f8fafc; border-radius: 20px 20px 0 0; color: #1e293b; }
        .notification-item { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; cursor: pointer; text-decoration: none; display: block; color: #1e293b; }
        .notification-item:hover { background: #f1f5f9; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; }
        .notification-text { font-size: 0.75rem; color: #64748b; }
        .notification-time { font-size: 0.7rem; color: #94a3b8; margin-top: 8px; }
        .mark-all-read { padding: 12px 20px; text-align: center; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 20px 20px; }
        .mark-all-read button { background: none; border: none; color: #0284c7; font-size: 0.85rem; cursor: pointer; width: 100%; font-weight: 500; }
        
        .main-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { font-size: 2rem; margin-bottom: 10px; }
        .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 0.85rem; }
        
        .search-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .btn-search { background: #0284c7; color: white; border: none; padding: 10px 25px; border-radius: 30px; }
        .btn-clear { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-block; }
        
        .orders-table-container { background: white; border-radius: 20px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .orders-table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .orders-table th { background: #f8fafc; padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
        .orders-table td { padding: 15px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .orders-table tr:hover { background: #f1f5f9; cursor: pointer; }
        .order-status { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-not-available { background: #fee2e2; color: #991b1b; }
        
        .view-btn { background: #0284c7; color: white; border: none; padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; cursor: pointer; }
        .view-btn:hover { background: #0369a1; }
        .back-btn { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        
        .order-details-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-header-custom { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px 25px; }
        .info-section { padding: 20px 25px; border-bottom: 1px solid #e2e8f0; }
        .section-title { font-size: 1rem; font-weight: 700; color: #1e293b; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #f8fafc; padding: 12px 15px; border-radius: 12px; }
        .info-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; }
        .info-value { font-size: 1rem; font-weight: 600; color: #1e293b; margin-top: 5px; }
        
        .product-card { background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
        .product-image { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; border: 1px solid #e2e8f0; }
        .product-placeholder { width: 60px; height: 60px; background: #e2e8f0; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #94a3b8; }
        
        .status-form { background: #f8fafc; padding: 20px 25px; border-radius: 0 0 20px 20px; }
        .radio-group { display: flex; gap: 20px; margin-top: 10px; flex-wrap: wrap; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .btn-confirm { background: #0284c7; color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: 600; transition: all 0.3s; }
        .btn-confirm:hover { background: #0369a1; transform: translateY(-2px); }
        .info-note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; }
        
        .btn-scanner { background: #6b7280; color: white; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 12px; margin-top: 5px; }
        .btn-scanner:hover { background: #4b5563; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .orders-table th, .orders-table td { padding: 10px 8px; font-size: 0.75rem; }
            .stat-number { font-size: 1.5rem; }
            .info-grid { grid-template-columns: 1fr; }
            .radio-group { flex-direction: column; gap: 8px; }
            .btn-confirm { width: 100%; }
            .product-image { width: 50px; height: 50px; }
        }
        
        #scanner-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 2000; display: none; flex-direction: column; align-items: center; justify-content: center; }
        #scanner-viewport { width: 100%; max-width: 500px; background: #000; position: relative; }
        #scanner-close { position: absolute; top: 20px; right: 20px; background: white; color: black; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; z-index: 2001; }
        #scanner-result { background: white; padding: 10px; margin-top: 20px; border-radius: 10px; text-align: center; }
    </style>
</head>
<body>

<div class="top-nav">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div class="brand"><i class="fas fa-store"></i> Hameedia <small class="ms-2 opacity-75">Branch Manager Panel</small></div>
        <div class="user-info">
            <div class="notification-container">
                <div class="notification-bell" onclick="toggleNotification()"><i class="fas fa-bell"></i><span class="notification-badge hidden" id="branchNotificationBadge">0</span></div>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header"><i class="fas fa-bell me-2"></i> New Orders <span class="badge bg-primary ms-2" id="notificationCountText">0 new</span></div>
                    <div id="notificationList"><div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted mb-2"></i><p class="text-muted mb-0">Loading...</p></div></div>
                    <div class="mark-all-read"><button onclick="markAllNotificationsRead()"><i class="fas fa-check-double me-1"></i> Mark all as read</button></div>
                </div>
            </div>
            <span class="user-badge"><i class="fas fa-map-marker-alt me-1"></i> <?= htmlspecialchars($_SESSION['branch_location']) ?></span>
            <span class="user-badge"><i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
        </div>
    </div>
</div>

<div id="scanner-container">
    <button id="scanner-close" onclick="closeScanner()">✕ Close Scanner</button>
    <div id="scanner-viewport"></div>
    <div id="scanner-result"></div>
</div>

<div class="main-container">
    <?= $message ?>
    
    <?php if ($view_order_id && $selected_order): ?>
        <a href="branch_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <div class="order-details-card">
            <div class="card-header-custom">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div><h4 class="mb-1"><i class="fas fa-shopping-cart me-2"></i> Order #<?= htmlspecialchars($selected_order['order_no']) ?></h4>
                    <p class="mb-0 opacity-75"><i class="far fa-clock me-1"></i> Placed on <?= date('l, F j, Y \a\t h:i A', strtotime($selected_order['created_at'])) ?></p></div>
                    <span class="badge bg-secondary"><?= $selected_order['status'] ?></span>
                </div>
            </div>
            
            <div class="info-section">
                <h6 class="section-title"><i class="fas fa-user me-2"></i> Customer Information</h6>
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Customer Name</div><div class="info-value"><?= htmlspecialchars($selected_order['customer_name']) ?></div></div>
                    <div class="info-item"><div class="info-label">Contact Number</div><div class="info-value"><?= htmlspecialchars($selected_order['contact_no'] ?: '-') ?></div></div>
                    <div class="info-item"><div class="info-label">Address</div><div class="info-value"><?= nl2br(htmlspecialchars($selected_order['address'] ?: '-')) ?></div></div>
                    <div class="info-item"><div class="info-label">Payment Mode</div><div class="info-value"><?= htmlspecialchars($selected_order['payment_mode'] ?: '-') ?></div></div>
                </div>
            </div>
            
            <div class="info-section">
                <h6 class="section-title"><i class="fas fa-receipt me-2"></i> Order Summary</h6>
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Order Number</div><div class="info-value">#<?= htmlspecialchars($selected_order['order_no']) ?></div></div>
                    <div class="info-item"><div class="info-label">KOKO Online ID</div><div class="info-value"><?= htmlspecialchars($selected_order['koko_online_id'] ?: '-') ?></div></div>
                    <div class="info-item"><div class="info-label">Remark ID</div><div class="info-value"><?= htmlspecialchars($selected_order['remark_id'] ?: '-') ?></div></div>
                    <div class="info-item"><div class="info-label">Shipping Charges</div><div class="info-value">Rs. <?= number_format($selected_order['shipping_charges'], 2) ?></div></div>
                    <div class="info-item"><div class="info-label">Total Amount</div><div class="info-value fs-5 text-success fw-bold">Rs. <?= number_format($selected_order['total_amount'], 2) ?></div></div>
                </div>
            </div>
            
            <div class="info-section">
                <h6 class="section-title"><i class="fas fa-boxes me-2"></i> Products Ordered</h6>
                <?php foreach ($selected_products as $product): ?>
                    <div class="product-card">
                        <div class="d-flex gap-3 flex-wrap">
                            <?php 
                            $image_url = !empty($product['image_url']) ? $product['image_url'] : null;
                            if($image_url): ?>
                                <img src="<?= htmlspecialchars($image_url) ?>" class="product-image" alt="<?= htmlspecialchars($product['product_name']) ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="product-placeholder" style="display: none;"><i class="fas fa-image fa-2x"></i></div>
                            <?php else: ?>
                                <div class="product-placeholder"><i class="fas fa-image fa-2x"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start flex-wrap">
                                    <div>
                                        <h6 class="mb-1"><?= htmlspecialchars($product['product_name']) ?></h6>
                                        <small class="text-muted"><i class="fas fa-palette"></i> <?= $product['color'] ?> | <i class="fas fa-ruler"></i> Size <?= $product['size'] ?> | <i class="fas fa-barcode"></i> <?= $product['sku'] ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-success">Rs. <?= number_format($product['after_discount_price'], 2) ?></div>
                                        <?php if ($product['discount_percent'] > 0): ?><span class="badge bg-warning text-dark"><i class="fas fa-tag"></i> -<?= $product['discount_percent'] ?>%</span><?php endif; ?>
                                    </div>
                                </div>
                                <!-- Per-Product Availability Radio Buttons -->
                                <div class="mt-2 pt-2 border-top">
                                    <label class="small fw-bold">Product Availability in Showroom:</label>
                                    <div class="radio-group mt-1">
                                        <label class="radio-label"><input type="radio" name="product_avail_<?= $product['id'] ?>" value="available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] == 'available' || !$product['product_availability']) ? 'checked' : '' ?>> <span class="badge bg-success">✓ Available</span></label>
                                        <label class="radio-label"><input type="radio" name="product_avail_<?= $product['id'] ?>" value="not_available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] == 'not_available') ? 'checked' : '' ?>> <span class="badge bg-danger">✗ Not Available</span></label>
                                    </div>
                                    <div class="product-reason-div" id="product-reason-<?= $product['id'] ?>" style="display: <?= ($product['product_availability'] == 'not_available') ? 'block' : 'none' ?>; margin-top: 8px;">
                                        <input type="text" name="product_reason_<?= $product['id'] ?>" class="form-control form-control-sm" placeholder="Reason for unavailability (e.g., Out of stock)" value="<?= htmlspecialchars($product['product_availability_reason'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="status-form">
                <form method="POST" id="orderForm">
                    <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                    
                    <?php foreach ($selected_products as $product): ?>
                        <input type="hidden" name="product_id[]" value="<?= $product['id'] ?>">
                        <input type="hidden" name="product_avail[]" id="product_avail_hidden_<?= $product['id'] ?>" value="<?= $product['product_availability'] ?? 'available' ?>">
                        <input type="hidden" name="product_reason[]" id="product_reason_hidden_<?= $product['id'] ?>" value="<?= htmlspecialchars($product['product_availability_reason'] ?? '') ?>">
                    <?php endforeach; ?>
                    
                    <div id="notAvailableNote" class="info-note" style="display: none;">
                        <i class="fas fa-info-circle me-2"></i> 
                        <strong>Note:</strong> Since you selected "Not Available in Showroom", Packing and Dispatch statuses will be automatically set to <strong>"No"</strong>.
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="fw-bold mb-2">📦 Packing Status</label>
                            <div class="radio-group" id="packingGroup">
                                <label class="radio-label"><input type="radio" name="packing_status" value="Yes" id="packingYes" <?= ($selected_order['packing_status'] == 'Yes') ? 'checked' : '' ?>> <span class="badge bg-success">Yes</span></label>
                                <label class="radio-label"><input type="radio" name="packing_status" value="No" id="packingNo" <?= ($selected_order['packing_status'] == 'No') ? 'checked' : '' ?>> <span class="badge bg-danger">No</span></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold mb-2">🚚 Dispatch Status</label>
                            <div class="radio-group" id="dispatchGroup">
                                <label class="radio-label"><input type="radio" name="dispatch_status" value="Yes" id="dispatchYes" <?= ($selected_order['dispatch_status'] == 'Yes') ? 'checked' : '' ?>> <span class="badge bg-success">Yes</span></label>
                                <label class="radio-label"><input type="radio" name="dispatch_status" value="No" id="dispatchNo" <?= ($selected_order['dispatch_status'] == 'No') ? 'checked' : '' ?>> <span class="badge bg-danger">No</span></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold mb-2">📄 Invoice Number</label>
                            <input type="text" name="invoice_no" class="form-control" value="<?= htmlspecialchars($selected_order['invoice_no'] ?? '') ?>" placeholder="Enter invoice number">
                        </div>
                    </div>
                    
                    <div class="row g-4 mt-3">
                        <div class="col-md-6">
                            <label class="fw-bold mb-2"><i class="fas fa-barcode"></i> Barcode Number</label>
                            <div class="input-group">
                                <input type="text" name="barcode_number" id="barcode_input" class="form-control" value="<?= htmlspecialchars($selected_order['barcode_number'] ?? '') ?>" placeholder="Scan or enter barcode number">
                                <button type="button" class="btn btn-secondary" onclick="openScanner()"><i class="fas fa-camera"></i> Scan</button>
                            </div>
                            <small class="text-muted">Click "Scan" to use camera for barcode scanning</small>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold mb-2"><i class="fas fa-sticky-note"></i> Comment / Note</label>
                            <input type="text" name="branch_comment" class="form-control" value="<?= htmlspecialchars($selected_order['branch_comment'] ?? '') ?>" placeholder="Add any comments about this order">
                        </div>
                    </div>
                    
                    <div class="row g-4 mt-3">
                        <div class="col-12">
                            <label class="fw-bold mb-2"><i class="fas fa-boxes"></i> Overall Product Availability Status</label>
                            <div class="radio-group">
                                <label class="radio-label"><input type="radio" name="product_availability" value="available" class="avail-radio" <?= ($selected_order['product_availability'] == 'available' || !$selected_order['product_availability']) ? 'checked' : '' ?>> <span class="badge bg-success">✓ All Available</span></label>
                                <label class="radio-label"><input type="radio" name="product_availability" value="partial" class="avail-radio" <?= ($selected_order['product_availability'] == 'partial') ? 'checked' : '' ?>> <span class="badge bg-warning text-dark">⚠️ Partially Available</span></label>
                                <label class="radio-label"><input type="radio" name="product_availability" value="not_available" class="avail-radio" <?= ($selected_order['product_availability'] == 'not_available') ? 'checked' : '' ?>> <span class="badge bg-danger">❌ None Available</span></label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-4 mt-3" id="partial_comment_div" style="display: <?= ($selected_order['product_availability'] == 'partial') ? 'block' : 'none' ?>;">
                        <div class="col-12">
                            <label class="fw-bold mb-2"><i class="fas fa-comment-dots"></i> Partial Availability Comment</label>
                            <textarea name="partial_comment" class="form-control" rows="2" placeholder="Specify which items are available..."><?= htmlspecialchars($selected_order['partial_comment'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="row g-4 mt-3" id="unavailability_reason_div" style="display: <?= ($selected_order['product_availability'] == 'not_available') ? 'block' : 'none' ?>;">
                        <div class="col-12">
                            <label class="fw-bold mb-2"><i class="fas fa-comment-dots"></i> Unavailability Reason</label>
                            <textarea name="unavailability_reason" class="form-control" rows="2" placeholder="Reason for unavailability..."><?= htmlspecialchars($selected_order['unavailability_reason'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button type="submit" name="update_order" class="btn-confirm">
                            <i class="fas fa-check-circle me-2"></i> Confirm & Share to Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
    <?php else: ?>
        <div class="stats-grid" id="statsContainer">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-shopping-cart"></i></div><div class="stat-number" id="totalOrders">0</div><div class="stat-label">Total Orders</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-number" id="pendingOrders">0</div><div class="stat-label">Pending Orders</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-number" id="completedOrders">0</div><div class="stat-label">Completed Orders</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-number" id="notAvailableOrders">0</div><div class="stat-label">Not Available</div></div>
        </div>
        
        <div class="search-card">
            <form method="GET" class="row g-3" onsubmit="return false;">
                <div class="col-md-9"><label class="form-label fw-bold"><i class="fas fa-search"></i> Search Orders</label><input type="text" name="search" id="searchInput" class="form-control form-control-lg" placeholder="Search by Order Number or Customer Name..." value="<?= htmlspecialchars($search_query) ?>"></div>
                <div class="col-md-3 d-flex gap-2 align-items-end"><button type="button" class="btn-search w-100" onclick="performSearch()"><i class="fas fa-search me-2"></i> Search</button><?php if ($search_query): ?><a href="branch_dashboard.php" class="btn-clear"><i class="fas fa-times me-1"></i> Clear</a><?php endif; ?></div>
            </form>
        </div>
        
        <div class="orders-table-container">
            <table class="orders-table"><thead><tr><th>Order No</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
            <tbody id="ordersTableBody"><tr><td colspan="7" class="text-center py-5">Loading orders...<div class="spinner-border text-primary mt-2"></div></td></tr></tbody></table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
<script>
    let lastUpdateTime = <?= time() ?>;
    let refreshInterval;
    let currentViewOrder = <?= json_encode($view_order_id) ?>;
    let searchQuery = <?= json_encode($search_query) ?>;
    let scannerActive = false;
    
    function toggleNotification() { const d=document.getElementById('notificationDropdown'); d.classList.toggle('show'); if(d.classList.contains('show')) loadNotifications(); }
    function performSearch() { window.location.href = 'branch_dashboard.php?search=' + encodeURIComponent(document.getElementById('searchInput').value); }
    
    function openScanner() {
        document.getElementById('scanner-container').style.display = 'flex';
        startScanner();
    }
    
    function closeScanner() {
        document.getElementById('scanner-container').style.display = 'none';
        if (scannerActive) {
            Quagga.stop();
            scannerActive = false;
        }
    }
    
    function startScanner() {
        if (scannerActive) return;
        
        Quagga.init({
            inputStream: {
                name: "Live",
                type: "LiveStream",
                target: document.querySelector('#scanner-viewport'),
                constraints: {
                    facingMode: "environment"
                }
            },
            decoder: {
                readers: ["code_128_reader", "ean_reader", "ean_8_reader", "code_39_reader", "upc_reader"]
            }
        }, function(err) {
            if (err) {
                console.error(err);
                document.getElementById('scanner-result').innerHTML = '<div class="text-danger">Camera error: ' + err + '</div>';
                return;
            }
            Quagga.start();
            scannerActive = true;
        });
        
        Quagga.onDetected(function(result) {
            var code = result.codeResult.code;
            document.getElementById('scanner-result').innerHTML = '<div class="text-success">Scanned: ' + code + '</div>';
            document.getElementById('barcode_input').value = code;
            setTimeout(closeScanner, 1500);
        });
    }
    
    async function loadLiveData() {
        if (currentViewOrder) return;
        try {
            let url = `get_live_data.php?last_update=${lastUpdateTime}`;
            const response = await fetch(url);
            const data = await response.json();
            if (data.success && data.stats) {
                document.getElementById('totalOrders').textContent = data.stats.total_orders;
                document.getElementById('pendingOrders').textContent = data.stats.pending_orders;
                document.getElementById('completedOrders').textContent = data.stats.completed_orders;
                document.getElementById('notAvailableOrders').textContent = data.stats.not_available_orders;
                let tableHtml = '';
                if (data.orders_list && data.orders_list.length > 0) {
                    data.orders_list.forEach(order => {
                        let statusClass = '', statusText = '';
                        if (order.product_availability === 'not_available') { statusClass = 'status-not-available'; statusText = 'Not Available'; }
                        else if (order.product_availability === 'partial') { statusClass = 'status-pending'; statusText = 'Partial'; }
                        else if (order.packing_status === 'Yes' && order.dispatch_status === 'Yes') { statusClass = 'status-completed'; statusText = 'Completed'; }
                        else { statusClass = 'status-pending'; statusText = 'Pending'; }
                        tableHtml += `<tr onclick="window.location.href='?view_order=${order.id}'"><td><strong>#${escapeHtml(order.order_no)}</strong><\/td><td>${escapeHtml(order.customer_name)}<\/td><td>${new Date(order.created_at).toLocaleDateString()}<\/td><td>${order.items_count||1}<\/td><td>Rs. ${parseFloat(order.total_amount).toFixed(2)}<\/td><td><span class="order-status ${statusClass}">${statusText}<\/span><\/td><td><button class="view-btn" onclick="event.stopPropagation();window.location.href='?view_order=${order.id}'"><i class="fas fa-eye"></i> View<\/button><\/td><\/tr>`;
                    });
                } else { tableHtml = '<tr><td colspan="7" class="text-center py-5">No orders found.<\/td><\/tr>'; }
                document.getElementById('ordersTableBody').innerHTML = tableHtml;
                const badge = document.getElementById('branchNotificationBadge');
                if (data.notification_count > 0) { badge.textContent = data.notification_count; badge.classList.remove('hidden'); document.getElementById('notificationCountText').textContent = `${data.notification_count} new`; }
                else { badge.classList.add('hidden'); document.getElementById('notificationCountText').textContent = '0 new'; }
                lastUpdateTime = data.timestamp;
            }
        } catch (error) { console.error('Error loading live data:', error); }
    }
    
    async function loadNotifications() {
        try {
            const response = await fetch(`check_notifications.php?last_check=${lastUpdateTime}`);
            const data = await response.json();
            const notificationList = document.getElementById('notificationList');
            if (data.notifications && data.notifications.length > 0) {
                let html = '';
                data.notifications.forEach(notif => { const date = new Date(notif.created_at); html += `<div class="notification-item unread" onclick="markSingleNotificationReadAndRedirect(${notif.id})"><div class="notification-title"><i class="fas fa-shopping-cart me-2"></i>Order #${escapeHtml(notif.order_no)}</div><div class="notification-text"><i class="fas fa-user me-1"></i> ${escapeHtml(notif.customer_name)} | <i class="fas fa-rupee-sign me-1"></i> Rs. ${parseFloat(notif.total_amount).toFixed(2)}</div><div class="notification-time"><i class="far fa-clock me-1"></i> ${date.toLocaleDateString()} ${date.toLocaleTimeString()}</div></div>`; });
                notificationList.innerHTML = html;
            } else { notificationList.innerHTML = `<div class="text-center py-4"><i class="fas fa-bell-slash fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No new orders</p></div>`; }
        } catch (error) { console.error('Error loading notifications:', error); }
    }
    
    async function markAllNotificationsRead() {
        try {
            const response = await fetch('mark_notification_read.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'mark_all=1' });
            const data = await response.json();
            if (data.success) {
                lastUpdateTime = data.new_timestamp;
                document.getElementById('branchNotificationBadge').classList.add('hidden');
                document.getElementById('notificationCountText').textContent = '0 new';
                document.getElementById('notificationList').innerHTML = `<div class="text-center py-4"><i class="fas fa-check-circle fa-2x text-success mb-2"></i><p class="text-muted mb-0">All notifications marked as read</p></div>`;
                setTimeout(() => document.getElementById('notificationDropdown').classList.remove('show'), 1500);
            }
        } catch (error) { console.error('Error marking notifications read:', error); }
    }
    
    function markSingleNotificationReadAndRedirect(orderId) { markAllNotificationsRead(); setTimeout(() => window.location.href = `?view_order=${orderId}`, 500); }
    function escapeHtml(text) { if (!text) return ''; const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
    
    document.querySelectorAll('.product-avail').forEach(radio => {
        radio.addEventListener('change', function() {
            const productId = this.dataset.product;
            const reasonDiv = document.getElementById(`product-reason-${productId}`);
            const hiddenAvail = document.getElementById(`product_avail_hidden_${productId}`);
            const hiddenReason = document.getElementById(`product_reason_hidden_${productId}`);
            const reasonInput = document.querySelector(`input[name="product_reason_${productId}"]`);
            
            if (this.value === 'not_available') {
                reasonDiv.style.display = 'block';
                if (hiddenAvail) hiddenAvail.value = 'not_available';
            } else {
                reasonDiv.style.display = 'none';
                if (hiddenAvail) hiddenAvail.value = 'available';
                if (reasonInput) reasonInput.value = '';
                if (hiddenReason) hiddenReason.value = '';
            }
        });
    });
    
    document.querySelectorAll('input[name^="product_reason_"]').forEach(input => {
        input.addEventListener('input', function() {
            const match = this.name.match(/product_reason_(\d+)/);
            if (match) {
                const productId = match[1];
                const hiddenReason = document.getElementById(`product_reason_hidden_${productId}`);
                if (hiddenReason) hiddenReason.value = this.value;
            }
        });
    });
    
    function startAutoRefresh() { if (!currentViewOrder) { loadLiveData(); refreshInterval = setInterval(loadLiveData, 10000); } }
    
    document.addEventListener('click', function(event) { const c = document.querySelector('.notification-container'); const d = document.getElementById('notificationDropdown'); if (c && !c.contains(event.target) && d) d.classList.remove('show'); });
    const si = document.getElementById('searchInput'); if (si) si.addEventListener('keypress', function(e) { if (e.key === 'Enter') performSearch(); });
    
    const radios = document.querySelectorAll('.avail-radio');
    const packingYes = document.getElementById('packingYes'), packingNo = document.getElementById('packingNo');
    const dispatchYes = document.getElementById('dispatchYes'), dispatchNo = document.getElementById('dispatchNo');
    const notAvailableNote = document.getElementById('notAvailableNote');
    
    function updateStatusBasedOnAvailability(value) {
        if (value === 'not_available') {
            if (packingYes) packingYes.checked = false;
            if (packingNo) packingNo.checked = true;
            if (dispatchYes) dispatchYes.checked = false;
            if (dispatchNo) dispatchNo.checked = true;
            const pg = document.getElementById('packingGroup'), dg = document.getElementById('dispatchGroup');
            if (pg) pg.style.opacity = '0.6';
            if (dg) dg.style.opacity = '0.6';
            if (notAvailableNote) notAvailableNote.style.display = 'block';
        } else {
            const pg = document.getElementById('packingGroup'), dg = document.getElementById('dispatchGroup');
            if (pg) pg.style.opacity = '1';
            if (dg) dg.style.opacity = '1';
            if (notAvailableNote) notAvailableNote.style.display = 'none';
        }
    }
    
    if (radios && radios.length > 0) {
        radios.forEach(radio => {
            radio.addEventListener('change', function() {
                const partialDiv = document.getElementById('partial_comment_div'), unavailDiv = document.getElementById('unavailability_reason_div');
                if (this.value === 'partial') { if (partialDiv) partialDiv.style.display = 'block'; if (unavailDiv) unavailDiv.style.display = 'none'; }
                else if (this.value === 'not_available') { if (partialDiv) partialDiv.style.display = 'none'; if (unavailDiv) unavailDiv.style.display = 'block'; }
                else { if (partialDiv) partialDiv.style.display = 'none'; if (unavailDiv) unavailDiv.style.display = 'none'; }
                updateStatusBasedOnAvailability(this.value);
            });
        });
        const currentSelected = document.querySelector('input[name="product_availability"]:checked');
        if (currentSelected) updateStatusBasedOnAvailability(currentSelected.value);
    }
    
    startAutoRefresh();
</script>
</body>
</html>