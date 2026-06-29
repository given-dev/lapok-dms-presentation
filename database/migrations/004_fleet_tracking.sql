-- LAPOK DMS — Fleet map tracking (routes, stops, vehicle GPS pings)
-- Run: mysql -u root lapok_dms < database/migrations/004_fleet_tracking.sql

USE lapok_dms;

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 7) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10, 7) NULL AFTER latitude;

ALTER TABLE routes
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 7) NULL COMMENT 'Route centroid / depot area' AFTER zone,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10, 7) NULL AFTER latitude;

CREATE TABLE IF NOT EXISTS vehicle_location_pings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT UNSIGNED NOT NULL,
    trip_id         INT UNSIGNED DEFAULT NULL,
    user_id         INT UNSIGNED DEFAULT NULL COMMENT 'Driver or cadet who sent ping',
    latitude        DECIMAL(10, 7) NOT NULL,
    longitude       DECIMAL(10, 7) NOT NULL,
    accuracy_m      INT UNSIGNED DEFAULT NULL,
    speed_kmh       DECIMAL(6, 2) DEFAULT NULL,
    heading         SMALLINT UNSIGNED DEFAULT NULL,
    source          ENUM('gps', 'manual', 'estimated') NOT NULL DEFAULT 'gps',
    recorded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vlp_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vlp_trip FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE SET NULL,
    CONSTRAINT fk_vlp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vlp_vehicle_time (vehicle_id, recorded_at),
    INDEX idx_vlp_trip (trip_id)
) ENGINE=InnoDB;

-- Gulu / Northern region coordinates for demo map
UPDATE customers SET latitude = 2.7850, longitude = 32.3050 WHERE id = 1;
UPDATE customers SET latitude = 2.7680, longitude = 32.2920 WHERE id = 2;
UPDATE customers SET latitude = 2.7580, longitude = 32.3100 WHERE id = 3;
UPDATE customers SET latitude = 2.7800, longitude = 32.2880 WHERE id = 4;

UPDATE routes SET latitude = 2.7726, longitude = 32.2988 WHERE id = 1;
UPDATE routes SET latitude = 2.7780, longitude = 32.3020 WHERE id = 2;
UPDATE routes SET latitude = 2.7650, longitude = 32.3150 WHERE id = 3;

-- Ensure trucks on route have active trips for map tracking
INSERT INTO delivery_trips (vehicle_id, driver_id, cadet_id, route_id, route_area, status, dispatched_at, odometer_start)
SELECT 1, 6, NULL, 2, 'Kampala Central', 'on_route', NOW(), 120400
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM delivery_trips WHERE vehicle_id = 1 AND status IN ('dispatched', 'on_route')
);

INSERT INTO delivery_trips (vehicle_id, driver_id, cadet_id, route_id, route_area, status, dispatched_at, odometer_start)
SELECT 2, 5, NULL, 3, 'Mukono', 'on_route', NOW(), 98500
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM delivery_trips WHERE vehicle_id = 2 AND status IN ('dispatched', 'on_route')
);

-- Demo GPS pings (recent — manager map shows live positions)
INSERT INTO vehicle_location_pings (vehicle_id, trip_id, user_id, latitude, longitude, source, recorded_at)
SELECT 3, dt.id, 5, 2.7620, 32.3010, 'gps', NOW()
FROM delivery_trips dt
WHERE dt.vehicle_id = 3 AND dt.status IN ('dispatched', 'on_route')
ORDER BY dt.dispatched_at DESC LIMIT 1;

INSERT INTO vehicle_location_pings (vehicle_id, trip_id, user_id, latitude, longitude, source, recorded_at)
SELECT 1, dt.id, 6, 2.7765, 32.2995, 'gps', NOW()
FROM delivery_trips dt
WHERE dt.vehicle_id = 1 AND dt.status IN ('dispatched', 'on_route')
ORDER BY dt.dispatched_at DESC LIMIT 1;

INSERT INTO vehicle_location_pings (vehicle_id, trip_id, user_id, latitude, longitude, source, recorded_at)
SELECT 2, dt.id, 5, 2.7695, 32.3120, 'gps', NOW()
FROM delivery_trips dt
WHERE dt.vehicle_id = 2 AND dt.status IN ('dispatched', 'on_route')
ORDER BY dt.dispatched_at DESC LIMIT 1;

-- Optional: assign customer stops to other routes (run once if trucks show without route lines)
INSERT IGNORE INTO route_stops (route_id, customer_id, stop_order) VALUES
(2, 4, 1), (2, 1, 2),
(3, 2, 1), (3, 3, 2);
