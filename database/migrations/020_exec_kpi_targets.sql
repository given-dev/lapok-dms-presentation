-- LAPOK DMS - executive KPI targets + carry-forward config.
-- sales_targets holds monthly sales targets by product bucket (SODA = CSD, WATER).
-- exec_kpi_config holds manual carry-forward figures (e.g. CSO opening balance).

CREATE TABLE IF NOT EXISTS sales_targets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_month  CHAR(7) NOT NULL,
    category      ENUM('SODA','WATER') NOT NULL,
    target_units  DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'crates / cartons',
    target_revenue DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'UGX',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_target (target_month, category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exec_kpi_config (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key    VARCHAR(64) NOT NULL,
    config_value  VARCHAR(191) NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exec_kpi_config_key (config_key)
) ENGINE=InnoDB;

-- CSO opening carry-forward: set to prior month cumulative still out when closing a month.
INSERT INTO exec_kpi_config (config_key, config_value) VALUES ('cso_opening_bf', '0')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- NOTE: sales_targets rows are NOT seeded here. The manager enters the monthly
-- per-cadet + depot targets via the "Monthly targets" page (api/targets/save.php),
-- so the executive board only ever reflects genuinely-entered targets.
