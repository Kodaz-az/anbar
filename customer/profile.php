            $passwordHash = $result['password'];
            
            // Verify current password
            if (!password_verify($currentPassword, $passwordHash)) {
                $error = 'Cari şifrə düzgün deyil';
            } else {
                // Hash new password
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update password
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $newPasswordHash, $userId);
                
                if ($stmt->execute()) {
                    $success = 'Şifrəniz uğurla yeniləndi';
                    
                    // Log activity
                    logActivity($userId, 'password_change', "Customer changed their password");
                } else {
                    $error = 'Şifrə yenilənərkən xəta baş verdi';
                }
            }
        }
    }
}

// Get unread messages count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;

// Get customer stats
$sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN order_status != 'cancelled' THEN total_amount ELSE 0 END) as total_spent,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as completed_orders
        FROM orders 
        WHERE customer_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$customerStats = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1eb15a">
    <title>Profil | AlumPro</title>
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
            --border-radius: 12px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
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
            line-height: 1.5;
            padding-bottom: 70px; /* Space for bottom navigation */
        }
        
        /* Mobile app style header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .header-actions a {
            color: var(--text-light);
            text-decoration: none;
            font-size: 18px;
            position: relative;
        }
        
        /* Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Profile header */
        .profile-header {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
        }
        
        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .profile-contact {
            color: #6b7280;
            font-size: 14px;
        }
        
        /* Profile stats */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-md);
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Profile card */
        .profile-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-md);
            overflow: hidden;
        }
        
        .card-header {
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-header i {
            color: var(--primary-color);
        }
        
        .card-body {
            padding: var(--spacing-md);
        }
        
        /* Forms */
        .form-group {
            margin-bottom: var(--spacing-md);
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #4b5563;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 177, 90, 0.1);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
            text-decoration: none;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .btn-block {
            width: 100%;
        }
        
        .btn-outline {
            background: white;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
        
        .btn-outline:hover {
            background: #f0fdf4;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        }
        
        /* Alerts */
        .alert {
            padding: var(--spacing-md);
            border-radius: 8px;
            margin-bottom: var(--spacing-md);
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        /* Tabs */
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: var(--spacing-md);
        }
        
        .tab-button {
            padding: 12px 16px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            position: relative;
        }
        
        .tab-button.active {
            color: var(--primary-color);
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 2px;
            background: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Logout button */
        .logout-button {
            margin-top: var(--spacing-md);
        }
        
        /* Bottom navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-around;
            padding: 12px 0;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #6b7280;
            font-size: 12px;
        }
        
        .nav-item.active {
            color: var(--primary-color);
        }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        /* Badge for notifications */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="header-title">Profil</div>
        <div class="header-actions">
            <a href="notifications.php">
                <i class="fas fa-bell"></i>
                <?php if($unreadMessages > 0): ?>
                    <span class="notification-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
        </div>
    </header>
    
    <div class="container">
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
        
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
            </div>
            <div class="profile-info">
                <div class="profile-name"><?= htmlspecialchars($customer['fullname']) ?></div>
                <div class="profile-contact">
                    <div><i class="fas fa-phone text-primary"></i> <?= htmlspecialchars($customer['phone']) ?></div>
                    <div><i class="fas fa-envelope text-primary"></i> <?= htmlspecialchars($customer['email']) ?></div>
                </div>
            </div>
        </div>
        
        <!-- Profile Stats -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-value"><?= $customerStats['total_orders'] ?? 0 ?></div>
                <div class="stat-label">Ümumi Sifariş</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= formatMoney($customerStats['total_spent'] ?? 0, '') ?></div>
                <div class="stat-label">Ümumi Alış</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $customerStats['completed_orders'] ?? 0 ?></div>
                <div class="stat-label">Tamamlanmış</div>
            </div>
        </div>
        
        <!-- Profile Tabs -->
        <div class="profile-card">
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="profile">Profil Məlumatları</button>
                <button class="tab-button" data-tab="password">Şifrə Dəyişmə</button>
                <button class="tab-button" data-tab="settings">Tənzimləmələr</button>
            </div>
            
            <!-- Profile Tab -->
            <div class="tab-content active" id="profile-tab">
                <div class="card-body">
                    <form action="" method="post">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label for="fullname" class="form-label">Ad Soyad</label>
                            <input type="text" id="fullname" name="fullname" class="form-control" value="<?= htmlspecialchars($customer['fullname']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">E-poçt</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Telefon</label>
                            <input type="tel" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="address" class="form-label">Ünvan</label>
                            <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($customer['address'] ?? '') ?>">
                        </div>
                        
                        <button type="submit" class="btn btn-block">
                            <i class="fas fa-save"></i> Məlumatları Yenilə
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Password Tab -->
            <div class="tab-content" id="password-tab">
                <div class="card-body">
                    <form action="" method="post">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password" class="form-label">Cari Şifrə</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password" class="form-label">Yeni Şifrə</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Şifrəni Təsdiqlə</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>
                        
                        <button type="submit" class="btn btn-block">
                            <i class="fas fa-key"></i> Şifrəni Dəyiş
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Settings Tab -->
            <div class="tab-content" id="settings-tab">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Bildiriş Tənzimləmələri</label>
                        <div class="settings-item">
                            <div class="form-check">
                                <input type="checkbox" id="notify_whatsapp" class="form-check-input" checked>
                                <label for="notify_whatsapp" class="form-check-label">WhatsApp bildirişləri</label>
                            </div>
                        </div>
                        <div class="settings-item">
                            <div class="form-check">
                                <input type="checkbox" id="notify_email" class="form-check-input">
                                <label for="notify_email" class="form-check-label">E-poçt bildirişləri</label>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="form-group">
                        <label class="form-label">Hesab Məlumatları</label>
                        <div class="settings-item">
                            <div>Qeydiyyat tarixi:</div>
                            <div><?= formatDate($customer['account_created']) ?></div>
                        </div>
                        <div class="settings-item">
                            <div>İstifadəçi ID:</div>
                            <div><?= $userId ?></div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="logout-button">
                        <a href="../auth/logout.php" class="btn btn-danger btn-block">
                            <i class="fas fa-sign-out-alt"></i> Çıxış
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="orders.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>
            <span>Sifarişlər</span>
        </a>
        <a href="messages.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span>Mesajlar</span>
            <?php if($unreadMessages > 0): ?>
                <span class="notification-badge"><?= $unreadMessages ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
    
    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Get the tab to activate
                    const tabId = this.getAttribute('data-tab');
                    
                    // Deactivate all tabs
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Activate the selected tab
                    this.classList.add('active');
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });
        });
    </script>
</body>
</html>