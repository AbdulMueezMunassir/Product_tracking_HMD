<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$branches = $pdo->query("SELECT id, branch_code, location FROM branch_managers")->fetchAll();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $order_no = $_POST['order_no'];
    $customer = $_POST['customer_name'];
    $address = $_POST['address'];
    $contact = $_POST['contact_no'];
    $remark = $_POST['remark_id'];
    $koko = $_POST['koko_online_id'];
    $payment = $_POST['payment_mode'];
    $shipping = floatval($_POST['shipping_charges']);
    $branch_id = $_POST['branch_id'];
    
    $product_names = $_POST['product_name'];
    $colors = $_POST['color'];
    $skus = $_POST['sku'];
    $sizes = $_POST['size'];
    $discounts = $_POST['discount_percent'];
    $prices = $_POST['price'];
    $image_urls = $_POST['image_url'];
    
    $total_products = 0;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO shop_orders (order_no, customer_name, address, contact_no, remark_id, koko_online_id, payment_mode, shipping_charges, total_amount, branch_id, packing_status, dispatch_status, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$order_no, $customer, $address, $contact, $remark, $koko, $payment, $shipping, 0, $branch_id, 'No', 'No', 'Pending']);
        $order_id = $pdo->lastInsertId();
        
        $insItem = $pdo->prepare("INSERT INTO order_products (order_id, product_name, color, sku, size, discount_percent, price, after_discount_price, image_url) VALUES (?,?,?,?,?,?,?,?,?)");
        
        for ($i = 0; $i < count($product_names); $i++) {
            $price = floatval($prices[$i]);
            $disc = floatval($discounts[$i]);
            $after = $price - ($price * $disc / 100);
            $total_products += $after;
            $image_url = !empty($image_urls[$i]) ? $image_urls[$i] : null;
            $insItem->execute([$order_id, $product_names[$i], $colors[$i], $skus[$i], $sizes[$i], $disc, $price, $after, $image_url]);
        }
        
        $total_amount = $total_products + $shipping;
        $pdo->prepare("UPDATE shop_orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $order_id]);
        
        $pdo->commit();
        $success = "Order #$order_no created successfully!";
        
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
        .sidebar { width: 260px; position: fixed; height: 100vh; background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px; overflow-y: auto; }
        .main-content { margin-left: 260px; padding: 20px; }
        .nav-link { color: #cfdde6; padding: 10px 15px; margin: 5px 0; border-radius: 8px; text-decoration: none; display: block; }
        .nav-link:hover, .nav-link.active { background: #2d3e5a; color: white; }
        .product-row { background: #f9fbfd; padding: 15px; border-radius: 16px; margin-bottom: 15px; border: 1px solid #e2e8f0; transition: all 0.3s; }
        .product-row:hover { background: #e0f2fe; border-color: #7dd3fc; transform: translateY(-2px); }
        .remove-product { cursor: pointer; color: #dc2626; margin-top: 32px; display: inline-block; }
        .after-discount { font-weight: bold; color: #059669; }
        .image-preview { max-width: 60px; max-height: 60px; border-radius: 8px; margin-top: 5px; border: 1px solid #e2e8f0; object-fit: cover; }
        .btn-create { background: #0284c7; color: white; border: none; padding: 12px 35px; border-radius: 30px; font-weight: 600; }
        .btn-create:hover { background: #0369a1; }
        @media (max-width: 768px) { .sidebar { width: 220px; } .main-content { margin-left: 220px; } }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small>Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link active"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr><small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-plus-circle"></i> Create New Order</h2>
    <?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    
    <div class="card"><div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label fw-bold">Order No *</label><input name="order_no" class="form-control" placeholder="e.g., 1155" required></div>
                <div class="col-md-6"><label class="form-label fw-bold">Customer Name *</label><input name="customer_name" class="form-control" placeholder="e.g., Indika Ranasinghe" required></div>
                <div class="col-12"><label class="form-label fw-bold">Address</label><textarea name="address" class="form-control" rows="2" placeholder="Enter full address"></textarea></div>
                <div class="col-md-4"><label class="form-label fw-bold">Contact No</label><input name="contact_no" class="form-control" placeholder="e.g., 077 654 7723"></div>
                <div class="col-md-4"><label class="form-label fw-bold">Remark ID</label><input name="remark_id" class="form-control" placeholder="e.g., 1155"></div>
                <div class="col-md-4"><label class="form-label fw-bold">KOKO Online ID</label><input name="koko_online_id" class="form-control" placeholder="e.g., 9869900"></div>
                <div class="col-md-4"><label class="form-label fw-bold">Payment Mode</label><input name="payment_mode" class="form-control" placeholder="e.g., KOKO, Cash"></div>
                <div class="col-md-4"><label class="form-label fw-bold">Shipping Charges (Rs.)</label><input type="number" step="0.01" name="shipping_charges" id="shipping" class="form-control" value="350"></div>
                <div class="col-md-4"><label class="form-label fw-bold">Assign Branch *</label><select name="branch_id" class="form-select" required><option value="">Select Branch</option><?php foreach($branches as $b): ?><option value="<?= $b['id'] ?>"><?= $b['branch_code'] ?> - <?= $b['location'] ?></option><?php endforeach; ?></select></div>
            </div>
            
            <hr class="my-4">
            <div class="d-flex justify-content-between align-items-center"><h4><i class="fas fa-box"></i> Products</h4><button type="button" id="addProductBtn" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add Product</button></div>
            
            <div id="productsContainer">
                <div class="product-row row g-2 align-items-end">
                    <div class="col-md-2"><label class="small fw-bold">Image URL</label><input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="https://example.com/image.jpg"><div class="image-preview-container"></div></div>
                    <div class="col-md-2"><label class="small fw-bold">Product Name</label><input name="product_name[]" class="form-control" placeholder="Product name"></div>
                    <div class="col-md-1"><label class="small fw-bold">Color</label><input name="color[]" class="form-control" placeholder="Color"></div>
                    <div class="col-md-2"><label class="small fw-bold">SKU</label><input name="sku[]" class="form-control" placeholder="SKU"></div>
                    <div class="col-md-1"><label class="small fw-bold">Size</label><input name="size[]" class="form-control" placeholder="M, L, XL"></div>
                    <div class="col-md-1"><label class="small fw-bold">Discount %</label><input name="discount_percent[]" type="number" step="0.01" class="form-control discount" value="0"></div>
                    <div class="col-md-1"><label class="small fw-bold">Price (Rs.)</label><input name="price[]" type="number" step="0.01" class="form-control price"></div>
                    <div class="col-md-1"><span class="after-discount">0.00</span></div>
                    <div class="col-md-1 text-center"><i class="fas fa-trash remove-product fa-lg"></i></div>
                </div>
            </div>
            
            <div class="mt-3 bg-light p-3 rounded"><strong>Total Products: Rs. <span id="totalProductsSpan">0.00</span></strong><br><strong class="fs-5">Grand Total: Rs. <span id="grandTotalSpan">0.00</span></strong></div>
            <div class="text-center mt-4"><button type="submit" name="create_order" class="btn-create"><i class="fas fa-paper-plane"></i> Create Order & Send to Showroom</button></div>
        </form>
    </div></div>
</div>

<script>
    function previewImageFromUrl(url, container) {
        if(url && url.trim() !== ''){
            var img = new Image();
            img.onload = function(){ $(container).html('<img src="'+url+'" class="image-preview" style="max-width:60px; max-height:60px; border-radius:8px;">'); };
            img.onerror = function(){ $(container).html('<div class="text-danger small">Invalid URL</div>'); };
            img.src = url;
        } else { $(container).html(''); }
    }
    $(document).on('input', '.product-image-url', function(){ previewImageFromUrl($(this).val(), $(this).closest('.product-row').find('.image-preview-container')); });
    
    function recalcTotal(){
        let total=0;
        $(".product-row").each(function(){
            let price=parseFloat($(this).find(".price").val())||0;
            let disc=parseFloat($(this).find(".discount").val())||0;
            let after=price-(price*disc/100);
            $(this).find(".after-discount").text(after.toFixed(2));
            total+=after;
        });
        let shipping=parseFloat($("#shipping").val())||0;
        $("#totalProductsSpan").text(total.toFixed(2));
        $("#grandTotalSpan").text((total+shipping).toFixed(2));
    }
    $(document).on("input", ".price, .discount, #shipping", recalcTotal);
    
    $("#addProductBtn").click(function(){
        let newRow=`<div class="product-row row g-2 align-items-end mt-3">
            <div class="col-md-2"><label class="small fw-bold">Image URL</label><input type="url" name="image_url[]" class="form-control form-control-sm product-image-url" placeholder="https://example.com/image.jpg"><div class="image-preview-container"></div></div>
            <div class="col-md-2"><label class="small fw-bold">Product Name</label><input name="product_name[]" class="form-control" placeholder="Product name"></div>
            <div class="col-md-1"><label class="small fw-bold">Color</label><input name="color[]" class="form-control" placeholder="Color"></div>
            <div class="col-md-2"><label class="small fw-bold">SKU</label><input name="sku[]" class="form-control" placeholder="SKU"></div>
            <div class="col-md-1"><label class="small fw-bold">Size</label><input name="size[]" class="form-control" placeholder="M, L, XL"></div>
            <div class="col-md-1"><label class="small fw-bold">Discount %</label><input name="discount_percent[]" type="number" step="0.01" class="form-control discount" value="0"></div>
            <div class="col-md-1"><label class="small fw-bold">Price (Rs.)</label><input name="price[]" type="number" step="0.01" class="form-control price"></div>
            <div class="col-md-1"><span class="after-discount">0.00</span></div>
            <div class="col-md-1 text-center"><i class="fas fa-trash remove-product fa-lg"></i></div>
        </div>`;
        $("#productsContainer").append(newRow);
        recalcTotal();
    });
    $(document).on("click", ".remove-product", function(){ if($(".product-row").length>1) $(this).closest(".product-row").remove(); else alert("At least one product required"); recalcTotal(); });
    recalcTotal();
</script>
</body>
</html>