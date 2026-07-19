-- Fleet map tracking schema only. Live locations are written by field devices.
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
