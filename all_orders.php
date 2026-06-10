<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_order'])) {
    $order_id = $_POST['order_id'];
    $branch_id = $_POST['branch_id'];
    $pdo->prepare("UPDATE shop_orders SET branch_id = ? WHERE id = ?")->execute([$branch_id, $order_id]);
    $share_msg = '<div class="alert alert-success alert-dismissible fade show">Order shared successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

$orders = $pdo->query("SELECT o.*, b.location as branch_name, b.branch_code,
                              (SELECT COUNT(*) FROM order_products WHERE order_id = o.id) as products_count 
                       FROM shop_orders o 
                       LEFT JOIN branch_managers b ON o.branch_id = b.id 
                       ORDER BY o.id DESC")->fetchAll();

$products_by_order = [];
foreach ($orders as $order) {
    $stmt = $pdo->prepare("SELECT * FROM order_products WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $products_by_order[$order['id']] = $stmt->fetchAll();
}

$branches = $pdo->query("SELECT id, branch_code, location FROM branch_managers")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Orders | Hameedia</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            width: 280px;
            position: fixed;
            height: 100vh;
            background: linear-gradient(135deg, #1e2a3e, #15232e);
            color: white;
            padding: 20px;
            overflow-y: auto;
        }
        .main-content { margin-left: 280px; padding: 25px 35px; }
        .nav-link { color: #cfdde6; padding: 12px 20px; margin: 5px 0; border-radius: 12px; text-decoration: none; display: block; transition: all 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .nav-link i { width: 28px; }
        
        .order-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .order-card:hover { box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .order-header {
            background: linear-gradient(135deg, #1e2a3e, #15232e);
            color: white;
            padding: 15px 25px;
            cursor: pointer;
            transition: all 0.3s;
        }
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
        .discount-tag { background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 12px; font-size: 0.7rem; }
        
        .branch-comment-box {
            background: #e0f2fe;
            border-left: 4px solid #0284c7;
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .availability-box {
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .availability-available { background: #dcfce7; border-left: 4px solid #22c55e; }
        .availability-partial { background: #fed7aa; border-left: 4px solid #f97316; }
        .availability-not { background: #fee2e2; border-left: 4px solid #ef4444; }
        
        .product-available-badge { font-size: 0.7rem; padding: 3px 8px; border-radius: 20px; display: inline-block; }
        .product-avail-yes { background: #dcfce7; color: #166534; }
        .product-avail-no { background: #fee2e2; color: #991b1b; }
        
        @media (max-width: 768px) {
            .sidebar { width: 240px; }
            .main-content { margin-left: 240px; padding: 15px; }
            .product-table { font-size: 0.75rem; }
            .product-table th, .product-table td { padding: 8px; }
        }
        @media (max-width: 576px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 1000; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; position: fixed; top: 15px; left: 15px; z-index: 1001; background: #1e2a3e; color: white; border: none; padding: 10px 15px; border-radius: 10px; cursor: pointer; }
            .top-header { margin-top: 50px; }
        }
        @media (min-width: 577px) { .menu-toggle { display: none; } }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999;"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link active"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2><i class="fas fa-list"></i> All Orders & Products</h2><p class="text-muted">Complete summary with packing, dispatch, invoice status, branch comments, and product availability</p></div>
        <div class="text-muted"><i class="fas fa-calendar"></i> <?= date('l, F j, Y') ?></div>
    </div>
    
    <?= isset($share_msg) ? $share_msg : '' ?>
    
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
        $products = $products_by_order[$order['id']];
        ?>
        <div class="order-card" data-order-id="<?= $order['id'] ?>" data-updated="<?= strtotime($order['updated_at']) ?>">
            <div class="order-header" onclick="toggleOrder(<?= $order['id'] ?>)">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-chevron-right toggle-icon"></i>
                        <div><strong class="fs-5">Order #<?= htmlspecialchars($order['order_no']) ?></strong><span class="status-badge <?= $status_class ?> ms-2"><?= $status_text ?></span></div>
                    </div>
                    <div class="d-flex gap-3 mt-2 mt-sm-0">
                        <div><i class="fas fa-user"></i> <?= htmlspecialchars($order['customer_name']) ?></div>
                        <div><i class="fas fa-store"></i> <?= htmlspecialchars($order['branch_name'] ?? 'Unassigned') ?></div>
                        <div><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($order['created_at'])) ?></div>
                    </div>
                </div>
            </div>
            
            <div class="order-body" id="order-body-<?= $order['id'] ?>">
                <div class="info-grid">
                    <div class="info-card"><div class="info-label">Customer Name</div><div class="info-value"><?= htmlspecialchars($order['customer_name']) ?></div></div>
                    <div class="info-card"><div class="info-label">Contact Number</div><div class="info-value"><?= htmlspecialchars($order['contact_no'] ?: '-') ?></div></div>
                    <div class="info-card"><div class="info-label">Payment Mode</div><div class="info-value"><?= htmlspecialchars($order['payment_mode'] ?: '-') ?></div></div>
                    <div class="info-card"><div class="info-label">Total Amount</div><div class="info-value text-success fw-bold">Rs. <?= number_format($order['total_amount'], 2) ?></div></div>
                </div>
                
                <?php if(!empty($order['address'])): ?>
                <div class="info-card mb-3"><div class="info-label">Delivery Address</div><div class="info-value"><?= nl2br(htmlspecialchars($order['address'])) ?></div></div>
                <?php endif; ?>
                
                <?php if(!empty($order['branch_comment'])): ?>
                <div class="branch-comment-box">
                    <i class="fas fa-comment-dots me-2"></i>
                    <strong>Branch Manager Comment:</strong><br>
                    <?= nl2br(htmlspecialchars($order['branch_comment'])) ?>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($order['barcode_number'])): ?>
                <div class="info-card mb-3" style="background: #f1f5f9;">
                    <div class="info-label"><i class="fas fa-barcode"></i> Barcode Number</div>
                    <div class="info-value"><?= htmlspecialchars($order['barcode_number']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="availability-box <?= $order['product_availability'] == 'not_available' ? 'availability-not' : ($order['product_availability'] == 'partial' ? 'availability-partial' : 'availability-available') ?>">
                    <i class="fas fa-boxes me-2"></i>
                    <strong>Overall Availability:</strong>
                    <?php if($order['product_availability'] == 'available' || !$order['product_availability']): ?>
                        <span class="badge bg-success">✓ All Products Available</span>
                    <?php elseif($order['product_availability'] == 'partial'): ?>
                        <span class="badge bg-warning text-dark">⚠️ Partially Available</span>
                        <?php if(!empty($order['partial_comment'])): ?>
                            <div class="mt-2 small"><strong>Details:</strong> <?= htmlspecialchars($order['partial_comment']) ?></div>
                        <?php endif; ?>
                    <?php elseif($order['product_availability'] == 'not_available'): ?>
                        <span class="badge bg-danger">❌ No Products Available</span>
                        <?php if(!empty($order['unavailability_reason'])): ?>
                            <div class="mt-2 small"><strong>Reason:</strong> <?= htmlspecialchars($order['unavailability_reason']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <h6 class="mt-3"><i class="fas fa-box"></i> Products Ordered</h6>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product Name</th>
                            <th>Color / Size</th>
                            <th>SKU</th>
                            <th>Discount</th>
                            <th>Price</th>
                            <th>After Disc.</th>
                            <th>Availability</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($products as $product): ?>
                        <tr>
                            <td>
                                <?php if($product['image_url']): ?>
                                    <img src="<?= htmlspecialchars($product['image_url']) ?>" class="product-image" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="product-placeholder" style="width:50px; height:50px; background:#e2e8f0; border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-image text-muted"></i></div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($product['product_name']) ?></strong></td>
                            <td><?= $product['color'] ?> / <?= $product['size'] ?></td>
                            <td><small class="text-muted"><?= $product['sku'] ?></small></td>
                            <td><?= $product['discount_percent'] > 0 ? '<span class="discount-tag">-'.$product['discount_percent'].'%</span>' : '-' ?></td>
                            <td class="text-muted">Rs. <?= number_format($product['price'], 2) ?></td>
                            <td class="fw-bold text-success">Rs. <?= number_format($product['after_discount_price'], 2) ?></td>
                            <td>
                                <?php if($product['product_availability'] == 'not_available'): ?>
                                    <span class="product-available-badge product-avail-no">
                                        <i class="fas fa-times-circle"></i> Not Available
                                    </span>
                                    <?php if(!empty($product['product_availability_reason'])): ?>
                                        <br><small class="text-muted">Reason: <?= htmlspecialchars($product['product_availability_reason']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="product-available-badge product-avail-yes">
                                        <i class="fas fa-check-circle"></i> Available
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light"><td colspan="6"><strong>Shipping Charges:</strong></td><td colspan="2"><strong>Rs. <?= number_format($order['shipping_charges'], 2) ?></strong></td></tr>
                        <tr class="bg-light"><td colspan="6"><strong class="fs-5">Grand Total:</strong></td><td colspan="2"><strong class="fs-5 text-success">Rs. <?= number_format($order['total_amount'], 2) ?></strong></td></tr>
                    </tfoot>
                </table>
                
                <div class="row mt-3 pt-2 border-top">
                    <div class="col-md-3">
                        <div class="info-card"><div class="info-label">📦 Packing Status</div><div class="info-value"><span class="badge <?= $order['packing_status'] == 'Yes' ? 'bg-success' : 'bg-danger' ?>"><?= $order['packing_status'] == 'Yes' ? 'Packed' : 'Not Packed' ?></span></div></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card"><div class="info-label">🚚 Dispatch Status</div><div class="info-value"><span class="badge <?= $order['dispatch_status'] == 'Yes' ? 'bg-success' : 'bg-danger' ?>"><?= $order['dispatch_status'] == 'Yes' ? 'Dispatched' : 'Not Dispatched' ?></span></div></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card"><div class="info-label">📄 Invoice Number</div><div class="info-value"><?= htmlspecialchars($order['invoice_no'] ?: '-') ?></div></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card"><div class="info-label">🏢 Share to Showroom</div><div class="info-value">
                            <form method="POST" class="d-flex gap-1"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><select name="branch_id" class="form-select form-select-sm" style="width: auto;"><?php foreach($branches as $b): ?><option value="<?= $b['id'] ?>" <?= ($b['id'] == $order['branch_id']) ? 'selected' : '' ?>><?= $b['branch_code'] ?> - <?= $b['location'] ?></option><?php endforeach; ?></select><button type="submit" name="share_order" class="btn btn-sm btn-primary"><i class="fas fa-share"></i> Send</button></form>
                        </div></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    
    <?php if(count($orders) == 0): ?>
    <div class="text-center py-5 bg-white rounded-4"><i class="fas fa-inbox fa-4x text-muted mb-3"></i><h5>No orders found</h5><p class="text-muted">Create your first order from the Create Order page.</p></div>
    <?php endif; ?>
    
    <footer class="text-center text-muted mt-4 small"><small>© 2024 Hameedia Order Management System</small></footer>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('open');
        if (overlay) overlay.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
    }
    
    function toggleOrder(id) {
        const body = document.getElementById('order-body-' + id);
        const header = body.previousElementSibling;
        body.classList.toggle('show');
        header.classList.toggle('collapsed');
    }
    
    let lastUpdateTime = <?= time() ?>;
    let refreshInterval;
    
    async function refreshOrders() {
        try {
            const response = await fetch(`all_orders_ajax.php?last_update=${lastUpdateTime}`);
            const data = await response.json();
            if (data.orders && data.orders.length > 0) {
                for (const order of data.orders) {
                    const orderCard = document.querySelector(`.order-card[data-order-id="${order.id}"]`);
                    if (orderCard && parseInt(orderCard.dataset.updated) < order.updated) {
                        location.reload();
                        return;
                    }
                }
                lastUpdateTime = data.timestamp;
            }
        } catch (error) {
            console.error('Auto-refresh error:', error);
        }
    }
    
    function startAutoRefresh() {
        refreshInterval = setInterval(refreshOrders, 10000);
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const firstBody = document.querySelector('.order-body');
        if (firstBody) firstBody.classList.add('show');
    });
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 577) toggleSidebar();
        });
    });
    
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
    
    startAutoRefresh();
</script>
</body>
</html>