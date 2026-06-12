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
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'orders';
$status_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

if (!isset($_SESSION['branch_last_notification_check'])) {
    $_SESSION['branch_last_notification_check'] = time();
}

// Display success message
if (isset($_SESSION['branch_success_message'])) {
    $message = '<div class="alert alert-success alert-dismissible fade show">' . $_SESSION['branch_success_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    unset($_SESSION['branch_success_message']);
}

// Helper function to add notification
function addNotification($pdo, $user_id, $branch_id, $user_role, $type, $title, $msg, $link) {
    $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, branch_id, user_role, notification_type, title, message, link) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$user_id, $branch_id, $user_role, $type, $title, $msg, $link]);
}

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message_action'])) {
    $to_type = $_POST['to_type'];
    $subject = $_POST['subject'];
    $message_text = $_POST['message_text'];
    
    if ($to_type == 'admin') {
        addNotification($pdo, 1, null, 'admin', 'message', "Message from " . $_SESSION['branch_location'] . ": " . $subject, $message_text, "admin_dashboard.php");
        $message = '<div class="alert alert-success">Message sent to Admin!</div>';
    } else {
        $to_branch = $_POST['to_branch'];
        addNotification($pdo, null, $to_branch, 'branch', 'message', "Message from " . $_SESSION['branch_location'] . ": " . $subject, $message_text, "branch_dashboard.php");
        $message = '<div class="alert alert-success">Message sent to branch!</div>';
    }
}

// Handle product request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_product'])) {
    $order_id = $_POST['order_id'];
    $product_id = $_POST['product_id'];
    $product_name = $_POST['product_name'];
    $sku = $_POST['sku'];
    $size = $_POST['size'];
    $supplying_branch = $_POST['supplying_branch'];
    $request_message = $_POST['request_message'];
    
    $stmt = $pdo->prepare("INSERT INTO product_requests (requesting_branch, supplying_branch, order_id, product_id, product_name, sku, size, request_message, status) VALUES (?,?,?,?,?,?,?,?, 'pending')");
    $stmt->execute([$branch_id, $supplying_branch, $order_id, $product_id, $product_name, $sku, $size, $request_message]);
    
    $stmt = $pdo->prepare("SELECT * FROM branch_managers WHERE id = ?");
    $stmt->execute([$supplying_branch]);
    $supply_branch = $stmt->fetch();
    
    addNotification($pdo, null, $supplying_branch, 'branch', 'request', "Product Request from " . $_SESSION['branch_location'], "Request for product: $product_name (Order #$order_id): $request_message", "branch_dashboard.php?tab=requests");
    $message = '<div class="alert alert-success">Product request sent!</div>';
}

// Handle request response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_request'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $response_message = $_POST['response_message'];
    
    $status = ($action == 'accept') ? 'accepted' : 'rejected';
    $stmt = $pdo->prepare("UPDATE product_requests SET status = ?, response_message = ? WHERE id = ? AND supplying_branch = ?");
    $stmt->execute([$status, $response_message, $request_id, $branch_id]);
    
    $stmt = $pdo->prepare("SELECT * FROM product_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    
    addNotification($pdo, null, $req['requesting_branch'], 'branch', 'request', "Product Request " . ucfirst($status), "Your request for {$req['product_name']} has been $status. Response: $response_message", "branch_dashboard.php?tab=requests");
    $message = '<div class="alert alert-success">Request ' . $status . '!</div>';
}

// Get selected order details
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

// Update order status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $order_id = $_POST['order_id'];
    $collection_status = $_POST['collection_status'];
    $packing_status = $_POST['packing_status'];
    $dispatch_status = $_POST['dispatch_status'];
    $product_availability = $_POST['product_availability'];
    
    $stmt = $pdo->prepare("SELECT order_no FROM shop_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();
    $order_no = $order_data['order_no'];
    
    // Determine workflow status
    if ($product_availability == 'not_available') {
        $workflow_status = 'not_collected';
        $collection_status = 'No'; $packing_status = 'No'; $dispatch_status = 'No';
    } elseif ($product_availability == 'partial') {
        if ($collection_status == 'Yes' && $packing_status == 'Yes' && $dispatch_status == 'Yes') { $workflow_status = 'dispatching';
        } elseif ($collection_status == 'Yes' && $packing_status == 'Yes') { $workflow_status = 'packing';
        } elseif ($collection_status == 'Yes') { $workflow_status = 'collecting';
        } else { $workflow_status = 'not_collected'; }
    } else {
        if ($collection_status == 'No') { $workflow_status = 'not_collected'; $packing_status = 'No'; $dispatch_status = 'No';
        } elseif ($collection_status == 'Yes' && $packing_status == 'No') { $workflow_status = 'collecting';
        } elseif ($packing_status == 'Yes' && $dispatch_status == 'No') { $workflow_status = 'packing';
        } elseif ($dispatch_status == 'Yes') { $workflow_status = 'dispatching';
        } else { $workflow_status = 'not_collected'; }
    }
    
    $invoice = $_POST['invoice_no'];
    $branch_comment = $_POST['branch_comment'];
    $barcode_number = $_POST['barcode_number'];
    $unavailability_reason = $_POST['unavailability_reason'] ?? null;
    $partial_comment = $_POST['partial_comment'] ?? null;
    $collection_notes = $_POST['collection_notes'];
    
    // Update per-product availability
    $product_ids = $_POST['product_id'] ?? [];
    $product_availabilities = $_POST['product_avail'] ?? [];
    $product_reasons = $_POST['product_reason'] ?? [];
    $updateProduct = $pdo->prepare("UPDATE order_products SET product_availability = ?, product_availability_reason = ? WHERE id = ?");
    for ($i = 0; $i < count($product_ids); $i++) {
        $updateProduct->execute([$product_availabilities[$i], $product_reasons[$i], $product_ids[$i]]);
    }
    
    $stmt = $pdo->prepare("UPDATE shop_orders SET packing_status=?, dispatch_status=?, collection_status=?, collection_notes=?, invoice_no=?, branch_comment=?, barcode_number=?, product_availability=?, unavailability_reason=?, partial_comment=?, workflow_status=?, last_status_update=NOW(), status='Confirmed', updated_at=NOW() WHERE id=? AND branch_id=?");
    $stmt->execute([$packing_status, $dispatch_status, $collection_status, $collection_notes, $invoice, $branch_comment, $barcode_number, $product_availability, $unavailability_reason, $partial_comment, $workflow_status, $order_id, $branch_id]);
    
    addNotification($pdo, 1, null, 'admin', 'order_update', "Order #{$order_no} Status Updated", "Branch updated order to: " . ucfirst(str_replace('_', ' ', $workflow_status)), "all_orders.php");
    $_SESSION['branch_success_message'] = "Order #{$order_no} updated! Status: " . ucfirst(str_replace('_', ' ', $workflow_status));
    header('Location: branch_dashboard.php?tab=orders&filter=' . $status_filter);
    exit;
}

// Get all data
$received_requests = $pdo->prepare("SELECT pr.*, o.order_no, o.customer_name, b.location as requesting_location, b.branch_code as requesting_code FROM product_requests pr LEFT JOIN shop_orders o ON pr.order_id = o.id LEFT JOIN branch_managers b ON pr.requesting_branch = b.id WHERE pr.supplying_branch = ? AND pr.status = 'pending' ORDER BY pr.created_at DESC");
$received_requests->execute([$branch_id]);
$received_requests = $received_requests->fetchAll();

$sent_requests = $pdo->prepare("SELECT pr.*, o.order_no, o.customer_name, b.location as supplying_location, b.branch_code as supplying_code FROM product_requests pr LEFT JOIN shop_orders o ON pr.order_id = o.id LEFT JOIN branch_managers b ON pr.supplying_branch = b.id WHERE pr.requesting_branch = ? ORDER BY pr.created_at DESC");
$sent_requests->execute([$branch_id]);
$sent_requests = $sent_requests->fetchAll();

$all_branches = $pdo->prepare("SELECT id, branch_code, location FROM branch_managers WHERE id != ?");
$all_branches->execute([$branch_id]);
$all_branches = $all_branches->fetchAll();

$notifications = $pdo->prepare("SELECT * FROM system_notifications WHERE branch_id = ? AND is_read = 0 ORDER BY created_at DESC");
$notifications->execute([$branch_id]);
$notification_list = $notifications->fetchAll();
$notification_count = count($notification_list);

$total_orders = $pdo->prepare("SELECT COUNT(*) FROM shop_orders WHERE branch_id = ?");
$total_orders->execute([$branch_id]);
$total_orders = $total_orders->fetchColumn();

$stats = $pdo->prepare("SELECT SUM(CASE WHEN workflow_status = 'not_collected' OR workflow_status IS NULL THEN 1 ELSE 0 END) as not_collected, SUM(CASE WHEN workflow_status = 'collecting' THEN 1 ELSE 0 END) as collecting, SUM(CASE WHEN workflow_status = 'packing' THEN 1 ELSE 0 END) as packing, SUM(CASE WHEN workflow_status = 'dispatching' THEN 1 ELSE 0 END) as dispatching, SUM(CASE WHEN product_availability = 'partial' THEN 1 ELSE 0 END) as partial, SUM(CASE WHEN product_availability = 'not_available' THEN 1 ELSE 0 END) as not_available, SUM(CASE WHEN packing_status = 'Yes' AND dispatch_status = 'Yes' THEN 1 ELSE 0 END) as completed FROM shop_orders WHERE branch_id = ?");
$stats->execute([$branch_id]);
$stats_data = $stats->fetch();

// Build filter condition
$filter_condition = "";
if ($status_filter == 'not_collected') { $filter_condition = "AND (workflow_status = 'not_collected' OR workflow_status IS NULL)";
} elseif ($status_filter == 'collecting') { $filter_condition = "AND workflow_status = 'collecting'";
} elseif ($status_filter == 'packing') { $filter_condition = "AND workflow_status = 'packing'";
} elseif ($status_filter == 'dispatching') { $filter_condition = "AND workflow_status = 'dispatching'";
} elseif ($status_filter == 'partial') { $filter_condition = "AND product_availability = 'partial'";
} elseif ($status_filter == 'not_available') { $filter_condition = "AND product_availability = 'not_available'";
} elseif ($status_filter == 'completed') { $filter_condition = "AND packing_status = 'Yes' AND dispatch_status = 'Yes'";
}

$orders_query = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM order_products WHERE order_id = o.id) as items_count FROM shop_orders o WHERE o.branch_id = ? $filter_condition ORDER BY o.created_at DESC");
$orders_query->execute([$branch_id]);
$orders_list = $orders_query->fetchAll();
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
        
        .top-nav { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 30px; border-radius: 20px; margin-bottom: 25px; }
        .brand { font-size: 1.5rem; font-weight: 700; }
        .brand i { margin-right: 10px; }
        .user-info { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .user-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 30px; font-size: 0.9rem; }
        .logout-btn { background: #dc2626; color: white; border: none; padding: 8px 20px; border-radius: 30px; text-decoration: none; transition: all 0.3s; }
        .logout-btn:hover { background: #b91c1c; transform: translateY(-2px); }
        
        .notification-container { position: relative; cursor: pointer; display: inline-block; }
        .notification-bell { background: rgba(255,255,255,0.15); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 10px; display: flex; align-items: center; justify-content: center; animation: pulse 1.5s infinite; }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
        
        .notification-dropdown { position: absolute; top: 50px; right: 0; width: 380px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: none; max-height: 450px; overflow-y: auto; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 700; background: #f8fafc; border-radius: 20px 20px 0 0; }
        .notification-item { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; cursor: pointer; display: block; color: #1e293b; text-decoration: none; }
        .notification-item:hover { background: #f1f5f9; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; }
        .notification-text { font-size: 0.75rem; color: #64748b; }
        .notification-time { font-size: 0.7rem; color: #94a3b8; margin-top: 8px; }
        .mark-all-read { padding: 12px 20px; text-align: center; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 20px 20px; }
        .mark-all-read button { background: none; border: none; color: #0284c7; cursor: pointer; width: 100%; }
        
        .main-container { max-width: 1400px; margin: 0 auto; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: all 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.filter-active { border: 3px solid #0284c7; }
        .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 0.85rem; }
        
        .search-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; }
        .btn-search { background: #0284c7; color: white; border: none; padding: 10px 25px; border-radius: 30px; }
        .btn-clear { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-block; }
        .clear-filter-btn { background: #e2e8f0; color: #1e293b; border: none; padding: 8px 20px; border-radius: 30px; font-size: 0.8rem; margin-left: 10px; cursor: pointer; }
        
        .orders-table-container { background: white; border-radius: 20px; overflow-x: auto; }
        .orders-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .orders-table th { background: #f8fafc; padding: 15px; border-bottom: 2px solid #e2e8f0; }
        .orders-table td { padding: 15px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .orders-table tr:hover { background: #f1f5f9; cursor: pointer; }
        
        .workflow-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
        .status-not_collected { background: #fee2e2; color: #991b1b; }
        .status-collecting { background: #fef3c7; color: #92400e; }
        .status-packing { background: #dbeafe; color: #1e40af; }
        .status-dispatching { background: #dcfce7; color: #166534; }
        .status-partial { background: #fed7aa; color: #9a3412; }
        .status-completed { background: #dcfce7; color: #166534; }
        
        .view-btn { background: #0284c7; color: white; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; }
        .back-btn { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        
        .order-details-card { background: white; border-radius: 20px; overflow: hidden; margin-bottom: 20px; }
        .card-header-custom { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px 25px; }
        .info-section { padding: 20px 25px; border-bottom: 1px solid #e2e8f0; }
        .section-title { font-size: 1rem; font-weight: 700; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #f8fafc; padding: 12px 15px; border-radius: 12px; }
        .info-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; }
        .info-value { font-size: 1rem; font-weight: 600; margin-top: 5px; }
        
        .product-card { background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
        .product-image { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; background: white; }
        .status-form { background: #f8fafc; padding: 20px 25px; border-radius: 0 0 20px 20px; }
        .radio-group { display: flex; gap: 20px; margin-top: 10px; flex-wrap: wrap; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .btn-confirm { background: #0284c7; color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: 600; }
        .info-note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; }
        
        .nav-tabs .nav-link { color: #1e2a3e; border: none; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: #0284c7; color: white; border-radius: 30px; }
        .request-card { background: white; border-radius: 15px; margin-bottom: 15px; overflow: hidden; border: 1px solid #e2e8f0; }
        .request-header { background: #f8fafc; padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-content { border-radius: 20px; }
        .modal-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; border-radius: 20px 20px 0 0; }
        .modal-header .btn-close { background-color: white; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-number { font-size: 1.3rem; }
            .orders-table th, .orders-table td { padding: 10px 8px; font-size: 0.7rem; }
            .btn-confirm { width: 100%; }
            .notification-dropdown { width: 320px; right: -80px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Branch Panel</small></div>
    <hr>
    <a href="branch_dashboard.php?tab=orders" class="nav-link <?= $active_tab == 'orders' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="branch_dashboard.php?tab=requests" class="nav-link <?= $active_tab == 'requests' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> Product Requests</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-truck"></i> Product Transfer</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small><br>
    <small class="text-secondary"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($_SESSION['branch_location']) ?></small>
</div>

<div class="main-content">
    <div class="top-nav">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div class="brand"><i class="fas fa-store"></i> Hameedia <small class="ms-2 opacity-75">Branch Manager Panel</small></div>
            <div class="user-info">
                <div class="notification-container">
                    <div class="notification-bell" onclick="toggleNotification()"><i class="fas fa-bell"></i><span class="notification-badge <?= $notification_count == 0 ? 'hidden' : '' ?>" id="branchNotificationBadge"><?= $notification_count ?></span></div>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header"><i class="fas fa-bell me-2"></i> Notifications <span class="badge bg-primary ms-2" id="notificationCountText"><?= $notification_count ?> new</span></div>
                        <div id="notificationList">
                            <?php if($notification_count > 0): ?>
                                <?php foreach($notification_list as $notif): ?>
                                <a href="<?= htmlspecialchars($notif['link']) ?>" class="notification-item unread" onclick="markNotificationRead(<?= $notif['id'] ?>)">
                                    <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                    <div class="notification-text"><?= htmlspecialchars(substr($notif['message'], 0, 100)) ?>...</div>
                                    <div class="notification-time"><?= date('d M Y, h:i A', strtotime($notif['created_at'])) ?></div>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4"><i class="fas fa-bell-slash fa-2x text-muted mb-2"></i><p>No new notifications</p></div>
                            <?php endif; ?>
                        </div>
                        <div class="mark-all-read"><button onclick="markAllNotificationsRead()">Mark all as read</button></div>
                    </div>
                </div>
                <span class="user-badge"><i class="fas fa-map-marker-alt me-1"></i> <?= htmlspecialchars($_SESSION['branch_location']) ?></span>
                <span class="user-badge"><i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#messageModal"><i class="fas fa-comment"></i> Message</button>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <?= $message ?>
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= $active_tab == 'orders' ? 'active' : '' ?>" href="branch_dashboard.php?tab=orders">My Orders</a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab == 'requests' ? 'active' : '' ?>" href="branch_dashboard.php?tab=requests">Product Requests <?= count($received_requests) > 0 ? '<span class="badge bg-danger ms-1">'.count($received_requests).'</span>' : '' ?></a></li>
        </ul>
        
        <?php if ($active_tab == 'requests'): ?>
            <div class="row">
                <div class="col-md-6">
                    <h4>Received Requests</h4>
                    <?php foreach($received_requests as $req): ?>
                    <div class="request-card">
                        <div class="request-header"><strong>From <?= htmlspecialchars($req['requesting_code']) ?></strong><span class="badge bg-warning ms-2">Pending</span></div>
                        <div class="p-3">
                            <div>Order #<?= $req['order_no'] ?> - <?= $req['customer_name'] ?></div>
                            <div>Product: <?= $req['product_name'] ?> (SKU: <?= $req['sku'] ?>, Size: <?= $req['size'] ?>)</div>
                            <div class="mt-2">"<?= htmlspecialchars($req['request_message']) ?>"</div>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <textarea name="response_message" class="form-control mb-2" rows="2" placeholder="Response..."></textarea>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="respond_request" value="accept" class="btn btn-success" onclick="this.form.action.value='accept'">Accept</button>
                                    <button type="submit" name="respond_request" value="reject" class="btn btn-danger" onclick="this.form.action.value='reject'">Reject</button>
                                </div>
                                <input type="hidden" name="action" value="">
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-md-6">
                    <h4>Sent Requests</h4>
                    <?php foreach($sent_requests as $req): ?>
                    <div class="request-card">
                        <div class="request-header"><strong>To <?= htmlspecialchars($req['supplying_code']) ?></strong><span class="badge bg-<?= $req['status'] == 'pending' ? 'warning' : ($req['status'] == 'accepted' ? 'success' : 'danger') ?> ms-2"><?= ucfirst($req['status']) ?></span></div>
                        <div class="p-3">
                            <div>Order #<?= $req['order_no'] ?> - <?= $req['customer_name'] ?></div>
                            <div>Product: <?= $req['product_name'] ?></div>
                            <div>"<?= htmlspecialchars($req['request_message']) ?>"</div>
                            <?php if($req['response_message']): ?><div class="mt-2 p-2 bg-light rounded">Response: "<?= htmlspecialchars($req['response_message']) ?>"</div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php elseif ($view_order_id && $selected_order): ?>
            <a href="branch_dashboard.php?tab=orders&filter=<?= $status_filter ?>" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            <div class="order-details-card">
                <div class="card-header-custom"><h4>Order #<?= htmlspecialchars($selected_order['order_no']) ?></h4></div>
                <div class="info-section">
                    <div class="info-grid">
                        <div class="info-item"><div class="info-label">Customer</div><div class="info-value"><?= htmlspecialchars($selected_order['customer_name']) ?></div></div>
                        <div class="info-item"><div class="info-label">Contact</div><div class="info-value"><?= htmlspecialchars($selected_order['contact_no'] ?: '-') ?></div></div>
                        <div class="info-item"><div class="info-label">Address</div><div class="info-value"><?= nl2br(htmlspecialchars($selected_order['address'] ?: '-')) ?></div></div>
                        <div class="info-item"><div class="info-label">Payment</div><div class="info-value"><?= htmlspecialchars($selected_order['payment_mode'] ?: '-') ?></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="info-grid">
                        <div class="info-item"><div class="info-label">Order #</div><div class="info-value"><?= $selected_order['order_no'] ?></div></div>
                        <div class="info-item"><div class="info-label">KOKO ID</div><div class="info-value"><?= $selected_order['koko_online_id'] ?: '-' ?></div></div>
                        <div class="info-item"><div class="info-label">Shipping</div><div class="info-value">Rs. <?= number_format($selected_order['shipping_charges'], 2) ?></div></div>
                        <div class="info-item"><div class="info-label">Total</div><div class="info-value text-success fw-bold">Rs. <?= number_format($selected_order['total_amount'], 2) ?></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <h6>Products</h6>
                    <?php foreach($selected_products as $product): ?>
                    <div class="product-card">
                        <div class="d-flex gap-3">
                            <?php if($product['image_url']): ?>
                                <img src="<?= $product['image_url'] ?>" class="product-image" onerror="this.src='https://via.placeholder.com/60'">
                            <?php else: ?>
                                <div class="product-image d-flex align-items-center justify-content-center bg-light"><i class="fas fa-image fa-2x text-muted"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <div><strong><?= $product['product_name'] ?></strong><br><small>Promo: <?= $product['promocode'] ?> | Size <?= $product['size'] ?> | SKU: <?= $product['sku'] ?></small></div>
                                    <div class="text-end"><strong class="text-success">Rs. <?= number_format($product['after_discount_price'], 2) ?></strong></div>
                                </div>
                                <div class="mt-2 border-top pt-2">
                                    <label class="small">Availability:</label>
                                    <div class="radio-group mt-1">
                                        <label class="radio-label"><input type="radio" name="product_avail_<?= $product['id'] ?>" value="available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] != 'not_available') ? 'checked' : '' ?>> Available</label>
                                        <label class="radio-label"><input type="radio" name="product_avail_<?= $product['id'] ?>" value="not_available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] == 'not_available') ? 'checked' : '' ?>> Not Available</label>
                                    </div>
                                    <div class="mt-2"><button type="button" class="btn btn-sm btn-warning" onclick="showRequestModal(<?= $product['id'] ?>, '<?= addslashes($product['product_name']) ?>', '<?= $product['sku'] ?>', '<?= $product['size'] ?>', <?= $selected_order['id'] ?>)">Request from Another Branch</button></div>
                                    <div class="product-reason-div mt-2" id="product-reason-<?= $product['id'] ?>" style="display: <?= ($product['product_availability'] == 'not_available') ? 'block' : 'none' ?>;">
                                        <input type="text" name="product_reason_<?= $product['id'] ?>" class="form-control form-control-sm" placeholder="Reason" value="<?= htmlspecialchars($product['product_availability_reason'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="status-form">
                    <form method="POST">
                        <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                        <?php foreach($selected_products as $product): ?>
                            <input type="hidden" name="product_id[]" value="<?= $product['id'] ?>">
                            <input type="hidden" name="product_avail[]" id="product_avail_hidden_<?= $product['id'] ?>" value="<?= $product['product_availability'] ?? 'available' ?>">
                            <input type="hidden" name="product_reason[]" id="product_reason_hidden_<?= $product['id'] ?>" value="<?= htmlspecialchars($product['product_availability_reason'] ?? '') ?>">
                        <?php endforeach; ?>
                        
                        <div class="info-note mb-3">Workflow: Product Collection → Packing → Dispatch</div>
                        
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="fw-bold">📦 Collection</label>
                                <div class="radio-group" id="collectionGroup">
                                    <label class="radio-label"><input type="radio" name="collection_status" value="Yes" id="collectionYes" <?= ($selected_order['collection_status'] == 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> Yes</label>
                                    <label class="radio-label"><input type="radio" name="collection_status" value="No" id="collectionNo" <?= ($selected_order['collection_status'] != 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> No</label>
                                </div>
                                <input type="text" name="collection_notes" class="form-control mt-2" placeholder="Notes" value="<?= htmlspecialchars($selected_order['collection_notes'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">📦 Packing</label>
                                <div class="radio-group" id="packingGroup">
                                    <label class="radio-label"><input type="radio" name="packing_status" value="Yes" id="packingYes" <?= ($selected_order['packing_status'] == 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> Yes</label>
                                    <label class="radio-label"><input type="radio" name="packing_status" value="No" id="packingNo" <?= ($selected_order['packing_status'] != 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> No</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">🚚 Dispatch</label>
                                <div class="radio-group" id="dispatchGroup">
                                    <label class="radio-label"><input type="radio" name="dispatch_status" value="Yes" id="dispatchYes" <?= ($selected_order['dispatch_status'] == 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> Yes</label>
                                    <label class="radio-label"><input type="radio" name="dispatch_status" value="No" id="dispatchNo" <?= ($selected_order['dispatch_status'] != 'Yes') ? 'checked' : '' ?> onchange="updateWorkflowStatus()"> No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-md-6"><label class="fw-bold"><i class="fas fa-barcode"></i> Barcode</label><input type="text" name="barcode_number" class="form-control" value="<?= htmlspecialchars($selected_order['barcode_number'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="fw-bold">📄 Invoice</label><input type="text" name="invoice_no" class="form-control" value="<?= htmlspecialchars($selected_order['invoice_no'] ?? '') ?>"></div>
                        </div>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-12"><label class="fw-bold">Comment</label><input type="text" name="branch_comment" class="form-control" value="<?= htmlspecialchars($selected_order['branch_comment'] ?? '') ?>"></div>
                        </div>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <label class="fw-bold">Overall Availability</label>
                                <div class="radio-group">
                                    <label class="radio-label"><input type="radio" name="product_availability" value="available" class="avail-radio" <?= ($selected_order['product_availability'] == 'available' || !$selected_order['product_availability']) ? 'checked' : '' ?> onchange="updateOverallStatus()"> All Available</label>
                                    <label class="radio-label"><input type="radio" name="product_availability" value="partial" class="avail-radio" <?= ($selected_order['product_availability'] == 'partial') ? 'checked' : '' ?> onchange="updateOverallStatus()"> Partially Available</label>
                                    <label class="radio-label"><input type="radio" name="product_availability" value="not_available" class="avail-radio" <?= ($selected_order['product_availability'] == 'not_available') ? 'checked' : '' ?> onchange="updateOverallStatus()"> None Available</label>
                                </div>
                            </div>
                        </div>
                        
                        <div id="partial_comment_div" style="display: <?= ($selected_order['product_availability'] == 'partial') ? 'block' : 'none' ?>;"><textarea name="partial_comment" class="form-control mt-2" rows="2" placeholder="Partial details..."><?= htmlspecialchars($selected_order['partial_comment'] ?? '') ?></textarea></div>
                        <div id="unavailability_reason_div" style="display: <?= ($selected_order['product_availability'] == 'not_available') ? 'block' : 'none' ?>;"><textarea name="unavailability_reason" class="form-control mt-2" rows="2" placeholder="Reason..."><?= htmlspecialchars($selected_order['unavailability_reason'] ?? '') ?></textarea></div>
                        
                        <div class="text-end mt-4"><button type="submit" name="update_order" class="btn-confirm">Confirm & Share</button></div>
                    </form>
                </div>
            </div>
            
            <div class="modal fade" id="requestModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Request Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="order_id" id="req_order_id">
                                <input type="hidden" name="product_id" id="req_product_id">
                                <input type="hidden" name="product_name" id="req_product_name">
                                <input type="hidden" name="sku" id="req_sku">
                                <input type="hidden" name="size" id="req_size">
                                <div class="mb-3"><label>Select Branch</label><select name="supplying_branch" class="form-select" required><?php foreach($all_branches as $b): ?><option value="<?= $b['id'] ?>"><?= $b['branch_code'] ?> - <?= $b['location'] ?></option><?php endforeach; ?></select></div>
                                <div class="mb-3"><label>Message</label><textarea name="request_message" class="form-control" rows="3" required></textarea></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="request_product" class="btn btn-primary">Send</button></div>
                        </form>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <?php if($status_filter != 'all'): ?>
                <div class="mb-3 text-end"><a href="branch_dashboard.php?tab=orders" class="clear-filter-btn"><i class="fas fa-times"></i> Clear Filter: <?= ucfirst(str_replace('_', ' ', $status_filter)) ?></a></div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card <?= $status_filter == 'all' ? 'filter-active' : '' ?>" onclick="applyFilter('all')"><div class="stat-number"><?= $total_orders ?></div><div class="stat-label">Total Orders</div></div>
                <div class="stat-card <?= $status_filter == 'pending' ? 'filter-active' : '' ?>" onclick="applyFilter('pending')"><div class="stat-number"><?= ($stats_data['not_collected'] ?? 0) + ($stats_data['collecting'] ?? 0) ?></div><div class="stat-label">Pending</div></div>
                <div class="stat-card <?= $status_filter == 'packing' ? 'filter-active' : '' ?>" onclick="applyFilter('packing')"><div class="stat-number"><?= $stats_data['packing'] ?? 0 ?></div><div class="stat-label">Packing</div></div>
                <div class="stat-card <?= $status_filter == 'dispatching' ? 'filter-active' : '' ?>" onclick="applyFilter('dispatching')"><div class="stat-number"><?= $stats_data['dispatching'] ?? 0 ?></div><div class="stat-label">Dispatching</div></div>
                <div class="stat-card <?= $status_filter == 'partial' ? 'filter-active' : '' ?>" onclick="applyFilter('partial')"><div class="stat-number"><?= ($stats_data['partial'] ?? 0) + ($stats_data['not_available'] ?? 0) ?></div><div class="stat-label">Partial/Not Avail</div></div>
                <div class="stat-card <?= $status_filter == 'completed' ? 'filter-active' : '' ?>" onclick="applyFilter('completed')"><div class="stat-number"><?= $stats_data['completed'] ?? 0 ?></div><div class="stat-label">Completed</div></div>
            </div>
            
            <div class="search-card">
                <form onsubmit="return false;" class="row g-3">
                    <div class="col-md-9"><label><i class="fas fa-search"></i> Search Orders</label><input type="text" id="searchInput" class="form-control" placeholder="Search by Order Number or Customer..." value="<?= htmlspecialchars($search_query) ?>"></div>
                    <div class="col-md-3 d-flex gap-2 align-items-end"><button type="button" class="btn-search w-100" onclick="performSearch()">Search</button><?php if($search_query): ?><a href="branch_dashboard.php?tab=orders&filter=<?= $status_filter ?>" class="btn-clear">Clear</a><?php endif; ?></div>
                </form>
            </div>
            
            <div class="orders-table-container">
                <table class="orders-table">
                    <thead><tr><th>Order No</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($orders_list as $order): 
                            if($order['product_availability'] == 'partial') { $status_class = 'status-partial'; $status_text = 'Partial';
                            } elseif($order['product_availability'] == 'not_available') { $status_class = 'status-not_collected'; $status_text = 'Not Available';
                            } elseif($order['workflow_status']) { $status_class = 'status-' . $order['workflow_status']; $status_text = ucfirst(str_replace('_', ' ', $order['workflow_status']));
                            } else { $status_class = 'status-not_collected'; $status_text = 'Not Collected'; }
                        ?>
                        <tr onclick="location.href='?tab=orders&view_order=<?= $order['id'] ?>&filter=<?= $status_filter ?>'">
                            <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                            <td><?= $order['items_count'] ?? 1 ?></td>
                            <td>Rs. <?= number_format($order['total_amount'], 2) ?></td>
                            <td><span class="workflow-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                            <td><button class="view-btn" onclick="event.stopPropagation();location.href='?tab=orders&view_order=<?= $order['id'] ?>&filter=<?= $status_filter ?>'">View</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Message Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope"></i> Send Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Send To</label>
                        <select name="to_type" class="form-select" id="messageToType" onchange="toggleBranchSelect()">
                            <option value="admin">Admin (Head Office)</option>
                            <option value="branch">Another Branch</option>
                        </select>
                    </div>
                    <div class="mb-3" id="branchSelectDiv" style="display: none;">
                        <label class="form-label">Select Branch</label>
                        <select name="to_branch" class="form-select">
                            <option value="">Select Branch</option>
                            <?php foreach($all_branches as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= $b['branch_code'] ?> - <?= $b['location'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="message_text" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_message_action" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentTab = <?= json_encode($active_tab) ?>;
    let currentFilter = <?= json_encode($status_filter) ?>;
    
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
    function toggleNotification() { document.getElementById('notificationDropdown').classList.toggle('show'); }
    function performSearch() { window.location.href = 'branch_dashboard.php?tab=orders&filter=' + currentFilter + '&search=' + encodeURIComponent(document.getElementById('searchInput').value); }
    function toggleBranchSelect() { document.getElementById('branchSelectDiv').style.display = document.getElementById('messageToType').value == 'branch' ? 'block' : 'none'; }
    function applyFilter(filter) { window.location.href = 'branch_dashboard.php?tab=orders&filter=' + filter; }
    
    function showRequestModal(pid, pname, psku, psize, oid) {
        document.getElementById('req_product_id').value = pid;
        document.getElementById('req_product_name').value = pname;
        document.getElementById('req_sku').value = psku;
        document.getElementById('req_size').value = psize;
        document.getElementById('req_order_id').value = oid;
        new bootstrap.Modal(document.getElementById('requestModal')).show();
    }
    
    function updateWorkflowStatus() {
        let collection = document.querySelector('input[name="collection_status"]:checked')?.value;
        let packing = document.querySelector('input[name="packing_status"]:checked')?.value;
        
        if (collection === 'No') {
            if(document.getElementById('packingYes')) document.getElementById('packingYes').checked = false;
            if(document.getElementById('packingNo')) document.getElementById('packingNo').checked = true;
            if(document.getElementById('dispatchYes')) document.getElementById('dispatchYes').checked = false;
            if(document.getElementById('dispatchNo')) document.getElementById('dispatchNo').checked = true;
            document.getElementById('packingGroup').style.opacity = '0.6';
            document.getElementById('dispatchGroup').style.opacity = '0.6';
        } else if (collection === 'Yes') {
            document.getElementById('packingGroup').style.opacity = '1';
            if (packing === 'Yes') {
                document.getElementById('dispatchGroup').style.opacity = '1';
            } else {
                if(document.getElementById('dispatchYes')) document.getElementById('dispatchYes').checked = false;
                if(document.getElementById('dispatchNo')) document.getElementById('dispatchNo').checked = true;
                document.getElementById('dispatchGroup').style.opacity = '0.6';
            }
        }
    }
    
    function updateOverallStatus() {
        let availability = document.querySelector('input[name="product_availability"]:checked')?.value;
        let partialDiv = document.getElementById('partial_comment_div');
        let unavailDiv = document.getElementById('unavailability_reason_div');
        
        if (availability === 'partial') {
            if(partialDiv) partialDiv.style.display = 'block';
            if(unavailDiv) unavailDiv.style.display = 'none';
        } else if (availability === 'not_available') {
            if(partialDiv) partialDiv.style.display = 'none';
            if(unavailDiv) unavailDiv.style.display = 'block';
        } else {
            if(partialDiv) partialDiv.style.display = 'none';
            if(unavailDiv) unavailDiv.style.display = 'none';
        }
        updateWorkflowStatus();
    }
    
    document.querySelectorAll('.product-avail').forEach(radio => {
        radio.addEventListener('change', function() {
            let pid = this.dataset.product;
            let reasonDiv = document.getElementById(`product-reason-${pid}`);
            let hiddenAvail = document.getElementById(`product_avail_hidden_${pid}`);
            if (this.value === 'not_available') { 
                reasonDiv.style.display = 'block'; 
                if(hiddenAvail) hiddenAvail.value = 'not_available'; 
            } else { 
                reasonDiv.style.display = 'none'; 
                if(hiddenAvail) hiddenAvail.value = 'available'; 
            }
        });
    });
    
    async function markNotificationRead(id) {
        await fetch('mark_notification_read.php', { method: 'POST', body: 'id='+id });
        location.reload();
    }
    
    async function markAllNotificationsRead() {
        await fetch('mark_notification_read.php', { method: 'POST', body: 'all=1' });
        document.getElementById('branchNotificationBadge').classList.add('hidden');
        document.getElementById('notificationCountText').innerHTML = '0 new';
        document.getElementById('notificationList').innerHTML = '<div class="text-center py-4"><i class="fas fa-check-circle"></i><p>All read</p></div>';
        setTimeout(() => document.getElementById('notificationDropdown').classList.remove('show'), 1500);
    }
    
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { if(window.innerWidth<992) toggleSidebar(); }));
    document.getElementById('searchInput')?.addEventListener('keypress', e => { if(e.key==='Enter') performSearch(); });
    document.getElementById('collectionYes')?.addEventListener('change', updateWorkflowStatus);
    document.getElementById('collectionNo')?.addEventListener('change', updateWorkflowStatus);
    updateWorkflowStatus();
</script>
</body>
</html>