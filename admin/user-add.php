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

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    
    $errors = [];
    
    // Validate required fields
    if (empty($fullname)) {
        $errors[] = 'Ad Soyad daxil edilməlidir';
    }
    
    if (empty($email)) {
        $errors[] = 'E-poçt daxil edilməlidir';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Düzgün e-poçt daxil edilməlidir';
    }
    
    if (empty($role)) {
        $errors[] = 'Rol seçilməlidir';
    } elseif (!in_array($role, ['admin', 'seller', 'production', 'customer'])) {
        $errors[] = 'Yanlış rol seçimi';
    }
    
    // Branch ID is required for sellers and production roles
    if (($role === 'seller' || $role === 'production') && empty($branch_id)) {
        $errors[] = 'Satıcı və istehsalat işçiləri üçün filial seçilməlidir';
    }
    
    // Phone is required for customers
    if ($role === 'customer' && empty($phone)) {
        $errors[] = 'Müştərilər üçün telefon nömrəsi daxil edilməlidir';
    }
    
    // Validate password
    if (empty($password)) {
        $errors[] = 'Şifrə daxil edilməlidir';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Şifrə ən az 6 simvol olmalıdır';
    } elseif ($password !== $confirm_password) {
        $errors[] = 'Şifrələr uyğun gəlmir';
    }
    
    // Check if email already exists
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = 'Bu e-poçt artıq istifadə olunur';
    }
    
    // Generate username from email
    $username = explode('@', $email)[0];
    
    // Check if username exists and generate a unique one
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $username = $username . rand(100, 999);
    }
    
    if (empty($errors)) {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Create new user - without created_by field
        if ($branch_id) {
            // If branch_id is provided
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, branch_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->bind_param("sssssi", $fullname, $email, $username, $hashedPassword, $role, $branch_id);
        } else {
            // If branch_id is NULL
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->bind_param("sssss", $fullname, $email, $username, $hashedPassword, $role);
        }
        
        if ($stmt->execute()) {
            $newUserId = $conn->insert_id;
            
            // If user is a customer, create customer record
            if ($role === 'customer') {
                $stmt = $conn->prepare("INSERT INTO customers (user_id, fullname, email, phone, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("isss", $newUserId, $fullname, $email, $phone);
                $stmt->execute();
            }
            
            // Redirect with success message
            $_SESSION['success_message'] = 'İstifadəçi uğurla əlavə edildi';
            header('Location: users.php');
            exit;
        } else {
            $errors[] = 'İstifadəçi əlavə edərkən xəta baş verdi: ' . $conn->error;
        }
    }
    
    // If we reach here, there were errors
    $_SESSION['error_message'] = implode('<br>', $errors);
    $_SESSION['form_data'] = $_POST; // Save form data for repopulation
    header('Location: users.php');
    exit;
}

// If not a POST request, redirect back to users page
header('Location: users.php');
exit;