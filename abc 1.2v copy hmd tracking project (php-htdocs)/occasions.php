<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Handle add occasion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_occasion'])) {
    $occasion_name = trim($_POST['occasion_name']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($occasion_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO occasions (occasion_name, is_active) VALUES (?, ?)");
            $stmt->execute([$occasion_name, $is_active]);
            $message = '<div class="alert alert-success">Occasion "' . htmlspecialchars($occasion_name) . '" added successfully!</div>';
        } catch (PDOException $e) {
            $error = '<div class="alert alert-danger">Occasion already exists!</div>';
        }
    } else {
        $error = '<div class="alert alert-danger">Please enter an occasion name.</div>';
    }
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_occasion'])) {
    $id = intval($_POST['id']);
    $occasion_name = trim($_POST['occasion_name']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($occasion_name) && $id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE occasions SET occasion_name = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$occasion_name, $is_active, $id]);
            $message = '<div class="alert alert-success">Occasion updated successfully!</div>';
        } catch (PDOException $e) {
            $error = '<div class="alert alert-danger">Error updating occasion.</div>';
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM occasions WHERE id = ?");
        $stmt->execute([$id]);
        $message = '<div class="alert alert-success">Occasion deleted successfully!</div>';
    } catch (PDOException $e) {
        $error = '<div class="alert alert-danger">Cannot delete occasion. It may be in use.</div>';
    }
}

$occasions = $pdo->query("SELECT * FROM occasions ORDER BY occasion_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Occasions | Hameedia</title>
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
        .card { border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 15px 20px; }
        .status-active { background: #dcfce7; color: #166534; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; }
        .status-inactive { background: #fee2e2; color: #991b1b; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4 class="mt-2">Hameedia</h4><small class="text-secondary">Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link"><i class="fas fa-store"></i> Branches</a>
    <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
    <a href="occasions.php" class="nav-link active"><i class="fas fa-calendar-alt"></i> Occasions</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-calendar-alt"></i> Manage Occasions</h2>
    <p class="text-muted">Manage occasions for customer orders (Birthday, Father's Day, etc.)</p>
    
    <?= $message ?>
    <?= $error ?>
    
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-plus-circle"></i> Add New Occasion</div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Occasion Name</label>
                    <input type="text" name="occasion_name" class="form-control" placeholder="e.g., Birthday, Father's Day" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="is_active" class="form-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" name="add_occasion" class="btn btn-primary w-100">
                        <i class="fas fa-plus"></i> Add Occasion
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><i class="fas fa-list"></i> Existing Occasions</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Occasion Name</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($occasions) > 0): ?>
                            <?php foreach($occasions as $occ): ?>
                            <tr>
                                <td><?= $occ['id'] ?></td>
                                <td><strong><?= htmlspecialchars($occ['occasion_name']) ?></strong></td>
                                <td>
                                    <span class="<?= $occ['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $occ['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= date('d M Y', strtotime($occ['created_at'])) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $occ['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?= $occ['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this occasion?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No occasions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php foreach($occasions as $occ): ?>
    <div class="modal fade" id="editModal<?= $occ['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Occasion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $occ['id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Occasion Name</label>
                            <input type="text" name="occasion_name" class="form-control" value="<?= htmlspecialchars($occ['occasion_name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="is_active" class="form-select">
                                <option value="1" <?= $occ['is_active'] ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$occ['is_active'] ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_occasion" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
    function toggleSidebar() { 
        document.getElementById('sidebar').classList.toggle('open'); 
        document.querySelector('.sidebar-overlay').classList.toggle('active'); 
    }
    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', function() { 
        if(window.innerWidth < 992) toggleSidebar(); 
    }));
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>