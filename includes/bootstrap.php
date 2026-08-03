<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kampala');

require_once dirname(__DIR__) . '/includes/response.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
    ini_set('display_errors', '0');
}

cors_headers();
handle_preflight();

/**
 * [start, exclusive-end) DATETIME bounds covering a single Y-m-d date.
 * Use these with `col >= ? AND col < ?` so the column index is usable
 * (a bare `DATE(col) = ?` predicate forces a full table scan).
 *
 * @return array{0: string, 1: string}
 */
function day_bounds(string $date): array
{
    return [$date . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($date . ' +1 day'))];
}

/**
 * [start, exclusive-end) DATETIME bounds covering an inclusive Y-m-d period.
 *
 * @return array{0: string, 1: string}
 */
function period_bounds(string $from, string $to): array
{
    return [$from . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))];
}
