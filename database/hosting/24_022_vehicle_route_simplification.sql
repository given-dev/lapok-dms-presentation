-- 022: One route per vehicle (no Mon-Sat grid).
-- Each vehicle follows one particular route every day. The cadet and route
-- live directly on `vehicles`; the legacy per-day `vehicle_route_assignments`
-- table is no longer read by the app.

ALTER TABLE vehicles
    ADD COLUMN route_area VARCHAR(500) NULL DEFAULT NULL AFTER current_route;

-- Backfill the cadet link from the user-side assignment (users.vehicle_id).
UPDATE vehicles v
JOIN users u ON u.vehicle_id = v.id AND u.role IN ('cadet', 'field_user')
SET v.cadet_id = u.id;

-- Backfill routes: prefer any route already recorded, else the vehicle's current route.
UPDATE vehicles v
LEFT JOIN (
    SELECT vehicle_id, MAX(route_area) AS route_area
    FROM vehicle_route_assignments
    WHERE route_area IS NOT NULL AND route_area <> ''
    GROUP BY vehicle_id
) a ON a.vehicle_id = v.id
SET v.route_area = COALESCE(NULLIF(a.route_area, ''), NULLIF(v.current_route, ''), v.route_area)
WHERE v.route_area IS NULL OR v.route_area = '';

-- Give any vehicle still without a route a number label (Route A, Route B, ...).
SET @route_rn = 0;
UPDATE vehicles
SET route_area = CONCAT('Route ', CHAR(64 + (@route_rn := @route_rn + 1)))
WHERE route_area IS NULL OR route_area = ''
ORDER BY id;

-- The legacy weekly table is deprecated and no longer used by the app.
-- Once you have verified dispatch, you can remove it with:
-- DROP TABLE IF EXISTS vehicle_route_assignments;
