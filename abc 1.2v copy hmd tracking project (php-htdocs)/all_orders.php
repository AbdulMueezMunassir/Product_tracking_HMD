<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// Date filters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$branch_filter = isset($_GET['branch']) ? $_GET['branch'] : '';

$where_clause = "1=1";
if ($date_from) {
    $where_clause .= " AND DATE(o.created_at) >= '$date_from'";
}
if ($date_to) {
    $where_clause .= " AND DATE(o.created_at) <= '$date_to'";
}
if ($status_filter) {
    $where_clause .= " AND o.status = '$status_filter'";
}
if ($branch_filter) {
    $where_clause .= " AND o.branch_id = '$branch_filter'";
}

$share_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_order'])) {
    $order_id = $_POST['order_id'];
    $branch_id = $_POST['branch_id'];
    $pdo->prepare("UPDATE shop_orders SET branch_id = ? WHERE id = ?")->execute([$branch_id, $order_id]);
    $share_msg = '<div class="alert alert-success alert-dismissible fade show">Order shared successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// Get orders
try {
    $orders_query = "SELECT o.*, b.location as branch_name, b.branch_code,
                            (SELECT COUNT(*) FROM order_products WHERE order_id = o.id) as products_count 
                     FROM shop_orders o 
                     LEFT JOIN branch_managers b ON o.branch_id = b.id 
                     WHERE $where_clause
                     ORDER BY o.id DESC";
    $orders = $pdo->query($orders_query)->fetchAll();
} catch (PDOException $e) {
    $orders = [];
    error_log("Database error: " . $e->getMessage());
}

// Get products for each order with secondary information
$products_by_order = [];
if (!empty($orders)) {
    foreach ($orders as $order) {
        try {
            $stmt = $pdo->prepare("SELECT op.*, 
                                   b1.location as primary_location, b1.branch_code as primary_code,
                                   b2.location as secondary_location, b2.branch_code as secondary_code
                                   FROM order_products op 
                                   LEFT JOIN branch_managers b1 ON op.assign_showroom = b1.id 
                                   LEFT JOIN branch_managers b2 ON op.secondary_showroom = b2.id
                                   WHERE op.order_id = ?");
            $stmt->execute([$order['id']]);
            $products_by_order[$order['id']] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $products_by_order[$order['id']] = [];
        }
    }
}

$branches = $pdo->query("SELECT id, branch_code, location FROM branch_managers")->fetchAll();
$all_branches = $pdo->query("SELECT id, branch_code, location FROM branch_managers")->fetchAll();

$has_orders = !empty($orders) && count($orders) > 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Orders | Hameedia</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
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
        
        .order-card { background: white; border-radius: 20px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: all 0.3s; }
        .order-card:hover { box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .order-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 25px; cursor: pointer; transition: all 0.3s; }
        .order-header:hover { background: linear-gradient(135deg, #2d3e5a, #1e2a3e); }
        .order-header .toggle-icon { transition: transform 0.3s; }
        .order-header.collapsed .toggle-icon { transform: rotate(-90deg); }
        .order-body { padding: 20px 25px; display: none; }
        .order-body.show { display: block; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-not-available { background: #fee2e2; color: #991b1b; }
        .status-partial { background: #fed7aa; color: #9a3412; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .info-card { background: #f8fafc; padding: 12px 15px; border-radius: 12px; }
        .info-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; }
        .info-value { font-size: 1rem; font-weight: 600; color: #1e293b; margin-top: 5px; }
        
        .product-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .product-table th { background: #f1f5f9; padding: 12px; text-align: left; font-weight: 600; font-size: 0.8rem; }
        .product-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .product-image { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
        
        .filter-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .btn-print { background: #1e293b; color: white; border: none; padding: 8px 20px; border-radius: 30px; }
        .btn-print:hover { background: #0f172a; color: white; }
        .btn-filter { background: #0284c7; color: white; border: none; padding: 8px 25px; border-radius: 30px; }
        
        .delivery-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .delivery-single { background: #dcfce7; color: #166534; }
        .delivery-combine { background: #dbeafe; color: #1e40af; }
        .delivery-separate { background: #fef3c7; color: #92400e; }
        
        .print-btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; }
        
        .primary-badge {
            background: #1e40af;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .secondary-badge {
            background: #8b5cf6;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .null-badge {
            background: #e2e8f0;
            color: #64748b;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .comment-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .comment-box i {
            color: #f59e0b;
        }
        .comment-box.barcode-box {
            background: #dbeafe;
            border-left-color: #0284c7;
        }
        .comment-box.barcode-box i {
            color: #0284c7;
        }
        .comment-box .barcode-code {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e40af;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        
        .occasion-badge {
            background: #fce4ec;
            color: #c62828;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        @media (max-width: 768px) { .sidebar { width: 240px; } .main-content { margin-left: 240px; padding: 15px; } }
        
        @media print {
            .sidebar, .menu-toggle, .sidebar-overlay, .no-print, .filter-card { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; }
            .order-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
            .order-body { display: block !important; }
            .print-btn-group { display: none !important; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link active"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
    <a href="occasions.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Occasions</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2><i class="fas fa-list"></i> All Orders & Products</h2><p class="text-muted">Complete summary with delivery options, dispatch status, and product assignments</p></div>
        <div>
            <button onclick="window.print()" class="btn-print no-print"><i class="fas fa-print"></i> Print All</button>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Confirmed" <?= $status_filter == 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Branch</label>
                <select name="branch" class="form-select">
                    <option value="">All</option>
                    <?php foreach($all_branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $branch_filter == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
                <a href="all_orders.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    
    <?= $share_msg ?>
    
    <?php if ($has_orders): ?>
        <?php foreach($orders as $order): ?>
            <?php
            if ($order['product_availability'] == 'not_available') {
                $status_class = 'status-not-available';
                $status_text = 'Not Available';
            } elseif ($order['product_availability'] == 'partial') {
                $status_class = 'status-partial';
                $status_text = 'Partially Available';
            } elseif ($order['packing_status'] == 'Yes' && $order['dispatch_status'] == 'Yes') {
                $status_class = 'status-completed';
                $status_text = 'Completed';
            } else {
                $status_class = 'status-pending';
                $status_text = 'Pending';
            }
            $products = isset($products_by_order[$order['id']]) ? $products_by_order[$order['id']] : [];
            $delivery_decision = isset($order['delivery_decision']) ? $order['delivery_decision'] : 'single';
            $delivery_class = 'delivery-' . $delivery_decision;
            $delivery_text = ucfirst($delivery_decision);
            ?>
            <div class="order-card">
                <div class="order-header" onclick="toggleOrder(<?= $order['id'] ?>)">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <i class="fas fa-chevron-right toggle-icon"></i>
                            <div><strong class="fs-5">Order #<?= htmlspecialchars($order['order_no']) ?></strong>
                                <span class="status-badge <?= $status_class ?> ms-2"><?= $status_text ?></span>
                                <?php if($delivery_decision != 'single'): ?>
                                    <span class="delivery-badge <?= $delivery_class ?> ms-2"><i class="fas fa-truck"></i> <?= $delivery_text ?></span>
                                <?php endif; ?>
                                <?php if(!empty($order['occasion'])): ?>
                                    <span class="occasion-badge ms-2"><i class="fas fa-gift"></i> <?= htmlspecialchars($order['occasion']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mt-2 mt-sm-0">
                            <div><i class="fas fa-user"></i> <?= htmlspecialchars($order['customer_name']) ?></div>
                            <div><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($order['created_at'])) ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="order-body" id="order-body-<?= $order['id'] ?>">
                    <div class="info-grid">
                        <div class="info-card"><div class="info-label">Customer</div><div class="info-value"><?= htmlspecialchars($order['customer_name']) ?></div></div>
                        <div class="info-card"><div class="info-label">Contact</div><div class="info-value"><?= htmlspecialchars($order['contact_no'] ?: '-') ?></div></div>
                        <?php if(!empty($order['occasion'])): ?>
                            <div class="info-card"><div class="info-label">Occasion</div><div class="info-value">🎉 <?= htmlspecialchars($order['occasion']) ?></div></div>
                        <?php endif; ?>
                        <div class="info-card"><div class="info-label">Payment Mode</div><div class="info-value"><?= htmlspecialchars($order['payment_mode'] ?: '-') ?></div></div>
                        <div class="info-card"><div class="info-label">Payment ID</div><div class="info-value"><?= htmlspecialchars($order['payment_id'] ?: '-') ?></div></div>
                        <div class="info-card"><div class="info-label">Delivery Decision</div>
                            <div class="info-value">
                                <span class="delivery-badge <?= $delivery_class ?>">
                                    <?php if($delivery_decision == 'single'): ?>
                                        <i class="fas fa-box"></i> Single Delivery
                                    <?php elseif($delivery_decision == 'combine'): ?>
                                        <i class="fas fa-boxes"></i> Combine & Pack
                                    <?php elseif($delivery_decision == 'separate'): ?>
                                        <i class="fas fa-truck"></i> Separate Deliveries
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card"><div class="info-label">Total</div><div class="info-value text-success fw-bold">Rs. <?= number_format($order['total_amount'], 2) ?></div></div>
                    </div>
                    
                    <?php if(!empty($order['address'])): ?>
                    <div class="info-card mb-3"><div class="info-label">Delivery Address</div><div class="info-value"><?= nl2br(htmlspecialchars($order['address'])) ?></div></div>
                    <?php endif; ?>
                    
                    <?php if(!empty($order['barcode_number'])): ?>
                        <div class="comment-box barcode-box mb-3">
                            <i class="fas fa-barcode me-2"></i> 
                            <strong>Barcode Number:</strong> 
                            <span class="barcode-code"><?= htmlspecialchars($order['barcode_number']) ?></span>
                            <br><small class="text-muted">Entered by: <?= htmlspecialchars($order['branch_name'] ?: 'Branch') ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(!empty($order['branch_comment'])): ?>
                        <div class="comment-box mb-3">
                            <i class="fas fa-comment me-2"></i> 
                            <strong>Branch Comment:</strong> 
                            <?= nl2br(htmlspecialchars($order['branch_comment'])) ?>
                            <br><small class="text-muted">From: <?= htmlspecialchars($order['branch_name'] ?: 'Branch') ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <h6 class="mt-3"><i class="fas fa-box"></i> Products Ordered</h6>
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Image</th><th>Product</th><th>Promo / Size</th><th>SKU</th><th>Disc</th>
                                <th>Price</th><th>After Disc.</th><th>Primary</th><th>Secondary</th><th>Dispatch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($products)): ?>
                                <?php foreach($products as $product): ?>
                                <tr>
                                    <td><?php if($product['image_url']): ?><img src="<?= htmlspecialchars($product['image_url']) ?>" class="product-image" onerror="this.style.display='none'"><?php else: ?>-<?php endif; ?></td>
                                    <td><strong><?= htmlspecialchars($product['product_name']) ?></strong></td>
                                    <td><?= $product['promocode'] ?> / <?= $product['size'] ?></td>
                                    <td><small><?= $product['sku'] ?></small></td>
                                    <td><?= $product['discount_percent'] > 0 ? '-'.$product['discount_percent'].'%' : '-' ?></td>
                                    <td>Rs. <?= number_format($product['price'], 2) ?></td>
                                    <td class="text-success fw-bold">Rs. <?= number_format($product['after_discount_price'], 2) ?></td>
                                    <td>
                                        <?php if($product['primary_code']): ?>
                                            <span class="primary-badge"><i class="fas fa-store"></i> <?= htmlspecialchars($product['primary_code']) ?></span>
                                        <?php else: ?>
                                            <span class="null-badge">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($product['secondary_code']): ?>
                                            <span class="secondary-badge"><i class="fas fa-store-alt"></i> <?= htmlspecialchars($product['secondary_code']) ?></span>
                                        <?php else: ?>
                                            <span class="null-badge">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $order['dispatch_status'] == 'Yes' ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center text-muted">No products found for this order.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-light"><td colspan="8"><strong>Shipping:</strong></td><td colspan="2"><strong>Rs. <?= number_format($order['shipping_charges'], 2) ?></strong></td></tr>
                            <tr class="bg-light"><td colspan="8"><strong class="fs-5">Grand Total:</strong></td><td colspan="2"><strong class="fs-5 text-success">Rs. <?= number_format($order['total_amount'], 2) ?></strong></td></tr>
                        </tfoot>
                    </table>
                    
                    <div class="row mt-3 pt-2 border-top">
                        <div class="col-md-3">
                            <div class="info-card"><div class="info-label">📦 Packing</div><div class="info-value"><span class="badge <?= $order['packing_status'] == 'Yes' ? 'bg-success' : 'bg-danger' ?>"><?= $order['packing_status'] == 'Yes' ? 'Packed' : 'Not Packed' ?></span></div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card"><div class="info-label">🚚 Dispatch</div><div class="info-value"><span class="badge <?= $order['dispatch_status'] == 'Yes' ? 'bg-success' : 'bg-danger' ?>"><?= $order['dispatch_status'] == 'Yes' ? 'Dispatched' : 'Not Dispatched' ?></span></div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card"><div class="info-label">📄 Invoice</div><div class="info-value"><?= htmlspecialchars($order['invoice_no'] ?: '-') ?></div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card"><div class="info-label">🏢 Share</div>
                                <form method="POST" class="d-flex gap-1"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><select name="branch_id" class="form-select form-select-sm" style="width: auto;"><?php foreach($branches as $b): ?><option value="<?= $b['id'] ?>" <?= ($b['id'] == $order['branch_id']) ? 'selected' : '' ?>><?= $b['branch_code'] ?></option><?php endforeach; ?></select><button type="submit" name="share_order" class="btn btn-sm btn-primary"><i class="fas fa-share"></i> Send</button></form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Print Button for Individual Order -->
                    <div class="print-btn-group no-print">
                        <a href="print_order.php?id=<?= $order['id'] ?>" target="_blank" class="btn btn-sm btn-dark">
                            <i class="fas fa-print"></i> Print Order #<?= $order['order_no'] ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-5 bg-white rounded-4">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5>No orders found</h5>
            <p class="text-muted">Try adjusting your filters or <a href="create_order.php">create a new order</a>.</p>
        </div>
    <?php endif; ?>
    
    <footer class="text-center text-muted mt-4 small"><small>© 2026 Hameedia Order Management System</small></footer>
</div>

<script>
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
    function toggleOrder(id) { 
        const body = document.getElementById('order-body-' + id); 
        const header = body.previousElementSibling; 
        body.classList.toggle('show'); 
        header.classList.toggle('collapsed'); 
    }
    
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { if(window.innerWidth<577) toggleSidebar(); }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
    
    document.addEventListener('DOMContentLoaded', function() { 
        const first = document.querySelector('.order-body'); 
        if(first) first.classList.add('show'); 
    });
</script>
</body>
</html>