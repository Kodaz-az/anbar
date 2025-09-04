-- WhatsApp Message Storage Tables
-- Created: 2025-09-02 09:42:19

-- WhatsApp messages table
CREATE TABLE whatsapp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL,
    customer_id INT NULL,
    phone_number VARCHAR(20) NOT NULL,
    message_type VARCHAR(20) NOT NULL,
    message_content TEXT NOT NULL,
    is_processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    received_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_id (message_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_phone (phone_number),
    INDEX idx_received (received_at),
    INDEX idx_processed (is_processed),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- Customer media files table
CREATE TABLE customer_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,
    phone_number VARCHAR(20) NOT NULL,
    media_type VARCHAR(20) NOT NULL,
    media_id VARCHAR(100) NOT NULL,
    media_url VARCHAR(255) NULL,
    caption TEXT NULL,
    file_path VARCHAR(255) NULL,
    is_downloaded TINYINT(1) NOT NULL DEFAULT 0,
    downloaded_at DATETIME NULL,
    received_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_id (customer_id),
    INDEX idx_phone (phone_number),
    INDEX idx_media_id (media_id),
    INDEX idx_downloaded (is_downloaded),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- WhatsApp templates table
CREATE TABLE whatsapp_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) NOT NULL,
    template_language VARCHAR(10) NOT NULL DEFAULT 'az',
    template_category VARCHAR(50) NOT NULL,
    template_content TEXT NOT NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    INDEX idx_template_name (template_name),
    INDEX idx_language (template_language),
    INDEX idx_active (is_active),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- WhatsApp template variables table
CREATE TABLE whatsapp_template_variables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    variable_name VARCHAR(50) NOT NULL,
    variable_description TEXT NULL,
    variable_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_template_id (template_id),
    FOREIGN KEY (template_id) REFERENCES whatsapp_templates(id) ON DELETE CASCADE
);

-- WhatsApp sent messages log
CREATE TABLE whatsapp_sent_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,
    phone_number VARCHAR(20) NOT NULL,
    message_type VARCHAR(20) NOT NULL,
    template_name VARCHAR(100) NULL,
    message_content TEXT NOT NULL,
    message_id VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    status_updated_at DATETIME NULL,
    sent_at DATETIME NOT NULL,
    sent_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_id (customer_id),
    INDEX idx_phone (phone_number),
    INDEX idx_template (template_name),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
);