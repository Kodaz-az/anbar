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

// Check if user has admin role
if (!hasRole(ROLE_ADMIN)) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get admin's information
$adminId = $_SESSION['user_id'];
$adminName = $_SESSION['fullname'];

// Get user ID from URL
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    header('Location: users.php');
    exit;
}

// Get user information
$conn = getDBConnection();
$sql = "SELECT u.*, b.name as branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header('Location: users.php');
    exit;
}

// Get additional user information based on role
$additionalInfo = [];

if ($user['role'] === 'customer') {
    // Get customer information
    $sql = "SELECT * FROM customers WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $customerInfo = $stmt->get_result()->fetch_assoc();
    
    if ($customerInfo) {
        $additionalInfo = $customerInfo;
        
        // Get order statistics
        $sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_spent,
                SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
                MAX(order_date) as last_order_date
            FROM orders
            WHERE customer_id = ?";
            
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $customerInfo['id']);
        $stmt->execute();
        $orderStats = $stmt->get_result()->fetch_assoc();
        
        if ($orderStats) {
            $additionalInfo = array_merge($additionalInfo, $orderStats);
        }
    }
} elseif ($user['role'] === 'seller') {
    // Get seller order statistics
    $sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_sales,
            COUNT(DISTINCT customer_id) as customer_count,
            MAX(order_date) as last_order_date
        FROM orders
        WHERE seller_id = ?";
        
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $sellerStats = $stmt->get_result()->fetch_assoc();
    
    if ($sellerStats) {
        $additionalInfo = $sellerStats;
    }
}

// Get user activity log
$sql = "SELECT al.*, u.fullname
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.user_id = ?
        ORDER BY al.timestamp DESC
        LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$activityLog = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İstifadəçi Detalları | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .user-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .user-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: 700;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .user-meta {
            color: #6b7280;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .user-role {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            margin-right: 10px;
        }
        
        .role-admin { background: #fee2e2; color: #b91c1c; }
        .role-seller { background: #e0f2fe; color: #0369a1; }
        .role-customer { background: #d1fae5; color: #065f46; }
        .role-production { background: #fef3c7; color: #92400e; }
        
        .user-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }
        .status-suspended { background: #fee2e2; color: #b91c1c; }
        
        .user-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .info-card {
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 15px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
        }
        
        .activity-time {
            color: #6b7280;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .info-list {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 10px;
        }
        
        .info-label {
            font-weight: 500;
            color: #4b5563;
        }
        
        .info-value {
            color: #1f2937;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .user-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-avatar {
                margin: 0 auto;
            }
            
            .user-info {
                text-align: center;
                width: 100%;
            }
            
            .user-meta {
                justify-content: center;
            }
            
            .user-actions {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-list {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .info-label {
                font-size: 14px;
                margin-top: 10px;
                border-bottom: 1px solid #f3f4f6;
                padding-bottom: 5px;
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
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Panel</a>
                <a href="users.php" class="active"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($adminName) ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-user"></i> İstifadəçi Detalları</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <a href="users.php">İstifadəçilər</a> / 
                    <span>İstifadəçi Detalları</span>
                </div>
            </div>

            <!-- User Header -->
            <div class="user-header">
                <div class="user-avatar">
                    <?= strtoupper(substr($user['fullname'], 0, 1)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($user['fullname']) ?></div>
                    <div class="user-meta">
                        <div class="meta-item">
                            <i class="fas fa-envelope"></i>
                            <span><?= htmlspecialchars($user['email']) ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($additionalInfo['phone'] ?? '-') ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span>Qeydiyyat: <?= formatDate($user['created_at']) ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="user-role role-<?= $user['role'] ?>">
                            <?php
                                switch ($user['role']) {
                                    case 'admin':
                                        echo 'Administrator';
                                        break;
                                    case 'seller':
                                        echo 'Satıcı';
                                        break;
                                    case 'production':
                                        echo 'İstehsalat';
                                        break;
                                    case 'customer':
                                        echo 'Müştəri';
                                        break;
                                    default:
                                        echo ucfirst($user['role']);
                                }
                            ?>
                        </span>
                        <span class="user-status status-<?= $user['status'] ?>">
                            <?php
                                switch ($user['status']) {
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
                                        echo ucfirst($user['status']);
                                }
                            ?>
                        </span>
                    </div>
                    
                    <div class="user-actions">
                        <button type="button" class="btn btn-primary edit-user-btn" 
                                data-id="<?= $user['id'] ?>"
                                data-name="<?= htmlspecialchars($user['fullname']) ?>"
                                data-email="<?= htmlspecialchars($user['email']) ?>"
                                data-role="<?= $user['role'] ?>"
                                data-branch="<?= $user['branch_id'] ?? '' ?>"
                                data-status="<?= $user['status'] ?>">
                            <i class="fas fa-edit"></i> Düzəliş et
                        </button>
                        
                        <?php if ($user['id'] !== $adminId): ?>
                            <button type="button" class="btn btn-secondary status-change-btn"
                                    data-id="<?= $user['id'] ?>"
                                    data-name="<?= htmlspecialchars($user['fullname']) ?>"
                                    data-status="<?= $user['status'] ?>">
                                <i class="fas fa-exchange-alt"></i> Status dəyiş
                            </button>
                            
                            <button type="button" class="btn btn-danger reset-password-btn"
                                    data-id="<?= $user['id'] ?>"
                                    data-name="<?= htmlspecialchars($user['fullname']) ?>">
                                <i class="fas fa-key"></i> Şifrə sıfırla
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- User Information -->
                    <div class="card info-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-info-circle"></i> İstifadəçi Məlumatları</h2>
                        </div>
                        <div class="card-body">
                            <div class="info-list">
                                <div class="info-label">İstifadəçi ID:</div>
                                <div class="info-value"><?= $user['id'] ?></div>
                                
                                <div class="info-label">Ad Soyad:</div>
                                <div class="info-value"><?= htmlspecialchars($user['fullname']) ?></div>
                                
                                <div class="info-label">E-poçt:</div>
                                <div class="info-value"><?= htmlspecialchars($user['email']) ?></div>
                                
                                <div class="info-label">Rol:</div>
                                <div class="info-value">
                                    <span class="user-role role-<?= $user['role'] ?>">
                                        <?php
                                            switch ($user['role']) {
                                                case 'admin':
                                                    echo 'Administrator';
                                                    break;
                                                case 'seller':
                                                    echo 'Satıcı';
                                                    break;
                                                case 'production':
                                                    echo 'İstehsalat';
                                                    break;
                                                case 'customer':
                                                    echo 'Müştəri';
                                                    break;
                                                default:
                                                    echo ucfirst($user['role']);
                                            }
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="user-status status-<?= $user['status'] ?>">
                                        <?php
                                            switch ($user['status']) {
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
                                                    echo ucfirst($user['status']);
                                            }
                                        ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($user['branch_name'])): ?>
                                    <div class="info-label">Filial:</div>
                                    <div class="info-value"><?= htmlspecialchars($user['branch_name']) ?></div>
                                <?php endif; ?>
                                
                                <div class="info-label">Qeydiyyat tarixi:</div>
                                <div class="info-value"><?= formatDate($user['created_at']) ?></div>
                                
                                <div class="info-label">Son giriş:</div>
                                <div class="info-value"><?= !empty($user['last_login']) ? formatDate($user['last_login']) : 'Heç vaxt' ?></div>
                                
                                <?php if ($user['role'] === 'customer' && !empty($additionalInfo)): ?>
                                    <div class="info-label">Telefon:</div>
                                    <div class="info-value"><?= htmlspecialchars($additionalInfo['phone'] ?? '-') ?></div>
                                    
                                    <?php if (!empty($additionalInfo['address'])): ?>
                                        <div class="info-label">Ünvan:</div>
                                        <div class="info-value"><?= htmlspecialchars($additionalInfo['address']) ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($additionalInfo['company'])): ?>
                                        <div class="info-label">Şirkət:</div>
                                        <div class="info-value"><?= htmlspecialchars($additionalInfo['company']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Statistics -->
                    <?php if ($user['role'] === 'customer' || $user['role'] === 'seller'): ?>
                        <div class="card info-card">
                            <div class="card-header">
                                <h2 class="card-title"><i class="fas fa-chart-pie"></i> Statistika</h2>
                            </div>
                            <div class="card-body">
                                <div class="stats-grid">
                                    <?php if ($user['role'] === 'customer'): ?>
                                        <div class="stat-card">
                                            <div class="stat-value"><?= $additionalInfo['total_orders'] ?? 0 ?></div>
                                            <div class="stat-label">Ümumi Sifariş</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= formatMoney($additionalInfo['total_spent'] ?? 0, '') ?></div>
                                            <div class="stat-label">Ümumi Alış</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= $additionalInfo['completed_orders'] ?? 0 ?></div>
                                            <div class="stat-label">Tamamlanmış</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= !empty($additionalInfo['last_order_date']) ? formatDate($additionalInfo['last_order_date'], 'd.m.Y') : '-' ?></div>
                                            <div class="stat-label">Son Sifariş</div>
                                        </div>
                                    <?php elseif ($user['role'] === 'seller'): ?>
                                        <div class="stat-card">
                                            <div class="stat-value"><?= $additionalInfo['total_orders'] ?? 0 ?></div>
                                            <div class="stat-label">Ümumi Sifariş</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= formatMoney($additionalInfo['total_sales'] ?? 0, '') ?></div>
                                            <div class="stat-label">Ümumi Satış</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= $additionalInfo['customer_count'] ?? 0 ?></div>
                                            <div class="stat-label">Müştəri Sayı</div>
                                        </div>
                                        
                                        <div class="stat-card">
                                            <div class="stat-value"><?= !empty($additionalInfo['last_order_date']) ? formatDate($additionalInfo['last_order_date'], 'd.m.Y') : '-' ?></div>
                                            <div class="stat-label">Son Satış</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Activity Log -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-history"></i> Aktivlik Tarixçəsi</h2>
                            <div class="card-actions">
                                <a href="activity-log.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-outline">
                                    <i class="fas fa-list"></i> Hamısını göstər
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($activityLog)): ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-info-circle"></i> Bu istifadəçi üçün aktivlik tapılmadı.
                                </div>
                            <?php else: ?>
                                <?php foreach ($activityLog as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php
                                                $activityIcon = 'fa-info-circle'; // Default
                                                
                                                if (strpos($activity['activity_type'], 'login') !== false) {
                                                    $activityIcon = 'fa-sign-in-alt';
                                                } elseif (strpos($activity['activity_type'], 'logout') !== false) {
                                                    $activityIcon = 'fa-sign-out-alt';
                                                } elseif (strpos($activity['activity_type'], 'order') !== false) {
                                                    $activityIcon = 'fa-clipboard-list';
                                                } elseif (strpos($activity['activity_type'], 'profile') !== false) {
                                                    $activityIcon = 'fa-user-edit';
                                                } elseif (strpos($activity['activity_type'], 'password') !== false) {
                                                    $activityIcon = 'fa-key';
                                                } elseif (strpos($activity['activity_type'], 'register') !== false) {
                                                    $activityIcon = 'fa-user-plus';
                                                }
                                            ?>
                                            <i class="fas <?= $activityIcon ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php
                                                    $activityDescription = $activity['description'];
                                                    
                                                    // Format activity type for display
                                                    if ($activity['activity_type'] === 'login') {
                                                        echo 'Hesaba giriş edildi';
                                                    } elseif ($activity['activity_type'] === 'logout') {
                                                        echo 'Hesabdan çıxış edildi';
                                                    } elseif ($activity['activity_type'] === 'register') {
                                                        echo 'Qeydiyyatdan keçdi';
                                                    } elseif ($activity['activity_type'] === 'profile_update') {
                                                        echo 'Profil məlumatları yeniləndi';
                                                    } elseif ($activity['activity_type'] === 'password_change') {
                                                        echo 'Şifrə dəyişdirildi';
                                                    } elseif ($activity['activity_type'] === 'order_create') {
                                                        echo 'Yeni sifariş yaradıldı';
                                                    } elseif ($activity['activity_type'] === 'order_update') {
                                                        echo 'Sifariş yeniləndi';
                                                    } else {
                                                        echo htmlspecialchars($activity['activity_type']);
                                                    }
                                                    
                                                    // Show description if available
                                                    if (!empty($activityDescription)) {
                                                        echo ': ' . htmlspecialchars($activityDescription);
                                                    }
                                                ?>
                                            </div>
                                            <div class="activity-time">
                                                <?= formatDate($activity['timestamp'], 'd.m.Y H:i') ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">İstifadəçi Düzəliş</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form action="user-edit.php" method="post">
                    <input type="hidden" id="edit_user_id" name="user_id" value="<?= $user['id'] ?>">
                    
                    <div class="form-group">
                        <label for="edit_fullname" class="form-label">Ad Soyad</label>
                        <input type="text" id="edit_fullname" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email" class="form-label">E-poçt</label>
                        <input type="email" id="edit_email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_role" class="form-label">Rol</label>
                                <select id="edit_role" name="role" class="form-control" required>
                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                    <option value="seller" <?= $user['role'] === 'seller' ? 'selected' : '' ?>>Satıcı</option>
                                    <option value="production" <?= $user['role'] === 'production' ? 'selected' : '' ?>>İstehsalat</option>
                                    <option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Müştəri</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_branch_id" class="form-label">Filial</label>
                                <select id="edit_branch_id" name="branch_id" class="form-control">
                                    <option value="">Seçin...</option>
                                    <?php 
                                    // Get branches for select
                                    $branchSql = "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name";
                                    $branchResult = $conn->query($branchSql);
                                    
                                    if ($branchResult && $branchResult->num_rows > 0) {
                                        while ($branch = $branchResult->fetch_assoc()) {
                                            $selected = ($user['branch_id'] == $branch['id']) ? 'selected' : '';
                                            echo '<option value="' . $branch['id'] . '" ' . $selected . '>' . htmlspecialchars($branch['name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status" class="form-label">Status</label>
                        <select id="edit_status" name="status" class="form-control" required>
                            <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Aktiv</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Deaktiv</option>
                            <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Dayandırılmış</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                        <button type="submit" class="btn btn-primary">Yadda saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div class="modal" id="statusChangeModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">İstifadəçi Statusunu Dəyiş</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form method="post" action="user-status.php">
                    <input type="hidden" id="status_user_id" name="user_id" value="<?= $user['id'] ?>">
                    
                    <p>İstifadəçi: <strong><?= htmlspecialchars($user['fullname']) ?></strong></p>
                    <p>Cari status: <span class="user-status status-<?= $user['status'] ?>">
                        <?php
                            switch ($user['status']) {
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
                                    echo ucfirst($user['status']);
                            }
                        ?>
                    </span></p>
                    
                    <div class="form-group">
                        <label for="new_status" class="form-label">Yeni Status</label>
                        <select id="new_status" name="status" class="form-control" required>
                            <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Aktiv</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Deaktiv</option>
                            <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Dayandırılmış</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                        <button type="submit" class="btn btn-primary">Yadda saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal" id="resetPasswordModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Şifrə Sıfırlama</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form method="post" action="user-reset-password.php">
                    <input type="hidden" id="reset_user_id" name="user_id" value="<?= $user['id'] ?>">
                    
                    <p>Bu istifadəçinin şifrəsi sıfırlanacaq və yeni şifrə yaradılacaq.</p>
                    <p><strong><?= htmlspecialchars($user['fullname']) ?></strong></p>
                    <p>Yeni şifrə istifadəçiyə email və ya WhatsApp vasitəsilə göndəriləcək.</p>
                    <p>Davam etmək istəyirsiniz?</p>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                        <button type="submit" class="btn btn-danger">Şifrəni Sıfırla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userMenu = document.querySelector('.header-right .user-info');
            userMenu.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const modalBackdrops = document.querySelectorAll('.modal-backdrop');
            const modalCloseButtons = document.querySelectorAll('.modal-close');
            
            // Open modals
            document.querySelector('.edit-user-btn')?.addEventListener('click', function() {
                document.getElementById('editUserModal').classList.add('show');
            });
            
            document.querySelector('.status-change-btn')?.addEventListener('click', function() {
                document.getElementById('statusChangeModal').classList.add('show');
            });
            
            document.querySelector('.reset-password-btn')?.addEventListener('click', function() {
                document.getElementById('resetPasswordModal').classList.add('show');
            });
            
            // Close modals
            modalBackdrops.forEach(backdrop => {
                backdrop.addEventListener('click', function() {
                    this.parentElement.classList.remove('show');
                });
            });
            
            modalCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('show');
                });
            });
            
            // Role-based branch visibility
            const roleSelect = document.getElementById('edit_role');
            const branchField = document.getElementById('edit_branch_id').parentElement.parentElement;
            
            roleSelect?.addEventListener('change', function() {
                if (this.value === 'seller' || this.value === 'production') {
                    branchField.style.display = 'block';
                    document.getElementById('edit_branch_id').setAttribute('required', 'required');
                } else {
                    branchField.style.display = 'none';
                    document.getElementById('edit_branch_id').removeAttribute('required');
                }
            });
            
            // Trigger change event to set initial state
            if (roleSelect) {
                roleSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>