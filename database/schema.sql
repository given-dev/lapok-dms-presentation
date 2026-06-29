-- LAPOK DMS — MySQL Schema (Phases 1–3)
-- Run: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS lapok_dms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lapok_dms;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS edit_requests;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS trip_load_items;
DROP TABLE IF EXISTS delivery_trips;
DROP TABLE IF EXISTS supplier_delivery_items;
DROP TABLE IF EXISTS supplier_deliveries;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS batches;
DROP TABLE IF EXISTS route_stops;
DROP TABLE IF EXISTS routes;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Users ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(180) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','executive','manager','accountant','field_user','driver','cadet') NOT NULL DEFAULT 'field_user',
    national_id     VARCHAR(30) DEFAULT NULL,
    phone           VARCHAR(30) DEFAULT NULL,
    vehicle_id      INT UNSIGNED DEFAULT NULL,
    default_route   VARCHAR(120) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Products ──────────────────────────────────────────────────────────
CREATE TABLE products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    sku             VARCHAR(30) NOT NULL UNIQUE,
    unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_stock       INT UNSIGNED NOT NULL DEFAULT 80,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Vehicles ──────────────────────────────────────────────────────────
CREATE TABLE vehicles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration    VARCHAR(30) NOT NULL UNIQUE,
    vehicle_type    ENUM('truck','tuktuk') NOT NULL DEFAULT 'truck',
    make_model      VARCHAR(80) DEFAULT NULL,
    capacity        INT UNSIGNED NOT NULL DEFAULT 40,
    driver_id       INT UNSIGNED DEFAULT NULL,
    cadet_id        INT UNSIGNED DEFAULT NULL,
    status          ENUM('available','on_route','inactive') NOT NULL DEFAULT 'available',
    current_route   VARCHAR(120) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE users
    ADD CONSTRAINT fk_users_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;

ALTER TABLE vehicles
    ADD CONSTRAINT fk_vehicles_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_vehicles_cadet FOREIGN KEY (cadet_id) REFERENCES users(id) ON DELETE SET NULL;

-- ── Customers ─────────────────────────────────────────────────────────
CREATE TABLE customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(160) NOT NULL,
    phone           VARCHAR(30) DEFAULT NULL,
    location        VARCHAR(255) DEFAULT NULL,
    category        ENUM('occasional','regular','vip') NOT NULL DEFAULT 'occasional',
    credit_balance  DECIMAL(14,2) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Routes ────────────────────────────────────────────────────────────
CREATE TABLE routes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    zone            VARCHAR(80) DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE route_stops (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_id        INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    stop_order      INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT fk_route_stops_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_stops_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Supplier deliveries (Coca-Cola intake) ────────────────────────────
CREATE TABLE supplier_deliveries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_date   DATE NOT NULL,
    delivery_time   TIME DEFAULT NULL,
    waybill         VARCHAR(60) DEFAULT NULL,
    invoice_number  VARCHAR(60) DEFAULT NULL,
    truck_plate     VARCHAR(30) DEFAULT NULL,
    driver_name     VARCHAR(120) DEFAULT NULL,
    received_by     INT UNSIGNED DEFAULT NULL,
    condition_note  ENUM('good','minor_damage','short_delivery') NOT NULL DEFAULT 'good',
    temperature     ENUM('cold','room','warm') NOT NULL DEFAULT 'cold',
    notes           TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_delivery_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_delivery_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE supplier_delivery_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id     INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    qty_ordered     INT UNSIGNED NOT NULL DEFAULT 0,
    qty_delivered   INT UNSIGNED NOT NULL DEFAULT 0,
    batch_number    VARCHAR(60) NOT NULL,
    expiry_date     DATE NOT NULL,
    unit_cost       DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_sdi_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_sdi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Batches & stock ───────────────────────────────────────────────────
CREATE TABLE batches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    batch_number    VARCHAR(60) NOT NULL,
    expiry_date     DATE NOT NULL,
    qty_warehouse   INT UNSIGNED NOT NULL DEFAULT 0,
    qty_on_vehicles INT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost       DECIMAL(12,2) NOT NULL DEFAULT 0,
    delivery_id     INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_batch (product_id, batch_number),
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_batches_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED DEFAULT NULL,
    movement_type   ENUM('stock_in','stock_out','dispatch','return','sale','adjustment','cancel_restore') NOT NULL,
    qty             INT NOT NULL,
    reference_type  VARCHAR(40) DEFAULT NULL,
    reference_id    INT UNSIGNED DEFAULT NULL,
    user_id         INT UNSIGNED DEFAULT NULL,
    notes           VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sm_created (created_at),
    INDEX idx_sm_product (product_id)
) ENGINE=InnoDB;

-- ── Delivery trips (dispatch) ─────────────────────────────────────────
CREATE TABLE delivery_trips (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT UNSIGNED NOT NULL,
    driver_id       INT UNSIGNED DEFAULT NULL,
    cadet_id        INT UNSIGNED DEFAULT NULL,
    route_id        INT UNSIGNED DEFAULT NULL,
    route_area      VARCHAR(120) DEFAULT NULL,
    status          ENUM('dispatched','on_route','returned','completed','cancelled') NOT NULL DEFAULT 'dispatched',
    dispatched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_at     DATETIME DEFAULT NULL,
    odometer_start  INT UNSIGNED DEFAULT NULL,
    odometer_end    INT UNSIGNED DEFAULT NULL,
    cash_reported   DECIMAL(14,2) DEFAULT NULL,
    cash_collected  DECIMAL(14,2) DEFAULT NULL,
    fuel_cost       DECIMAL(12,2) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    CONSTRAINT fk_trip_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_trip_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_trip_cadet FOREIGN KEY (cadet_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_trip_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE trip_load_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED DEFAULT NULL,
    qty_loaded      INT UNSIGNED NOT NULL DEFAULT 0,
    qty_sold        INT UNSIGNED NOT NULL DEFAULT 0,
    qty_returned    INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_tli_trip FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_tli_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tli_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Orders (sales) ────────────────────────────────────────────────────
CREATE TABLE orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_ref       VARCHAR(30) NOT NULL UNIQUE,
    customer_id     INT UNSIGNED DEFAULT NULL,
    user_id         INT UNSIGNED NOT NULL,
    trip_id         INT UNSIGNED DEFAULT NULL,
    vehicle_id      INT UNSIGNED DEFAULT NULL,
    status          ENUM('draft','pending','confirmed','dispatched','delivered','cancelled') NOT NULL DEFAULT 'pending',
    payment_type    ENUM('cash','credit') NOT NULL DEFAULT 'cash',
    amount_total    DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_paid     DECIMAL(14,2) NOT NULL DEFAULT 0,
    efris_ref       VARCHAR(60) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME DEFAULT NULL,
    confirmed_by    INT UNSIGNED DEFAULT NULL,
    delivered_at    DATETIME DEFAULT NULL,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_trip FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_orders_status (status),
    INDEX idx_orders_customer (customer_id),
    INDEX idx_orders_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED DEFAULT NULL,
    qty             INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
    subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_oi_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Edit / cancel requests ────────────────────────────────────────────
CREATE TABLE edit_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    request_type    ENUM('edit','cancel') NOT NULL,
    reason          VARCHAR(255) NOT NULL,
    details         TEXT DEFAULT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED DEFAULT NULL,
    reviewed_at     DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_er_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_er_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_er_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Audit log ─────────────────────────────────────────────────────────
CREATE TABLE audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    table_name      VARCHAR(60) NOT NULL,
    record_id       INT UNSIGNED DEFAULT NULL,
    action          VARCHAR(40) NOT NULL,
    old_values      JSON DEFAULT NULL,
    new_values      JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;
