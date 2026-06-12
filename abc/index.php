<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Hameedia | Head Office & Branch Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364); 
            height: 100vh; 
            display: flex; 
            align-items: center; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card { 
            border-radius: 30px; 
            background: white; 
            padding: 40px; 
            width: 450px; 
            box-shadow: 0 20px 35px rgba(0,0,0,0.2);
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .brand { font-weight: 800; color: #1e2a3e; font-size: 28px; }
        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            background: white;
            padding: 0 5px;
            z-index: 10;
        }
        .password-toggle:hover { color: #1e2a3e; }
        .btn-login {
            background: #1e2a3e;
            color: white;
            border-radius: 50px;
            padding: 12px;
            font-weight: 600;
            width: 100%;
            border: none;
            transition: all 0.3s;
        }
        .btn-login:hover { background: #0f1a2a; transform: translateY(-2px); }
        .input-group-text { background: #f8fafc; }
        hr { margin: 20px 0; }
        .footer-note { font-size: 0.7rem; color: #94a3b8; text-align: center; margin-top: 15px; }
    </style>
</head>
<body>
<div class="container d-flex justify-content-center">
    <div class="login-card">
        <div class="text-center mb-4">
            <i class="fas fa-store fa-3x" style="color: #1e2a3e;"></i>
            <h2 class="brand mt-2">Hameedia</h2>
            <p class="text-secondary">Head Office & Branch Login</p>
        </div>
        
        <?php
        session_start();
        if (isset($_SESSION['login_error'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> ' . $_SESSION['login_error'] . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
            unset($_SESSION['login_error']);
        }
        ?>
        
        <form method="POST" action="authenticate.php">
            <div class="mb-3">
                <label class="form-label fw-bold">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user"></i></span>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Enter your username" required autofocus>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-bold">Password</label>
                <div class="password-wrapper">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                </div>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt me-2"></i>Login →
            </button>
        </form>
        
        <hr>
        <div class="text-center">
            <small class="text-muted">
                <i class="fas fa-shield-alt me-1"></i> Secure Login for Authorized Users Only
            </small>
        </div>
        <div class="footer-note">
            <i class="fas fa-laptop"></i> Hameedia Order Management System
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }
</script>
</body>
</html>