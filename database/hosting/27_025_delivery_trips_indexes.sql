-- 025: Speed up delivery_trips lookups.
-- The dispatch log, dashboards, exceptions center and reports all filter trips by
-- status and by dispatch/return datetime, but delivery_trips only had the primary
-- key and the FK indexes, so every one of those queries was a full table scan.
-- These indexes (plus the status+returned composite for the day/period report
-- filters) turn them into index range scans. Backed by the range-predicate
-- query rewrites shipped with this change.

ALTER TABLE delivery_trips
    ADD INDEX idx_dt_status (status),
    ADD INDEX idx_dt_dispatched (dispatched_at),
    ADD INDEX idx_dt_returned (returned_at),
    ADD INDEX idx_dt_status_returned (status, returned_at);
