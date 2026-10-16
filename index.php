<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager']);

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function countQuery(mysqli $conn, string $sql): int {
    try {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_row();
            return (int)($row[0] ?? 0);
        }
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
    }
    return 0;
}

function tableExists(mysqli $conn, string $table): bool {
    try {
        $safeTable = $conn->real_escape_string($table);
        $result = $conn->query(
            "SHOW TABLES LIKE '{$safeTable}'"
        );
        return $result && $result->num_rows > 0;
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        return false;
    }
}

/* Employee metrics */
$totalEmployees = countQuery(
    $conn,
    "SELECT COUNT(*) FROM employees"
);

$activeEmployees = countQuery(
    $conn,
    "SELECT COUNT(*) FROM employees WHERE status = 'Active'"
);

$presentToday = countQuery(
    $conn,
    "SELECT COUNT(*) FROM employee_attendance
     WHERE attendance_date = CURDATE()
       AND status = 'Present'"
);

$pendingLeaves = countQuery(
    $conn,
    "SELECT COUNT(*) FROM leave_requests
     WHERE status = 'Pending'"
);

$openTasks = countQuery(
    $conn,
    "SELECT COUNT(*) FROM hr_tasks WHERE status <> 'Done'"
);

/* Base salary estimate */
$monthlyPayroll = 0.0;

try {
    $result = $conn->query(
        "SELECT COALESCE(SUM(salary), 0)
         FROM employees WHERE status = 'Active'"
    );

    if ($result) {
        $monthlyPayroll = (float)$result->fetch_row()[0];
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
}

/* Recent employees */
$recent = [];

try {
    $result = $conn->query(
        "SELECT employee_code, full_name, department,
                designation, status
         FROM employees
         ORDER BY id DESC
         LIMIT 5"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent[] = $row;
        }
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
}

/*
 * Performance metrics.
 * Read-only: this dashboard does not create or modify tables.
 */
$configuredKPIs = 0;
$recordedResults = 0;
$resultsMeetingTarget = 0;
$performanceAvailable = false;

if (
    tableExists($conn, 'performance_indicators') &&
    tableExists($conn, 'performance_results')
) {
    $performanceAvailable = true;

    $configuredKPIs = countQuery(
        $conn,
        "SELECT COUNT(*) FROM performance_indicators"
    );

    $recordedResults = countQuery(
        $conn,
        "SELECT COUNT(*) FROM performance_results"
    );

    $resultsMeetingTarget = countQuery(
        $conn,
        "SELECT COUNT(*)
         FROM performance_results pr
         INNER JOIN performance_indicators pi
             ON pi.id = pr.indicator_id
         WHERE pi.target_value > 0
           AND pr.actual_value >= pi.target_value"
    );
}

$performanceUrl = 'performance.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#312e81">
    <title>HRMS | People operations</title>
    <link rel="stylesheet" href="assets.css">

    <style>
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .performance-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .performance-banner p {
            margin-bottom: 0;
        }

        .performance-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .performance-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 600;
        }

        @media (max-width: 800px) {
            .performance-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }

            .performance-banner {
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>
        PeopleOS
        <span style="font-size:12px;font-weight:600;vertical-align:middle;background:#ffffff20;padding:5px 8px;border-radius:7px">
            HRMS
        </span>
    </h1>
    <p>A clearer view of your people, attendance and work.</p>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>

    <div class="page-head">
        <div>
            <h2>Workspace overview</h2>
            <p><?= e(date('l, d F Y')) ?> · Your HR operations at a glance.</p>
        </div>
        <a class="btn" href="add_employee.php">＋ Add employee</a>
    </div>

    <!-- Existing employee and HR metrics -->
    <section class="cards"
        style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px">

        <div class="card">
            <div class="metric-row">
                <h3>Total employees</h3>
                <span class="metric-icon">PE</span>
            </div>
            <p class="number"><?= $totalEmployees ?></p>
            <p class="muted">All employee records</p>
        </div>

        <div class="card">
            <div class="metric-row">
                <h3>Active workforce</h3>
                <span class="metric-icon">✓</span>
            </div>
            <p class="number"><?= $activeEmployees ?></p>
            <p class="muted">Currently active</p>
        </div>

        <div class="card">
            <div class="metric-row">
                <h3>Present today</h3>
                <span class="metric-icon">AT</span>
            </div>
            <p class="number"><?= $presentToday ?></p>
            <p class="muted">Attendance entries marked present</p>
        </div>

        <div class="card">
            <div class="metric-row">
                <h3>Pending leave</h3>
                <span class="metric-icon">LV</span>
            </div>
            <p class="number"><?= $pendingLeaves ?></p>
            <p class="muted">Awaiting review</p>
        </div>

    </section>

    <!-- NEW: Management performance section -->
    <section class="panel" style="margin-bottom:20px">

        <div class="performance-banner">
            <div>
                <h2 style="margin:0 0 8px">Management indicators</h2>
                <p class="muted">
                    Track KPIs, measure results, and review progress against targets.
                </p>
            </div>

            <a class="btn" href="<?= e($performanceUrl) ?>">
                Open performance setup →
            </a>
        </div>

        <?php if (!$performanceAvailable): ?>

            <div class="card">
                <h3>Performance setup needs initialization</h3>
                <p class="muted">
                    The performance tables were not found. Open the Performance
                    page and follow its setup instructions before recording results.
                </p>
                <div class="performance-actions">
                    <a class="btn" href="<?= e($performanceUrl) ?>">
                        Open performance module
                    </a>
                </div>
            </div>

        <?php else: ?>

            <div class="performance-grid">

                <div class="card">
                    <div class="metric-row">
                        <h3>KPIs configured</h3>
                        <span class="metric-icon">KPI</span>
                    </div>
                    <p class="number"><?= $configuredKPIs ?></p>
                    <p class="muted">Indicators defined by management</p>
                </div>

                <div class="card">
                    <div class="metric-row">
                        <h3>Results recorded</h3>
                        <span class="metric-icon">PR</span>
                    </div>
                    <p class="number"><?= $recordedResults ?></p>
                    <p class="muted">Performance results submitted</p>
                </div>

                <div class="card">
                    <div class="metric-row">
                        <h3>Meeting target</h3>
                        <span class="metric-icon">✓</span>
                    </div>
                    <p class="number"><?= $resultsMeetingTarget ?></p>
                    <p class="muted">Results at or above target</p>
                </div>

            </div>

            <span class="performance-status">Performance module connected</span>

            <div class="performance-actions">
                <a href="<?= e($performanceUrl) ?>">Configure indicators →</a>
                <a href="<?= e($performanceUrl) ?>">Record results →</a>
                <a href="<?= e($performanceUrl) ?>">Review performance →</a>
            </div>

        <?php endif; ?>

    </section>

    <!-- Existing employee list and operations snapshot -->
    <section class="grid" style="margin-bottom:20px">

        <div class="panel col-8">
            <div class="page-head">
                <div>
                    <h3>Recently added employees</h3>
                    <p>Latest records in your employee directory.</p>
                </div>
                <a href="employees.php">View all →</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $employee): ?>
                            <tr>
                                <td>
                                    <strong><?= e($employee['full_name']) ?></strong>
                                    <br>
                                    <small><?= e($employee['employee_code']) ?></small>
                                </td>
                                <td><?= e($employee['department']) ?></td>
                                <td><?= e($employee['designation']) ?></td>
                                <td>
                                    <span class="pill"><?= e($employee['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$recent): ?>
                            <tr>
                                <td colspan="4">
                                    No employees yet. Add your first employee to get started.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel col-4">
            <h3>Operations snapshot</h3>

            <div style="display:flex;justify-content:space-between;padding:13px 0;border-bottom:1px solid var(--line)">
                <span class="muted">Open tasks</span>
                <strong><?= $openTasks ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;padding:13px 0;border-bottom:1px solid var(--line)">
                <span class="muted">Monthly base payroll</span>
                <strong>₹<?= number_format($monthlyPayroll, 0) ?></strong>
            </div>

            <p class="muted" style="font-size:12px;line-height:1.6;margin-top:14px">
                Payroll figure is a base-salary estimate, not a statutory payroll calculation.
            </p>
        </div>

    </section>

    <!-- Existing quick actions -->
    <section class="panel">
        <h3>Quick actions</h3>

        <div class="quick-links">
            <a href="employees.php">Employee directory →</a>
            <a href="attendance.php">Mark attendance →</a>
            <a href="leave.php">Review leave requests →</a>
            <a href="payroll.php">Open payroll register →</a>
            <a href="tasks.php">Manage team tasks →</a>
            <a href="performance.php">Management performance →</a>
        </div>
    </section>

    <footer style="text-align:center;color:var(--muted);padding:25px">
        PeopleOS HRMS · Local development build · <?= date('Y') ?>
    </footer>

</main>

</body>
</html>