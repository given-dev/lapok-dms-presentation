-- LAPOK DMS — CCBA / MyCCBA integration tables (blueprint v1)
-- Run after schema.sql or on existing lapok_dms:
--   mysql -u root lapok_dms < database/migrations/002_ccba_integration.sql

USE lapok_dms;

-- ── Product mapping: Lapok SKU ↔ MyCCBA catalog code ─────────────────
CREATE TABLE IF NOT EXISTS ccba_product_map (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    ccba_sku_code   VARCHAR(80) NOT NULL COMMENT 'e.g. Coke 500ML 01X12 (PET) — confirm with CCBA',
    ccba_pack_desc  VARCHAR(120) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ccba_product (product_id),
    UNIQUE KEY uq_ccba_sku (ccba_sku_code),
    CONSTRAINT fk_ccba_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Outbound replenishment orders (manager creates in Lapok) ─────────
CREATE TABLE IF NOT EXISTS ccba_orders (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lapok_ref               VARCHAR(40) NOT NULL UNIQUE,
    status                  ENUM(
        'draft',
        'ready_for_ccba',
        'submitted_to_ccba',
        'ccba_acknowledged',
        'ccba_confirmed',
        'scheduled',
        'dispatched',
        'delivered',
        'received_in_lapok',
        'closed',
        'partial_delivery',
        'cancelled',
        'rejected'
    ) NOT NULL DEFAULT 'draft',
    submission_mode         ENUM('assisted_portal', 'api', 'edi', 'manual') NOT NULL DEFAULT 'assisted_portal',
    ccba_order_no           VARCHAR(60) DEFAULT NULL COMMENT 'From MyCCBA confirmation',
    ccba_po_no              VARCHAR(60) DEFAULT NULL,
    ccba_customer_code      VARCHAR(40) DEFAULT NULL COMMENT 'Outlet/depot code on CCBA — TBD',
    requested_delivery_date DATE DEFAULT NULL,
    submitted_at            DATETIME DEFAULT NULL,
    confirmed_at            DATETIME DEFAULT NULL,
    closed_at               DATETIME DEFAULT NULL,
    created_by              INT UNSIGNED NOT NULL,
    notes                   TEXT DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ccba_orders_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_ccba_orders_status (status),
    INDEX idx_ccba_orders_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ccba_order_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ccba_order_id       INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    ccba_sku_code       VARCHAR(80) DEFAULT NULL,
    qty_requested       INT UNSIGNED NOT NULL DEFAULT 0,
    qty_confirmed       INT UNSIGNED DEFAULT NULL,
    unit_cost_estimate  DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes               VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_ccba_oi_order FOREIGN KEY (ccba_order_id) REFERENCES ccba_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccba_oi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Status timeline (append-only) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS ccba_status_events (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ccba_order_id       INT UNSIGNED NOT NULL,
    status              VARCHAR(40) NOT NULL,
    ccba_status_label   VARCHAR(120) DEFAULT NULL COMMENT 'Raw label from portal/API',
    source              ENUM('lapok', 'ccba_portal', 'ccba_api', 'manager') NOT NULL DEFAULT 'lapok',
    payload_json        JSON DEFAULT NULL,
    recorded_by         INT UNSIGNED DEFAULT NULL,
    recorded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ccba_se_order FOREIGN KEY (ccba_order_id) REFERENCES ccba_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccba_se_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ccba_se_order_time (ccba_order_id, recorded_at)
) ENGINE=InnoDB;

-- ── Flexible references (PO, waybill, invoice, etc.) ─────────────────
CREATE TABLE IF NOT EXISTS ccba_refs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ccba_order_id   INT UNSIGNED DEFAULT NULL,
    delivery_id     INT UNSIGNED DEFAULT NULL COMMENT 'supplier_deliveries.id when receipt-linked',
    ref_type        VARCHAR(40) NOT NULL COMMENT 'ccba_order_no, ccba_po, waybill, invoice, truck_plate, driver_name',
    ref_value       VARCHAR(120) NOT NULL,
    source          ENUM('lapok', 'ccba_portal', 'ccba_api', 'paper') NOT NULL DEFAULT 'lapok',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ccba_refs_order FOREIGN KEY (ccba_order_id) REFERENCES ccba_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccba_refs_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE SET NULL,
    INDEX idx_ccba_refs_type (ref_type, ref_value)
) ENGINE=InnoDB;

-- ── Daily stock snapshots for CCBA level-2 sync ──────────────────────
CREATE TABLE IF NOT EXISTS ccba_stock_snapshots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_date   DATE NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    qty_warehouse   INT UNSIGNED NOT NULL DEFAULT 0,
    qty_on_vehicles INT UNSIGNED NOT NULL DEFAULT 0,
    sync_status     ENUM('pending', 'synced', 'failed') NOT NULL DEFAULT 'pending',
    sync_error      VARCHAR(255) DEFAULT NULL,
    synced_at       DATETIME DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ccba_ss_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccba_ss_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_ccba_ss_day_product (snapshot_date, product_id)
) ENGINE=InnoDB;

-- ── Link inbound delivery to outbound CCBA order ─────────────────────
ALTER TABLE supplier_deliveries
    ADD COLUMN IF NOT EXISTS ccba_order_id INT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_delivery_ccba_order FOREIGN KEY (ccba_order_id) REFERENCES ccba_orders(id) ON DELETE SET NULL;

-- ── Optional: depot-level CCBA config (no secrets in repo) ───────────
CREATE TABLE IF NOT EXISTS ccba_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key      VARCHAR(60) NOT NULL UNIQUE,
    config_value    VARCHAR(255) NOT NULL,
    notes           VARCHAR(255) DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO ccba_config (config_key, config_value, notes) VALUES
    ('portal_url_uganda', 'https://uganda.myccba.africa/', 'MyCCBA Uganda — confirm with rep'),
    ('integration_mode', 'assisted_portal', 'assisted_portal | api | edi'),
    ('ccba_customer_code', '', 'Fill after CCBA onboarding')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
