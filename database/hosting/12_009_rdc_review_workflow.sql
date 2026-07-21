-- LAPOK DMS â€” RDC review workflow statuses and metadata
-- Run: mysql -u root lapok_dms < database/migrations/009_rdc_review_workflow.sql


ALTER TABLE rdc_daily_sheets
  MODIFY COLUMN status ENUM('draft','submitted','under_review','approved','rejected','reopened')
  NOT NULL DEFAULT 'draft';

ALTER TABLE rdc_daily_sheets
  ADD COLUMN reviewed_by INT UNSIGNED DEFAULT NULL AFTER submitted_by,
  ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER submitted_at,
  ADD COLUMN review_note TEXT DEFAULT NULL AFTER notes;

ALTER TABLE rdc_daily_sheets
  ADD INDEX idx_rdc_review_status (status, balance_date),
  ADD CONSTRAINT fk_rdc_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
