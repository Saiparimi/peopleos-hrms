<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager']);

function overviewEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

$employees = [];
$errorMessage = '';

/*
 * Use existing tables only. No database changes.
 * Actual attendance is shown when an attendance entry exists.
 * Approved leave is shown when there is no attendance entry.
 */
$sql = "
    SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        a.status AS attendance_status,
        CASE WHEN EXISTS (
            SELECT 1
            FROM leave_requests l
            WHERE l.employee_id = e.id
              AND l.status = 'Approved'
              AND l.start_date <= ?
              AND l.end_date >= ?
        ) THEN 1 ELSE 0 END AS approved_leave
    FROM employees e
    LEFT JOIN employee_attendance a
        ON a.employee_id = e.id
       AND a.attendance_date = ?
    WHERE e.status = 'Active'
    ORDER BY e.full_name ASC
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $selectedDate, $selectedDate, $selectedDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $attendance = $row['attendance_status'];

        if ($attendance !== null && $attendance !== '') {
            $row['overview_status'] = $attendance;
        } elseif ((int)$row['approved_leave'] === 1) {
            $row['overview_status'] = 'On approved leave';
        } else {
            $row['overview_status'] = 'Not marked';
        }

        $employees[] = $row;
    }

    $stmt->close();
} catch (Throwable $exception) {
    error_log('Attendance overview: ' . $exception->getMessage());
    $errorMessage = 'Could not load attendance. Please check the database connection and table structure.';
}

$counts = [
    'Present' => 0,
    'Absent' => 0,
    'Half Day' => 0,
    'On approved leave' => 0,
    'Not marked' => 0
];

foreach ($employees as $employee) {
    $status = $employee['overview_status'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$totalEmployees = count($employees);
$markedCount = $counts['Present'] + $counts['Absent'] + $counts['Half Day'];
$attendanceRate = $totalEmployees > 0
    ? round(($counts['Present'] + ($counts['Half Day'] * 0.5)) / $totalEmployees * 100, 1)
    : 0;

$filter = $_GET['status'] ?? 'All';
$allowedFilters = [
    'All', 'Present', 'Absent', 'Half Day',
    'On approved leave', 'Not marked'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

$visibleEmployees = array_values(array_filter(
    $employees,
    static function ($employee) use ($filter) {
        return $filter === 'All' || $employee['overview_status'] === $filter;
    }
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#312e81">
    <title>Daily Attendance Overview | PeopleOS</title>
    <link rel="stylesheet" href="assets.css">
    <style>
        .attendance-cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin: 20px 0;
        }
        .attendance-card, .attendance-panel {
            background: var(--card, #fff);
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 15px;
            padding: 18px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, .04);
        }
        .attendance-card h3 {
            color: var(--muted, #64748b);
            font-size: 12px;
            margin: 0 0 10px;
        }
        .attendance-number {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
            color: #4338ca;
        }
        .attendance-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 16px 0;
        }
        .attendance-filter a {
            padding: 8px 11px;
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 8px;
            text-decoration: none;
            color: #334155;
            background: #fff;
            font-size: 12px;
        }
        .attendance-filter a.active {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #fff;
        }
        .status-label {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            background: #e2e8f0;
            color: #334155;
        }
        .status-present { background: #dcfce7; color: #166534; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-half { background: #fef3c7; color: #92400e; }
        .status-leave { background: #dbeafe; color: #1d4ed8; }
        .status-unmarked { background: #e2e8f0; color: #475569; }
        .date-form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 12px;
        }
        .date-form label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .date-form input {
            padding: 10px;
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 8px;
            background: #fff;
        }
        .table-wrap { overflow-x: auto; }
        .table-wrap table { min-width: 680px; width: 100%; }
        .table-wrap td, .table-wrap th { padding: 12px 10px; }
        .overview-note {
            color: var(--muted, #64748b);
            font-size: 12px;
            line-height: 1.6;
        }
        @media (max-width: 760px) {
            .attendance-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 440px) {
            .attendance-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
    <h1>PeopleOS <span style="font-size:12px">HRMS</span></h1>
    <p>Daily attendance and workforce overview</p>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>
    <div class="page-head">
        <div>
            <h2>Daily Attendance Overview</h2>
            <p>Review attendance status for every active employee.</p>
        </div>
        <a class="btn" href="attendance.php">Mark attendance →</a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <section class="attendance-panel">
            <p><?= overviewEscape($errorMessage) ?></p>
        </section>
    <?php else: ?>

    <section class="attendance-panel">
        <form class="date-form" method="get" action="attendance_overview.php">
            <div>
                <label for="date">Attendance date</label>
                <input type="date" id="date" name="date"
                       value="<?= overviewEscape($selectedDate) ?>" required>
            </div>
            <input type="hidden" name="status" value="<?= overviewEscape($filter) ?>">
            <button class="btn" type="submit">View date</button>
            <a class="btn" href="attendance_overview.php">Today</a>
        </form>
    </section>

    <section class="attendance-cards">
        <div class="attendance-card">
            <h3>Active employees</h3>
            <p class="attendance-number"><?= $totalEmployees ?></p>
        </div>
        <div class="attendance-card">
            <h3>Present</h3>
            <p class="attendance-number"><?= $counts['Present'] ?></p>
        </div>
        <div class="attendance-card">
            <h3>Absent</h3>
            <p class="attendance-number"><?= $counts['Absent'] ?></p>
        </div>
        <div class="attendance-card">
            <h3>Half day</h3>
            <p class="attendance-number"><?= $counts['Half Day'] ?></p>
        </div>
        <div class="attendance-card">
            <h3>On approved leave</h3>
            <p class="attendance-number"><?= $counts['On approved leave'] ?></p>
        </div>
        <div class="attendance-card">
            <h3>Not marked</h3>
            <p class="attendance-number"><?= $counts['Not marked'] ?></p>
        </div>
    </section>

    <section class="attendance-panel">
        <div class="page-head">
            <div>
                <h3>Employee attendance register</h3>
                <p><?= overviewEscape(date('d M Y', strtotime($selectedDate))) ?>
                    · <?= count($visibleEmployees) ?> employee(s) displayed</p>
            </div>
            <div>
                <button class="btn" type="button" onclick="window.print()">Print</button>
            </div>
        </div>

        <div class="attendance-filter">
            <?php foreach ($allowedFilters as $option): ?>
                <a class="<?= $filter === $option ? 'active' : '' ?>"
                   href="attendance_overview.php?date=<?= urlencode($selectedDate) ?>&amp;status=<?= urlencode($option) ?>">
                    <?= overviewEscape($option) ?>
                    (<?= $counts[$option] ?? $totalEmployees ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Attendance status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($visibleEmployees as $employee): ?>
                    <?php
                    $status = $employee['overview_status'];
                    $class = 'status-unmarked';

                    if ($status === 'Present') $class = 'status-present';
                    elseif ($status === 'Absent') $class = 'status-absent';
                    elseif ($status === 'Half Day') $class = 'status-half';
                    elseif ($status === 'On approved leave') $class = 'status-leave';
                    ?>
                    <tr>
                        <td>
                            <strong><?= overviewEscape($employee['full_name']) ?></strong><br>
                            <small><?= overviewEscape($employee['employee_code']) ?></small>
                        </td>
                        <td><?= overviewEscape($employee['department']) ?></td>
                        <td><?= overviewEscape($employee['designation']) ?></td>
                        <td>
                            <span class="status-label <?= $class ?>">
                                <?= overviewEscape($status) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$visibleEmployees): ?>
                    <tr>
                        <td colspan="4">No employees match this filter.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="overview-note">
            “Not marked” means no attendance entry exists for the selected date and no approved leave
            covers that date. It does not automatically mean Absent. Attendance rate:
            <?= $attendanceRate ?>% (Present counts as 1 and Half Day as 0.5, divided by all active employees).
        </p>
    </section>
    <?php endif; ?>

    <footer style="text-align:center;color:var(--muted);padding:25px">
        PeopleOS HRMS · Daily attendance overview · <?= date('Y') ?>
    </footer>
</main>
</body>
</html>