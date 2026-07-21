-- LAPOK DMS â€” EFRIS / fiscal device integration (fiscal-first import)
-- Run: mysql -u root lapok_dms < database/migrations/004_efris_integration.sql


-- â”€â”€ Product mapping: Lapok product â†” URA / fiscal device item code â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS efris_product_map (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    efris_item_code VARCHAR(80) NOT NULL COMMENT 'Item code on fiscal device / EFRIS catalog',
    efris_item_name VARCHAR(120) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_efris_product (product_id),
    UNIQUE KEY uq_efris_item_code (efris_item_code),
    CONSTRAINT fk_efris_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- â”€â”€ Imported fiscal receipts (device â†’ Lapok) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS efris_receipts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    efris_invoice_no    VARCHAR(80) NOT NULL COMMENT 'URA / FDMS invoice or receipt number',
    device_serial       VARCHAR(60) DEFAULT NULL,
    fiscal_timestamp    DATETIME NOT NULL,
    amount_total        DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_type        ENUM('cash','credit','other') NOT NULL DEFAULT 'cash',
    status              ENUM('pending_link','linked','unmapped','ignored') NOT NULL DEFAULT 'pending_link',
    source              ENUM('device_push','efris_api','manual') NOT NULL DEFAULT 'device_push',
    order_id            INT UNSIGNED DEFAULT NULL,
    linked_by           INT UNSIGNED DEFAULT NULL,
    vehicle_id          INT UNSIGNED DEFAULT NULL,
    trip_id             INT UNSIGNED DEFAULT NULL,
    customer_id         INT UNSIGNED DEFAULT NULL,
    payload_json        JSON DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linked_at           DATETIME DEFAULT NULL,
    UNIQUE KEY uq_efris_invoice (efris_invoice_no),
    CONSTRAINT fk_efris_rcpt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_efris_rcpt_user FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_efris_rcpt_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    CONSTRAINT fk_efris_rcpt_trip FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE SET NULL,
    CONSTRAINT fk_efris_rcpt_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_efris_rcpt_status (status, fiscal_timestamp)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS efris_receipt_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id      INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED DEFAULT NULL,
    efris_item_code VARCHAR(80) DEFAULT NULL,
    item_name       VARCHAR(120) NOT NULL,
    qty             INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
    subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
    map_status      ENUM('mapped','unmapped') NOT NULL DEFAULT 'unmapped',
    CONSTRAINT fk_efris_ri_receipt FOREIGN KEY (receipt_id) REFERENCES efris_receipts(id) ON DELETE CASCADE,
    CONSTRAINT fk_efris_ri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- â”€â”€ Depot / device config (no secrets in repo â€” set in DB) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS efris_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key      VARCHAR(60) NOT NULL UNIQUE,
    config_value    VARCHAR(255) NOT NULL,
    notes           VARCHAR(255) DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO efris_config (config_key, config_value, notes) VALUES
    ('integration_mode', 'fiscal_first', 'fiscal_first | lapok_first'),
    ('seller_tin', '', 'CCBA / outlet TIN registered with URA'),
    ('default_device_serial', '', 'Primary field fiscal device â€” optional'),
    ('ingest_api_key', '', 'Shared secret for device push webhook â€” set in production')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
