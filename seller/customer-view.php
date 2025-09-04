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
$sql = "SELECT c.*, u.email, u.status AS account_status, u.last_login
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

// Get customer statistics
$sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_spent,
            SUM(remaining_amount) as outstanding_balance,
            MAX(order_date) as last_order_date
        FROM orders
        WHERE customer_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customerStats = $stmt->get_result()->fetch_assoc();

// Get recent orders
$sql = "SELECT o.*, b.name as branch_name
        FROM orders o
        LEFT JOIN branches b ON o.branch_id = b.id
        WHERE o.customer_id = ?
        ORDER BY o.order_date DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get customer notes
$sql = "SELECT n.*, u.fullname as created_by_name
        FROM customer_notes n
        LEFT JOIN users u ON n.created_by = u.id
        WHERE n.customer_id = ?
        ORDER BY n.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customerNotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Process note form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $noteContent = trim($_POST['note'] ?? '');
    
    if (!empty($noteContent)) {
        $sql = "INSERT INTO customer_notes (customer_id, note, created_by, created_at) 
                VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $customerId, $noteContent, $sellerId);
        
        if ($stmt->execute()) {
            // Redirect to avoid form resubmission
            header("Location: customer-view.php?id={$customerId}&note_added=1");
            exit;
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

// Order status configurations
$statusConfig = [
    'new' => ['text' => 'Yeni', 'color' => 'info'],
    'processing' => ['text' => 'Hazırlanır', 'color' => 'warning'],
    'completed' => ['text' => 'Hazır', 'color' => 'success'],
    'delivered' => ['text' => 'Təhvil verilib', 'color' => 'success'],
    'cancelled' => ['text' => 'Ləğv edilib', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müştəri Məlumatları | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .customer-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .customer-avatar {
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
        
        .customer-info {
            flex: 1;
        }
        
        .customer-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .customer-meta {
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
        
        .account-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }
        .status-suspended { background: #fee2e2; color: #b91c1c; }
        
        .customer-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
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
        
        .recent-order {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        
        .recent-order:hover {
            transform: translateY(-2px);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .order-number {
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .order-date {
            color: #6b7280;
            font-size: 14px;
        }
        
        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f3f4f6;
        }
        
        .order-amount {
            font-weight: 700;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-info { background-color: #e0f2fe; color: #0369a1; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        
        .note-item {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .note-header {
            display: flex;
            justify-content: space-between;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .note-content {
            margin-bottom: 10px;
            white-space: pre-wrap;
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
        
        .contact-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        
        .contact-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-btn.whatsapp {
            background: #25d366;
            color: white;
        }
        
        .contact-btn.call {
            background: #3b82f6;
            color: white;
        }
        
        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .customer-meta {
                justify-content: center;
            }
            
            .customer-actions {
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
                <h1><i class="fas fa-user"></i> Müştəri Məlumatları</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <span>Müştəri Məlumatları</span>
                </div>
            </div>

            <?php if (isset($_GET['note_added']) && $_GET['note_added'] == 1): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Qeyd uğurla əlavə edildi.
                </div>
            <?php endif; ?>

            <!-- Customer Header -->
            <div class="customer-header">
                <div class="customer-avatar">
                    <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
                </div>
                <div class="customer-info">
                    <div class="customer-name"><?= htmlspecialchars($customer['fullname']) ?></div>
                    <div class="customer-meta">
                        <div class="meta-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($customer['phone']) ?></span>
                        </div>
                        <?php if (!empty($customer['email'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-envelope"></i>
                                <span><?= htmlspecialchars($customer['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['company'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-building"></i>
                                <span><?= htmlspecialchars($customer['company']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span>Qeydiyyat: <?= formatDate($customer['created_at']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($customer['account_status'])): ?>
                        <div>
                            <span class="account-status status-<?= $customer['account_status'] ?>">
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
                                            echo ucfirst($customer['account_status']);
                                    }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="customer-actions">
                        <a href="customer-edit.php?id=<?= $customer['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Düzəliş et
                        </a>
                        <a href="customer-orders.php?id=<?= $customer['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-clipboard-list"></i> Bütün Sifarişlər
                        </a>
                        <a href="hesabla.php?customer_id=<?= $customer['id'] ?>" class="btn btn-success">
                            <i class="fas fa-plus"></i> Yeni Sifariş
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Customer Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $customerStats['total_orders'] ?? 0 ?></div>
                    <div class="stat-label">Ümumi Sifariş</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= formatMoney($customerStats['total_spent'] ?? 0, '') ?></div>
                    <div class="stat-label">Ümumi Alış</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= formatMoney($customerStats['outstanding_balance'] ?? 0, '') ?></div>
                    <div class="stat-label">Qalıq Borc</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= !empty($customerStats['last_order_date']) ? formatDate($customerStats['last_order_date'], 'd.m.Y') : '-' ?></div>
                    <div class="stat-label">Son Sifariş</div>
                </div>
            </div>
            
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Recent Orders -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-clipboard-list"></i> Son Sifarişlər</h2>
                            <div class="card-actions">
                                <a href="customer-orders.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline">
                                    <i class="fas fa-list"></i> Hamısını göstər
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentOrders)): ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-info-circle"></i> Bu müştərinin hələ sifarişi yoxdur.
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $order): ?>
                                    <?php 
                                        $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                                    ?>
                                    <a href="order-details.php?id=<?= $order['id'] ?>" class="recent-order">
                                        <div class="order-header">
                                            <div class="order-number">#<?= htmlspecialchars($order['order_number']) ?></div>
                                            <div class="order-date"><?= formatDate($order['order_date']) ?></div>
                                        </div>
                                        <div>
                                            <div><strong>Filial:</strong> <?= htmlspecialchars($order['branch_name']) ?></div>
                                            <?php if (!empty($order['initial_note'])): ?>
                                                <div class="mt-2">
                                                    <strong>Qeyd:</strong> <?= mb_substr(htmlspecialchars($order['initial_note']), 0, 100) ?><?= mb_strlen($order['initial_note']) > 100 ? '...' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="order-footer">
                                            <div class="order-amount"><?= formatMoney($order['total_amount']) ?></div>
                                            <div class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Customer Notes -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-sticky-note"></i> Müştəri Qeydləri</h2>
                        </div>
                        <div class="card-body">
                            <form method="post" action="" class="mb-4">
                                <input type="hidden" name="action" value="add_note">
                                <div class="form-group">
                                    <label for="note" class="form-label">Yeni Qeyd</label>
                                    <textarea id="note" name="note" class="form-control" rows="3" placeholder="Müştəri haqqında qeydlərinizi yazın..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Qeyd Əlavə Et
                                </button>
                            </form>
                            
                            <div class="mt-4">
                                <?php if (empty($customerNotes)): ?>
                                    <div class="text-center p-4">
                                        <i class="fas fa-info-circle"></i> Bu müştəri üçün hələ qeyd yoxdur.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($customerNotes as $note): ?>
                                        <div class="note-item">
                                            <div class="note-header">
                                                <div><strong><?= htmlspecialchars($note['created_by_name']) ?></strong></div>
                                                <div><?= formatDate($note['created_at']) ?></div>
                                            </div>
                                            <div class="note-content"><?= nl2br(htmlspecialchars($note['note'])) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Customer Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-info-circle"></i> Ətraflı Məlumat</h2>
                        </div>
                        <div class="card-body">
                            <div class="info-list">
                                <div class="info-label">Müştəri ID:</div>
                                <div class="info-value"><?= $customer['id'] ?></div>
                                
                                <div class="info-label">Ad Soyad:</div>
                                <div class="info-value"><?= htmlspecialchars($customer['fullname']) ?></div>
                                
                                <div class="info-label">Telefon:</div>
                                <div class="info-value"><?= htmlspecialchars($customer['phone']) ?></div>
                                
                                <?php if (!empty($customer['email'])): ?>
                                    <div class="info-label">E-poçt:</div>
                                    <div class="info-value"><?= htmlspecialchars($customer['email']) ?></div>
                                <?php endif; ?>
                                
                                <?php if (!empty($customer['company'])): ?>
                                    <div class="info-label">Şirkət:</div>
                                    <div class="info-value"><?= htmlspecialchars($customer['company']) ?></div>
                                <?php endif; ?>
                                
                                <?php if (!empty($customer['address'])): ?>
                                    <div class="info-label">Ünvan:</div>
                                    <div class="info-value"><?= htmlspecialchars($customer['address']) ?></div>
                                <?php endif; ?>
                                
                                <div class="info-label">Qeydiyyat tarixi:</div>
                                <div class="info-value"><?= formatDate($customer['created_at']) ?></div>
                                
                                <?php if (!empty($customer['last_login'])): ?>
                                    <div class="info-label">Son giriş:</div>
                                    <div class="info-value"><?= formatDate($customer['last_login']) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="contact-buttons">
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $customer['phone']) ?>" class="contact-btn whatsapp" target="_blank">
                                    <i class="fab fa-whatsapp"></i> WhatsApp ilə yazın
                                </a>
                                <a href="tel:<?= preg_replace('/[^0-9]/', '', $customer['phone']) ?>" class="contact-btn call">
                                    <i class="fas fa-phone"></i> Zəng edin
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($customerStats['outstanding_balance'] > 0): ?>
                        <!-- Payment Information -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Ödəniş Məlumatları</h2>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Bu müştərinin <strong><?= formatMoney($customerStats['outstanding_balance']) ?></strong> məbləğində qalıq borcu var.
                                </div>
                                
                                <?php
                                // Get orders with outstanding balance
                                $sql = "SELECT id, order_number, order_date, total_amount, advance_payment, remaining_amount 
                                        FROM orders 
                                        WHERE customer_id = ? AND remaining_amount > 0
                                        ORDER BY order_date DESC";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param("i", $customerId);
                                $stmt->execute();
                                $outstandingOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                ?>
                                
                                <?php if (!empty($outstandingOrders)): ?>
                                    <div class="mt-3">
                                        <h3 class="card-subtitle mb-3">Ödənilməmiş Sifarişlər</h3>
                                        
                                        <?php foreach ($outstandingOrders as $order): ?>
                                            <div class="recent-order">
                                                <div class="order-header">
                                                    <div class="order-number">#<?= htmlspecialchars($order['order_number']) ?></div>
                                                    <div class="order-date"><?= formatDate($order['order_date']) ?></div>
                                                </div>
                                                <div class="mt-2">
                                                    <div><strong>Ümumi məbləğ:</strong> <?= formatMoney($order['total_amount']) ?></div>
                                                    <div><strong>Avans ödəniş:</strong> <?= formatMoney($order['advance_payment']) ?></div>
                                                    <div class="text-danger"><strong>Qalıq borc:</strong> <?= formatMoney($order['remaining_amount']) ?></div>
                                                </div>
                                                <div class="order-footer">
                                                    <a href="order-details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                                                        <i class="fas fa-eye"></i> Ətraflı bax
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
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