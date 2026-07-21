-- LAPOK DMS â€” Manager daily OCCD / Inventory boards (whiteboard digitization)
-- Run: mysql -u root lapok_dms < database/migrations/003_occd_daily_boards.sql


CREATE TABLE IF NOT EXISTS manager_daily_boards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_date      DATE NOT NULL,
    board_type      ENUM('inventory_board', 'occd_dashboard') NOT NULL,
    status          ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    payload_json    JSON NOT NULL,
    submitted_by    INT UNSIGNED DEFAULT NULL,
    submitted_at    DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_board_day_type (board_date, board_type),
    CONSTRAINT fk_mdb_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_mdb_date (board_date),
    INDEX idx_mdb_status (status)
) ENGINE=InnoDB;
