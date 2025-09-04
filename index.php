<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is already logged in
if (isLoggedIn()) {
    // Redirect based on user role
    redirectBasedOnRole();
    exit;
}

/**
 * Redirect user based on role
 */
function redirectBasedOnRole() {
    switch ($_SESSION['role']) {
        case ROLE_ADMIN:
            header('Location: admin/index.php');
            break;
        case ROLE_SELLER:
            header('Location: seller/index.php');
            break;
        case ROLE_CUSTOMER:
            header('Location: customer/index.php');
            break;
        case ROLE_PRODUCTION:
            header('Location: production/index.php');
            break;
        default:
            header('Location: auth/login.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AlumPro.az - Alüminium və Şüşə Sifarişi</title>
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
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            color: var(--text-dark);
            line-height: 1.6;
        }
        
        .hero {
            min-height: 100vh;
            background-image: var(--primary-gradient), url('assets/images/hero-bg.jpg');
            background-size: cover;
            background-position: center;
            background-blend-mode: multiply;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 2rem;
            text-align: center;
        }
        
        .hero-content {
            max-width: 800px;
            z-index: 1;
        }
        
        .logo {
            max-width: 200px;
            margin-bottom: 2rem;
        }
        
        h1 {
            font-size: 3rem;
            font-weight: 700;
            color: var(--text-light);
            margin-bottom: 1.5rem;
        }
        
        .hero-description {
            font-size: 1.2rem;
            color: var(--text-light);
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: var(--text-light);
            border: 2px solid var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--text-light);
            color: var(--primary-color);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--text-light);
            border: 2px solid var(--text-light);
        }
        
        .btn-outline:hover {
            background-color: var(--text-light);
            color: var(--primary-color);
        }
        
        .header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
        }
        
        .header-logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-light);
            text-decoration: none;
        }
        
        .nav-links {
            display: flex;
            gap: 1.5rem;
        }
        
        .nav-link {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s ease;
        }
        
        .nav-link:hover {
            opacity: 0.8;
        }
        
        .login-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            color: var(--text-light);
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        
        .login-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        @media (max-width: 768px) {
            h1 {
                font-size: 2.5rem;
            }
            
            .hero-description {
                font-size: 1rem;
            }
            
            .header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-links {
                display: none;
            }
            
            .cta-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="index.php" class="header-logo">ALUMPRO.AZ</a>
        
        <nav class="nav-links">
            <a href="#" class="nav-link">Məhsullar</a>
            <a href="#" class="nav-link">Xidmətlər</a>
            <a href="#" class="nav-link">Haqqımızda</a>
            <a href="#" class="nav-link">Əlaqə</a>
        </nav>
        
        <a href="auth/login.php" class="login-btn">
            <i class="fas fa-sign-in-alt"></i> Daxil ol
        </a>
    </header>
    
    <section class="hero">
        <div class="hero-content">
            <img src="assets/images/logo-white.png" alt="AlumPro.az Logo" class="logo">
            
            <h1>Alüminium və Şüşə Məhsullarınız üçün Etibarlı Həllər</h1>
            
            <p class="hero-description">
                AlumPro.az ilə sifarişlərinizi rahatlıqla idarə edin, statuslarını izləyin və həmişə yeniliklərdən xəbərdar olun. 
                Keyfiyyətli alüminium və şüşə məhsulları üçün etibarlı həlləriniz burada.
            </p>
            
            <div class="cta-buttons">
                <a href="auth/login.php" class="btn btn-primary">Daxil ol</a>
                <a href="auth/register.php" class="btn btn-outline">Qeydiyyatdan keç</a>
            </div>
        </div>
    </section>
</body>
</html>