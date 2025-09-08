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

// Check if warehouse tables exist
$warehouseCategoriesExist = tableExists($conn, 'warehouse_categories');
$glassInventoryExists = tableExists($conn, 'glass_inventory');
$profileInventoryExists = tableExists($conn, 'profile_inventory');
$accessoriesInventoryExists = tableExists($conn, 'accessories_inventory');

// Get filter parameters
$category = $_GET['category'] ?? 'all';
$branch = isset($_GET['branch']) && is_numeric($_GET['branch']) ? (int)$_GET['branch'] : 0;
$search = $_GET['search'] ?? '';
$stockStatus = $_GET['stock_status'] ?? 'all';
$sort = $_GET['sort'] ?? 'name_asc';

// Get warehouse categories
$categoriesSql = "SELECT * FROM warehouse_categories WHERE status = 'active' ORDER BY name";
$categories = [];
if ($warehouseCategoriesExist) {
    $categoriesResult = $conn->query($categoriesSql);
    if ($categoriesResult) {
        $categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);
    }
}

// Get branches
$branchesSql = "SELECT * FROM branches WHERE status = 'active' ORDER BY name";
$branches = $conn->query($branchesSql)->fetch_all(MYSQLI_ASSOC);

// Prepare inventory data
$inventory = [];

// Fetch glass inventory
if ($category === 'all' || $category === 'glass') {
    if ($glassInventoryExists) {
        $sql = "SELECT *, 'glass' AS inventory_type FROM glass_inventory";
        $whereClauses = [];
        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $whereClauses[] = "(name LIKE ? OR type LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $types .= "ss";
        }
        
        if ($stockStatus === 'low') {
            $whereClauses[] = "(remaining_volume < purchase_volume * 0.2 AND remaining_volume > 0)";
        } elseif ($stockStatus === 'out') {
            $whereClauses[] = "(remaining_volume <= 0)";
        } elseif ($stockStatus === 'in') {
            $whereClauses[] = "(remaining_volume > 0)";
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        switch ($sort) {
            case 'name_asc':
                $sql .= " ORDER BY name ASC";
                break;
            case 'name_desc':
                $sql .= " ORDER BY name DESC";
                break;
            case 'stock_asc':
                $sql .= " ORDER BY remaining_volume ASC";
                break;
            case 'stock_desc':
                $sql .= " ORDER BY remaining_volume DESC";
                break;
            default:
                $sql .= " ORDER BY name ASC";
        }
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $glassInventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($glassInventory as $item) {
            $inventory[] = $item;
        }
    }
}

// Fetch profile inventory
if ($category === 'all' || $category === 'profile') {
    if ($profileInventoryExists) {
        $sql = "SELECT *, 'profile' AS inventory_type FROM profile_inventory";
        $whereClauses = [];
        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $whereClauses[] = "(name LIKE ? OR type LIKE ? OR color LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $types .= "sss";
        }
        
        if ($stockStatus === 'low') {
            $whereClauses[] = "(remaining_quantity < purchase_quantity * 0.2 AND remaining_quantity > 0)";
        } elseif ($stockStatus === 'out') {
            $whereClauses[] = "(remaining_quantity <= 0)";
        } elseif ($stockStatus === 'in') {
            $whereClauses[] = "(remaining_quantity > 0)";
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        switch ($sort) {
            case 'name_asc':
                $sql .= " ORDER BY name ASC";
                break;
            case 'name_desc':
                $sql .= " ORDER BY name DESC";
                break;
            case 'stock_asc':
                $sql .= " ORDER BY remaining_quantity ASC";
                break;
            case 'stock_desc':
                $sql .= " ORDER BY remaining_quantity DESC";
                break;
            default:
                $sql .= " ORDER BY name ASC";
        }
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $profileInventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($profileInventory as $item) {
            $inventory[] = $item;
        }
    }
}

// Fetch accessories inventory
if ($category === 'all' || $category === 'accessory') {
    if ($accessoriesInventoryExists) {
        $sql = "SELECT *, 'accessory' AS inventory_type FROM accessories_inventory";
        $whereClauses = [];
        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $whereClauses[] = "(name LIKE ?)";
            $params[] = "%$search%";
            $types .= "s";
        }
        
        if ($stockStatus === 'low') {
            $whereClauses[] = "(remaining_quantity < purchase_quantity * 0.2 AND remaining_quantity > 0)";
        } elseif ($stockStatus === 'out') {
            $whereClauses[] = "(remaining_quantity <= 0)";
        } elseif ($stockStatus === 'in') {
            $whereClauses[] = "(remaining_quantity > 0)";
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        switch ($sort) {
            case 'name_asc':
                $sql .= " ORDER BY name ASC";
                break;
            case 'name_desc':
                $sql .= " ORDER BY name DESC";
                break;
            case 'stock_asc':
                $sql .= " ORDER BY remaining_quantity ASC";
                break;
            case 'stock_desc':
                $sql .= " ORDER BY remaining_quantity DESC";
                break;
            default:
                $sql .= " ORDER BY name ASC";
        }
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $accessoriesInventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($accessoriesInventory as $item) {
            $inventory[] = $item;
        }
    }
}

// Process inventory operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new item
    if (isset($_POST['add_item'])) {
        $itemType = $_POST['item_type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        
        if (empty($name)) {
            $message = 'Məhsul adı boş ola bilməz';
            $messageType = 'error';
        } else {
            switch ($itemType) {
                case 'glass':
                    if ($glassInventoryExists) {
                        $thickness = floatval($_POST['thickness'] ?? 0);
                        $type = trim($_POST['glass_type'] ?? '');
                        // Handle custom glass type
                        if ($type === 'custom' && !empty($_POST['custom_glass_type'])) {
                            $type = trim($_POST['custom_glass_type']);
                        }
                        $dimensions = trim($_POST['dimensions'] ?? '');
                        $purchaseVolume = floatval($_POST['purchase_volume'] ?? 0);
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "INSERT INTO glass_inventory (name, thickness, type, dimensions, purchase_volume, purchase_price, remaining_volume, notes, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sdssddsis", $name, $thickness, $type, $dimensions, $purchaseVolume, $purchasePrice, $purchaseVolume, $notes, $userId);
                        
                        if ($stmt->execute()) {
                            $message = 'Şüşə məhsulu uğurla əlavə edildi';
                            $messageType = 'success';
                            logActivity($userId, 'add_inventory', "Şüşə məhsulu əlavə edildi: $name");
                        } else {
                            $message = 'Məhsul əlavə edərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Şüşə inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'profile':
                    if ($profileInventoryExists) {
                        $color = trim($_POST['color'] ?? '');
                        $type = trim($_POST['profile_type'] ?? '');
                        // Handle custom profile type
                        if ($type === 'custom' && !empty($_POST['custom_profile_type'])) {
                            $type = trim($_POST['custom_profile_type']);
                        }
                        $unitOfMeasure = $_POST['unit_of_measure'] ?? 'ədəd';
                        $country = trim($_POST['country'] ?? '');
                        $purchaseQuantity = floatval($_POST['purchase_quantity'] ?? 0);
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $salesPrice = floatval($_POST['sales_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "INSERT INTO profile_inventory (name, color, type, unit_of_measure, country, purchase_quantity, purchase_price, sales_price, remaining_quantity, notes, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssssddddsi", $name, $color, $type, $unitOfMeasure, $country, $purchaseQuantity, $purchasePrice, $salesPrice, $purchaseQuantity, $notes, $userId);
                        
                        if ($stmt->execute()) {
                            $message = 'Profil məhsulu uğurla əlavə edildi';
                            $messageType = 'success';
                            logActivity($userId, 'add_inventory', "Profil məhsulu əlavə edildi: $name");
                        } else {
                            $message = 'Məhsul əlavə edərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Profil inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'accessory':
                    if ($accessoriesInventoryExists) {
                        $unitOfMeasure = $_POST['accessory_unit'] ?? 'ədəd';
                        $purchaseQuantity = floatval($_POST['purchase_quantity'] ?? 0);
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "INSERT INTO accessories_inventory (name, unit_of_measure, purchase_quantity, purchase_price, remaining_quantity, notes, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssddsi", $name, $unitOfMeasure, $purchaseQuantity, $purchasePrice, $purchaseQuantity, $notes, $userId);
                        
                        if ($stmt->execute()) {
                            $message = 'Aksesuar məhsulu uğurla əlavə edildi';
                            $messageType = 'success';
                            logActivity($userId, 'add_inventory', "Aksesuar məhsulu əlavə edildi: $name");
                        } else {
                            $message = 'Məhsul əlavə edərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Aksesuar inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                default:
                    $message = 'Yanlış məhsul növü';
                    $messageType = 'error';
            }
        }
    }
    
    // Update stock
    if (isset($_POST['update_stock'])) {
        $itemType = $_POST['item_type'] ?? '';
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $operation = $_POST['operation'] ?? 'add';
        $notes = trim($_POST['stock_notes'] ?? '');
        
        if ($itemId <= 0) {
            $message = 'Məhsul ID-si düzgün deyil';
            $messageType = 'error';
        } elseif ($quantity <= 0) {
            $message = 'Miqdar 0-dan böyük olmalıdır';
            $messageType = 'error';
        } else {
            switch ($itemType) {
                case 'glass':
                    if ($glassInventoryExists) {
                        // Get current stock
                        $sql = "SELECT name, remaining_volume FROM glass_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $currentStock = floatval($result['remaining_volume']);
                            $newStock = $currentStock;
                            
                            if ($operation === 'add') {
                                $newStock += $quantity;
                            } elseif ($operation === 'subtract') {
                                $newStock -= $quantity;
                                if ($newStock < 0) $newStock = 0;
                            } else { // set
                                $newStock = $quantity;
                            }
                            
                            // Update stock
                            $sql = "UPDATE glass_inventory SET remaining_volume = ?, updated_by = ? WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("dii", $newStock, $userId, $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Şüşə məhsulunun stoku uğurla yeniləndi';
                                $messageType = 'success';
                                
                                $operationText = $operation === 'add' ? 'əlavə edildi' : ($operation === 'subtract' ? 'çıxarıldı' : 'yeniləndi');
                                logActivity($userId, 'update_inventory', "Şüşə məhsulunun stoku $operationText: {$result['name']} ($quantity m²). Qeyd: $notes");
                            } else {
                                $message = 'Stoku yeniləyərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Şüşə inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'profile':
                    if ($profileInventoryExists) {
                        // Get current stock
                        $sql = "SELECT name, remaining_quantity FROM profile_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $currentStock = floatval($result['remaining_quantity']);
                            $newStock = $currentStock;
                            
                            if ($operation === 'add') {
                                $newStock += $quantity;
                            } elseif ($operation === 'subtract') {
                                $newStock -= $quantity;
                                if ($newStock < 0) $newStock = 0;
                            } else { // set
                                $newStock = $quantity;
                            }
                            
                            // Update stock
                            $sql = "UPDATE profile_inventory SET remaining_quantity = ?, updated_by = ? WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("dii", $newStock, $userId, $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Profil məhsulunun stoku uğurla yeniləndi';
                                $messageType = 'success';
                                
                                $operationText = $operation === 'add' ? 'əlavə edildi' : ($operation === 'subtract' ? 'çıxarıldı' : 'yeniləndi');
                                logActivity($userId, 'update_inventory', "Profil məhsulunun stoku $operationText: {$result['name']} ($quantity). Qeyd: $notes");
                            } else {
                                $message = 'Stoku yeniləyərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Profil inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'accessory':
                    if ($accessoriesInventoryExists) {
                        // Get current stock
                        $sql = "SELECT name, remaining_quantity FROM accessories_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $currentStock = floatval($result['remaining_quantity']);
                            $newStock = $currentStock;
                            
                            if ($operation === 'add') {
                                $newStock += $quantity;
                            } elseif ($operation === 'subtract') {
                                $newStock -= $quantity;
                                if ($newStock < 0) $newStock = 0;
                            } else { // set
                                $newStock = $quantity;
                            }
                            
                            // Update stock
                            $sql = "UPDATE accessories_inventory SET remaining_quantity = ?, updated_by = ? WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("dii", $newStock, $userId, $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Aksesuar məhsulunun stoku uğurla yeniləndi';
                                $messageType = 'success';
                                
                                $operationText = $operation === 'add' ? 'əlavə edildi' : ($operation === 'subtract' ? 'çıxarıldı' : 'yeniləndi');
                                logActivity($userId, 'update_inventory', "Aksesuar məhsulunun stoku $operationText: {$result['name']} ($quantity). Qeyd: $notes");
                            } else {
                                $message = 'Stoku yeniləyərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Aksesuar inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                default:
                    $message = 'Yanlış məhsul növü';
                    $messageType = 'error';
            }
        }
    }
    
    // Delete item
    if (isset($_POST['delete_item'])) {
        $itemType = $_POST['item_type'] ?? '';
        $itemId = (int)($_POST['item_id'] ?? 0);
        
        if ($itemId <= 0) {
            $message = 'Məhsul ID-si düzgün deyil';
            $messageType = 'error';
        } else {
            switch ($itemType) {
                case 'glass':
                    if ($glassInventoryExists) {
                        // Get item name for log
                        $sql = "SELECT name FROM glass_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $itemName = $result['name'];
                            
                            // Delete item
                            $sql = "DELETE FROM glass_inventory WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("i", $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Şüşə məhsulu uğurla silindi';
                                $messageType = 'success';
                                logActivity($userId, 'delete_inventory', "Şüşə məhsulu silindi: $itemName");
                            } else {
                                $message = 'Məhsul silinərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Şüşə inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'profile':
                    if ($profileInventoryExists) {
                        // Get item name for log
                        $sql = "SELECT name FROM profile_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $itemName = $result['name'];
                            
                            // Delete item
                            $sql = "DELETE FROM profile_inventory WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("i", $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Profil məhsulu uğurla silindi';
                                $messageType = 'success';
                                logActivity($userId, 'delete_inventory', "Profil məhsulu silindi: $itemName");
                            } else {
                                $message = 'Məhsul silinərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Profil inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'accessory':
                    if ($accessoriesInventoryExists) {
                        // Get item name for log
                        $sql = "SELECT name FROM accessories_inventory WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $itemId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        
                        if ($result) {
                            $itemName = $result['name'];
                            
                            // Delete item
                            $sql = "DELETE FROM accessories_inventory WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("i", $itemId);
                            
                            if ($stmt->execute()) {
                                $message = 'Aksesuar məhsulu uğurla silindi';
                                $messageType = 'success';
                                logActivity($userId, 'delete_inventory', "Aksesuar məhsulu silindi: $itemName");
                            } else {
                                $message = 'Məhsul silinərkən xəta baş verdi';
                                $messageType = 'error';
                            }
                        } else {
                            $message = 'Məhsul tapılmadı';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Aksesuar inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                default:
                    $message = 'Yanlış məhsul növü';
                    $messageType = 'error';
            }
        }
    }
    
    // Edit item
    if (isset($_POST['edit_item'])) {
        $itemType = $_POST['item_type'] ?? '';
        $itemId = (int)($_POST['item_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        
        if ($itemId <= 0) {
            $message = 'Məhsul ID-si düzgün deyil';
            $messageType = 'error';
        } elseif (empty($name)) {
            $message = 'Məhsul adı boş ola bilməz';
            $messageType = 'error';
        } else {
            switch ($itemType) {
                case 'glass':
                    if ($glassInventoryExists) {
                        $thickness = floatval($_POST['thickness'] ?? 0);
                        $type = trim($_POST['glass_type'] ?? '');
                        if ($type === 'custom' && !empty($_POST['custom_glass_type'])) {
                            $type = trim($_POST['custom_glass_type']);
                        }
                        $dimensions = trim($_POST['dimensions'] ?? '');
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "UPDATE glass_inventory SET 
                                name = ?, 
                                thickness = ?, 
                                type = ?, 
                                dimensions = ?, 
                                purchase_price = ?, 
                                notes = ?, 
                                updated_by = ? 
                                WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sdssdii", $name, $thickness, $type, $dimensions, $purchasePrice, $notes, $userId, $itemId);
                        
                        if ($stmt->execute()) {
                            $message = 'Şüşə məhsulu uğurla yeniləndi';
                            $messageType = 'success';
                            logActivity($userId, 'edit_inventory', "Şüşə məhsulu yeniləndi: $name");
                        } else {
                            $message = 'Məhsul yenilənərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Şüşə inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'profile':
                    if ($profileInventoryExists) {
                        $color = trim($_POST['color'] ?? '');
                        $type = trim($_POST['profile_type'] ?? '');
                        if ($type === 'custom' && !empty($_POST['custom_profile_type'])) {
                            $type = trim($_POST['custom_profile_type']);
                        }
                        $unitOfMeasure = $_POST['unit_of_measure'] ?? 'ədəd';
                        $country = trim($_POST['country'] ?? '');
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $salesPrice = floatval($_POST['sales_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "UPDATE profile_inventory SET 
                                name = ?, 
                                color = ?, 
                                type = ?, 
                                unit_of_measure = ?, 
                                country = ?, 
                                purchase_price = ?, 
                                sales_price = ?, 
                                notes = ?, 
                                updated_by = ? 
                                WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssssddii", $name, $color, $type, $unitOfMeasure, $country, $purchasePrice, $salesPrice, $notes, $userId, $itemId);
                        
                        if ($stmt->execute()) {
                            $message = 'Profil məhsulu uğurla yeniləndi';
                            $messageType = 'success';
                            logActivity($userId, 'edit_inventory', "Profil məhsulu yeniləndi: $name");
                        } else {
                            $message = 'Məhsul yenilənərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Profil inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'accessory':
                    if ($accessoriesInventoryExists) {
                        $unitOfMeasure = $_POST['accessory_unit'] ?? 'ədəd';
                        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
                        $notes = trim($_POST['notes'] ?? '');
                        
                        $sql = "UPDATE accessories_inventory SET 
                                name = ?, 
                                unit_of_measure = ?, 
                                purchase_price = ?, 
                                notes = ?, 
                                updated_by = ? 
                                WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssii", $name, $unitOfMeasure, $purchasePrice, $notes, $userId, $itemId);
                        
                        if ($stmt->execute()) {
                            $message = 'Aksesuar məhsulu uğurla yeniləndi';
                            $messageType = 'success';
                            logActivity($userId, 'edit_inventory', "Aksesuar məhsulu yeniləndi: $name");
                        } else {
                            $message = 'Məhsul yenilənərkən xəta baş verdi';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Aksesuar inventar cədvəli mövcud deyil';
                        $messageType = 'error';
                    }
                    break;
                    
                default:
                    $message = 'Yanlış məhsul növü';
                    $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anbar İdarəetməsi | AlumPro</title>
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
        
        .inventory-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .type-glass {
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .type-profile {
            background: #d1fae5;
            color: #065f46;
        }
        
        .type-accessory {
            background: #fef3c7;
            color: #92400e;
        }
        
        .stock-normal {
            color: #10b981;
        }
        
        .stock-low {
            color: #f59e0b;
        }
        
        .stock-out {
            color: #ef4444;
        }
        
        .table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #4b5563;
        }
        
        .table-hover tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .action-btn {
            margin-right: 5px;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1050;
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
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
        }
        
        .modal-dialog {
            position: relative;
            width: auto;
            margin: 1.75rem auto;
            max-width: 500px;
            z-index: 1050;
        }
        
        .modal-dialog.modal-lg {
            max-width: 700px;
        }
        
        .modal-content {
            position: relative;
            display: flex;
            flex-direction: column;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid rgba(0, 0, 0, 0.2);
            border-radius: 0.3rem;
            outline: 0;
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            border-top-left-radius: 0.3rem;
            border-top-right-radius: 0.3rem;
            background: linear-gradient(135deg, #1eb15a 0%, #1e5eb1 100%);
            color: white;
        }
        
        .modal-title {
            margin: 0;
            line-height: 1.5;
            font-size: 1.25rem;
            font-weight: 500;
        }
        
        .modal-header .close {
            padding: 1rem;
            margin: -1rem -1rem -1rem auto;
            background-color: transparent;
            border: 0;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
        }
        
        .modal-body {
            position: relative;
            flex: 1 1 auto;
            padding: 1rem;
        }
        
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .modal-footer .btn + .btn {
            margin-left: 0.5rem;
        }
        
        /* Form Tabs */
        .form-tabs {
            display: flex;
            margin-bottom: 20px;
            background-color: #f8fafc;
            border-radius: 8px;
            padding: 4px;
        }
        
        .form-tab {
            padding: 8px 16px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            font-weight: 500;
            text-align: center;
            flex: 1;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-tab.active {
            background-color: white;
            color: #1eb15a;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .form-tab:not(.active):hover {
            background-color: rgba(255,255,255,0.5);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-content {
            display: none;
        }
        
        .form-content.active {
            display: block;
        }
        
        /* Custom Type Input */
        .custom-type-container {
            display: none;
            margin-top: 10px;
        }
        
        @media (max-width: 576px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
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
                <a href="customers.php"><i class="fas fa-user-tie"></i> Müştərilər</a>
                <a href="inventory.php" class="active"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="orders.php"><i class="fas fa-shopping-cart"></i> Sifarişlər</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
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
                <h1><i class="fas fa-warehouse"></i> Anbar İdarəetməsi</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <span>Anbar</span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?>">
                    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="card-title"><i class="fas fa-boxes"></i> İnventar</h2>
                        <button type="button" class="btn btn-primary" onclick="openAddItemModal()">
                            <i class="fas fa-plus"></i> Yeni Məhsul
                        </button>
                    </div>
                    
                    <form action="" method="get" id="filterForm">
                        <div class="filter-container">
                            <div class="filter-item">
                                <label class="filter-label">Kateqoriya:</label>
                                <select name="category" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>Bütün Kateqoriyalar</option>
                                    <option value="glass" <?= $category === 'glass' ? 'selected' : '' ?>>Şüşə</option>
                                    <option value="profile" <?= $category === 'profile' ? 'selected' : '' ?>>Profil</option>
                                    <option value="accessory" <?= $category === 'accessory' ? 'selected' : '' ?>>Aksesuar</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Stok:</label>
                                <select name="stock_status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $stockStatus === 'all' ? 'selected' : '' ?>>Bütün Stoklar</option>
                                    <option value="in" <?= $stockStatus === 'in' ? 'selected' : '' ?>>Stokda var</option>
                                    <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Stok azalır</option>
                                    <option value="out" <?= $stockStatus === 'out' ? 'selected' : '' ?>>Stokda yoxdur</option>
                                </select>
                            </div>
                            
                            <?php if (!empty($branches)): ?>
                                <div class="filter-item">
                                    <label class="filter-label">Filial:</label>
                                    <select name="branch" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                        <option value="0" <?= $branch === 0 ? 'selected' : '' ?>>Bütün Filiallar</option>
                                        <?php foreach ($branches as $branchItem): ?>
                                            <option value="<?= $branchItem['id'] ?>" <?= $branch === (int)$branchItem['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($branchItem['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <div class="filter-item">
                                <label class="filter-label">Sıralama:</label>
                                <select name="sort" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Ad (A-Z)</option>
                                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Ad (Z-A)</option>
                                    <option value="stock_asc" <?= $sort === 'stock_asc' ? 'selected' : '' ?>>Stok (Az-Çox)</option>
                                    <option value="stock_desc" <?= $sort === 'stock_desc' ? 'selected' : '' ?>>Stok (Çox-Az)</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Axtar:</label>
                                <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="Məhsul adı">
                            </div>
                            
                            <div class="filter-item">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Tətbiq et
                                </button>
                                <a href="inventory.php" class="btn btn-outline ml-2">
                                    <i class="fas fa-redo"></i> Sıfırla
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if (empty($inventory)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <?php if (!$glassInventoryExists && !$profileInventoryExists && !$accessoriesInventoryExists): ?>
                        Anbar cədvəlləri mövcud deyil. Zəhmət olmasa, verilənlər bazasını yoxlayın.
                    <?php else: ?>
                        Axtarış nəticəsində məhsul tapılmadı.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 60px">ID</th>
                                        <th style="width: 100px">Növ</th>
                                        <th>Məhsul adı</th>
                                        <th>Xüsusiyyətləri</th>
                                        <th>Alış qiyməti</th>
                                        <th>Stok</th>
                                        <th style="width: 200px">Əməliyyatlar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory as $item): 
                                        $inventoryType = $item['inventory_type'];
                                        $stockLevel = '';
                                        $stockClass = '';
                                        
                                        if ($inventoryType === 'glass') {
                                            $stockField = 'remaining_volume';
                                            $purchaseField = 'purchase_volume';
                                            $stockUnit = 'm²';
                                        } else {
                                            $stockField = 'remaining_quantity';
                                            $purchaseField = 'purchase_quantity';
                                            $stockUnit = $item['unit_of_measure'] ?? 'ədəd';
                                        }
                                        
                                        $stock = $item[$stockField] ?? 0;
                                        $purchaseStock = $item[$purchaseField] ?? 0;
                                        $stockPercentage = $purchaseStock > 0 ? ($stock / $purchaseStock * 100) : 0;
                                        
                                        if ($stock <= 0) {
                                            $stockClass = 'stock-out';
                                            $stockIcon = 'fa-times-circle';
                                            $stockText = 'Stokda yoxdur';
                                        } elseif ($stockPercentage <= 20) {
                                            $stockClass = 'stock-low';
                                            $stockIcon = 'fa-exclamation-circle';
                                            $stockText = 'Stok azalır';
                                        } else {
                                            $stockClass = 'stock-normal';
                                            $stockIcon = 'fa-check-circle';
                                            $stockText = 'Normal stok';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= $item['id'] ?></td>
                                        <td>
                                            <span class="inventory-type-badge type-<?= $inventoryType ?>">
                                                <?php
                                                switch ($inventoryType) {
                                                    case 'glass':
                                                        echo 'Şüşə';
                                                        break;
                                                    case 'profile':
                                                        echo 'Profil';
                                                        break;
                                                    case 'accessory':
                                                        echo 'Aksesuar';
                                                        break;
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td>
                                            <?php if ($inventoryType === 'glass'): ?>
                                                <div>Növü: <?= htmlspecialchars($item['type'] ?? '-') ?></div>
                                                <div>Qalınlıq: <?= htmlspecialchars($item['thickness'] ?? '-') ?> mm</div>
                                                <?php if (!empty($item['dimensions'])): ?>
                                                    <div>Ölçülər: <?= htmlspecialchars($item['dimensions']) ?></div>
                                                <?php endif; ?>
                                            <?php elseif ($inventoryType === 'profile'): ?>
                                                <div>Növü: <?= htmlspecialchars($item['type'] ?? '-') ?></div>
                                                <div>Rəng: <?= htmlspecialchars($item['color'] ?? '-') ?></div>
                                                <div>Ölçü vahidi: <?= htmlspecialchars($item['unit_of_measure'] ?? 'ədəd') ?></div>
                                                <?php if (!empty($item['country'])): ?>
                                                    <div>Ölkə: <?= htmlspecialchars($item['country']) ?></div>
                                                <?php endif; ?>
                                            <?php elseif ($inventoryType === 'accessory'): ?>
                                                <div>Ölçü vahidi: <?= htmlspecialchars($item['unit_of_measure'] ?? 'ədəd') ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format($item['purchase_price'] ?? 0, 2) ?> ₼</td>
                                        <td>
                                            <div class="<?= $stockClass ?>">
                                                <i class="fas <?= $stockIcon ?>"></i> <?= $stockText ?>
                                            </div>
                                            <div><?= number_format($stock, 2) ?> <?= $stockUnit ?></div>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary action-btn" 
                                                    onclick="updateStock('<?= $inventoryType ?>', <?= $item['id'] ?>, '<?= addslashes($item['name']) ?>', '<?= $stockUnit ?>')">
                                                <i class="fas fa-edit"></i> Stok
                                            </button>
                                            <button type="button" class="btn btn-sm btn-info action-btn" 
                                                    onclick="editItem('<?= $inventoryType ?>', <?= $item['id'] ?>)">
                                                <i class="fas fa-pen"></i> Düzəliş
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger action-btn" 
                                                    onclick="deleteItem('<?= $inventoryType ?>', <?= $item['id'] ?>, '<?= addslashes($item['name']) ?>')">
                                                <i class="fas fa-trash"></i> Sil
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Add Item Modal -->
    <div class="modal" id="addItemModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Yeni Məhsul Əlavə Et</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-tabs">
                        <div class="form-tab active" data-form="glass">Şüşə</div>
                        <div class="form-tab" data-form="profile">Profil</div>
                        <div class="form-tab" data-form="accessory">Aksesuar</div>
                    </div>
                    
                    <!-- Glass Form -->
                    <div class="form-content active" id="glass-form">
                        <form action="" method="post">
                            <input type="hidden" name="add_item" value="1">
                            <input type="hidden" name="item_type" value="glass">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="glass_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                    <input type="text" id="glass_name" name="name" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="glass_type" class="form-label">Şüşə növü <span class="text-danger">*</span></label>
                                    <select id="glass_type" name="glass_type" class="form-control" required onchange="toggleCustomType('glass')">
                                        <option value="">Seçin...</option>
                                        <option value="Adi">Adi</option>
                                        <option value="Güzgü">Güzgü</option>
                                        <option value="Refle">Refle</option>
                                        <option value="Qumlama">Qumlama</option>
                                        <option value="Laminasiya">Laminasiya</option>
                                        <option value="Temper">Temper</option>
                                        <option value="Rəngli">Rəngli</option>
                                        <option value="Qalın">Qalın</option>
                                        <option value="custom">Digər...</option>
                                    </select>
                                    <div id="custom_glass_type_container" class="custom-type-container">
                                        <input type="text" id="custom_glass_type" name="custom_glass_type" class="form-control" placeholder="Şüşə növünü daxil edin">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="thickness" class="form-label">Qalınlıq (mm) <span class="text-danger">*</span></label>
                                    <input type="number" id="thickness" name="thickness" class="form-control" step="0.5" min="1" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="dimensions" class="form-label">Ölçülər</label>
                                    <input type="text" id="dimensions" name="dimensions" class="form-control" placeholder="Məs: 2000x3000">
                                </div>
                                
                                <div class="form-group">
                                    <label for="purchase_volume" class="form-label">Alış miqdarı (m²) <span class="text-danger">*</span></label>
                                    <input type="number" id="purchase_volume" name="purchase_volume" class="form-control" step="0.01" min="0" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                    <input type="number" id="purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="glass_notes" class="form-label">Qeydlər</label>
                                <textarea id="glass_notes" name="notes" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Əlavə et
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Profile Form -->
                    <div class="form-content" id="profile-form">
                        <form action="" method="post">
                                                       <input type="hidden" name="item_type" value="profile">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="profile_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                    <input type="text" id="profile_name" name="name" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="profile_type" class="form-label">Profil növü <span class="text-danger">*</span></label>
                                    <select id="profile_type" name="profile_type" class="form-control" required onchange="toggleCustomType('profile')">
                                        <option value="">Seçin...</option>
                                        <option value="Yan">Yan</option>
                                        <option value="BQ">BQ</option>
                                        <option value="Qulp20">Qulp20</option>
                                        <option value="Qulp110">Qulp110</option>
                                        <option value="Qulp20 3m">Qulp20 3m</option>
                                        <option value="Veqa">Veqa</option>
                                        <option value="Ref">Ref</option>
                                        <option value="Işıqlı">Işıqlı</option>
                                        <option value="Rels">Rels</option>
                                        <option value="custom">Digər...</option>
                                    </select>
                                    <div id="custom_profile_type_container" class="custom-type-container">
                                        <input type="text" id="custom_profile_type" name="custom_profile_type" class="form-control" placeholder="Profil növünü daxil edin">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="color" class="form-label">Rəng <span class="text-danger">*</span></label>
                                    <select id="color" name="color" class="form-control" required>
                                        <option value="">Seçin...</option>
                                        <option value="Qara">Qara</option>
                                        <option value="Qızılı">Qızılı</option>
                                        <option value="Antrasit">Antrasit</option>
                                        <option value="Açıq Antrasit">Açıq Antrasit</option>
                                        <option value="Qəhvəyi">Qəhvəyi</option>
                                        <option value="Ağ">Ağ</option>
                                        <option value="Digər">Digər</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="country" class="form-label">İstehsalçı ölkə</label>
                                    <select id="country" name="country" class="form-control">
                                        <option value="">Seçin...</option>
                                        <option value="Türkiyə">Türkiyə</option>
                                        <option value="Çin">Çin</option>
                                        <option value="Rusiya">Rusiya</option>
                                        <option value="Polşa">Polşa</option>
                                        <option value="Almaniya">Almaniya</option>
                                        <option value="İtaliya">İtaliya</option>
                                        <option value="Digər">Digər</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="unit_of_measure" class="form-label">Ölçü vahidi <span class="text-danger">*</span></label>
                                    <select id="unit_of_measure" name="unit_of_measure" class="form-control" required>
                                        <option value="ədəd">Ədəd</option>
                                        <option value="6m">6 metr</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="profile_purchase_quantity" class="form-label">Alış miqdarı <span class="text-danger">*</span></label>
                                    <input type="number" id="profile_purchase_quantity" name="purchase_quantity" class="form-control" step="0.01" min="0" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="profile_purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                    <input type="number" id="profile_purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="sales_price" class="form-label">Satış qiyməti (₼)</label>
                                    <input type="number" id="sales_price" name="sales_price" class="form-control" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="profile_notes" class="form-label">Qeydlər</label>
                                <textarea id="profile_notes" name="notes" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Əlavə et
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Accessory Form -->
                    <div class="form-content" id="accessory-form">
                        <form action="" method="post">
                            <input type="hidden" name="add_item" value="1">
                            <input type="hidden" name="item_type" value="accessory">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="accessory_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                    <input type="text" id="accessory_name" name="name" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="accessory_unit" class="form-label">Ölçü vahidi <span class="text-danger">*</span></label>
                                    <select id="accessory_unit" name="accessory_unit" class="form-control" required>
                                        <option value="ədəd">Ədəd</option>
                                        <option value="boy">Boy</option>
                                        <option value="kisə">Kisə</option>
                                        <option value="kg">Kiloqram</option>
                                        <option value="palet">Palet</option>
                                        <option value="top">Top</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="accessory_purchase_quantity" class="form-label">Alış miqdarı <span class="text-danger">*</span></label>
                                    <input type="number" id="accessory_purchase_quantity" name="purchase_quantity" class="form-control" step="0.01" min="0" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="accessory_purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                    <input type="number" id="accessory_purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="accessory_notes" class="form-label">Qeydlər</label>
                                <textarea id="accessory_notes" name="notes" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Əlavə et
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Stock Modal -->
    <div class="modal" id="updateStockModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-boxes"></i> Stoku Yenilə</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form action="" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="update_stock" value="1">
                        <input type="hidden" name="item_type" id="stock_item_type">
                        <input type="hidden" name="item_id" id="stock_item_id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong id="stock_item_name"></strong> məhsulu üçün stok yeniləməsi
                        </div>
                        
                        <div class="form-group">
                            <label for="operation" class="form-label">Əməliyyat</label>
                            <select id="operation" name="operation" class="form-control">
                                <option value="add">Əlavə et</option>
                                <option value="subtract">Çıxart</option>
                                <option value="set">Dəqiq miqdar təyin et</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity" class="form-label">Miqdar <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" id="quantity" name="quantity" class="form-control" step="0.01" min="0" required>
                                <div class="input-group-append">
                                    <span class="input-group-text" id="stock_unit"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="stock_notes" class="form-label">Qeyd</label>
                            <textarea id="stock_notes" name="stock_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Yadda Saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Item Modal -->
    <div class="modal" id="editItemModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Məhsulu Redaktə Et</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="edit-modal-content">
                        <!-- Content will be loaded dynamically -->
                        <div class="text-center p-5">
                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                            <p class="mt-2">Yüklənir...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteItemModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt"></i> Məhsulu Sil</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form action="" method="post">
                        <input type="hidden" name="delete_item" value="1">
                        <input type="hidden" name="item_type" id="delete_item_type">
                        <input type="hidden" name="item_id" id="delete_item_id">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <span id="delete_warning_text">Bu məhsulu silmək istədiyinizə əminsiniz?</span>
                        </div>
                        
                        <p>Məhsul adı: <strong id="delete_item_name"></strong></p>
                        <p>Bu əməliyyat geri qaytarıla bilməz.</p>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Sil</button>
                        </div>
                    </form>
                </div>
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
            
            // Form tabs functionality
            const formTabs = document.querySelectorAll('.form-tab');
            const formContents = document.querySelectorAll('.form-content');
            
            formTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const form = this.getAttribute('data-form');
                    
                    // Deactivate all tabs and forms
                    formTabs.forEach(t => t.classList.remove('active'));
                    formContents.forEach(c => c.classList.remove('active'));
                    
                    // Activate current tab and form
                    this.classList.add('active');
                    document.getElementById(form + '-form').classList.add('active');
                });
            });
            
            // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const modalBackdrops = document.querySelectorAll('.modal-backdrop');
            const modalCloseButtons = document.querySelectorAll('.modal-close, .close, [data-dismiss="modal"]');
            
            // Close modal with backdrop or close button
            modalBackdrops.forEach(backdrop => {
                backdrop.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('show');
                });
            });
            
            modalCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('show');
                });
            });
        });
        
        // Toggle custom type input field
        function toggleCustomType(itemType) {
            const selectElement = document.getElementById(itemType + '_type');
            const customContainer = document.getElementById('custom_' + itemType + '_type_container');
            
            if (selectElement.value === 'custom') {
                customContainer.style.display = 'block';
            } else {
                customContainer.style.display = 'none';
            }
        }
        
        function openAddItemModal() {
            document.getElementById('addItemModal').classList.add('show');
        }
        
        function updateStock(itemType, itemId, itemName, unit) {
            document.getElementById('stock_item_type').value = itemType;
            document.getElementById('stock_item_id').value = itemId;
            document.getElementById('stock_item_name').textContent = itemName;
            document.getElementById('stock_unit').textContent = unit;
            
            // Reset form
            document.getElementById('operation').value = 'add';
            document.getElementById('quantity').value = '';
            document.getElementById('stock_notes').value = '';
            
            // Show modal
            document.getElementById('updateStockModal').classList.add('show');
        }
        
        function editItem(itemType, itemId) {
            // Set loading state
            document.getElementById('edit-modal-content').innerHTML = `
                <div class="text-center p-5">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2">Yüklənir...</p>
                </div>
            `;
            
            // Show modal
            document.getElementById('editItemModal').classList.add('show');
            
            // Fetch item details and populate form
            // This would be an AJAX call in a real app
            // For now, let's simulate with setTimeout
            setTimeout(() => {
                let formHtml = '';
                
                if (itemType === 'glass') {
                    formHtml = `
                    <form action="" method="post">
                        <input type="hidden" name="edit_item" value="1">
                        <input type="hidden" name="item_type" value="glass">
                        <input type="hidden" name="item_id" value="${itemId}">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="edit_glass_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                <input type="text" id="edit_glass_name" name="name" class="form-control" value="Test Glass ${itemId}" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_glass_type" class="form-label">Şüşə növü <span class="text-danger">*</span></label>
                                <select id="edit_glass_type" name="glass_type" class="form-control" required onchange="toggleEditCustomType('glass')">
                                    <option value="Adi">Adi</option>
                                    <option value="Güzgü">Güzgü</option>
                                    <option value="Refle" selected>Refle</option>
                                    <option value="Qumlama">Qumlama</option>
                                    <option value="Laminasiya">Laminasiya</option>
                                    <option value="Temper">Temper</option>
                                    <option value="Rəngli">Rəngli</option>
                                    <option value="Qalın">Qalın</option>
                                    <option value="custom">Digər...</option>
                                </select>
                                <div id="edit_custom_glass_type_container" class="custom-type-container" style="display: none;">
                                    <input type="text" id="edit_custom_glass_type" name="custom_glass_type" class="form-control" placeholder="Şüşə növünü daxil edin">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_thickness" class="form-label">Qalınlıq (mm) <span class="text-danger">*</span></label>
                                <input type="number" id="edit_thickness" name="thickness" class="form-control" step="0.5" min="1" value="4" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_dimensions" class="form-label">Ölçülər</label>
                                <input type="text" id="edit_dimensions" name="dimensions" class="form-control" placeholder="Məs: 2000x3000" value="2000x3000">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                <input type="number" id="edit_purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" value="10.50" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_glass_notes" class="form-label">Qeydlər</label>
                            <textarea id="edit_glass_notes" name="notes" class="form-control" rows="2">Test notes</textarea>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Yadda saxla
                            </button>
                        </div>
                    </form>
                    `;
                } else if (itemType === 'profile') {
                    formHtml = `
                    <form action="" method="post">
                        <input type="hidden" name="edit_item" value="1">
                        <input type="hidden" name="item_type" value="profile">
                        <input type="hidden" name="item_id" value="${itemId}">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="edit_profile_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                <input type="text" id="edit_profile_name" name="name" class="form-control" value="Test Profile ${itemId}" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_profile_type" class="form-label">Profil növü <span class="text-danger">*</span></label>
                                <select id="edit_profile_type" name="profile_type" class="form-control" required onchange="toggleEditCustomType('profile')">
                                    <option value="Yan">Yan</option>
                                    <option value="BQ" selected>BQ</option>
                                    <option value="Qulp20">Qulp20</option>
                                    <option value="Qulp110">Qulp110</option>
                                    <option value="Qulp20 3m">Qulp20 3m</option>
                                    <option value="Veqa">Veqa</option>
                                    <option value="Ref">Ref</option>
                                    <option value="Işıqlı">Işıqlı</option>
                                    <option value="Rels">Rels</option>
                                    <option value="custom">Digər...</option>
                                </select>
                                <div id="edit_custom_profile_type_container" class="custom-type-container" style="display: none;">
                                    <input type="text" id="edit_custom_profile_type" name="custom_profile_type" class="form-control" placeholder="Profil növünü daxil edin">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_color" class="form-label">Rəng <span class="text-danger">*</span></label>
                                <select id="edit_color" name="color" class="form-control" required>
                                    <option value="">Seçin...</option>
                                    <option value="Qara">Qara</option>
                                    <option value="Qızılı" selected>Qızılı</option>
                                    <option value="Antrasit">Antrasit</option>
                                    <option value="Açıq Antrasit">Açıq Antrasit</option>
                                    <option value="Qəhvəyi">Qəhvəyi</option>
                                    <option value="Ağ">Ağ</option>
                                    <option value="Digər">Digər</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_country" class="form-label">İstehsalçı ölkə</label>
                                <select id="edit_country" name="country" class="form-control">
                                    <option value="">Seçin...</option>
                                    <option value="Türkiyə" selected>Türkiyə</option>
                                    <option value="Çin">Çin</option>
                                    <option value="Rusiya">Rusiya</option>
                                    <option value="Polşa">Polşa</option>
                                    <option value="Almaniya">Almaniya</option>
                                    <option value="İtaliya">İtaliya</option>
                                    <option value="Digər">Digər</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_unit_of_measure" class="form-label">Ölçü vahidi <span class="text-danger">*</span></label>
                                <select id="edit_unit_of_measure" name="unit_of_measure" class="form-control" required>
                                    <option value="ədəd" selected>Ədəd</option>
                                    <option value="6m">6 metr</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                <input type="number" id="edit_purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" value="25.00" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_sales_price" class="form-label">Satış qiyməti (₼)</label>
                                <input type="number" id="edit_sales_price" name="sales_price" class="form-control" step="0.01" min="0" value="35.00">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_profile_notes" class="form-label">Qeydlər</label>
                            <textarea id="edit_profile_notes" name="notes" class="form-control" rows="2">Test notes</textarea>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Yadda saxla
                            </button>
                        </div>
                    </form>
                    `;
                } else if (itemType === 'accessory') {
                    formHtml = `
                    <form action="" method="post">
                        <input type="hidden" name="edit_item" value="1">
                        <input type="hidden" name="item_type" value="accessory">
                        <input type="hidden" name="item_id" value="${itemId}">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="edit_accessory_name" class="form-label">Məhsul adı <span class="text-danger">*</span></label>
                                <input type="text" id="edit_accessory_name" name="name" class="form-control" value="Test Accessory ${itemId}" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_accessory_unit" class="form-label">Ölçü vahidi <span class="text-danger">*</span></label>
                                <select id="edit_accessory_unit" name="accessory_unit" class="form-control" required>
                                    <option value="ədəd" selected>Ədəd</option>
                                    <option value="boy">Boy</option>
                                    <option value="kisə">Kisə</option>
                                    <option value="kg">Kiloqram</option>
                                    <option value="palet">Palet</option>
                                    <option value="top">Top</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_purchase_price" class="form-label">Alış qiyməti (₼) <span class="text-danger">*</span></label>
                                <input type="number" id="edit_purchase_price" name="purchase_price" class="form-control" step="0.01" min="0" value="5.00" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_accessory_notes" class="form-label">Qeydlər</label>
                            <textarea id="edit_accessory_notes" name="notes" class="form-control" rows="2">Test notes</textarea>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Yadda saxla
                            </button>
                        </div>
                    </form>
                    `;
                }
                
                document.getElementById('edit-modal-content').innerHTML = formHtml;
                
                // Set up close button event handlers for the newly added content
                document.querySelectorAll('#editItemModal [data-dismiss="modal"]').forEach(button => {
                    button.addEventListener('click', function() {
                        document.getElementById('editItemModal').classList.remove('show');
                    });
                });
            }, 500);
        }
        
        function toggleEditCustomType(itemType) {
            const selectElement = document.getElementById('edit_' + itemType + '_type');
            const customContainer = document.getElementById('edit_custom_' + itemType + '_type_container');
            
            if (selectElement.value === 'custom') {
                customContainer.style.display = 'block';
            } else {
                customContainer.style.display = 'none';
            }
        }
        
        function deleteItem(itemType, itemId, itemName) {
            document.getElementById('delete_item_type').value = itemType;
            document.getElementById('delete_item_id').value = itemId;
            document.getElementById('delete_item_name').textContent = itemName;
            
            let typeText = '';
            switch (itemType) {
                case 'glass': typeText = 'şüşə'; break;
                case 'profile': typeText = 'profil'; break;
                case 'accessory': typeText = 'aksesuar'; break;
            }
            
            document.getElementById('delete_warning_text').textContent = `Bu ${typeText} məhsulunu silmək istədiyinizə əminsiniz?`;
            
            // Show modal
            document.getElementById('deleteItemModal').classList.add('show');
        }
    </script>
</body>
</html>