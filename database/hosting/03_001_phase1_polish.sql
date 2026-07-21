# Phase 1 polish â€” run if you already imported an older schema


ALTER TABLE users
  MODIFY role ENUM('admin','executive','manager','accountant','driver','cadet','field_user') NOT NULL DEFAULT 'field_user';

ALTER TABLE supplier_delivery_items
  ADD COLUMN IF NOT EXISTS batch_number VARCHAR(60) NULL AFTER unit_cost,
  ADD COLUMN IF NOT EXISTS expiry_date DATE NULL AFTER batch_number;

ALTER TABLE dispatches
  ADD COLUMN IF NOT EXISTS driver_id INT UNSIGNED NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS cadet_id INT UNSIGNED NULL AFTER driver_id,
  ADD COLUMN IF NOT EXISTS odometer_start INT UNSIGNED NULL AFTER route_area,
  ADD COLUMN IF NOT EXISTS odometer_end INT UNSIGNED NULL AFTER odometer_start,
  ADD COLUMN IF NOT EXISTS fuel_cost_ugx DECIMAL(12,2) NULL AFTER odometer_end,
  ADD COLUMN IF NOT EXISTS cash_reported DECIMAL(14,2) NULL AFTER fuel_cost_ugx,
  ADD COLUMN IF NOT EXISTS cash_received DECIMAL(14,2) NULL AFTER cash_reported,
  ADD COLUMN IF NOT EXISTS cash_confirmed_by INT UNSIGNED NULL AFTER cash_received,
  ADD COLUMN IF NOT EXISTS cash_confirmed_at TIMESTAMP NULL AFTER cash_confirmed_by,
  ADD COLUMN IF NOT EXISTS trip_notes TEXT NULL AFTER cash_confirmed_at,
  ADD COLUMN IF NOT EXISTS damage_notes TEXT NULL AFTER trip_notes;

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(14,2) NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER amount_paid,
  ADD COLUMN IF NOT EXISTS efris_ref VARCHAR(80) NULL AFTER payment_type;
