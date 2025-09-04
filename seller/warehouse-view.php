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
$branchId = $_SESSION['branch_id'] ?? 0;

// Check if branch ID is available
if ($branchId <= 0) {
    header('Location: index.php?error=no_branch');
    exit;
}

// Get item ID from URL
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($itemId <= 0) {
    header('Location: warehouse.php');
    exit;
}

// Get item information
$conn = getDBConnection();
$sql = "SELECT i.*, it.name as item_type_name, it.unit
        FROM inventory_items i
        JOIN item_types it ON i.item_type_id = it.id
        WHERE i.id = ? AND i.branch_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $itemId, $branchId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();

if (!$item) {
    header('Location: warehouse.php');
    exit;
}

// Get inventory history
$sql = "SELECT ih.*, u.fullname as user_name
        FROM inventory_history ih
        JOIN users u ON ih.user_id = u.id
        WHERE ih.item_id = ?
        ORDER BY ih.created_at DESC
        LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $itemId);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread messages count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $sellerId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anbar Məhsulu | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .item-header {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .item-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            flex-shrink: 0;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .item-meta {
            color: #6b7280;
            margin-bottom: 15px;
        }
        
        .item-actions {
            display: flex;
            gap: 10px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .stats-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stats-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .stats-label {
            color: #6b7280;
            font-size: 16px;
            text-align: center;
        }
        
        .history-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .history-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .icon-add {
            background: #10b981;
        }
        
        .icon-remove {
            background: #ef4444;
        }
        
        .icon-adjust {
            background: #f59e0b;
        }
        
        .history-content {
            flex: 1;
        }
        
        .history-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .history-details {
            display: flex;
            gap: 15px;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .history-note {
            font-size: 14px;
            color: #4b5563;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        
        .warning-threshold {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fef3c7;
            color: #92400e;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .status-good {
            color: #10b981;
            font-weight: 500;
        }
        
        .status-warning {
            color: #f59e0b;
            font-weight: 500;
        }
        
        .status-critical {
            color: #ef4444;
            font-weight: 500;
        }
        
        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .item-header {
                flex-direction: column;
            }
            
            .item-icon {
                margin: 0 auto;
            }
            
            .item-info {
                text-align: center;
            }
            
            .item-actions {
                justify-content: center;
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
                <a href="customers.php"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="warehouse.php" class="active"><i class="fas fa-warehouse"></i> Anbar</a>
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
                <h1><i class="fas fa-box"></i> Anbar Məhsulu</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="warehouse.php">Anbar</a> / 
                    <span>Məhsul Detalları</span>
                </div>
            </div>

            <!-- Item Header -->
            <div class="item-header">
                <div class="item-icon">
                    <?php
                    $icon = 'fa-box';
                    
                    if (strpos(strtolower($item['item_type_name']), 'profil') !== false) {
                        $icon = 'fa-grip-lines';
                    } elseif (strpos(strtolower($item['item_type_name']), 'şüşə') !== false) {
                        $icon = 'fa-square';
                    } elseif (strpos(strtolower($item['item_type_name']), 'aksesuar') !== false) {
                        $icon = 'fa-tools';
                    }
                    ?>
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div class="item-info">
                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="item-meta">
                        <div><strong>Kateqoriya:</strong> <?= htmlspecialchars($item['item_type_name']) ?></div>
                        <div><strong>Ölçü vahidi:</strong> <?= htmlspecialchars($item['unit']) ?></div>
                        <div>
                            <strong>Cari Miqdar:</strong> 
                            <?= $item['current_quantity'] ?> <?= htmlspecialchars($item['unit']) ?>
                            
                            <?php
                            $status = 'good';
                            if ($item['current_quantity'] <= $item['critical_threshold']) {
                                $status = 'critical';
                            } elseif ($item['current_quantity'] <= $item['warning_threshold']) {
                                $status = 'warning';
                            }
                            ?>
                            
                            <span class="status-<?= $status ?>">
                                <?php if ($status === 'critical'): ?>
                                    (Kritik səviyyə)
                                <?php elseif ($status === 'warning'): ?>
                                    (Diqqət)
                                <?php else: ?>
                                    (Normal)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="item-actions">
                        <a href="warehouse-edit.php?id=<?= $item['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Düzəliş et
                        </a>
                        <a href="warehouse-history.php?id=<?= $item['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-history"></i> Tam Tarixçə
                        </a>
                        <a href="warehouse.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Geri
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Threshold Warning -->
            <?php if ($status !== 'good'): ?>
                <div class="warning-threshold">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <?php if ($status === 'critical'): ?>
                            <strong>Kritik ehtiyat səviyyəsi!</strong> Cari miqdar kritik həddən aşağıdır 
                            (<?= $item['critical_threshold'] ?> <?= htmlspecialchars($item['unit']) ?>). 
                            Təcili yeni tədarük edilməlidir.
                        <?php else: ?>
                            <strong>Diqqət!</strong> Cari miqdar xəbərdarlıq həddindədir 
                            (<?= $item['warning_threshold'] ?> <?= htmlspecialchars($item['unit']) ?>). 
                            Yaxın zamanda yeni tədarük planlaşdırılmalıdır.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Stats Cards -->
                    <div class="stats-card">
                        <div class="stats-value"><?= $item['current_quantity'] ?> <?= htmlspecialchars($item['unit']) ?></div>
                        <div class="stats-label">Cari Ehtiyat</div>
                    </div>
                    
                    <!-- Item Details -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-info-circle"></i> Məhsul Haqqında</h2>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <tbody>
                                    <tr>
                                        <th>Məhsul ID:</th>
                                        <td><?= $item['id'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>Məhsul Adı:</th>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Kateqoriya:</th>
                                        <td><?= htmlspecialchars($item['item_type_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Ölçü Vahidi:</th>
                                        <td><?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Başlanğıc Miqdar:</th>
                                        <td><?= $item['initial_quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Cari Miqdar:</th>
                                        <td><?= $item['current_quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Xəbərdarlıq Həddi:</th>
                                        <td><?= $item['warning_threshold'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Kritik Hədd:</th>
                                        <td><?= $item['critical_threshold'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Qiymət:</th>
                                        <td><?= formatMoney($item['unit_price']) ?> / <?= htmlspecialchars($item['unit']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tədarükçü:</th>
                                        <td><?= htmlspecialchars($item['supplier'] ?? '-') ?></td>
                                    </tr>
                                    <tr>
                                        <th>Yaradılma Tarixi:</th>
                                        <td><?= formatDate($item['created_at']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Son Yeniləmə:</th>
                                        <td><?= formatDate($item['updated_at']) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <?php if (!empty($item['notes'])): ?>
                                <div class="mt-4">
                                    <h3 class="mb-2">Qeydlər:</h3>
                                    <div><?= nl2br(htmlspecialchars($item['notes'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Inventory History -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-history"></i> Anbar Tarixçəsi</h2>
                            <div class="card-actions">
                                <a href="warehouse-history.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline">
                                    <i class="fas fa-list"></i> Tam Tarixçə
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($history)): ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-info-circle"></i> Bu məhsul üçün hələ hərəkət qeydə alınmayıb.
                                </div>
                            <?php else: ?>
                                <?php foreach ($history as $entry): ?>
                                    <div class="history-item">
                                        <?php
                                        $historyType = 'adjust';
                                        $historyIcon = 'fa-edit';
                                        $historyClass = 'icon-adjust';
                                        $badgeClass = 'badge-warning';
                                        $actionText = 'Düzəliş edildi';
                                        
                                        if ($entry['action_type'] === 'add') {
                                            $historyType = 'add';
                                            $historyIcon = 'fa-plus';
                                            $historyClass = 'icon-add';
                                            $badgeClass = 'badge-success';
                                            $actionText = 'Əlavə edildi';
                                        } elseif ($entry['action_type'] === 'remove') {
                                            $historyType = 'remove';
                                            $historyIcon = 'fa-minus';
                                            $historyClass = 'icon-remove';
                                            $badgeClass = 'badge-danger';
                                            $actionText = 'Çıxarıldı';
                                        }
                                        ?>
                                        <div class="history-icon <?= $historyClass ?>">
                                            <i class="fas <?= $historyIcon ?>"></i>
                                        </div>
                                        
                                        <div class="history-content">
                                            <div class="history-title">
                                                <span class="badge <?= $badgeClass ?>"><?= $actionText ?></span>
                                                <?= $entry['quantity_change'] > 0 ? '+' : '' ?><?= $entry['quantity_change'] ?> <?= htmlspecialchars($item['unit']) ?>
                                            </div>
                                            <div class="history-details">
                                                <div><?= formatDate($entry['created_at']) ?></div>
                                                <div>İstifadəçi: <?= htmlspecialchars($entry['user_name']) ?></div>
                                            </div>
                                            <?php if (!empty($entry['notes'])): ?>
                                                <div class="history-note"><?= htmlspecialchars($entry['notes']) ?></div>
                                            <?php endif; ?>
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
        });
    </script>
</body>
</html>