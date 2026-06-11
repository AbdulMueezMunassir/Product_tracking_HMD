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

// Get admin notifications - FIXED: Removed placeholder that had no parameter
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
$completed = $pdo->query("SELECT COUNT(*) FROM shop_orders WHERE workflow_status = 'completed' OR (packing_status = 'Yes' AND dispatch_status = 'Yes')")->fetchColumn();
$branches_count = $pdo->query("SELECT COUNT(*) FROM branch_managers")->fetchColumn();

// Get recent orders
$recent_orders = $pdo->query("SELECT o.*, b.location as branch_loc FROM shop_orders o LEFT JOIN branch_managers b ON o.branch_id = b.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
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
        
        .sidebar {
            width: 280px;
            position: fixed;
            top: 0;
            left: -280px;
            height: 100vh;
            background: linear-gradient(135deg, #1e2a3e, #15232e);
            color: white;
            padding: 20px;
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar.open { left: 0; }
        .main-content { margin-left: 0; padding: 25px 35px; transition: margin-left 0.3s; }
        .nav-link { color: #cfdde6; padding: 12px 20px; margin: 5px 0; border-radius: 12px; text-decoration: none; display: block; transition: all 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .nav-link i { width: 28px; }
        
        @media (min-width: 992px) {
            .sidebar { left: 0; width: 280px; }
            .menu-toggle { display: none; }
            .main-content { margin-left: 280px; }
        }
        
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .menu-toggle {
                display: block;
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1001;
                background: #1e2a3e;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 10px;
                cursor: pointer;
            }
            .top-header { margin-top: 50px; }
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            display: none;
        }
        .sidebar-overlay.active { display: block; }
        
        .top-header {
            background: white;
            border-radius: 20px;
            padding: 15px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .notification-container { position: relative; cursor: pointer; }
        .notification-bell {
            background: #f8fafc;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .notification-bell:hover { background: #e2e8f0; transform: scale(1.05); }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: pulse 1.5s infinite;
        }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); background: #dc2626; } 100% { transform: scale(1); } }
        
        .notification-dropdown {
            position: absolute;
            top: 55px;
            right: 0;
            width: 380px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
            max-height: 450px;
            overflow-y: auto;
        }
        .notification-dropdown.show { display: block; }
        .notification-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            background: #f8fafc;
            border-radius: 20px 20px 0 0;
            color: #1e293b;
        }
        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: block;
            color: #1e293b;
        }
        .notification-item:hover { background: #f1f5f9; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; }
        .notification-text { font-size: 0.75rem; color: #64748b; }
        .notification-time { font-size: 0.7rem; color: #94a3b8; margin-top: 8px; }
        .mark-all-read {
            padding: 12px 20px;
            text-align: center;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 20px 20px;
        }
        .mark-all-read button {
            background: none;
            border: none;
            color: #0284c7;
            font-size: 0.85rem;
            cursor: pointer;
            width: 100%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card-stats {
            border-radius: 20px;
            border: none;
            transition: transform 0.2s;
            cursor: pointer;
        }
        .card-stats:hover { transform: translateY(-5px); }
        .table-responsive { overflow-x: auto; }
        .table th { background: #f8fafc; white-space: nowrap; }
        
        .workflow-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        .status-not_collected { background: #fee2e2; color: #991b1b; }
        .status-collecting { background: #fef3c7; color: #92400e; }
        .status-packing { background: #dbeafe; color: #1e40af; }
        .status-dispatching { background: #dcfce7; color: #166534; }
        .status-completed { background: #dcfce7; color: #166534; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-number { font-size: 1.3rem; }
            .top-header { flex-direction: column; gap: 10px; text-align: center; }
            .notification-dropdown { width: 320px; right: -60px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4">
        <i class="fas fa-store fa-2x"></i>
        <h4 class="mt-2">Hameedia</h4>
        <small class="text-secondary">Head Office Admin</small>
    </div>
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
    <div class="top-header d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="fas fa-chart-line"></i> Dashboard</h2>
            <small class="text-muted">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?></small>
        </div>
        <div class="notification-container">
            <div class="notification-bell" onclick="toggleNotification()">
                <i class="fas fa-bell fa-lg" style="color: #1e2a3e;"></i>
                <span class="notification-badge <?= $notification_count == 0 ? 'hidden' : '' ?>" id="notificationBadge"><?= $notification_count ?></span>
            </div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <i class="fas fa-bell me-2"></i> Notifications
                    <span class="badge bg-primary ms-2" id="notificationCountText"><?= $notification_count ?> new</span>
                </div>
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
        <div class="card card-stats bg-primary text-white p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-white-50">Total Orders</small><h2 class="mt-1"><?= $total_orders ?></h2></div>
                <i class="fas fa-shopping-cart fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="card card-stats bg-danger text-white p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-white-50">Not Collected</small><h2 class="mt-1"><?= $not_collected ?></h2></div>
                <i class="fas fa-times-circle fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="card card-stats bg-warning text-dark p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-dark-50">Collecting</small><h2 class="mt-1"><?= $collecting ?></h2></div>
                <i class="fas fa-box fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="card card-stats bg-info text-white p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-white-50">Packing</small><h2 class="mt-1"><?= $packing ?></h2></div>
                <i class="fas fa-box-open fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="card card-stats bg-secondary text-white p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-white-50">Dispatching</small><h2 class="mt-1"><?= $dispatching ?></h2></div>
                <i class="fas fa-truck fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="card card-stats bg-success text-white p-3">
            <div class="d-flex justify-content-between">
                <div><small class="text-white-50">Completed</small><h2 class="mt-1"><?= $completed ?></h2></div>
                <i class="fas fa-check-circle fa-2x opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-history me-2"></i> Recent Orders
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order No</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="recentOrdersTable">
                    <?php foreach($recent_orders as $order): ?>
                    <tr style="cursor:pointer" onclick="window.location.href='all_orders.php'">
                        <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                        <td>Rs. <?= number_format($order['total_amount'], 2) ?></td>
                        <td><?= htmlspecialchars($order['branch_loc'] ?? 'Unassigned') ?></td>
                        <td>
                            <span class="workflow-badge status-<?= $order['workflow_status'] ?: 'not_collected' ?>">
                                <?= ucfirst(str_replace('_', ' ', $order['workflow_status'] ?? 'Not Collected')) ?>
                            </span>
                        </td>
                        <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            <td>
        </div>
    </div>
    
    <footer class="text-center text-muted mt-4 small">
        <small>© 2026 Hameedia Order Management System</small>
    </footer>
</div>

<script>
    let lastUpdateTime = <?= time() ?>;
    let refreshInterval;
    
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
    }
    
    function toggleNotification() {
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.classList.toggle('show');
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    async function loadLiveData() {
        try {
            const response = await fetch(`get_live_data.php?last_update=${lastUpdateTime}`);
            const data = await response.json();
            if (data.success) {
                let tableHtml = '';
                if (data.recent_orders && data.recent_orders.length > 0) {
                    data.recent_orders.forEach(order => {
                        let statusClass = order.workflow_status || 'not_collected';
                        tableHtml += `<tr style="cursor:pointer" onclick="window.location.href='all_orders.php'">
                            <td><strong>#${escapeHtml(order.order_no)}</strong></td>
                            <td>${escapeHtml(order.customer_name)}</td>
                            <td>Rs. ${parseFloat(order.total_amount).toFixed(2)}</td>
                            <td>${escapeHtml(order.branch_loc || 'Unassigned')}</td>
                            <td><span class="workflow-badge status-${statusClass}">${statusClass.replace('_', ' ')}</span></td>
                            <td>${new Date(order.created_at).toLocaleDateString()}</td>
                            </table>`;
                    });
                } else {
                    tableHtml = '<tr><td colspan="6" class="text-center py-3">No orders yet.</td></tr>';
                }
                document.getElementById('recentOrdersTable').innerHTML = tableHtml;
                lastUpdateTime = data.timestamp;
            }
        } catch (error) {
            console.error('Error loading live data:', error);
        }
    }
    
    async function markNotificationRead(id) {
        await fetch('mark_notification_read.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id 
        });
    }
    
    async function markAllNotificationsRead() {
        await fetch('mark_notification_read.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'all=1' 
        });
        document.getElementById('notificationBadge').classList.add('hidden');
        document.getElementById('notificationCountText').innerHTML = '0 new';
        document.getElementById('notificationList').innerHTML = '<div class="text-center py-4"><i class="fas fa-check-circle fa-2x text-success mb-2"></i><p class="text-muted mb-0">All notifications marked as read</p></div>';
        setTimeout(() => {
            document.getElementById('notificationDropdown').classList.remove('show');
        }, 1500);
    }
    
    function startAutoRefresh() {
        loadLiveData();
        refreshInterval = setInterval(loadLiveData, 10000);
    }
    
    // Close sidebar when clicking on a link (mobile)
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                toggleSidebar();
            }
        });
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const container = document.querySelector('.notification-container');
        const dropdown = document.getElementById('notificationDropdown');
        if (container && !container.contains(event.target) && dropdown) {
            dropdown.classList.remove('show');
        }
    });
    
    startAutoRefresh();
</script>
</body>
</html>