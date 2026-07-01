<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['admin_last_notification_check'])) {
    $_SESSION['admin_last_notification_check'] = time();
}

$status_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Get system notifications
$notifications = $pdo->prepare("SELECT * FROM system_notifications WHERE user_id = 1 AND is_read = 0 ORDER BY created_at DESC");
$notifications->execute();
$notification_list = $notifications->fetchAll();
$notification_count = count($notification_list);

// Get branch messages
$branch_messages = $pdo->prepare("
    SELECT bm.*, b.location, b.branch_code, b.manager_name,
           o.order_no as order_number
    FROM branch_messages bm 
    LEFT JOIN branch_managers b ON bm.branch_id = b.id 
    LEFT JOIN shop_orders o ON bm.order_id = o.id
    WHERE bm.is_read_admin = 0 
    ORDER BY bm.created_at DESC
");
$branch_messages->execute();
$message_list = $branch_messages->fetchAll();
$branch_messages_count = count($message_list);

// Old messages count
$old_messages_count = $pdo->query("SELECT COUNT(*) FROM showroom_messages WHERE from_showroom IS NOT NULL AND is_read_admin = '0'")->fetchColumn();

// Total notification count
$total_notification_count = $notification_count + $branch_messages_count + $old_messages_count;

// Mark branch messages as read when viewed
if ($tab == 'messages' || isset($_GET['mark_read'])) {
    $pdo->exec("UPDATE branch_messages SET is_read_admin = 1 WHERE is_read_admin = 0");
    $pdo->exec("UPDATE showroom_messages SET is_read_admin = '1' WHERE is_read_admin = '0' AND from_showroom IS NOT NULL");
}

// Get all messages for display
$all_messages = $pdo->prepare("
    SELECT bm.*, b.location, b.branch_code, b.manager_name,
           o.order_no as order_number
    FROM branch_messages bm 
    LEFT JOIN branch_managers b ON bm.branch_id = b.id 
    LEFT JOIN shop_orders o ON bm.order_id = o.id
    ORDER BY bm.created_at DESC LIMIT 50
");
$all_messages->execute();
$all_messages_list = $all_messages->fetchAll();

// Get statistics
$total_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
$not_collected = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'not_collected' OR workflow_status IS NULL")->fetchColumn();
$collecting = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'collecting'")->fetchColumn();
$packing = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'packing'")->fetchColumn();
$dispatching = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'dispatching'")->fetchColumn();
$partial = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE product_availability = 'partial'")->fetchColumn();
$not_available = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE product_availability = 'not_available'")->fetchColumn();
$completed = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE packing_status = 'Yes' AND dispatch_status = 'Yes'")->fetchColumn();

// Build where clause for filtering
$where_clause = "";
if ($status_filter == 'not_collected') {
    $where_clause = "WHERE (workflow_status = 'not_collected' OR workflow_status IS NULL)";
} elseif ($status_filter == 'collecting') {
    $where_clause = "WHERE workflow_status = 'collecting'";
} elseif ($status_filter == 'packing') {
    $where_clause = "WHERE workflow_status = 'packing'";
} elseif ($status_filter == 'dispatching') {
    $where_clause = "WHERE workflow_status = 'dispatching'";
} elseif ($status_filter == 'partial') {
    $where_clause = "WHERE product_availability = 'partial'";
} elseif ($status_filter == 'not_available') {
    $where_clause = "WHERE product_availability = 'not_available'";
} elseif ($status_filter == 'completed') {
    $where_clause = "WHERE packing_status = 'Yes' AND dispatch_status = 'Yes'";
}

// Get recent orders
$recent_orders = $pdo->query("
    SELECT o.*, b.location as branch_loc, b.branch_code,
           (SELECT GROUP_CONCAT(DISTINCT b2.branch_code) FROM order_products op 
            LEFT JOIN branch_managers b2 ON op.assign_showroom = b2.id 
            WHERE op.order_id = o.id) as primary_branches,
           (SELECT GROUP_CONCAT(DISTINCT b3.branch_code) FROM order_products op 
            LEFT JOIN branch_managers b3 ON op.secondary_showroom = b3.id 
            WHERE op.order_id = o.id AND op.secondary_showroom IS NOT NULL) as secondary_branches,
           (SELECT GROUP_CONCAT(DISTINCT CONCAT(b4.branch_code, ': ', bm.message)) 
            FROM branch_messages bm 
            LEFT JOIN branch_managers b4 ON bm.branch_id = b4.id 
            WHERE bm.order_id = o.id AND bm.message_type = 'availability' AND bm.is_read_admin = 0) as availability_messages
    FROM shop_orders o 
    LEFT JOIN branch_managers b ON o.branch_id = b.id 
    $where_clause 
    ORDER BY o.created_at DESC LIMIT 20
")->fetchAll();

// Get old messages
$old_messages = $pdo->query("
    SELECT sm.*, b.location, b.branch_code, b.manager_name 
    FROM showroom_messages sm 
    LEFT JOIN branch_managers b ON sm.from_showroom = b.id 
    WHERE sm.from_showroom IS NOT NULL 
    ORDER BY sm.created_at DESC LIMIT 10
")->fetchAll();

// Handle success/error messages
$success_msg = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_msg = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Hameedia</title>
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
        
        .top-header { background: white; border-radius: 20px; padding: 15px 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        .notification-container { position: relative; cursor: pointer; display: inline-block; }
        .notification-bell { background: #f8fafc; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s; position: relative; border: none; }
        .notification-bell:hover { background: #e2e8f0; transform: scale(1.05); }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 11px; display: flex; align-items: center; justify-content: center; font-weight: bold; animation: pulse 1.5s infinite; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3); }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); background: #dc2626; } 100% { transform: scale(1); } }
        
        .notification-dropdown { position: absolute; top: 55px; right: 0; width: 400px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: none; max-height: 500px; overflow-y: auto; z-index: 1000; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 700; background: #f8fafc; border-radius: 20px 20px 0 0; position: sticky; top: 0; z-index: 1; }
        .notification-item { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; cursor: pointer; display: block; color: #1e293b; text-decoration: none; }
        .notification-item:hover { background: #f1f5f9; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; }
        .notification-text { font-size: 0.75rem; color: #64748b; }
        .notification-time { font-size: 0.7rem; color: #94a3b8; margin-top: 8px; }
        .mark-all-read { padding: 12px 20px; text-align: center; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 20px 20px; position: sticky; bottom: 0; background: white; }
        .mark-all-read button { background: none; border: none; color: #0284c7; cursor: pointer; width: 100%; font-weight: 500; }
        .mark-all-read button:hover { text-decoration: underline; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 20px; padding: 15px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); cursor: pointer; transition: all 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.active { border: 3px solid #0284c7; }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; color: inherit; }
        .stat-card .stat-label { font-size: 0.75rem; text-transform: uppercase; color: inherit; opacity: 0.85; letter-spacing: 0.5px; }
        .stat-card.bg-primary .stat-number, .stat-card.bg-primary .stat-label { color: white; }
        .stat-card.bg-danger .stat-number, .stat-card.bg-danger .stat-label { color: white; }
        .stat-card.bg-warning .stat-number, .stat-card.bg-warning .stat-label { color: #1e293b; }
        .stat-card.bg-info .stat-number, .stat-card.bg-info .stat-label { color: white; }
        .stat-card.bg-secondary .stat-number, .stat-card.bg-secondary .stat-label { color: white; }
        .stat-card.bg-success .stat-number, .stat-card.bg-success .stat-label { color: white; }
        
        .workflow-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-not_collected { background: #fee2e2; color: #991b1b; }
        .status-collecting { background: #fef3c7; color: #92400e; }
        .status-packing { background: #dbeafe; color: #1e40af; }
        .status-dispatching { background: #dcfce7; color: #166534; }
        .status-partial { background: #fed7aa; color: #9a3412; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-not_available { background: #fee2e2; color: #991b1b; }
        
        .availability-badge { padding: 3px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 600; display: inline-block; }
        .avail-available { background: #dcfce7; color: #166534; }
        .avail-partial { background: #fed7aa; color: #9a3412; }
        .avail-not_available { background: #fee2e2; color: #991b1b; }
        
        .branch-badge { padding: 2px 8px; border-radius: 12px; font-size: 0.6rem; display: inline-block; margin: 1px; }
        .branch-primary { background: #dbeafe; color: #1e40af; }
        .branch-secondary { background: #fef3c7; color: #92400e; }
        
        .clear-filter-btn { background: #e2e8f0; color: #1e293b; border: none; padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; margin-left: 10px; text-decoration: none; }
        .message-unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .message-from { font-weight: 600; color: #1e2a3e; }
        
        .table th { white-space: nowrap; }
        .table td { vertical-align: middle; }
        
        .message-box { 
            background: #fef3c7; 
            border-left: 4px solid #f59e0b; 
            padding: 15px 20px; 
            border-radius: 8px; 
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .message-box:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .message-box.info { background: #e0f2fe; border-color: #0284c7; }
        .message-box.success { background: #dcfce7; border-color: #22c55e; }
        .message-box.danger { background: #fee2e2; border-color: #dc2626; }
        .message-box .msg-subject { font-weight: 600; font-size: 1.05rem; }
        .message-box .msg-from { font-size: 0.85rem; color: #475569; }
        .message-box .msg-time { font-size: 0.7rem; color: #94a3b8; }
        .message-box .msg-body { margin-top: 8px; padding: 10px 15px; background: white; border-radius: 8px; }
        .message-box .admin-reply-box { 
            background: #dcfce7; 
            border-left: 3px solid #22c55e; 
            padding: 12px 15px; 
            border-radius: 8px; 
            margin-top: 10px;
        }
        
        .nav-tabs .nav-link { color: #1e2a3e; border: none; padding: 10px 20px; border-radius: 30px; }
        .nav-tabs .nav-link.active { background: #0284c7; color: white; }
        .nav-tabs .nav-link .badge { margin-left: 5px; }
        
        .btn-reply { 
            background: #0284c7; 
            color: white; 
            border: none; 
            padding: 6px 18px; 
            border-radius: 30px; 
            transition: all 0.3s;
        }
        .btn-reply:hover { background: #0369a1; transform: translateY(-2px); }
        
        .msg-type-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 600; }
        .msg-type-availability { background: #fee2e2; color: #991b1b; }
        .msg-type-general { background: #dbeafe; color: #1e40af; }
        .msg-type-request { background: #fef3c7; color: #92400e; }
        .msg-type-update { background: #dcfce7; color: #166534; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .table { font-size: 0.75rem; }
            .notification-dropdown { width: 320px; right: -60px; }
            .message-box { padding: 12px 15px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php?tab=dashboard" class="nav-link <?= $tab == 'dashboard' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
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
    <div class="top-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2><i class="fas fa-chart-line"></i> Dashboard</h2>
            <small>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?></small>
            <?php if($status_filter != 'all'): ?>
                <a href="admin_dashboard.php?tab=dashboard" class="clear-filter-btn"><i class="fas fa-times"></i> Clear Filter</a>
            <?php endif; ?>
        </div>
        
        <div class="notification-container">
            <button class="notification-bell" onclick="toggleNotification()" title="Click to view notifications">
                <i class="fas fa-bell fa-lg" style="color: #1e2a3e;"></i>
                <span class="notification-badge <?= $total_notification_count == 0 ? 'hidden' : '' ?>" id="notificationBadge">
                    <?= $total_notification_count ?>
                </span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <i class="fas fa-bell me-2"></i> Notifications
                    <span class="badge bg-primary ms-2" id="notificationCount"><?= $total_notification_count ?> new</span>
                </div>
                <div id="notificationList">
                    <?php if($total_notification_count > 0): ?>
                        <?php foreach($notification_list as $notif): ?>
                        <a href="<?= htmlspecialchars($notif['link'] ?? 'all_orders.php') ?>" class="notification-item unread" onclick="markNotificationRead(<?= $notif['id'] ?>)">
                            <div class="notification-title"><i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($notif['title']) ?></div>
                            <div class="notification-text"><?= htmlspecialchars(substr($notif['message'], 0, 100)) ?>...</div>
                            <div class="notification-time"><i class="far fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($notif['created_at'])) ?></div>
                        </a>
                        <?php endforeach; ?>
                        <?php if($branch_messages_count > 0): ?>
                            <a href="admin_dashboard.php?tab=messages" class="notification-item unread" onclick="toggleNotification()">
                                <div class="notification-title"><i class="fas fa-comment-dots me-2"></i> New Branch Messages</div>
                                <div class="notification-text">You have <?= $branch_messages_count ?> new message(s) from branches</div>
                                <div class="notification-time">Click to view all messages</div>
                            </a>
                        <?php endif; ?>
                        <?php if($old_messages_count > 0): ?>
                            <a href="admin_dashboard.php?tab=messages" class="notification-item unread" onclick="toggleNotification()">
                                <div class="notification-title"><i class="fas fa-envelope me-2"></i> Old Messages</div>
                                <div class="notification-text">You have <?= $old_messages_count ?> unread message(s)</div>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No new notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mark-all-read">
                    <button onclick="markAllNotificationsRead()">
                        <i class="fas fa-check-double me-1"></i> Mark all as read
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i> <?= $success_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i> <?= $error_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($tab == 'messages'): ?>
        <!-- Messages Tab -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-envelope me-2"></i> Messages from Branches</h5>
                <a href="admin_dashboard.php?tab=dashboard" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            <div class="card-body">
                <?php if(count($all_messages_list) > 0): ?>
                    <?php foreach($all_messages_list as $msg): 
                        $msg_class = $msg['is_read_admin'] == 0 ? 'message-unread' : '';
                        $type_class = 'general';
                        if($msg['message_type'] == 'availability') $type_class = 'availability';
                        elseif($msg['message_type'] == 'request') $type_class = 'request';
                        elseif($msg['message_type'] == 'update') $type_class = 'update';
                        $has_reply = !empty($msg['admin_reply']);
                    ?>
                    <div class="message-box <?= $type_class ?> <?= $msg_class ?>" id="msg-<?= $msg['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                            <div>
                                <span class="msg-from">
                                    <i class="fas fa-store"></i> 
                                    <strong><?= htmlspecialchars($msg['branch_code'] ?? 'Unknown') ?></strong>
                                    <span class="text-muted">(<?= htmlspecialchars($msg['location'] ?? '') ?>)</span>
                                </span>
                                <?php if($msg['order_number']): ?>
                                    <span class="badge bg-secondary ms-2">Order #<?= htmlspecialchars($msg['order_number']) ?></span>
                                <?php endif; ?>
                                <?php if($msg['is_read_admin'] == 0): ?>
                                    <span class="badge bg-primary ms-1">New</span>
                                <?php endif; ?>
                                <span class="msg-type-badge msg-type-<?= $type_class ?> ms-1"><?= ucfirst($msg['message_type']) ?></span>
                                <?php if($has_reply): ?>
                                    <span class="badge bg-success ms-1">Replied</span>
                                <?php endif; ?>
                            </div>
                            <span class="msg-time"><i class="far fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></span>
                        </div>
                        <div class="msg-subject mt-2"><?= htmlspecialchars($msg['subject']) ?></div>
                        <div class="msg-body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                        
                        <?php if($has_reply): ?>
                            <div class="admin-reply-box">
                                <strong class="text-success"><i class="fas fa-reply"></i> Your Reply:</strong>
                                <?= nl2br(htmlspecialchars($msg['admin_reply'])) ?>
                                <br><small class="text-muted"><?= date('d M Y, h:i A', strtotime($msg['admin_reply_date'])) ?></small>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-reply mt-2" onclick="openReplyModal(<?= $msg['id'] ?>, '<?= addslashes($msg['subject']) ?>')">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-4x d-block mb-3"></i>
                        <h5>No messages from branches</h5>
                        <p>Messages from branch managers will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Dashboard View -->
        <div class="stats-grid">
            <div class="stat-card bg-primary text-white <?= $status_filter == 'all' ? 'active' : '' ?>" onclick="applyFilter('all')">
                <div class="stat-number"><?= $total_orders ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card bg-danger text-white <?= $status_filter == 'not_collected' ? 'active' : '' ?>" onclick="applyFilter('not_collected')">
                <div class="stat-number"><?= $not_collected ?></div>
                <div class="stat-label">Not Collected</div>
            </div>
            <div class="stat-card bg-warning text-dark <?= $status_filter == 'collecting' ? 'active' : '' ?>" onclick="applyFilter('collecting')">
                <div class="stat-number"><?= $collecting ?></div>
                <div class="stat-label">Collecting</div>
            </div>
            <div class="stat-card bg-info text-white <?= $status_filter == 'packing' ? 'active' : '' ?>" onclick="applyFilter('packing')">
                <div class="stat-number"><?= $packing ?></div>
                <div class="stat-label">Packing</div>
            </div>
            <div class="stat-card bg-secondary text-white <?= $status_filter == 'dispatching' ? 'active' : '' ?>" onclick="applyFilter('dispatching')">
                <div class="stat-number"><?= $dispatching ?></div>
                <div class="stat-label">Dispatching</div>
            </div>
            <div class="stat-card bg-warning text-dark <?= $status_filter == 'partial' ? 'active' : '' ?>" onclick="applyFilter('partial')">
                <div class="stat-number"><?= $partial ?></div>
                <div class="stat-label">Partial</div>
            </div>
            <div class="stat-card bg-danger text-white <?= $status_filter == 'not_available' ? 'active' : '' ?>" onclick="applyFilter('not_available')">
                <div class="stat-number"><?= $not_available ?></div>
                <div class="stat-label">Not Available</div>
            </div>
            <div class="stat-card bg-success text-white <?= $status_filter == 'completed' ? 'active' : '' ?>" onclick="applyFilter('completed')">
                <div class="stat-number"><?= $completed ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="card mt-4">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-history me-2"></i> Recent Orders</span>
                <a href="admin_dashboard.php?tab=messages" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-envelope me-1"></i> View Messages
                    <?php if($branch_messages_count > 0): ?>
                        <span class="badge bg-danger ms-1"><?= $branch_messages_count ?> new</span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Order No</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Branch Assignment</th>
                            <th>Status</th>
                            <th>Availability</th>
                            <th>Messages</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($recent_orders) > 0): ?>
                            <?php foreach($recent_orders as $order): 
                                $status_display = $order['workflow_status'] ?? 'not_collected';
                                if($order['product_availability'] == 'partial') {
                                    $status_display = 'partial';
                                } elseif($order['product_availability'] == 'not_available') {
                                    $status_display = 'not_available';
                                }
                                
                                $avail_class = 'avail-available';
                                $avail_text = 'Available';
                                if($order['product_availability'] == 'partial') { 
                                    $avail_class = 'avail-partial'; 
                                    $avail_text = 'Partial'; 
                                } elseif($order['product_availability'] == 'not_available') { 
                                    $avail_class = 'avail-not_available'; 
                                    $avail_text = 'Not Available'; 
                                }
                                
                                $has_message = !empty($order['availability_messages']);
                            ?>
                            <tr onclick="location.href='all_orders.php'" style="cursor:pointer">
                                <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td>Rs. <?= number_format($order['total_amount'], 2) ?></td>
                                <td>
                                    <?php if($order['primary_branches']): ?>
                                        <span class="branch-badge branch-primary"><i class="fas fa-store"></i> <?= htmlspecialchars($order['primary_branches']) ?></span>
                                    <?php endif; ?>
                                    <?php if($order['secondary_branches']): ?>
                                        <span class="branch-badge branch-secondary"><i class="fas fa-store-alt"></i> <?= htmlspecialchars($order['secondary_branches']) ?></span>
                                    <?php endif; ?>
                                    <?php if(!$order['primary_branches'] && !$order['secondary_branches']): ?>
                                        <span class="text-muted">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="workflow-badge status-<?= str_replace('_', '', $status_display) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $status_display)) ?>
                                    </span>
                                </td>
                                <td><span class="availability-badge <?= $avail_class ?>"><?= $avail_text ?></span></td>
                                <td>
                                    <?php if($has_message): ?>
                                        <span class="badge bg-warning text-dark" title="<?= htmlspecialchars($order['availability_messages']) ?>">
                                            <i class="fas fa-comment"></i> 
                                            <?= substr_count($order['availability_messages'], ':') ?> msg
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No orders found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    
    <footer class="text-center text-muted mt-4 small">© 2026 Hameedia Order Management System</footer>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="replyModalLabel"><i class="fas fa-reply"></i> Reply to Branch Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="reply_message.php" id="replyForm">
                <div class="modal-body">
                    <input type="hidden" name="message_id" id="reply_message_id" value="">
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" id="reply_subject" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Your Reply <span class="text-danger">*</span></label>
                        <textarea name="reply_message" id="reply_message_text" class="form-control" rows="5" placeholder="Type your reply here..." required></textarea>
                        <small class="text-muted">Your reply will be sent to the branch manager.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_admin_reply" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Send Reply
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
    
    function toggleNotification() {
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
        }
    }
    
    function applyFilter(filter) { 
        window.location.href = 'admin_dashboard.php?tab=dashboard&filter=' + filter; 
    }
    
    function openReplyModal(id, subject) {
        document.getElementById('reply_message_id').value = id;
        document.getElementById('reply_subject').value = 'Re: ' + subject;
        document.getElementById('reply_message_text').value = '';
        var replyModal = new bootstrap.Modal(document.getElementById('replyModal'), {
            backdrop: 'static',
            keyboard: false
        });
        replyModal.show();
    }
    
    function markNotificationRead(id) {
        fetch('mark_notification_read.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id 
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('notificationBadge');
                const count = document.getElementById('notificationCount');
                let currentCount = parseInt(badge.textContent) || 0;
                if (currentCount > 0) {
                    currentCount--;
                    badge.textContent = currentCount;
                    count.textContent = currentCount + ' new';
                    if (currentCount === 0) {
                        badge.classList.add('hidden');
                    }
                }
                location.reload();
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    function markAllNotificationsRead() {
        fetch('mark_notification_read.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'all=1' 
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('notificationBadge').classList.add('hidden');
                document.getElementById('notificationCount').textContent = '0 new';
                document.getElementById('notificationList').innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p class="text-muted mb-0">All notifications marked as read</p>
                    </div>
                `;
                setTimeout(() => {
                    document.getElementById('notificationDropdown').classList.remove('show');
                }, 1500);
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const container = document.querySelector('.notification-container');
        const dropdown = document.getElementById('notificationDropdown');
        if (container && !container.contains(event.target) && dropdown) {
            dropdown.classList.remove('show');
        }
    });
    
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { 
        if(window.innerWidth < 992) toggleSidebar(); 
    }));
    
    // Auto-refresh notifications every 30 seconds
    setInterval(function() {
        const badge = document.getElementById('notificationBadge');
        if (!document.getElementById('notificationDropdown').classList.contains('show')) {
            fetch('check_notifications.php?last_check=' + Date.now())
            .then(response => response.json())
            .then(data => {
                if (data.count > 0 && data.count != parseInt(badge.textContent)) {
                    badge.textContent = data.count;
                    badge.classList.remove('hidden');
                    document.getElementById('notificationCount').textContent = data.count + ' new';
                }
            })
            .catch(error => console.error('Error refreshing notifications:', error));
        }
    }, 30000);
</script>
</body>
</html>