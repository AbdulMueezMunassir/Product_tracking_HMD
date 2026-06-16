<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$branches = $pdo->query("SELECT id, branch_code, location, email FROM branch_managers")->fetchAll();

// Get payment modes from database
$payment_modes = $pdo->query("SELECT id, mode_name FROM payment_modes WHERE is_active = 1 ORDER BY mode_name")->fetchAll();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $order_no = $_POST['order_no'];
    $customer = $_POST['customer_name'];
    $address = $_POST['address'];
    $contact = $_POST['contact_no'];
    $remark_id = $_POST['remark_id'];
    $koko = $_POST['koko_online_id'];
    $payment = $_POST['payment_mode'];
    $shipping = floatval($_POST['shipping_charges']);
    
    $product_names = $_POST['product_name'];
    $promocodes = $_POST['promocode'];
    $skus = $_POST['sku'];
    $sizes = $_POST['size'];
    $discounts = $_POST['discount_percent'];
    $prices = $_POST['price'];
    $image_urls = $_POST['image_url'];
    $product_branches = $_POST['product_branch'];
    
    $total_products = 0;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO shop_orders (order_no, customer_name, address, contact_no, remark_id, koko_online_id, payment_mode, shipping_charges, total_amount, branch_id, packing_status, dispatch_status, collection_status, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$order_no, $customer, $address, $contact, $remark_id, $koko, $payment, $shipping, 0, NULL, 'No', 'No', 'No', 'Pending']);
        $order_id = $pdo->lastInsertId();
        
        $insItem = $pdo->prepare("INSERT INTO order_products (order_id, product_name, promocode, sku, size, discount_percent, price, after_discount_price, image_url, assign_showroom) VALUES (?,?,?,?,?,?,?,?,?,?)");
        
        $assigned_branches = [];
        
        for ($i = 0; $i < count($product_names); $i++) {
            $price = floatval($prices[$i]);
            $disc = floatval($discounts[$i]);
            $after = $price - ($price * $disc / 100);
            $total_products += $after;
            $image_url = !empty($image_urls[$i]) ? $image_urls[$i] : null;
            $product_branch = !empty($product_branches[$i]) ? $product_branches[$i] : null;
            
            if (empty($product_branch) && count($branches) > 0) {
                $product_branch = $branches[0]['id'];
            }
            
            $insItem->execute([$order_id, $product_names[$i], $promocodes[$i], $skus[$i], $sizes[$i], $disc, $price, $after, $image_url, $product_branch]);
            
            if ($product_branch) {
                if (!isset($assigned_branches[$product_branch])) {
                    $assigned_branches[$product_branch] = [];
                }
                $assigned_branches[$product_branch][] = $product_names[$i];
            }
        }
        
        $total_amount = $total_products + $shipping;
        $pdo->prepare("UPDATE shop_orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $order_id]);
        
        $pdo->commit();
        $success = "Order #$order_no created successfully!";
        
        // Send email notifications
        foreach ($assigned_branches as $branch_id => $products) {
            $branch_info = $pdo->prepare("SELECT email, location, branch_code FROM branch_managers WHERE id = ?");
            $branch_info->execute([$branch_id]);
            $branch = $branch_info->fetch();
            
            if ($branch && $branch['email']) {
                $product_list = implode(", ", $products);
                $subject = "New Order Assigned - Order #$order_no";
                $body = "<h2>New Order Assigned to Your Showroom</h2>
                         <p><strong>Order #:</strong> $order_no</p>
                         <p><strong>Customer:</strong> $customer</p>
                         <p><strong>Contact:</strong> $contact</p>
                         <p><strong>Products Assigned:</strong> $product_list</p>
                         <p><strong>Total Amount:</strong> Rs. $total_amount</p>";
                sendEmailNotification($branch['email'], $subject, $body);
            }
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

$last_order = $pdo->query("SELECT order_no FROM shop_orders ORDER BY id DESC LIMIT 1")->fetchColumn();
$next_order_no = $last_order ? intval($last_order) + 1 : 1000;
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
        
        .product-row { background: white; padding: 20px; border-radius: 16px; margin-bottom: 15px; border: 1px solid #e2e8f0; transition: all 0.3s; position: relative; }
        .product-row:hover { background: #e0f2fe; border-color: #7dd3fc; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .product-header { font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .remove-product { cursor: pointer; color: #dc2626; margin-top: 30px; display: inline-block; transition: all 0.2s; font-size: 1.2rem; }
        .remove-product:hover { color: #b91c1c; transform: scale(1.1); }
        .after-discount { font-weight: bold; color: #059669; font-size: 1rem; display: flex; align-items: center; height: 38px; background: #ecfdf5; padding: 0 10px; border-radius: 8px; }
        .image-preview { max-width: 60px; max-height: 60px; border-radius: 8px; margin-top: 5px; border: 2px solid #e2e8f0; object-fit: cover; background: white; }
        
        .form-card { background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .form-card-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px 25px; }
        .form-card-body { padding: 25px; }
        
        .totals-section { background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border-radius: 16px; padding: 20px; margin-top: 20px; }
        .btn-create { background: linear-gradient(135deg, #0284c7, #0369a1); color: white; border: none; padding: 12px 35px; border-radius: 50px; font-weight: 600; transition: all 0.3s; }
        .btn-create:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-add-product { background: #0284c7; color: white; border: none; padding: 8px 20px; border-radius: 30px; transition: all 0.3s; }
        .btn-add-product:hover { background: #0369a1; transform: translateY(-1px); }
        
        .form-control:focus, .form-select:focus { border-color: #0284c7; box-shadow: 0 0 0 0.2rem rgba(2, 132, 199, 0.25); }
        .form-label { font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; color: #334155; }
        .required-star { color: #dc2626; }
        .branch-note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 15px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 15px; }
        
        @media (max-width: 768px) { .sidebar { width: 240px; } .main-content { margin-left: 240px; padding: 15px; } }
    </style>
    <script>
        function autoFillRemarkId() { document.getElementById('remark_id').value = document.getElementById('order_no').value; }
        
        function recalcTotal(){
            let total = 0;
            $(".product-row").each(function(){
                let price = parseFloat($(this).find(".price").val()) || 0;
                let disc = parseFloat($(this).find(".discount").val()) || 0;
                let after = price - (price * disc / 100);
                $(this).find(".after-discount").text(after.toFixed(2));
                total += after;
            });
            let shipping = parseFloat($("#shipping").val()) || 0;
            $("#totalProductsSpan").text(total.toFixed(2));
            $("#shippingSpan").text(shipping.toFixed(2));
            $("#grandTotalSpan").text((total + shipping).toFixed(2));
        }
        
        function previewImageFromUrl(url, container) {
            if(url && url.trim() !== ''){
                var img = new Image();
                img.onload = function(){ $(container).html('<img src="'+url+'" class="image-preview">'); };
                img.onerror = function(){ $(container).html('<div class="text-danger small mt-1">Invalid URL</div>'); };
                img.src = url;
            } else { $(container).html(''); }
        }
        
        $(document).ready(function() {
            $(document).on('input', '.product-image-url', function(){ 
                previewImageFromUrl($(this).val(), $(this).closest('.product-row').find('.image-preview-container')); 
            });
            $(document).on("input", ".price, .discount, #shipping", recalcTotal);
            recalcTotal();
        });
        
        let branchOptions = `<?php foreach($branches as $b): ?><option value="<?= $b['id'] ?>"><?= addslashes($b['branch_code']) ?> - <?= addslashes($b['location']) ?></option><?php endforeach; ?>`;
        
        $(document).on("click", "#addProductBtn", function(){
            let newRow = `<div class="product-row">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <div class="product-header"><i class="fas fa-image"></i> Image URL</div>
                        <input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="https://example.com/image.jpg">
                        <div class="image-preview-container mt-1"></div>
                    </div>
                    <div class="col-md-2">
                        <div class="product-header"><i class="fas fa-tag"></i> Product Name</div>
                        <input name="product_name[]" class="form-control" placeholder="e.g., Tshirt">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-ticket-alt"></i> Promocode</div>
                        <input name="promocode[]" class="form-control" placeholder="Code">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-barcode"></i> SKU</div>
                        <input name="sku[]" class="form-control" placeholder="SKU">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-ruler"></i> Size</div>
                        <input name="size[]" class="form-control" placeholder="M, L, XL">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-percent"></i> Discount %</div>
                        <input name="discount_percent[]" type="number" step="0.01" class="form-control discount" value="0" placeholder="0">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-rupee-sign"></i> Price (Rs.)</div>
                        <input name="price[]" type="number" step="0.01" class="form-control price" placeholder="0.00">
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-store"></i> Assign Showroom</div>
                        <select name="product_branch[]" class="form-select"><option value="">Select</option>${branchOptions}</select>
                    </div>
                    <div class="col-md-1">
                        <div class="product-header"><i class="fas fa-calculator"></i> After Discount</div>
                        <div class="after-discount">0.00</div>
                    </div>
                    <div class="col-md-1 text-center">
                        <i class="fas fa-trash remove-product fa-lg"></i>
                    </div>
                </div>
            </div>`;
            $("#productsContainer").append(newRow);
            recalcTotal();
        });
        
        $(document).on("click", ".remove-product", function(){ 
            if($(".product-row").length > 1) $(this).closest(".product-row").remove(); 
            else alert("At least one product required"); 
            recalcTotal(); 
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
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="reset_data.php" class="nav-link" style="color: #f87171;"><i class="fas fa-trash-alt"></i> Reset Data</a>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-plus-circle"></i> Create New Order</h2>
    <?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    
    <div class="form-card">
        <div class="form-card-header"><h4 class="mb-0"><i class="fas fa-file-alt me-2"></i> Order Information</h4></div>
        <div class="form-card-body">
            <div class="branch-note">
                <i class="fas fa-info-circle"></i> 
                <strong>Note:</strong> Each product can be assigned to a different showroom. 
                The branch manager will only see products assigned to their showroom.
            </div>
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Order No <span class="required-star">*</span></label>
                        <input type="number" name="order_no" id="order_no" class="form-control" value="<?= $next_order_no ?>" placeholder="e.g., 1155" required onkeyup="autoFillRemarkId()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer Name <span class="required-star">*</span></label>
                        <input name="customer_name" class="form-control" placeholder="e.g., Indika Ranasinghe" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Remark ID <span class="text-muted">(Auto-filled)</span></label>
                        <input type="text" name="remark_id" id="remark_id" class="form-control" readonly style="background:#e9ecef">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Online ID</label>
                        <input name="koko_online_id" class="form-control" placeholder="e.g., 9869900">
                    </div>
                    <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2" placeholder="Enter full address"></textarea></div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Contact No <span class="required-star">*</span></label>
                        <input name="contact_no" class="form-control" placeholder="e.g., 077 654 7723" required>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Select Payment Mode <span class="required-star">*</span></label>
                        <select name="payment_mode" id="payment_mode_select" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach($payment_modes as $pm): ?>
                                <option value="<?= htmlspecialchars($pm['mode_name']) ?>"><?= htmlspecialchars($pm['mode_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Shipping Charges (Rs.)</label>
                        <input type="number" step="0.01" name="shipping_charges" id="shipping" class="form-control" value="0" placeholder="0.00">
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4><i class="fas fa-box"></i> Products</h4>
                    <button type="button" id="addProductBtn" class="btn-add-product"><i class="fas fa-plus"></i> Add Product</button>
                </div>
                
                <div id="productsContainer">
                    <div class="product-row">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <div class="product-header"><i class="fas fa-image"></i> Image URL</div>
                                <input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="https://example.com/image.jpg">
                                <div class="image-preview-container mt-1"></div>
                            </div>
                            <div class="col-md-2">
                                <div class="product-header"><i class="fas fa-tag"></i> Product Name</div>
                                <input name="product_name[]" class="form-control" placeholder="e.g., Tshirt">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-ticket-alt"></i> Promocode</div>
                                <input name="promocode[]" class="form-control" placeholder="Code">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-barcode"></i> SKU</div>
                                <input name="sku[]" class="form-control" placeholder="SKU">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-ruler"></i> Size</div>
                                <input name="size[]" class="form-control" placeholder="M, L, XL">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-percent"></i> Discount %</div>
                                <input name="discount_percent[]" type="number" step="0.01" class="form-control discount" value="0" placeholder="0">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-rupee-sign"></i> Price (Rs.)</div>
                                <input name="price[]" type="number" step="0.01" class="form-control price" placeholder="0.00">
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-store"></i> Assign Showroom</div>
                                <select name="product_branch[]" class="form-select" required>
                                    <option value="">-- Select Showroom --</option>
                                    <?php foreach($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_code']) ?> - <?= htmlspecialchars($b['location']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <div class="product-header"><i class="fas fa-calculator"></i> After Discount</div>
                                <div class="after-discount">0.00</div>
                            </div>
                            <div class="col-md-1 text-center">
                                <i class="fas fa-trash remove-product fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="totals-section">
                    <div class="row">
                        <div class="col-md-6 offset-md-6">
                            <table class="table table-borderless mb-0">
                                <tr><th>Total Products:</th><td class="text-end">Rs. <span id="totalProductsSpan">0.00</span></td></tr>
                                <tr><th>Shipping Charges:</th><td class="text-end">Rs. <span id="shippingSpan">0.00</span></td></tr>
                                <tr class="border-top"><th class="fs-5">Grand Total:</th><td class="text-end"><strong class="fs-5 text-success">Rs. <span id="grandTotalSpan">0.00</span></strong></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" name="create_order" class="btn-create"><i class="fas fa-paper-plane me-2"></i> Create Order & Send to Showrooms</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { if(window.innerWidth<992) toggleSidebar(); }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
</script>
</body>
</html>