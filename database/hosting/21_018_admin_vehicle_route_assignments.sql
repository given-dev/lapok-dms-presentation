-- LAPOK DMS - recurring weekly vehicle/cadet/route assignments.
-- Only the main Admin can maintain these rows through the application API.

CREATE TABLE IF NOT EXISTS vehicle_route_assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT UNSIGNED NOT NULL,
    cadet_id        INT UNSIGNED DEFAULT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL COMMENT '1=Monday ... 6=Saturday',
    route_area      VARCHAR(500) NOT NULL,
    updated_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_assignment_day (vehicle_id, day_of_week),
    KEY idx_assignment_cadet (cadet_id),
    CONSTRAINT fk_assignment_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_cadet FOREIGN KEY (cadet_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vehicle_route_assignments (vehicle_id, cadet_id, day_of_week, route_area)
SELECT v.id, v.cadet_id, d.day_no, d.route_area
FROM vehicles v
JOIN (
    SELECT 'KCA 201T' registration, 1 day_no, 'Kampala-Gulu Hwy, Acholi Rd & Keyo Rd' route_area UNION ALL
    SELECT 'KCA 201T', 2, 'Jomo Kenyatta Rd-Moroto Rd, Keyo Rd' UNION ALL
    SELECT 'KCA 201T', 3, 'Laroo, Keyo Rd' UNION ALL
    SELECT 'KCA 201T', 4, 'Kampala-Gulu Hwy, Acholi Rd & Keyo Rd' UNION ALL
    SELECT 'KCA 201T', 5, 'Jomo Kenyatta Rd-Moroto Rd, Keyo Rd' UNION ALL
    SELECT 'KCA 201T', 6, 'Kampala-Gulu Hwy, Acholi Rd, Moroto Rd & Laroo' UNION ALL

    SELECT 'TUK-001', 1, 'Muroni Rd, Tank Rd, Layibi Centre, Ring Rd, Commercial Rd, Phillip Taner, Acholi Lane & Round Point' UNION ALL
    SELECT 'TUK-001', 2, 'Tegwana, Aywec, Alex Ojera Rd, Pop Star & Adonga Rd' UNION ALL
    SELECT 'TUK-001', 3, 'Acholi Lane, Labor Line, Commercial Rd, School Rd' UNION ALL
    SELECT 'TUK-001', 4, 'Ring Rd, Muroni Rd, Alex Ojera Rd, Phillip Taner, Mama Cave & Cubu' UNION ALL
    SELECT 'TUK-001', 5, 'Layibi Centre, Aywec and Round Point' UNION ALL
    SELECT 'TUK-001', 6, 'Commercial Rd, Layibi, Acholi Lane, School Rd & Pop Star' UNION ALL

    SELECT 'TUK-002', 1, 'Main Street-Gulu Avenue, Labwor Rd, Main Market, Dr Lucile Rd, Cemetery Rd & Nakesero' UNION ALL
    SELECT 'TUK-002', 2, 'Awich Rd, Queens Avenue, Aliker Street, Senior Quarters, Kabedopong' UNION ALL
    SELECT 'TUK-002', 3, 'Layibi Corner, Koro Rom & Koro Pida, Cemetery Rd & Nakesero' UNION ALL
    SELECT 'TUK-002', 4, 'Labwor Rd, Main Market, Dr Lucile Rd, Senior Quarters' UNION ALL
    SELECT 'TUK-002', 5, 'Awich Rd, Queens Avenue, Aliker Street, Bank Lane & Kabedopong' UNION ALL
    SELECT 'TUK-002', 6, 'Main Street-Gulu Avenue, Cemetery Rd & Senior Quarters' UNION ALL

    SELECT 'KCB 774Y', 1, 'Kitgum Rd, Awach' UNION ALL
    SELECT 'KCB 774Y', 2, 'Acet-Awere' UNION ALL
    SELECT 'KCB 774Y', 3, 'Palaro' UNION ALL
    SELECT 'KCB 774Y', 4, 'Opit' UNION ALL
    SELECT 'KCB 774Y', 5, 'Bobi-Minakulu' UNION ALL
    SELECT 'KCB 774Y', 6, ''
) d ON d.registration = v.registration
ON DUPLICATE KEY UPDATE route_area = VALUES(route_area), cadet_id = COALESCE(vehicle_route_assignments.cadet_id, VALUES(cadet_id));
