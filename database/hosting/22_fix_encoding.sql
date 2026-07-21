-- Fix garbled em-dash encoding in report_packets titles
-- The UTF-8 em dash (U+2014, bytes E2 80 94) was stored as the Windows-1252
-- mojibake sequence "Ã”Ã‡Ã¶" (bytes C3 94 C3 87 C3 B6) on some MySQL setups.
-- This patch replaces both forms with a plain ASCII ' - '.


UPDATE report_packets
SET title = REPLACE(title, 'Ã”Ã‡Ã¶', ' - ')
WHERE title LIKE '%Ã”Ã‡Ã¶%';

-- Also replace any correctly-stored em dashes with ASCII hyphen for consistency
UPDATE report_packets
SET title = REPLACE(title, 'â€”', ' - ')
WHERE title LIKE '%â€”%';

-- Same fix for middle dot (Â·) mojibake
UPDATE report_packets
SET title = REPLACE(title, 'Ã‚Â·', ' - ')
WHERE title LIKE '%Ã‚Â·%';

UPDATE report_packets
SET title = REPLACE(title, 'Â·', ' - ')
WHERE title LIKE '%Â·%';
