<?php
declare(strict_types=1);

/**
 * Daily deadline reminders:
 * - Cadets: submit sales report before 19:00
 * - RDC (accountant): finish balancing (+ pack) before 19:00
 * - Manager: submit executive brief before 20:00
 *
 * Safe to call often — sends at most one notification per person per deadline key per day.
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/cadet_reports.php';

function deadline_tz(): DateTimeZone
{
    try {
        return new DateTimeZone(date_default_timezone_get() ?: 'Africa/Kampala');
    } catch (Throwable) {
        return new DateTimeZone('Africa/Kampala');
    }
}

function deadline_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', deadline_tz());
}

/** Minutes since local midnight */
function deadline_minutes_now(?DateTimeImmutable $now = null): int
{
    $now ??= deadline_now();
    return ((int) $now->format('G')) * 60 + (int) $now->format('i');
}

function deadline_hm(int $hour, int $minute = 0): int
{
    return $hour * 60 + $minute;
}

/**
 * @return array{date: string, minute: int, windows: array<string, mixed>}
 */
function deadline_schedule(?DateTimeImmutable $now = null): array
{
    $now ??= deadline_now();
    return [
        'date' => $now->format('Y-m-d'),
        'minute' => deadline_minutes_now($now),
        'windows' => [
            'cadet' => [
                'label' => 'Cadet sales report',
                'due' => deadline_hm(19, 0),
                'warn_from' => deadline_hm(16, 30),
                'urgent_from' => deadline_hm(18, 0),
            ],
            'rdc' => [
                'label' => 'RDC daily close',
                'due' => deadline_hm(19, 0),
                'warn_from' => deadline_hm(16, 30),
                'urgent_from' => deadline_hm(18, 0),
            ],
            'manager' => [
                'label' => 'Manager executive brief',
                'due' => deadline_hm(20, 0),
                'warn_from' => deadline_hm(18, 0),
                'urgent_from' => deadline_hm(19, 0),
            ],
        ],
    ];
}

function deadline_phase(int $minute, int $warnFrom, int $urgentFrom, int $due): string
{
    if ($minute < $warnFrom) {
        return 'quiet';
    }
    if ($minute < $urgentFrom) {
        return 'warn';
    }
    if ($minute < $due) {
        return 'urgent';
    }
    return 'overdue';
}

function deadline_format_clock(int $hm): string
{
    $h = intdiv($hm, 60);
    $m = $hm % 60;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

function deadline_already_sent_today(int $recipientId, string $key, string $date): bool
{
    $titlePrefix = '[Deadline]';
    $stmt = db()->prepare(
        "SELECT id FROM user_notifications
         WHERE recipient_id = ?
           AND DATE(created_at) = ?
           AND title LIKE ?
           AND body LIKE ?
         LIMIT 1"
    );
    $stmt->execute([$recipientId, $date, $titlePrefix . '%', '%#' . $key . '#%']);
    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $opts
 */
function deadline_notify(int $recipientId, string $key, string $date, string $title, string $body, array $opts = []): bool
{
    if ($recipientId <= 0 || deadline_already_sent_today($recipientId, $key, $date)) {
        return false;
    }
    $tag = ' #' . $key . '#';
    $id = notify_user($recipientId, '[Deadline] ' . $title, $body . $tag, array_merge([
        'sender_role' => 'system',
        'severity' => $opts['severity'] ?? 'warning',
        'link_page' => $opts['link_page'] ?? null,
    ], $opts));
    return $id !== null;
}

/** Cadets who still need today's report */
function deadline_pending_cadet_ids(string $date): array
{
    return cadet_pending_report_user_ids(db(), $date);
}

function deadline_rdc_sheet_done(string $date): bool
{
    $stmt = db()->prepare('SELECT status FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $stmt->execute([$date]);
    $status = (string) ($stmt->fetchColumn() ?: '');
    return in_array($status, ['submitted', 'under_review', 'approved'], true);
}

function deadline_manager_brief_done(string $date): bool
{
    $stmt = db()->prepare(
        "SELECT id FROM report_packets
         WHERE report_date = ?
           AND from_role = 'manager'
           AND to_role = 'executive'
           AND status IN ('sent','read','acknowledged')
         LIMIT 1"
    );
    $stmt->execute([$date]);
    return (bool) $stmt->fetchColumn();
}

/** @return list<int> */
function deadline_role_user_ids(string $role): array
{
    $stmt = db()->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1');
    $stmt->execute([$role]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/**
 * @return array<string, mixed>
 */
function deadline_status_for_user(array $user, ?DateTimeImmutable $now = null): array
{
    $now ??= deadline_now();
    $sched = deadline_schedule($now);
    $date = $sched['date'];
    $minute = $sched['minute'];
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);

    $banner = null;
    if (in_array($role, ['cadet', 'field_user'], true)) {
        $win = $sched['windows']['cadet'];
        $pending = in_array($userId, deadline_pending_cadet_ids($date), true);
        $submittedToday = cadet_report_submitted_today(db(), $userId, $date);
        $phase = deadline_phase($minute, $win['warn_from'], $win['urgent_from'], $win['due']);
        if ($pending) {
            if ($phase === 'quiet') {
                $phase = 'info';
            }
            $banner = [
                'role' => 'cadet',
                'phase' => $phase,
                'due_label' => deadline_format_clock($win['due']),
                'title' => $phase === 'overdue'
                    ? 'Sales report overdue'
                    : 'Submit today\'s sales before ' . deadline_format_clock($win['due']),
                'body' => $phase === 'overdue'
                    ? 'Deadline was ' . deadline_format_clock($win['due']) . '. Open Today\'s report and submit now.'
                    : 'Daily target: enter sales, cash, and expenses, then submit to RDC before '
                        . deadline_format_clock($win['due']) . '.',
                'link_page' => 'cadet-daily',
                'done' => false,
            ];
        } elseif ($submittedToday) {
            $banner = [
                'role' => 'cadet',
                'phase' => 'done',
                'due_label' => deadline_format_clock($win['due']),
                'title' => 'Today\'s report submitted',
                'body' => 'You are clear for the ' . deadline_format_clock($win['due']) . ' deadline.',
                'link_page' => 'cadet-dashboard',
                'done' => true,
            ];
        }
    } elseif ($role === 'accountant') {
        $win = $sched['windows']['rdc'];
        $done = deadline_rdc_sheet_done($date);
        $phase = deadline_phase($minute, $win['warn_from'], $win['urgent_from'], $win['due']);
        if (!$done) {
            if ($phase === 'quiet') {
                $phase = 'info';
            }
            $banner = [
                'role' => 'rdc',
                'phase' => $phase,
                'due_label' => deadline_format_clock($win['due']),
                'title' => $phase === 'overdue'
                    ? 'Daily close overdue'
                    : 'Finish daily close before ' . deadline_format_clock($win['due']),
                'body' => $phase === 'overdue'
                    ? 'Deadline was ' . deadline_format_clock($win['due']) . '. Complete balancing and submit to the manager.'
                    : 'Daily target: review cadet reports, finish Today\'s close, and send the manager pack before '
                        . deadline_format_clock($win['due']) . '.',
                'link_page' => 'accountant-rdc',
                'done' => false,
            ];
        } elseif ($done) {
            $banner = [
                'role' => 'rdc',
                'phase' => 'done',
                'due_label' => deadline_format_clock($win['due']),
                'title' => 'Daily close submitted',
                'body' => 'Sheet is with the manager. You can still send the pack if needed.',
                'link_page' => 'accountant-rdc-hub',
                'done' => true,
            ];
        }
    } elseif ($role === 'manager') {
        $win = $sched['windows']['manager'];
        $done = deadline_manager_brief_done($date);
        $phase = deadline_phase($minute, $win['warn_from'], $win['urgent_from'], $win['due']);
        if (!$done) {
            if ($phase === 'quiet') {
                $phase = 'info';
            }
            $banner = [
                'role' => 'manager',
                'phase' => $phase,
                'due_label' => deadline_format_clock($win['due']),
                'title' => $phase === 'overdue'
                    ? 'Executive brief overdue'
                    : 'Submit to executive before ' . deadline_format_clock($win['due']),
                'body' => $phase === 'overdue'
                    ? 'Deadline was ' . deadline_format_clock($win['due']) . '. Send today\'s executive brief from PDF reports.'
                    : 'Daily target: review the RDC sheet and submit the executive brief / pack before '
                        . deadline_format_clock($win['due']) . '.',
                'link_page' => 'report-exchange',
                'done' => false,
            ];
        } elseif ($done) {
            $banner = [
                'role' => 'manager',
                'phase' => 'done',
                'due_label' => deadline_format_clock($win['due']),
                'title' => 'Executive brief sent',
                'body' => 'Today\'s leadership pack is out.',
                'link_page' => 'report-exchange',
                'done' => true,
            ];
        }
    }

    return [
        'date' => $date,
        'server_time' => $now->format('H:i'),
        'deadlines' => [
            'cadet_due' => deadline_format_clock($sched['windows']['cadet']['due']),
            'rdc_due' => deadline_format_clock($sched['windows']['rdc']['due']),
            'manager_due' => deadline_format_clock($sched['windows']['manager']['due']),
        ],
        'banner' => $banner,
    ];
}

/**
 * Send due reminder notifications (idempotent per day).
 *
 * @return array<string, mixed>
 */
function deadline_reminders_run(?DateTimeImmutable $now = null): array
{
    $now ??= deadline_now();
    $sched = deadline_schedule($now);
    $date = $sched['date'];
    $minute = $sched['minute'];
    $sent = ['cadet' => 0, 'rdc' => 0, 'manager' => 0];

    // Cadets
    $cWin = $sched['windows']['cadet'];
    $cPhase = deadline_phase($minute, $cWin['warn_from'], $cWin['urgent_from'], $cWin['due']);
    if ($cPhase !== 'quiet') {
        $sev = $cPhase === 'overdue' ? 'danger' : ($cPhase === 'urgent' ? 'warning' : 'info');
        $title = $cPhase === 'overdue'
            ? 'Sales report overdue — submit now'
            : 'Submit sales before ' . deadline_format_clock($cWin['due']);
        $body = $cPhase === 'overdue'
            ? 'Today\'s cadet report deadline (' . deadline_format_clock($cWin['due']) . ') has passed. Open Today\'s report and submit.'
            : 'Daily reminder: enter today\'s sales, cash, and expenses and submit to RDC before '
                . deadline_format_clock($cWin['due']) . '.';
        foreach (deadline_pending_cadet_ids($date) as $cid) {
            if (deadline_notify($cid, 'cadet_sales_' . $date . '_' . $cPhase, $date, $title, $body, [
                'severity' => $sev,
                'link_page' => 'cadet-daily',
            ])) {
                $sent['cadet']++;
            }
        }
    }

    // RDC / accountant
    $rWin = $sched['windows']['rdc'];
    $rPhase = deadline_phase($minute, $rWin['warn_from'], $rWin['urgent_from'], $rWin['due']);
    if ($rPhase !== 'quiet' && !deadline_rdc_sheet_done($date)) {
        $sev = $rPhase === 'overdue' ? 'danger' : ($rPhase === 'urgent' ? 'warning' : 'info');
        $title = $rPhase === 'overdue'
            ? 'Daily close overdue — finish now'
            : 'Finish daily close before ' . deadline_format_clock($rWin['due']);
        $body = $rPhase === 'overdue'
            ? 'RDC deadline (' . deadline_format_clock($rWin['due']) . ') has passed. Complete balancing and submit to the manager.'
            : 'Daily reminder: review cadet reports, finish Today\'s close, and send the manager pack before '
                . deadline_format_clock($rWin['due']) . '.';
        foreach (deadline_role_user_ids('accountant') as $aid) {
            if (deadline_notify($aid, 'rdc_close_' . $date . '_' . $rPhase, $date, $title, $body, [
                'severity' => $sev,
                'link_page' => 'accountant-rdc',
            ])) {
                $sent['rdc']++;
            }
        }
    }

    // Manager
    $mWin = $sched['windows']['manager'];
    $mPhase = deadline_phase($minute, $mWin['warn_from'], $mWin['urgent_from'], $mWin['due']);
    if ($mPhase !== 'quiet' && !deadline_manager_brief_done($date)) {
        $sev = $mPhase === 'overdue' ? 'danger' : ($mPhase === 'urgent' ? 'warning' : 'info');
        $title = $mPhase === 'overdue'
            ? 'Executive brief overdue — submit now'
            : 'Submit to executive before ' . deadline_format_clock($mWin['due']);
        $body = $mPhase === 'overdue'
            ? 'Manager deadline (' . deadline_format_clock($mWin['due']) . ') has passed. Send today\'s executive brief from PDF reports.'
            : 'Daily reminder: review the RDC sheet and submit the executive brief / pack before '
                . deadline_format_clock($mWin['due']) . '.';
        foreach (deadline_role_user_ids('manager') as $mid) {
            if (deadline_notify($mid, 'mgr_brief_' . $date . '_' . $mPhase, $date, $title, $body, [
                'severity' => $sev,
                'link_page' => 'report-exchange',
            ])) {
                $sent['manager']++;
            }
        }
    }

    return [
        'date' => $date,
        'server_time' => $now->format('H:i'),
        'sent' => $sent,
        'sent_total' => array_sum($sent),
    ];
}
