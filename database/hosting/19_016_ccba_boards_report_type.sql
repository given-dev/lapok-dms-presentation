-- Outpost DMS â€” add CCBA boards companion PDF report type
-- Run: mysql -u root lapok_dms < database/migrations/016_ccba_boards_report_type.sql


ALTER TABLE report_packets
  MODIFY COLUMN report_type
  ENUM('field_eod', 'accountant_pack', 'manager_brief', 'ccba_boards', 'uploaded') NOT NULL;
