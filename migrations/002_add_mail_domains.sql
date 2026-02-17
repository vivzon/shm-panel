-- Add missing mail_domains table to existing SHM Panel installation
-- Run this if you get error: Table 'shm_panel.mail_domains' doesn't exist

USE shm_panel;

CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify table was created
SHOW TABLES LIKE 'mail_domains';
SELECT COUNT(*) as table_exists FROM information_schema.tables 
WHERE table_schema = 'shm_panel' AND table_name = 'mail_domains';
