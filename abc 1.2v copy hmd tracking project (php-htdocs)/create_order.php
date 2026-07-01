<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$branches = $pdo->query("SELECT id, branch_code, location, email FROM branch_managers")->fetchAll();
$payment_modes = $pdo->query("SELECT id, mode_name FROM payment_modes WHERE is_active = 1 ORDER BY mode_name")->fetchAll();
$occasions = $pdo->query("SELECT id, occasion_name FROM occasions WHERE is_active = 1 ORDER BY occasion_name")->fetchAll();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $order_no = $_POST['order_no'];
    $customer = $_POST['customer_name'];
    $address = $_POST['address'];
    $contact = $_POST['contact_no'];
    $occasion = $_POST['occasion'] ?? '';
    $remark_id = $_POST['remark_id'];
    $payment = $_POST['payment_mode'];
    $payment_id = $_POST['payment_id'];
    
    // Get the shipping charge entered by admin
    $shipping_charge = floatval($_POST['shipping_charge'] ?? 0);
    $shipping_product_index = isset($_POST['shipping_product']) ? intval($_POST['shipping_product']) : -1;
    
    $product_names = $_POST['product_name'];
    $promocodes = $_POST['promocode'] ?? [];
    $skus = $_POST['sku'] ?? [];
    $sizes = $_POST['size'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];
    $prices = $_POST['price'] ?? [];
    $image_urls = $_POST['image_url'] ?? [];
    $primary_branches = $_POST['primary_branch'] ?? [];
    $secondary_branches = $_POST['secondary_branch'] ?? [];
    $has_secondary = $_POST['has_secondary'] ?? [];
    $delivery_decisions = $_POST['delivery_decision'] ?? [];
    
    $total_products = 0;
    $total_shipping = 0;
    
    try {
        $pdo->beginTransaction();
        
        $default_branch = !empty($branches) ? $branches[0]['id'] : null;
        
        $stmt = $pdo->prepare("INSERT INTO shop_orders (order_no, customer_name, address, contact_no, occasion, remark_id, payment_mode, payment_id, shipping_charges, delivery_decision, total_amount, branch_id, packing_status, dispatch_status, collection_status, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$order_no, $customer, $address, $contact, $occasion, $remark_id, $payment, $payment_id, 0, 'single', 0, $default_branch, 'No', 'No', 'No', 'Pending']);
        $order_id = $pdo->lastInsertId();
        
        $insItem = $pdo->prepare("INSERT INTO order_products (order_id, product_name, promocode, sku, size, discount_percent, price, after_discount_price, per_product_shipping, apply_shipping, image_url, assign_showroom, secondary_showroom, delivery_decision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        
        $assigned_branches = [];
        $has_multiple = false;
        $secondary_items = [];
        
        for ($i = 0; $i < count($product_names); $i++) {
            $price = floatval($prices[$i] ?? 0);
            $disc = floatval($discounts[$i] ?? 0);
            $after = $price - ($price * $disc / 100);
            
            // Check if this product should get the shipping charge
            $apply_product_shipping = ($shipping_product_index == $i && $shipping_charge > 0) ? 1 : 0;
            $product_shipping = $apply_product_shipping ? $shipping_charge : 0;
            
            if ($apply_product_shipping) {
                $total_shipping += $product_shipping;
            }
            
            $total_products += $after;
            $image_url = !empty($image_urls[$i]) ? $image_urls[$i] : null;
            
            $primary_branch = !empty($primary_branches[$i]) ? $primary_branches[$i] : $default_branch;
            
            // Determine secondary branch
            $is_secondary_enabled = isset($has_secondary[$i]) && $has_secondary[$i] == '1';
            $secondary_branch_value = isset($secondary_branches[$i]) ? $secondary_branches[$i] : null;
            
            $secondary_branch = null;
            $delivery_decision = isset($delivery_decisions[$i]) ? $delivery_decisions[$i] : 'combine';
            
            if ($is_secondary_enabled && !empty($secondary_branch_value) && $secondary_branch_value != $primary_branch) {
                $secondary_branch = $secondary_branch_value;
                $has_multiple = true;
                
                $secondary_items[] = [
                    'product_name' => $product_names[$i],
                    'product_sku' => $skus[$i] ?? '',
                    'product_size' => $sizes[$i] ?? '',
                    'primary_branch' => $primary_branch,
                    'secondary_branch' => $secondary_branch,
                    'delivery_decision' => $delivery_decision
                ];
            }
            
            // Insert product
            $insItem->execute([
                $order_id, 
                $product_names[$i], 
                $promocodes[$i] ?? '', 
                $skus[$i] ?? '', 
                $sizes[$i] ?? '', 
                $disc, 
                $price, 
                $after,
                $product_shipping,
                $apply_product_shipping,
                $image_url, 
                $primary_branch, 
                $secondary_branch,
                $delivery_decision
            ]);
            
            if ($primary_branch) {
                if (!isset($assigned_branches[$primary_branch])) {
                    $assigned_branches[$primary_branch] = [];
                }
                $assigned_branches[$primary_branch][] = $product_names[$i];
            }
        }
        
        // Send notifications to secondary showrooms
        if ($has_multiple && !empty($secondary_items)) {
            foreach ($secondary_items as $item) {
                $product_name = $item['product_name'];
                $primary_branch_id = $item['primary_branch'];
                $secondary_branch_id = $item['secondary_branch'];
                $delivery_decision = $item['delivery_decision'];
                
                $primary_info = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
                $primary_info->execute([$primary_branch_id]);
                $primary_data = $primary_info->fetch();
                
                $secondary_info = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
                $secondary_info->execute([$secondary_branch_id]);
                $secondary_data = $secondary_info->fetch();
                
                if (!$primary_data || !$secondary_data) continue;
                
                if ($delivery_decision == 'combine') {
                    $message_text = "📦 **Combine & Pack Request**\n\n";
                    $message_text .= "Please send the following product to **{$primary_data['location']}** showroom for combined packing:\n\n";
                    $message_text .= "📋 Product: {$product_name}\n";
                    $message_text .= "🆔 Order #: {$order_no}\n";
                    $message_text .= "👤 Customer: {$customer}\n";
                    $message_text .= "📍 Send to: {$primary_data['location']} ({$primary_data['branch_code']})\n\n";
                    $message_text .= "⚠️ Please pack this product and send to the primary showroom for combined delivery.";
                    
                    $subject_text = "Combine & Pack Request - Order #{$order_no} - {$product_name}";
                } else {
                    $message_text = "🚚 **Separate Delivery Request**\n\n";
                    $message_text .= "Please send the following product separately to the customer address:\n\n";
                    $message_text .= "📋 Product: {$product_name}\n";
                    $message_text .= "🆔 Order #: {$order_no}\n";
                    $message_text .= "👤 Customer: {$customer}\n";
                    $message_text .= "📍 Address: {$address}\n\n";
                    $message_text .= "⚠️ Please dispatch this product separately to the customer's delivery address.";
                    
                    $subject_text = "Separate Delivery Request - Order #{$order_no} - {$product_name}";
                }
                
                addNotification($pdo, null, $secondary_branch_id, 'branch', 'message', 
                    $subject_text, 
                    $message_text,
                    "branch_dashboard.php?tab=orders");
            }
        }
        
        // Update total with per-product shipping included
        $total_amount = $total_products + $total_shipping;
        $pdo->prepare("UPDATE shop_orders SET total_amount = ?, shipping_charges = ? WHERE id = ?")->execute([$total_amount, $total_shipping, $order_id]);
        
        $pdo->commit();
        
        $branch_names = [];
        foreach ($assigned_branches as $branch_id => $products) {
            $branch_info = $pdo->prepare("SELECT branch_code, location FROM branch_managers WHERE id = ?");
            $branch_info->execute([$branch_id]);
            $branch = $branch_info->fetch();
            if ($branch) {
                $branch_names[] = $branch['branch_code'] . " (" . $branch['location'] . ")";
            }
        }
        $branch_display = !empty($branch_names) ? implode(", ", $branch_names) : "Default Branch";
        
        $success = "✅ Order #$order_no created successfully!<br>";
        $success .= "<strong>Assigned to:</strong> " . $branch_display;
        if ($has_multiple) {
            $success .= "<br><strong>📦 Delivery Decision:</strong> Products have been assigned with individual delivery decisions.";
            $success .= "<br><strong>📨 Notifications sent to:</strong> Secondary showrooms have been notified.";
        }
        if ($total_shipping > 0) {
            $success .= "<br><strong>💰 Shipping:</strong> Rs. " . number_format($total_shipping, 2) . " applied to Product " . ($shipping_product_index + 1);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Order | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
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
        
        .product-row {
            background: white;
            padding: 15px;
            border-radius: 16px;
            margin-bottom: 15px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
            position: relative;
        }
        .product-row:hover {
            border-color: #0284c7;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .product-row.has-secondary {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .product-row.has-shipping {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .product-row .product-number {
            position: absolute;
            top: -12px;
            left: 15px;
            background: #1e2a3e;
            color: white;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .product-row .line1 {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .product-row .line2 {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .product-row .line3 {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .product-row .field-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 70px;
        }
        .product-row .field-group .field-label {
            font-size: 0.6rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }
        .product-row .field-group .field-label .required {
            color: #dc2626;
            font-weight: 700;
        }
        .product-row .field-group .form-control,
        .product-row .field-group .form-select {
            font-size: 0.85rem;
            padding: 4px 8px;
            height: 34px;
        }
        .product-row .field-group .form-control-sm {
            font-size: 0.8rem;
            padding: 3px 6px;
            height: 30px;
        }
        
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type=number] { 
            -moz-appearance: textfield; 
            appearance: textfield;
        }
        #order_no::-webkit-inner-spin-button, 
        #order_no::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        #order_no { 
            -moz-appearance: textfield; 
            appearance: textfield;
        }
        
        .product-row .after-discount-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 4px 12px;
            height: 34px;
            display: flex;
            align-items: center;
            font-weight: bold;
            color: #059669;
            font-size: 0.9rem;
            min-width: 70px;
            justify-content: center;
        }
        .product-row .remove-product {
            cursor: pointer;
            color: #dc2626;
            padding: 6px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-size: 1.1rem;
            border-radius: 50%;
            background: #fef2f2;
            height: 34px;
            width: 34px;
        }
        .product-row .remove-product:hover { 
            color: #b91c1c; 
            background: #fee2e2;
            transform: scale(1.1);
        }
        
        .product-row .image-preview { 
            max-width: 50px; 
            max-height: 50px; 
            border-radius: 6px; 
            border: 2px solid #e2e8f0; 
            object-fit: cover; 
            background: white;
        }
        .product-row .image-preview-container {
            min-height: 50px;
            display: flex;
            align-items: center;
        }
        
        .product-row .secondary-section { 
            background: #fef3c7; 
            padding: 6px 10px; 
            border-radius: 8px; 
            margin-top: 4px; 
            border: 1px dashed #f59e0b; 
            display: block;
        }
        
        .product-row .delivery-section {
            background: #dbeafe;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 4px;
            border: 1px solid #0284c7;
            display: none;
        }
        .product-row .delivery-section.show { display: block; }
        
        .form-card { background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .form-card-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px 25px; }
        .form-card-body { padding: 25px; }
        
        .section-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
        .section-title i { color: #0284c7; margin-right: 10px; }
        
        .totals-section { background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border-radius: 16px; padding: 20px; margin-top: 20px; }
        .btn-create { background: linear-gradient(135deg, #0284c7, #0369a1); color: white; border: none; padding: 12px 35px; border-radius: 50px; font-weight: 600; transition: all 0.3s; }
        .btn-create:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-add-product { background: #0284c7; color: white; border: none; padding: 6px 18px; border-radius: 30px; transition: all 0.3s; font-size: 0.85rem; }
        .btn-add-product:hover { background: #0369a1; transform: translateY(-1px); }
        
        .form-control:focus, .form-select:focus { border-color: #0284c7; box-shadow: 0 0 0 0.2rem rgba(2, 132, 199, 0.25); }
        .form-label { font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: #334155; }
        .required-star { color: #dc2626; }
        
        .checkbox-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .send-to-info {
            background: #dcfce7;
            border-left: 4px solid #22c55e;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 5px;
            font-size: 0.8rem;
        }
        .send-to-info i {
            color: #16a34a;
        }
        
        .branch-note {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }
        
        .product-total-box {
            background: #dbeafe;
            border-radius: 8px;
            padding: 4px 12px;
            height: 34px;
            display: flex;
            align-items: center;
            font-weight: bold;
            color: #1e40af;
            font-size: 0.9rem;
            min-width: 90px;
            justify-content: center;
        }
        
        .shipping-radio-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            padding: 10px 0;
        }
        .shipping-radio-group .form-check {
            margin: 0;
        }
        .shipping-radio-group .form-check-label {
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
        }
        .shipping-radio-group .form-check-input:checked + .form-check-label {
            color: #16a34a;
            font-weight: 600;
        }
        
        .shipping-charge-input {
            max-width: 200px;
            display: inline-block;
        }
        
        .shipping-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .shipping-badge.applied {
            background: #dcfce7;
            color: #166534;
        }
        .shipping-badge.not-applied {
            background: #f1f5f9;
            color: #64748b;
        }
        
        @media (max-width: 768px) { 
            .sidebar { width: 240px; } 
            .main-content { margin-left: 240px; padding: 15px; }
            .product-row .line1, .product-row .line2, .product-row .line3 {
                flex-direction: column;
            }
            .product-row .field-group {
                min-width: 100%;
            }
        }
    </style>
    <script>
        function autoFillRemarkId() { 
            var orderNo = document.getElementById('order_no').value;
            if(orderNo) {
                document.getElementById('remark_id').value = '#' + orderNo;
            } else {
                document.getElementById('remark_id').value = '';
            }
        }
        
        function recalcTotal(){
            let totalProducts = 0;
            let totalShipping = 0;
            let shippingAmount = parseFloat($("#shipping_charge_input").val()) || 0;
            let selectedProduct = $('input[name="shipping_product"]:checked').val();
            
            $(".product-row").each(function(index){
                let price = parseFloat($(this).find(".price").val()) || 0;
                let disc = parseFloat($(this).find(".discount").val()) || 0;
                let after = price - (price * disc / 100);
                $(this).find(".after-discount-box").text(after.toFixed(2));
                totalProducts += after;
                
                // Check if this product is selected for shipping
                let applyShipping = (selectedProduct !== undefined && parseInt(selectedProduct) === index);
                let productShipping = applyShipping ? shippingAmount : 0;
                
                if (applyShipping && shippingAmount > 0) {
                    totalShipping += productShipping;
                    $(this).find(".product-total-box").text((after + productShipping).toFixed(2));
                    $(this).find(".shipping-status").html('<span class="shipping-badge applied"><i class="fas fa-check-circle"></i> + Rs. ' + productShipping.toFixed(2) + ' shipping</span>');
                    $(this).addClass('has-shipping');
                } else {
                    $(this).find(".product-total-box").text(after.toFixed(2));
                    $(this).find(".shipping-status").html('<span class="shipping-badge not-applied">No shipping</span>');
                    $(this).removeClass('has-shipping');
                }
                
                $(this).find('.product-number').text('Product ' + (index + 1));
            });
            
            $("#totalProductsSpan").text(totalProducts.toFixed(2));
            $("#shippingSpan").text(totalShipping.toFixed(2));
            $("#grandTotalSpan").text((totalProducts + totalShipping).toFixed(2));
            
            // Enable/disable radio buttons based on shipping amount
            if (shippingAmount > 0) {
                $('input[name="shipping_product"]').prop('disabled', false);
            } else {
                $('input[name="shipping_product"]').prop('disabled', true);
                $('input[name="shipping_product"]').prop('checked', false);
            }
        }
        
        function previewImageFromUrl(url, container) {
            if(url && url.trim() !== ''){
                var img = new Image();
                img.onload = function(){ 
                    $(container).html('<img src="'+url+'" class="image-preview">'); 
                };
                img.onerror = function(){ 
                    $(container).html('<div class="text-danger small mt-1">Invalid URL</div>'); 
                };
                img.src = url;
            } else { 
                $(container).html(''); 
            }
        }
        
        function updateProductDeliveryInfo(row) {
            var primarySelect = row.find('.primary-branch-select');
            var secondarySelect = row.find('.secondary-branch-select');
            var deliverySection = row.find('.delivery-section');
            var sendToInfo = row.find('.send-to-info');
            var deliveryDecision = row.find('.delivery-decision-radio:checked').val() || 'combine';
            var isChecked = row.find('.has-secondary-checkbox').is(':checked');
            
            var primaryText = primarySelect.find('option:selected').text();
            var secondaryText = secondarySelect.find('option:selected').text();
            
            if (isChecked && secondaryText && secondaryText !== '-- Select --' && secondaryText !== primaryText) {
                deliverySection.addClass('show');
                if (deliveryDecision === 'combine') {
                    sendToInfo.html('<i class="fas fa-boxes me-1"></i> <strong>Send to:</strong> ' + primaryText + ' for combined packing');
                } else {
                    sendToInfo.html('<i class="fas fa-truck me-1"></i> <strong>Send separately</strong> to customer address');
                }
            } else {
                deliverySection.removeClass('show');
            }
        }
        
        function checkSecondaryStatus() {
            var hasSecondary = false;
            $('.has-secondary-checkbox').each(function() {
                var row = $(this).closest('.product-row');
                var secondarySelect = row.find('.secondary-branch-select');
                var primarySelect = row.find('.primary-branch-select');
                var isChecked = $(this).is(':checked');
                var secondaryText = secondarySelect.find('option:selected').text();
                var primaryText = primarySelect.find('option:selected').text();
                
                if (isChecked) {
                    hasSecondary = true;
                    row.addClass('has-secondary');
                    if (secondaryText && secondaryText !== '-- Select --' && secondaryText !== primaryText) {
                        updateProductDeliveryInfo(row);
                    } else {
                        row.find('.delivery-section').removeClass('show');
                    }
                } else {
                    row.removeClass('has-secondary');
                    row.find('.delivery-section').removeClass('show');
                }
            });
        }
        
        $(document).on('change', '.has-secondary-checkbox', function() { 
            checkSecondaryStatus(); 
        });
        
        $(document).on('change', '.primary-branch-select, .secondary-branch-select, .delivery-decision-radio', function() {
            var row = $(this).closest('.product-row');
            updateProductDeliveryInfo(row);
        });
        
        $(document).on('input', '#shipping_charge_input', function() {
            recalcTotal();
        });
        
        $(document).on('change', 'input[name="shipping_product"]', function() {
            recalcTotal();
        });
        
        $(document).on('input', '.product-image-url', function(){ 
            previewImageFromUrl($(this).val(), $(this).closest('.product-row').find('.image-preview-container')); 
        });
        
        $(document).on("input", ".price, .discount", recalcTotal);
        
        $(document).ready(function() {
            recalcTotal();
            setTimeout(function() { checkSecondaryStatus(); }, 100);
        });
        
        let branchOptions = `<?php foreach($branches as $b): ?><option value="<?= $b['id'] ?>"><?= addslashes($b['branch_code']) ?> - <?= addslashes($b['location']) ?></option><?php endforeach; ?>`;
        
        $(document).on("click", "#addProductBtn", function(){
            let productCount = $(".product-row").length;
            let idx = productCount;
            let newRow = `
            <div class="product-row">
                <span class="product-number">Product ${idx+1}</span>
                
                <div class="line1">
                    <div class="field-group" style="min-width:80px; max-width:100px;">
                        <span class="field-label"><i class="fas fa-image"></i> Image</span>
                        <input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="URL" style="height:30px; font-size:0.75rem;">
                        <div class="image-preview-container mt-1"></div>
                    </div>
                    <div class="field-group" style="flex:2; min-width:120px;">
                        <span class="field-label">Name <span class="required">*</span></span>
                        <input name="product_name[]" class="form-control" placeholder="Product name" required>
                    </div>
                    <div class="field-group" style="min-width:90px;">
                        <span class="field-label">Promo</span>
                        <input name="promocode[]" class="form-control" placeholder="Code">
                    </div>
                    <div class="field-group" style="min-width:100px;">
                        <span class="field-label">SKU</span>
                        <input name="sku[]" class="form-control" placeholder="SKU">
                    </div>
                    <div class="field-group" style="min-width:70px;">
                        <span class="field-label">Size</span>
                        <input name="size[]" class="form-control" placeholder="Size">
                    </div>
                </div>
                
                <div class="line2">
                    <div class="field-group" style="min-width:70px;">
                        <span class="field-label">Disc %</span>
                        <input name="discount_percent[]" type="number" step="0.01" class="form-control form-control-sm discount" value="0" placeholder="0">
                    </div>
                    <div class="field-group" style="min-width:80px;">
                        <span class="field-label">Price <span class="required">*</span></span>
                        <input name="price[]" type="number" step="0.01" class="form-control form-control-sm price" placeholder="0.00" required>
                    </div>
                    <div class="field-group" style="min-width:140px;">
                        <span class="field-label">Primary Showroom <span class="required">*</span></span>
                        <select name="primary_branch[]" class="form-select form-select-sm primary-branch-select" required>
                            <option value="">-- Select --</option>
                            ${branchOptions}
                        </select>
                    </div>
                    <div class="field-group" style="min-width:120px;">
                        <span class="field-label">Secondary</span>
                        <div style="display:flex; align-items:center; gap:8px; height:34px;">
                            <div class="form-check" style="margin:0;">
                                <input type="checkbox" class="form-check-input has-secondary-checkbox" name="has_secondary[${idx}]" value="1" style="margin-top:2px;">
                                <label class="form-check-label checkbox-label">Enable</label>
                            </div>
                        </div>
                        <div class="secondary-section">
                            <label class="small text-muted">Secondary Showroom</label>
                            <select name="secondary_branch[${idx}]" class="form-select form-select-sm secondary-branch-select">
                                <option value="">-- Select --</option>
                                ${branchOptions}
                            </select>
                        </div>
                    </div>
                    <div class="field-group" style="min-width:80px;">
                        <span class="field-label">After Disc</span>
                        <div class="after-discount-box">0.00</div>
                    </div>
                    <div class="field-group" style="min-width:40px; max-width:50px;">
                        <span class="field-label">&nbsp;</span>
                        <i class="fas fa-trash remove-product"></i>
                    </div>
                </div>
                
                <div class="line3">
                    <div class="field-group" style="min-width:100%;">
                        <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap; margin-top:5px;">
                            <div class="form-check">
                                <input class="form-check-input shipping-radio" type="radio" name="shipping_product" value="${idx}">
                                <label class="form-check-label" style="font-size:0.85rem; font-weight:500;">
                                    <i class="fas fa-truck text-success"></i> Add shipping to this product
                                </label>
                            </div>
                            <span class="shipping-status"><span class="shipping-badge not-applied">No shipping</span></span>
                        </div>
                        <div class="d-flex align-items-center gap-3 mt-2">
                            <span class="small fw-bold">Product Total (incl. shipping):</span>
                            <span class="product-total-box">0.00</span>
                        </div>
                    </div>
                </div>
                
                <div class="delivery-section">
                    <div class="field-group" style="min-width:100%;">
                        <span class="field-label"><i class="fas fa-truck"></i> Delivery Decision</span>
                        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:5px;">
                            <div class="form-check">
                                <input class="form-check-input delivery-decision-radio" type="radio" name="delivery_decision[${idx}]" value="combine" checked>
                                <label class="form-check-label" style="font-size:0.85rem;">
                                    <strong>Combine & Pack</strong>
                                    <br><small class="text-muted">Send to Primary Showroom</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input delivery-decision-radio" type="radio" name="delivery_decision[${idx}]" value="separate">
                                <label class="form-check-label" style="font-size:0.85rem;">
                                    <strong>Separate Delivery</strong>
                                    <br><small class="text-muted">Send directly to customer</small>
                                </label>
                            </div>
                        </div>
                        <div class="send-to-info mt-2">
                            <i class="fas fa-info-circle"></i> 
                            <span>Select delivery option for this product</span>
                        </div>
                    </div>
                </div>
            </div>`;
            $("#productsContainer").append(newRow);
            recalcTotal();
            setTimeout(function() { checkSecondaryStatus(); }, 100);
        });
        
        $(document).on("click", ".remove-product", function(){ 
            if($(".product-row").length > 1) {
                $(this).closest(".product-row").remove(); 
                recalcTotal();
                $(".product-row").each(function(index) {
                    $(this).find('.product-number').text('Product ' + (index + 1));
                });
                setTimeout(function() { checkSecondaryStatus(); }, 100);
            } else {
                alert("At least one product is required.");
            }
        });
    </script>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link active"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
    <a href="occasions.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Occasions</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="reset_data.php" class="nav-link" style="color: #f87171;"><i class="fas fa-trash-alt"></i> Reset Data</a>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-plus-circle"></i> Create New Order</h2>
    
    <?php if($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i> <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="form-card">
        <div class="form-card-header">
            <h4><i class="fas fa-file-alt me-2"></i> Order Information</h4>
            <small class="opacity-75">Fill in the order details below.</small>
        </div>
        <div class="form-card-body">
            <form method="POST" id="orderForm">
                <!-- Section 1: Customer Information -->
                <h5 class="section-title"><i class="fas fa-user"></i> 1. Customer Information</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Order No <span class="required-star">*</span></label>
                        <input type="number" name="order_no" id="order_no" class="form-control" value="" placeholder="Enter order number" required onkeyup="autoFillRemarkId()" style="-moz-appearance:textfield; appearance:textfield;">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer Name <span class="required-star">*</span></label>
                        <input name="customer_name" class="form-control" placeholder="Full name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contact No <span class="required-star">*</span></label>
                        <input name="contact_no" class="form-control" placeholder="Phone number" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Occasion</label>
                        <select name="occasion" class="form-select">
                            <option value="">-- Select Occasion --</option>
                            <?php foreach($occasions as $occ): ?>
                                <option value="<?= htmlspecialchars($occ['occasion_name']) ?>"><?= htmlspecialchars($occ['occasion_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Address <span class="required-star">*</span></label>
                        <input name="address" class="form-control" placeholder="Delivery address" required>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Section 2: Payment -->
                <h5 class="section-title"><i class="fas fa-credit-card"></i> 2. Payment Details</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Select Payment Mode <span class="required-star">*</span></label>
                        <select name="payment_mode" id="payment_mode_select" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach($payment_modes as $pm): ?>
                                <option value="<?= htmlspecialchars($pm['mode_name']) ?>"><?= htmlspecialchars($pm['mode_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment ID</label>
                        <input type="text" name="payment_id" class="form-control" placeholder="e.g., TXN-12345">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Remark ID</label>
                        <input type="text" name="remark_id" id="remark_id" class="form-control" readonly style="background:#e9ecef; color:#1e293b; font-weight:600;" placeholder="Auto-filled">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shipping Charges (Rs.)</label>
                        <input type="number" step="0.01" name="shipping_charge" id="shipping_charge_input" class="form-control" value="0" placeholder="0.00">
                        <small class="text-muted">Enter shipping amount, then select which product gets it</small>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Section 3: Products -->
                <h5 class="section-title"><i class="fas fa-box"></i> 3. Products</h5>
                
                <div class="branch-note">
                    <i class="fas fa-info-circle"></i> 
                    <strong>How to add shipping to a product:</strong><br>
                    1. Enter the <strong>Shipping Charges</strong> amount in the field above<br>
                    2. Below each product, select the <strong>radio button</strong> for the product that should get the shipping<br>
                    3. Only <strong>one product</strong> can have shipping at a time<br>
                    4. The product total and grand total will update automatically
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-muted small mb-0">
                        <span class="required" style="color:#dc2626;">*</span> Required fields
                    </p>
                    <button type="button" id="addProductBtn" class="btn-add-product"><i class="fas fa-plus"></i> Add Product</button>
                </div>
                
                <div id="productsContainer">
                    <div class="product-row">
                        <span class="product-number">Product 1</span>
                        
                        <div class="line1">
                            <div class="field-group" style="min-width:80px; max-width:100px;">
                                <span class="field-label"><i class="fas fa-image"></i> Image</span>
                                <input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="URL" style="height:30px; font-size:0.75rem;">
                                <div class="image-preview-container mt-1"></div>
                            </div>
                            <div class="field-group" style="flex:2; min-width:120px;">
                                <span class="field-label">Name <span class="required">*</span></span>
                                <input name="product_name[]" class="form-control" placeholder="Product name" required>
                            </div>
                            <div class="field-group" style="min-width:90px;">
                                <span class="field-label">Promo</span>
                                <input name="promocode[]" class="form-control" placeholder="Code">
                            </div>
                            <div class="field-group" style="min-width:100px;">
                                <span class="field-label">SKU</span>
                                <input name="sku[]" class="form-control" placeholder="SKU">
                            </div>
                            <div class="field-group" style="min-width:70px;">
                                <span class="field-label">Size</span>
                                <input name="size[]" class="form-control" placeholder="Size">
                            </div>
                        </div>
                        
                        <div class="line2">
                            <div class="field-group" style="min-width:70px;">
                                <span class="field-label">Disc %</span>
                                <input name="discount_percent[]" type="number" step="0.01" class="form-control form-control-sm discount" value="0" placeholder="0">
                            </div>
                            <div class="field-group" style="min-width:80px;">
                                <span class="field-label">Price <span class="required">*</span></span>
                                <input name="price[]" type="number" step="0.01" class="form-control form-control-sm price" placeholder="0.00" required>
                            </div>
                            <div class="field-group" style="min-width:140px;">
                                <span class="field-label">Primary Showroom <span class="required">*</span></span>
                                <select name="primary_branch[]" class="form-select form-select-sm primary-branch-select" required>
                                    <option value="">-- Select --</option>
                                    <?php foreach($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_code']) ?> - <?= htmlspecialchars($b['location']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field-group" style="min-width:120px;">
                                <span class="field-label">Secondary</span>
                                <div style="display:flex; align-items:center; gap:8px; height:34px;">
                                    <div class="form-check" style="margin:0;">
                                        <input type="checkbox" class="form-check-input has-secondary-checkbox" name="has_secondary[0]" value="1" style="margin-top:2px;">
                                        <label class="form-check-label checkbox-label">Enable</label>
                                    </div>
                                </div>
                                <div class="secondary-section">
                                    <label class="small text-muted">Secondary Showroom</label>
                                    <select name="secondary_branch[0]" class="form-select form-select-sm secondary-branch-select">
                                        <option value="">-- Select --</option>
                                        <?php foreach($branches as $b): ?>
                                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_code']) ?> - <?= htmlspecialchars($b['location']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="field-group" style="min-width:80px;">
                                <span class="field-label">After Disc</span>
                                <div class="after-discount-box">0.00</div>
                            </div>
                            <div class="field-group" style="min-width:40px; max-width:50px;">
                                <span class="field-label">&nbsp;</span>
                                <i class="fas fa-trash remove-product"></i>
                            </div>
                        </div>
                        
                        <div class="line3">
                            <div class="field-group" style="min-width:100%;">
                                <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap; margin-top:5px;">
                                    <div class="form-check">
                                        <input class="form-check-input shipping-radio" type="radio" name="shipping_product" value="0">
                                        <label class="form-check-label" style="font-size:0.85rem; font-weight:500;">
                                            <i class="fas fa-truck text-success"></i> Add shipping to this product
                                        </label>
                                    </div>
                                    <span class="shipping-status"><span class="shipping-badge not-applied">No shipping</span></span>
                                </div>
                                <div class="d-flex align-items-center gap-3 mt-2">
                                    <span class="small fw-bold">Product Total (incl. shipping):</span>
                                    <span class="product-total-box">0.00</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="delivery-section">
                            <div class="field-group" style="min-width:100%;">
                                <span class="field-label"><i class="fas fa-truck"></i> Delivery Decision</span>
                                <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:5px;">
                                    <div class="form-check">
                                        <input class="form-check-input delivery-decision-radio" type="radio" name="delivery_decision[0]" value="combine" checked>
                                        <label class="form-check-label" style="font-size:0.85rem;">
                                            <strong>Combine & Pack</strong>
                                            <br><small class="text-muted">Send to Primary Showroom</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input delivery-decision-radio" type="radio" name="delivery_decision[0]" value="separate">
                                        <label class="form-check-label" style="font-size:0.85rem;">
                                            <strong>Separate Delivery</strong>
                                            <br><small class="text-muted">Send directly to customer</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="send-to-info mt-2">
                                    <i class="fas fa-info-circle"></i> 
                                    <span>Select delivery option for this product</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Totals Section -->
                <div class="totals-section">
                    <div class="row">
                        <div class="col-md-6 offset-md-6">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <th>Total Products:</th>
                                    <td class="text-end">Rs. <span id="totalProductsSpan" class="fw-bold">0.00</span></td>
                                </tr>
                                <tr>
                                    <th>Shipping Charges:</th>
                                    <td class="text-end">Rs. <span id="shippingSpan" class="fw-bold">0.00</span></td>
                                </tr>
                                <tr class="border-top">
                                    <th class="fs-5">Grand Total:</th>
                                    <td class="text-end"><strong class="fs-5 text-success">Rs. <span id="grandTotalSpan">0.00</span></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" name="create_order" class="btn-create">
                        <i class="fas fa-paper-plane me-2"></i> Create Order & Send to Showrooms
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() { 
        document.getElementById('sidebar').classList.toggle('open'); 
        document.querySelector('.sidebar-overlay').classList.toggle('active'); 
    }
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { 
        if(window.innerWidth < 992) toggleSidebar(); 
    }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
</script>
</body>
</html>