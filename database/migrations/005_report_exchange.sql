-- LAPOK DMS — PDF report exchange (Field → Accountant → Manager → Executive)
-- Run: mysql -u root lapok_dms < database/migrations/005_report_exchange.sql

USE lapok_dms;

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

-- Demo packets along the reporting chain (PDF files created on first API access if missing)
INSERT INTO report_packets (
    packet_ref, report_type, title, summary, report_date,
    file_path, file_name, file_size,
    from_user_id, from_role, to_role, status, trip_id, sent_at
) VALUES
(
    'RPT-EOD-20260507-001', 'field_eod',
    'EOD — David Ssemuju · TUK-001 · Owino / Katwe',
    'Stock, cash UGX 480,000, returns noted. Awaiting accountant consolidation.',
    '2026-05-07', 'storage/reports/demo-eod-001.pdf', 'EOD_TUK001_20260507.pdf', 0,
    5, 'cadet', 'accountant', 'sent', 1, '2026-05-07 17:05:00'
),
(
    'RPT-ACC-20260507-001', 'accountant_pack',
    'Daily finance consolidation — 07 May 2026',
    'Cash handover, receivables aging, trip variances. Forwarded to Manager.',
    '2026-05-07', 'storage/reports/demo-acc-001.pdf', 'Finance_Pack_20260507.pdf', 0,
    4, 'accountant', 'manager', 'sent', NULL, '2026-05-07 17:45:00'
),
(
    'RPT-MGR-20260507-001', 'manager_brief',
    'Executive operations brief — 07 May 2026',
    'Sales, stock exceptions, fleet status, upward summary for Board.',
    '2026-05-07', 'storage/reports/demo-mgr-001.pdf', 'Executive_Brief_20260507.pdf', 0,
    3, 'manager', 'executive', 'sent', NULL, '2026-05-07 18:30:00'
)
ON DUPLICATE KEY UPDATE title = VALUES(title);
