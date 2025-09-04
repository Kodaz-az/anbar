<?php
session_start();
require_once '../config/config.php';
require_once '../includes/functions.php';

// Log unauthorized access attempt if user is logged in
if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'unknown';
    $requestedUrl = $_SERVER['HTTP_REFERER'] ?? 'unknown';
    
    logActivity($userId, 'unauthorized_access', "User with role '$role' attempted to access: $requestedUrl");
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İcazəsiz Giriş | AlumPro.az</title>
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
        }
        
        .unauthorized-container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            text-align: center;
        }
        
        .unauthorized-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: 30px;
        }
        
        .unauthorized-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        
        .unauthorized-title {
            font-size: 24px;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .unauthorized-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .unauthorized-body {
            padding: 30px;
        }
        
        .unauthorized-message {
            margin-bottom: 30px;
            line-height: 1.6;
            color: #4b5563;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.3s;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #4b5563;
            margin-right: 10px;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .btn-group {
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        
        @media (max-width: 480px) {
            .unauthorized-container {
                border-radius: 0;
                box-shadow: none;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="unauthorized-container">
        <div class="unauthorized-header">
            <div class="unauthorized-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h1 class="unauthorized-title">İcazəsiz Giriş</h1>
            <p class="unauthorized-subtitle">Bu səhifəyə baxmaq üçün icazəniz yoxdur</p>
        </div>
        
        <div class="unauthorized-body">
            <p class="unauthorized-message">
                Bu səhifəyə baxmaq üçün lazımi səlahiyyətlərə malik deyilsiniz. Əgər bu səhifəyə 
                giriş etməyə icazənizin olduğunu düşünürsünüzsə, zəhmət olmasa, administratorla əlaqə 
                saxlayın və ya aşağıdakı düymələrdən birini klikləyin.
            </p>
            
            <div class="btn-group">
                <?php if (isLoggedIn()): ?>
                    <a href="<?= getHomepageForRole($_SESSION['role'] ?? '') ?>" class="btn btn-secondary">
                        <i class="fas fa-home"></i> Ana Səhifə
                    </a>
                    <a href="logout.php" class="btn">
                        <i class="fas fa-sign-out-alt"></i> Çıxış
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Giriş
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
/**
 * Get homepage URL based on user role
 * @param string $role User role
 * @return string Homepage URL
 */
function getHomepageForRole($role) {
    switch ($role) {
        case ROLE_ADMIN:
            return '../admin/index.php';
        case ROLE_SELLER:
            return '../seller/index.php';
        case ROLE_CUSTOMER:
            return '../customer/index.php';
        case ROLE_PRODUCTION:
            return '../production/index.php';
        default:
            return '../index.php';
    }
}
?>