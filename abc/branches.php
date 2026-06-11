<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_code = $_POST['branch_code'];
    $manager_name = $_POST['manager_name'];
    $location = $_POST['location'];
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO branch_managers (branch_code, manager_name, location, username, password) VALUES (?,?,?,?,?)");
        $stmt->execute([$branch_code, $manager_name, $location, $username, $password]);
        $message = '<div class="alert alert-success">Branch created! Login: ' . $username . '</div>';
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $pdo->prepare("DELETE FROM branch_managers WHERE id = ?")->execute([$id]);
    $message = '<div class="alert alert-success">Branch deleted!</div>';
}

$branches = $pdo->query("SELECT * FROM branch_managers ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Branches | Hameedia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .sidebar { width: 260px; position: fixed; height: 100vh; background: linear-gradient(135deg, #1e2a3e, #15232e); color: white; padding: 20px; }
        .main-content { margin-left: 260px; padding: 20px; }
        .nav-link { color: #cfdde6; padding: 10px 15px; margin: 5px 0; border-radius: 8px; text-decoration: none; display: block; }
        .nav-link:hover, .nav-link.active { background: #2d3e5a; color: white; }
        @media (max-width: 768px) { .sidebar { width: 220px; } .main-content { margin-left: 220px; } }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="text-center mb-4"><i class="fas fa-store fa-2x"></i><h4>Hameedia</h4><small>Admin Menu</small></div>
    <hr>
    <a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="create_order.php" class="nav-link"><i class="fas fa-plus-circle"></i> Create Order</a>
    <a href="all_orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a>
    <a href="branches.php" class="nav-link active"><i class="fas fa-store"></i> Branches</a>
    <hr>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <hr><small><i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?></small>
</div>

<div class="main-content">
    <h2><i class="fas fa-store"></i> Manage Branches</h2>
    <?= $message ?>
    
    <div class="card mb-4"><div class="card-header bg-white fw-bold"><i class="fas fa-plus-circle"></i> Add New Branch</div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Branch Code</label><input type="text" name="branch_code" class="form-control" placeholder="e.g., COL-01" required></div>
                <div class="col-md-3"><label class="form-label">Manager Name</label><input type="text" name="manager_name" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label">Location</label><input type="text" name="location" class="form-control" placeholder="e.g., Colombo Showroom" required></div>
                <div class="col-md-3"><label class="form-label">Login Username</label><input type="text" name="username" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label">Login Password</label><input type="text" name="password" class="form-control" value="admin123" required></div>
                <div class="col-md-3 align-self-end"><button type="submit" name="add_branch" class="btn btn-dark"><i class="fas fa-plus"></i> Create Branch</button></div>
            </div>
        </form>
    </div></div>
    
    <div class="card"><div class="card-header bg-white fw-bold"><i class="fas fa-list"></i> Existing Branches</div>
    <div class="table-responsive"><table class="table table-hover"><thead class="table-light"><tr><th>Code</th><th>Manager Name</th><th>Location</th><th>Username</th><th>Actions</th></tr></thead>
    <tbody><?php foreach($branches as $b): ?><tr><td><?= htmlspecialchars($b['branch_code']) ?></td><td><?= htmlspecialchars($b['manager_name']) ?></td><td><?= htmlspecialchars($b['location']) ?></td><td><?= htmlspecialchars($b['username']) ?></td><td><a href="?delete=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this branch?')"><i class="fas fa-trash"></i> Delete</a></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
</body>
</html>