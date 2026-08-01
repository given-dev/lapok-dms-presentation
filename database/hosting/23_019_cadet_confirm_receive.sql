-- LAPOK DMS - cadet confirms receipt of dispatch before going on route.
-- Trip status flows: dispatched -> on_route (cadet confirm) -> returned.
-- acknowledged_at records when the cadet confirmed the load and left the depot.

ALTER TABLE delivery_trips
    ADD COLUMN acknowledged_at DATETIME DEFAULT NULL AFTER dispatched_at;
