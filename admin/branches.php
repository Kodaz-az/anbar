<?php
// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug output - check what's in the session
error_log("SESSION DATA: " . print_r($_SESSION, true));

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Basic authentication checks with detailed logging
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    error_log("branches.php access denied: No user_id in session");
    header('Location: ../auth/login.php');
    exit;
}

// Role checking - allow both admin and superadmin roles
$allowedRoles = ['admin', 'superadmin'];
$userRole = $_SESSION['role'] ?? 'none';

// Log the current role for debugging
error_log("User attempting to access branches.php with role: $userRole");

if (!in_array($userRole, $allowedRoles)) {
    error_log("branches.php access denied: Invalid role ($userRole)");
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get admin info
$userId = $_SESSION['user_id'];
$adminName = $_SESSION['fullname'] ?? 'Admin User';

// Initialize variables
$error = '';
$success = '';
$branches = [];

// Database connection
try {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("DB Connection Error in branches.php: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new branch
        if ($_POST['action'] === 'add_branch') {
            $name = sanitizeInput($_POST['name'] ?? '');
            $address = sanitizeInput($_POST['address'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $manager = sanitizeInput($_POST['manager'] ?? '');
            $status = sanitizeInput($_POST['status'] ?? 'active');
            
            if (empty($name)) {
                $error = 'Filial adı boş ola bilməz!';
            } else {
                try {
                    // Check if branch with same name exists
                    $stmt = $conn->prepare("SELECT id FROM branches WHERE name = ?");
                    $stmt->bind_param("s", $name);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error = 'Bu adda filial artıq mövcuddur!';
                    } else {
                        // Insert new branch
                        $stmt = $conn->prepare("INSERT INTO branches (name, address, phone, manager, status, created_at, created_by_id, updated_at, updated_by_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW(), ?)");
                        $stmt->bind_param("ssssiii", $name, $address, $phone, $manager, $status, $userId, $userId);
                        
                        if ($stmt->execute()) {
                            $success = 'Filial uğurla əlavə edildi!';
                        } else {
                            $error = 'Filial əlavə edilərkən xəta baş verdi: ' . $conn->error;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error adding branch: " . $e->getMessage());
                    $error = 'Sistem xətası baş verdi. Xahiş edirik daha sonra yenidən cəhd edin.';
                }
            }
        }
        
        // Update branch
        if ($_POST['action'] === 'edit_branch') {
            $id = (int)($_POST['branch_id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            $address = sanitizeInput($_POST['address'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $manager = sanitizeInput($_POST['manager'] ?? '');
            $status = sanitizeInput($_POST['status'] ?? 'active');
            
            if (empty($name)) {
                $error = 'Filial adı boş ola bilməz!';
            } else {
                try {
                    // Check if branch with same name exists but different ID
                    $stmt = $conn->prepare("SELECT id FROM branches WHERE name = ? AND id != ?");
                    $stmt->bind_param("si", $name, $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error = 'Bu adda filial artıq mövcuddur!';
                    } else {
                        // Update branch
                        $stmt = $conn->prepare("UPDATE branches SET name = ?, address = ?, phone = ?, manager = ?, status = ?, updated_at = NOW(), updated_by_id = ? WHERE id = ?");
                        $stmt->bind_param("sssssii", $name, $address, $phone, $manager, $status, $userId, $id);
                        
                        if ($stmt->execute()) {
                            $success = 'Filial uğurla yeniləndi!';
                        } else {
                            $error = 'Filial yenilənərkən xəta baş verdi: ' . $conn->error;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error updating branch: " . $e->getMessage());
                    $error = 'Sistem xətası baş verdi. Xahiş edirik daha sonra yenidən cəhd edin.';
                }
            }
        }
        
        // Delete branch
        if ($_POST['action'] === 'delete_branch') {
            $id = (int)($_POST['branch_id'] ?? 0);
            
            try {
                // Check if branch has users
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE branch_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                
                if ($result['count'] > 0) {
                    $error = 'Bu filiala təyin edilmiş işçilər var. Silməzdən əvvəl işçiləri başqa filiala köçürün.';
                } else {
                    // Check if branch has orders
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE branch_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result()->fetch_assoc();
                    
                    if ($result['count'] > 0) {
                        $error = 'Bu filialda sifarişlər var. Silməzdən əvvəl sifarişləri başqa filiala köçürün.';
                    } else {
                        // Delete branch
                        $stmt = $conn->prepare("DELETE FROM branches WHERE id = ?");
                        $stmt->bind_param("i", $id);
                        
                        if ($stmt->execute()) {
                            $success = 'Filial uğurla silindi!';
                        } else {
                            $error = 'Filial silinərkən xəta baş verdi: ' . $conn->error;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error deleting branch: " . $e->getMessage());
                $error = 'Sistem xətası baş verdi. Xahiş edirik daha sonra yenidən cəhd edin.';
            }
        }
    }
}

// Get all branches with additional information
try {
    // Fix: Use created_by_id and updated_by_id to join with users table
    $sql = "SELECT b.*, 
            creator.fullname as created_by_name,
            updater.fullname as updated_by_name,
            (SELECT COUNT(*) FROM users WHERE users.branch_id = b.id) as user_count,
            (SELECT COUNT(*) FROM orders WHERE orders.branch_id = b.id) as order_count
            FROM branches b
            LEFT JOIN users creator ON b.created_by_id = creator.id
            LEFT JOIN users updater ON b.updated_by_id = updater.id
            ORDER BY b.name ASC";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    } else {
        error_log("Error fetching branches: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Exception in branches query: " . $e->getMessage());
    $error = 'Filial məlumatları əldə edilərkən xəta baş verdi.';
}

// Get unread message count
$unreadMessages = 0;
try {
    if (function_exists('tableExists') && tableExists($conn, 'messages')) {
        $sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $unreadMessages = $result['unread_count'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error getting unread messages: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filiallar | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <!-- Debugging info - Remove in production -->
    <!--
    Role: <?= htmlspecialchars($userRole) ?>
    User ID: <?= htmlspecialchars($userId) ?>
    -->

    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="branches.php" class="active"><i class="fas fa-building"></i> Filiallar</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="settings.php"><i class="fas fa-cogs"></i> Tənzimləmələr</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="messages.php"><i class="fas fa-envelope"></i> 
                    Mesajlar
                    <?php if($unreadMessages > 0): ?>
                        <span class="notification-badge"><?= $unreadMessages ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($adminName) ?></span>
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
                <h1><i class="fas fa-building"></i> Filiallar</h1>
                <div class="breadcrumb">
                    <a href="index.php">Admin Panel</a> / <span>Filiallar</span>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <div class="content-card">
                <div class="card-header">
                    <h2>Filial Siyahısı</h2>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#addBranchModal">
                        <i class="fas fa-plus"></i> Yeni Filial
                    </button>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Ad</th>
                                <th>Ünvan</th>
                                <th>Telefon</th>
                                <th>Menecer</th>
                                <th>Status</th>
                                <th>İşçi sayı</th>
                                <th>Sifariş sayı</th>
                                <th>Əməliyyatlar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($branches as $branch): ?>
                                <tr>
                                    <td><?= $branch['id'] ?></td>
                                    <td><?= htmlspecialchars($branch['name']) ?></td>
                                    <td><?= htmlspecialchars($branch['address']) ?></td>
                                    <td><?= htmlspecialchars($branch['phone']) ?></td>
                                    <td><?= htmlspecialchars($branch['manager']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $branch['status'] === 'active' ? 'active' : 'inactive' ?>">
                                            <?= $branch['status'] === 'active' ? 'Aktiv' : 'Deaktiv' ?>
                                        </span>
                                    </td>
                                    <td><?= $branch['user_count'] ?></td>
                                    <td><?= $branch['order_count'] ?></td>
                                    <td>
                                        <button class="btn-action view-branch-btn" title="Ətraflı bax"
                                            data-id="<?= $branch['id'] ?>"
                                            data-name="<?= htmlspecialchars($branch['name']) ?>"
                                            data-address="<?= htmlspecialchars($branch['address']) ?>"
                                            data-phone="<?= htmlspecialchars($branch['phone']) ?>"
                                            data-manager="<?= htmlspecialchars($branch['manager']) ?>"
                                            data-status="<?= $branch['status'] ?>"
                                            data-created="<?= formatDate($branch['created_at']) ?>"
                                            data-created-by="<?= htmlspecialchars($branch['created_by_name']) ?>"
                                            data-updated="<?= formatDate($branch['updated_at']) ?>"
                                            data-updated-by="<?= htmlspecialchars($branch['updated_by_name']) ?>"
                                            data-user-count="<?= $branch['user_count'] ?>"
                                            data-order-count="<?= $branch['order_count'] ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <button class="btn-action edit-branch-btn" title="Düzəliş et"
                                            data-id="<?= $branch['id'] ?>"
                                            data-name="<?= htmlspecialchars($branch['name']) ?>"
                                            data-address="<?= htmlspecialchars($branch['address']) ?>"
                                            data-phone="<?= htmlspecialchars($branch['phone']) ?>"
                                            data-manager="<?= htmlspecialchars($branch['manager']) ?>"
                                            data-status="<?= $branch['status'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if ($branch['user_count'] == 0 && $branch['order_count'] == 0): ?>
                                            <button class="btn-action delete-branch-btn" title="Sil"
                                                data-id="<?= $branch['id'] ?>"
                                                data-name="<?= htmlspecialchars($branch['name']) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($branches)): ?>
                                <tr>
                                    <td colspan="9" class="text-center">Heç bir filial tapılmadı.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Branch Modal -->
    <div class="modal" id="addBranchModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Filial</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form method="post" action="branches.php">
                    <input type="hidden" name="action" value="add_branch">
                    
                    <div class="form-group">
                        <label for="name">Filial adı *</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Ünvan</label>
                        <input type="text" id="address" name="address" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Telefon</label>
                        <input type="text" id="phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="manager">Menecer</label>
                        <input type="text" id="manager" name="manager" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="active">Aktiv</option>
                            <option value="inactive">Deaktiv</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary">Əlavə et</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Branch Modal -->
    <div class="modal" id="editBranchModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Filial Düzəliş</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form method="post" action="branches.php">
                    <input type="hidden" name="action" value="edit_branch">
                    <input type="hidden" id="edit_branch_id" name="branch_id">
                    
                    <div class="form-group">
                        <label for="edit_name">Filial adı *</label>
                        <input type="text" id="edit_name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_address">Ünvan</label>
                        <input type="text" id="edit_address" name="address" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone">Telefon</label>
                        <input type="text" id="edit_phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_manager">Menecer</label>
                        <input type="text" id="edit_manager" name="manager" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="active">Aktiv</option>
                            <option value="inactive">Deaktiv</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary">Yadda saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Branch Modal -->
    <div class="modal" id="viewBranchModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Filial Məlumatları</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <table class="detail-table">
                    <tr>
                        <th>ID:</th>
                        <td id="view_id"></td>
                    </tr>
                    <tr>
                        <th>Ad:</th>
                        <td id="view_name"></td>
                    </tr>
                    <tr>
                        <th>Ünvan:</th>
                        <td id="view_address"></td>
                    </tr>
                    <tr>
                        <th>Telefon:</th>
                        <td id="view_phone"></td>
                    </tr>
                    <tr>
                        <th>Menecer:</th>
                        <td id="view_manager"></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td id="view_status"></td>
                    </tr>
                    <tr>
                        <th>İşçi sayı:</th>
                        <td id="view_user_count"></td>
                    </tr>
                    <tr>
                        <th>Sifariş sayı:</th>
                        <td id="view_order_count"></td>
                    </tr>
                    <tr>
                        <th>Yaradılma tarixi:</th>
                        <td id="view_created"></td>
                    </tr>
                    <tr>
                        <th>Yaradan:</th>
                        <td id="view_created_by"></td>
                    </tr>
                    <tr>
                        <th>Son yenilənmə:</th>
                        <td id="view_updated"></td>
                    </tr>
                    <tr>
                        <th>Yeniləyən:</th>
                        <td id="view_updated_by"></td>
                    </tr>
                </table>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                    <button type="button" class="btn btn-primary" id="view_edit_btn">
                        <i class="fas fa-edit"></i> Düzəliş et
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Branch Modal -->
    <div class="modal" id="deleteBranchModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Filial Silmə</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form method="post" action="branches.php">
                    <input type="hidden" name="action" value="delete_branch">
                    <input type="hidden" id="delete_branch_id" name="branch_id">
                    
                    <p>Aşağıdakı filialı silmək istədiyinizə əminsiniz?</p>
                    <p><strong id="delete_branch_name"></strong></p>
                    <p>Bu əməliyyat geri qaytarıla bilməz!</p>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                        <button type="submit" class="btn btn-danger">Sil</button>
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
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
            
            // Modal functionality
            const modalTriggers = document.querySelectorAll('[data-toggle="modal"]');
            
            // Open modal
            modalTriggers.forEach(trigger => {
                trigger.addEventListener('click', function() {
                    const targetModalId = this.getAttribute('data-target');
                    document.querySelector(targetModalId).classList.add('show');
                });
            });
            
            // Close modal with [data-dismiss="modal"] elements
            document.querySelectorAll('[data-dismiss="modal"]').forEach(element => {
                element.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        modal.classList.remove('show');
                    }
                });
            });
            
            // Edit branch button
            const editBranchButtons = document.querySelectorAll('.edit-branch-btn');
            editBranchButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const address = this.getAttribute('data-address');
                    const phone = this.getAttribute('data-phone');
                    const manager = this.getAttribute('data-manager');
                    const status = this.getAttribute('data-status');
                    
                    document.getElementById('edit_branch_id').value = id;
                    document.getElementById('edit_name').value = name;
                    document.getElementById('edit_address').value = address;
                    document.getElementById('edit_phone').value = phone;
                    document.getElementById('edit_manager').value = manager;
                    document.getElementById('edit_status').value = status;
                    
                    document.getElementById('editBranchModal').classList.add('show');
                });
            });
            
            // View branch button
            const viewBranchButtons = document.querySelectorAll('.view-branch-btn');
            viewBranchButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const address = this.getAttribute('data-address');
                    const phone = this.getAttribute('data-phone');
                    const manager = this.getAttribute('data-manager');
                    const status = this.getAttribute('data-status');
                    const created = this.getAttribute('data-created');
                    const createdBy = this.getAttribute('data-created-by');
                    const updated = this.getAttribute('data-updated');
                    const updatedBy = this.getAttribute('data-updated-by');
                    const userCount = this.getAttribute('data-user-count');
                    const orderCount = this.getAttribute('data-order-count');
                    
                    document.getElementById('view_id').textContent = id;
                    document.getElementById('view_name').textContent = name;
                    document.getElementById('view_address').textContent = address;
                    document.getElementById('view_phone').textContent = phone;
                    document.getElementById('view_manager').textContent = manager;
                    document.getElementById('view_status').textContent = status === 'active' ? 'Aktiv' : 'Deaktiv';
                    document.getElementById('view_user_count').textContent = userCount;
                    document.getElementById('view_order_count').textContent = orderCount;
                    document.getElementById('view_created').textContent = created;
                    document.getElementById('view_created_by').textContent = createdBy;
                    document.getElementById('view_updated').textContent = updated;
                    document.getElementById('view_updated_by').textContent = updatedBy;
                    
                    // Setup edit button inside view modal
                    document.getElementById('view_edit_btn').onclick = function() {
                        document.getElementById('viewBranchModal').classList.remove('show');
                        
                        document.getElementById('edit_branch_id').value = id;
                        document.getElementById('edit_name').value = name;
                        document.getElementById('edit_address').value = address;
                        document.getElementById('edit_phone').value = phone;
                        document.getElementById('edit_manager').value = manager;
                        document.getElementById('edit_status').value = status;
                        
                        document.getElementById('editBranchModal').classList.add('show');
                    };
                    
                    document.getElementById('viewBranchModal').classList.add('show');
                });
            });
            
            // Delete branch button
            const deleteBranchButtons = document.querySelectorAll('.delete-branch-btn');
            deleteBranchButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    
                    document.getElementById('delete_branch_id').value = id;
                    document.getElementById('delete_branch_name').textContent = name;
                    
                    document.getElementById('deleteBranchModal').classList.add('show');
                });
            });
        });
    </script>
</body>
</html>