-- LAPOK DMS — RDC daily balancing (Accountant / Resident Depot Commissioner)
-- Run: mysql -u root lapok_dms < database/migrations/008_rdc_daily_balancing.sql

USE lapok_dms;

CREATE TABLE IF NOT EXISTS rdc_daily_sheets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    balance_date    DATE NOT NULL,
    status          ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    sales_json      JSON NOT NULL,
    recoveries_json JSON NOT NULL,
    expenses_json   JSON NOT NULL,
    cash_out_json   JSON DEFAULT NULL,
    cash_actual_json JSON NOT NULL,
    sales_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
    recovery_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    expenses_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
    expected_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    actual_total    DECIMAL(14,2) NOT NULL DEFAULT 0,
    variance        DECIMAL(14,2) NOT NULL DEFAULT 0,
    columns_json    JSON NOT NULL COMMENT 'Column keys and labels for this sheet',
    notes           TEXT DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    submitted_by    INT UNSIGNED DEFAULT NULL,
    submitted_at    DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rdc_balance_date (balance_date),
    CONSTRAINT fk_rdc_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rdc_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rdc_status (status, balance_date)
) ENGINE=InnoDB;
