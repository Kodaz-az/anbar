<?php
/**
 * AlumPro.az - Seller Messages
 * Last Updated: 2025-09-03 08:53:52
 * Author: AlumproAz
 */

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

// Check if user has seller or admin role
if (!hasRole(['seller', 'admin'])) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get seller's information
$sellerId = $_SESSION['user_id'];
$sellerName = $_SESSION['fullname'];
$branchId = $_SESSION['branch_id'] ?? null;

// Initialize database connection
$conn = getDBConnection();

// Get branch information
$branch = getBranchById($branchId);
$branchName = $branch ? $branch['name'] : '';

// Handle message actions
$action = $_GET['action'] ?? '';
$messageId = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Handle reply to existing message
if ($action === 'new' && isset($_GET['reply_to']) && is_numeric($_GET['reply_to'])) {
    $replyToId = (int)$_GET['reply_to'];
    
    if (tableExists($conn, 'messages')) {
        $sql = "SELECT m.*, u.fullname AS sender_name 
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.id = ? AND m.receiver_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $replyToId, $sellerId);
        $stmt->execute();
        $originalMessage = $stmt->get_result()->fetch_assoc();
        
        if ($originalMessage) {
            $replySubject = 'RE: ' . $originalMessage['subject'];
            $replyToUserId = $originalMessage['sender_id'];
            $replyToUserName = $originalMessage['sender_name'];
        }
    }
}

// New message form handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $subject = sanitizeInput($_POST['subject'] ?? '');
    $messageText = sanitizeInput($_POST['message'] ?? '');
    
    if ($receiverId <= 0) {
        $error = 'Xəta: Alıcı seçilməyib!';
    } elseif (empty($subject)) {
        $error = 'Xəta: Mövzu daxil edilməyib!';
    } elseif (empty($messageText)) {
        $error = 'Xəta: Mesaj mətni daxil edilməyib!';
    } else {
        // Try to send message
        if (tableExists($conn, 'messages')) {
            $sql = "INSERT INTO messages (sender_id, receiver_id, subject, message, is_read, created_at) 
                    VALUES (?, ?, ?, ?, 0, NOW())";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiss", $sellerId, $receiverId, $subject, $messageText);
            
            if ($stmt->execute()) {
                $success = 'Mesaj uğurla göndərildi!';
                // Clear form fields after successful submission
                unset($receiverId, $subject, $messageText);
            } else {
                $error = 'Mesaj göndərilərkən xəta baş verdi: ' . $conn->error;
            }
        } else {
            $error = 'Mesaj cədvəli mövcud deyil!';
        }
    }
}

// Mark message as read
if ($action === 'read' && $messageId > 0) {
    if (tableExists($conn, 'messages')) {
        $sql = "UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $messageId, $sellerId);
        $stmt->execute();
    }
}

// Delete message
if ($action === 'delete' && $messageId > 0) {
    if (tableExists($conn, 'messages')) {
        $sql = "DELETE FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $messageId, $sellerId, $sellerId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = 'Mesaj silindi!';
        } else {
            $error = 'Mesaj silinərkən xəta baş verdi!';
        }
    }
}

// Get messages
$inbox = [];
$sent = [];

if (tableExists($conn, 'messages')) {
    // Get inbox messages
    $sql = "SELECT m.*, u.fullname AS sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.receiver_id = ?
            ORDER BY m.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $inbox[] = $row;
    }
    
    // Get sent messages
    $sql = "SELECT m.*, u.fullname AS receiver_name 
            FROM messages m
            JOIN users u ON m.receiver_id = u.id
            WHERE m.sender_id = ?
            ORDER BY m.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sent[] = $row;
    }
}

// Get users for new message
$users = [];
if (tableExists($conn, 'users')) {
    $sql = "SELECT id, fullname, role FROM users WHERE id != ? AND status = 'active' ORDER BY role, fullname";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $roleLabels = [
        'admin' => 'Admin',
        'seller' => 'Satıcı',
        'production' => 'İstehsalat',
        'customer' => 'Müştəri'
    ];
    
    while ($row = $result->fetch_assoc()) {
        $roleName = isset($roleLabels[$row['role']]) ? $roleLabels[$row['role']] : $row['role'];
        $row['role_name'] = $roleName;
        $users[] = $row;
    }
}

// Get unread message count
$unreadMessages = 0;
if (tableExists($conn, 'messages')) {
    $sql = "SELECT COUNT(*) AS count FROM messages WHERE receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $unreadMessages = $result['count'] ?? 0;
}

// View single message
$viewMessage = null;
if ($action === 'view' && $messageId > 0) {
    if (tableExists($conn, 'messages')) {
        $sql = "SELECT m.*, 
                    CASE 
                        WHEN m.sender_id = ? THEN u_receiver.fullname
                        ELSE u_sender.fullname
                    END AS other_user,
                    u_sender.fullname AS sender_name,
                    u_receiver.fullname AS receiver_name
                FROM messages m
                JOIN users u_sender ON m.sender_id = u_sender.id
                JOIN users u_receiver ON m.receiver_id = u_receiver.id
                WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiii", $sellerId, $messageId, $sellerId, $sellerId);
        $stmt->execute();
        $viewMessage = $stmt->get_result()->fetch_assoc();
        
        // Mark as read if this is a received message
        if ($viewMessage && $viewMessage['receiver_id'] == $sellerId && !$viewMessage['is_read']) {
            $sql = "UPDATE messages SET is_read = 1 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $messageId);
            $stmt->execute();
            
            // Update the local message object too
            $viewMessage['is_read'] = 1;
            
            // Update the unread count
            $unreadMessages--;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mesajlar | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .messages-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .message-sidebar {
            flex: 0 0 250px;
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .message-content {
            flex: 1;
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 10px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 5px;
            color: #333;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: #e9ecef;
        }
        
        .sidebar-menu i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .message-count {
            background-color: #dc3545;
            color: white;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 12px;
            margin-left: auto;
        }
        
        .message-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .message-item {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .message-item:hover {
            background-color: #f8f9fa;
        }
        
        .message-item.unread {
            background-color: #e9f5ff;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .message-sender {
            font-weight: bold;
        }
        
        .message-date {
            color: #6c757d;
            font-size: 0.85em;
        }
        
        .message-subject {
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .message-preview {
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .message-view {
            padding: 20px;
        }
        
        .message-view-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .message-view-subject {
            font-size: 1.5em;
            margin-bottom: 10px;
        }
        
        .message-view-meta {
            display: flex;
            justify-content: space-between;
            color: #6c757d;
        }
        
        .message-view-body {
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        .message-form {
            margin-top: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn-primary {
            background-color: #007bff;
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            background-color: #0069d9;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .message-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .btn-danger {
            background-color: #dc3545;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3em;
            margin-bottom: 15px;
            color: #adb5bd;
        }
        
        .grouped-select {
            max-height: 300px;
            overflow-y: auto;
        }
        
        optgroup {
            font-weight: bold;
            color: #495057;
        }
        
        @media (max-width: 768px) {
            .messages-container {
                flex-direction: column;
            }
            
            .message-sidebar {
                flex: 0 0 auto;
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
                <a href="index.php"><i class="fas fa-home"></i> Ana Səhifə</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="warehouse.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="messages.php" class="active"><i class="fas fa-envelope"></i> 
                    Mesajlar
                    <?php if($unreadMessages > 0): ?>
                        <span class="notification-badge"><?= $unreadMessages ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($sellerName) ?> <?= !empty($branchName) ? "(" . htmlspecialchars($branchName) . ")" : "" ?></span>
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
                <h1><i class="fas fa-envelope"></i> Mesajlar</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <span>Mesajlar</span>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <div class="messages-container">
                <div class="message-sidebar">
                    <ul class="sidebar-menu">
                        <li>
                            <a href="messages.php" class="<?= ($action !== 'sent' && $action !== 'new' && $action !== 'view') ? 'active' : '' ?>">
                                <i class="fas fa-inbox"></i> Gələnlər
                                <?php if($unreadMessages > 0): ?>
                                    <span class="message-count"><?= $unreadMessages ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li>
                            <a href="messages.php?action=sent" class="<?= $action === 'sent' ? 'active' : '' ?>">
                                <i class="fas fa-paper-plane"></i> Göndərilənlər
                            </a>
                        </li>
                        <li>
                            <a href="messages.php?action=new" class="<?= $action === 'new' ? 'active' : '' ?>">
                                <i class="fas fa-plus"></i> Yeni Mesaj
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="message-content">
                    <?php if ($action === 'new'): ?>
                        <!-- New Message Form -->
                        <h2>Yeni Mesaj</h2>
                        <form method="post" action="messages.php?action=new" class="message-form">
                            <div class="form-group">
                                <label for="receiver_id">Alıcı:</label>
                                <select name="receiver_id" id="receiver_id" class="form-control grouped-select" required>
                                    <option value="">Alıcı seçin</option>
                                    
                                    <?php 
                                    // Group users by role
                                    $usersByRole = [];
                                    foreach ($users as $user) {
                                        $usersByRole[$user['role']][] = $user;
                                    }
                                    
                                    // Display users grouped by role
                                    foreach ($usersByRole as $role => $roleUsers): 
                                        $roleName = isset($roleUsers[0]['role_name']) ? $roleUsers[0]['role_name'] : ucfirst($role);
                                    ?>
                                        <optgroup label="<?= htmlspecialchars($roleName) ?>">
                                            <?php foreach ($roleUsers as $user): ?>
                                                <option value="<?= $user['id'] ?>" <?= (isset($replyToUserId) && $replyToUserId == $user['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($user['fullname']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="subject">Mövzu:</label>
                                <input type="text" name="subject" id="subject" class="form-control" 
                                       value="<?= isset($replySubject) ? htmlspecialchars($replySubject) : '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="message">Mesaj:</label>
                                <textarea name="message" id="message" class="form-control" required><?php 
                                    if (isset($originalMessage)) {
                                        echo "\n\n\n";
                                        echo "------------ " . formatDate($originalMessage['created_at']) . " tarixində " . 
                                             htmlspecialchars($originalMessage['sender_name']) . " yazıb: ------------\n";
                                        echo htmlspecialchars($originalMessage['message']);
                                    }
                                ?></textarea>
                            </div>
                            <button type="submit" name="send_message" class="btn-primary">
                                <i class="fas fa-paper-plane"></i> Göndər
                            </button>
                        </form>
                    
                    <?php elseif ($action === 'view' && $viewMessage): ?>
                        <!-- View Message -->
                        <div class="message-view">
                            <div class="message-view-header">
                                <div class="message-view-subject"><?= htmlspecialchars($viewMessage['subject']) ?></div>
                                <div class="message-view-meta">
                                    <div>
                                        <?php if ($viewMessage['sender_id'] == $sellerId): ?>
                                            <strong>Kimə:</strong> <?= htmlspecialchars($viewMessage['receiver_name']) ?>
                                        <?php else: ?>
                                            <strong>Kimdən:</strong> <?= htmlspecialchars($viewMessage['sender_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?= formatDate($viewMessage['created_at']) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="message-view-body">
                                <?= nl2br(htmlspecialchars($viewMessage['message'])) ?>
                            </div>
                            
                            <div class="message-actions">
                                <?php if ($viewMessage['sender_id'] != $sellerId): ?>
                                    <a href="messages.php?action=new&reply_to=<?= $viewMessage['id'] ?>" class="btn-secondary">
                                        <i class="fas fa-reply"></i> Cavabla
                                    </a>
                                <?php endif; ?>
                                
                                <a href="messages.php?action=delete&id=<?= $viewMessage['id'] ?>" class="btn-danger" 
                                   onclick="return confirm('Bu mesajı silmək istədiyinizə əminsiniz?')">
                                    <i class="fas fa-trash"></i> Sil
                                </a>
                                
                                <a href="messages.php<?= $viewMessage['sender_id'] == $sellerId ? '?action=sent' : '' ?>" class="btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Geri
                                </a>
                            </div>
                        </div>
                    
                    <?php elseif ($action === 'sent'): ?>
                        <!-- Sent Messages -->
                        <h2>Göndərilən Mesajlar</h2>
                        <?php if (empty($sent)): ?>
                            <div class="empty-state">
                                <i class="fas fa-paper-plane"></i>
                                <p>Göndərilmiş mesaj yoxdur</p>
                            </div>
                        <?php else: ?>
                            <ul class="message-list">
                                <?php foreach ($sent as $message): ?>
                                    <li class="message-item">
                                        <a href="messages.php?action=view&id=<?= $message['id'] ?>" style="text-decoration:none; color:inherit;">
                                            <div class="message-header">
                                                <div class="message-sender">Kimə: <?= htmlspecialchars($message['receiver_name']) ?></div>
                                                <div class="message-date"><?= formatDate($message['created_at']) ?></div>
                                            </div>
                                            <div class="message-subject"><?= htmlspecialchars($message['subject']) ?></div>
                                            <div class="message-preview"><?= htmlspecialchars(substr($message['message'], 0, 100)) ?><?= strlen($message['message']) > 100 ? '...' : '' ?></div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <!-- Inbox Messages -->
                        <h2>Gələn Mesajlar</h2>
                        <?php if (empty($inbox)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>Gələn qutusu boşdur</p>
                            </div>
                        <?php else: ?>
                            <ul class="message-list">
                                <?php foreach ($inbox as $message): ?>
                                    <li class="message-item <?= $message['is_read'] ? '' : 'unread' ?>">
                                        <a href="messages.php?action=view&id=<?= $message['id'] ?>" style="text-decoration:none; color:inherit;">
                                            <div class="message-header">
                                                <div class="message-sender"><?= htmlspecialchars($message['sender_name']) ?></div>
                                                <div class="message-date"><?= formatDate($message['created_at']) ?></div>
                                            </div>
                                            <div class="message-subject">
                                                <?= $message['is_read'] ? '' : '<i class="fas fa-circle" style="color:#007bff; font-size:0.7em; margin-right:5px;"></i>' ?>
                                                <?= htmlspecialchars($message['subject']) ?>
                                            </div>
                                            <div class="message-preview"><?= htmlspecialchars(substr($message['message'], 0, 100)) ?><?= strlen($message['message']) > 100 ? '...' : '' ?></div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
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
            // Show user menu on click
            const userInfo = document.querySelector('.user-info');
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
            
            // Highlight currently active menu item
            const currentPath = window.location.pathname;
            document.querySelectorAll('.nav-links a').forEach(link => {
                if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>