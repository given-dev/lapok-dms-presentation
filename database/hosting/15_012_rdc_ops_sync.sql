-- RDC month-end workspace + staff welfare register (server sync across roles)

CREATE TABLE IF NOT EXISTS rdc_month_end (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_month    CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    state_json      JSON NOT NULL,
    updated_by      INT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rdc_month_end (period_month),
    CONSTRAINT fk_rdc_month_end_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rdc_month_end_period (period_month)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_welfare_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_date      DATE NOT NULL,
    staff_name      VARCHAR(120) NOT NULL,
    entry_type      ENUM('request','advance','medical','other') NOT NULL DEFAULT 'request',
    amount_ugx      DECIMAL(14,2) NOT NULL DEFAULT 0,
    status          ENUM('open','resolved') NOT NULL DEFAULT 'open',
    notes           TEXT DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    updated_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_welfare_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_welfare_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_welfare_date (entry_date),
    INDEX idx_welfare_status (status),
    INDEX idx_welfare_staff (staff_name)
) ENGINE=InnoDB;
