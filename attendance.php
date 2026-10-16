<?php
require_once __DIR__ . "/db.php";
require_once __DIR__.'/auth.php';
require_roles(['Admin', 'HR', 'Manager']);
csrf_verify();

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$message = '';
$messageType = 'success';

// Validate the selected date.
$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $selectedDate
) {
    $selectedDate = date('Y-m-d');
}

// Save attendance.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = filter_input(
        INPUT_POST,
        'employee_id',
        FILTER_VALIDATE_INT
    );

    $attendanceDate = $_POST['attendance_date'] ?? '';
    $status = $_POST['status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    $validStatuses = ['Present', 'Absent', 'Half Day'];

    $postDate = DateTime::createFromFormat(
        '!Y-m-d',
        $attendanceDate
    );

    if (
        !$employeeId ||
        !$postDate ||
        $postDate->format('Y-m-d') !== $attendanceDate ||
        !in_array($status, $validStatuses, true) ||
        strlen($remarks) > 255
    ) {
        $message = 'Invalid input. Please check all fields.';
        $messageType = 'error';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO employee_attendance
                (employee_id, attendance_date, status, remarks)
             SELECT id, ?, ?, ?
             FROM employees
             WHERE id = ? AND status = 'Active'
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                remarks = VALUES(remarks)"
        );

        if (!$stmt) {
            error_log("Attendance prepare failed: " . $conn->error);
            $message = 'Unable to prepare attendance record.';
            $messageType = 'error';
        } else {
            $stmt->bind_param(
                "sssi",
                $attendanceDate,
                $status,
                $remarks,
                $employeeId
            );

            if ($stmt->execute() && $stmt->affected_rows >= 0) {
                if ($stmt->affected_rows === 0) {
                    $message = 'No active employee was found.';
                    $messageType = 'error';
                } else {
                    header(
                        'Location: attendance.php?date=' .
                        urlencode($attendanceDate) .
                        '&saved=1'
                    );
                    exit;
                }
            } else {
                error_log(
                    "Attendance save failed: " . $stmt->error
                );
                $message = 'Unable to save attendance.';
                $messageType = 'error';
            }

            $stmt->close();
        }
    }

    if (
        isset($attendanceDate) &&
        isset($postDate) &&
        $postDate instanceof DateTime &&
        $postDate->format('Y-m-d') === $attendanceDate
    ) {
        $selectedDate = $attendanceDate;
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'Attendance saved successfully!';
    $messageType = 'success';
}

// Load active employees.
$employees = $conn->query(
    "SELECT id, employee_code, full_name
     FROM employees
     WHERE status = 'Active'
     ORDER BY full_name"
);

if (!$employees) {
    error_log("Employee list failed: " . $conn->error);
    http_response_code(500);
    exit('Unable to load employees.');
}

// Load attendance for the selected date.
$stmt = $conn->prepare(
    "SELECT ea.id, ea.employee_id, ea.attendance_date,
            ea.status, ea.remarks,
            e.employee_code, e.full_name
     FROM employee_attendance ea
     INNER JOIN employees e ON e.id = ea.employee_id
     WHERE ea.attendance_date = ?
     ORDER BY e.full_name"
);

if (!$stmt) {
    error_log("Attendance query failed: " . $conn->error);
    http_response_code(500);
    exit('Unable to load attendance.');
}

$stmt->bind_param("s", $selectedDate);
$stmt->execute();

$attendanceResult = $stmt->get_result();

$records = [];
$counts = [
    'Present' => 0,
    'Absent' => 0,
    'Half Day' => 0
];

while ($row = $attendanceResult->fetch_assoc()) {
    $records[] = $row;
    $counts[$row['status']]++;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Attendance | HRMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        header {
            background: #172554;
            color: white;
            padding: 22px 30px;
        }

        header h1 {
            margin: 0 0 8px;
        }

        header p {
            margin: 0;
            color: #dbeafe;
        }

        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 10px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px #0000000d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }

        textarea {
            resize: vertical;
        }

        .btn {
            display: inline-block;
            padding: 11px 16px;
            background: #1d4ed8;
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            background: #1e40af;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .message {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat {
            background: white;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 3px 12px #0000000d;
        }

        .stat strong {
            display: block;
            font-size: 26px;
            margin-top: 8px;
        }

        .table-box {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #eaf0ff;
        }

        .empty {
            text-align: center;
            color: #64748b;
            padding: 25px;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            header {
                padding: 20px;
            }

            main {
                padding: 0 12px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header>
    <h1>HRMS — Attendance</h1>
    <p>Daily Employee Attendance Management</p>
</header>
<?php include 'nav.php'; ?>

<main>

    <div class="actions" style="margin: 0 0 20px;">
        <a class="btn" href="index.php">Dashboard</a>
        <a class="btn" href="employees.php">Employees</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            Present
            <strong><?= $counts['Present'] ?></strong>
        </div>

        <div class="stat">
            Absent
            <strong><?= $counts['Absent'] ?></strong>
        </div>

        <div class="stat">
            Half Day
            <strong><?= $counts['Half Day'] ?></strong>
        </div>
    </section>

    <section class="card">
        <h2>Mark Attendance</h2>

        <?php if ($employees->num_rows > 0): ?>

            <form method="POST" action="attendance.php"><?=csrf_field()?>

                <div class="form-grid">

                    <div>
                        <label for="employee_id">Employee</label>

                        <select id="employee_id"
                                name="employee_id" required>
                            <option value="">Select employee</option>

                            <?php
                            $employees->data_seek(0);
                            while ($employee = $employees->fetch_assoc()):
                            ?>
                                <option value="<?= (int)$employee['id'] ?>">
                                    <?= e($employee['employee_code']) ?>
                                    — <?= e($employee['full_name']) ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div>
                        <label for="attendance_date">Date</label>

                        <input type="date"
                               id="attendance_date"
                               name="attendance_date"
                               value="<?= e($selectedDate) ?>"
                               required>
                    </div>

                    <div>
                        <label for="status">Status</label>

                        <select id="status" name="status" required>
                            <option value="Present">Present</option>
                            <option value="Absent">Absent</option>
                            <option value="Half Day">Half Day</option>
                        </select>
                    </div>

                    <div>
                        <label for="remarks">Remarks (optional)</label>

                        <textarea id="remarks"
                                  name="remarks"
                                  maxlength="255"
                                  rows="2"
                                  placeholder="Optional notes"></textarea>
                    </div>

                </div>

                <div class="actions">
                    <button class="btn" type="submit">
                        Save Attendance
                    </button>
                </div>

            </form>

        <?php else: ?>

            <p>No active employees found. Register an employee first.</p>

        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Attendance Report</h2>

        <form method="GET" action="attendance.php">
            <div class="form-grid">
                <div>
                    <label for="report_date">View attendance by date</label>

                    <input type="date"
                           id="report_date"
                           name="date"
                           value="<?= e($selectedDate) ?>"
                           required>
                </div>

                <div style="align-self: end;">
                    <button class="btn" type="submit">
                        View Report
                    </button>
                </div>
            </div>
        </form>

        <p>
            <strong>Selected date:</strong>
            <?= e($selectedDate) ?>
        </p>

        <div class="table-box">
            <table>
                <thead>
                    <tr>
                        <th>Employee Code</th>
                        <th>Employee Name</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($records) > 0): ?>

                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= e($record['employee_code']) ?></td>
                                <td><?= e($record['full_name']) ?></td>
                                <td><?= e($record['attendance_date']) ?></td>
                                <td><?= e($record['status']) ?></td>
                                <td><?= e($record['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No attendance records for this date.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>