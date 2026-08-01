-- LAPOK DMS - per-cadet monthly sales targets.
-- sales_targets now carries one row per sales unit:
--   vehicle_id NULL  = DEPOT (depot's own sales column)
--   vehicle_id = N   = the cadet assigned to vehicle N
-- The overall month target = SUM of all rows for the month.
-- The manager feeds these monthly via the "Monthly targets" page.

ALTER TABLE sales_targets
    ADD COLUMN vehicle_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'NULL = DEPOT sales unit; else vehicles.id' AFTER category;

ALTER TABLE sales_targets DROP KEY uq_sales_target;
ALTER TABLE sales_targets ADD UNIQUE KEY uq_sales_target (target_month, category, vehicle_id);

-- Existing rows (previously the single overall figure) now represent the DEPOT row.
UPDATE sales_targets SET vehicle_id = NULL WHERE vehicle_id IS NULL;
