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
if (!hasRole('admin')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Fetch admin user data
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

// Process profile update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Profile update
    if (isset($_POST['update_profile'])) {
        $fullname = trim($_POST['fullname']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        if (empty($fullname)) {
            $message = 'Ad Soyad boş ola bilməz';
            $messageType = 'error';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Düzgün e-poçt ünvanı daxil edin';
            $messageType = 'error';
        } else {
            // Check if email already exists for another user
            $sql = "SELECT id FROM users WHERE email = ? AND id != ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $email, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = 'Bu e-poçt ünvanı artıq istifadə olunur';
                $messageType = 'error';
            } else {
                // Update profile
                $sql = "UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $fullname, $email, $phone, $userId);
                
                if ($stmt->execute()) {
                    $_SESSION['fullname'] = $fullname;
                    $message = 'Profil məlumatları uğurla yeniləndi';
                    $messageType = 'success';
                    
                    // Log activity
                    logActivity($userId, 'profile_update', 'Profil məlumatları yeniləndi');
                    
                    // Refresh admin data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $admin = $stmt->get_result()->fetch_assoc();
                } else {
                    $message = 'Profil məlumatlarını yeniləyərkən xəta baş verdi';
                    $messageType = 'error';
                }
            }
        }
    }
    
    // Password change
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $message = 'Bütün şifrə sahələri doldurulmalıdır';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Yeni şifrə və təkrar şifrə eyni olmalıdır';
            $messageType = 'error';
        } elseif (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $message = 'Şifrə ən azı ' . PASSWORD_MIN_LENGTH . ' simvol olmalıdır';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $admin['password'])) {
            $message = 'Cari şifrə yanlışdır';
            $messageType = 'error';
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $message = 'Şifrə uğurla dəyişdirildi';
                $messageType = 'success';
                
                // Log activity
                logActivity($userId, 'password_change', 'Şifrə dəyişdirildi');
            } else {
                $message = 'Şifrəni dəyişərkən xəta baş verdi';
                $messageType = 'error';
            }
        }
    }
    
    // Profile image upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $file = $_FILES['profile_image'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            $message = 'Yalnız JPEG, PNG və GIF şəkil formatları qəbul edilir';
            $messageType = 'error';
        } elseif ($file['size'] > $maxSize) {
            $message = 'Şəkil ölçüsü 5MB-dan çox ola bilməz';
            $messageType = 'error';
        } else {
            $uploadDir = '../uploads/profiles/';
            
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = $userId . '_' . time() . '_' . basename($file['name']);
            $targetFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                // Update profile image path in database
                $imagePath = 'uploads/profiles/' . $fileName;
                $sql = "UPDATE users SET profile_image = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $imagePath, $userId);
                
                if ($stmt->execute()) {
                    $message = 'Profil şəkli uğurla yeniləndi';
                    $messageType = 'success';
                    
                    // Delete old profile image if exists
                    if (!empty($admin['profile_image']) && $admin['profile_image'] !== $imagePath) {
                        $oldImagePath = '../' . $admin['profile_image'];
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }
                    
                    // Refresh admin data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $admin = $stmt->get_result()->fetch_assoc();
                } else {
                    $message = 'Profil şəklini yeniləyərkən xəta baş verdi';
                    $messageType = 'error';
                }
            } else {
                $message = 'Şəkil yükləyərkən xəta baş verdi';
                $messageType = 'error';
            }
        }
    }
}

// Get recent activity logs
$sql = "SELECT al.*, u.fullname 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$activityLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profili | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
        }
        
        .profile-sidebar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            text-align: center;
        }
        
        .profile-image-container {
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
            position: relative;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-color);
        }
        
        .profile-image-default {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
        }
        
        .profile-image-upload {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .profile-image-upload:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .profile-info {
            margin-bottom: 20px;
        }
        
        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .profile-role {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            background: var(--primary-light-color);
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .profile-details {
            text-align: left;
        }
        
        .profile-details-item {
            display: flex;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .profile-details-icon {
            margin-right: 10px;
            color: var(--primary-color);
            width: 20px;
            text-align: center;
        }
        
        .tab-container {
            margin-top: 20px;
        }
        
        .tab-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        
        .tab-button {
            padding: 10px 20px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .tab-button.active {
            color: var(--primary-color);
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-light-color);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .activity-time {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        @media (max-width: 992px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
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
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="inventory.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="orders.php"><i class="fas fa-shopping-cart"></i> Sifarişlər</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($admin['fullname']) ?> <i class="fas fa-angle-down"></i></span>
                <div class="user-menu">
                    <a href="profile.php" class="active"><i class="fas fa-user-cog"></i> Profil</a>
                    <a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Çıxış</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-main">
        <div class="app-container">
            <div class="page-header">
                <h1><i class="fas fa-user-cog"></i> Admin Profili</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <span>Profil</span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?>">
                    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <div class="profile-container">
                <!-- Profile Sidebar -->
                <div class="profile-sidebar">
                    <form action="" method="post" enctype="multipart/form-data" id="profileImageForm">
                        <div class="profile-image-container">
                            <?php if (!empty($admin['profile_image']) && file_exists('../' . $admin['profile_image'])): ?>
                                <img src="../<?= $admin['profile_image'] ?>" alt="Profile Image" class="profile-image">
                            <?php else: ?>
                                <div class="profile-image-default">
                                    <?= strtoupper(substr($admin['fullname'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            
                            <label for="profile_image" class="profile-image-upload" title="Profil şəklini dəyiş">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" id="profile_image" name="profile_image" style="display: none;" accept="image/*" onchange="document.getElementById('profileImageForm').submit()">
                        </div>
                    </form>
                    
                    <div class="profile-info">
                        <div class="profile-name"><?= htmlspecialchars($admin['fullname']) ?></div>
                        <div class="profile-role">Administrator</div>
                    </div>
                    
                    <div class="profile-details">
                        <div class="profile-details-item">
                            <div class="profile-details-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div><?= htmlspecialchars($admin['email']) ?></div>
                        </div>
                        
                        <?php if (!empty($admin['phone'])): ?>
                            <div class="profile-details-item">
                                <div class="profile-details-icon">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div><?= htmlspecialchars($admin['phone']) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="profile-details-item">
                            <div class="profile-details-icon">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div>Qeydiyyat: <?= date('d.m.Y', strtotime($admin['created_at'])) ?></div>
                        </div>
                        
                        <?php if (!empty($admin['last_login'])): ?>
                            <div class="profile-details-item">
                                <div class="profile-details-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div>Son giriş: <?= date('d.m.Y H:i', strtotime($admin['last_login'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Profile Content -->
                <div class="profile-content">
                    <div class="card">
                        <div class="card-body">
                            <div class="tab-container">
                                <div class="tab-header">
                                    <div class="tab-button active" data-tab="profile">Profil Məlumatları</div>
                                    <div class="tab-button" data-tab="password">Şifrə Dəyişdir</div>
                                    <div class="tab-button" data-tab="activity">Aktivlik</div>
                                </div>
                                
                                <!-- Profile Tab -->
                                <div class="tab-content active" id="profile-tab">
                                    <form action="" method="post">
                                        <input type="hidden" name="update_profile" value="1">
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label for="fullname" class="form-label">Ad Soyad</label>
                                                <input type="text" id="fullname" name="fullname" class="form-control" value="<?= htmlspecialchars($admin['fullname']) ?>" required>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="email" class="form-label">E-poçt</label>
                                                <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($admin['email']) ?>" required>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="username" class="form-label">İstifadəçi adı</label>
                                                <input type="text" id="username" class="form-control" value="<?= htmlspecialchars($admin['username']) ?>" disabled>
                                                <div class="form-text">İstifadəçi adı dəyişdirilə bilməz</div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="phone" class="form-label">Telefon</label>
                                                <input type="tel" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($admin['phone'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-group mt-4">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Məlumatları Yenilə
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Password Tab -->
                                <div class="tab-content" id="password-tab">
                                    <form action="" method="post">
                                        <input type="hidden" name="change_password" value="1">
                                        
                                        <div class="form-group">
                                            <label for="current_password" class="form-label">Cari Şifrə</label>
                                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                                        </div>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label for="new_password" class="form-label">Yeni Şifrə</label>
                                                <input type="password" id="new_password" name="new_password" class="form-control" required>
                                                <div class="form-text">Ən azı <?= PASSWORD_MIN_LENGTH ?> simvol</div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="confirm_password" class="form-label">Şifrə Təkrarı</label>
                                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group mt-4">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-key"></i> Şifrəni Dəyişdir
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Activity Tab -->
                                <div class="tab-content" id="activity-tab">
                                    <h3>Son Aktivliklər</h3>
                                    
                                    <?php if (empty($activityLogs)): ?>
                                        <div class="text-center p-4">
                                            <i class="fas fa-info-circle"></i> Aktivlik qeydləri tapılmadı.
                                        </div>
                                    <?php else: ?>
                                        <div class="activity-list">
                                            <?php foreach ($activityLogs as $log): ?>
                                                <div class="activity-item">
                                                    <div class="activity-icon">
                                                        <?php
                                                        $icon = 'fa-info-circle';
                                                        
                                                        if (strpos($log['action_type'], 'login') !== false) {
                                                            $icon = 'fa-sign-in-alt';
                                                        } elseif (strpos($log['action_type'], 'logout') !== false) {
                                                            $icon = 'fa-sign-out-alt';
                                                        } elseif (strpos($log['action_type'], 'profile') !== false) {
                                                            $icon = 'fa-user-edit';
                                                        } elseif (strpos($log['action_type'], 'password') !== false) {
                                                            $icon = 'fa-key';
                                                        } elseif (strpos($log['action_type'], 'create') !== false) {
                                                            $icon = 'fa-plus';
                                                        } elseif (strpos($log['action_type'], 'update') !== false) {
                                                            $icon = 'fa-edit';
                                                        } elseif (strpos($log['action_type'], 'delete') !== false) {
                                                            $icon = 'fa-trash';
                                                        }
                                                        ?>
                                                        <i class="fas <?= $icon ?>"></i>
                                                    </div>
                                                    <div class="activity-content">
                                                        <div class="activity-title">
                                                            <?php
                                                            $actionType = $log['action_type'];
                                                            
                                                            switch ($actionType) {
                                                                case 'login':
                                                                    echo 'Sistemə giriş';
                                                                    break;
                                                                case 'logout':
                                                                    echo 'Sistemdən çıxış';
                                                                    break;
                                                                case 'profile_update':
                                                                    echo 'Profil məlumatları yeniləndi';
                                                                    break;
                                                                case 'password_change':
                                                                    echo 'Şifrə dəyişdirildi';
                                                                    break;
                                                                case 'create_user':
                                                                    echo 'Yeni istifadəçi yaradıldı';
                                                                    break;
                                                                case 'update_user':
                                                                    echo 'İstifadəçi məlumatları yeniləndi';
                                                                    break;
                                                                case 'delete_user':
                                                                    echo 'İstifadəçi silindi';
                                                                    break;
                                                                default:
                                                                    echo htmlspecialchars($actionType);
                                                            }
                                                            ?>
                                                        </div>
                                                        <div class="activity-time"><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></div>
                                                        <?php if (!empty($log['action_details'])): ?>
                                                            <div class="activity-description mt-2"><?= htmlspecialchars($log['action_details']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <a href="activity-log.php?user_id=<?= $userId ?>" class="btn btn-outline">
                                                <i class="fas fa-history"></i> Bütün Aktivlikləri Göstər
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
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
            
            // Tab functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tab = this.getAttribute('data-tab');
                    
                    // Deactivate all tabs
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Activate current tab
                    this.classList.add('active');
                    document.getElementById(tab + '-tab').classList.add('active');
                });
            });
            
            // Password validation
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value !== newPasswordInput.value) {
                    this.setCustomValidity('Şifrələr uyğun gəlmir');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            newPasswordInput.addEventListener('input', function() {
                if (confirmPasswordInput.value && this.value !== confirmPasswordInput.value) {
                    confirmPasswordInput.setCustomValidity('Şifrələr uyğun gəlmir');
                } else {
                    confirmPasswordInput.setCustomValidity('');
                }
            });
            
            // Set active tab from URL hash if exists
            const hash = window.location.hash.substring(1);
            if (hash) {
                const tabButton = document.querySelector(`.tab-button[data-tab="${hash}"]`);
                if (tabButton) {
                    tabButton.click();
                }
            }
        });
    </script>
</body>
</html>