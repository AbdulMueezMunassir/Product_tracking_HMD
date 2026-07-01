<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_code = trim($_POST['branch_code']);
    $manager_name = trim($_POST['manager_name']);
    $location = trim($_POST['location']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO branch_managers (branch_code, manager_name, location, email, username, password) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$branch_code, $manager_name, $location, $email, $username, $password]);
        $message = '<div class="alert alert-success">Branch created! Login: ' . htmlspecialchars($username) . '</div>';
        
        // Send email notification
        $subject = "Welcome to Hameedia Order Management System";
        $body = "<h2>Welcome to Hameedia</h2>
                 <p>Dear $manager_name,</p>
                 <p>Your branch account has been created successfully.</p>
                 <p><strong>Login Credentials:</strong></p>
                 <ul>
                     <li>Username: $username</li>
                     <li>Password: " . $_POST['password'] . "</li>
                     <li>Login URL: http://localhost/abc/index.php</li>
                 </ul>
                 <p>Please change your password after first login.</p>
                 <p>Best regards,<br>Hameedia Head Office</p>";
        sendEmailNotification($email, $subject, $body);
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $pdo->prepare("DELETE FROM branch_managers WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">Branch deleted!</div>';
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Cannot delete branch. It may be in use.</div>';
    }
}

$branches = $pdo->query("SELECT * FROM branch_managers ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Branches | Hameedia</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <a href="branches.php" class="nav-link active"><i class="fas fa-store"></i> Branches</a>
    <a href="payment_modes.php" class="nav-link"><i class="fas fa-credit-card"></i> Payment Modes</a>
    <a href="occasions.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Occasions</a>
    <a href="product_transfer.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Product Transfer</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr>
    <small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-store"></i> Manage Branches</h2>
    <?= $message ?>
    
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-plus-circle"></i> Add New Branch</div>
        <div class="card-body p-4">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Branch Code *</label>
                        <input type="text" name="branch_code" class="form-control" placeholder="e.g., COL-01" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Manager Name *</label>
                        <input type="text" name="manager_name" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Location *</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g., Colombo Showroom" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Email *</label>
                        <input type="email" name="email" class="form-control" placeholder="manager@branch.com" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Login Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Login Password *</label>
                        <input type="text" name="password" class="form-control" value="admin123" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" name="add_branch" class="btn btn-dark w-100">
                            <i class="fas fa-plus"></i> Create Branch
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><i class="fas fa-list"></i> Existing Branches</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Email</th>
                            <th>Username</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($branches as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['branch_code']) ?></strong></td>
                            <td><?= htmlspecialchars($b['manager_name']) ?></td>
                            <td><?= htmlspecialchars($b['location']) ?></td>
                            <td><?= htmlspecialchars($b['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars($b['username']) ?></td>
                            <td>
                                <a href="?delete=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this branch?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
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
</body>
</html>