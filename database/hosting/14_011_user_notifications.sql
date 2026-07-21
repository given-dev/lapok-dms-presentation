-- LAPOK DMS â€” In-app notifications (cadets receive from manager / RDC / admin)
-- Run: mysql -u root lapok_dms < database/migrations/011_user_notifications.sql


CREATE TABLE IF NOT EXISTS user_notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id    INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED DEFAULT NULL,
    sender_role     VARCHAR(30) DEFAULT NULL,
    title           VARCHAR(160) NOT NULL,
    body            TEXT NOT NULL,
    severity        ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
    link_page       VARCHAR(60) DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    read_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notif_recipient_unread (recipient_id, is_read, created_at)
) ENGINE=InnoDB;
