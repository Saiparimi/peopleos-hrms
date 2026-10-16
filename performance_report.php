<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager']);

function reportEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Select report month; default to the current month. */
$month = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$date = DateTime::createFromFormat('!Y-m', $month);

if (!$date || $date->format('Y-m') !== $month) {
    $month = date('Y-m');
    $date = new DateTime($month . '-01');
}

$startDate = $date->format('Y-m-01');
$endDate = (clone $date)->modify('first day of next month')->format('Y-m-01');
$periodEnd = (clone $date)->modify('last day of this month')->format('Y-m-d');

$attendanceTarget = 95.0;
$taskTarget = 90.0;

/* Use configured targets when the KPIs exist. */
try {
    $targetQuery = $conn->query(
        "SELECT indicator_name, target_value
         FROM performance_indicators
         WHERE indicator_name IN ('Attendance Rate', 'Task Completion Rate')"
    );

    if ($targetQuery) {
        while ($target = $targetQuery->fetch_assoc()) {
            if ($target['indicator_name'] === 'Attendance Rate') {
                $attendanceTarget = (float)$target['target_value'];
            }

            if ($target['indicator_name'] === 'Task Completion Rate') {
                $taskTarget = (float)$target['target_value'];
            }
        }
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
}

/*
 * Attendance:
 * Present = 1 credited day
 * Half Day = 0.5 credited day
 * Absent = 0 credited days
 *
 * Task completion:
 * Done tasks / tasks created during the selected month.
 * Tasks without an assigned employee are not attributed to an employee.
 */
$sql = "
    SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        e.status AS employee_status,

        COALESCE(a.attendance_entries, 0) AS attendance_entries,
        CASE
            WHEN a.attendance_entries > 0
            THEN ROUND(100 * a.credited_days / a.attendance_entries, 2)
            ELSE NULL
        END AS attendance_rate,

        COALESCE(t.task_count, 0) AS task_count,
        COALESCE(t.completed_tasks, 0) AS completed_tasks,
        CASE
            WHEN t.task_count > 0
            THEN ROUND(100 * t.completed_tasks / t.task_count, 2)
            ELSE NULL
        END AS task_rate

    FROM employees e

    LEFT JOIN (
        SELECT
            employee_id,
            COUNT(*) AS attendance_entries,
            SUM(
                CASE status
                    WHEN 'Present' THEN 1
                    WHEN 'Half Day' THEN 0.5
                    ELSE 0
                END
            ) AS credited_days
        FROM employee_attendance
        WHERE attendance_date >= ?
          AND attendance_date < ?
        GROUP BY employee_id
    ) a ON a.employee_id = e.id

    LEFT JOIN (
        SELECT
            employee_id,
            COUNT(*) AS task_count,
            SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) AS completed_tasks
        FROM hr_tasks
        WHERE created_at >= ?
          AND created_at < ?
          AND employee_id IS NOT NULL
        GROUP BY employee_id
    ) t ON t.employee_id = e.id

    ORDER BY e.full_name ASC
";

$employees = [];

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssss',
        $startDate,
        $endDate,
        $startDate,
        $endDate
    );
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }

    $stmt->close();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Unable to generate the report. Check the PHP error log for details.');
}

/* Export the same report to CSV for Excel. */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="employee-performance-' .
        $month . '.csv"'
    );

    $output = fopen('php://output', 'w');

    // UTF-8 BOM helps Excel open the CSV correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Employee Code',
        'Employee Name',
        'Department',
        'Designation',
        'Employment Status',
        'Attendance Entries',
        'Attendance Rate (%)',
        'Attendance Target (%)',
        'Attendance Status',
        'Tasks Assigned This Month',
        'Tasks Completed',
        'Task Completion Rate (%)',
        'Task Target (%)',
        'Task Status',
        'Report Month'
    ]);

    foreach ($employees as $employee) {
        $attendanceRate = $employee['attendance_rate'];
        $taskRate = $employee['task_rate'];

        fputcsv($output, [
            $employee['employee_code'],
            $employee['full_name'],
            $employee['department'],
            $employee['designation'],
            $employee['employee_status'],
            $employee['attendance_entries'],
            $attendanceRate === null ? 'No data' : $attendanceRate,
            $attendanceTarget,
            $attendanceRate === null
                ? 'No data'
                : ((float)$attendanceRate >= $attendanceTarget
                    ? 'Meeting target' : 'Below target'),
            $employee['task_count'],
            $employee['completed_tasks'],
            $taskRate === null ? 'No data' : $taskRate,
            $taskTarget,
            $taskRate === null
                ? 'No data'
                : ((float)$taskRate >= $taskTarget
                    ? 'Meeting target' : 'Below target'),
            $month
        ]);
    }

    fclose($output);
    exit;
}

/* Dashboard summary counts. Missing data is not counted as a failure. */
$totalEmployees = count($employees);
$attendanceAvailable = 0;
$attendanceMeeting = 0;
$taskAvailable = 0;
$taskMeeting = 0;

foreach ($employees as $employee) {
    if ($employee['attendance_rate'] !== null) {
        $attendanceAvailable++;

        if ((float)$employee['attendance_rate'] >= $attendanceTarget) {
            $attendanceMeeting++;
        }
    }

    if ($employee['task_rate'] !== null) {
        $taskAvailable++;

        if ((float)$employee['task_rate'] >= $taskTarget) {
            $taskMeeting++;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Performance Report | PeopleOS HRMS</title>
    <link rel="stylesheet" href="assets.css">

    <style>
        .report-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .report-filters {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .report-filters label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .report-filters input {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            font: inherit;
        }

        .report-table {
            min-width: 1050px;
        }

        .report-table td,
        .report-table th {
            white-space: nowrap;
        }

        .report-table td:first-child {
            white-space: normal;
            min-width: 170px;
        }

        .status-good {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-low {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-empty {
            display: inline-block;
            background: #e5e7eb;
            color: #374151;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
        }

        .report-note {
            font-size: 13px;
            line-height: 1.6;
            color: var(--muted);
        }

        @media (max-width: 850px) {
            .report-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 500px) {
            .report-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<header>
    <h1>PeopleOS <span style="font-size:12px">HRMS</span></h1>
    <p>Management indicators and employee performance</p>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>
    <div class="page-head">
        <div>
            <h2>All-Employee Performance Report</h2>
            <p>
                <?= reportEscape(date('F Y', strtotime($startDate))) ?>
                · Attendance and task completion
            </p>
        </div>

        <a class="btn" href="performance.php">Performance setup →</a>
    </div>

    <section class="panel">
        <form method="get" class="report-filters">
            <div>
                <label for="month">Report month</label>
                <input
                    type="month"
                    id="month"
                    name="month"
                    value="<?= reportEscape($month) ?>"
                    required
                >
            </div>

            <button class="btn" type="submit">Generate report</button>

            <a class="btn"
               href="performance_report.php?month=<?= reportEscape($month) ?>&export=csv">
                Export CSV
            </a>
        </form>
    </section>

    <section class="report-cards">
        <div class="card">
            <h3>Total employees</h3>
            <p class="number"><?= $totalEmployees ?></p>
            <p class="muted">All employee records</p>
        </div>

        <div class="card">
            <h3>Attendance data available</h3>
            <p class="number"><?= $attendanceAvailable ?></p>
            <p class="muted">Target: <?= reportEscape($attendanceTarget) ?>%</p>
        </div>

        <div class="card">
            <h3>Attendance meeting target</h3>
            <p class="number"><?= $attendanceMeeting ?></p>
            <p class="muted">Among employees with data</p>
        </div>

        <div class="card">
            <h3>Task completion meeting target</h3>
            <p class="number"><?= $taskMeeting ?></p>
            <p class="muted">
                <?= $taskAvailable ?> employees have task data
            </p>
        </div>
    </section>

    <section class="panel">
        <h3>Employee-by-employee results</h3>
        <p class="report-note">
            Employees without attendance entries or assigned tasks show
            “No data”, not a zero score. Targets are configurable on the
            Performance Setup page.
        </p>

        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Attendance entries</th>
                        <th>Attendance rate</th>
                        <th>Attendance target status</th>
                        <th>Tasks assigned</th>
                        <th>Tasks completed</th>
                        <th>Task completion</th>
                        <th>Task target status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td>
                            <strong><?= reportEscape($employee['full_name']) ?></strong>
                            <br>
                            <small><?= reportEscape($employee['employee_code']) ?></small>
                            <br>
                            <small><?= reportEscape($employee['designation']) ?></small>
                        </td>

                        <td><?= reportEscape($employee['department']) ?></td>
                        <td><?= reportEscape($employee['employee_status']) ?></td>
                        <td><?= (int)$employee['attendance_entries'] ?></td>

                        <td>
                            <?php if ($employee['attendance_rate'] === null): ?>
                                <span class="status-empty">No data</span>
                            <?php else: ?>
                                <?= number_format((float)$employee['attendance_rate'], 2) ?>%
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($employee['attendance_rate'] === null): ?>
                                <span class="status-empty">No data</span>
                            <?php elseif ((float)$employee['attendance_rate'] >= $attendanceTarget): ?>
                                <span class="status-good">Meeting target</span>
                            <?php else: ?>
                                <span class="status-low">Below target</span>
                            <?php endif; ?>
                        </td>

                        <td><?= (int)$employee['task_count'] ?></td>
                        <td><?= (int)$employee['completed_tasks'] ?></td>

                        <td>
                            <?php if ($employee['task_rate'] === null): ?>
                                <span class="status-empty">No data</span>
                            <?php else: ?>
                                <?= number_format((float)$employee['task_rate'], 2) ?>%
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($employee['task_rate'] === null): ?>
                                <span class="status-empty">No data</span>
                            <?php elseif ((float)$employee['task_rate'] >= $taskTarget): ?>
                                <span class="status-good">Meeting target</span>
                            <?php else: ?>
                                <span class="status-low">Below target</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$employees): ?>
                    <tr>
                        <td colspan="10">No employee records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" style="margin-top:20px">
        <h3>How the report is calculated</h3>
        <p class="report-note">
            Attendance rate = credited attendance days divided by recorded
            attendance entries. Present counts as 1, Half Day as 0.5, and
            Absent as 0.
        </p>
        <p class="report-note">
            Task completion = tasks marked Done divided by tasks assigned to
            the employee and created during the selected month. Tasks without
            an assigned employee are excluded.
        </p>
        <p class="report-note">
            This report is based on recorded data, not a full working-day
            calendar. Missing records are displayed as No data. Confirm these
            definitions with management before using the report for formal
            employee evaluations.
        </p>
    </section>

    <footer style="text-align:center;color:var(--muted);padding:25px">
        PeopleOS HRMS · Employee performance report · <?= date('Y') ?>
    </footer>
</main>
</body>
</html>