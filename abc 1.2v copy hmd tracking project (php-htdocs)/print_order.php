<?php
session_start();
require_once 'config.php';

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id == 0) {
    header('Location: ' . ($_SESSION['role'] == 'admin' ? 'all_orders.php' : 'branch_dashboard.php'));
    exit;
}

$order = $pdo->prepare("SELECT * FROM shop_orders WHERE id = ?");
$order->execute([$order_id]);
$order_data = $order->fetch();

if (!$order_data) {
    header('Location: ' . ($_SESSION['role'] == 'admin' ? 'all_orders.php' : 'branch_dashboard.php'));
    exit;
}

$products = $pdo->prepare("SELECT op.*, 
                           b1.location as primary_location, b1.branch_code as primary_code,
                           b2.location as secondary_location, b2.branch_code as secondary_code
                           FROM order_products op 
                           LEFT JOIN branch_managers b1 ON op.assign_showroom = b1.id 
                           LEFT JOIN branch_managers b2 ON op.secondary_showroom = b2.id
                           WHERE op.order_id = ?");
$products->execute([$order_id]);
$products_data = $products->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Order #<?= $order_data['order_no'] ?></title>
    <meta charset="UTF-8">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: white; }
        .print-container { max-width: 800px; margin: 0 auto; padding: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e2a3e; padding-bottom: 15px; }
        .header h1 { color: #1e2a3e; font-weight: 800; }
        .header .order-badge { background: #1e2a3e; color: white; padding: 5px 15px; border-radius: 30px; display: inline-block; margin-top: 10px; }
        .order-details { margin-bottom: 25px; }
        .order-details table { width: 100%; }
        .order-details td { padding: 5px 0; }
        .label { color: #64748b; font-weight: 600; width: 40%; }
        .value { font-weight: 500; }
        .product-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .product-table th { background: #1e2a3e; color: white; padding: 10px; text-align: left; border: 1px solid #1e2a3e; }
        .product-table td { padding: 10px; border: 1px solid #e2e8f0; }
        .product-table .subtotal { background: #f8fafc; font-weight: 600; }
        .total-section { margin-top: 20px; padding-top: 15px; border-top: 2px solid #1e2a3e; }
        .footer { margin-top: 30px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        .delivery-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; }
        .delivery-single { background: #dcfce7; color: #166534; }
        .delivery-combine { background: #dbeafe; color: #1e40af; }
        .delivery-separate { background: #fef3c7; color: #92400e; }
        
        @media print {
            .no-print { display: none !important; }
            body { padding: 20px; }
            .btn-print { display: none !important; }
            .product-table th { background: #1e2a3e !important; color: white !important; }
        }
    </style>
</head>
<body>
<div class="print-container">
    <div class="header">
        <h1><i class="fas fa-store"></i> Hameedia</h1>
        <p>Order Confirmation & Packing Slip</p>
        <div class="order-badge">Order #<?= htmlspecialchars($order_data['order_no']) ?></div>
    </div>
    
    <div class="order-details">
        <h5 class="mb-3">Order Information</h5>
        <table>
            <tr><td class="label">Date:</td><td class="value"><?= date('l, F j, Y \a\t h:i A', strtotime($order_data['created_at'])) ?></td></tr>
            <tr><td class="label">Customer:</td><td class="value"><?= htmlspecialchars($order_data['customer_name']) ?></td></tr>
            <tr><td class="label">Contact:</td><td class="value"><?= htmlspecialchars($order_data['contact_no']) ?></td></tr>
            <tr><td class="label">Delivery Address:</td><td class="value"><?= nl2br(htmlspecialchars($order_data['address'])) ?></td></tr>
            <tr><td class="label">Payment Mode:</td><td class="value"><?= htmlspecialchars($order_data['payment_mode']) ?></td></tr>
            <tr><td class="label">Payment ID:</td><td class="value"><?= htmlspecialchars($order_data['payment_id'] ?: 'N/A') ?></td></tr>
            <tr><td class="label">Remark ID:</td><td class="value"><?= htmlspecialchars($order_data['remark_id'] ?: 'N/A') ?></td></tr>
            <tr><td class="label">Online ID:</td><td class="value"><?= htmlspecialchars($order_data['koko_online_id'] ?: 'N/A') ?></td></tr>
            <tr><td class="label">Delivery Option:</td><td class="value"><span class="delivery-badge delivery-<?= $order_data['delivery_decision'] ?? 'single' ?>"><?= ucfirst($order_data['delivery_decision'] ?? 'Single') ?></span></td></tr>
            <tr><td class="label">Shipping Charges:</td><td class="value">Rs. <?= number_format($order_data['shipping_charges'], 2) ?></td></tr>
        </table>
    </div>
    
    <h5>Products Ordered</h5>
    <table class="product-table">
        <thead>
            <tr><th>#</th><th>Product Name</th><th>Promo / Size</th><th>SKU</th><th>Price</th><th>After Disc.</th><th>Primary</th><th>Secondary</th></tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($products_data as $p): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($p['product_name']) ?></td>
                <td><?= $p['promocode'] ?> / <?= $p['size'] ?></td>
                <td><?= $p['sku'] ?></td>
                <td>Rs. <?= number_format($p['price'], 2) ?></td>
                <td class="text-success">Rs. <?= number_format($p['after_discount_price'], 2) ?></td>
                <td><?= $p['primary_code'] ?: '-' ?></td>
                <td><?= $p['secondary_code'] ?: '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="subtotal"><td colspan="5"><strong>Subtotal:</strong></td><td colspan="3"><strong>Rs. <?= number_format($order_data['total_amount'] - $order_data['shipping_charges'], 2) ?></strong></td></tr>
            <tr class="subtotal"><td colspan="5"><strong>Shipping Charges:</strong></td><td colspan="3"><strong>Rs. <?= number_format($order_data['shipping_charges'], 2) ?></strong></td></tr>
            <tr class="subtotal" style="background:#dbeafe;"><td colspan="5"><strong class="fs-5">Grand Total:</strong></td><td colspan="3"><strong class="fs-5 text-success">Rs. <?= number_format($order_data['total_amount'], 2) ?></strong></td></tr>
        </tfoot>
    </table>
    
    <div class="total-section">
        <div class="row">
            <div class="col-md-6">
                <p><strong>📦 Packing Status:</strong> <?= $order_data['packing_status'] == 'Yes' ? '✅ Packed' : '❌ Not Packed' ?></p>
                <p><strong>🚚 Dispatch Status:</strong> <?= $order_data['dispatch_status'] == 'Yes' ? '✅ Dispatched' : '❌ Not Dispatched' ?></p>
            </div>
            <div class="col-md-6 text-end">
                <p><strong>📄 Invoice Number:</strong> <?= htmlspecialchars($order_data['invoice_no'] ?: 'N/A') ?></p>
                <p><strong>📊 Order Status:</strong> <?= $order_data['status'] ?></p>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <p>© 2026 Hameedia Order Management System | Thank you for your order!</p>
        <p class="text-muted small">This is a system-generated document. Please keep for your records.</p>
    </div>
</div>
</body>
</html>