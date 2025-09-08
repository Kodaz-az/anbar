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

// Initialize database connection
$conn = getDBConnection();

// Initialize variables
$customers = [];
$selectedCustomer = null;
$customerOrders = [];
$totalCustomers = 0;
$error = '';
$success = '';

// Process form submission for new/edit customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new customer
        if ($_POST['action'] === 'add_customer') {
            $fullname = sanitizeInput($_POST['fullname'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $company = sanitizeInput($_POST['company'] ?? '');
            $address = sanitizeInput($_POST['address'] ?? '');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            $createAccount = isset($_POST['create_account']);
            
            if (empty($fullname) || empty($phone)) {
                $error = 'Ad, soyad və telefon nömrəsi tələb olunur';
            } else {
                try {
                    // Check if phone number already exists
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
                    $stmt->bind_param("s", $phone);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error = 'Bu telefon nömrəsi ilə müştəri artıq mövcuddur';
                    } else {
                        // Begin transaction
                        $conn->begin_transaction();
                        
                        // Insert customer
                        $stmt = $conn->prepare("INSERT INTO customers (fullname, phone, email, address, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->bind_param("sssssii", $fullname, $phone, $email, $address, $notes, $adminId, $adminId);
                        
                        if ($stmt->execute()) {
                            $customerId = $conn->insert_id;
                            
                            // Create user account if requested
                            if ($createAccount && !empty($email)) {
                                // Generate random password
                                $password = generateRandomPassword();
                                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                                
                                // Create username from fullname
                                $username = strtolower(preg_replace('/[^a-z0-9]/', '', transliterate($fullname))) . rand(100, 999);
                                
                                $stmt = $conn->prepare("INSERT INTO users (username, email, password, fullname, role, status, created_at) VALUES (?, ?, ?, ?, 'customer', 'active', NOW())");
                                $stmt->bind_param("ssss", $username, $email, $hashedPassword, $fullname);
                                
                                if ($stmt->execute()) {
                                    $userId = $conn->insert_id;
                                    
                                    // Link user to customer
                                    $stmt = $conn->prepare("UPDATE customers SET user_id = ? WHERE id = ?");
                                    $stmt->bind_param("ii", $userId, $customerId);
                                    $stmt->execute();
                                    
                                    // Send welcome message with password
                                    if (defined('WHATSAPP_ENABLED') && WHATSAPP_ENABLED) {
                                        require_once '../includes/whatsapp.php';
                                        
                                        $variables = [
                                            'customer_name' => $fullname,
                                            'email' => $email,
                                            'password' => $password,
                                            'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'AlumPro',
                                            'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : '',
                                            'login_url' => defined('SITE_URL') ? SITE_URL . '/auth/login.php' : ''
                                        ];
                                        
                                        sendWhatsAppTemplate($phone, 'new_account', $variables);
                                    }
                                }
                            }
                            
                            // Log activity
                            logActivity($adminId, 'create_customer', "Admin yeni müştəri yaratdı: $fullname (ID: $customerId)");
                            
                            // Commit transaction
                            $conn->commit();
                            
                            $success = 'Müştəri uğurla əlavə edildi';
                            
                            // Redirect to customer details page
                            header("Location: customers.php?id=$customerId&success=created");
                            exit;
                        } else {
                            $conn->rollback();
                            $error = 'Müştəri yaradılarkən xəta baş verdi: ' . $conn->error;
                        }
                    }
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollback();
                    }
                    $error = 'Sistem xətası baş verdi: ' . $e->getMessage();
                }
            }
        }
        
        // Edit customer
        if ($_POST['action'] === 'edit_customer') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $fullname = sanitizeInput($_POST['fullname'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $company = sanitizeInput($_POST['company'] ?? '');
            $address = sanitizeInput($_POST['address'] ?? '');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            $createAccount = isset($_POST['create_account']);
            
            if (empty($fullname) || empty($phone)) {
                $error = 'Ad, soyad və telefon nömrəsi tələb olunur';
            } else {
                try {
                    // Check if customer exists
                    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $customerId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $customer = $result->fetch_assoc();
                    
                    if (!$customer) {
                        $error = 'Müştəri tapılmadı';
                    } else {
                        // Begin transaction
                        $conn->begin_transaction();
                        
                        // Update customer
                        $stmt = $conn->prepare("UPDATE customers SET fullname = ?, phone = ?, email = ?, address = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->bind_param("sssssii", $fullname, $phone, $email, $address, $notes, $adminId, $customerId);
                        
                        if ($stmt->execute()) {
                            // Handle user account
                            if (!empty($customer['user_id'])) {
                                // Update existing user account
                                $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, updated_at = NOW() WHERE id = ?");
                                $stmt->bind_param("ssi", $fullname, $email, $customer['user_id']);
                                $stmt->execute();
                            } elseif ($createAccount && !empty($email)) {
                                // Create new user account
                                $password = generateRandomPassword();
                                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                                $username = strtolower(preg_replace('/[^a-z0-9]/', '', transliterate($fullname))) . rand(100, 999);
                                
                                $stmt = $conn->prepare("INSERT INTO users (username, email, password, fullname, role, status, created_at) VALUES (?, ?, ?, ?, 'customer', 'active', NOW())");
                                $stmt->bind_param("ssss", $username, $email, $hashedPassword, $fullname);
                                
                                if ($stmt->execute()) {
                                    $userId = $conn->insert_id;
                                    
                                    // Link user to customer
                                    $stmt = $conn->prepare("UPDATE customers SET user_id = ? WHERE id = ?");
                                    $stmt->bind_param("ii", $userId, $customerId);
                                    $stmt->execute();
                                    
                                    // Send welcome message with password
                                    if (defined('WHATSAPP_ENABLED') && WHATSAPP_ENABLED) {
                                        require_once '../includes/whatsapp.php';
                                        
                                        $variables = [
                                            'customer_name' => $fullname,
                                            'email' => $email,
                                            'password' => $password,
                                            'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'AlumPro',
                                            'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : '',
                                            'login_url' => defined('SITE_URL') ? SITE_URL . '/auth/login.php' : ''
                                        ];
                                        
                                        sendWhatsAppTemplate($phone, 'new_account', $variables);
                                    }
                                }
                            }
                            
                            // Log activity
                            logActivity($adminId, 'edit_customer', "Admin müştəri məlumatlarını yenilədi: $fullname (ID: $customerId)");
                            
                            // Commit transaction
                            $conn->commit();
                            
                            $success = 'Müştəri məlumatları uğurla yeniləndi';
                            
                            // Redirect to avoid form resubmission
                            header("Location: customers.php?id=$customerId&success=updated");
                            exit;
                        } else {
                            $conn->rollback();
                            $error = 'Müştəri yenilənərkən xəta baş verdi: ' . $conn->error;
                        }
                    }
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollback();
                    }
                    $error = 'Sistem xətası baş verdi: ' . $e->getMessage();
                }
            }
        }
        
        // Add customer note
        if ($_POST['action'] === 'add_note') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $noteContent = trim($_POST['note'] ?? '');
            
            if (!empty($noteContent) && $customerId > 0) {
                // Check if customer_notes table exists
                $tableExists = false;
                $result = $conn->query("SHOW TABLES LIKE 'customer_notes'");
                if ($result && $result->num_rows > 0) {
                    $tableExists = true;
                }
                
                if ($tableExists) {
                    $stmt = $conn->prepare("INSERT INTO customer_notes (customer_id, note, created_by, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->bind_param("isi", $customerId, $noteContent, $adminId);
                    
                    if ($stmt->execute()) {
                        // Redirect to avoid form resubmission
                        header("Location: customers.php?id=$customerId&note_added=1");
                        exit;
                    } else {
                        $error = 'Qeyd əlavə edilərkən xəta baş verdi: ' . $conn->error;
                    }
                } else {
                    // If table doesn't exist, update customer notes field instead
                    $stmt = $conn->prepare("UPDATE customers SET notes = CONCAT_WS('\n---\n', ?, notes) WHERE id = ?");
                    $stmt->bind_param("si", $noteContent, $customerId);
                    
                    if ($stmt->execute()) {
                        // Redirect to avoid form resubmission
                        header("Location: customers.php?id=$customerId&note_added=1");
                        exit;
                    } else {
                        $error = 'Qeyd əlavə edilərkən xəta baş verdi: ' . $conn->error;
                    }
                }
            } else {
                $error = 'Qeyd məzmunu və müştəri ID tələb olunur';
            }
        }
        
        // Reset customer password
        if ($_POST['action'] === 'reset_password') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            // Get customer information
            $stmt = $conn->prepare("SELECT c.*, u.id as user_id, u.email FROM customers c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            
            if ($customer && !empty($customer['user_id'])) {
                // Generate new password
                $password = generateRandomPassword();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Update password
                $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $customer['user_id']);
                
                if ($stmt->execute()) {
                    // Send new password to customer
                    if (defined('WHATSAPP_ENABLED') && WHATSAPP_ENABLED && !empty($customer['phone'])) {
                        require_once '../includes/whatsapp.php';
                        
                        $variables = [
                            'customer_name' => $customer['fullname'],
                            'email' => $customer['email'],
                            'password' => $password,
                            'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'AlumPro',
                            'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : ''
                        ];
                        
                        sendWhatsAppTemplate($customer['phone'], 'password_reset', $variables);
                    }
                    
                    // Log activity
                    logActivity($adminId, 'reset_customer_password', "Admin müştəri şifrəsini sıfırladı: {$customer['fullname']} (ID: $customerId)");
                    
                    $success = 'Müştəri şifrəsi uğurla sıfırlandı';
                    
                    // Redirect to avoid form resubmission
                    header("Location: customers.php?id=$customerId&success=password_reset");
                    exit;
                } else {
                    $error = 'Şifrə sıfırlanarkən xəta baş verdi: ' . $conn->error;
                }
            } else {
                $error = 'Müştərinin hesabı yoxdur və ya müştəri tapılmadı';
            }
        }
        
        // Deactivate customer account
        if ($_POST['action'] === 'deactivate_account') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            // Get customer information
            $stmt = $conn->prepare("SELECT c.*, u.id as user_id FROM customers c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            
            if ($customer && !empty($customer['user_id'])) {
                // Update status
                $stmt = $conn->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $customer['user_id']);
                
                if ($stmt->execute()) {
                    // Log activity
                    logActivity($adminId, 'deactivate_customer_account', "Admin müştəri hesabını deaktiv etdi: {$customer['fullname']} (ID: $customerId)");
                    
                    $success = 'Müştəri hesabı deaktiv edildi';
                    
                    // Redirect to avoid form resubmission
                    header("Location: customers.php?id=$customerId&success=account_deactivated");
                    exit;
                } else {
                    $error = 'Hesab deaktiv edilərkən xəta baş verdi: ' . $conn->error;
                }
            } else {
                $error = 'Müştərinin hesabı yoxdur və ya müştəri tapılmadı';
            }
        }
        
        // Activate customer account
        if ($_POST['action'] === 'activate_account') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            // Get customer information
            $stmt = $conn->prepare("SELECT c.*, u.id as user_id FROM customers c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            
            if ($customer && !empty($customer['user_id'])) {
                // Update status
                $stmt = $conn->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $customer['user_id']);
                
                if ($stmt->execute()) {
                    // Log activity
                    logActivity($adminId, 'activate_customer_account', "Admin müştəri hesabını aktiv etdi: {$customer['fullname']} (ID: $customerId)");
                    
                    $success = 'Müştəri hesabı aktiv edildi';
                    
                    // Redirect to avoid form resubmission
                    header("Location: customers.php?id=$customerId&success=account_activated");
                    exit;
                } else {
                    $error = 'Hesab aktiv edilərkən xəta baş verdi: ' . $conn->error;
                }
            } else {
                $error = 'Müştərinin hesabı yoxdur və ya müştəri tapılmadı';
            }
        }
        
        // Delete customer (soft delete or handle with caution)
        if ($_POST['action'] === 'delete_customer') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            // Check if customer has orders
            $stmt = $conn->prepare("SELECT COUNT(*) as order_count FROM orders WHERE customer_id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result['order_count'] > 0) {
                $error = 'Bu müştərinin sifarişləri var. Silmədən əvvəl sifarişləri silin və ya müştərini deaktiv edin.';
            } else {
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Check if customer_notes table exists and delete notes if it does
                    $result = $conn->query("SHOW TABLES LIKE 'customer_notes'");
                    if ($result && $result->num_rows > 0) {
                        $stmt = $conn->prepare("DELETE FROM customer_notes WHERE customer_id = ?");
                        $stmt->bind_param("i", $customerId);
                        $stmt->execute();
                    }
                    
                    // Get user ID if exists
                    $stmt = $conn->prepare("SELECT user_id FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $customerId);
                    $stmt->execute();
                    $customer = $stmt->get_result()->fetch_assoc();
                    
                    // Delete user if exists
                    if (!empty($customer['user_id'])) {
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->bind_param("i", $customer['user_id']);
                        $stmt->execute();
                    }
                    
                    // Delete customer
                    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $customerId);
                    
                    if ($stmt->execute()) {
                        // Log activity
                        logActivity($adminId, 'delete_customer', "Admin müştərini sildi (ID: $customerId)");
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $success = 'Müştəri uğurla silindi';
                        
                        // Redirect to customer list
                        header("Location: customers.php?success=deleted");
                        exit;
                    } else {
                        $conn->rollback();
                        $error = 'Müştəri silinərkən xəta baş verdi: ' . $conn->error;
                    }
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollback();
                    }
                    $error = 'Sistem xətası baş verdi: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get filter parameters
$filterType = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Selected customer ID for details view
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if we're viewing a specific customer
if ($customerId > 0) {
    // Get customer details
    $stmt = $conn->prepare("SELECT c.*, u.email, u.status AS account_status, u.last_login
                           FROM customers c
                           LEFT JOIN users u ON c.user_id = u.id
                           WHERE c.id = ?");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $selectedCustomer = $stmt->get_result()->fetch_assoc();
    
    if ($selectedCustomer) {
        // Get customer statistics
        $stmt = $conn->prepare("SELECT 
                               COUNT(*) as total_orders,
                               SUM(total_amount) as total_spent,
                               SUM(remaining_amount) as outstanding_balance,
                               MAX(order_date) as last_order_date
                               FROM orders
                               WHERE customer_id = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $customerStats = $stmt->get_result()->fetch_assoc();
        
        // Get recent orders
        $stmt = $conn->prepare("SELECT o.*, b.name as branch_name, u.fullname as seller_name
                              FROM orders o
                              LEFT JOIN branches b ON o.branch_id = b.id
                              LEFT JOIN users u ON o.seller_id = u.id
                              WHERE o.customer_id = ?
                              ORDER BY o.order_date DESC
                              LIMIT 10");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $customerOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Check if customer_notes table exists
        $customerNotes = [];
        $result = $conn->query("SHOW TABLES LIKE 'customer_notes'");
        if ($result && $result->num_rows > 0) {
            // Get customer notes if the table exists
            $stmt = $conn->prepare("SELECT n.*, u.fullname as created_by_name
                                FROM customer_notes n
                                LEFT JOIN users u ON n.created_by = u.id
                                WHERE n.customer_id = ?
                                ORDER BY n.created_at DESC");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $customerNotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        // Customer not found, redirect to list
        header("Location: customers.php");
        exit;
    }
} else {
    // We're showing the customer list view
    // Build the query based on filters
    $whereClause = [];
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $searchTerm = "%{$search}%";
        $whereClause[] = "(c.fullname LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ssss";
    }
    
    switch ($filterType) {
        case 'recent_orders':
            // Customers with orders in the last 30 days
            $whereClause[] = "c.id IN (SELECT DISTINCT customer_id FROM orders WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
            break;
        case 'today_orders':
            // Customers with orders today
            $whereClause[] = "c.id IN (SELECT DISTINCT customer_id FROM orders WHERE DATE(order_date) = CURDATE())";
            break;
        case 'most_orders':
            // Sort will be by order count
            $sort = 'most_orders';
            break;
        case 'with_debt':
            // Customers with debt
            $whereClause[] = "c.id IN (SELECT DISTINCT customer_id FROM orders WHERE remaining_amount > 0)";
            break;
        case 'high_debt':
            // Customers with high debt (sort by debt amount)
            $whereClause[] = "c.id IN (SELECT DISTINCT customer_id FROM orders WHERE remaining_amount > 0)";
            $sort = 'high_debt';
            break;
        case 'inactive':
            // Customers with no orders in the last 90 days
            $whereClause[] = "c.id NOT IN (SELECT DISTINCT customer_id FROM orders WHERE order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY))";
            break;
    }
    
    $whereClauseStr = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    // Set order by clause based on sort parameter
    $orderByClause = "ORDER BY c.created_at DESC"; // Default (newest)
    $joinClause = "";
    $groupByClause = "";
    $selectAdditional = "";
    
    switch ($sort) {
        case 'oldest':
            $orderByClause = "ORDER BY c.created_at ASC";
            break;
        case 'name_asc':
            $orderByClause = "ORDER BY c.fullname ASC";
            break;
        case 'name_desc':
            $orderByClause = "ORDER BY c.fullname DESC";
            break;
        case 'most_orders':
            $joinClause = "LEFT JOIN orders o ON c.id = o.customer_id";
            $groupByClause = "GROUP BY c.id";
            $selectAdditional = ", COUNT(o.id) as order_count";
            $orderByClause = "ORDER BY order_count DESC";
            break;
        case 'high_debt':
            $joinClause = "LEFT JOIN orders o ON c.id = o.customer_id";
            $groupByClause = "GROUP BY c.id";
            $selectAdditional = ", SUM(o.remaining_amount) as total_debt";
            $orderByClause = "ORDER BY total_debt DESC";
            break;
    }
    
    // Get total count
    $sqlCount = "SELECT COUNT(DISTINCT c.id) as total FROM customers c $joinClause $whereClauseStr";
    $stmtCount = $conn->prepare($sqlCount);
    
    if (!empty($params)) {
        $stmtCount->bind_param($types, ...$params);
    }
    
    $stmtCount->execute();
    $totalCustomers = $stmtCount->get_result()->fetch_assoc()['total'];
    
    // Calculate total pages
    $totalPages = ceil($totalCustomers / $perPage);
    
    // Get customers with statistics
    $sql = "SELECT c.*, 
           u.status as account_status,
           (SELECT COUNT(*) FROM orders WHERE customer_id = c.id) as order_count,
           (SELECT SUM(total_amount) FROM orders WHERE customer_id = c.id) as total_spent,
           (SELECT SUM(remaining_amount) FROM orders WHERE customer_id = c.id) as remaining_debt,
           (SELECT MAX(order_date) FROM orders WHERE customer_id = c.id) as last_order_date
           $selectAdditional
           FROM customers c
           LEFT JOIN users u ON c.user_id = u.id
           $joinClause
           $whereClauseStr
           $groupByClause
           $orderByClause
           LIMIT ?, ?";
    
    $params[] = $offset;
    $params[] = $perPage;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get system statistics
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM customers");
    $stmt->execute();
    $customerCount = $stmt->get_result()->fetch_assoc()['total'];
    
    $stmt = $conn->prepare("SELECT SUM(remaining_amount) as total_debt FROM orders");
    $stmt->execute();
    $totalDebt = $stmt->get_result()->fetch_assoc()['total_debt'] ?? 0;
    
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as count FROM orders WHERE remaining_amount > 0");
    $stmt->execute();
    $customersWithDebt = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as count FROM orders WHERE DATE(order_date) = CURDATE()");
    $stmt->execute();
    $customersToday = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
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

/**
 * Transliterate Azerbaijani text to Latin for username generation
 * @param string $text Text to transliterate
 * @return string Transliterated text
 */

?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müştərilər | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        /* Filter styles */
        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .filter-tab {
            padding: 8px 15px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            cursor: pointer;
            text-decoration: none;
            color: #4b5563;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .filter-tab:hover {
            background: #f3f4f6;
        }
        
        .filter-tab.active {
            background: var(--primary-color);
            color: white;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
        }
        
        /* Customer List Horizontal Layout */
        .customers-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .customer-list-item {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: row;
            align-items: center;
            padding: 20px;
            margin-bottom: 15px;
            transition: transform 0.2s;
            position: relative;
            gap: 20px;
        }
        
        .customer-list-item:hover {
            transform: translateY(-3px);
        }
        
        .customer-avatar {
            width: 60px;
            height: 60px;
            min-width: 60px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 24px;
        }
        
        .customer-list-info {
            flex: 1;
            display: flex;
            flex-direction: row;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .customer-list-main {
            flex: 1;
            min-width: 200px;
        }
        
        .customer-list-name {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .customer-list-contact {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .customer-list-detail {
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .customer-list-stats {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .customer-list-stat {
            text-align: center;
            min-width: 90px;
        }
        
        .customer-list-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .customer-list-stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .customer-list-actions {
            display: flex;
            gap: 8px;
        }
        
        .list-action-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f3f4f6;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .list-action-btn:hover {
            background: #e5e7eb;
        }
        
        .list-action-btn.btn-view {
            background: var(--primary-gradient);
            color: white;
        }
        
        .list-action-btn.btn-whatsapp {
            background: #25d366;
            color: white;
        }
        
        .debt-badge {
            background: #ef4444;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        /* Customer detail view */
        .customer-detail-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .customer-detail-avatar {
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
        
        .customer-detail-name {
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
            flex-wrap: wrap;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .detail-tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        
        .detail-tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            color: #6b7280;
            border-bottom: 3px solid transparent;
        }
        
        .detail-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .detail-content {
            margin-bottom: 30px;
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
        
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-new { background: #e0f2fe; color: #0369a1; }
        .status-processing { background: #fef3c7; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-delivered { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #b91c1c; }
        
        .has-debt {
            color: #b91c1c;
        }
        
        .no-debt {
            color: #065f46;
        }
        
        /* Customer form styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .customer-tab-content {
            display: none;
        }
        
        .customer-tab-content.active {
            display: block;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1000;
        }
        
        .modal.show {
            display: block;
        }
        
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1001;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            margin: 50px auto;
            max-width: 500px;
            position: relative;
            z-index: 1002;
        }
        
        .modal-header {
            padding: 15px 20px;
            background: linear-gradient(to right, #1e5eb1, #1eb15a);
            color: white;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 18px;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-detail-header {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-meta {
                justify-content: center;
            }
            
            .customer-actions {
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-list-item {
                flex-direction: column;
                align-items: flex-start;
                padding: 15px;
            }
            
            .customer-list-info {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }
            
            .customer-list-stats {
                width: 100%;
                justify-content: space-around;
            }
            
            .customer-list-actions {
                width: 100%;
                justify-content: flex-end;
                margin-top: 15px;
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
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php" class="active"><i class="fas fa-user-tie"></i> Müştərilər</a>
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
            <?php if ($customerId === 0): // Show list view ?>
            <div class="page-header">
                <h1><i class="fas fa-user-tie"></i> Müştərilər</h1>
                <div>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#addCustomerModal">
                        <i class="fas fa-plus"></i> Yeni Müştəri
                    </button>
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
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($customerCount) ?></div>
                    <div class="stat-label">Ümumi Müştərilər</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($customersWithDebt) ?></div>
                    <div class="stat-label">Borcu Olan Müştərilər</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= formatMoney($totalDebt, '') ?></div>
                    <div class="stat-label">Ümumi Borc</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($customersToday) ?></div>
                    <div class="stat-label">Bu Gün Sifariş Verənlər</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="filter-tabs">
                        <a href="?filter=all" class="filter-tab <?= $filterType === 'all' ? 'active' : '' ?>">
                            <i class="fas fa-users"></i> Bütün Müştərilər
                        </a>
                        <a href="?filter=today_orders" class="filter-tab <?= $filterType === 'today_orders' ? 'active' : '' ?>">
                            <i class="fas fa-calendar-day"></i> Bu Gün Sifariş Verənlər
                        </a>
                        <a href="?filter=recent_orders" class="filter-tab <?= $filterType === 'recent_orders' ? 'active' : '' ?>">
                            <i class="fas fa-clock"></i> Son 30 Gün Sifariş Verənlər
                        </a>
                        <a href="?filter=most_orders" class="filter-tab <?= $filterType === 'most_orders' ? 'active' : '' ?>">
                            <i class="fas fa-crown"></i> Ən Çox Sifariş Verənlər
                        </a>
                        <a href="?filter=with_debt" class="filter-tab <?= $filterType === 'with_debt' ? 'active' : '' ?>">
                            <i class="fas fa-hand-holding-usd"></i> Borcu Olanlar
                        </a>
                        <a href="?filter=high_debt" class="filter-tab <?= $filterType === 'high_debt' ? 'active' : '' ?>">
                            <i class="fas fa-exclamation-triangle"></i> Yüksək Borclu
                        </a>
                        <a href="?filter=inactive" class="filter-tab <?= $filterType === 'inactive' ? 'active' : '' ?>">
                            <i class="fas fa-user-clock"></i> Qeyri-Aktiv Müştərilər
                        </a>
                    </div>
                    
                    <form action="" method="get" class="search-form">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filterType) ?>">
                        <input type="text" name="search" class="search-input" placeholder="Ad, Email, Telefon və ya Şirkət Adı ilə axtarış..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Axtar
                        </button>
                    </form>
                    
                    <div class="filter-container">
                        <div>
                            <label>Sıralama:</label>
                            <select name="sort" onchange="window.location.href=this.value" class="form-control">
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Ən Yeni</option>
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Ən Köhnə</option>
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Ad (A-Z)</option>
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Ad (Z-A)</option>
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=most_orders" <?= $sort === 'most_orders' ? 'selected' : '' ?>>Ən Çox Sifariş</option>
                                <option value="?filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=high_debt" <?= $sort === 'high_debt' ? 'selected' : '' ?>>Ən Yüksək Borc</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Customer Horizontal List View -->
            <?php if (empty($customers)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> Seçilmiş filtrlərə uyğun müştəri tapılmadı.
                </div>
            <?php else: ?>
                <div class="customers-list">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-list-item">
                            <div class="customer-avatar">
                                <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
                            </div>
                            
                            <div class="customer-list-info">
                                <div class="customer-list-main">
                                    <div class="customer-list-name">
                                        <?= htmlspecialchars($customer['fullname']) ?>
                                        <?php if (($customer['remaining_debt'] ?? 0) > 0): ?>
                                            <span class="debt-badge">
                                                <?= formatMoney($customer['remaining_debt']) ?> borc
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="customer-list-contact">
                                        <div class="customer-list-detail">
                                            <i class="fas fa-phone text-primary"></i>
                                            <?= htmlspecialchars($customer['phone']) ?>
                                        </div>
                                        
                                        <?php if (!empty($customer['email'])): ?>
                                        <div class="customer-list-detail">
                                            <i class="fas fa-envelope text-primary"></i>
                                            <?= htmlspecialchars($customer['email']) ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($customer['address'])): ?>
                                        <div class="customer-list-detail">
                                            <i class="fas fa-map-marker-alt text-primary"></i>
                                            <?= htmlspecialchars($customer['address']) ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($customer['account_status'])): ?>
                                        <div class="customer-list-detail">
                                            <i class="fas fa-user-circle text-primary"></i>
                                            <span class="status-pill status-<?= $customer['account_status'] ?>">
                                                <?php
                                                switch ($customer['account_status']) {
                                                    case 'active': echo 'Aktiv hesab'; break;
                                                    case 'inactive': echo 'Deaktiv hesab'; break;
                                                    case 'suspended': echo 'Dayandırılmış hesab'; break;
                                                    default: echo ucfirst($customer['account_status']); break;
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="customer-list-stats">
                                    <div class="customer-list-stat">
                                        <div class="customer-list-stat-value"><?= $customer['order_count'] ?? 0 ?></div>
                                        <div class="customer-list-stat-label">Sifariş</div>
                                    </div>
                                    
                                    <div class="customer-list-stat">
                                        <div class="customer-list-stat-value"><?= formatMoney($customer['total_spent'] ?? 0, '') ?></div>
                                        <div class="customer-list-stat-label">Ümumi alış</div>
                                    </div>
                                    
                                    <div class="customer-list-stat">
                                        <div class="customer-list-stat-value"><?= formatDate($customer['created_at'], 'd.m.Y') ?></div>
                                        <div class="customer-list-stat-label">Qeydiyyat tarixi</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="customer-list-actions">
                                <a href="?id=<?= $customer['id'] ?>" class="list-action-btn btn-view" title="Ətraflı bax">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="orders.php?customer_id=<?= $customer['id'] ?>" class="list-action-btn" title="Sifarişləri göstər">
                                    <i class="fas fa-clipboard-list"></i>
                                </a>
                                <a href="hesabla.php?customer_id=<?= $customer['id'] ?>" class="list-action-btn" title="Yeni Sifariş">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php if (!empty($customer['phone']) && ($customer['remaining_debt'] ?? 0) > 0): ?>
                                    <a href="send-debt-reminder.php?id=<?= $customer['id'] ?>" class="list-action-btn btn-whatsapp" title="Borc xatırlat">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $customer['phone']) ?>" target="_blank" class="list-action-btn btn-whatsapp" title="WhatsApp">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                                                        <a href="?page=1&filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?= $page - 1 ?>&filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                <i class="fas fa-angle-left"></i>
                            </a>
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
                            <a href="?page=<?= $i ?>&filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?= $totalPages ?>&filter=<?= $filterType ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php else: // Show customer detail view ?>
            
            <div class="page-header">
                <h1><i class="fas fa-user-tie"></i> Müştəri Məlumatları</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <span>Müştəri Məlumatları</span>
                </div>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success) || isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <?php 
                        if (!empty($success)) {
                            echo htmlspecialchars($success);
                        } elseif ($_GET['success'] === 'created') {
                            echo 'Müştəri uğurla əlavə edildi';
                        } elseif ($_GET['success'] === 'updated') {
                            echo 'Müştəri məlumatları uğurla yeniləndi';
                        } elseif ($_GET['success'] === 'password_reset') {
                            echo 'Müştəri şifrəsi uğurla sıfırlandı';
                        } elseif ($_GET['success'] === 'account_activated') {
                            echo 'Müştəri hesabı aktiv edildi';
                        } elseif ($_GET['success'] === 'account_deactivated') {
                            echo 'Müştəri hesabı deaktiv edildi';
                        } elseif (isset($_GET['note_added']) && $_GET['note_added'] == 1) {
                            echo 'Qeyd uğurla əlavə edildi';
                        }
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- Customer Header -->
            <div class="customer-detail-header">
                <div class="customer-detail-avatar">
                    <?= strtoupper(substr($selectedCustomer['fullname'], 0, 1)) ?>
                </div>
                <div class="customer-info">
                    <div class="customer-detail-name"><?= htmlspecialchars($selectedCustomer['fullname']) ?></div>
                    <div class="customer-meta">
                        <div class="meta-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($selectedCustomer['phone']) ?></span>
                        </div>
                        <?php if (!empty($selectedCustomer['email'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-envelope"></i>
                                <span><?= htmlspecialchars($selectedCustomer['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($selectedCustomer['address'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($selectedCustomer['address']) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span>Qeydiyyat: <?= formatDate($selectedCustomer['created_at']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($selectedCustomer['account_status'])): ?>
                        <div>
                            <span class="account-status status-<?= $selectedCustomer['account_status'] ?>">
                                <?php
                                    switch ($selectedCustomer['account_status']) {
                                        case 'active':
                                            echo 'Aktiv hesab';
                                            break;
                                        case 'inactive':
                                            echo 'Deaktiv hesab';
                                            break;
                                        case 'suspended':
                                            echo 'Dayandırılmış hesab';
                                            break;
                                        default:
                                            echo ucfirst($selectedCustomer['account_status']);
                                    }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="customer-actions">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#editCustomerModal">
                            <i class="fas fa-edit"></i> Düzəliş et
                        </button>
                        <a href="orders.php?customer_id=<?= $selectedCustomer['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-clipboard-list"></i> Sifarişlər
                        </a>
                        <a href="hesabla.php?customer_id=<?= $selectedCustomer['id'] ?>" class="btn btn-success">
                            <i class="fas fa-plus"></i> Yeni Sifariş
                        </a>
                        <?php if (!empty($selectedCustomer['user_id'])): ?>
                            <?php if ($selectedCustomer['account_status'] === 'active'): ?>
                                <button class="btn btn-warning" data-toggle="modal" data-target="#deactivateAccountModal">
                                    <i class="fas fa-user-slash"></i> Hesabı deaktiv et
                                </button>
                            <?php else: ?>
                                <button class="btn btn-info" data-toggle="modal" data-target="#activateAccountModal">
                                    <i class="fas fa-user-check"></i> Hesabı aktiv et
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-outline" data-toggle="modal" data-target="#resetPasswordModal">
                                <i class="fas fa-key"></i> Şifrəni sıfırla
                            </button>
                        <?php endif; ?>
                        <?php if (($customerStats['outstanding_balance'] ?? 0) > 0): ?>
                            <a href="send-debt-reminder.php?id=<?= $selectedCustomer['id'] ?>" class="btn btn-whatsapp">
                                <i class="fab fa-whatsapp"></i> Borc xatırlat
                            </a>
                        <?php endif; ?>
                        <button class="btn btn-danger" data-toggle="modal" data-target="#deleteCustomerModal">
                            <i class="fas fa-trash"></i> Sil
                        </button>
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
                
                <div class="stat-card <?= ($customerStats['outstanding_balance'] ?? 0) > 0 ? 'has-debt' : 'no-debt' ?>">
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
                    <!-- Tabs navigation -->
                    <div class="detail-tabs">
                        <div class="detail-tab active" data-tab="orders">
                            <i class="fas fa-clipboard-list"></i> Sifarişlər
                        </div>
                        <div class="detail-tab" data-tab="notes">
                            <i class="fas fa-sticky-note"></i> Qeydlər
                        </div>
                    </div>
                    
                    <!-- Orders Tab -->
                    <div class="customer-tab-content active" id="orders-tab">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h2 class="card-title">Son Sifarişlər</h2>
                                <div class="card-actions">
                                    <a href="orders.php?customer_id=<?= $selectedCustomer['id'] ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-list"></i> Bütün sifarişlər
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($customerOrders)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Bu müştərinin hələ heç bir sifarişi yoxdur.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Sifariş №</th>
                                                    <th>Tarix</th>
                                                    <th>Filial</th>
                                                    <th>Satıcı</th>
                                                    <th>Məbləğ</th>
                                                    <th>Qalıq</th>
                                                    <th>Status</th>
                                                    <th>Əməliyyatlar</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($customerOrders as $order): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($order['order_number']) ?></td>
                                                        <td><?= formatDate($order['order_date']) ?></td>
                                                        <td><?= htmlspecialchars($order['branch_name'] ?? '-') ?></td>
                                                        <td><?= htmlspecialchars($order['seller_name'] ?? '-') ?></td>
                                                        <td><?= formatMoney($order['total_amount']) ?></td>
                                                        <td>
                                                            <?php if ($order['remaining_amount'] > 0): ?>
                                                                <span class="has-debt"><?= formatMoney($order['remaining_amount']) ?></span>
                                                            <?php else: ?>
                                                                <span class="no-debt">0.00 ₼</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-pill status-<?= $order['order_status'] ?>">
                                                                <?php
                                                                switch ($order['order_status']) {
                                                                    case 'new': echo 'Yeni'; break;
                                                                    case 'processing': echo 'Hazırlanır'; break;
                                                                    case 'completed': echo 'Hazır'; break;
                                                                    case 'delivered': echo 'Təhvil verilib'; break;
                                                                    case 'cancelled': echo 'Ləğv edilib'; break;
                                                                    default: echo ucfirst($order['order_status']); break;
                                                                }
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="order-details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes Tab -->
                    <div class="customer-tab-content" id="notes-tab">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h2 class="card-title">Müştəri Qeydləri</h2>
                            </div>
                            <div class="card-body">
                                <form method="post" action="" class="mb-4">
                                    <input type="hidden" name="action" value="add_note">
                                    <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
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
                                        <div class="alert alert-info">
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
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Customer Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-info-circle"></i> Müştəri Məlumatları</h2>
                        </div>
                        <div class="card-body">
                            <div class="info-list">
                                <div class="info-label">Müştəri ID:</div>
                                <div class="info-value"><?= $selectedCustomer['id'] ?></div>
                                
                                <div class="info-label">Ad Soyad:</div>
                                <div class="info-value"><?= htmlspecialchars($selectedCustomer['fullname']) ?></div>
                                
                                <div class="info-label">Telefon:</div>
                                <div class="info-value"><?= htmlspecialchars($selectedCustomer['phone']) ?></div>
                                
                                <?php if (!empty($selectedCustomer['email'])): ?>
                                    <div class="info-label">E-poçt:</div>
                                    <div class="info-value"><?= htmlspecialchars($selectedCustomer['email']) ?></div>
                                <?php endif; ?>
                                
                                <?php if (!empty($selectedCustomer['address'])): ?>
                                    <div class="info-label">Ünvan:</div>
                                    <div class="info-value"><?= htmlspecialchars($selectedCustomer['address']) ?></div>
                                <?php endif; ?>
                                
                                <div class="info-label">Qeydiyyat tarixi:</div>
                                <div class="info-value"><?= formatDate($selectedCustomer['created_at']) ?></div>
                                
                                <?php if (!empty($selectedCustomer['last_login'])): ?>
                                    <div class="info-label">Son giriş:</div>
                                    <div class="info-value"><?= formatDate($selectedCustomer['last_login']) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($selectedCustomer['notes'])): ?>
                                <div class="mt-4">
                                    <div class="info-label">Qeydlər:</div>
                                    <div class="info-value"><?= nl2br(htmlspecialchars($selectedCustomer['notes'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (($customerStats['outstanding_balance'] ?? 0) > 0): ?>
                    <!-- Payment Information -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Ödəniş Məlumatları</h2>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> Bu müştərinin <strong><?= formatMoney($customerStats['outstanding_balance']) ?></strong> məbləğində borcu var.
                            </div>
                            
                            <?php
                            // Get orders with outstanding balance
                            $sql = "SELECT id, order_number, order_date, total_amount, advance_payment, remaining_amount 
                                    FROM orders 
                                    WHERE customer_id = ? AND remaining_amount > 0
                                    ORDER BY order_date DESC";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("i", $selectedCustomer['id']);
                            $stmt->execute();
                            $outstandingOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            ?>
                            
                            <?php if (!empty($outstandingOrders)): ?>
                                <h3 class="mt-3 mb-3">Ödənilməmiş Sifarişlər</h3>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Sifariş №</th>
                                                <th>Tarix</th>
                                                <th>Ümumi məbləğ</th>
                                                <th>Ödənilib</th>
                                                <th>Qalıq borc</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($outstandingOrders as $order): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($order['order_number']) ?></td>
                                                    <td><?= formatDate($order['order_date']) ?></td>
                                                    <td><?= formatMoney($order['total_amount']) ?></td>
                                                    <td><?= formatMoney($order['advance_payment']) ?></td>
                                                    <td class="has-debt"><?= formatMoney($order['remaining_amount']) ?></td>
                                                    <td>
                                                        <a href="order-details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Edit Customer Modal -->
            <div class="modal" id="editCustomerModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Müştəri Düzəliş</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="edit_customer">
                            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="fullname" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" id="fullname" name="fullname" class="form-control" value="<?= htmlspecialchars($selectedCustomer['fullname']) ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="phone" class="form-label">Telefon <span class="text-danger">*</span></label>
                                    <input type="tel" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($selectedCustomer['phone']) ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email" class="form-label">E-poçt</label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($selectedCustomer['email'] ?? '') ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="company" class="form-label">Şirkət</label>
                                    <input type="text" id="company" name="company" class="form-control" value="">
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="address" class="form-label">Ünvan</label>
                                    <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($selectedCustomer['address'] ?? '') ?>">
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="notes" class="form-label">Qeydlər</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3"><?= htmlspecialchars($selectedCustomer['notes'] ?? '') ?></textarea>
                                </div>
                                
                                <?php if (empty($selectedCustomer['user_id'])): ?>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <div class="form-check">
                                        <input type="checkbox" id="create_account" name="create_account" class="form-check-input" value="1">
                                        <label for="create_account" class="form-check-label">Müştəri üçün hesab yarat</label>
                                        <div class="form-text">Avtomatik şifrə yaradılacaq və müştəriyə göndəriləcək</div>
                                    </div>
                                </div>
                                <?php endif; ?>
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
            <div class="modal" id="resetPasswordModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Şifrə Sıfırlama</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                            
                            <p>Bu müştərinin şifrəsi sıfırlanacaq və yeni şifrə yaradılacaq.</p>
                            <p><strong><?= htmlspecialchars($selectedCustomer['fullname']) ?></strong></p>
                            <p>Yeni şifrə müştəriyə WhatsApp vasitəsilə göndəriləcək.</p>
                            <p>Davam etmək istəyirsiniz?</p>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-warning">Şifrəni Sıfırla</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Deactivate Account Modal -->
            <div class="modal" id="deactivateAccountModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hesabı Deaktiv Et</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="deactivate_account">
                            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                            
                            <p>Bu müştərinin hesabını deaktiv etmək istəyirsiniz?</p>
                            <p><strong><?= htmlspecialchars($selectedCustomer['fullname']) ?></strong></p>
                            <p>Hesab deaktiv edildikdən sonra müştəri sistemə daxil ola bilməyəcək.</p>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-warning">Deaktiv Et</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Activate Account Modal -->
            <div class="modal" id="activateAccountModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hesabı Aktiv Et</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="activate_account">
                            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                            
                            <p>Bu müştərinin hesabını aktiv etmək istəyirsiniz?</p>
                            <p><strong><?= htmlspecialchars($selectedCustomer['fullname']) ?></strong></p>
                            <p>Hesab aktiv edildikdən sonra müştəri sistemə daxil ola biləcək.</p>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-success">Aktiv Et</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Delete Customer Modal -->
            <div class="modal" id="deleteCustomerModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Müştəri Silmə</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="delete_customer">
                            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">
                            
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Diqqət!</strong> Bu əməliyyat geri qaytarıla bilməz!
                            </div>
                            
                            <p>Bu müştərini silmək istədiyinizə əminsiniz?</p>
                            <p><strong><?= htmlspecialchars($selectedCustomer['fullname']) ?></strong></p>
                            
                            <?php if (($customerStats['total_orders'] ?? 0) > 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-circle"></i> Bu müştərinin <?= $customerStats['total_orders'] ?> sifarişi var. Silmədən əvvəl sifarişləri başqa müştərilərə köçürün və ya silin.
                                </div>
                            <?php endif; ?>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-danger" <?= ($customerStats['total_orders'] ?? 0) > 0 ? 'disabled' : '' ?>>Sil</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
            
            <!-- Add Customer Modal (Available on all views) -->
            <div class="modal" id="addCustomerModal">
                <div class="modal-backdrop" data-dismiss="modal"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Yeni Müştəri</h5>
                        <button type="button" class="modal-close" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="add_customer">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="add_fullname" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" id="add_fullname" name="fullname" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="add_phone" class="form-label">Telefon <span class="text-danger">*</span></label>
                                    <input type="tel" id="add_phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="add_email" class="form-label">E-poçt</label>
                                    <input type="email" id="add_email" name="email" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="add_company" class="form-label">Şirkət</label>
                                    <input type="text" id="add_company" name="company" class="form-control">
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="add_address" class="form-label">Ünvan</label>
                                    <input type="text" id="add_address" name="address" class="form-control">
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="add_notes" class="form-label">Qeydlər</label>
                                    <textarea id="add_notes" name="notes" class="form-control" rows="3"></textarea>
                                </div>
                                
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <div class="form-check">
                                        <input type="checkbox" id="add_create_account" name="create_account" class="form-check-input" value="1">
                                        <label for="add_create_account" class="form-check-label">Müştəri üçün hesab yarat</label>
                                        <div class="form-text">Avtomatik şifrə yaradılacaq və müştəriyə göndəriləcək</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-primary">Müştəri Əlavə Et</button>
                            </div>
                        </form>
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
                    this.closest('.modal').classList.remove('show');
                });
            });
            
            // Tab switching
            const tabs = document.querySelectorAll('.detail-tab');
            const tabContents = document.querySelectorAll('.customer-tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabName = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs and hide all contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to clicked tab and show corresponding content
                    this.classList.add('active');
                    document.getElementById(tabName + '-tab').classList.add('active');
                });
            });
            
            // Handle phone number formatting
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    
                    if (value.length > 0) {
                        if (value.length <= 3) {
                            value = '+' + value;
                        } else if (value.length <= 5) {
                            value = '+' + value.substring(0, 3) + ' ' + value.substring(3);
                        } else if (value.length <= 8) {
                            value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5);
                        } else if (value.length <= 10) {
                            value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8);
                        } else {
                            value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8, 10) + ' ' + value.substring(10);
                        }
                    }
                    
                    e.target.value = value;
                });
            });
            
            // Email requirement for account creation
            const createAccountCheckboxes = document.querySelectorAll('#create_account, #add_create_account');
            createAccountCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const emailField = this.id === 'create_account' ? 
                                      document.getElementById('email') : 
                                      document.getElementById('add_email');
                    
                    if (this.checked) {
                        emailField.setAttribute('required', 'required');
                        emailField.parentElement.querySelector('label').innerHTML = 'E-poçt <span class="text-danger">*</span>';
                    } else {
                        emailField.removeAttribute('required');
                        emailField.parentElement.querySelector('label').innerHTML = 'E-poçt';
                    }
                });
            });
        });
    </script>
</body>
</html>