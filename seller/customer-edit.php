<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

// Check if session has expired
if (isSessionExpired()) {
    session_unset();
    session_destroy();
    redirectToLogin();
}

// Check if user has seller role
if (!hasRole('seller')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get seller information
$sellerId = $_SESSION['user_id'];
$sellerName = $_SESSION['fullname'];

// Get customer ID from URL
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customerId <= 0) {
    header('Location: customers.php');
    exit;
}

// Get customer information
$conn = getDBConnection();
$sql = "SELECT c.*, u.email, u.status AS account_status
        FROM customers c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    header('Location: customers.php');
    exit;
}

// Process form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($fullname)) {
        $error = 'Müştəri adı daxil edilməlidir';
    } elseif (empty($phone)) {
        $error = 'Telefon nömrəsi daxil edilməlidir';
    } else {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Update customer details
            $sql = "UPDATE customers 
                    SET fullname = ?, phone = ?, company = ?, address = ?, notes = ?, updated_at = NOW(), updated_by = ?
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssii", $fullname, $phone, $company, $address, $notes, $sellerId, $customerId);
            $stmt->execute();
            
            // Update user email if exists and changed
            if (!empty($customer['user_id']) && !empty($email) && $email !== $customer['email']) {
                $sql = "UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $email, $customer['user_id']);
                $stmt->execute();
            }
            
            // If email provided but user doesn't exist, create account
            if (empty($customer['user_id']) && !empty($email) && isset($_POST['create_account'])) {
                // Generate password
                $password = generateRandomPassword();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Create user account
                $sql = "INSERT INTO users (username, email, password, fullname, role, status, created_at)
                        VALUES (?, ?, ?, ?, 'customer', 'active', NOW())";
                $username = strtolower(preg_replace('/[^a-z0-9]/', '', transliterate($fullname))) . rand(100, 999);
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $username, $email, $hashedPassword, $fullname);
                $stmt->execute();
                
                $userId = $conn->insert_id;
                
                // Link user to customer
                $sql = "UPDATE customers SET user_id = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $userId, $customerId);
                $stmt->execute();
                
                // Send welcome message with password
                if (WHATSAPP_ENABLED) {
                    require_once '../includes/whatsapp.php';
                    
                    $variables = [
                        'customer_name' => $fullname,
                        'email' => $email,
                        'password' => $password,
                        'company_name' => COMPANY_NAME,
                        'company_phone' => COMPANY_PHONE,
                        'login_url' => SITE_URL . '/auth/login.php'
                    ];
                    
                    sendWhatsAppTemplate($phone, 'new_account', $variables);
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $success = 'Müştəri məlumatları uğurla yeniləndi';
            
            // Refresh customer data
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = 'Xəta baş verdi: ' . $e->getMessage();
        }
    }
}

// Get unread messages count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $sellerId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;

/**
 * Generate a random password
 * @param int $length Password length
 * @return string Random password
 */
function generateRandomPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    return $password;
}

/**
 * Transliterate Azerbaijani text to Latin for username generation
 * @param string $text Text to transliterate
 * @return string Transliterated text
 */
function transliterate($text) {
    $replacements = [
        'ə' => 'e', 'Ə' => 'E',
        'ü' => 'u', 'Ü' => 'U',
        'ö' => 'o', 'Ö' => 'O',
        'ğ' => 'g', 'Ğ' => 'G',
        'ş' => 's', 'Ş' => 'S',
        'ç' => 'c', 'Ç' => 'C',
        'ı' => 'i', 'I' => 'I',
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $text);
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müştəri Düzəliş | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .card {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 0;
        }
        
        .form-col {
            flex: 1;
        }
        
        .switch-container {
            display: flex;
            align-items: center;
            margin-top: 20px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-right: 10px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="hesabla.php"><i class="fas fa-calculator"></i> Hesabla</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php" class="active"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="warehouse.php"><i class="fas fa-warehouse"></i> Anbar</a>
            </div>
        </div>
        <div class="header-right">
            <a href="messages.php" class="nav-link position-relative">
                <i class="fas fa-envelope"></i>
                <?php if($unreadMessages > 0): ?>
                    <span class="notification-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
            <div class="user-info">
                <span><?= htmlspecialchars($sellerName) ?> <i class="fas fa-angle-down"></i></span>
                <div class="user-menu">
                    <a href="profile.php"><i class="fas fa-user-cog"></i> Profil</a>
                    <a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Çıxış</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-main">
        <div class="app-container">
            <div class="page-header">
                <h1><i class="fas fa-user-edit"></i> Müştəri Düzəliş</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <a href="customer-view.php?id=<?= $customerId ?>">Müştəri Məlumatları</a> / 
                    <span>Düzəliş</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Müştəri Məlumatları</h2>
                </div>
                <div class="card-body">
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
                    
                    <form action="" method="post">
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="fullname" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" id="fullname" name="fullname" class="form-control" value="<?= htmlspecialchars($customer['fullname']) ?>" required>
                                </div>
                            </div>
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="phone" class="form-label">Telefon <span class="text-danger">*</span></label>
                                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="email" class="form-label">E-poçt</label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                                    <div class="form-text">Hesab yaratmaq istəyirsinizsə e-poçt tələb olunur</div>
                                </div>
                            </div>
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="company" class="form-label">Şirkət</label>
                                    <input type="text" id="company" name="company" class="form-control" value="<?= htmlspecialchars($customer['company'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address" class="form-label">Ünvan</label>
                            <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($customer['address'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="notes" class="form-label">Qeydlər</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea>
                        </div>
                        
                        <?php if (empty($customer['user_id'])): ?>
                            <div class="switch-container">
                                <label class="switch">
                                    <input type="checkbox" name="create_account" value="1" id="create_account">
                                    <span class="slider"></span>
                                </label>
                                <label for="create_account">Müştəri üçün hesab yarat</label>
                                <div class="form-text ml-2">(Avtomatik şifrə yaradılıb WhatsApp ilə göndəriləcək)</div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i> Bu müştərinin artıq hesabı var. Status: 
                                <strong>
                                    <?php
                                        switch ($customer['account_status']) {
                                            case 'active':
                                                echo 'Aktiv';
                                                break;
                                            case 'inactive':
                                                echo 'Deaktiv';
                                                break;
                                            case 'suspended':
                                                echo 'Dayandırılmış';
                                                break;
                                            default:
                                                echo ucfirst($customer['account_status'] ?? 'Unknown');
                                        }
                                    ?>
                                </strong>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Yadda Saxla
                            </button>
                            <a href="customer-view.php?id=<?= $customerId ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Ləğv Et
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userInfo = document.querySelector('.user-info');
            userInfo.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Email field requirement based on create account checkbox
            const createAccountCheckbox = document.getElementById('create_account');
            const emailField = document.getElementById('email');
            
            if (createAccountCheckbox) {
                createAccountCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        emailField.setAttribute('required', 'required');
                    } else {
                        emailField.removeAttribute('required');
                    }
                });
            }
            
            // Initialize phone number formatting
            const phoneField = document.getElementById('phone');
            
            phoneField.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                
                if (value.length > 0) {
                    if (value.length <= 3) {
                        value = '+' + value;
                    } else if (value.length <= 5) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3);
                    } else if (value.length <= 8) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5);
                    } else if (value.length <= 10) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8);
                    } else {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8, 10) + ' ' + value.substring(10);
                    }
                }
                
                e.target.value = value;
            });
        });
    </script>
</body>
</html>