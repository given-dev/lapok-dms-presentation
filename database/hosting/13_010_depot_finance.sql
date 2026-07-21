-- Depot finance controls: opening/closing stock snapshots + monthly fixed costs (manual entry)

CREATE TABLE IF NOT EXISTS depot_stock_snapshots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_date   DATE NOT NULL,
    snapshot_type   ENUM('opening','closing') NOT NULL,
    lines_json      JSON NOT NULL,
    notes           TEXT DEFAULT NULL,
    submitted_by    INT UNSIGNED NOT NULL,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_depot_snapshot (snapshot_date, snapshot_type),
    CONSTRAINT fk_depot_snapshot_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_depot_snapshot_date (snapshot_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS depot_fixed_costs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cost_month      CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    rent_ugx        DECIMAL(14,2) NOT NULL DEFAULT 0,
    salaries_ugx    DECIMAL(14,2) NOT NULL DEFAULT 0,
    utilities_ugx   DECIMAL(14,2) NOT NULL DEFAULT 0,
    security_ugx    DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_ugx       DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes           TEXT DEFAULT NULL,
    updated_by      INT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_depot_cost_month (cost_month),
    CONSTRAINT fk_depot_fixed_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
