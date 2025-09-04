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

// Check if user has customer role
if (!hasRole('customer')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get user information
$userId = $_SESSION['user_id'];
$userName = $_SESSION['fullname'];

// Get conversation ID if specified
$conversationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNewMessage = isset($_GET['new']) && $_GET['new'] === '1';
$refType = $_GET['ref'] ?? '';
$refId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get customer details from the database
$conn = getDBConnection();
$sql = "SELECT * FROM customers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    // If customer record not found
    $_SESSION['error_message'] = "Müştəri məlumatları tapılmadı";
    header('Location: ../auth/logout.php');
    exit;
}

// Get contacts (sellers and admins)
$sql = "SELECT u.id, u.fullname, u.role, b.name as branch 
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.role IN ('seller', 'admin') AND u.status = 'active'
        ORDER BY u.role = 'admin' DESC, u.fullname";

$stmt = $conn->prepare($sql);
$stmt->execute();
$contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get conversations
$sql = "SELECT 
            c.id,
            c.last_message_time,
            c.unread_count,
            u.id as contact_id,
            u.fullname as contact_name,
            u.role as contact_role,
            b.name as branch_name,
            (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message
        FROM conversations c
        JOIN users u ON (c.user1_id = ? AND c.user2_id = u.id) OR (c.user2_id = ? AND c.user1_id = u.id)
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE (c.user1_id = ? OR c.user2_id = ?)
        ORDER BY c.last_message_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$conversations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get messages for selected conversation
$messages = [];
$activeContact = null;

if ($conversationId > 0) {
    // Verify if this conversation belongs to the user
    $sql = "SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $conversationId, $userId, $userId);
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_assoc();
    
    if (!$conversation) {
        $conversationId = 0;
    } else {
        // Get other user info
        $otherUserId = ($conversation['user1_id'] == $userId) ? $conversation['user2_id'] : $conversation['user1_id'];
        
        $sql = "SELECT u.id, u.fullname, u.role, b.name as branch 
                FROM users u
                LEFT JOIN branches b ON u.branch_id = b.id
                WHERE u.id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $otherUserId);
        $stmt->execute();
        $activeContact = $stmt->get_result()->fetch_assoc();
        
        // Get messages
        $sql = "SELECT m.*, u.fullname as sender_name 
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $conversationId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Mark messages as read
        $sql = "UPDATE messages SET is_read = 1, read_at = NOW() 
                WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $conversationId, $userId);
        $stmt->execute();
        
        // Update conversation unread count
        $sql = "UPDATE conversations SET unread_count = 0 
                WHERE id = ? AND (user1_id = ? OR user2_id = ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $conversationId, $userId, $userId);
        $stmt->execute();
    }
} elseif ($isNewMessage && !empty($contacts)) {
    // If creating a new message, select the first contact
    $activeContact = $contacts[0];
    
    // Check if conversation already exists
    $sql = "SELECT id FROM conversations 
            WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $userId, $activeContact['id'], $activeContact['id'], $userId);
    $stmt->execute();
    $existingConversation = $stmt->get_result()->fetch_assoc();
    
    if ($existingConversation) {
        $conversationId = $existingConversation['id'];
        
        // Get messages
        $sql = "SELECT m.*, u.fullname as sender_name 
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $conversationId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Mark messages as read
        $sql = "UPDATE messages SET is_read = 1, read_at = NOW() 
                WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $conversationId, $userId);
        $stmt->execute();
    }
}

// Handle new message submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $message = trim($_POST['message'] ?? '');
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    
    if (empty($message)) {
        $error = 'Mesaj daxil edilməlidir';
    } elseif ($receiverId <= 0) {
        $error = 'Alıcı seçilməlidir';
    } else {
        // Check if conversation exists
        $sql = "SELECT id FROM conversations 
                WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiii", $userId, $receiverId, $receiverId, $userId);
        $stmt->execute();
        $existingConversation = $stmt->get_result()->fetch_assoc();
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            if ($existingConversation) {
                $conversationId = $existingConversation['id'];
                
                // Update conversation last message time
                $sql = "UPDATE conversations 
                        SET last_message_time = NOW(), 
                            unread_count = unread_count + 1
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $conversationId);
                $stmt->execute();
            } else {
                // Create new conversation
                $sql = "INSERT INTO conversations (user1_id, user2_id, created_at, last_message_time, unread_count) 
                        VALUES (?, ?, NOW(), NOW(), 1)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $userId, $receiverId);
                $stmt->execute();
                
                $conversationId = $conn->insert_id;
            }
            
            // Save the message
            $sql = "INSERT INTO messages (conversation_id, sender_id, receiver_id, message, created_at, is_read) 
                    VALUES (?, ?, ?, ?, NOW(), 0)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiis", $conversationId, $userId, $receiverId, $message);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Redirect to the conversation to prevent form resubmission
            header("Location: messages.php?id={$conversationId}");
            exit;
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = 'Mesaj göndərilərkən xəta baş verdi';
            
            if (DEBUG_MODE) {
                error_log("Message sending error: " . $e->getMessage());
            }
        }
    }
}

// Get total unread message count
$sql = "SELECT SUM(unread_count) as total_unread FROM conversations 
        WHERE (user1_id = ? OR user2_id = ?) AND unread_count > 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['total_unread'] ?? 0;
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1eb15a">
    <title>Mesajlar | AlumPro</title>
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
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Mobile app style header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .header-actions a, .back-btn {
            color: var(--text-light);
            text-decoration: none;
            font-size: 18px;
            position: relative;
        }
        
        /* Main container */
        .messages-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* Conversations list */
        .conversations-list {
            width: 320px;
            background: white;
            border-right: 1px solid #e5e7eb;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .search-box {
            padding: var(--spacing-md);
            border-bottom: 1px solid #e5e7eb;
        }
        
        .search-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .conversations {
            flex: 1;
            overflow-y: auto;
        }
        
        .conversation-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            text-decoration: none;
            color: var(--text-dark);
            position: relative;
        }
        
        .conversation-item.active {
            background: #f3f4f6;
        }
        
        .conversation-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .conversation-content {
            flex: 1;
            min-width: 0;
        }
        
        .conversation-name {
            font-weight: 500;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-last-message {
            font-size: 13px;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-time {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
        }
        
        .conversation-unread {
            position: absolute;
            top: 50%;
            right: var(--spacing-md);
            transform: translateY(-50%);
            background: var(--primary-color);
            color: white;
            font-size: 12px;
            font-weight: 500;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .new-message-btn {
            padding: var(--spacing-md);
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--primary-color);
            font-weight: 500;
            gap: 8px;
        }
        
        /* Message view */
        .message-view {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f3f4f6;
        }
        
        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-lg);
            color: #6b7280;
            text-align: center;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: var(--spacing-md);
            opacity: 0.3;
        }
        
        .chat-header {
            padding: var(--spacing-md);
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
        }
        
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .chat-info {
            flex: 1;
        }
        
        .chat-name {
            font-weight: 500;
        }
        
        .chat-status {
            font-size: 12px;
            color: #6b7280;
        }
        
        .messages-container {
            flex: 1;
            padding: var(--spacing-md);
            overflow-y: auto;
        }
        
        .message-bubble {
            max-width: 70%;
            margin-bottom: var(--spacing-md);
            position: relative;
        }
        
        .message-bubble.outgoing {
            margin-left: auto;
            background: #d1fae5;
            border-radius: 16px 4px 16px 16px;
            padding: 12px 16px;
        }
        
        .message-bubble.incoming {
            background: white;
            border-radius: 4px 16px 16px 16px;
            padding: 12px 16px;
        }
        
        .message-sender {
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 4px;
            color: var(--primary-color);
        }
        
        .message-text {
            margin-bottom: 4px;
            word-wrap: break-word;
        }
        
        .message-time {
            font-size: 11px;
            color: #6b7280;
            text-align: right;
        }
        
        .message-input {
            padding: var(--spacing-md);
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: var(--spacing-md);
        }
        
        .message-input textarea {
            flex: 1;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 24px;
            resize: none;
            max-height: 100px;
            font-family: inherit;
            font-size: 14px;
        }
        
        .message-input textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Mobile styles */
        @media (max-width: 768px) {
            .messages-container {
                position: relative;
            }
            
            .conversations-list {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 100%;
                z-index: 5;
                transform: translateX(0);
                transition: transform 0.3s ease;
            }
            
            .conversations-list.hidden {
                transform: translateX(-100%);
            }
            
            .message-view {
                width: 100%;
            }
            
            .back-to-list {
                display: block;
            }
        }
        
        @media (min-width: 769px) {
            .back-to-list {
                display: none;
            }
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
        <div class="header-title">Mesajlar</div>
        <div class="header-actions">
            <a href="notifications.php">
                <i class="fas fa-bell"></i>
                <?php if($unreadMessages > 0): ?>
                    <span class="notification-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
        </div>
    </header>
    
    <div class="messages-container">
        <!-- Conversations List -->
        <div class="conversations-list <?= ($conversationId > 0 && isset($_GET['mobile'])) ? 'hidden' : '' ?>">
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Axtar..." id="search-input">
            </div>
            
            <div class="conversations">
                <?php if (empty($conversations)): ?>
                    <div style="padding: var(--spacing-md); text-align: center; color: #6b7280;">
                        Aktiv söhbət yoxdur
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conversation): ?>
                        <a href="messages.php?id=<?= $conversation['id'] ?>&mobile=1" class="conversation-item <?= ($conversationId == $conversation['id']) ? 'active' : '' ?>">
                            <div class="conversation-avatar">
                                <?= strtoupper(substr($conversation['contact_name'], 0, 1)) ?>
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-name"><?= htmlspecialchars($conversation['contact_name']) ?></div>
                                <div class="conversation-last-message"><?= htmlspecialchars(substr($conversation['last_message'], 0, 50)) ?></div>
                            </div>
                            <div class="conversation-time">
                                <?= formatDate($conversation['last_message_time'], 'H:i') ?>
                            </div>
                            
                            <?php if ($conversation['unread_count'] > 0): ?>
                                <div class="conversation-unread"><?= $conversation['unread_count'] ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <a href="messages.php?new=1" class="new-message-btn">
                <i class="fas fa-plus-circle"></i> Yeni mesaj
            </a>
        </div>
        
        <!-- Message View -->
        <div class="message-view">
            <?php if ($conversationId > 0 && $activeContact): ?>
                <!-- Chat Header -->
                <div class="chat-header">
                    <?php if (isset($_GET['mobile'])): ?>
                        <a href="messages.php" class="back-to-list">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <div class="chat-avatar">
                        <?= strtoupper(substr($activeContact['fullname'], 0, 1)) ?>
                    </div>
                    <div class="chat-info">
                        <div class="chat-name"><?= htmlspecialchars($activeContact['fullname']) ?></div>
                        <div class="chat-status">
                            <?php if ($activeContact['role'] === 'admin'): ?>
                                <span>Administrator</span>
                            <?php else: ?>
                                <span><?= htmlspecialchars($activeContact['branch']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <div class="messages-container" id="messages-container">
                    <?php foreach ($messages as $message): ?>
                        <div class="message-bubble <?= ($message['sender_id'] == $userId) ? 'outgoing' : 'incoming' ?>">
                            <?php if ($message['sender_id'] != $userId): ?>
                                <div class="message-sender"><?= htmlspecialchars($message['sender_name']) ?></div>
                            <?php endif; ?>
                            <div class="message-text"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                            <div class="message-time"><?= formatDate($message['created_at'], 'H:i') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Message Input -->
                <form class="message-input" method="post" action="">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="receiver_id" value="<?= $activeContact['id'] ?>">
                    
                    <textarea name="message" placeholder="Mesajınızı yazın..." required></textarea>
                    <button type="submit" class="send-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            <?php elseif ($isNewMessage && !empty($contacts)): ?>
                <!-- New Message View -->
                <div class="chat-header">
                    <?php if (isset($_GET['mobile'])): ?>
                        <a href="messages.php" class="back-to-list">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <div class="chat-avatar">
                        <?= strtoupper(substr($activeContact['fullname'], 0, 1)) ?>
                    </div>
                    <div class="chat-info">
                        <div class="chat-name"><?= htmlspecialchars($activeContact['fullname']) ?></div>
                        <div class="chat-status">
                            <?php if ($activeContact['role'] === 'admin'): ?>
                                <span>Administrator</span>
                            <?php else: ?>
                                <span><?= htmlspecialchars($activeContact['branch']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="messages-container" id="messages-container">
                    <?php if (empty($messages)): ?>
                        <div style="text-align: center; padding: var(--spacing-lg); color: #6b7280;">
                            <p>Yeni söhbətə başlayın</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="message-bubble <?= ($message['sender_id'] == $userId) ? 'outgoing' : 'incoming' ?>">
                                <?php if ($message['sender_id'] != $userId): ?>
                                    <div class="message-sender"><?= htmlspecialchars($message['sender_name']) ?></div>
                                <?php endif; ?>
                                <div class="message-text"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                                <div class="message-time"><?= formatDate($message['created_at'], 'H:i') ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Message Input -->
                <form class="message-input" method="post" action="">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="receiver_id" value="<?= $activeContact['id'] ?>">
                    
                    <textarea name="message" placeholder="Mesajınızı yazın..." required></textarea>
                    <button type="submit" class="send-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-comments"></i></div>
                    <h3>Mesajlaşma</h3>
                    <p>Söhbət başlatmaq üçün bir kontakt seçin və ya yeni mesaj yaradın.</p>
                </div>
            <?php endif; ?>
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
        <a href="messages.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span>Mesajlar</span>
            <?php if($unreadMessages > 0): ?>
                <span class="notification-badge"><?= $unreadMessages ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll to bottom of messages
            const messagesContainer = document.getElementById('messages-container');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
            
            // Search functionality
            const searchInput = document.getElementById('search-input');
            const conversations = document.querySelectorAll('.conversation-item');
            
            if (searchInput && conversations) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    
                    conversations.forEach(conversation => {
                        const name = conversation.querySelector('.conversation-name').textContent.toLowerCase();
                        const message = conversation.querySelector('.conversation-last-message').textContent.toLowerCase();
                        
                        if (name.includes(searchTerm) || message.includes(searchTerm)) {
                            conversation.style.display = '';
                        } else {
                            conversation.style.display = 'none';
                        }
                    });
                });
            }
            
            // Auto resize textarea
            const textarea = document.querySelector('textarea[name="message"]');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }
        });
    </script>
</body>
</html>