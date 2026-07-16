-- Fix garbled em-dash encoding in report_packets titles
-- The UTF-8 em dash (U+2014, bytes E2 80 94) was stored as the Windows-1252
-- mojibake sequence "ÔÇö" (bytes C3 94 C3 87 C3 B6) on some MySQL setups.
-- This patch replaces both forms with a plain ASCII ' - '.

USE lapok_dms;

UPDATE report_packets
SET title = REPLACE(title, 'ÔÇö', ' - ')
WHERE title LIKE '%ÔÇö%';

-- Also replace any correctly-stored em dashes with ASCII hyphen for consistency
UPDATE report_packets
SET title = REPLACE(title, '—', ' - ')
WHERE title LIKE '%—%';

-- Same fix for middle dot (·) mojibake
UPDATE report_packets
SET title = REPLACE(title, 'Â·', ' - ')
WHERE title LIKE '%Â·%';

UPDATE report_packets
SET title = REPLACE(title, '·', ' - ')
WHERE title LIKE '%·%';
