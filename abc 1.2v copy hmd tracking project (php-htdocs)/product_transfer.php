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

$orders = $pdo->query("SELECT id, order_no, customer_name FROM shop_orders ORDER BY id DESC")->fetchAll();

// Handle product transfer request - ONLY ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_transfer'])) {
    if ($role !== 'admin') {
        $error = 'Only Admin can create transfer requests.';
    } else {
        $from_showroom = $_POST['from_showroom'];
        $to_showroom = $_POST['to_showroom'];
        $order_id = $_POST['order_id'];
        $item_description = $_POST['item_description'];
        $sku = $_POST['sku'];
        $nav_document_number = $_POST['nav_document_number'];
        $web_order_number = $_POST['web_order_number'];
        $requestor = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
        
        if ($from_showroom == $to_showroom) {
            $error = 'From showroom and To showroom cannot be the same!';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO product_transfers (requestor, from_showroom, to_showroom, order_id, item_description, sku, nav_document_number, web_order_number, status, created_at) VALUES (?,?,?,?,?,?,?,?, 'pending', NOW())");
                $stmt->execute([$requestor, $from_showroom, $to_showroom, $order_id, $item_description, $sku, $nav_document_number, $web_order_number]);
                $transfer_id = $pdo->lastInsertId();
                
                $from_branch = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
                $from_branch->execute([$from_showroom]);
                $from_data = $from_branch->fetch();
                
                $to_branch = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
                $to_branch->execute([$to_showroom]);
                $to_data = $to_branch->fetch();
                
                $order_info = $pdo->prepare("SELECT order_no FROM shop_orders WHERE id = ?");
                $order_info->execute([$order_id]);
                $order_data = $order_info->fetch();
                $order_no = $order_data ? $order_data['order_no'] : 'N/A';
                
                // Send notification to TO showroom (receiving branch)
                addNotification($pdo, null, $to_showroom, 'branch', 'transfer', 
                    "📦 Product Transfer Request - Action Required", 
                    "You have received a product transfer request from {$from_data['location']}. Order #{$order_no}: {$item_description}. Please login to approve or reject.", 
                    "product_transfer.php");
                
                // Send notification to FROM showroom (sending branch)
                addNotification($pdo, null, $from_showroom, 'branch', 'transfer', 
                    "📦 Product Transfer Request Sent", 
                    "Your product transfer request to {$to_data['location']} has been submitted. Order #{$order_no}: {$item_description}. Waiting for approval.", 
                    "product_transfer.php");
                
                // Send notification to admin
                addNotification($pdo, 1, null, 'admin', 'transfer', 
                    "📦 Product Transfer Request Created", 
                    "Product transfer from {$from_data['location']} to {$to_data['location']} for Order #{$order_no}: {$item_description}", 
                    "product_transfer.php");
                
                // Send email to TO showroom
                if ($to_data && $to_data['email']) {
                    $subject = "Product Transfer Request - Order #" . $order_no;
                    $body = "<h2>Product Transfer Request - Action Required</h2>
                             <p>A product transfer request has been submitted and requires your approval.</p>
                             <p><strong>From:</strong> {$from_data['location']} ({$from_data['branch_code']})</p>
                             <p><strong>To:</strong> {$to_data['location']} ({$to_data['branch_code']})</p>
                             <p><strong>Order #:</strong> " . $order_no . "</p>
                             <p><strong>Item Description:</strong> $item_description</p>
                             <p><strong>SKU:</strong> $sku</p>
                             <p>Please login to the system to approve or reject this request.</p>";
                    sendEmailNotification($to_data['email'], $subject, $body);
                }
                
                $pdo->commit();
                $message = '✅ Product transfer request submitted successfully! The receiving branch has been notified.';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Handle transfer response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_transfer'])) {
    $transfer_id = $_POST['transfer_id'];
    $status = $_POST['status'];
    $response_message = $_POST['response_message'];
    
    try {
        $stmt = $pdo->prepare("SELECT pt.*, o.order_no FROM product_transfers pt LEFT JOIN shop_orders o ON pt.order_id = o.id WHERE pt.id = ?");
        $stmt->execute([$transfer_id]);
        $transfer = $stmt->fetch();
        
        if (!$transfer) {
            throw new Exception("Transfer request not found.");
        }
        
        if ($role === 'branch' && $branch_id != $transfer['to_showroom']) {
            throw new Exception("You are not authorized to respond to this transfer request.");
        }
        
        if ($transfer['status'] != 'pending') {
            throw new Exception("This transfer request has already been processed.");
        }
        
        $stmt = $pdo->prepare("UPDATE product_transfers SET status = ?, response_message = ? WHERE id = ?");
        $stmt->execute([$status, $response_message, $transfer_id]);
        
        $from_branch = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
        $from_branch->execute([$transfer['from_showroom']]);
        $from_data = $from_branch->fetch();
        
        $to_branch = $pdo->prepare("SELECT location, branch_code, email FROM branch_managers WHERE id = ?");
        $to_branch->execute([$transfer['to_showroom']]);
        $to_data = $to_branch->fetch();
        
        $order_no = $transfer['order_no'] ?? 'N/A';
        
        $status_text = $status == 'approved' ? '✅ APPROVED' : '❌ REJECTED';
        addNotification($pdo, null, $transfer['from_showroom'], 'branch', 'transfer', 
            "📦 Product Transfer {$status_text}", 
            "Your product transfer request to {$to_data['location']} has been {$status}. Response: " . ($response_message ?: 'No additional comments.') . " Order #{$order_no}", 
            "product_transfer.php");
        
        addNotification($pdo, 1, null, 'admin', 'transfer', 
            "📦 Product Transfer {$status_text}", 
            "Product transfer from {$from_data['location']} to {$to_data['location']} has been {$status}. Response: " . ($response_message ?: 'No additional comments.'), 
            "product_transfer.php");
        
        if ($from_data && $from_data['email']) {
            $subject = "Product Transfer Request {$status_text}";
            $body = "<h2>Product Transfer Request {$status_text}</h2>
                     <p>Your product transfer request has been {$status}.</p>
                     <p><strong>From:</strong> {$from_data['location']} ({$from_data['branch_code']})</p>
                     <p><strong>To:</strong> {$to_data['location']} ({$to_data['branch_code']})</p>
                     <p><strong>Status:</strong> " . strtoupper($status) . "</p>
                     <p><strong>Response:</strong> " . ($response_message ?: 'No additional comments.') . "</p>";
            sendEmailNotification($from_data['email'], $subject, $body);
        }
        
        $message = "✅ Transfer request {$status} successfully! The requesting branch has been notified.";
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get transfers
if ($role == 'admin') {
    $transfers = $pdo->query("
        SELECT pt.*, 
               f.location as from_location, f.branch_code as from_code, 
               t.location as to_location, t.branch_code as to_code, 
               o.order_no 
        FROM product_transfers pt 
        LEFT JOIN branch_managers f ON pt.from_showroom = f.id 
        LEFT JOIN branch_managers t ON pt.to_showroom = t.id 
        LEFT JOIN shop_orders o ON pt.order_id = o.id 
        ORDER BY pt.created_at DESC
    ")->fetchAll();
} else {
    $transfers = $pdo->prepare("
        SELECT pt.*, 
               f.location as from_location, f.branch_code as from_code, 
               t.location as to_location, t.branch_code as to_code, 
               o.order_no 
        FROM product_transfers pt 
        LEFT JOIN branch_managers f ON pt.from_showroom = f.id 
        LEFT JOIN branch_managers t ON pt.to_showroom = t.id 
        LEFT JOIN shop_orders o ON pt.order_id = o.id 
        WHERE pt.from_showroom = ? OR pt.to_showroom = ? 
        ORDER BY pt.created_at DESC
    ");
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
        .transfer-card { background: white; border-radius: 20px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .transfer-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .form-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .form-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .btn-approve { background: #22c55e; color: white; border: none; padding: 5px 20px; border-radius: 20px; }
        .btn-approve:hover { background: #16a34a; color: white; }
        .btn-reject { background: #dc2626; color: white; border: none; padding: 5px 20px; border-radius: 20px; }
        .btn-reject:hover { background: #b91c1c; color: white; }
        .response-box { background: #f8fafc; border-radius: 8px; padding: 10px 15px; border-left: 3px solid #0284c7; }
        .action-required { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 15px; border-radius: 8px; margin-top: 10px; }
        .admin-only { background: #dbeafe; border-left: 4px solid #0284c7; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; }
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
        <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
        <a href="occasions.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Occasions</a>
        <a href="product_transfer.php" class="nav-link active"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <?php else: ?>
        <a href="branch_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="branch_dashboard.php?tab=messages" class="nav-link"><i class="fas fa-envelope"></i> Messages</a>
        <a href="product_transfer.php" class="nav-link active"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <?php endif; ?>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Guest') ?></small>
    <?php if($role == 'branch'): ?>
        <br><small class="text-secondary"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($_SESSION['branch_location'] ?? '') ?></small>
    <?php endif; ?>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2><i class="fas fa-exchange-alt"></i> Product Transfer</h2><p class="text-muted">Request product transfers between showrooms <?= ($role == 'admin') ? '(Admin only)' : '' ?></p></div>
        <div class="text-muted"><i class="fas fa-calendar"></i> <?= date('l, F j, Y') ?></div>
    </div>
    
    <?php if($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i> <?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <?php if($role == 'admin'): ?>
        <div class="admin-only"><i class="fas fa-info-circle"></i> <strong>Admin Only:</strong> You can create product transfer requests to any branch.</div>
        <div class="form-card">
            <div class="form-header"><h5 class="mb-0"><i class="fas fa-paper-plane"></i> Request Product Transfer</h5></div>
            <div class="p-4">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">From Showroom *</label>
                            <select name="from_showroom" class="form-select" required>
                                <option value="">Select From</option>
                                <?php foreach($showrooms as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= $s['branch_code'] ?> - <?= $s['location'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">To Showroom *</label>
                            <select name="to_showroom" class="form-select" required>
                                <option value="">Select To</option>
                                <?php foreach($showrooms as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= $s['branch_code'] ?> - <?= $s['location'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Order ID *</label>
                            <select name="order_id" class="form-select" required>
                                <option value="">Select Order</option>
                                <?php foreach($orders as $o): ?>
                                    <option value="<?= $o['id'] ?>">#<?= $o['order_no'] ?> - <?= $o['customer_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Item Description</label>
                            <textarea name="item_description" class="form-control" rows="2" placeholder="Describe the product to transfer"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">SKU</label>
                            <input type="text" name="sku" class="form-control" placeholder="Product SKU">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">NAV Document Number</label>
                            <input type="text" name="nav_document_number" class="form-control" placeholder="NAV Document #">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Web Order Number</label>
                            <input type="text" name="web_order_number" class="form-control" placeholder="Web Order #">
                        </div>
                        <div class="col-12">
                            <button type="submit" name="request_transfer" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Transfer Request</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> <strong>Info:</strong> Only Admin can create product transfer requests. You can view and respond to requests sent to your showroom.</div>
    <?php endif; ?>
    
    <h4 class="mt-4"><i class="fas fa-list"></i> Transfer Requests</h4>
    
    <?php if(count($transfers) > 0): ?>
        <?php foreach($transfers as $transfer): 
            $can_respond = false;
            if ($transfer['status'] == 'pending') {
                if ($role == 'admin') {
                    $can_respond = true;
                } elseif ($role == 'branch' && $branch_id == $transfer['to_showroom']) {
                    $can_respond = true;
                }
            }
            $order_no = isset($transfer['order_no']) ? $transfer['order_no'] : 'N/A';
        ?>
        <div class="transfer-card">
            <div class="transfer-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <strong>Request #<?= $transfer['id'] ?></strong> - Order #<?= $order_no ?> 
                        <span class="badge bg-secondary"><?= htmlspecialchars($transfer['from_code'] ?? 'N/A') ?> → <?= htmlspecialchars($transfer['to_code'] ?? 'N/A') ?></span>
                    </div>
                    <span class="status-badge status-<?= $transfer['status'] ?>"><?= ucfirst($transfer['status']) ?></span>
                </div>
            </div>
            <div class="p-3">
                <div class="row">
                    <div class="col-md-3">
                        <small class="text-muted">Requestor:</small>
                        <div><strong><?= htmlspecialchars($transfer['requestor'] ?? 'N/A') ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Date:</small>
                        <div><?= date('d M Y, h:i A', strtotime($transfer['created_at'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted">Item Description:</small>
                        <div><?= nl2br(htmlspecialchars($transfer['item_description'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="col-md-3 mt-2">
                        <small class="text-muted">SKU:</small>
                        <div><?= htmlspecialchars($transfer['sku'] ?: '-') ?></div>
                    </div>
                    <div class="col-md-3 mt-2">
                        <small class="text-muted">NAV Document:</small>
                        <div><?= htmlspecialchars($transfer['nav_document_number'] ?: '-') ?></div>
                    </div>
                    <div class="col-md-3 mt-2">
                        <small class="text-muted">Web Order #:</small>
                        <div><?= htmlspecialchars($transfer['web_order_number'] ?: '-') ?></div>
                    </div>
                    <div class="col-md-3 mt-2">
                        <small class="text-muted">From:</small>
                        <div><strong><?= htmlspecialchars($transfer['from_location'] ?? '') ?></strong></div>
                    </div>
                </div>
                
                <?php if($transfer['status'] == 'pending'): ?>
                    <?php if($can_respond): ?>
                        <div class="action-required mt-3"><i class="fas fa-clock text-warning"></i> <strong>Action Required:</strong> Please respond to this transfer request.</div>
                        <form method="POST" class="mt-3 row g-2">
                            <input type="hidden" name="transfer_id" value="<?= $transfer['id'] ?>">
                            <div class="col-md-7">
                                <input type="text" name="response_message" class="form-control form-control-sm" placeholder="Response message..." required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="respond_transfer" value="approved" class="btn btn-approve w-100" onclick="this.form.status.value='approved'">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="respond_transfer" value="rejected" class="btn btn-reject w-100" onclick="this.form.status.value='rejected'">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                            <input type="hidden" name="status" value="">
                        </form>
                    <?php else: ?>
                        <div class="mt-3 text-muted"><i class="fas fa-hourglass-half"></i> Waiting for <?= htmlspecialchars($transfer['to_location'] ?? 'receiving branch') ?> to respond...</div>
                    <?php endif; ?>
                <?php elseif($transfer['response_message']): ?>
                    <div class="mt-3 response-box"><strong>Response:</strong> <?= nl2br(htmlspecialchars($transfer['response_message'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-3x d-block mb-3"></i><p>No transfer requests found.</p></div>
    <?php endif; ?>
</div>

<script>
    function toggleSidebar() { 
        document.getElementById('sidebar').classList.toggle('open'); 
        document.querySelector('.sidebar-overlay').classList.toggle('active'); 
    }
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { 
        if(window.innerWidth < 577) toggleSidebar(); 
    }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
</script>
</body>
</html>