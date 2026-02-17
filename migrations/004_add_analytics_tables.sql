-- ============================================================================
-- SHM Panel - Add Missing Analytics Tables
-- ============================================================================
-- This script adds the domain_traffic and malware_scans tables
-- These are required by cpanel/index.php and cpanel/domains.php

USE shm_panel;

-- ============================================================================
-- 1. Domain Traffic Table (for analytics in dashboard)
-- ============================================================================

CREATE TABLE IF NOT EXISTS domain_traffic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    date DATE NOT NULL,
    hits INT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_id (domain_id),
    INDEX idx_date (date),
    UNIQUE KEY unique_domain_date (domain_id, date),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. Malware Scans Table (for security scanning in domains)
-- ============================================================================

CREATE TABLE IF NOT EXISTS malware_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    status ENUM('clean', 'infected', 'running', 'failed') DEFAULT 'clean',
    threats_found INT DEFAULT 0,
    files_scanned INT DEFAULT 0,
    scan_duration INT DEFAULT 0,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details TEXT,
    INDEX idx_domain_id (domain_id),
    INDEX idx_status (status),
    INDEX idx_scanned_at (scanned_at),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Insert sample data for testing (optional)
-- ============================================================================

-- Insert sample traffic data for the last 7 days for existing domains
INSERT IGNORE INTO domain_traffic (domain_id, date, hits, bytes_sent, unique_visitors)
SELECT 
    id as domain_id,
    DATE(NOW() - INTERVAL n DAY) as date,
    FLOOR(RAND() * 1000) as hits,
    FLOOR(RAND() * 10000000) as bytes_sent,
    FLOOR(RAND() * 500) as unique_visitors
FROM domains
CROSS JOIN (
    SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
    UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
) days
WHERE NOT EXISTS (
    SELECT 1 FROM domain_traffic dt 
    WHERE dt.domain_id = domains.id 
    AND dt.date = DATE(NOW() - INTERVAL n DAY)
);

-- Insert initial malware scan status for all domains
INSERT IGNORE INTO malware_scans (domain_id, status, scanned_at)
SELECT id, 'clean', NOW()
FROM domains
WHERE NOT EXISTS (
    SELECT 1 FROM malware_scans ms WHERE ms.domain_id = domains.id
);

-- ============================================================================
-- 4. Verify tables were created
-- ============================================================================

SELECT 
    'Tables Created' as Status,
    COUNT(*) as Count
FROM information_schema.tables 
WHERE table_schema = 'shm_panel'
    AND table_name IN ('domain_traffic', 'malware_scans');

SELECT 'Migration completed successfully!' as Result;
