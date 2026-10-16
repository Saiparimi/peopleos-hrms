<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager']);

function alertEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function alertCount(mysqli $conn, string $sql): int {
    try {
        $result = $conn->query($sql);
        if ($result) {
            return (int)($result->fetch_row()[0] ?? 0);
        }
    } catch (Throwable $exception) {
        error_log('Alerts count: ' . $exception->getMessage());
    }
    return 0;
}

$today = date('Y-m-d');

$pendingLeaves = alertCount(
    $conn,
    "SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'"
);

$overdueTasks = alertCount(
    $conn,
    "SELECT COUNT(*) FROM hr_tasks
     WHERE status <> 'Done'
       AND due_date IS NOT NULL
       AND due_date < CURDATE()"
);

/*
 * Missing attendance:
 * - Active employees only
 * - Exclude employees who already have an attendance record today
 * - Exclude employees with approved leave covering today
 */
$missingAttendance = [];

$sql = "
    SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.department
    FROM employees e
    WHERE e.status = 'Active'
      AND NOT EXISTS (
          SELECT 1
          FROM employee_attendance a
          WHERE a.employee_id = e.id
            AND a.attendance_date = CURDATE()
      )
      AND NOT EXISTS (
          SELECT 1
          FROM leave_requests l
          WHERE l.employee_id = e.id
            AND l.status = 'Approved'
            AND l.start_date <= CURDATE()
            AND l.end_date >= CURDATE()
      )
    ORDER BY e.full_name
    LIMIT 100
";

try {
    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $missingAttendance[] = $row;
    }
} catch (Throwable $exception) {
    error_log('Missing attendance: ' . $exception->getMessage());
}

$missingAttendanceCount = count($missingAttendance);

/* Load pending leave requests for the alert inbox. */
$leaveAlerts = [];

try {
    $result = $conn->query("
        SELECT
            l.id,
            l.employee_id,
            l.leave_type,
            l.start_date,
            l.end_date,
            l.reason,
            e.full_name,
            e.employee_code,
            e.department
        FROM leave_requests l
        LEFT JOIN employees e ON e.id = l.employee_id
        WHERE l.status = 'Pending'
        ORDER BY l.created_at DESC
        LIMIT 20
    ");

    while ($row = $result->fetch_assoc()) {
        $leaveAlerts[] = $row;
    }
} catch (Throwable $exception) {
    error_log('Leave alerts: ' . $exception->getMessage());
}

/* Load overdue tasks. */
$taskAlerts = [];

try {
    $result = $conn->query("
        SELECT
            t.id,
            t.title,
            t.priority,
            t.due_date,
            t.employee_id,
            e.full_name,
            e.employee_code
        FROM hr_tasks t
        LEFT JOIN employees e ON e.id = t.employee_id
        WHERE t.status <> 'Done'
          AND t.due_date IS NOT NULL
          AND t.due_date < CURDATE()
        ORDER BY t.due_date ASC
        LIMIT 30
    ");

    while ($row = $result->fetch_assoc()) {
        $taskAlerts[] = $row;
    }
} catch (Throwable $exception) {
    error_log('Task alerts: ' . $exception->getMessage());
}

$filter = $_GET['filter'] ?? 'all';

if (!in_array($filter, ['all', 'leave', 'task', 'attendance'], true)) {
    $filter = 'all';
}

$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#312e81">
    <title>Notifications &amp; Alerts | PeopleOS HRMS</title>
    <link rel="stylesheet" href="assets.css">
    <style>
        .alert-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        .alert-stat, .alert-panel {
            background: var(--card, #fff);
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, .04);
        }
        .alert-stat h3 {
            margin: 0 0 12px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted, #64748b);
        }
        .alert-number {
            font-size: 30px;
            font-weight: 800;
            color: #4f46e5;
            margin: 0 0 6px;
        }
        .alert-muted {
            color: var(--muted, #64748b);
            font-size: 13px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 18px 0;
        }
        .filter-link, .alert-action {
            display: inline-block;
            text-decoration: none;
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 9px;
            padding: 9px 13px;
            background: #fff;
            color: #334155;
            font-size: 13px;
        }
        .filter-link.active, .alert-action {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        .alert-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--line, #e5e7eb);
        }
        .alert-item:last-child { border-bottom: 0; }
        .alert-item h4 { margin: 7px 0; font-size: 14px; }
        .alert-item p { margin: 4px 0; }
        .alert-tag {
            display: inline-block;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
            background: #dbeafe;
            color: #1d4ed8;
        }
        .empty-state { padding: 20px 0; color: var(--muted, #64748b); }
        .alert-section { margin-top: 20px; }
        @media (max-width: 760px) {
            .alert-summary { grid-template-columns: 1fr; }
            .alert-item { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>

<header>
    <h1>PeopleOS <span style="font-size:12px">HRMS</span></h1>
    <p>Notifications and operational alerts</p>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>
    <div class="page-head">
        <div>
            <h2>Notifications &amp; Alerts</h2>
            <p>Review items that may need attention. Date:
                <?= alertEscape(date('d F Y')) ?>
            </p>
        </div>
    </div>

    <section class="alert-summary">
        <div class="alert-stat">
            <h3>Pending leave requests</h3>
            <p class="alert-number"><?= count($leaveAlerts) ?></p>
            <p class="alert-muted">Awaiting review</p>
        </div>

        <div class="alert-stat">
            <h3>Overdue tasks</h3>
            <p class="alert-number"><?= count($taskAlerts) ?></p>
            <p class="alert-muted">Not marked Done</p>
        </div>

        <div class="alert-stat">
            <h3>Missing attendance entries</h3>
            <p class="alert-number"><?= $missingAttendanceCount ?></p>
            <p class="alert-muted">Active employees without an entry today, excluding approved leave</p>
        </div>
    </section>

    <section class="alert-panel alert-section">
        <h3>Alert inbox</h3>
        <p class="alert-muted">
            Alerts are calculated from current database records. They are not saved as separate notifications.
        </p>

        <div class="filter-row">
            <a class="filter-link <?= $filter === 'all' ? 'active' : '' ?>" href="alerts.php?filter=all">All</a>
            <a class="filter-link <?= $filter === 'leave' ? 'active' : '' ?>" href="alerts.php?filter=leave">Leave</a>
            <a class="filter-link <?= $filter === 'task' ? 'active' : '' ?>" href="alerts.php?filter=task">Task</a>
            <a class="filter-link <?= $filter === 'attendance' ? 'active' : '' ?>" href="alerts.php?filter=attendance">Attendance</a>
        </div>

        <?php if ($filter === 'all' || $filter === 'leave'): ?>
            <?php foreach ($leaveAlerts as $item): ?>
                <div class="alert-item">
                    <div>
                        <span class="alert-tag">Leave</span>
                        <h4>Leave request awaiting review</h4>
                        <p>
                            <?= alertEscape($item['full_name'] ?? 'Unknown employee') ?>
                            (<?= alertEscape($item['employee_code'] ?? '') ?>)
                            · <?= alertEscape($item['leave_type']) ?>
                        </p>
                        <p class="alert-muted">
                            <?= alertEscape($item['start_date']) ?>
                            to <?= alertEscape($item['end_date']) ?>
                            · <?= alertEscape($item['department'] ?? '') ?>
                        </p>
                    </div>
                    <a class="alert-action" href="leave.php">Review leave →</a>
                </div>
            <?php endforeach; ?>

            <?php if (!$leaveAlerts): ?>
                <p class="empty-state">No pending leave requests.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($filter === 'all' || $filter === 'task'): ?>
            <?php foreach ($taskAlerts as $item): ?>
                <div class="alert-item">
                    <div>
                        <span class="alert-tag">Task</span>
                        <h4><?= alertEscape($item['title']) ?></h4>
                        <p>
                            Assigned to:
                            <?= alertEscape($item['full_name'] ?? 'Unassigned') ?>
                            <?php if (!empty($item['employee_code'])): ?>
                                (<?= alertEscape($item['employee_code']) ?>)
                            <?php endif; ?>
                        </p>
                        <p class="alert-muted">
                            Due <?= alertEscape($item['due_date']) ?>
                            · Priority: <?= alertEscape($item['priority']) ?>
                        </p>
                    </div>
                    <a class="alert-action" href="tasks.php">Check tasks →</a>
                </div>
            <?php endforeach; ?>

            <?php if (!$taskAlerts): ?>
                <p class="empty-state">No overdue tasks.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($filter === 'all' || $filter === 'attendance'): ?>
            <?php foreach ($missingAttendance as $item): ?>
                <div class="alert-item">
                    <div>
                        <span class="alert-tag">Attendance</span>
                        <h4>Attendance not recorded</h4>
                        <p>
                            <?= alertEscape($item['full_name']) ?>
                            (<?= alertEscape($item['employee_code']) ?>)
                            · <?= alertEscape($item['department']) ?>
                        </p>
                    </div>
                    <a class="alert-action" href="attendance.php">Check attendance →</a>
                </div>
            <?php endforeach; ?>

            <?php if (!$missingAttendance): ?>
                <p class="empty-state">
                    No missing attendance entries for employees who are not on approved leave.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="alert-panel alert-section">
        <h3>Important notes</h3>
        <p class="alert-muted">
            Missing attendance means no attendance record exists for today. Employees with approved leave
            covering today are excluded. Check shift schedules, holidays, and attendance policy before taking action.
        </p>
        <p class="alert-muted">
            The inbox shows up to 20 pending leave requests, 30 overdue tasks, and 100 missing-attendance entries.
        </p>
    </section>

    <footer style="text-align:center;color:var(--muted);padding:25px">
        PeopleOS HRMS · Alerts center · <?= alertEscape(date('Y')) ?>
    </footer>
</main>
</body>
</html>