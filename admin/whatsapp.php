<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/whatsapp.php';

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

// Check WhatsApp service status
$serviceStatus = checkWhatsAppServiceStatus();

// Process template actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_template') {
        $templateCode = trim($_POST['template_code'] ?? '');
        $templateName = trim($_POST['template_name'] ?? '');
        $templateContent = trim($_POST['template_content'] ?? '');
        $templateSubject = trim($_POST['template_subject'] ?? '');
        $channel = $_POST['channel'] ?? 'whatsapp';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($templateCode)) {
            $error = 'Şablon kodu daxil edilməlidir';
        } elseif (empty($templateName)) {
            $error = 'Şablon adı daxil edilməlidir';
        } elseif (empty($templateContent)) {
            $error = 'Şablon məzmunu daxil edilməlidir';
        } else {
            $conn = getDBConnection();
            
            // Check if template exists
            $stmt = $conn->prepare("SELECT id FROM notification_templates WHERE template_code = ? AND channel = ?");
            $stmt->bind_param("ss", $templateCode, $channel);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing template
                $templateId = $result->fetch_assoc()['id'];
                
                $stmt = $conn->prepare("UPDATE notification_templates SET template_name = ?, template_subject = ?, template_content = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssi", $templateName, $templateSubject, $templateContent, $status, $templateId);
                
                if ($stmt->execute()) {
                    $success = 'Şablon uğurla yeniləndi';
                    
                    // If this is a WhatsApp template, save it to the WhatsApp service
                    if ($channel === 'whatsapp') {
                        // Save to the templates directory of WhatsApp service
                        $templateFile = __DIR__ . '/../api/whatsapp/templates/' . $templateCode . '.txt';
                        file_put_contents($templateFile, $templateContent);
                    }
                } else {
                    $error = 'Şablon yeniləmə zamanı xəta baş verdi';
                }
            } else {
                // Create new template
                $stmt = $conn->prepare("INSERT INTO notification_templates (template_code, template_name, template_subject, template_content, channel, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param("ssssss", $templateCode, $templateName, $templateSubject, $templateContent, $channel, $status);
                
                if ($stmt->execute()) {
                    $success = 'Şablon uğurla yaradıldı';
                    
                    // If this is a WhatsApp template, save it to the WhatsApp service
                    if ($channel === 'whatsapp') {
                        // Save to the templates directory of WhatsApp service
                        $templateFile = __DIR__ . '/../api/whatsapp/templates/' . $templateCode . '.txt';
                        file_put_contents($templateFile, $templateContent);
                    }
                } else {
                    $error = 'Şablon yaratma zamanı xəta baş verdi';
                }
            }
        }
    } elseif ($action === 'delete_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        
        if ($templateId <= 0) {
            $error = 'Yanlış şablon ID';
        } else {
            $conn = getDBConnection();
            
            // Get template info before deletion
            $stmt = $conn->prepare("SELECT template_code, channel FROM notification_templates WHERE id = ?");
            $stmt->bind_param("i", $templateId);
            $stmt->execute();
            $templateInfo = $stmt->get_result()->fetch_assoc();
            
            if (!$templateInfo) {
                $error = 'Şablon tapılmadı';
            } else {
                $stmt = $conn->prepare("DELETE FROM notification_templates WHERE id = ?");
                $stmt->bind_param("i", $templateId);
                
                if ($stmt->execute()) {
                    $success = 'Şablon uğurla silindi';
                    
                    // If this was a WhatsApp template, delete it from the WhatsApp service
                    if ($templateInfo['channel'] === 'whatsapp') {
                        $templateFile = __DIR__ . '/../api/whatsapp/templates/' . $templateInfo['template_code'] . '.txt';
                        if (file_exists($templateFile)) {
                            unlink($templateFile);
                        }
                    }
                } else {
                    $error = 'Şablon silmə zamanı xəta baş verdi';
                }
            }
        }
    } elseif ($action === 'test_message') {
        $phone = trim($_POST['test_phone'] ?? '');
        $message = trim($_POST['test_message'] ?? '');
        
        if (empty($phone)) {
            $error = 'Telefon nömrəsi daxil edilməlidir';
        } elseif (empty($message)) {
            $error = 'Test mesajı daxil edilməlidir';
        } else {
            $result = sendWhatsAppMessage($phone, $message);
            
            if ($result) {
                $success = 'Test mesajı uğurla göndərildi';
            } else {
                $error = 'Test mesajı göndərmə zamanı xəta baş verdi';
            }
        }
    } elseif ($action === 'restart_service') {
        // Execute the restart command
        $output = [];
        $command = 'cd ' . __DIR__ . '/../api/whatsapp && ./start-service.sh restart';
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $success = 'WhatsApp xidməti yenidən başladıldı';
        } else {
            $error = 'WhatsApp xidmətini yenidən başlatma zamanı xəta baş verdi: ' . implode('<br>', $output);
        }
    }
}

// Get templates
$conn = getDBConnection();
$sql = "SELECT * FROM notification_templates ORDER BY channel, template_code";
$result = $conn->query($sql);
$templates = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }
}

// Get WhatsApp service logs
$logFile = __DIR__ . '/../api/whatsapp/whatsapp-logs.txt';
$logs = '';

if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $logs = implode("\n", array_slice(explode("\n", $logs), -50)); // Get last 50 lines
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp İnteqrasiyası | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .status-indicator.active {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-indicator.inactive {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .status-dot.active {
            background-color: #10b981;
        }
        
        .status-dot.inactive {
            background-color: #ef4444;
        }
        
        .template-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-md);
        }
        
        .template-header {
            padding: var(--spacing-md);
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .template-title {
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .template-body {
            padding: var(--spacing-md);
        }
        
        .template-content {
            background: #f9fafb;
            padding: var(--spacing-md);
            border-radius: 8px;
            font-family: monospace;
            margin-bottom: var(--spacing-md);
            white-space: pre-wrap;
        }
        
        .template-actions {
            display: flex;
            gap: var(--spacing-sm);
        }
        
        .channel-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .channel-whatsapp {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .channel-email {
            background-color: #e0f2fe;
            color: #0369a1;
        }
        
        .channel-sms {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .log-container {
            background: #1f2937;
            color: #e5e7eb;
            padding: var(--spacing-md);
            border-radius: var(--border-radius);
            font-family: monospace;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .qr-container {
            background: white;
            padding: var(--spacing-md);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--spacing-md);
        }
        
        #qrcode {
            max-width: 100%;
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
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php" class="active"><i class="fas fa-cog"></i> Tənzimləmələr</a>
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
                <h1><i class="fab fa-whatsapp"></i> WhatsApp İnteqrasiyası</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <a href="settings.php">Tənzimləmələr</a> / 
                    <span>WhatsApp</span>
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
            
            <!-- Service Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">WhatsApp Xidməti Statusu</h2>
                    <div class="card-actions">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="restart_service">
                            <button type="submit" class="btn btn-outline">
                                <i class="fas fa-sync-alt"></i> Yenidən Başlat
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <div class="status-indicator <?= $serviceStatus && isset($serviceStatus['status']) && $serviceStatus['status'] === 'active' ? 'active' : 'inactive' ?>">
                            <div class="status-dot <?= $serviceStatus && isset($serviceStatus['status']) && $serviceStatus['status'] === 'active' ? 'active' : 'inactive' ?>"></div>
                            <span>
                                <?php if ($serviceStatus && isset($serviceStatus['status']) && $serviceStatus['status'] === 'active'): ?>
                                    WhatsApp xidməti aktiv
                                <?php else: ?>
                                    WhatsApp xidməti aktiv deyil
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="status-indicator <?= $serviceStatus && isset($serviceStatus['client']) && $serviceStatus['client'] === 'connected' ? 'active' : 'inactive' ?>">
                            <div class="status-dot <?= $serviceStatus && isset($serviceStatus['client']) && $serviceStatus['client'] === 'connected' ? 'active' : 'inactive' ?>"></div>
                            <span>
                                <?php if ($serviceStatus && isset($serviceStatus['client']) && $serviceStatus['client'] === 'connected'): ?>
                                    WhatsApp hesabı qoşulub
                                <?php else: ?>
                                    WhatsApp hesabı qoşulmayıb (QR kodu skan edin)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if (!$serviceStatus || !isset($serviceStatus['client']) || $serviceStatus['client'] !== 'connected'): ?>
                        <div class="qr-container">
                            <div id="qrcode">
                                <p>QR kodu yüklənir... Əgər görsənmirsə, WhatsApp xidməti çalışmır ola bilər.</p>
                            </div>
                        </div>
                        
                        <div class="text-center mb-4">
                            <p>WhatsApp Web vasitəsilə qoşulmaq üçün telefonunuzdan bu QR kodu skan edin.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h3 class="mb-3">Test Mesajı Göndər</h3>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="test_message">
                                <div class="form-group">
                                    <label for="test_phone" class="form-label">Telefon Nömrəsi</label>
                                    <input type="text" id="test_phone" name="test_phone" class="form-control" placeholder="+994 XX XXX XX XX" required>
                                </div>
                                <div class="form-group">
                                    <label for="test_message" class="form-label">Test Mesajı</label>
                                    <textarea id="test_message" name="test_message" class="form-control" rows="3" required>Salam, bu bir test mesajıdır.</textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Göndər
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Templates -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">Bildiriş Şablonları</h2>
                    <div class="card-actions">
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#templateModal">
                            <i class="fas fa-plus"></i> Yeni Şablon
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($templates)): ?>
                        <div class="text-center p-4">
                            <p>Heç bir şablon tapılmadı</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($templates as $template): ?>
                            <div class="template-card">
                                <div class="template-header">
                                    <div class="template-title">
                                        <span><?= htmlspecialchars($template['template_name']) ?></span>
                                        <span class="channel-badge channel-<?= $template['channel'] ?>">
                                            <?= strtoupper($template['channel']) ?>
                                        </span>
                                        <?php if ($template['status'] !== 'active'): ?>
                                            <span class="badge badge-secondary">Deaktiv</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="template-actions">
                                        <button type="button" class="btn btn-sm btn-outline edit-template" 
                                                data-id="<?= $template['id'] ?>"
                                                data-code="<?= htmlspecialchars($template['template_code']) ?>"
                                                data-name="<?= htmlspecialchars($template['template_name']) ?>"
                                                data-subject="<?= htmlspecialchars($template['template_subject']) ?>"
                                                data-content="<?= htmlspecialchars($template['template_content']) ?>"
                                                data-channel="<?= htmlspecialchars($template['channel']) ?>"
                                                data-status="<?= htmlspecialchars($template['status']) ?>">
                                            <i class="fas fa-edit"></i> Düzəliş et
                                        </button>
                                        <form method="post" action="" class="d-inline delete-form">
                                            <input type="hidden" name="action" value="delete_template">
                                            <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Sil
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="template-body">
                                    <div class="mb-2">
                                        <strong>Kod:</strong> <?= htmlspecialchars($template['template_code']) ?>
                                    </div>
                                    <?php if (!empty($template['template_subject'])): ?>
                                        <div class="mb-2">
                                            <strong>Başlıq:</strong> <?= htmlspecialchars($template['template_subject']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="template-content"><?= htmlspecialchars($template['template_content']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Logs -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">WhatsApp Xidməti Logları</h2>
                    <div class="card-actions">
                        <button type="button" class="btn btn-outline" id="refresh-logs">
                            <i class="fas fa-sync-alt"></i> Yenilə
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="log-container" id="logs"><?= htmlspecialchars($logs) ?></div>
                </div>
            </div>
        </div>
    </main>

    <!-- Template Modal -->
    <div class="modal" id="templateModal" tabindex="-1">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal">
            <div class="modal-header">
                <h5 class="modal-title">Bildiriş Şablonu</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form id="templateForm" method="post" action="">
                    <input type="hidden" name="action" value="save_template">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="template_code" class="form-label">Şablon Kodu</label>
                                <input type="text" id="template_code" name="template_code" class="form-control" required>
                                <div class="form-text">Şablon kodunu dəyişdirməyin, yeni eyni adlı şablon yaradılacaq</div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="template_name" class="form-label">Şablon Adı</label>
                                <input type="text" id="template_name" name="template_name" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="channel" class="form-label">Kanal</label>
                                <select id="channel" name="channel" class="form-control">
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="email">E-poçt</option>
                                    <option value="sms">SMS</option>
                                    <option value="system">Sistem</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="active">Aktiv</option>
                                    <option value="inactive">Deaktiv</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="template_subject" class="form-label">Başlıq (E-poçt üçün)</label>
                        <input type="text" id="template_subject" name="template_subject" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="template_content" class="form-label">Şablon Məzmunu</label>
                        <textarea id="template_content" name="template_content" class="form-control" rows="8" required></textarea>
                        <div class="form-text">Dəyişənlər üçün {{customer_name}}, {{order_number}} kimi placeholderlər istifadə edin</div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Ləğv Et</button>
                        <button type="submit" class="btn btn-primary">Yadda Saxla</button>
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
            const modal = document.getElementById('templateModal');
            const modalBackdrop = document.querySelector('.modal-backdrop');
            const modalCloseButtons = document.querySelectorAll('[data-dismiss="modal"]');
            
            // Open modal
            document.querySelectorAll('[data-toggle="modal"]').forEach(button => {
                button.addEventListener('click', function() {
                    const target = this.getAttribute('data-target');
                    document.querySelector(target).classList.add('show');
                    
                    // Reset form
                    document.getElementById('templateForm').reset();
                    document.getElementById('template_code').value = '';
                    document.getElementById('template_name').value = '';
                    document.getElementById('template_subject').value = '';
                    document.getElementById('template_content').value = '';
                    document.getElementById('channel').value = 'whatsapp';
                    document.getElementById('status').value = 'active';
                });
            });
            
            // Close modal
            modalCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.classList.remove('show');
                });
            });
            
            // Edit template
            document.querySelectorAll('.edit-template').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const code = this.getAttribute('data-code');
                    const name = this.getAttribute('data-name');
                    const subject = this.getAttribute('data-subject');
                    const content = this.getAttribute('data-content');
                    const channel = this.getAttribute('data-channel');
                    const status = this.getAttribute('data-status');
                    
                    document.getElementById('template_code').value = code;
                    document.getElementById('template_name').value = name;
                    document.getElementById('template_subject').value = subject;
                    document.getElementById('template_content').value = content;
                    document.getElementById('channel').value = channel;
                    document.getElementById('status').value = status;
                    
                    modal.classList.add('show');
                });
            });
            
            // Confirm delete
            document.querySelectorAll('.delete-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Şablonu silmək istədiyinizə əminsiniz?')) {
                        e.preventDefault();
                    }
                });
            });
            
            // Refresh logs
            document.getElementById('refresh-logs').addEventListener('click', function() {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newLogs = doc.getElementById('logs').innerHTML;
                        document.getElementById('logs').innerHTML = newLogs;
                    });
            });
            
            // Load QR code if service is active but client is not connected
            <?php if ($serviceStatus && isset($serviceStatus['status']) && $serviceStatus['status'] === 'active' && 
                  (!isset($serviceStatus['client']) || $serviceStatus['client'] !== 'connected')): ?>
                
                function loadQRCode() {
                    fetch('../api/whatsapp/qrcode.txt')
                        .then(response => {
                            if (response.ok) {
                                return response.text();
                            }
                            throw new Error('Failed to load QR code');
                        })
                        .then(qrCode => {
                            if (qrCode.trim()) {
                                // Use a QR code library to generate the QR code
                                const qrContainer = document.getElementById('qrcode');
                                qrContainer.innerHTML = '';
                                
                                // Create QR code image using Google Charts API
                                const img = document.createElement('img');
                                img.src = 'https://chart.googleapis.com/chart?cht=qr&chl=' + encodeURIComponent(qrCode) + '&chs=300x300&chld=L|0';
                                img.alt = 'WhatsApp QR Code';
                                qrContainer.appendChild(img);
                            } else {
                                document.getElementById('qrcode').innerHTML = '<p>QR kodu hələ mövcud deyil. Bir az sonra yenidən cəhd edin.</p>';
                            }
                        })
                        .catch(error => {
                            console.error('Error loading QR code:', error);
                            document.getElementById('qrcode').innerHTML = '<p>QR kodu yüklənərkən xəta baş verdi. WhatsApp xidməti çalışmır ola bilər.</p>';
                        });
                }
                
                // Load QR code immediately and then every 10 seconds
                loadQRCode();
                setInterval(loadQRCode, 10000);
            <?php endif; ?>
        });
    </script>
</body>
</html>