-- ============================================================================
-- SHM Panel - Transactions Table
-- ============================================================================
-- Tracks all payment transactions for client accounts.
-- Referenced by: landing/process_payment.php, cpanel/billing.php
-- ============================================================================

USE shm_panel;

CREATE TABLE IF NOT EXISTS transactions (
    id                  INT AUTO_INCREMENT PRIMARY KEY,

    -- Who & what
    client_id           INT NOT NULL,
    package_id          INT,

    -- Payment details
    amount              DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    currency            CHAR(3)        NOT NULL DEFAULT 'INR',
    payment_gateway     VARCHAR(50)    NOT NULL DEFAULT 'manual',  -- razorpay | paypal | manual
    transaction_id      VARCHAR(255)   NOT NULL,                   -- gateway's txn reference
    gateway_response    JSON,                                      -- raw webhook/callback payload

    -- Billing cycle
    plan_name           VARCHAR(100),
    billing_period      ENUM('monthly', 'quarterly', 'yearly', 'one_time') DEFAULT 'monthly',
    period_start        DATE,
    period_end          DATE,

    -- Status lifecycle
    status              ENUM('pending', 'paid', 'failed', 'refunded', 'disputed') DEFAULT 'pending',
    refunded_at         TIMESTAMP NULL DEFAULT NULL,
    refund_reason       TEXT,

    -- Meta
    ip_address          VARCHAR(45),
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY unique_txn (payment_gateway, transaction_id),

    -- Indexes for common queries
    INDEX idx_client_id        (client_id),
    INDEX idx_package_id       (package_id),
    INDEX idx_status           (status),
    INDEX idx_gateway          (payment_gateway),
    INDEX idx_created          (created_at),
    INDEX idx_client_status    (client_id, status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment transaction ledger for all client purchases';


-- ============================================================================
-- Foreign Keys (add if your tables have proper PKs)
-- ============================================================================

ALTER TABLE transactions
    ADD CONSTRAINT fk_txn_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    ADD CONSTRAINT fk_txn_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL;


-- ============================================================================
-- Verification
-- ============================================================================

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'shm_panel'
  AND TABLE_NAME   = 'transactions'
ORDER BY ORDINAL_POSITION;

SELECT 'transactions table created successfully!' AS Result;
