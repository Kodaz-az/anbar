<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    // Redirect based on user role
    redirectBasedOnRole();
    exit;
}

$error = '';
$email = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error = 'Bütün sahələri doldurun';
    } else {
        // Get user from database
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, fullname, email, password, role, status, branch_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Check if account is active
                if ($user['status'] !== 'active') {
                    $error = 'Hesabınız aktiv deyil. Zəhmət olmasa, administratorla əlaqə saxlayın.';
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['branch_id'] = $user['branch_id'];
                    $_SESSION['last_activity'] = time();
                    
                    // Log login activity
                    logActivity($user['id'], ACTIVITY_LOGIN, 'Login successful');
                    
                    // Redirect based on user role
                    redirectBasedOnRole();
                    exit;
                }
            } else {
                $error = 'Yanlış e-poçt və ya şifrə';
                // Log failed login attempt
                logActivity(0, 'login_failed', "Failed login attempt for email: $email");
            }
        } else {
            $error = 'Yanlış e-poçt və ya şifrə';
            // Log failed login attempt
            logActivity(0, 'login_failed', "Failed login attempt for email: $email");
        }
    }
}

/**
 * Redirect user based on role
 */
function redirectBasedOnRole() {
    switch ($_SESSION['role']) {
        case ROLE_ADMIN:
            header('Location: ../admin/index.php');
            break;
        case ROLE_SELLER:
            header('Location: ../seller/index.php');
            break;
        case ROLE_CUSTOMER:
            header('Location: ../customer/index.php');
            break;
        case ROLE_PRODUCTION:
            header('Location: ../production/index.php');
            break;
        default:
            header('Location: ../index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş | AlumPro.az</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --text-light: #ffffff;
            --text-dark: #333333;
            --background-light: #f8f9fa;
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--background-light);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image: url('../assets/images/background.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        
        .login-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: 20px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 24px;
            font-weight: 500;
        }
        
        .login-form {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4b5563;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 177, 90, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 12px 15px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .login-footer {
            padding: 0 30px 30px;
            text-align: center;
        }
        
        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .login-options {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        .company-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .company-logo img {
            max-width: 150px;
            height: auto;
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 0;
                box-shadow: none;
            }
            
            .login-options {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="company-logo">
                <img src="../assets/images/logo-white.png" alt="AlumPro.az">
            </div>
            <h1>Giriş</h1>
        </div>
        
        <div class="login-form">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="email" class="form-label">E-poçt</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="E-poçt ünvanınızı daxil edin" value="<?= htmlspecialchars($email) ?>" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Şifrə</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Şifrənizi daxil edin" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Daxil ol
                    </button>
                </div>
            </form>
        </div>
        
        <div class="login-footer">
            <div class="login-options">
                <a href="reset-password.php">Şifrəni unutmuşam</a>
                <a href="register.php">Qeydiyyatdan keç</a>
            </div>
        </div>
    </div>
</body>
</html>