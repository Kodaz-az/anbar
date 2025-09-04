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

// Process user actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($userId <= 0) {
            $error = 'Yanlış istifadəçi ID';
        } elseif (!in_array($status, ['active', 'inactive', 'suspended'])) {
            $error = 'Yanlış status';
        } elseif ($userId === $adminId) {
            $error = 'Özünüzün statusunu dəyişə bilməzsiniz';
        } else {
            $conn = getDBConnection();
            $stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $status, $userId);
            
            if ($stmt->execute()) {
                logActivity($adminId, 'update_user_status', "Updated user ID: $userId status to: $status");
                $success = 'İstifadəçi statusu uğurla yeniləndi';
            } else {
                $error = 'İstifadəçi statusu yeniləmə zamanı xəta baş verdi';
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if ($userId <= 0) {
            $error = 'Yanlış istifadəçi ID';
        } elseif ($userId === $adminId) {
            $error = 'Özünüzün şifrəsini bu şəkildə sıfırlaya bilməzsiniz';
        } else {
            $conn = getDBConnection();
            
            // Generate a new random password
            $newPassword = generateRandomPassword();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                // Get user's email
                $stmt = $conn->prepare("SELECT email, fullname, phone FROM users u LEFT JOIN customers c ON u.id = c.user_id WHERE u.id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                
                if ($user) {
                    // Send password reset notification
                    logActivity($adminId, 'reset_user_password', "Reset password for user ID: $userId");
                    
                    // Send password via email
                    // sendPasswordResetEmail($user['email'], $user['fullname'], $newPassword);
                    
                    // If WhatsApp integration is enabled and the user has a phone number
                    if (WHATSAPP_ENABLED && !empty($user['phone'])) {
                        require_once '../includes/whatsapp.php';
                        
                        $variables = [
                            'customer_name' => $user['fullname'],
                            'email' => $user['email'],
                            'password' => $newPassword,
                            'company_phone' => COMPANY_PHONE
                        ];
                        
                        sendWhatsAppTemplate($user['phone'], 'password_reset', $variables);
                    }
                    
                    $success = 'İstifadəçi şifrəsi uğurla sıfırlandı: ' . $newPassword;
                } else {
                    $success = 'İstifadəçi şifrəsi uğurla sıfırlandı, lakin email məlumatı tapılmadı';
                }
            } else {
                $error = 'Şifrə sıfırlama zamanı xəta baş verdi';
            }
        }
    }
}

// Get filter parameters
$role = $_GET['role'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_desc';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$whereClause = [];
$params = [];
$types = "";

if ($role !== 'all') {
    $whereClause[] = "u.role = ?";
    $params[] = $role;
    $types .= "s";
}

if ($status !== 'all') {
    $whereClause[] = "u.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($search)) {
    $searchTerm = "%{$search}%";
    $whereClause[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

$whereClauseStr = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";

// Set order by clause based on sort parameter
$orderByClause = "ORDER BY u.created_at DESC"; // Default
switch ($sort) {
    case 'created_asc':
        $orderByClause = "ORDER BY u.created_at ASC";
        break;
    case 'name_asc':
        $orderByClause = "ORDER BY u.fullname ASC";
        break;
    case 'name_desc':
        $orderByClause = "ORDER BY u.fullname DESC";
        break;
    case 'role':
        $orderByClause = "ORDER BY u.role ASC, u.fullname ASC";
        break;
    case 'status':
        $orderByClause = "ORDER BY u.status ASC, u.fullname ASC";
        break;
}

// Get total count
$conn = getDBConnection();
$sqlCount = "SELECT COUNT(*) as total FROM users u $whereClauseStr";
$stmtCount = $conn->prepare($sqlCount);

if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();
$totalUsers = $stmtCount->get_result()->fetch_assoc()['total'];

// Calculate total pages
$totalPages = ceil($totalUsers / $perPage);

// Get users with branch information
$sql = "SELECT u.*, b.name as branch_name 
        FROM users u 
        LEFT JOIN branches b ON u.branch_id = b.id 
        $whereClauseStr 
        $orderByClause 
        LIMIT ?, ?";

$params[] = $offset;
$params[] = $perPage;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get branches for filter and user creation
$sql = "SELECT * FROM branches WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);
$branches = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
}

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
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İstifadəçilər | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .filter-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-label {
            font-weight: 500;
            color: #6b7280;
            white-space: nowrap;
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: var(--border-radius);
            background-color: white;
        }
        
        .filter-select {
            min-width: 150px;
        }
        
        .filter-input {
            min-width: 200px;
        }
        
        .user-role {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .role-admin { background: #fee2e2; color: #b91c1c; }
        .role-seller { background: #e0f2fe; color: #0369a1; }
        .role-customer { background: #d1fae5; color: #065f46; }
        .role-production { background: #fef3c7; color: #92400e; }
        
        .user-status {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }
        .status-suspended { background: #fee2e2; color: #b91c1c; }
        
        .action-btn {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .user-table td {
            vertical-align: middle;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            background: white;
            color: #374151;
            text-decoration: none;
            font-weight: 500;
            box-shadow: var(--card-shadow);
        }
        
        .page-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .modal-body .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 0;
        }
        
        .modal-body .form-col {
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-select, .filter-input {
                width: 100%;
            }
            
            .modal-body .form-row {
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
                <h1><i class="fas fa-users"></i> İstifadəçilər</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <span>İstifadəçilər</span>
                </div>
            </div>

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
            
            <!-- Page Actions -->
            <div class="page-actions">
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addUserModal">
                    <i class="fas fa-user-plus"></i> Yeni İstifadəçi
                </button>
            </div>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="" method="get" id="filterForm">
                        <div class="filter-container">
                            <div class="filter-item">
                                <label class="filter-label">Rol:</label>
                                <select name="role" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>Bütün Rollar</option>
                                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administratorlar</option>
                                    <option value="seller" <?= $role === 'seller' ? 'selected' : '' ?>>Satıcılar</option>
                                    <option value="production" <?= $role === 'production' ? 'selected' : '' ?>>İstehsalat</option>
                                    <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>Müştərilər</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Status:</label>
                                <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Bütün Statuslar</option>
                                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktiv</option>
                                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Deaktiv</option>
                                    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Dayandırılmış</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Sıralama:</label>
                                <select name="sort" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>Yeni qeydiyyat</option>
                                    <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>Köhnə qeydiyyat</option>
                                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Ad (A-Z)</option>
                                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Ad (Z-A)</option>
                                    <option value="role" <?= $sort === 'role' ? 'selected' : '' ?>>Rol</option>
                                    <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                                </select>
                            </div>
                            
                            <div class="filter-item flex-grow-1">
                                <label class="filter-label">Axtar:</label>
                                <div class="d-flex w-100">
                                    <input type="text" name="search" class="filter-input flex-grow-1" value="<?= htmlspecialchars($search) ?>" placeholder="Ad, email və ya istifadəçi adı">
                                    <button type="submit" class="btn btn-primary ml-2">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- User List -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">İstifadəçilər (<?= $totalUsers ?>)</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Ad Soyad</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Filial</th>
                                    <th>Status</th>
                                    <th>Qeydiyyat tarixi</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">İstifadəçi tapılmadı</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= $user['id'] ?></td>
                                            <td><?= htmlspecialchars($user['fullname']) ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td>
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
                                            </td>
                                            <td><?= htmlspecialchars($user['branch_name'] ?? '-') ?></td>
                                            <td>
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
                                            </td>
                                            <td><?= formatDate($user['created_at']) ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="user-details.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline action-btn" title="Ətraflı">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <button type="button" class="btn btn-sm btn-outline action-btn edit-user-btn" 
                                                            data-id="<?= $user['id'] ?>"
                                                            data-name="<?= htmlspecialchars($user['fullname']) ?>"
                                                            data-email="<?= htmlspecialchars($user['email']) ?>"
                                                            data-role="<?= $user['role'] ?>"
                                                            data-branch="<?= $user['branch_id'] ?? '' ?>"
                                                            data-status="<?= $user['status'] ?>"
                                                            title="Düzəliş et">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($user['id'] !== $adminId): ?>
                                                        <button type="button" class="btn btn-sm btn-outline action-btn status-change-btn" 
                                                                data-id="<?= $user['id'] ?>"
                                                                data-name="<?= htmlspecialchars($user['fullname']) ?>"
                                                                data-status="<?= $user['status'] ?>"
                                                                title="Status dəyiş">
                                                            <i class="fas fa-exchange-alt"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-sm btn-outline action-btn reset-password-btn" 
                                                                data-id="<?= $user['id'] ?>"
                                                                data-name="<?= htmlspecialchars($user['fullname']) ?>"
                                                                title="Şifrə sıfırla">
                                                            <i class="fas fa-key"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1&role=<?= $role ?>&status=<?= $status ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="page-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?= $page - 1 ?>&role=<?= $role ?>&status=<?= $status ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="page-link">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                            <?php endif; ?>
                            
                            <?php
                            // Show limited page numbers with current page in the middle
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            // Always show at least 5 pages if available
                            if ($endPage - $startPage + 1 < 5) {
                                if ($startPage === 1) {
                                    $endPage = min($totalPages, 5);
                                } elseif ($endPage === $totalPages) {
                                    $startPage = max(1, $totalPages - 4);
                                }
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a href="?page=<?= $i ?>&role=<?= $role ?>&status=<?= $status ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&role=<?= $role ?>&status=<?= $status ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="page-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?= $totalPages ?>&role=<?= $role ?>&status=<?= $status ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="page-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Yeni İstifadəçi Əlavə Et</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form action="user-add.php" method="post">
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="fullname" class="form-label">Ad Soyad</label>
                                <input type="text" id="fullname" name="fullname" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="email" class="form-label">E-poçt</label>
                                <input type="email" id="email" name="email" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="role" class="form-label">Rol</label>
                                <select id="role" name="role" class="form-control" required>
                                    <option value="admin">Administrator</option>
                                    <option value="seller">Satıcı</option>
                                    <option value="production">İstehsalat</option>
                                    <option value="customer">Müştəri</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="branch_id" class="form-label">Filial</label>
                                <select id="branch_id" name="branch_id" class="form-control">
                                    <option value="">Seçin...</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>"><?= htmlspecialchars($branch['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Satıcı və istehsalat işçiləri üçün tələb olunur</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="password" class="form-label">Şifrə</label>
                                <input type="password" id="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="confirm_password" class="form-label">Şifrə təsdiqi</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone" class="form-label">Telefon</label>
                        <input type="tel" id="phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX">
                        <div class="form-text">Müştərilər üçün tələb olunur</div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                        <button type="submit" class="btn btn-primary">Əlavə et</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
                    <input type="hidden" id="edit_user_id" name="user_id">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_fullname" class="form-label">Ad Soyad</label>
                                <input type="text" id="edit_fullname" name="fullname" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_email" class="form-label">E-poçt</label>
                                <input type="email" id="edit_email" name="email" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_role" class="form-label">Rol</label>
                                <select id="edit_role" name="role" class="form-control" required>
                                    <option value="admin">Administrator</option>
                                    <option value="seller">Satıcı</option>
                                    <option value="production">İstehsalat</option>
                                    <option value="customer">Müştəri</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="edit_branch_id" class="form-label">Filial</label>
                                <select id="edit_branch_id" name="branch_id" class="form-control">
                                    <option value="">Seçin...</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>"><?= htmlspecialchars($branch['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status" class="form-label">Status</label>
                        <select id="edit_status" name="status" class="form-control" required>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Deaktiv</option>
                            <option value="suspended">Dayandırılmış</option>
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
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" id="status_user_id" name="user_id">
                    
                    <p id="status_user_name"></p>
                    
                    <div class="form-group">
                        <label for="new_status" class="form-label">Yeni Status</label>
                        <select id="new_status" name="status" class="form-control" required>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Deaktiv</option>
                            <option value="suspended">Dayandırılmış</option>
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
                <form method="post" action="">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" id="reset_user_id" name="user_id">
                    
                    <p>Bu istifadəçinin şifrəsi sıfırlanacaq və yeni şifrə yaradılacaq.</p>
                    <p id="reset_user_name" class="font-weight-bold"></p>
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
            const userInfo = document.querySelector('.user-info');
            userInfo.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const modalBackdrops = document.querySelectorAll('.modal-backdrop');
            const modalCloseButtons = document.querySelectorAll('.modal-close');
            const modalTriggers = document.querySelectorAll('[data-toggle="modal"]');
            
            // Open modal
            modalTriggers.forEach(trigger => {
                trigger.addEventListener('click', function() {
                    const targetModalId = this.getAttribute('data-target');
                    document.querySelector(targetModalId).classList.add('show');
                });
            });
            
            // Close modal with backdrop or close button
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
            
            // Edit user button
            const editUserButtons = document.querySelectorAll('.edit-user-btn');
            editUserButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const email = this.getAttribute('data-email');
                    const role = this.getAttribute('data-role');
                    const branch = this.getAttribute('data-branch');
                    const status = this.getAttribute('data-status');
                    
                    document.getElementById('edit_user_id').value = userId;
                    document.getElementById('edit_fullname').value = name;
                    document.getElementById('edit_email').value = email;
                    document.getElementById('edit_role').value = role;
                    document.getElementById('edit_branch_id').value = branch;
                    document.getElementById('edit_status').value = status;
                    
                    document.getElementById('editUserModal').classList.add('show');
                });
            });
            
            // Status change button
            const statusChangeButtons = document.querySelectorAll('.status-change-btn');
            statusChangeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const status = this.getAttribute('data-status');
                    
                    document.getElementById('status_user_id').value = userId;
                    document.getElementById('status_user_name').textContent = `İstifadəçi: ${name} (Cari status: ${statusText(status)})`;
                    document.getElementById('new_status').value = status;
                    
                    document.getElementById('statusChangeModal').classList.add('show');
                });
            });
            
            // Reset password button
            const resetPasswordButtons = document.querySelectorAll('.reset-password-btn');
            resetPasswordButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    
                    document.getElementById('reset_user_id').value = userId;
                    document.getElementById('reset_user_name').textContent = `İstifadəçi: ${name}`;
                    
                    document.getElementById('resetPasswordModal').classList.add('show');
                });
            });
            
            // Helper function to convert status code to human-readable text
            function statusText(status) {
                switch (status) {
                    case 'active': return 'Aktiv';
                    case 'inactive': return 'Deaktiv';
                    case 'suspended': return 'Dayandırılmış';
                    default: return status;
                }
            }
            
            // Role-based branch visibility
            const roleSelects = document.querySelectorAll('#role, #edit_role');
            roleSelects.forEach(select => {
                select.addEventListener('change', function() {
                    const branchField = this.id === 'role' ? 
                        document.getElementById('branch_id').parentElement.parentElement : 
                        document.getElementById('edit_branch_id').parentElement.parentElement;
                    
                    if (this.value === 'seller' || this.value === 'production') {
                        branchField.style.display = 'block';
                        const branchSelect = this.id === 'role' ? 
                            document.getElementById('branch_id') : 
                            document.getElementById('edit_branch_id');
                        branchSelect.setAttribute('required', 'required');
                    } else {
                        branchField.style.display = 'none';
                        const branchSelect = this.id === 'role' ? 
                            document.getElementById('branch_id') : 
                            document.getElementById('edit_branch_id');
                        branchSelect.removeAttribute('required');
                    }
                });
                
                // Trigger change event to set initial state
                select.dispatchEvent(new Event('change'));
            });
        });
    </script>
</body>
</html>