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
$status_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$message = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'orders';

if (!isset($_SESSION['branch_last_notification_check'])) {
    $_SESSION['branch_last_notification_check'] = time();
}

if (isset($_SESSION['branch_success_message'])) {
    $message = '<div class="alert alert-success alert-dismissible fade show">' . $_SESSION['branch_success_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    unset($_SESSION['branch_success_message']);
}

// Get branch messages (inbox)
$inbox_messages = $pdo->prepare("
    SELECT bm.*, o.order_no as order_number
    FROM branch_messages bm
    LEFT JOIN shop_orders o ON bm.order_id = o.id
    WHERE bm.branch_id = ? AND bm.is_read_branch = 0
    ORDER BY bm.created_at DESC
");
$inbox_messages->execute([$branch_id]);
$inbox_list = $inbox_messages->fetchAll();
$inbox_count = count($inbox_list);

// Get all messages (including read)
$all_messages = $pdo->prepare("
    SELECT bm.*, o.order_no as order_number
    FROM branch_messages bm
    LEFT JOIN shop_orders o ON bm.order_id = o.id
    WHERE bm.branch_id = ?
    ORDER BY bm.created_at DESC LIMIT 50
");
$all_messages->execute([$branch_id]);
$all_messages_list = $all_messages->fetchAll();

// Handle direct message to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_admin_message'])) {
    $order_id = !empty($_POST['order_id']) ? $_POST['order_id'] : null;
    $subject = $_POST['subject'];
    $message_text = $_POST['message_text'];
    $message_type = $_POST['message_type'] ?? 'general';
    
    $stmt = $pdo->prepare("INSERT INTO branch_messages (branch_id, order_id, subject, message, message_type, is_read_admin, is_read_branch) VALUES (?,?,?,?,?,0,1)");
    $stmt->execute([$branch_id, $order_id, $subject, $message_text, $message_type]);
    
    addNotification($pdo, 1, null, 'admin', 'message', 
        "Message from " . $_SESSION['branch_location'] . ": " . $subject, 
        $message_text, 
        "admin_dashboard.php?tab=messages");
    
    $_SESSION['branch_success_message'] = "Message sent to Admin!";
    header('Location: branch_dashboard.php?tab=' . $active_tab);
    exit;
}

// Handle product availability message
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
    
    $product_ids = $_POST['product_id'] ?? [];
    $product_availabilities = $_POST['product_avail'] ?? [];
    $product_reasons = $_POST['product_reason'] ?? [];
    
    $updateProduct = $pdo->prepare("UPDATE order_products SET product_availability = ?, product_availability_reason = ? WHERE id = ?");
    for ($i = 0; $i < count($product_ids); $i++) {
        $updateProduct->execute([
            $product_availabilities[$i], 
            $product_reasons[$i] ?? null, 
            $product_ids[$i]
        ]);
    }
    
    // Calculate overall availability
    $checkAvailability = $pdo->prepare("SELECT 
        SUM(CASE WHEN product_availability = 'not_available' THEN 1 ELSE 0 END) as not_available_count,
        SUM(CASE WHEN product_availability = 'available' THEN 1 ELSE 0 END) as available_count,
        COUNT(*) as total
        FROM order_products WHERE order_id = ?");
    $checkAvailability->execute([$order_id]);
    $avail_stats = $checkAvailability->fetch();
    
    if ($avail_stats['not_available_count'] == $avail_stats['total'] && $avail_stats['total'] > 0) {
        $overall_availability = 'not_available';
    } elseif ($avail_stats['not_available_count'] > 0) {
        $overall_availability = 'partial';
    } else {
        $overall_availability = 'available';
    }
    
    // Update the order
    $stmt = $pdo->prepare("UPDATE shop_orders SET 
        packing_status=?, 
        dispatch_status=?, 
        collection_status=?, 
        collection_notes=?, 
        invoice_no=?, 
        branch_comment=?, 
        barcode_number=?, 
        product_availability=?, 
        unavailability_reason=?, 
        partial_comment=?, 
        workflow_status=?, 
        branch_message_read=0,
        last_status_update=NOW(), 
        status='Confirmed', 
        updated_at=NOW() 
        WHERE id=?");
    $stmt->execute([$packing_status, $dispatch_status, $collection_status, $collection_notes, $invoice, $branch_comment, $barcode_number, $overall_availability, $unavailability_reason, $partial_comment, $workflow_status, $order_id]);
    
    // Create message for admin about availability
    $availability_msg = '';
    if ($overall_availability == 'not_available') {
        $availability_msg = "All products are NOT AVAILABLE";
        if (!empty($unavailability_reason)) {
            $availability_msg .= ": " . $unavailability_reason;
        }
    } elseif ($overall_availability == 'partial') {
        $availability_msg = "Some products are NOT AVAILABLE";
        if (!empty($partial_comment)) {
            $availability_msg .= ": " . $partial_comment;
        }
    }
    
    if (!empty($availability_msg)) {
        $stmt = $pdo->prepare("INSERT INTO branch_messages (branch_id, order_id, subject, message, message_type, is_read_admin, is_read_branch) VALUES (?,?,?,?,'availability',0,1)");
        $stmt->execute([
            $branch_id, 
            $order_id, 
            "Availability Update - Order #{$order_no}", 
            $availability_msg
        ]);
    }
    
    addNotification($pdo, 1, null, 'admin', 'order_update', 
        "Order #{$order_no} Status Updated", 
        "Branch updated order to: " . ucfirst(str_replace('_', ' ', $workflow_status)) . 
        ($availability_msg ? " | " . $availability_msg : ""), 
        "admin_dashboard.php?tab=messages");
    
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

$all_branches = $pdo->prepare("SELECT id, branch_code, location, email FROM branch_managers WHERE id != ?");
$all_branches->execute([$branch_id]);
$all_branches = $all_branches->fetchAll();

$notifications = $pdo->prepare("SELECT * FROM system_notifications WHERE branch_id = ? AND is_read = 0 ORDER BY created_at DESC");
$notifications->execute([$branch_id]);
$notification_list = $notifications->fetchAll();
$notification_count = count($notification_list);

// Build search conditions
$search_condition = "";
$search_params = [];
if (!empty($search_query)) {
    $search_condition = "AND (o.order_no LIKE ? OR o.customer_name LIKE ?)";
    $search_params = ["%$search_query%", "%$search_query%"];
}

$date_condition = "";
if (!empty($date_from)) {
    $date_condition .= " AND DATE(o.created_at) >= ?";
    $search_params[] = $date_from;
}
if (!empty($date_to)) {
    $date_condition .= " AND DATE(o.created_at) <= ?";
    $search_params[] = $date_to;
}

// Get ALL orders where this branch is EITHER primary OR secondary
$sql = "
    SELECT DISTINCT o.*, 
        (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND (op.assign_showroom = ? OR op.secondary_showroom = ?)) as items_count,
        (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND op.secondary_showroom = ?) as secondary_items_count
    FROM shop_orders o 
    INNER JOIN order_products op ON o.id = op.order_id 
    WHERE (op.assign_showroom = ? OR op.secondary_showroom = ?) 
    $search_condition $date_condition
    ORDER BY o.created_at DESC
";

$orders_query = $pdo->prepare($sql);
$params = [$branch_id, $branch_id, $branch_id, $branch_id, $branch_id];
$params = array_merge($params, $search_params);

$orders_query->execute($params);
$all_orders_for_branch = $orders_query->fetchAll();

$total_orders = count($all_orders_for_branch);
$pending_orders = 0;
$packing_count = 0;
$dispatching_count = 0;
$partial_count = 0;
$completed_count = 0;

foreach ($all_orders_for_branch as $order) {
    if ($order['workflow_status'] == 'packing') {
        $packing_count++;
    } elseif ($order['workflow_status'] == 'dispatching') {
        $dispatching_count++;
    } elseif ($order['product_availability'] == 'partial' || $order['product_availability'] == 'not_available') {
        $partial_count++;
    } elseif ($order['packing_status'] == 'Yes' && $order['dispatch_status'] == 'Yes') {
        $completed_count++;
    } else {
        $pending_orders++;
    }
}

// Apply filters for table display
$filter_condition = "";
if ($status_filter == 'pending') {
    $filter_condition = "AND (o.workflow_status = 'not_collected' OR o.workflow_status IS NULL OR o.workflow_status = 'collecting')";
} elseif ($status_filter == 'packing') {
    $filter_condition = "AND o.workflow_status = 'packing'";
} elseif ($status_filter == 'dispatching') {
    $filter_condition = "AND o.workflow_status = 'dispatching'";
} elseif ($status_filter == 'partial') {
    $filter_condition = "AND (o.product_availability = 'partial' OR o.product_availability = 'not_available')";
} elseif ($status_filter == 'completed') {
    $filter_condition = "AND o.packing_status = 'Yes' AND o.dispatch_status = 'Yes'";
}

if ($status_filter != 'all' && $status_filter != '') {
    $filtered_sql = "
        SELECT DISTINCT o.*, 
            (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND (op.assign_showroom = ? OR op.secondary_showroom = ?)) as items_count,
            (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND op.secondary_showroom = ?) as secondary_items_count
        FROM shop_orders o 
        INNER JOIN order_products op ON o.id = op.order_id 
        WHERE (op.assign_showroom = ? OR op.secondary_showroom = ?) 
        $search_condition $date_condition $filter_condition
        ORDER BY o.created_at DESC
    ";
    
    $orders_query_filtered = $pdo->prepare($filtered_sql);
    $filtered_params = [$branch_id, $branch_id, $branch_id, $branch_id, $branch_id];
    $filtered_params = array_merge($filtered_params, $search_params);
    
    $orders_query_filtered->execute($filtered_params);
    $orders_list = $orders_query_filtered->fetchAll();
} else {
    $orders_list = $all_orders_for_branch;
}

// Get selected order details
$selected_order = null;
$selected_products = [];
$is_secondary = false;
$delivery_type = 'single';
$primary_location = '';
$customer_address = '';

if ($view_order_id) {
    $stmt = $pdo->prepare("SELECT * FROM shop_orders WHERE id = ?");
    $stmt->execute([$view_order_id]);
    $selected_order = $stmt->fetch();
    
    if ($selected_order) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM order_products 
            WHERE order_id = ? AND assign_showroom = ?
        ");
        $stmt->execute([$view_order_id, $branch_id]);
        $is_primary = $stmt->fetch()['count'] > 0;
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM order_products 
            WHERE order_id = ? AND secondary_showroom = ?
        ");
        $stmt->execute([$view_order_id, $branch_id]);
        $is_secondary_branch = $stmt->fetch()['count'] > 0;
        
        if ($is_primary) {
            $stmt = $pdo->prepare("
                SELECT op.*, 
                       b1.location as primary_location, b1.branch_code as primary_code,
                       b2.location as secondary_location, b2.branch_code as secondary_code,
                       0 as is_secondary_product
                FROM order_products op 
                LEFT JOIN branch_managers b1 ON op.assign_showroom = b1.id 
                LEFT JOIN branch_managers b2 ON op.secondary_showroom = b2.id
                WHERE op.order_id = ?
            ");
            $stmt->execute([$view_order_id]);
            $selected_products = $stmt->fetchAll();
            $is_secondary = false;
        } elseif ($is_secondary_branch) {
            $stmt = $pdo->prepare("
                SELECT op.*, 
                       b1.location as primary_location, b1.branch_code as primary_code,
                       b2.location as secondary_location, b2.branch_code as secondary_code,
                       1 as is_secondary_product
                FROM order_products op 
                LEFT JOIN branch_managers b1 ON op.assign_showroom = b1.id 
                LEFT JOIN branch_managers b2 ON op.secondary_showroom = b2.id
                WHERE op.order_id = ? AND op.secondary_showroom = ?
            ");
            $stmt->execute([$view_order_id, $branch_id]);
            $selected_products = $stmt->fetchAll();
            $is_secondary = true;
            
            if (!empty($selected_products)) {
                $delivery_type = $selected_products[0]['delivery_decision'] ?? 'combine';
                $primary_info = $pdo->prepare("SELECT location, branch_code FROM branch_managers WHERE id = ?");
                $primary_info->execute([$selected_products[0]['assign_showroom']]);
                $primary = $primary_info->fetch();
                if ($primary) {
                    $primary_location = $primary['location'] . ' (' . $primary['branch_code'] . ')';
                }
                $customer_address = $selected_order['address'];
            }
        } else {
            $selected_products = [];
            $stmt = $pdo->prepare("
                SELECT op.*, 
                       b1.location as primary_location, b1.branch_code as primary_code,
                       b2.location as secondary_location, b2.branch_code as secondary_code,
                       1 as is_secondary_product
                FROM order_products op 
                LEFT JOIN branch_managers b1 ON op.assign_showroom = b1.id 
                LEFT JOIN branch_managers b2 ON op.secondary_showroom = b2.id
                WHERE op.order_id = ? AND op.secondary_showroom = ?
            ");
            $stmt->execute([$view_order_id, $branch_id]);
            $selected_products = $stmt->fetchAll();
            if (!empty($selected_products)) {
                $is_secondary = true;
                $delivery_type = $selected_products[0]['delivery_decision'] ?? 'combine';
                $primary_info = $pdo->prepare("SELECT location, branch_code FROM branch_managers WHERE id = ?");
                $primary_info->execute([$selected_products[0]['assign_showroom']]);
                $primary = $primary_info->fetch();
                if ($primary) {
                    $primary_location = $primary['location'] . ' (' . $primary['branch_code'] . ')';
                }
                $customer_address = $selected_order['address'];
            }
        }
    }
}

// Mark inbox messages as read when viewed
if ($active_tab == 'messages') {
    $pdo->prepare("UPDATE branch_messages SET is_read_branch = 1 WHERE branch_id = ? AND is_read_branch = 0")->execute([$branch_id]);
}
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
        .notification-bell { background: rgba(255,255,255,0.15); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; border: none; color: white; }
        .notification-bell:hover { background: rgba(255,255,255,0.25); }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 10px; display: flex; align-items: center; justify-content: center; animation: pulse 1.5s infinite; }
        .notification-badge.hidden { display: none; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
        
        .notification-dropdown { position: absolute; top: 50px; right: 0; width: 380px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: none; max-height: 450px; overflow-y: auto; z-index: 1000; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 700; background: #f8fafc; border-radius: 20px 20px 0 0; position: sticky; top: 0; }
        .notification-item { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; cursor: pointer; display: block; color: #1e293b; text-decoration: none; }
        .notification-item:hover { background: #f1f5f9; }
        .notification-item.unread { background: #e0f2fe; border-left: 3px solid #0284c7; }
        .notification-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; }
        .notification-text { font-size: 0.75rem; color: #64748b; }
        .notification-time { font-size: 0.7rem; color: #94a3b8; margin-top: 8px; }
        .mark-all-read { padding: 12px 20px; text-align: center; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 20px 20px; position: sticky; bottom: 0; background: white; }
        .mark-all-read button { background: none; border: none; color: #0284c7; cursor: pointer; width: 100%; font-weight: 500; }
        .mark-all-read button:hover { text-decoration: underline; }
        
        .main-container { max-width: 1400px; margin: 0 auto; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: all 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.filter-active { border: 3px solid #0284c7; }
        .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 0.85rem; }
        
        .search-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; }
        .btn-search { background: #0284c7; color: white; border: none; padding: 10px 25px; border-radius: 30px; }
        .btn-search:hover { background: #0369a1; }
        .btn-clear { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-block; }
        .btn-clear:hover { background: #475569; color: white; }
        .clear-filter-btn { background: #e2e8f0; color: #1e293b; border: none; padding: 8px 20px; border-radius: 30px; font-size: 0.8rem; margin-left: 10px; cursor: pointer; text-decoration: none; display: inline-block; }
        .clear-filter-btn:hover { background: #cbd5e1; }
        
        .orders-table-container { background: white; border-radius: 20px; overflow-x: auto; }
        .orders-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .orders-table th { background: #f8fafc; padding: 15px; border-bottom: 2px solid #e2e8f0; font-size: 0.75rem; text-transform: uppercase; }
        .orders-table td { padding: 15px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .orders-table tr:hover { background: #f1f5f9; cursor: pointer; }
        
        .workflow-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
        .status-not_collected { background: #fee2e2; color: #991b1b; }
        .status-collecting { background: #fef3c7; color: #92400e; }
        .status-packing { background: #dbeafe; color: #1e40af; }
        .status-dispatching { background: #dcfce7; color: #166534; }
        .status-partial { background: #fed7aa; color: #9a3412; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-not_available { background: #fee2e2; color: #991b1b; }
        
        .view-btn { background: #0284c7; color: white; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; font-size: 0.75rem; }
        .view-btn:hover { background: #0369a1; }
        .back-btn { background: #64748b; color: white; border: none; padding: 10px 25px; border-radius: 30px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .back-btn:hover { background: #475569; color: white; }
        
        .order-details-card { background: white; border-radius: 20px; overflow: hidden; margin-bottom: 20px; }
        .card-header-custom { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px 25px; }
        .info-section { padding: 20px 25px; border-bottom: 1px solid #e2e8f0; }
        .section-title { font-size: 1rem; font-weight: 700; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #f8fafc; padding: 12px 15px; border-radius: 12px; }
        .info-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; }
        .info-value { font-size: 1rem; font-weight: 600; margin-top: 5px; }
        
        .product-card { background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
        .product-image { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; background: white; border: 1px solid #e2e8f0; }
        .assigned-badge { background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; display: inline-block; }
        .status-form { background: #f8fafc; padding: 20px 25px; border-radius: 0 0 20px 20px; }
        .radio-group { display: flex; gap: 20px; margin-top: 10px; flex-wrap: wrap; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .btn-confirm { background: #0284c7; color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: 600; }
        .btn-confirm:hover { background: #0369a1; }
        .info-note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; }
        
        .nav-tabs .nav-link { color: #1e2a3e; border: none; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: #0284c7; color: white; border-radius: 30px; }
        .request-card { background: white; border-radius: 15px; margin-bottom: 15px; overflow: hidden; border: 1px solid #e2e8f0; }
        .request-header { background: #f8fafc; padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-content { border-radius: 20px; }
        .modal-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; border-radius: 20px 20px 0 0; }
        .modal-header .btn-close { background-color: white; }
        
        .message-box { 
            background: #fef3c7; 
            border-left: 4px solid #f59e0b; 
            padding: 15px 20px; 
            border-radius: 8px; 
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .message-box:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .message-box.admin-reply { background: #dcfce7; border-color: #22c55e; }
        .message-box .msg-subject { font-weight: 600; font-size: 1.05rem; }
        .message-box .msg-from { font-size: 0.85rem; color: #475569; }
        .message-box .msg-time { font-size: 0.7rem; color: #94a3b8; }
        .message-box .msg-body { margin-top: 8px; padding: 10px 15px; background: white; border-radius: 8px; white-space: pre-line; }
        .message-box .admin-reply-box { 
            background: #dcfce7; 
            border-left: 3px solid #22c55e; 
            padding: 12px 15px; 
            border-radius: 8px; 
            margin-top: 10px;
        }
        .message-badge { 
            background: #ef4444; 
            color: white; 
            padding: 2px 10px; 
            border-radius: 20px; 
            font-size: 0.7rem; 
            font-weight: 600;
        }
        .msg-type-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 600; }
        .msg-type-availability { background: #fee2e2; color: #991b1b; }
        .msg-type-general { background: #dbeafe; color: #1e40af; }
        .msg-type-request { background: #fef3c7; color: #92400e; }
        .msg-type-update { background: #dcfce7; color: #166534; }
        
        .delivery-alert { 
            border-radius: 12px; 
            padding: 15px 20px; 
            margin-bottom: 15px;
            border-left: 4px solid;
        }
        .delivery-combine { 
            background: #dbeafe; 
            border-color: #0284c7; 
            color: #1e3a5f;
        }
        .delivery-separate { 
            background: #fef3c7; 
            border-color: #f59e0b; 
            color: #78350f;
        }
        
        .delivery-msg {
            border-left-color: #0284c7 !important;
            background: #e0f2fe !important;
        }
        
        .badge-secondary-you {
            background: #8b5cf6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-number { font-size: 1.3rem; }
            .orders-table th, .orders-table td { padding: 10px 8px; font-size: 0.7rem; }
            .btn-confirm { width: 100%; }
            .notification-dropdown { width: 320px; right: -80px; }
            .search-card .row { flex-direction: column; }
            .message-box { padding: 12px 15px; }
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
    <a href="branch_dashboard.php?tab=requests" class="nav-link <?= $active_tab == 'requests' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> Product Requests <?= count($received_requests) > 0 ? '<span class="badge bg-danger ms-1">'.count($received_requests).'</span>' : '' ?></a>
    <a href="branch_dashboard.php?tab=messages" class="nav-link <?= $active_tab == 'messages' ? 'active' : '' ?>">
        <i class="fas fa-envelope"></i> Messages
        <?php if($inbox_count > 0): ?>
            <span class="badge bg-danger ms-1"><?= $inbox_count ?></span>
        <?php endif; ?>
    </a>
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
                    <button class="notification-bell" onclick="toggleNotification()" title="Click to view notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge <?= ($notification_count + $inbox_count) == 0 ? 'hidden' : '' ?>" id="branchNotificationBadge">
                            <?= $notification_count + $inbox_count ?>
                        </span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <i class="fas fa-bell me-2"></i> Notifications
                            <span class="badge bg-primary ms-2" id="notificationCountText"><?= $notification_count + $inbox_count ?> new</span>
                        </div>
                        <div id="notificationList">
                            <?php if($inbox_count > 0): ?>
                                <?php foreach($inbox_list as $msg): ?>
                                <a href="branch_dashboard.php?tab=messages" class="notification-item unread">
                                    <div class="notification-title"><i class="fas fa-reply text-success me-2"></i> Admin Reply</div>
                                    <div class="notification-text"><?= htmlspecialchars(substr($msg['message'], 0, 100)) ?>...</div>
                                    <div class="notification-time"><?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></div>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if($notification_count > 0): ?>
                                <?php foreach($notification_list as $notif): ?>
                                <a href="<?= htmlspecialchars($notif['link'] ?? '#') ?>" class="notification-item unread" onclick="markNotificationRead(<?= $notif['id'] ?>)">
                                    <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                    <div class="notification-text"><?= htmlspecialchars(substr($notif['message'], 0, 100)) ?>...</div>
                                    <div class="notification-time"><?= date('d M Y, h:i A', strtotime($notif['created_at'])) ?></div>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if(($notification_count + $inbox_count) == 0): ?>
                                <div class="text-center py-4"><i class="fas fa-bell-slash fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No new notifications</p></div>
                            <?php endif; ?>
                        </div>
                        <div class="mark-all-read"><button onclick="markAllNotificationsRead()"><i class="fas fa-check-double me-1"></i> Mark all as read</button></div>
                    </div>
                </div>
                <span class="user-badge"><i class="fas fa-map-marker-alt me-1"></i> <?= htmlspecialchars($_SESSION['branch_location']) ?></span>
                <span class="user-badge"><i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#messageModal"><i class="fas fa-comment"></i> Message Admin</button>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <?= $message ?>
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= $active_tab == 'orders' ? 'active' : '' ?>" href="branch_dashboard.php?tab=orders">My Orders</a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab == 'requests' ? 'active' : '' ?>" href="branch_dashboard.php?tab=requests">Product Requests <?= count($received_requests) > 0 ? '<span class="badge bg-danger ms-1">'.count($received_requests).'</span>' : '' ?></a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab == 'messages' ? 'active' : '' ?>" href="branch_dashboard.php?tab=messages">
                <i class="fas fa-envelope"></i> Messages 
                <?php if($inbox_count > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $inbox_count ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
        
        <?php if ($active_tab == 'messages'): ?>
            <!-- Messages Tab -->
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-envelope me-2"></i> Messages from Admin</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#messageModal">
                        <i class="fas fa-plus"></i> New Message
                    </button>
                </div>
                <div class="card-body">
                    <?php if(count($all_messages_list) > 0): ?>
                        <?php foreach($all_messages_list as $msg): 
                            $is_unread = ($msg['is_read_branch'] == 0);
                            $has_reply = !empty($msg['admin_reply']);
                            $type_class = 'general';
                            if($msg['message_type'] == 'availability') $type_class = 'availability';
                            elseif($msg['message_type'] == 'request') $type_class = 'request';
                            elseif($msg['message_type'] == 'update') $type_class = 'update';
                            
                            $is_delivery_msg = strpos($msg['subject'], 'Combine & Pack') !== false || 
                                               strpos($msg['subject'], 'Separate Delivery') !== false;
                            
                            $msg_style = $is_delivery_msg ? 'delivery-msg' : '';
                        ?>
                        <div class="message-box <?= $has_reply ? 'admin-reply' : '' ?> <?= $is_unread ? 'unread' : '' ?> <?= $msg_style ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <div>
                                    <span class="msg-from">
                                        <i class="fas fa-user-tie"></i> 
                                        <strong>Admin (Head Office)</strong>
                                    </span>
                                    <?php if($msg['order_number']): ?>
                                        <span class="badge bg-secondary ms-2">Order #<?= htmlspecialchars($msg['order_number']) ?></span>
                                    <?php endif; ?>
                                    <?php if($is_unread): ?>
                                        <span class="badge bg-primary ms-1">New</span>
                                    <?php endif; ?>
                                    <span class="msg-type-badge msg-type-<?= $type_class ?> ms-1"><?= ucfirst($msg['message_type']) ?></span>
                                    <?php if($is_delivery_msg): ?>
                                        <span class="badge bg-info ms-1">📦 Delivery</span>
                                    <?php endif; ?>
                                </div>
                                <span class="msg-time"><i class="far fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></span>
                            </div>
                            <div class="msg-subject mt-2"><?= htmlspecialchars($msg['subject']) ?></div>
                            <div class="msg-body" style="white-space: pre-line;"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                            
                            <?php if($has_reply): ?>
                                <div class="admin-reply-box">
                                    <strong class="text-success"><i class="fas fa-reply"></i> Admin Reply:</strong>
                                    <?= nl2br(htmlspecialchars($msg['admin_reply'])) ?>
                                    <br><small class="text-muted"><?= date('d M Y, h:i A', strtotime($msg['admin_reply_date'])) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-4x d-block mb-3"></i>
                            <h5>No messages</h5>
                            <p>Messages from Admin will appear here.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#messageModal">
                                <i class="fas fa-paper-plane"></i> Send Message to Admin
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php elseif ($active_tab == 'requests'): ?>
            <!-- Requests Tab -->
            <div class="row">
                <div class="col-md-6">
                    <h4>Received Requests <?= count($received_requests) > 0 ? '<span class="badge bg-danger">'.count($received_requests).'</span>' : '' ?></h4>
                    <?php foreach($received_requests as $req): ?>
                    <div class="request-card">
                        <div class="request-header">
                            <strong>From <?= htmlspecialchars($req['requesting_code']) ?></strong>
                            <span class="badge bg-warning ms-2">Pending</span>
                        </div>
                        <div class="p-3">
                            <div><strong>Order #<?= $req['order_no'] ?></strong> - Customer: <?= $req['customer_name'] ?></div>
                            <div>Product: <?= $req['product_name'] ?> (SKU: <?= $req['sku'] ?>, Size: <?= $req['size'] ?>)</div>
                            <div class="mt-2">"<?= htmlspecialchars($req['request_message']) ?>"</div>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <textarea name="response_message" class="form-control" rows="2" placeholder="Response message..."><?= htmlspecialchars($req['response_message'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-6">
                                        <button type="submit" name="accept_request" class="btn btn-success w-100">✓ Accept</button>
                                    </div>
                                    <div class="col-6">
                                        <button type="submit" name="respond_to_request" value="reject" class="btn btn-danger w-100">✗ Reject</button>
                                    </div>
                                    <input type="hidden" name="status" value="">
                                </div>
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
                            <div><strong>Order #<?= $req['order_no'] ?></strong> - Customer: <?= $req['customer_name'] ?></div>
                            <div>Product: <?= $req['product_name'] ?> (SKU: <?= $req['sku'] ?>, Size: <?= $req['size'] ?>)</div>
                            <div>"<?= htmlspecialchars($req['request_message']) ?>"</div>
                            <?php if($req['response_message']): ?><div class="mt-2 p-2 bg-light rounded">Response: "<?= htmlspecialchars($req['response_message']) ?>"</div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php elseif ($view_order_id && $selected_order): ?>
            <!-- Order View -->
            <a href="branch_dashboard.php?tab=orders&filter=<?= $status_filter ?>" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            
            <!-- Delivery Decision Alert for Secondary Showroom -->
            <?php if($is_secondary && $delivery_type == 'combine'): ?>
                <div class="delivery-alert delivery-combine">
                    <i class="fas fa-boxes fa-2x float-start me-3"></i>
                    <div>
                        <h5 class="mb-1">📦 Combine & Pack Request</h5>
                        <p class="mb-0">
                            <strong>Instruction:</strong> Please send the assigned products to the 
                            <strong>Primary Showroom</strong> for combined packing.
                            <br><strong>Send to:</strong> <?= htmlspecialchars($primary_location) ?>
                        </p>
                    </div>
                </div>
            <?php elseif($is_secondary && $delivery_type == 'separate'): ?>
                <div class="delivery-alert delivery-separate">
                    <i class="fas fa-truck fa-2x float-start me-3"></i>
                    <div>
                        <h5 class="mb-1">🚚 Separate Delivery Request</h5>
                        <p class="mb-0">
                            <strong>Instruction:</strong> Please send the assigned products directly to the customer address.
                            <br><strong>Address:</strong> <?= nl2br(htmlspecialchars($customer_address)) ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="order-details-card">
                <div class="card-header-custom">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div><h4><i class="fas fa-shopping-cart me-2"></i> Order #<?= htmlspecialchars($selected_order['order_no']) ?></h4>
                        <p class="mb-0 opacity-75"><i class="far fa-clock me-1"></i> <?= date('l, F j, Y \a\t h:i A', strtotime($selected_order['created_at'])) ?></p></div>
                        <span class="badge bg-secondary"><?= htmlspecialchars($selected_order['status']) ?></span>
                    </div>
                </div>
                
                <div class="info-section">
                    <div class="info-grid">
                        <div class="info-item"><div class="info-label">Customer</div><div class="info-value"><?= htmlspecialchars($selected_order['customer_name']) ?></div></div>
                        <div class="info-item"><div class="info-label">Contact</div><div class="info-value"><?= htmlspecialchars($selected_order['contact_no'] ?: '-') ?></div></div>
                        <div class="info-item"><div class="info-label">Address</div><div class="info-value"><?= nl2br(htmlspecialchars($selected_order['address'] ?: '-')) ?></div></div>
                        <div class="info-item"><div class="info-label">Payment</div><div class="info-value"><?= htmlspecialchars($selected_order['payment_mode'] ?: '-') ?></div></div>
                        <div class="info-item"><div class="info-label">Payment ID</div><div class="info-value"><?= htmlspecialchars($selected_order['payment_id'] ?: '-') ?></div></div>
                        <div class="info-item"><div class="info-label">Delivery Decision</div><div class="info-value">
                            <?php if($is_secondary && $delivery_type == 'combine'): ?>
                                <span class="badge bg-info">Combine & Pack</span>
                            <?php elseif($is_secondary && $delivery_type == 'separate'): ?>
                                <span class="badge bg-warning">Separate Deliveries</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Single Delivery</span>
                            <?php endif; ?>
                        </div></div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h6 class="section-title"><i class="fas fa-box me-2"></i>Products Ordered</h6>
                    <?php if(!empty($selected_products)): ?>
                        <?php foreach($selected_products as $product): ?>
                        <div class="product-card">
                            <div class="d-flex gap-3 flex-wrap">
                                <?php if($product['image_url']): ?>
                                    <img src="<?= htmlspecialchars($product['image_url']) ?>" class="product-image" onerror="this.src='https://via.placeholder.com/60x60?text=No+Image'">
                                <?php else: ?>
                                    <div class="product-image d-flex align-items-center justify-content-center bg-light"><i class="fas fa-image fa-2x text-muted"></i></div>
                                <?php endif; ?>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?= htmlspecialchars($product['product_name']) ?></strong><br>
                                            <small>Promo: <?= htmlspecialchars($product['promocode'] ?? '') ?> | Size <?= $product['size'] ?> | SKU: <?= $product['sku'] ?></small>
                                            <?php if($product['assign_showroom'] && $product['primary_code']): ?>
                                                <br><span class="assigned-badge"><i class="fas fa-store"></i> Primary: <?= htmlspecialchars($product['primary_code']) ?></span>
                                            <?php endif; ?>
                                            <?php if($product['secondary_showroom'] && $product['secondary_code']): ?>
                                                <?php if($product['secondary_showroom'] == $branch_id): ?>
                                                    <br><span class="assigned-badge" style="background:#8b5cf6;color:white;"><i class="fas fa-store-alt"></i> Secondary: <?= htmlspecialchars($product['secondary_code']) ?> <span class="badge bg-light text-dark ms-1">You</span></span>
                                                <?php else: ?>
                                                    <br><span class="assigned-badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-store-alt"></i> Secondary: <?= htmlspecialchars($product['secondary_code']) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end"><strong class="text-success">Rs. <?= number_format($product['after_discount_price'], 2) ?></strong></div>
                                    </div>
                                    <div class="mt-2 border-top pt-2">
                                        <label class="small">Availability:</label>
                                        <div class="radio-group mt-1">
                                            <label class="radio-label">
                                                <input type="radio" name="product_avail_<?= $product['id'] ?>" value="available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] != 'not_available') ? 'checked' : '' ?>> Available
                                            </label>
                                            <label class="radio-label">
                                                <input type="radio" name="product_avail_<?= $product['id'] ?>" value="not_available" class="product-avail" data-product="<?= $product['id'] ?>" <?= ($product['product_availability'] == 'not_available') ? 'checked' : '' ?>> Not Available
                                            </label>
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-warning" onclick="showRequestModal(<?= $product['id'] ?>, '<?= addslashes($product['product_name']) ?>', '<?= $product['sku'] ?>', '<?= $product['size'] ?>', <?= $selected_order['id'] ?>)">Request from Another Branch</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">No products assigned to your showroom for this order.</div>
                    <?php endif; ?>
                </div>
                <div class="status-form">
                    <form method="POST">
                        <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                        <?php foreach($selected_products as $product): ?>
                            <input type="hidden" name="product_id[]" value="<?= $product['id'] ?>">
                            <input type="hidden" name="product_avail[]" id="product_avail_hidden_<?= $product['id'] ?>" value="<?= $product['product_availability'] ?? 'available' ?>">
                            <input type="hidden" name="product_reason[]" id="product_reason_hidden_<?= $product['id'] ?>" value="">
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
                            <div class="col-md-6"><label class="fw-bold"><i class="fas fa-barcode"></i> Courier tracking number</label><input type="text" name="barcode_number" class="form-control" value="<?= htmlspecialchars($selected_order['barcode_number'] ?? '') ?>"></div>
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
                        
                        <div id="partial_comment_div" style="display: <?= ($selected_order['product_availability'] == 'partial') ? 'block' : 'none' ?>;">
                            <textarea name="partial_comment" class="form-control mt-2" rows="2" placeholder="Partial details..."><?= htmlspecialchars($selected_order['partial_comment'] ?? '') ?></textarea>
                        </div>
                        <div id="unavailability_reason_div" style="display: <?= ($selected_order['product_availability'] == 'not_available') ? 'block' : 'none' ?>;">
                            <textarea name="unavailability_reason" class="form-control mt-2" rows="2" placeholder="Reason..."><?= htmlspecialchars($selected_order['unavailability_reason'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="text-end mt-4">
                            <button type="submit" name="update_order" class="btn-confirm">
                                <i class="fas fa-check-circle me-1"></i> Confirm & Share to Admin
                            </button>
                        </div>
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
            <!-- Dashboard View -->
            <?php if($status_filter != 'all'): ?>
                <div class="mb-3 text-end">
                    <a href="branch_dashboard.php?tab=orders" class="clear-filter-btn"><i class="fas fa-times"></i> Clear Filter: <?= ucfirst(str_replace('_', ' ', $status_filter)) ?></a>
                </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card <?= $status_filter == 'all' ? 'filter-active' : '' ?>" onclick="applyFilter('all')"><div class="stat-number"><?= $total_orders ?></div><div class="stat-label">Total Orders</div></div>
                <div class="stat-card <?= $status_filter == 'pending' ? 'filter-active' : '' ?>" onclick="applyFilter('pending')"><div class="stat-number"><?= $pending_orders ?></div><div class="stat-label">Pending</div></div>
                <div class="stat-card <?= $status_filter == 'packing' ? 'filter-active' : '' ?>" onclick="applyFilter('packing')"><div class="stat-number"><?= $packing_count ?></div><div class="stat-label">Packing</div></div>
                <div class="stat-card <?= $status_filter == 'dispatching' ? 'filter-active' : '' ?>" onclick="applyFilter('dispatching')"><div class="stat-number"><?= $dispatching_count ?></div><div class="stat-label">Dispatching</div></div>
                <div class="stat-card <?= $status_filter == 'partial' ? 'filter-active' : '' ?>" onclick="applyFilter('partial')"><div class="stat-number"><?= $partial_count ?></div><div class="stat-label">Partial/Not Avail</div></div>
                <div class="stat-card <?= $status_filter == 'completed' ? 'filter-active' : '' ?>" onclick="applyFilter('completed')"><div class="stat-number"><?= $completed_count ?></div><div class="stat-label">Completed</div></div>
            </div>
            
            <!-- Search and Date Filters -->
            <div class="search-card">
                <form method="GET" class="row g-3" action="branch_dashboard.php" id="filterForm">
                    <input type="hidden" name="tab" value="orders">
                    <input type="hidden" name="filter" value="<?= $status_filter ?>">
                    
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Search Orders</label>
                        <input type="text" name="search" class="form-control" placeholder="Order # or Customer..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                        <a href="branch_dashboard.php?tab=orders" class="btn-clear">Clear</a>
                    </div>
                </form>
            </div>
            
            <div class="orders-table-container">
                <table class="orders-table">
                    <thead><tr><th>Order No</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if(count($orders_list) > 0): ?>
                            <?php foreach($orders_list as $order): 
                                if($order['product_availability'] == 'partial') { $status_class = 'status-partial'; $status_text = 'Partial';
                                } elseif($order['product_availability'] == 'not_available') { $status_class = 'status-not_available'; $status_text = 'Not Available';
                                } elseif($order['workflow_status'] == 'packing') { $status_class = 'status-packing'; $status_text = 'Packing';
                                } elseif($order['workflow_status'] == 'dispatching') { $status_class = 'status-dispatching'; $status_text = 'Dispatching';
                                } elseif($order['workflow_status'] == 'collecting') { $status_class = 'status-collecting'; $status_text = 'Collecting';
                                } elseif($order['packing_status'] == 'Yes' && $order['dispatch_status'] == 'Yes') { $status_class = 'status-completed'; $status_text = 'Completed';
                                } else { $status_class = 'status-not_collected'; $status_text = 'Pending'; }
                            ?>
                            <tr onclick="location.href='?tab=orders&view_order=<?= $order['id'] ?>&filter=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
                                <td><strong>#<?= htmlspecialchars($order['order_no']) ?></strong></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                                <td><?= $order['items_count'] ?? 1 ?></td>
                                <td>Rs. <?= number_format($order['total_amount'], 2) ?></td>
                                <td><span class="workflow-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                                <td><button class="view-btn" onclick="event.stopPropagation();location.href='?tab=orders&view_order=<?= $order['id'] ?>&filter=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">View</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i><br>
                                <?php if($search_query || $date_from || $date_to): ?>
                                    No orders found matching your search criteria.
                                <?php else: ?>
                                    No orders assigned to your showroom yet.
                                <?php endif; ?>
                            </td></tr>
                        <?php endif; ?>
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
                <h5 class="modal-title"><i class="fas fa-envelope"></i> Send Message to Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter subject" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="message_text" class="form-control" rows="4" placeholder="Type your message to admin..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message Type</label>
                        <select name="message_type" class="form-select">
                            <option value="general">General</option>
                            <option value="request">Request</option>
                            <option value="availability">Availability Issue</option>
                            <option value="update">Order Update</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Related Order (Optional)</label>
                        <select name="order_id" class="form-select">
                            <option value="">-- Select Order --</option>
                            <?php foreach($orders_list as $order): ?>
                                <option value="<?= $order['id'] ?>">#<?= $order['order_no'] ?> - <?= $order['customer_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_admin_message" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentTab = <?= json_encode($active_tab) ?>;
    let currentFilter = <?= json_encode($status_filter) ?>;
    let currentSearch = <?= json_encode($search_query) ?>;
    let currentDateFrom = <?= json_encode($date_from) ?>;
    let currentDateTo = <?= json_encode($date_to) ?>;
    
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
        window.location.href = 'branch_dashboard.php?tab=orders&filter=' + filter + '&search=' + encodeURIComponent(currentSearch) + '&date_from=' + currentDateFrom + '&date_to=' + currentDateTo; 
    }
    
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
            let hiddenAvail = document.getElementById(`product_avail_hidden_${pid}`);
            if (this.value === 'not_available') { 
                if(hiddenAvail) hiddenAvail.value = 'not_available'; 
            } else { 
                if(hiddenAvail) hiddenAvail.value = 'available'; 
            }
        });
    });
    
    async function markNotificationRead(id) {
        try {
            const response = await fetch('mark_notification_read.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id 
            });
            const data = await response.json();
            if (data.success) {
                const badge = document.getElementById('branchNotificationBadge');
                const count = document.getElementById('notificationCountText');
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
        } catch (error) {
            console.error('Error marking notification read:', error);
        }
    }
    
    async function markAllNotificationsRead() {
        try {
            const response = await fetch('mark_notification_read.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'all=1' 
            });
            const data = await response.json();
            if (data.success) {
                document.getElementById('branchNotificationBadge').classList.add('hidden');
                document.getElementById('notificationCountText').textContent = '0 new';
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
        } catch (error) {
            console.error('Error marking all notifications read:', error);
        }
    }
    
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
    
    document.getElementById('collectionYes')?.addEventListener('change', updateWorkflowStatus);
    document.getElementById('collectionNo')?.addEventListener('change', updateWorkflowStatus);
    updateWorkflowStatus();
    
    setInterval(function() {
        const badge = document.getElementById('branchNotificationBadge');
        if (!document.getElementById('notificationDropdown').classList.contains('show')) {
            fetch('check_notifications.php?last_check=' + Date.now())
            .then(response => response.json())
            .then(data => {
                if (data.count > 0 && data.count != parseInt(badge.textContent)) {
                    badge.textContent = data.count;
                    badge.classList.remove('hidden');
                    document.getElementById('notificationCountText').textContent = data.count + ' new';
                }
            })
            .catch(error => console.error('Error refreshing notifications:', error));
        }
    }, 30000);
</script>
</body>
</html>