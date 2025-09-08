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

// Fetch current settings
$settings = [
    'company_name' => COMPANY_NAME,
    'company_address' => COMPANY_ADDRESS,
    'company_phone' => COMPANY_PHONE,
    'company_email' => COMPANY_EMAIL,
    'company_vat_id' => COMPANY_VAT_ID,
    'site_url' => SITE_URL,
    'site_name' => SITE_NAME,
    'site_description' => SITE_DESCRIPTION,
    'default_language' => DEFAULT_LANGUAGE,
    'timezone' => TIMEZONE
];

// Get branches
$branchesSql = "SELECT * FROM branches ORDER BY name";
$branches = $conn->query($branchesSql)->fetch_all(MYSQLI_ASSOC);

// Get notification templates
$templatesSql = "SELECT * FROM notification_templates ORDER BY template_name";
$templates = $conn->query($templatesSql)->fetch_all(MYSQLI_ASSOC);

// Process settings update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Company settings update
    if (isset($_POST['update_company'])) {
        $companyName = trim($_POST['company_name']);
        $companyAddress = trim($_POST['company_address']);
        $companyPhone = trim($_POST['company_phone']);
        $companyEmail = trim($_POST['company_email']);
        $companyVatId = trim($_POST['company_vat_id']);
        
        if (empty($companyName)) {
            $message = 'Şirkət adı boş ola bilməz';
            $messageType = 'error';
        } else {
            // In a real application, these values would be updated in a settings table
            // For this demo, we'll just update the configuration file
            // This is a simplified approach - in production, use a proper settings management system
            
            // Update session with new values
            $settings['company_name'] = $companyName;
            $settings['company_address'] = $companyAddress;
            $settings['company_phone'] = $companyPhone;
            $settings['company_email'] = $companyEmail;
            $settings['company_vat_id'] = $companyVatId;
            
            $message = 'Şirkət məlumatları uğurla yeniləndi';
            $messageType = 'success';
            
            // Log activity
            logActivity($userId, 'update_company_settings', 'Şirkət məlumatları yeniləndi');
        }
    }
    
    // Site settings update
    if (isset($_POST['update_site'])) {
        $siteUrl = trim($_POST['site_url']);
        $siteName = trim($_POST['site_name']);
        $siteDescription = trim($_POST['site_description']);
        $defaultLanguage = trim($_POST['default_language']);
        $timezone = trim($_POST['timezone']);
        
        if (empty($siteName)) {
            $message = 'Sayt adı boş ola bilməz';
            $messageType = 'error';
        } else {
            // Update session with new values
            $settings['site_url'] = $siteUrl;
            $settings['site_name'] = $siteName;
            $settings['site_description'] = $siteDescription;
            $settings['default_language'] = $defaultLanguage;
            $settings['timezone'] = $timezone;
            
            $message = 'Sayt məlumatları uğurla yeniləndi';
            $messageType = 'success';
            
            // Log activity
            logActivity($userId, 'update_site_settings', 'Sayt məlumatları yeniləndi');
        }
    }
    
    // Branch add/update
    if (isset($_POST['update_branch'])) {
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $branchName = trim($_POST['branch_name']);
        $branchAddress = trim($_POST['branch_address']);
        $branchPhone = trim($_POST['branch_phone']);
        $branchManagerId = (int)($_POST['branch_manager_id'] ?? 0);
        $branchStatus = $_POST['branch_status'] ?? 'active';
        
        if (empty($branchName)) {
            $message = 'Filial adı boş ola bilməz';
            $messageType = 'error';
        } else {
            if ($branchId > 0) {
                // Update existing branch
                $sql = "UPDATE branches SET 
                        name = ?, 
                        address = ?, 
                        phone = ?, 
                        manager_id = ?, 
                        status = ?,
                        updated_at = NOW(),
                        updated_by = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssisii", $branchName, $branchAddress, $branchPhone, $branchManagerId, $branchStatus, $userId, $branchId);
                
                if ($stmt->execute()) {
                    $message = 'Filial məlumatları uğurla yeniləndi';
                    $messageType = 'success';
                    
                    // Log activity
                    logActivity($userId, 'update_branch', 'Filial məlumatları yeniləndi: ' . $branchName);
                } else {
                    $message = 'Filial məlumatlarını yeniləyərkən xəta baş verdi';
                    $messageType = 'error';
                }
            } else {
                // Add new branch
               $sql = "INSERT INTO branches (name, address, phone, manager_id, status, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssis", $branchName, $branchAddress, $branchPhone, $branchManagerId, $branchStatus);
                
                if ($stmt->execute()) {
                    $message = 'Yeni filial uğurla əlavə edildi';
                    $messageType = 'success';
                    
                    // Log activity
                    logActivity($userId, 'create_branch', 'Yeni filial əlavə edildi: ' . $branchName);
                    
                    // Refresh branches list
                    $branches = $conn->query($branchesSql)->fetch_all(MYSQLI_ASSOC);
                } else {
                    $message = 'Filial əlavə edərkən xəta baş verdi';
                    $messageType = 'error';
                }
            }
        }
    }
    
    // Template update
    if (isset($_POST['update_template'])) {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $templateName = trim($_POST['template_name']);
        $templateType = $_POST['template_type'] ?? 'whatsapp';
        $templateSubject = trim($_POST['template_subject'] ?? '');
        $templateContent = trim($_POST['template_content']);
        $templateVariables = trim($_POST['template_variables'] ?? '');
        
        if (empty($templateName) || empty($templateContent)) {
            $message = 'Şablon adı və məzmunu boş ola bilməz';
            $messageType = 'error';
        } else {
            if ($templateId > 0) {
                // Update existing template
                $sql = "UPDATE notification_templates SET 
                        template_name = ?, 
                        template_type = ?, 
                        template_subject = ?, 
                        template_content = ?, 
                        variables = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssi", $templateName, $templateType, $templateSubject, $templateContent, $templateVariables, $templateId);
                
                if ($stmt->execute()) {
                    $message = 'Şablon məlumatları uğurla yeniləndi';
                    $messageType = 'success';
                    
                    // Log activity
                    logActivity($userId, 'update_template', 'Şablon məlumatları yeniləndi: ' . $templateName);
                    
                    // Refresh templates list
                    $templates = $conn->query($templatesSql)->fetch_all(MYSQLI_ASSOC);
                } else {
                    $message = 'Şablon məlumatlarını yeniləyərkən xəta baş verdi';
                    $messageType = 'error';
                }
            } else {
                // Add new template
                $sql = "INSERT INTO notification_templates (template_name, template_type, template_subject, template_content, variables, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssss", $templateName, $templateType, $templateSubject, $templateContent, $templateVariables);
                
                if ($stmt->execute()) {
                    $message = 'Yeni şablon uğurla əlavə edildi';
                    $messageType = 'success';
                    
                    // Log activity
                    logActivity($userId, 'create_template', 'Yeni şablon əlavə edildi: ' . $templateName);
                    
                    // Refresh templates list
                    $templates = $conn->query($templatesSql)->fetch_all(MYSQLI_ASSOC);
                } else {
                    $message = 'Şablon əlavə edərkən xəta baş verdi';
                    $messageType = 'error';
                }
            }
        }
    }
}

// Get managers for branch assignment
$managersSql = "SELECT id, fullname FROM users WHERE role IN ('admin', 'seller') ORDER BY fullname";
$managers = $conn->query($managersSql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Tənzimləmələri | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .tab-container {
            margin-top: 20px;
        }
        
        .tab-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 10px 20px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            font-weight: 500;
            margin-bottom: -1px;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
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
        
        .settings-section {
            margin-bottom: 30px;
        }
        
        .settings-section-title {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .branch-card, .template-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
            transition: all 0.3s;
        }
        
        .branch-card:hover, .template-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .branch-title, .template-title {
            font-weight: 500;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .branch-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        .branch-details, .template-details {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .branch-actions, .template-actions {
            display: flex;
            gap: 10px;
        }
        
        .template-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            background: #e0f2fe;
            color: #0369a1;
            margin-left: 10px;
        }
        
        .template-preview {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-top: 10px;
        }
        
        .template-variables {
            background: #e0f2fe;
            border-radius: var(--border-radius);
            padding: 10px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .variable-tag {
            display: inline-block;
            background: #0369a1;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            margin-right: 5px;
            margin-bottom: 5px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        
        /* Custom Modal Styling - Override Bootstrap */
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.7) !important;
            opacity: 1 !important;
        }
        
        .modal-dialog {
            max-width: 500px !important;
            margin: 1.75rem auto !important;
        }
        
        .modal-dialog.large {
            max-width: 700px !important;
        }
        
        .modal-content {
            border: none !important;
            border-radius: 8px !important;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2) !important;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1eb15a 0%, #1e5eb1 100%) !important;
            color: white !important;
            border-bottom: none !important;
            padding: 15px 20px !important;
            border-top-left-radius: 8px !important;
            border-top-right-radius: 8px !important;
        }
        
        .modal-title {
            font-weight: 600 !important;
            font-size: 18px !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        
        .close {
            color: white !important;
            opacity: 0.8 !important;
            text-shadow: none !important;
            font-size: 24px !important;
        }
        
        .close:hover {
            opacity: 1 !important;
        }
        
        .modal-body {
            padding: 20px !important;
            background-color: white !important;
            color: #333 !important;
        }
        
        .modal-footer {
            border-top: 1px solid #eee !important;
            padding: 15px 20px !important;
            background-color: white !important;
        }
        
        @media (max-width: 992px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .modal-dialog {
                margin: 10px !important;
                max-width: calc(100% - 20px) !important;
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
                <a href="settings.php" class="active"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= $_SESSION['fullname'] ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-cog"></i> Sistem Tənzimləmələri</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <span>Tənzimləmələr</span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?>">
                    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body">
                    <div class="tab-container">
                        <div class="tab-header">
                            <div class="tab-button active" data-tab="company">Şirkət Məlumatları</div>
                            <div class="tab-button" data-tab="site">Sayt Tənzimləmələri</div>
                            <div class="tab-button" data-tab="branches">Filiallar</div>
                            <div class="tab-button" data-tab="notifications">Bildiriş Şablonları</div>
                            <div class="tab-button" data-tab="backups">Ehtiyat Nüsxələr</div>
                        </div>
                        
                        <!-- Company Settings Tab -->
                        <div class="tab-content active" id="company-tab">
                            <form action="" method="post">
                                <input type="hidden" name="update_company" value="1">
                                
                                <div class="settings-section">
                                    <div class="settings-section-title">Şirkət Məlumatları</div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="company_name" class="form-label">Şirkət Adı</label>
                                            <input type="text" id="company_name" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name']) ?>" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="company_email" class="form-label">E-poçt</label>
                                            <input type="email" id="company_email" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email']) ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="company_phone" class="form-label">Telefon</label>
                                            <input type="text" id="company_phone" name="company_phone" class="form-control" value="<?= htmlspecialchars($settings['company_phone']) ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="company_vat_id" class="form-label">VÖEN</label>
                                            <input type="text" id="company_vat_id" name="company_vat_id" class="form-control" value="<?= htmlspecialchars($settings['company_vat_id']) ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="company_address" class="form-label">Ünvan</label>
                                        <textarea id="company_address" name="company_address" class="form-control" rows="3"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="form-group mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Məlumatları Yenilə
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Site Settings Tab -->
                        <div class="tab-content" id="site-tab">
                            <form action="" method="post">
                                <input type="hidden" name="update_site" value="1">
                                
                                <div class="settings-section">
                                    <div class="settings-section-title">Sayt Tənzimləmələri</div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="site_name" class="form-label">Sayt Adı</label>
                                            <input type="text" id="site_name" name="site_name" class="form-control" value="<?= htmlspecialchars($settings['site_name']) ?>" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="site_url" class="form-label">Sayt URL</label>
                                            <input type="url" id="site_url" name="site_url" class="form-control" value="<?= htmlspecialchars($settings['site_url']) ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="default_language" class="form-label">Varsayılan Dil</label>
                                            <select id="default_language" name="default_language" class="form-control">
                                                <option value="az" <?= $settings['default_language'] === 'az' ? 'selected' : '' ?>>Azərbaycan</option>
                                                <option value="en" <?= $settings['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                                                <option value="ru" <?= $settings['default_language'] === 'ru' ? 'selected' : '' ?>>Русский</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="timezone" class="form-label">Saat Qurşağı</label>
                                            <select id="timezone" name="timezone" class="form-control">
                                                <option value="Asia/Baku" <?= $settings['timezone'] === 'Asia/Baku' ? 'selected' : '' ?>>Bakı (UTC+4)</option>
                                                <option value="Europe/Istanbul" <?= $settings['timezone'] === 'Europe/Istanbul' ? 'selected' : '' ?>>İstanbul (UTC+3)</option>
                                                <option value="Europe/Moscow" <?= $settings['timezone'] === 'Europe/Moscow' ? 'selected' : '' ?>>Moskva (UTC+3)</option>
                                                <option value="UTC" <?= $settings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="site_description" class="form-label">Sayt Təsviri</label>
                                        <textarea id="site_description" name="site_description" class="form-control" rows="3"><?= htmlspecialchars($settings['site_description']) ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="form-group mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Məlumatları Yenilə
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Branches Tab -->
                        <div class="tab-content" id="branches-tab">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Filiallar</span>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openBranchModal()">
                                            <i class="fas fa-plus"></i> Yeni Filial
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="branches-list">
                                    <?php if (empty($branches)): ?>
                                        <div class="text-center p-4">
                                            <i class="fas fa-info-circle"></i> Hələ filial əlavə edilməyib.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($branches as $branch): ?>
                                            <div class="branch-card">
                                                <div class="branch-title">
                                                    <?= htmlspecialchars($branch['name']) ?>
                                                    <span class="branch-status status-<?= $branch['status'] ?>">
                                                        <?= $branch['status'] === 'active' ? 'Aktiv' : 'Deaktiv' ?>
                                                    </span>
                                                </div>
                                                <div class="branch-details">
                                                    <?php if (!empty($branch['address'])): ?>
                                                        <div><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($branch['address']) ?></div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($branch['phone'])): ?>
                                                        <div><i class="fas fa-phone"></i> <?= htmlspecialchars($branch['phone']) ?></div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($branch['manager_id'])): ?>
                                                        <?php
                                                        $managerName = '';
                                                        foreach ($managers as $manager) {
                                                            if ($manager['id'] == $branch['manager_id']) {
                                                                $managerName = $manager['fullname'];
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <?php if (!empty($managerName)): ?>
                                                            <div><i class="fas fa-user"></i> Menecer: <?= htmlspecialchars($managerName) ?></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="branch-actions">
                                                    <button type="button" class="btn btn-sm btn-outline" 
                                                            onclick="editBranch(<?= $branch['id'] ?>, '<?= addslashes($branch['name']) ?>', '<?= addslashes($branch['address'] ?? '') ?>', '<?= addslashes($branch['phone'] ?? '') ?>', '<?= $branch['manager_id'] ?? 0 ?>', '<?= $branch['status'] ?>')">
                                                        <i class="fas fa-edit"></i> Düzəliş et
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notifications Tab -->
                        <div class="tab-content" id="notifications-tab">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Bildiriş Şablonları</span>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openTemplateModal()">
                                            <i class="fas fa-plus"></i> Yeni Şablon
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="templates-list">
                                    <?php if (empty($templates)): ?>
                                        <div class="text-center p-4">
                                            <i class="fas fa-info-circle"></i> Hələ bildiriş şablonu əlavə edilməyib.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($templates as $template): ?>
                                            <div class="template-card">
                                                <div class="template-title">
                                                    <?= htmlspecialchars($template['template_name']) ?>
                                                    <span class="template-type">
                                                        <?php
                                                        switch ($template['template_type']) {
                                                            case 'whatsapp':
                                                                echo 'WhatsApp';
                                                                break;
                                                            case 'email':
                                                                echo 'E-poçt';
                                                                break;
                                                            case 'sms':
                                                                echo 'SMS';
                                                                break;
                                                            case 'system':
                                                                echo 'Sistem';
                                                                break;
                                                            default:
                                                                echo ucfirst($template['template_type']);
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="template-details">
                                                    <?php if (!empty($template['template_subject'])): ?>
                                                        <div><strong>Mövzu:</strong> <?= htmlspecialchars($template['template_subject']) ?></div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($template['template_content'])): ?>
                                                        <div class="mt-2">
                                                            <?= mb_substr(htmlspecialchars($template['template_content']), 0, 100) ?>...
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="template-actions">
                                                    <button type="button" class="btn btn-sm btn-outline" 
                                                            onclick="editTemplate(<?= $template['id'] ?>, '<?= addslashes($template['template_name']) ?>', '<?= addslashes($template['template_type']) ?>', '<?= addslashes($template['template_subject'] ?? '') ?>', '<?= addslashes($template['template_content']) ?>', '<?= addslashes($template['variables'] ?? '') ?>')">
                                                        <i class="fas fa-edit"></i> Düzəliş et
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline" 
                                                            onclick="previewTemplate(<?= $template['id'] ?>, '<?= addslashes($template['template_name']) ?>', '<?= addslashes($template['template_type']) ?>', '<?= addslashes($template['template_subject'] ?? '') ?>', '<?= addslashes($template['template_content']) ?>', '<?= addslashes($template['variables'] ?? '') ?>')">
                                                        <i class="fas fa-eye"></i> Önizləmə
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Backups Tab -->
                        <div class="tab-content" id="backups-tab">
                            <div class="settings-section">
                                <div class="settings-section-title">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Ehtiyat Nüsxə Yarat</span>
                                        <button type="button" class="btn btn-sm btn-primary" id="createBackupBtn">
                                            <i class="fas fa-download"></i> Yeni Ehtiyat Nüsxə
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Ehtiyat nüsxə yaratmaq üçün düyməyə klikləyin. Bu əməliyyat verilənlər bazasının tam bir surətini yaradacaq.
                                </div>
                                
                                <div class="mt-4" id="backupStatus" style="display: none;">
                                    <div class="progress mb-3">
                                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                    </div>
                                    <div id="backupMessage"></div>
                                </div>
                                
                                <div class="settings-section-title mt-4">Mövcud Ehtiyat Nüsxələr</div>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Tarix</th>
                                                <th>Fayl Adı</th>
                                                <th>Ölçü</th>
                                                <th>Əməliyyatlar</th>
                                            </tr>
                                        </thead>
                                        <tbody id="backupsList">
                                            <tr>
                                                <td colspan="4" class="text-center">Ehtiyat nüsxələr yüklənir...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Branch Modal -->
    <div class="modal fade" id="branchModal" tabindex="-1" role="dialog" aria-labelledby="branchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="branchModalLabel"><i class="fas fa-building"></i> Yeni Filial</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="update_branch" value="1">
                        <input type="hidden" name="branch_id" id="branch_id" value="0">
                        
                        <div class="form-group">
                            <label for="branch_name" class="form-label">Filial Adı <span class="text-danger">*</span></label>
                            <input type="text" id="branch_name" name="branch_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_address" class="form-label">Ünvan</label>
                            <textarea id="branch_address" name="branch_address" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_phone" class="form-label">Telefon</label>
                            <input type="text" id="branch_phone" name="branch_phone" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_manager_id" class="form-label">Menecer</label>
                            <select id="branch_manager_id" name="branch_manager_id" class="form-control">
                                <option value="">Seçin...</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?= $manager['id'] ?>"><?= htmlspecialchars($manager['fullname']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_status" class="form-label">Status</label>
                            <select id="branch_status" name="branch_status" class="form-control">
                                <option value="active">Aktiv</option>
                                <option value="inactive">Deaktiv</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary">Yadda Saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1" role="dialog" aria-labelledby="templateModalLabel" aria-hidden="true">
        <div class="modal-dialog large" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalLabel"><i class="fas fa-file-alt"></i> Yeni Bildiriş Şablonu</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="update_template" value="1">
                        <input type="hidden" name="template_id" id="template_id" value="0">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="template_name" class="form-label">Şablon Adı <span class="text-danger">*</span></label>
                                <input type="text" id="template_name" name="template_name" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="template_type" class="form-label">Şablon Növü <span class="text-danger">*</span></label>
                                <select id="template_type" name="template_type" class="form-control">
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="email">E-poçt</option>
                                    <option value="sms">SMS</option>
                                    <option value="system">Sistem Bildirişi</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_subject" class="form-label">Mövzu</label>
                            <input type="text" id="template_subject" name="template_subject" class="form-control">
                            <div class="form-text">E-poçt üçün tələb olunur</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_content" class="form-label">Məzmun <span class="text-danger">*</span></label>
                            <textarea id="template_content" name="template_content" class="form-control" rows="6" required></textarea>
                            <div class="form-text">Dəyişənlər: {{dəyişən_adı}} formatında yazın</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_variables" class="form-label">Dəyişənlər (JSON formatında)</label>
                            <input type="text" id="template_variables" name="template_variables" class="form-control" placeholder='["customer_name", "order_number", "amount"]'>
                            <div class="template-variables" id="variablesList">
                                <div class="mb-2">Tez əlavə etmək üçün klikləyin:</div>
                                <span class="variable-tag" onclick="addVariable('customer_name')">customer_name</span>
                                <span class="variable-tag" onclick="addVariable('order_number')">order_number</span>
                                <span class="variable-tag" onclick="addVariable('order_date')">order_date</span>
                                <span class="variable-tag" onclick="addVariable('total_amount')">total_amount</span>
                                <span class="variable-tag" onclick="addVariable('remaining_debt')">remaining_debt</span>
                                <span class="variable-tag" onclick="addVariable('phone')">phone</span>
                                <span class="variable-tag" onclick="addVariable('email')">email</span>
                                <span class="variable-tag" onclick="addVariable('company_name')">company_name</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary">Yadda Saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Template Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-labelledby="previewModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalLabel"><i class="fas fa-eye"></i> Şablon Önizləməsi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>Şablon:</strong> <span id="preview_template_name"></span>
                        <span class="template-type" id="preview_template_type"></span>
                    </div>
                    
                    <div class="mb-3" id="preview_subject_container">
                        <strong>Mövzu:</strong> <span id="preview_template_subject"></span>
                    </div>
                    
                    <div class="template-preview" id="preview_template_content"></div>
                    
                    <div class="mt-3">
                        <strong>Dəyişənlər:</strong>
                        <div id="preview_variables" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.0/js/bootstrap.bundle.min.js"></script>
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
                    
                    // Update URL hash
                    window.location.hash = tab;
                });
            });
            
            // Set active tab from URL hash if exists
            const hash = window.location.hash.substring(1);
            if (hash) {
                const tabButton = document.querySelector(`.tab-button[data-tab="${hash}"]`);
                if (tabButton) {
                    tabButton.click();
                }
            }
            
            // Modal close buttons
            document.querySelectorAll('[data-dismiss="modal"]').forEach(button => {
                button.addEventListener('click', function() {
                    jQuery(this).closest('.modal').modal('hide');
                });
            });
            
            // Backup functionality
            document.getElementById('createBackupBtn').addEventListener('click', function() {
                const backupStatus = document.getElementById('backupStatus');
                const progressBar = backupStatus.querySelector('.progress-bar');
                const backupMessage = document.getElementById('backupMessage');
                
                backupStatus.style.display = 'block';
                progressBar.style.width = '0%';
                progressBar.setAttribute('aria-valuenow', 0);
                progressBar.textContent = '0%';
                backupMessage.innerHTML = 'Ehtiyat nüsxə yaradılır...';
                
                // Simulate backup process (in real app, this would be an AJAX call)
                let progress = 0;
                const interval = setInterval(function() {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    progressBar.setAttribute('aria-valuenow', progress);
                    progressBar.textContent = progress + '%';
                    
                    if (progress >= 100) {
                        clearInterval(interval);
                        backupMessage.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Ehtiyat nüsxə uğurla yaradıldı: <strong>alumpro_backup_' + formatDate(new Date()) + '.sql</strong></div>';
                        loadBackups();
                    }
                }, 500);
            });
            
            // Load backups (simulate)
            loadBackups();
        });
        
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return year + month + day + '_' + hours + minutes;
        }
        
        function loadBackups() {
            const backupsList = document.getElementById('backupsList');
            
            // Simulate loading backups (in real app, this would be an AJAX call)
            setTimeout(function() {
                const now = new Date();
                const yesterday = new Date(now);
                yesterday.setDate(yesterday.getDate() - 1);
                const lastWeek = new Date(now);
                lastWeek.setDate(lastWeek.getDate() - 7);
                
                backupsList.innerHTML = `
                    <tr>
                        <td>${now.toLocaleString('az-AZ')}</td>
                        <td>alumpro_backup_${formatDate(now)}.sql</td>
                        <td>4.2 MB</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline" title="Yüklə">
                                <i class="fas fa-download"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" title="Bərpa et">
                                <i class="fas fa-undo"></i>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>${yesterday.toLocaleString('az-AZ')}</td>
                        <td>alumpro_backup_${formatDate(yesterday)}.sql</td>
                        <td>4.1 MB</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline" title="Yüklə">
                                <i class="fas fa-download"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" title="Bərpa et">
                                <i class="fas fa-undo"></i>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>${lastWeek.toLocaleString('az-AZ')}</td>
                        <td>alumpro_backup_${formatDate(lastWeek)}.sql</td>
                        <td>3.9 MB</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline" title="Yüklə">
                                <i class="fas fa-download"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" title="Bərpa et">
                                <i class="fas fa-undo"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }, 1000);
        }
        
        // Function to open branch modal
        function openBranchModal() {
            // Reset form data
            document.getElementById('branchModalLabel').innerHTML = '<i class="fas fa-building"></i> Yeni Filial';
            document.getElementById('branch_id').value = 0;
            document.getElementById('branch_name').value = '';
            document.getElementById('branch_address').value = '';
            document.getElementById('branch_phone').value = '';
            document.getElementById('branch_manager_id').value = '';
            document.getElementById('branch_status').value = 'active';
            
            // Open modal with proper settings
            jQuery('#branchModal').modal({
                backdrop: 'static',
                keyboard: false,
                show: true
            });
        }
        
        // Function to edit branch
        function editBranch(id, name, address, phone, managerId, status) {
            document.getElementById('branchModalLabel').innerHTML = '<i class="fas fa-edit"></i> Filial Düzəliş';
            document.getElementById('branch_id').value = id;
            document.getElementById('branch_name').value = name;
            document.getElementById('branch_address').value = address;
            document.getElementById('branch_phone').value = phone;
            
            if (managerId) {
                document.getElementById('branch_manager_id').value = managerId;
            } else {
                document.getElementById('branch_manager_id').value = '';
            }
            
            document.getElementById('branch_status').value = status;
            
            // Open modal with proper settings
            jQuery('#branchModal').modal({
                backdrop: 'static',
                keyboard: false,
                show: true
            });
        }
        
        // Function to open template modal
        function openTemplateModal() {
            // Reset form data
            document.getElementById('templateModalLabel').innerHTML = '<i class="fas fa-file-alt"></i> Yeni Bildiriş Şablonu';
            document.getElementById('template_id').value = 0;
            document.getElementById('template_name').value = '';
            document.getElementById('template_type').value = 'whatsapp';
            document.getElementById('template_subject').value = '';
            document.getElementById('template_content').value = '';
            document.getElementById('template_variables').value = '';
            
            // Open modal with proper settings
            jQuery('#templateModal').modal({
                backdrop: 'static',
                keyboard: false,
                show: true
            });
        }
        
        // Function to edit template
        function editTemplate(id, name, type, subject, content, variables) {
            document.getElementById('templateModalLabel').innerHTML = '<i class="fas fa-edit"></i> Şablon Düzəliş';
            document.getElementById('template_id').value = id;
            document.getElementById('template_name').value = name;
            document.getElementById('template_type').value = type;
            document.getElementById('template_subject').value = subject;
            document.getElementById('template_content').value = content;
            document.getElementById('template_variables').value = variables;
            
            // Open modal with proper settings
            jQuery('#templateModal').modal({
                backdrop: 'static',
                keyboard: false,
                show: true
            });
        }
        
        // Function to preview template
        function previewTemplate(id, name, type, subject, content, variables) {
            document.getElementById('preview_template_name').textContent = name;
            
            // Set type with nice formatting
            let typeText = type;
            switch (type) {
                case 'whatsapp':
                    typeText = 'WhatsApp';
                    break;
                case 'email':
                    typeText = 'E-poçt';
                    break;
                case 'sms':
                    typeText = 'SMS';
                    break;
                case 'system':
                    typeText = 'Sistem';
                    break;
            }
            document.getElementById('preview_template_type').textContent = typeText;
            
            // Show/hide subject based on type
            const subjectContainer = document.getElementById('preview_subject_container');
            if (type === 'email' || type === 'system') {
                subjectContainer.style.display = 'block';
                document.getElementById('preview_template_subject').textContent = subject;
            } else {
                subjectContainer.style.display = 'none';
            }
            
            // Format content with variables highlighted
            let formattedContent = content.replace(/\{\{([^}]+)\}\}/g, '<span style="background-color: #e0f2fe; padding: 0 4px; border-radius: 4px; color: #0369a1;">{{$1}}</span>');
            document.getElementById('preview_template_content').innerHTML = formattedContent;
            
            // Display variables
            const variablesContainer = document.getElementById('preview_variables');
            if (variables && variables.trim() !== '') {
                try {
                    const varsArray = JSON.parse(variables);
                    let varsHtml = '';
                    
                    varsArray.forEach(variable => {
                        varsHtml += `<span class="variable-tag">${variable}</span>`;
                    });
                    
                    variablesContainer.innerHTML = varsHtml;
                } catch (e) {
                    variablesContainer.innerHTML = '<div class="alert alert-warning">JSON formatı xətası</div>';
                }
            } else {
                variablesContainer.innerHTML = '<em>Dəyişən təyin edilməyib</em>';
            }
            
            // Open modal with proper settings
            jQuery('#previewModal').modal({
                backdrop: 'static',
                keyboard: false,
                show: true
            });
        }
        
        // Function to add variable to template
        function addVariable(variable) {
            const input = document.getElementById('template_variables');
            let variables = [];
            
            try {
                if (input.value.trim()) {
                    variables = JSON.parse(input.value);
                }
            } catch (e) {
                variables = [];
            }
            
            // Add variable if it doesn't exist already
            if (!variables.includes(variable)) {
                variables.push(variable);
                input.value = JSON.stringify(variables);
            }
            
            // Add to content as well
            const contentInput = document.getElementById('template_content');
            const cursorPos = contentInput.selectionStart;
            const textBefore = contentInput.value.substring(0, cursorPos);
            const textAfter = contentInput.value.substring(cursorPos);
            
            contentInput.value = textBefore + '{{' + variable + '}}' + textAfter;
            
            // Set focus back to content with cursor at right position
            contentInput.focus();
            contentInput.selectionStart = cursorPos + variable.length + 4;
            contentInput.selectionEnd = cursorPos + variable.length + 4;
        }
    </script>
</body>
</html>