-- AlumPro.az SQL Schema
-- Version: 1.0
-- Created: 2025-09-02

-- Create database
CREATE DATABASE IF NOT EXISTS alumpro_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE alumpro_db;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    role ENUM('admin', 'seller', 'production', 'customer') NOT NULL DEFAULT 'customer',
    status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    branch_id INT NULL,
    last_login DATETIME NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    lockout_time DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_branch (branch_id)
);

-- Customers table
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    fullname VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    company VARCHAR(100) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    created_by INT NULL,
    updated_at DATETIME NULL,
    updated_by INT NULL,
    INDEX idx_user (user_id),
    INDEX idx_phone (phone),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Branches table
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    manager VARCHAR(100) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    created_by INT NOT NULL,
    updated_at DATETIME NULL,
    updated_by INT NULL,
    INDEX idx_status (status),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Orders table
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) UNIQUE NOT NULL,
    barcode VARCHAR(20) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    branch_id INT NOT NULL,
    order_date DATETIME NOT NULL,
    order_status ENUM('new', 'processing', 'completed', 'delivered', 'cancelled') NOT NULL DEFAULT 'new',
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    advance_payment DECIMAL(10, 2) NOT NULL DEFAULT 0,
    remaining_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    processing_date DATETIME NULL,
    completion_date DATETIME NULL,
    delivery_date DATETIME NULL,
    initial_note TEXT NULL,
    seller_notes TEXT NULL,
    drawing_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_branch (branch_id),
    INDEX idx_status (order_status),
    INDEX idx_dates (order_date, completion_date, delivery_date),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
);

-- Order Profiles table (for aluminum profiles)
CREATE TABLE order_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    profile_type VARCHAR(100) NOT NULL,
    width INT NOT NULL, -- in cm
    height INT NOT NULL, -- in cm
    quantity INT NOT NULL DEFAULT 1,
    total_length DECIMAL(10, 2) NULL, -- in meters
    total_weight DECIMAL(10, 2) NULL, -- in kg
    color VARCHAR(50) NULL,
    hinge_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_order (order_id),
    INDEX idx_profile_type (profile_type),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);