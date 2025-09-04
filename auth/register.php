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
$success = '';
$formData = [
    'fullname' => '',
    'email' => '',
    'phone' => '',
    'password' => '',
    'confirm_password' => ''
];

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $formData = [
        'fullname' => trim($_POST['fullname'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? ''
    ];
    
    // Validate input
    if (empty($formData['fullname'])) {
        $error = 'Ad və soyad daxil edilməlidir';
    } elseif (empty($formData['email'])) {
        $error = 'E-poçt ünvanı daxil edilməlidir';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Düzgün e-poçt ünvanı daxil edin';
    } elseif (empty($formData['phone'])) {
        $error = 'Telefon nömrəsi daxil edilməlidir';
    } elseif (empty($formData['password'])) {
        $error = 'Şifrə daxil edilməlidir';
    } elseif (strlen($formData['password']) < 6) {
        $error = 'Şifrə ən azı 6 simvol olmalıdır';
    } elseif ($formData['password'] !== $formData['confirm_password']) {
        $error = 'Şifrələr uyğun gəlmir';
    } else {
        $conn = getDBConnection();
        
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $formData['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Bu e-poçt ünvanı artıq istifadə olunur';
        } else {
            // Check if phone already exists
            $stmt = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
            $stmt->bind_param("s", $formData['phone']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Bu telefon nömrəsi artıq qeydiyyatdan keçib';
            } else {
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Hash password
                    $hashedPassword = password_hash($formData['password'], PASSWORD_DEFAULT);
                    
                    // Insert user record
                    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, status, created_at) VALUES (?, ?, ?, 'customer', 'active', NOW())");
                    $stmt->bind_param("sss", $formData['fullname'], $formData['email'], $hashedPassword);
                    $stmt->execute();
                    
                    $userId = $conn->insert_id;
                    
                    // Insert customer record
                    $stmt = $conn->prepare("INSERT INTO customers (user_id, fullname, phone, email, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("isss", $userId, $formData['fullname'], $formData['phone'], $formData['email']);
                    $stmt->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    // Log activity
                    logActivity($userId, 'register', "New customer account registered");
                    
                    // Set success message
                    $success = 'Hesabınız uğurla yaradıldı. İndi daxil ola bilərsiniz.';
                    
                    // Clear form data
                    $formData = [
                        'fullname' => '',
                        'email' => '',
                        'phone' => '',
                        'password' => '',
                        'confirm_password' => ''
                    ];
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $error = 'Qeydiyyat zamanı xəta baş verdi. Zəhmət olmasa, bir daha cəhd edin.';
                    
                    if (DEBUG_MODE) {
                        error_log("Registration error: " . $e->getMessage());
                    }
                }
            }
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
    <title>Qeydiyyat | AlumPro.az</title>
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
        
        .register-container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        
        .register-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: 20px;
            text-align: center;
        }
        
        .register-header h1 {
            font-size: 24px;
            font-weight: 500;
        }
        
        .register-form {
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
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-col {
            flex: 1;
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
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .register-footer {
            padding: 0 30px 30px;
            text-align: center;
        }
        
        .register-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
        }
        
        .register-footer a:hover {
            text-decoration: underline;
        }
        
        .company-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .company-logo img {
            max-width: 150px;
            height: auto;
        }
        
        .form-text {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        @media (max-width: 576px) {
            .register-container {
                border-radius: 0;
                box-shadow: none;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <div class="company-logo">
                <img src="../assets/images/logo-white.png" alt="AlumPro.az">
            </div>
            <h1>Qeydiyyat</h1>
        </div>
        
        <div class="register-form">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="fullname" class="form-label">Ad Soyad</label>
                    <input type="text" id="fullname" name="fullname" class="form-control" placeholder="Ad və soyadınızı daxil edin" value="<?= htmlspecialchars($formData['fullname']) ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="email" class="form-label">E-poçt</label>
                            <input type="email" id="email" name="email" class="form-control" placeholder="E-poçt ünvanınızı daxil edin" value="<?= htmlspecialchars($formData['email']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-col">
                        <div class="form-group">
                            <label for="phone" class="form-label">Telefon</label>
                            <input type="tel" id="phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX" value="<?= htmlspecialchars($formData['phone']) ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="password" class="form-label">Şifrə</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Ən azı 6 simvol" required>
                            <div class="form-text">Şifrə ən azı 6 simvol olmalıdır</div>
                        </div>
                    </div>
                    
                    <div class="form-col">
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Şifrəni təsdiqləyin</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Şifrəni təkrar daxil edin" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-user-plus"></i> Qeydiyyatdan keç
                    </button>
                </div>
            </form>
        </div>
        
        <div class="register-footer">
            <p>Artıq hesabınız var? <a href="login.php">Daxil olun</a></p>
        </div>
    </div>
</body>
</html>