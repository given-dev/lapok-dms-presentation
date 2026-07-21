-- Outpost DMS â€” RDC review comment threads (manager â†” accountant notes per sheet)
-- Run: Get-Content database\migrations\014_rdc_review_comments.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms

CREATE TABLE IF NOT EXISTS rdc_sheet_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    balance_date DATE NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body VARCHAR(1000) NOT NULL,
    action_tag VARCHAR(32) NULL COMMENT 'approve|reject|reopen|start_review|comment',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rdc_comments_date (balance_date, created_at),
    CONSTRAINT fk_rdc_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
