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
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #1e2a3e, #15232e);
            color: white;
            padding: 20px;
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar.open { left: 0; }
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
        
        @media (min-width: 992px) {
            .sidebar { left: 0; width: 280px; }
            .sidebar-overlay { display: none !important; }
            .menu-toggle { display: none; }
            .main-content { margin-left: 280px; }
        }
        
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 15px; }
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
        
        .main-content { padding: 20px 30px; transition: margin-left 0.3s ease; }
        .card-stats { border-radius: 20px; border: none; transition: transform 0.2s; cursor: pointer; }
        .card-stats:hover { transform: translateY(-5px); }
        .nav-link { color: #cfdde6; padding: 12px 20px; margin: 5px 0; border-radius: 12px; text-decoration: none; display: block; transition: all 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .nav-link i { width: 28px; }
        
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
            transition: all 0.3s;
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
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); background: #dc2626; }
            100% { transform: scale(1); }
        }
        
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
            font-weight: 500;
        }
        .mark-all-read button:hover { text-decoration: underline; }
        
        .table-responsive { overflow-x: auto; }
        .table th { background: #f8fafc; font-weight: 600; white-space: nowrap; }
        .table td { vertical-align: middle; white-space: nowrap; }
        
        @media (max-width: 576px) {
            .stat-number { font-size: 1.5rem; }
            .card-stats h2 { font-size: 1.8rem; }
            .top-header { flex-direction: column; gap: 10px; text-align: center; }
            .notification-dropdown { width: 320px; right: -60px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

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
                <span class="notification-badge hidden" id="notificationBadge">0</span>
            </div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <i class="fas fa-bell me-2"></i> Notifications
                    <span class="badge bg-primary ms-2" id="notificationCountText">0 new</span>
                </div>
                <div id="notificationList">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">Loading notifications...</p>
                    </div>
                </div>
                <div class="mark-all-read">
                    <button onclick="markAllNotificationsRead()">
                        <i class="fas fa-check-double me-1"></i> Mark all as read
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3" id="statsContainer">
        <div class="col-6 col-md-3">
            <div class="card card-stats bg-primary text-white p-3">
                <div class="d-flex justify-content-between">
                    <div><small class="text-white-50">Total Orders</small><h2 class="mt-1" id="totalOrders">0</h2></div>
                    <i class="fas fa-shopping-cart fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stats bg-warning text-dark p-3">
                <div class="d-flex justify-content-between">
                    <div><small class="text-dark-50">Pending</small><h2 class="mt-1" id="pendingOrders">0</h2></div>
                    <i class="fas fa-clock fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stats bg-success text-white p-3">
                <div class="d-flex justify-content-between">
                    <div><small class="text-white-50">Completed</small><h2 class="mt-1" id="completedOrders">0</h2></div>
                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stats bg-info text-white p-3">
                <div class="d-flex justify-content-between">
                    <div><small class="text-white-50">Branches</small><h2 class="mt-1" id="branchesCount">0</h2></div>
                    <i class="fas fa-store fa-2x opacity-50"></i>
                </div>
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
                    <tr><th>Order No</th><th>Customer</th><th>Total</th><th>Branch</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody id="recentOrdersTable">
                    <tr><td colspan="6" class="text-center py-3">Loading orders...</td></tr>
                </tbody>
            </table>
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
        if (dropdown.classList.contains('show')) {
            loadNotifications();
        }
    }
    
    async function loadLiveData() {
        try {
            const response = await fetch(`get_live_data.php?last_update=${lastUpdateTime}`);
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('totalOrders').textContent = data.stats.total_orders;
                document.getElementById('pendingOrders').textContent = data.stats.pending_orders;
                document.getElementById('completedOrders').textContent = data.stats.completed_orders;
                document.getElementById('branchesCount').textContent = data.stats.branches_count;
                
                let tableHtml = '';
                data.recent_orders.forEach(order => {
                    let statusBadge = '';
                    if (order.packing_status === 'Yes' && order.dispatch_status === 'Yes') {
                        statusBadge = '<span class="badge bg-success">Completed</span>';
                    } else if (order.packing_status === 'Yes') {
                        statusBadge = '<span class="badge bg-info">Packed</span>';
                    } else {
                        statusBadge = '<span class="badge bg-secondary">Pending</span>';
                    }
                    tableHtml += `<tr style="cursor:pointer" onclick="window.location.href='all_orders.php'">
                        <td><strong>#${escapeHtml(order.order_no)}</strong></td>
                        <td>${escapeHtml(order.customer_name)}</td>
                        <td>Rs. ${parseFloat(order.total_amount).toFixed(2)}</td>
                        <td>${escapeHtml(order.branch_loc || 'Unassigned')}</td>
                        <td>${statusBadge}</td>
                        <td>${new Date(order.created_at).toLocaleDateString()}</td>
                    </tr>`;
                });
                document.getElementById('recentOrdersTable').innerHTML = tableHtml || '<tr><td colspan="6" class="text-center py-3">No orders yet.</td></tr>';
                
                const badge = document.getElementById('notificationBadge');
                if (data.notification_count > 0) {
                    badge.textContent = data.notification_count;
                    badge.classList.remove('hidden');
                    document.getElementById('notificationCountText').textContent = `${data.notification_count} new`;
                } else {
                    badge.classList.add('hidden');
                    document.getElementById('notificationCountText').textContent = '0 new';
                }
                lastUpdateTime = data.timestamp;
            }
        } catch (error) {
            console.error('Error loading live data:', error);
        }
    }
    
    async function loadNotifications() {
        try {
            const response = await fetch(`check_notifications.php?last_check=${lastUpdateTime}`);
            const data = await response.json();
            
            const notificationList = document.getElementById('notificationList');
            const notificationCountText = document.getElementById('notificationCountText');
            const notificationBadge = document.getElementById('notificationBadge');
            
            if (data.notifications && data.notifications.length > 0) {
                let html = '';
                data.notifications.forEach(notif => {
                    const date = new Date(notif.updated_at);
                    const formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
                    
                    let statusText = '';
                    if (notif.packing_status === 'Yes' && notif.dispatch_status === 'Yes') {
                        statusText = 'Completed';
                    } else if (notif.packing_status === 'Yes') {
                        statusText = 'Packed';
                    } else if (notif.dispatch_status === 'Yes') {
                        statusText = 'Dispatched';
                    } else {
                        statusText = 'Updated';
                    }
                    
                    html += `
                        <div class="notification-item unread" onclick="markSingleNotificationReadAndRedirect()">
                            <div class="notification-title">
                                <i class="fas fa-shopping-cart me-2"></i>
                                Order #${escapeHtml(notif.order_no)} - ${statusText}
                            </div>
                            <div class="notification-text">
                                <i class="fas fa-store me-1"></i> ${escapeHtml(notif.branch_loc || 'Branch')} |
                                <i class="fas fa-box me-1"></i> Packing: ${notif.packing_status || 'Pending'} |
                                <i class="fas fa-truck me-1"></i> Dispatch: ${notif.dispatch_status || 'Pending'}
                                ${notif.invoice_no ? ` | <i class="fas fa-file-invoice me-1"></i> Invoice: ${escapeHtml(notif.invoice_no)}` : ''}
                            </div>
                            <div class="notification-time">
                                <i class="far fa-clock me-1"></i> ${formattedDate}
                            </div>
                        </div>
                    `;
                });
                notificationList.innerHTML = html;
                notificationCountText.innerHTML = `${data.count} new`;
                notificationBadge.textContent = data.count;
                notificationBadge.classList.remove('hidden');
            } else {
                notificationList.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">No new notifications</p>
                    </div>
                `;
                notificationCountText.innerHTML = '0 new';
                notificationBadge.classList.add('hidden');
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
            document.getElementById('notificationList').innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
                    <p class="text-muted mb-0">Error loading notifications</p>
                </div>
            `;
        }
    }
    
    async function markAllNotificationsRead() {
        try {
            const response = await fetch('mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'mark_all=1'
            });
            const data = await response.json();
            
            if (data.success) {
                lastUpdateTime = data.new_timestamp;
                
                const badge = document.getElementById('notificationBadge');
                if (badge) badge.classList.add('hidden');
                
                const countText = document.getElementById('notificationCountText');
                if (countText) countText.innerHTML = '0 new';
                
                const notificationList = document.getElementById('notificationList');
                if (notificationList) {
                    notificationList.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <p class="text-muted mb-0">All notifications marked as read</p>
                        </div>
                    `;
                }
                
                setTimeout(() => {
                    const dropdown = document.getElementById('notificationDropdown');
                    if (dropdown) dropdown.classList.remove('show');
                }, 1500);
            }
        } catch (error) {
            console.error('Error marking notifications read:', error);
        }
    }
    
    function markSingleNotificationReadAndRedirect() {
        markAllNotificationsRead();
        setTimeout(() => {
            window.location.href = 'all_orders.php';
        }, 500);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function startAutoRefresh() {
        loadLiveData();
        refreshInterval = setInterval(loadLiveData, 10000);
    }
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) toggleSidebar();
        });
    });
    
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