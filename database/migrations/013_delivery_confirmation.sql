-- Outpost DMS — Supplier delivery manager confirmation
-- Run: mysql -u root lapok_dms < database/migrations/013_delivery_confirmation.sql

USE lapok_dms;

ALTER TABLE supplier_deliveries
  ADD COLUMN IF NOT EXISTS confirm_status ENUM('pending_confirm','confirmed','rejected') NOT NULL DEFAULT 'pending_confirm' AFTER notes,
  ADD COLUMN IF NOT EXISTS confirmed_by INT UNSIGNED DEFAULT NULL AFTER confirm_status,
  ADD COLUMN IF NOT EXISTS confirmed_at DATETIME DEFAULT NULL AFTER confirmed_by,
  ADD COLUMN IF NOT EXISTS confirm_note TEXT DEFAULT NULL AFTER confirmed_at;

-- Existing historical deliveries were already accepted into stock — mark them confirmed
UPDATE supplier_deliveries
SET confirm_status = 'confirmed',
    confirmed_at = COALESCE(confirmed_at, created_at)
WHERE confirmed_at IS NULL
  AND confirm_status = 'pending_confirm'
  AND created_at < CURDATE();
