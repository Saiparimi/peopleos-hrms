<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager', 'Employee']);

$user = current_user();
$role = (string) ($user['role'] ?? '');
$userEmployeeId = (int) ($user['employee_id'] ?? 0);

$isReviewer = in_array(
    $role,
    ['Admin', 'HR', 'Manager'],
    true
);

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$msg = '';
$err = '';

/*
|--------------------------------------------------------------------------
| Handle leave submission and review
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    /*
    |--------------------------------------------------------------------------
    | Approve or reject a pending request
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['decision'])) {

        if (!$isReviewer) {
            http_response_code(403);
            exit('You are not authorized to review leave requests.');
        }

        $leaveId = filter_var(
            $_POST['leave_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $decision = (string) ($_POST['decision'] ?? '');

        if (
            $leaveId === false ||
            $leaveId === null ||
            $leaveId < 1 ||
            !in_array($decision, ['Approved', 'Rejected'], true)
        ) {
            $err = 'Invalid leave approval action.';
        } else {
            try {
                $stmt = $conn->prepare(
                    "UPDATE leave_requests
                     SET status = ?
                     WHERE id = ?
                       AND status = 'Pending'"
                );

                $stmt->bind_param(
                    'si',
                    $decision,
                    $leaveId
                );

                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed === 1) {
                    audit_event(
                        $conn,
                        'leave_' . strtolower($decision),
                        'leave',
                        $leaveId
                    );

                    header(
                        'Location: leave.php?decision='
                        . strtolower($decision)
                    );
                    exit;
                }

                $err = 'Request not found or already reviewed.';

            } catch (Throwable $exception) {
                error_log(
                    'HRMS leave review error: '
                    . $exception->getMessage()
                );

                $err = 'Unable to review the request. Check the application log.';
            }
        }

    } else {

        /*
        |--------------------------------------------------------------------------
        | Submit a leave request
        |--------------------------------------------------------------------------
        |
        | Employees: always use their linked employee ID.
        | HR/Admin/Manager: may request leave for themselves or
        | submit a request for another active employee.
        |
        */

        $employeeInput = trim(
            (string) ($_POST['employee_id'] ?? '')
        );

        if ($role === 'Employee') {

            // Never trust an employee ID supplied by the browser.
            $employeeId = $userEmployeeId;

        } elseif ($employeeInput === 'self') {

            // Resolve the signed-in user's own employee profile.
            $employeeId = $userEmployeeId;

        } else {

            // Only authorized non-employee roles reach this branch.
            $employeeId = filter_var(
                $employeeInput,
                FILTER_VALIDATE_INT
            );
        }

        $leaveType = trim(
            (string) ($_POST['leave_type'] ?? '')
        );

        $startDate = trim(
            (string) ($_POST['start_date'] ?? '')
        );

        $endDate = trim(
            (string) ($_POST['end_date'] ?? '')
        );

        $reason = trim(
            (string) ($_POST['reason'] ?? '')
        );

        $allowedTypes = [
            'Casual',
            'Sick',
            'Earned',
            'Unpaid'
        ];

        $start = DateTime::createFromFormat(
            '!Y-m-d',
            $startDate
        );

        $end = DateTime::createFromFormat(
            '!Y-m-d',
            $endDate
        );

        $validStart = $start !== false
            && $start !== null
            && $start->format('Y-m-d') === $startDate;

        $validEnd = $end !== false
            && $end !== null
            && $end->format('Y-m-d') === $endDate;

        $validEmployee = is_int($employeeId)
            && $employeeId > 0;

        if (
            !$validEmployee ||
            !in_array($leaveType, $allowedTypes, true) ||
            !$validStart ||
            !$validEnd ||
            $endDate < $startDate ||
            $reason === '' ||
            strlen($reason) > 500
        ) {
            $err = !$validEmployee
                ? 'Your account has no linked employee profile. Ask an administrator to link your account before requesting leave for yourself.'
                : 'Check the leave type, dates and reason.';

        } else {
            try {
                /*
                 * Confirm the employee is active.
                 */
                $check = $conn->prepare(
                    "SELECT id
                     FROM employees
                     WHERE id = ?
                       AND status = 'Active'
                     LIMIT 1"
                );

                $check->bind_param(
                    'i',
                    $employeeId
                );

                $check->execute();

                $employeeExists =
                    $check->get_result()->num_rows === 1;

                $check->close();

                if (!$employeeExists) {
                    $err = 'The selected employee is inactive or does not exist.';

                } else {

                    /*
                     * Prevent overlapping Pending or Approved requests.
                     * Rejected requests do not block new requests.
                     */
                    $overlap = $conn->prepare(
                        "SELECT id
                         FROM leave_requests
                         WHERE employee_id = ?
                           AND status IN ('Pending', 'Approved')
                           AND start_date <= ?
                           AND end_date >= ?
                         LIMIT 1"
                    );

                    $overlap->bind_param(
                        'iss',
                        $employeeId,
                        $endDate,
                        $startDate
                    );

                    $overlap->execute();

                    $hasOverlap =
                        $overlap->get_result()->num_rows > 0;

                    $overlap->close();

                    if ($hasOverlap) {
                        $err = 'This employee already has a pending or approved leave request for overlapping dates.';

                    } else {

                        /*
                         * The database should default status to Pending.
                         * This matches the existing leave_requests schema.
                         */
                        $stmt = $conn->prepare(
                            "INSERT INTO leave_requests
                                (
                                    employee_id,
                                    leave_type,
                                    start_date,
                                    end_date,
                                    reason
                                )
                             VALUES (?, ?, ?, ?, ?)"
                        );

                        $stmt->bind_param(
                            'issss',
                            $employeeId,
                            $leaveType,
                            $startDate,
                            $endDate,
                            $reason
                        );

                        $stmt->execute();

                        $newLeaveId = (int) $conn->insert_id;

                        $stmt->close();

                        audit_event(
                            $conn,
                            'leave_submitted',
                            'leave',
                            $newLeaveId
                        );

                        header('Location: leave.php?submitted=1');
                        exit;
                    }
                }

            } catch (Throwable $exception) {
                error_log(
                    'HRMS leave submission error: '
                    . $exception->getMessage()
                );

                $err = 'Unable to submit the leave request. Check the application error log.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success messages
|--------------------------------------------------------------------------
*/

if (isset($_GET['submitted'])) {
    $msg = 'Leave request submitted successfully. Status: Pending.';
}

if (
    isset($_GET['decision']) &&
    in_array(
        $_GET['decision'],
        ['approved', 'rejected'],
        true
    )
) {
    $msg = 'Leave request '
        . $_GET['decision']
        . ' successfully.';
}

/*
|--------------------------------------------------------------------------
| Load active employees
|--------------------------------------------------------------------------
*/

$employees = [];

if ($isReviewer) {
    $employeeResult = $conn->query(
        "SELECT id, employee_code, full_name
         FROM employees
         WHERE status = 'Active'
         ORDER BY full_name"
    );

    while ($employee = $employeeResult->fetch_assoc()) {
        $employees[] = $employee;
    }

    $employeeResult->free();
}

/*
|--------------------------------------------------------------------------
| Load recent leave requests
|--------------------------------------------------------------------------
*/

if ($role === 'Employee') {

    if ($userEmployeeId > 0) {
        $stmt = $conn->prepare(
            "SELECT
                l.*,
                e.employee_code,
                e.full_name
             FROM leave_requests AS l
             INNER JOIN employees AS e
                ON e.id = l.employee_id
             WHERE l.employee_id = ?
             ORDER BY l.created_at DESC
             LIMIT 100"
        );

        $stmt->bind_param(
            'i',
            $userEmployeeId
        );

        $stmt->execute();
        $rows = $stmt->get_result();

    } else {
        $rows = false;
    }

} else {

    $rows = $conn->query(
        "SELECT
            l.*,
            e.employee_code,
            e.full_name
         FROM leave_requests AS l
         INNER JOIN employees AS e
            ON e.id = l.employee_id
         ORDER BY l.created_at DESC
         LIMIT 100"
    );
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Leave Management | PeopleOS HRMS</title>

    <link rel="stylesheet" href="assets.css">

    <style>
        .leave-layout {
            display: grid;
            grid-template-columns: minmax(280px, 0.8fr) minmax(0, 1.5fr);
            gap: 24px;
            align-items: start;
        }

        .leave-layout .panel {
            min-width: 0;
        }

        .leave-layout .field {
            margin-bottom: 18px;
        }

        .leave-layout input,
        .leave-layout select,
        .leave-layout textarea {
            width: 100%;
            box-sizing: border-box;
        }

        .leave-layout .btn {
            cursor: pointer;
        }

        .leave-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .leave-actions form {
            margin: 0;
        }

        .leave-actions .btn {
            width: auto;
        }

        .status-approved {
            color: #166534;
            font-weight: 700;
        }

        .status-rejected {
            color: #b91c1c;
            font-weight: 700;
        }

        .status-pending {
            color: #92400e;
            font-weight: 700;
        }

        .leave-notice {
            margin: 12px 0;
            padding: 12px;
            border-radius: 10px;
            background: #eff6ff;
            color: #1e40af;
        }

        .leave-warning {
            margin: 12px 0;
            padding: 12px;
            border-radius: 10px;
            background: #fff7ed;
            color: #9a3412;
        }

        @media (max-width: 850px) {
            .leave-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Leave management</h1>
    <p>Request time off and track approval progress.</p>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>

    <div class="page-head">
        <div>
            <h2>Leave requests</h2>
            <p>Submit leave requests and review their progress.</p>
        </div>

        <span class="pill">Approval workflow</span>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert error">
            <?= e($err) ?>
        </div>
    <?php endif; ?>

    <?php if ($role !== 'Employee' && $userEmployeeId < 1): ?>
        <div class="leave-warning">
            <strong>Personal leave requests are not linked yet.</strong>
            Your login does not have an employee ID. An administrator
            must link your login to your own employee record before
            you can select “Myself”.
            You can still submit requests for other active employees.
        </div>
    <?php endif; ?>

    <div class="leave-layout">

        <!-- =====================================================
             NEW LEAVE REQUEST
        ====================================================== -->

        <section class="panel">

            <h3>New leave request</h3>

            <form method="post">

                <?= csrf_field() ?>

                <?php if ($role === 'Employee'): ?>

                    <input
                        type="hidden"
                        name="employee_id"
                        value="self"
                    >

                    <div class="field">
                        <label>Requesting employee</label>

                        <input
                            type="text"
                            value="Myself"
                            disabled
                        >
                    </div>

                    <p class="muted">
                        Your request will be associated with your
                        linked employee profile.
                    </p>

                <?php else: ?>

                    <div class="field">

                        <label for="employee_id">
                            Request leave for
                        </label>

                        <select
                            id="employee_id"
                            name="employee_id"
                            required
                        >

                            <option value="">
                                Select an employee
                            </option>

                            <?php if ($userEmployeeId > 0): ?>
                                <option value="self">
                                    Myself — my own leave request
                                </option>
                            <?php endif; ?>

                            <option disabled>
                                ── Other employees ──
                            </option>

                            <?php foreach ($employees as $employee): ?>

                                <option
                                    value="<?= (int) $employee['id'] ?>"
                                >
                                    <?= e(
                                        $employee['employee_code']
                                        . ' — '
                                        . $employee['full_name']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <?php if ($userEmployeeId > 0): ?>
                        <div class="leave-notice">
                            Choose <strong>Myself</strong> to request
                            your own leave, or select another employee
                            when submitting a request on their behalf.
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

                <div class="field">

                    <label for="leave_type">
                        Leave type
                    </label>

                    <select
                        id="leave_type"
                        name="leave_type"
                        required
                    >
                        <option value="Casual">Casual</option>
                        <option value="Sick">Sick</option>
                        <option value="Earned">Earned</option>
                        <option value="Unpaid">Unpaid</option>
                    </select>

                </div>

                <div class="field">

                    <label for="start_date">
                        Start date
                    </label>

                    <input
                        id="start_date"
                        type="date"
                        name="start_date"
                        required
                    >

                </div>

                <div class="field">

                    <label for="end_date">
                        End date
                    </label>

                    <input
                        id="end_date"
                        type="date"
                        name="end_date"
                        required
                    >

                </div>

                <div class="field">

                    <label for="reason">
                        Reason for leave
                    </label>

                    <textarea
                        id="reason"
                        name="reason"
                        rows="4"
                        maxlength="500"
                        required
                        placeholder="Enter the reason for your leave..."
                    ></textarea>

                </div>

                <button
                    class="btn"
                    type="submit"
                >
                    Submit leave request
                </button>

            </form>

        </section>

        <!-- =====================================================
             RECENT REQUESTS
        ====================================================== -->

        <section class="panel">

            <h3>Recent requests</h3>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th>Submitted</th>

                            <?php if ($isReviewer): ?>
                                <th>Review action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($rows && $rows->num_rows > 0): ?>

                        <?php while ($request = $rows->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= e($request['full_name']) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= e($request['employee_code']) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= e($request['leave_type']) ?>
                                </td>

                                <td>
                                    <?= e($request['start_date']) ?>

                                    <br>

                                    to <?= e($request['end_date']) ?>
                                </td>

                                <td>

                                    <?php
                                    $statusClass = match (
                                        $request['status']
                                    ) {
                                        'Approved' => 'status-approved',
                                        'Rejected' => 'status-rejected',
                                        default => 'status-pending',
                                    };
                                    ?>

                                    <span class="<?= e($statusClass) ?>">
                                        <?= e($request['status']) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= e($request['reason'] ?? '—') ?>
                                </td>

                                <td>
                                    <?= e($request['created_at']) ?>
                                </td>

                                <?php if ($isReviewer): ?>

                                    <td>

                                        <?php if (
                                            $request['status'] === 'Pending'
                                        ): ?>

                                            <div class="leave-actions">

                                                <form
                                                    method="post"
                                                    onsubmit="return confirm('Approve this leave request?');"
                                                >

                                                    <?= csrf_field() ?>

                                                    <input
                                                        type="hidden"
                                                        name="leave_id"
                                                        value="<?= (int) $request['id'] ?>"
                                                    >

                                                    <button
                                                        class="btn"
                                                        type="submit"
                                                        name="decision"
                                                        value="Approved"
                                                    >
                                                        Approve
                                                    </button>

                                                </form>

                                                <form
                                                    method="post"
                                                    onsubmit="return confirm('Reject this leave request?');"
                                                >

                                                    <?= csrf_field() ?>

                                                    <input
                                                        type="hidden"
                                                        name="leave_id"
                                                        value="<?= (int) $request['id'] ?>"
                                                    >

                                                    <button
                                                        class="btn"
                                                        type="submit"
                                                        name="decision"
                                                        value="Rejected"
                                                        style="background:#b91c1c"
                                                    >
                                                        Reject
                                                    </button>

                                                </form>

                                            </div>

                                        <?php else: ?>

                                            <span class="muted">
                                                No action available
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td
                                colspan="<?= $isReviewer ? 7 : 6 ?>"
                            >
                                No leave requests found.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <p class="muted" style="margin-top:16px">
                Requests start as Pending. Only authorized HR,
                Admin and Manager accounts can approve or reject
                pending requests.
            </p>

        </section>

    </div>

</main>

<script>
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');

    if (startDate && endDate) {
        startDate.addEventListener('change', function () {
            endDate.min = this.value;

            if (endDate.value && endDate.value < this.value) {
                endDate.value = this.value;
            }
        });

        endDate.addEventListener('change', function () {
            if (startDate.value && this.value < startDate.value) {
                this.value = startDate.value;
            }
        });
    }
</script>

</body>
</html>