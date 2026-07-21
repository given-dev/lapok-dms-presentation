-- LAPOK DMS â€” PDF report exchange (Field â†’ Accountant â†’ Manager â†’ Executive)
-- Run: mysql -u root lapok_dms < database/migrations/005_report_exchange.sql


CREATE TABLE IF NOT EXISTS report_packets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    packet_ref          VARCHAR(40) NOT NULL UNIQUE,
    report_type         ENUM('field_eod', 'accountant_pack', 'manager_brief', 'uploaded') NOT NULL,
    title               VARCHAR(200) NOT NULL,
    summary             TEXT DEFAULT NULL,
    report_date         DATE NOT NULL,
    file_path           VARCHAR(255) NOT NULL,
    file_name           VARCHAR(160) NOT NULL,
    file_size           INT UNSIGNED NOT NULL DEFAULT 0,
    from_user_id        INT UNSIGNED NOT NULL,
    from_role           VARCHAR(30) NOT NULL,
    to_role             ENUM('accountant', 'manager', 'executive') NOT NULL,
    status              ENUM('sent', 'read', 'acknowledged') NOT NULL DEFAULT 'sent',
    parent_packet_id    INT UNSIGNED DEFAULT NULL COMMENT 'Source packet when forwarded',
    trip_id             INT UNSIGNED DEFAULT NULL,
    sent_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at             DATETIME DEFAULT NULL,
    acknowledged_at     DATETIME DEFAULT NULL,
    acknowledged_by     INT UNSIGNED DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rp_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rp_parent FOREIGN KEY (parent_packet_id) REFERENCES report_packets(id) ON DELETE SET NULL,
    CONSTRAINT fk_rp_trip FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE SET NULL,
    CONSTRAINT fk_rp_ack_user FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rp_to_role (to_role, status, sent_at),
    INDEX idx_rp_from_user (from_user_id, sent_at),
    INDEX idx_rp_report_date (report_date)
) ENGINE=InnoDB;

-- Report packets are created only by live field, accountant, and manager actions.
