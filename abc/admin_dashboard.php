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

// Get filter parameter
$status_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Get admin notifications
$notifications = $pdo->prepare("SELECT * FROM system_notifications WHERE user_id = 1 AND is_read = 0 ORDER BY created_at DESC");
$notifications->execute();
$notification_list = $notifications->fetchAll();
$notification_count = count($notification_list);

// Get statistics
$total_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
$not_collected = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'not_collected' OR workflow_status IS NULL")->fetchColumn();
$collecting = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'collecting'")->fetchColumn();
$packing = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'packing'")->fetchColumn();
$dispatching = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'dispatching'")->fetchColumn();
$partial = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE product_availability = 'partial'")->fetchColumn();
$not_available = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE product_availability = 'not_available'")->fetchColumn();
$completed = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE packing_status = 'Yes' AND dispatch_status = 'Yes'")->fetchColumn();

// Build WHERE clause based on filter
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

// Get orders with filter
$recent_orders = $pdo->query("SELECT o.*, b.location as branch_loc FROM shop_orders o LEFT JOIN branch_managers b ON o.branch_id = b.id $where_clause ORDER BY o.created_at DESC LIMIT 50")->fetchAll();

// Get status display text
$status_display_text = '';
switch($status_filter) {
    case 'not_collected': $status_display_text = 'Not Collected'; break;
    case 'collecting': $status_display_text = 'Collecting'; break;
    case 'packing': $status_display_text = 'Packing'; break;
    case 'dispatching': $status_display_text = 'Dispatching'; break;
    case 'partial': $status_display_text = 'Partially Available'; break;
    case 'not_available': $status_display_text = 'Not Available'; break;
    case 'completed': $status_display_text = 'Completed'; break;
    default: $status_display_text = 'All Orders';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        
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
        
        .notification-container { position: relative; cursor: pointer; }
        .notification-bell { background: #f8fafc; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; }
        .notification-bell:hover { background: #e2e8f0; transform: scale(1.05); }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 11px; display: flex; align-items: center; justify-content: center; animation: pulse 1.5s infinite; }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
        
        .notification-dropdown { position: absolute; top: 55px; right: 0; width: 380px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 1000; display: none; max-height: 450px; overflow-y: auto; }
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { border-radius: 20px; border: none; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer; position: relative; overflow: hidden; background: white; padding: 15px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-card.active { border: 3px solid #0284c7; }
        .stat-card .stat-number { font-size: 1.8rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card.bg-primary .stat-label, .stat-card.bg-primary .stat-number { color: white; }
        .stat-card.bg-danger .stat-label, .stat-card.bg-danger .stat-number { color: white; }
        .stat-card.bg-warning .stat-label, .stat-card.bg-warning .stat-number { color: #1e293b; }
        .stat-card.bg-info .stat-label, .stat-card.bg-info .stat-number { color: white; }
        .stat-card.bg-secondary .stat-label, .stat-card.bg-secondary .stat-number { color: white; }
        .stat-card.bg-success .stat-label, .stat-card.bg-success .stat-number { color: white; }
        
        .table-responsive { overflow-x: auto; }
        .table th { background: #f8fafc; white-space: nowrap; }
        .table td { vertical-align: middle; }
        
        .workflow-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-not_collected { background: #fee2e2; color: #991b1b; }
        .status-collecting { background: #fef3c7; color: #92400e; }
        .status-packing { background: #dbeafe; color: #1e40af; }
        .status-dispatching { background: #dcfce7; color: #166534; }
        .status-partial { background: #fed7aa; color: #9a3412; }
        .status-not_available { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dcfce7; color: #166534; }
        
        .clear-filter-btn { background: #e2e8f0; color: #1e293b; border: none; padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; margin-left: 10px; text-decoration: none; display: inline-block; }
        .clear-filter-btn:hover { background: #cbd5e1; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .top-header { flex-direction: column; gap: 10px; text-align: center; }
            .notification-dropdown { width: 320px; right: -60px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Head Office Admin</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small class="text-secondary"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <div class="top-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2 class="mb-0"><i class="fas fa-chart-line"></i> Dashboard</h2>
            <small class="text-muted">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?></small>
            <?php if($status_filter != 'all'): ?>
                <a href="admin_dashboard.php" class="clear-filter-btn"><i class="fas fa-times"></i> Clear Filter: <?= $status_display_text ?></a>
            <?php endif; ?>
        </div>
        <div class="notification-container">
            <div class="notification-bell" onclick="toggleNotification()"><i class="fas fa-bell fa-lg" style="color:#1e2a3e;"></i><span class="notification-badge <?= $notification_count == 0 ? 'hidden' : '' ?>" id="notificationBadge"><?= $notification_count ?></span></div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header"><i class="fas fa-bell me-2"></i> Notifications <span class="badge bg-primary ms-2" id="notificationCountText"><?= $notification_count ?> new</span></div>
                <div id="notificationList">
                    <?php if($notification_count > 0): ?>
                        <?php foreach($notification_list as $notif): ?>
                        <a href="<?= htmlspecialchars($notif['link']) ?>" class="notification-item unread" onclick="markNotificationRead(<?= $notif['id'] ?>)">
                            <div class="notification-title"><i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($notif['title']) ?></div>
                            <div class="notification-text"><?= htmlspecialchars(substr($notif['message'], 0, 100)) ?>...</div>
                            <div class="notification-time"><i class="far fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($notif['created_at'])) ?></div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-bell-slash fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No new notifications</p></div>
                    <?php endif; ?>
                </div>
                <div class="mark-all-read"><button onclick="markAllNotificationsRead()"><i class="fas fa-check-double me-1"></i> Mark all as read</button></div>
            </div>
        </div>
    </div>

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

    <div class="card mt-4">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-history me-2"></i> 
            <?php if($status_filter == 'all'): ?>Recent Orders<?php else: ?>Orders - <?= $status_display_text ?><?php endif; ?>
            <span class="badge bg-secondary ms-2"><?= count($recent_orders) ?> orders</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Order No</th><th>Customer</th><th>Total</th><th>Branch</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody id="recentOrdersTable">
                    <?php if(count($recent_orders) > 0): ?>
                        <?php foreach($recent_orders as $order): 
                            if($order['product_availability'] == 'partial') { $status_display = 'partial'; $status_text = 'Partially Available';
                            } elseif($order['product_availability'] == 'not_available') { $status_display = 'not_available'; $status_text = 'Not Available';
                            } elseif($order['workflow_status']) { $status_display = $order['workflow_status']; $status_text = ucfirst(str_replace('_', ' ', $order['workflow_status']));
                            } else { $status_display = 'not_collected'; $status_text = 'Not Collected'; }
                        ?>
                        <tr style="cursor:pointer" onclick="window.location.href='all_orders.php'">
                            <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td>Rs. <?= number_format($order['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($order['branch_loc'] ?? 'Unassigned') ?></td>
                            <td><span class="workflow-badge status-<?= str_replace('_', '', $status_display) ?>"><?= $status_text ?></span></td>
                            <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5">No orders found with this status</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <footer class="text-center text-muted mt-4 small"><small>© 2026 Hameedia Order Management System | Click on status cards to filter orders</small></footer>
</div>

<script>
    let lastUpdateTime = <?= time() ?>;
    let refreshInterval;
    let currentFilter = <?= json_encode($status_filter) ?>;
    
    function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
    function toggleNotification() { document.getElementById('notificationDropdown').classList.toggle('show'); }
    function applyFilter(filter) { window.location.href = 'admin_dashboard.php?filter=' + filter; }
    
    async function loadLiveData() {
        try {
            let resp = await fetch(`get_live_data.php?last_update=${lastUpdateTime}&filter=${currentFilter}`);
            let data = await resp.json();
            if(data.success && data.recent_orders){
                let html = '';
                data.recent_orders.forEach(o => {
                    let statusDisplay = o.workflow_status || 'not_collected';
                    html += `<tr onclick="location.href='all_orders.php'">`;
                    html += `<td><strong>#${escapeHtml(o.order_no)}</strong></td>`;
                    html += `<td>${escapeHtml(o.customer_name)}</td>`;
                    html += `<td>Rs.${parseFloat(o.total_amount).toFixed(2)}</td>`;
                    html += `<td>${escapeHtml(o.branch_loc || 'Unassigned')}</td>`;
                    html += `<td><span class="workflow-badge status-${statusDisplay}">${statusDisplay.replace('_',' ')}</span></td>`;
                    html += `<td>${new Date(o.created_at).toLocaleDateString()}</td></tr>`;
                });
                if(html) document.getElementById('recentOrdersTable').innerHTML = html;
                lastUpdateTime = data.timestamp;
            }
        } catch(e) { console.error(e); }
    }
    
    async function markNotificationRead(id) { await fetch('mark_notification_read.php', { method:'POST', body:'id='+id }); location.reload(); }
    async function markAllNotificationsRead() {
        await fetch('mark_notification_read.php', { method:'POST', body:'all=1' });
        document.getElementById('notificationBadge').classList.add('hidden');
        document.getElementById('notificationCountText').innerHTML = '0 new';
        document.getElementById('notificationList').innerHTML = '<div class="text-center py-4"><i class="fas fa-check-circle fa-2x text-success"></i><p>All read</p></div>';
        setTimeout(() => document.getElementById('notificationDropdown').classList.remove('show'), 1500);
    }
    
    function escapeHtml(t) { if(!t) return ''; const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }
    function startAutoRefresh() { loadLiveData(); refreshInterval = setInterval(loadLiveData, 10000); }
    
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { if(window.innerWidth<992) toggleSidebar(); }));
    document.querySelector('.sidebar-overlay')?.addEventListener('click', toggleSidebar);
    startAutoRefresh();
</script>
</body>
</html>