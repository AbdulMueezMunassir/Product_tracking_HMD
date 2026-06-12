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

$showrooms = $pdo->query("SELECT id, branch_code, location FROM branch_managers ORDER BY location")->fetchAll();

// Handle product transfer request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_transfer'])) {
    $from_showroom = $_POST['from_showroom'];
    $to_showroom = $_POST['to_showroom'];
    $item_description = $_POST['item_description'];
    $sku = $_POST['sku'];
    $nav_document_number = $_POST['nav_document_number'];
    $web_order_number = $_POST['web_order_number'];
    $requestor = $_SESSION['username'];
    
    if ($from_showroom == $to_showroom) {
        $error = 'From showroom and To showroom cannot be the same!';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO product_transfers (requestor, from_showroom, to_showroom, item_description, sku, nav_document_number, web_order_number, status) VALUES (?,?,?,?,?,?,?, 'pending')");
            $stmt->execute([$requestor, $from_showroom, $to_showroom, $item_description, $sku, $nav_document_number, $web_order_number]);
            $message = 'Product transfer request submitted successfully!';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle message sending - FIXED for admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $to_showroom = $_POST['to_showroom_msg'];
    $subject = $_POST['subject'];
    $message_text = $_POST['message_text'];
    
    if ($role == 'admin') {
        $from_showroom = null;
    } else {
        $from_showroom = $branch_id;
    }
    
    try {
        $check = $pdo->prepare("SELECT id FROM branch_managers WHERE id = ?");
        $check->execute([$to_showroom]);
        if (!$check->fetch()) {
            $error = 'Selected branch does not exist!';
        } else {
            $stmt = $pdo->prepare("INSERT INTO showroom_messages (from_showroom, to_showroom, subject, message) VALUES (?,?,?,?)");
            $stmt->execute([$from_showroom, $to_showroom, $subject, $message_text]);
            $message = 'Message sent successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Update transfer status (Admin only)
if ($role == 'admin' && isset($_POST['update_transfer_status'])) {
    $transfer_id = $_POST['transfer_id'];
    $new_status = $_POST['new_status'];
    $stmt = $pdo->prepare("UPDATE product_transfers SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $transfer_id]);
    $message = "Transfer status updated to $new_status!";
}

// Get transfers
if ($role == 'admin') {
    $transfers = $pdo->query("SELECT pt.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code FROM product_transfers pt LEFT JOIN branch_managers f ON pt.from_showroom = f.id LEFT JOIN branch_managers t ON pt.to_showroom = t.id ORDER BY pt.created_at DESC")->fetchAll();
} else {
    $transfers = $pdo->prepare("SELECT pt.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code FROM product_transfers pt LEFT JOIN branch_managers f ON pt.from_showroom = f.id LEFT JOIN branch_managers t ON pt.to_showroom = t.id WHERE pt.from_showroom = ? OR pt.to_showroom = ? ORDER BY pt.created_at DESC");
    $transfers->execute([$branch_id, $branch_id]);
    $transfers = $transfers->fetchAll();
}

// Get messages
if ($role == 'admin') {
    $messages = $pdo->query("SELECT sm.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code FROM showroom_messages sm LEFT JOIN branch_managers f ON sm.from_showroom = f.id LEFT JOIN branch_managers t ON sm.to_showroom = t.id ORDER BY sm.created_at DESC")->fetchAll();
} else {
    $messages = $pdo->prepare("SELECT sm.*, f.location as from_location, f.branch_code as from_code, t.location as to_location, t.branch_code as to_code FROM showroom_messages sm LEFT JOIN branch_managers f ON sm.from_showroom = f.id LEFT JOIN branch_managers t ON sm.to_showroom = t.id WHERE sm.from_showroom = ? OR sm.to_showroom = ? ORDER BY sm.created_at DESC");
    $messages->execute([$branch_id, $branch_id]);
    $messages = $messages->fetchAll();
    $pdo->prepare("UPDATE showroom_messages SET is_read = '1' WHERE to_showroom = ? AND is_read = '0'")->execute([$branch_id]);
}

$pending_request_count = 0;
if ($role == 'branch') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_requests WHERE supplying_branch = ? AND status = 'pending'");
    $stmt->execute([$branch_id]);
    $pending_request_count = $stmt->fetchColumn();
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
        
        .transfer-card, .message-card { background: white; border-radius: 20px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .transfer-header, .message-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .message-unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        
        .form-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .form-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .badge-notify { background: #ef4444; color: white; border-radius: 50%; padding: 2px 8px; font-size: 11px; margin-left: 8px; }
        
        @media (max-width: 768px) { .sidebar { width: 240px; } .main-content { margin-left: 240px; padding: 15px; } }
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
    <?php else: ?>
        <a href="branch_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="product_transfer.php" class="nav-link active"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
        <?php if($pending_request_count > 0): ?><span class="badge-notify"><?= $pending_request_count ?></span><?php endif; ?>
    <?php endif; ?>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2><i class="fas fa-exchange-alt"></i> Product Transfer</h2><p class="text-muted">Request product transfers between showrooms and communicate with other branches</p></div>
        <div class="text-muted"><i class="fas fa-calendar"></i> <?= date('l, F j, Y') ?></div>
    </div>
    
    <?php if($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    
    <div class="form-card">
        <div class="form-header"><h5 class="mb-0"><i class="fas fa-paper-plane"></i> Request Product Transfer</h5></div>
        <div class="p-4">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-bold">From Showroom *</label><select name="from_showroom" class="form-select" required><option value="">Select From Showroom</option><?php foreach($showrooms as $s): ?><option value="<?= $s['id'] ?>" <?= ($role != 'admin' && $branch_id == $s['id']) ? 'selected' : '' ?>><?= $s['branch_code'] ?> - <?= $s['location'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-bold">To Showroom *</label><select name="to_showroom" class="form-select" required><option value="">Select To Showroom</option><?php foreach($showrooms as $s): ?><option value="<?= $s['id'] ?>"><?= $s['branch_code'] ?> - <?= $s['location'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label fw-bold">Item Description</label><textarea name="item_description" class="form-control" rows="3" placeholder="Describe the product to transfer"></textarea></div>
                    <div class="col-md-4"><label class="form-label fw-bold">SKU</label><input type="text" name="sku" class="form-control" placeholder="Product SKU"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">NAV Document Number</label><input type="text" name="nav_document_number" class="form-control" placeholder="NAV Document #"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Web Order Number</label><input type="text" name="web_order_number" class="form-control" placeholder="Web Order #"></div>
                    <div class="col-12"><button type="submit" name="request_transfer" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Transfer Request</button></div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="form-card">
        <div class="form-header"><h5 class="mb-0"><i class="fas fa-comment"></i> Send Message to Showroom</h5></div>
        <div class="p-4">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-bold">To Showroom</label><select name="to_showroom_msg" class="form-select" required><option value="">Select Showroom</option><?php foreach($showrooms as $s): if($role != 'admin' && $branch_id == $s['id']) continue; ?><option value="<?= $s['id'] ?>"><?= $s['branch_code'] ?> - <?= $s['location'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Subject</label><input type="text" name="subject" class="form-control" placeholder="Message subject"></div>
                    <div class="col-12"><label class="form-label fw-bold">Message</label><textarea name="message_text" class="form-control" rows="3" placeholder="Type your message here..." required></textarea></div>
                    <div class="col-12"><button type="submit" name="send_message" class="btn btn-success"><i class="fas fa-envelope"></i> Send Message</button></div>
                </div>
            </form>
        </div>
    </div>
    
    <h4 class="mt-4"><i class="fas fa-list"></i> Transfer Requests</h4>
    <?php foreach($transfers as $transfer): ?>
    <div class="transfer-card">
        <div class="transfer-header"><div class="d-flex justify-content-between"><div><strong>Request #<?= $transfer['id'] ?></strong> - <?= $transfer['from_code'] ?> → <?= $transfer['to_code'] ?></div><span class="status-badge status-<?= $transfer['status'] ?>"><?= ucfirst($transfer['status']) ?></span></div></div>
        <div class="p-3"><div class="row"><div class="col-md-6"><small>Requestor:</small><div><strong><?= htmlspecialchars($transfer['requestor']) ?></strong></div></div><div class="col-md-6"><small>Date:</small><div><?= date('d M Y, h:i A', strtotime($transfer['created_at'])) ?></div></div><div class="col-12 mt-2"><small>Item Description:</small><div><?= nl2br(htmlspecialchars($transfer['item_description'])) ?></div></div><div class="col-md-4 mt-2"><small>SKU:</small><div><?= htmlspecialchars($transfer['sku'] ?: '-') ?></div></div><div class="col-md-4 mt-2"><small>NAV Document:</small><div><?= htmlspecialchars($transfer['nav_document_number'] ?: '-') ?></div></div><div class="col-md-4 mt-2"><small>Web Order #:</small><div><?= htmlspecialchars($transfer['web_order_number'] ?: '-') ?></div></div>
        <?php if($role == 'admin'): ?>
        <div class="col-12 mt-3"><form method="POST" class="d-flex gap-2"><input type="hidden" name="transfer_id" value="<?= $transfer['id'] ?>"><select name="new_status" class="form-select w-auto"><option value="pending" <?= $transfer['status'] == 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $transfer['status'] == 'approved' ? 'selected' : '' ?>>Approved</option><option value="completed" <?= $transfer['status'] == 'completed' ? 'selected' : '' ?>>Completed</option><option value="rejected" <?= $transfer['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option></select><button type="submit" name="update_transfer_status" class="btn btn-sm btn-primary">Update</button></form></div>
        <?php endif; ?></div></div>
    </div>
    <?php endforeach; ?>
    
    <h4 class="mt-4"><i class="fas fa-comments"></i> Showroom Messages</h4>
    <?php foreach($messages as $msg): ?>
    <div class="message-card <?= ($msg['is_read'] == '0' && $role != 'admin') ? 'message-unread' : '' ?>">
        <div class="message-header"><div class="d-flex justify-content-between"><div><i class="fas fa-envelope"></i> <?= htmlspecialchars($msg['subject'] ?: 'No Subject') ?></div><div class="small"><?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></div></div></div>
        <div class="p-3"><div class="row"><div class="col-md-6"><small>From:</small><div><strong><?= $msg['from_showroom'] == 0 ? 'Admin' : ($msg['from_code'] . ' - ' . $msg['from_location']) ?></strong></div></div><div class="col-md-6"><small>To:</small><div><strong><?= $msg['to_code'] . ' - ' . $msg['to_location'] ?></strong></div></div><div class="col-12 mt-2"><small>Message:</small><div><?= nl2br(htmlspecialchars($msg['message'])) ?></div></div></div></div>
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