-- MySQL database structure for AlumPro Warehouse and Sales System

-- Users table for authentication and roles
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'seller', 'customer', 'production') NOT NULL,
    branch_id INT NULL,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'active',
    profile_image VARCHAR(255),
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (role),
    INDEX (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Branches table (stores/locations)
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity log for tracking user actions
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (action_type),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warehouse categories
CREATE TABLE IF NOT EXISTS warehouse_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default warehouse categories
INSERT INTO warehouse_categories (name, description) VALUES
('Aluminium Profil', 'Aluminium profil anbari'),
('Şüşə', 'Şüşə anbari'),
('Aksesuar', 'Aksesuar anbari');

-- Glass inventory
CREATE TABLE IF NOT EXISTS glass_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    thickness DECIMAL(5,2) NOT NULL COMMENT 'mm',
    type VARCHAR(100) NOT NULL,
    dimensions VARCHAR(50) COMMENT 'Width x Height',
    supplier_id INT,
    purchase_date DATE,
    purchase_volume DECIMAL(10,2),
    purchase_price DECIMAL(10,2),
    sold_volume DECIMAL(10,2) DEFAULT 0,
    remaining_volume DECIMAL(10,2),
    unit_of_measure VARCHAR(20) DEFAULT 'm²',
    notes TEXT,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (name),
    INDEX (thickness),
    INDEX (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profile inventory
CREATE TABLE IF NOT EXISTS profile_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(50),
    type VARCHAR(100),
    unit_of_measure ENUM('ədəd', '6m') DEFAULT 'ədəd',
    country VARCHAR(100),
    purchase_quantity DECIMAL(10,2),
    purchase_price DECIMAL(10,2),
    sold_quantity DECIMAL(10,2) DEFAULT 0,
    sales_price DECIMAL(10,2),
    remaining_quantity DECIMAL(10,2),
    notes TEXT,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (name),
    INDEX (color),
    INDEX (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accessories inventory
CREATE TABLE IF NOT EXISTS accessories_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    unit_of_measure ENUM('ədəd', 'boy', 'kisə', 'kg', 'palet', 'top') DEFAULT 'ədəd',
    purchase_quantity DECIMAL(10,2),
    purchase_price DECIMAL(10,2),
    sold_quantity DECIMAL(10,2) DEFAULT 0,
    remaining_quantity DECIMAL(10,2),
    notes TEXT,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE COMMENT 'Link to users table if registered',
    fullname VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    total_orders INT DEFAULT 0,
    total_payment DECIMAL(12,2) DEFAULT 0,
    advance_payment DECIMAL(12,2) DEFAULT 0,
    remaining_debt DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (phone),
    INDEX (fullname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    branch_id INT NOT NULL,
    order_date DATETIME NOT NULL,
    barcode VARCHAR(50) UNIQUE,
    order_status ENUM('new', 'processing', 'completed', 'delivered', 'cancelled') DEFAULT 'new',
    total_amount DECIMAL(12,2) DEFAULT 0,
    assembly_fee DECIMAL(10,2) DEFAULT 0,
    advance_payment DECIMAL(12,2) DEFAULT 0,
    remaining_amount DECIMAL(12,2) DEFAULT 0,
    initial_note TEXT,
    seller_notes TEXT,
    drawing_image TEXT COMMENT 'Base64 image of drawing canvas',
    delivery_date DATE,
    delivered_by INT,
    delivery_signature TEXT COMMENT 'Base64 image of signature',
    pdf_file VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (order_number),
    INDEX (customer_id),
    INDEX (seller_id),
    INDEX (order_date),
    INDEX (barcode),
    INDEX (order_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order details (profiles)
CREATE TABLE IF NOT EXISTS order_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    profile_type VARCHAR(50) NOT NULL,
    height DECIMAL(10,2) NOT NULL COMMENT 'cm',
    width DECIMAL(10,2) NOT NULL COMMENT 'cm',
    quantity INT NOT NULL,
    hinge_count INT DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order details (glass)
CREATE TABLE IF NOT EXISTS order_glass (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    glass_type VARCHAR(50) NOT NULL,
    height DECIMAL(10,2) NOT NULL COMMENT 'cm',
    width DECIMAL(10,2) NOT NULL COMMENT 'cm',
    quantity INT NOT NULL,
    offset_mm INT DEFAULT 0,
    area DECIMAL(10,4) NOT NULL COMMENT 'm²',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order price details
CREATE TABLE IF NOT EXISTS order_pricing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    side_profiles_length DECIMAL(10,2) COMMENT 'm',
    side_profiles_price DECIMAL(10,2) DEFAULT 0,
    handle_profiles_length DECIMAL(10,2) COMMENT 'm',
    handle_profiles_price DECIMAL(10,2) DEFAULT 0,
    glass_area DECIMAL(10,4) COMMENT 'm²',
    glass_price DECIMAL(10,2) DEFAULT 0,
    hinge_count INT DEFAULT 0,
    hinge_price DECIMAL(10,2) DEFAULT 0,
    connection_count INT DEFAULT 0,
    connection_price DECIMAL(10,2) DEFAULT 0,
    mechanism_count INT DEFAULT 0,
    mechanism_price DECIMAL(10,2) DEFAULT 0,
    transport_fee DECIMAL(10,2) DEFAULT 0,
    assembly_fee DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messages between users
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(255),
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (sender_id),
    INDEX (receiver_id),
    INDEX (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WhatsApp notification templates
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) NOT NULL UNIQUE,
    template_type ENUM('whatsapp', 'email', 'sms', 'system') NOT NULL,
    template_subject VARCHAR(255),
    template_content TEXT NOT NULL,
    variables TEXT COMMENT 'JSON array of available variables',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default WhatsApp templates
INSERT INTO notification_templates (template_name, template_type, template_subject, template_content, variables) 
VALUES 
('new_order', 'whatsapp', 'Yeni Sifariş', 'Hörmətli {{customer_name}}, {{order_date}} tarixində {{order_number}} nömrəli sifarişiniz qəbul edildi. Sifarişinizin ümumi məbləği: {{total_amount}} AZN. Təşəkkür edirik!', '["customer_name", "order_date", "order_number", "total_amount"]'),
('debt_reminder', 'whatsapp', 'Borc Xatırlatması', 'Hörmətli {{customer_name}}, {{order_number}} nömrəli sifarişiniz üzrə {{remaining_debt}} AZN məbləğində borcunuz var. Xahiş edirik ödənişi tamamlayın. Ətraflı məlumat üçün: {{phone}}', '["customer_name", "order_number", "remaining_debt", "phone"]'),
('order_completed', 'whatsapp', 'Sifariş Hazırdır', 'Hörmətli {{customer_name}}, {{order_number}} nömrəli sifarişiniz hazırdır və təhvil üçün hazırdır. Filialımıza yaxınlaşa bilərsiniz. Əlaqə: {{phone}}', '["customer_name", "order_number", "phone"]');

-- Initial Admin User
INSERT INTO users (username, password, fullname, email, role) 
VALUES ('admin', '$2y$10$1NLmI.UvCXqNWhj9mSBEF.UuKj3VmaESlsYsyQsY9iUbpmBw4C8VG', 'System Admin', 'admin@alumpro.az', 'admin');
-- Default password is 'admin123' - this should be changed immediately after first login