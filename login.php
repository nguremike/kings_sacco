<?php
require_once 'config/config.php';
require_once 'password_functions.php';


// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $loginResult = loginUser($username, $password);

    if ($loginResult['success']) {
        $_SESSION['user_id'] = $loginResult['user']['id'];
        $_SESSION['user_name'] = $loginResult['user']['full_name'];
        $_SESSION['user_role'] = $loginResult['user']['role'];
        $_SESSION['user_username'] = $loginResult['user']['username'];

        header('Location: dashboard.php');
        exit();
    } else {
        $error = $loginResult['message'];
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Login</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Custom CSS -->
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header i {
            font-size: 60px;
            color: #667eea;
            margin-bottom: 20px;
        }

        .login-header h2 {
            color: #333;
            font-weight: 600;
        }

        .login-header p {
            color: #666;
            font-size: 14px;
        }

        .form-control {
            height: 50px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding-left: 45px;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: none;
        }

        .input-group-text {
            background: transparent;
            border: none;
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            z-index: 10;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            height: 50px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .alert {
            border-radius: 10px;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <i class="fas fa-hand-holding-usd"></i>
            <h2>Welcome Back</h2>
            <p>Sign in to access your SACCO account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3 position-relative">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input type="text" class="form-control" name="username" placeholder="Username" required>
            </div>

            <div class="mb-4 position-relative">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" class="form-control" name="password" placeholder="Password" required>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember">
                    <label class="form-check-label" for="remember">
                        Remember me
                    </label>
                </div>
                <a href="#" class="text-decoration-none" style="color: #667eea;">Forgot Password?</a>
            </div>

            <button type="submit" class="btn btn-login w-100">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </button>
        </form>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>