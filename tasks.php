<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR', 'Manager', 'Employee']);
csrf_verify();

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$err = '';
$user = current_user();
$role = $user['role'];
$userEmployeeId = (int) ($user['employee_id'] ?? 0);

$isManager = in_array(
    $role,
    ['Admin', 'HR', 'Manager'],
    true
);

/*
|--------------------------------------------------------------------------
| Handle POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Create a task
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['create_task']) && $isManager) {

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $employeeInput = $_POST['employee_id'] ?? '';
        $employeeId = $employeeInput === ''
            ? null
            : filter_var($employeeInput, FILTER_VALIDATE_INT);

        $priority = $_POST['priority'] ?? 'Medium';
        $dueInput = $_POST['due_date'] ?? '';

        $allowedPriorities = ['Low', 'Medium', 'High'];

        $date = $dueInput === ''
            ? null
            : DateTime::createFromFormat('!Y-m-d', $dueInput);

        $validDate = $dueInput === ''
            || (
                $date !== false
                && $date !== null
                && $date->format('Y-m-d') === $dueInput
            );

        $validEmployee = $employeeInput === ''
            || (
                $employeeId !== false
                && $employeeId !== null
                && $employeeId > 0
            );

        if (
            $title === ''
            || strlen($title) > 160
            || !in_array($priority, $allowedPriorities, true)
            || !$validDate
            || !$validEmployee
        ) {
            $err = 'Enter a valid title, priority, employee and due date.';
        } else {
            try {

                // Confirm that the selected employee is active.
                if ($employeeId !== null) {

                    $check = $conn->prepare(
                        "SELECT id
                         FROM employees
                         WHERE id = ?
                           AND status = 'Active'
                         LIMIT 1"
                    );

                    $check->bind_param('i', $employeeId);
                    $check->execute();

                    $employeeExists =
                        $check->get_result()->num_rows === 1;

                    $check->close();

                    if (!$employeeExists) {
                        $err = 'Selected employee is inactive or does not exist.';
                    }
                }

                if ($err === '') {

                    $dueDate = $dueInput === ''
                        ? null
                        : $dueInput;

                    $stmt = $conn->prepare(
                        'INSERT INTO hr_tasks
                            (
                                title,
                                description,
                                employee_id,
                                priority,
                                due_date
                            )
                         VALUES (?, ?, ?, ?, ?)'
                    );

                    $stmt->bind_param(
                        'ssiss',
                        $title,
                        $description,
                        $employeeId,
                        $priority,
                        $dueDate
                    );

                    $stmt->execute();
                    $stmt->close();

                    header('Location: tasks.php?created=1');
                    exit;
                }

            } catch (Throwable $exception) {

                error_log(
                    'HRMS task creation error: '
                    . $exception->getMessage()
                );

                $err = 'Unable to create the task. Check the application error log.';
            }
        }

    /*
    |--------------------------------------------------------------------------
    | Update task status
    |--------------------------------------------------------------------------
    */

    } elseif (isset($_POST['update_status'])) {

        $taskId = filter_var(
            $_POST['task_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = trim($_POST['status'] ?? '');

        $allowedStatuses = [
            'To Do',
            'In Progress',
            'Done'
        ];

        if (
            $taskId === false
            || $taskId === null
            || $taskId < 1
            || !in_array($newStatus, $allowedStatuses, true)
        ) {
            $err = 'Invalid task ID or status.';

        } elseif (
            $role === 'Employee'
            && $userEmployeeId < 1
        ) {
            $err = 'Your account is not linked to a valid employee record.';

        } else {

            try {

                /*
                 * Employees can update only their assigned tasks.
                 * Other permitted roles can update any task.
                 */

                if ($role === 'Employee') {

                    $check = $conn->prepare(
                        'SELECT id, status
                         FROM hr_tasks
                         WHERE id = ?
                           AND employee_id = ?
                         LIMIT 1'
                    );

                    $check->bind_param(
                        'ii',
                        $taskId,
                        $userEmployeeId
                    );

                } else {

                    $check = $conn->prepare(
                        'SELECT id, status
                         FROM hr_tasks
                         WHERE id = ?
                         LIMIT 1'
                    );

                    $check->bind_param('i', $taskId);
                }

                $check->execute();

                $task = $check->get_result()->fetch_assoc();

                $check->close();

                if (!$task) {

                    $err = 'Task not found or you do not have permission to update it.';

                } elseif ($task['status'] === $newStatus) {

                    // Saving the already-selected status changes nothing.
                    header('Location: tasks.php?unchanged=1');
                    exit;

                } elseif ($task['status'] === 'Done') {

                    // Completed tasks cannot be reopened.
                    $err = 'Completed tasks cannot be reopened.';

                } else {

                    $oldStatus = $task['status'];

                    /*
                     * Include the previous status in the UPDATE to
                     * avoid overwriting a concurrent status change.
                     */

                    if ($role === 'Employee') {

                        $stmt = $conn->prepare(
                            'UPDATE hr_tasks
                             SET status = ?
                             WHERE id = ?
                               AND employee_id = ?
                               AND status = ?'
                        );

                        $stmt->bind_param(
                            'siis',
                            $newStatus,
                            $taskId,
                            $userEmployeeId,
                            $oldStatus
                        );

                    } else {

                        $stmt = $conn->prepare(
                            'UPDATE hr_tasks
                             SET status = ?
                             WHERE id = ?
                               AND status = ?'
                        );

                        $stmt->bind_param(
                            'sis',
                            $newStatus,
                            $taskId,
                            $oldStatus
                        );
                    }

                    $stmt->execute();

                    $changedRows = $stmt->affected_rows;

                    $stmt->close();

                    /*
                     * Confirm the status stored in the database.
                     */

                    if ($role === 'Employee') {

                        $verify = $conn->prepare(
                            'SELECT status
                             FROM hr_tasks
                             WHERE id = ?
                               AND employee_id = ?
                             LIMIT 1'
                        );

                        $verify->bind_param(
                            'ii',
                            $taskId,
                            $userEmployeeId
                        );

                    } else {

                        $verify = $conn->prepare(
                            'SELECT status
                             FROM hr_tasks
                             WHERE id = ?
                             LIMIT 1'
                        );

                        $verify->bind_param('i', $taskId);
                    }

                    $verify->execute();

                    $savedTask = $verify->get_result()->fetch_assoc();

                    $verify->close();

                    if (!$savedTask) {

                        $err = 'Task not found or you are not authorized to update it.';

                    } elseif ($savedTask['status'] === $newStatus) {

                        if ($changedRows === 1) {

                            audit_event(
                                $conn,
                                'task_status_updated',
                                'task',
                                $taskId
                            );

                            header('Location: tasks.php?updated=1');
                            exit;
                        }

                        header('Location: tasks.php?unchanged=1');
                        exit;

                    } else {

                        $err = 'The task was not updated. Refresh and try again.';
                    }
                }

            } catch (Throwable $exception) {

                error_log(
                    'HRMS task status update error: '
                    . $exception->getMessage()
                );

                $err = 'Unable to update the task. Check the application error log.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load active employees
|--------------------------------------------------------------------------
*/

$employees = $conn->query(
    "SELECT id, full_name, employee_code
     FROM employees
     WHERE status = 'Active'
     ORDER BY full_name"
);

/*
|--------------------------------------------------------------------------
| Load tasks
|--------------------------------------------------------------------------
*/

if ($role === 'Employee') {

    $stmt = $conn->prepare(
        "SELECT
            t.*,
            e.full_name,
            e.employee_code
         FROM hr_tasks AS t
         LEFT JOIN employees AS e
            ON e.id = t.employee_id
         WHERE t.employee_id = ?
         ORDER BY
            FIELD(t.status, 'To Do', 'In Progress', 'Done'),
            t.due_date IS NULL,
            t.due_date"
    );

    $stmt->bind_param('i', $userEmployeeId);
    $stmt->execute();

    $tasks = $stmt->get_result();

} else {

    $tasks = $conn->query(
        "SELECT
            t.*,
            e.full_name,
            e.employee_code
         FROM hr_tasks AS t
         LEFT JOIN employees AS e
            ON e.id = t.employee_id
         ORDER BY
            FIELD(t.status, 'To Do', 'In Progress', 'Done'),
            t.due_date IS NULL,
            t.due_date"
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

    <title>Tasks | HRMS</title>

    <link rel="stylesheet" href="assets.css">
</head>

<body>

<header>
    <h1>Team tasks</h1>
    <p>Assignment and progress tracking</p>
</header>

<?php include 'nav.php'; ?>

<main>

    <div class="page-head">
        <div>
            <h2>Work management</h2>
            <p>Assign owners, set priorities and track completion.</p>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>

        <div class="alert">
            Task created successfully.
        </div>

    <?php elseif (isset($_GET['updated'])): ?>

        <div class="alert">
            Task status updated successfully.
        </div>

    <?php elseif (isset($_GET['unchanged'])): ?>

        <div class="alert">
            Task already has that status. No change was needed.
        </div>

    <?php endif; ?>

    <?php if ($err !== ''): ?>

        <div class="alert error">
            <?= e($err) ?>
        </div>

    <?php endif; ?>

    <div class="grid">

        <?php if ($isManager): ?>

            <section class="panel col-4">

                <h3>Create task</h3>

                <form method="post">

                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="create_task"
                        value="1"
                    >

                    <div class="field">

                        <label for="title">
                            Task title
                        </label>

                        <input
                            id="title"
                            name="title"
                            maxlength="160"
                            required
                        >

                    </div>

                    <div class="field">

                        <label for="description">
                            Description
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="3"
                        ></textarea>

                    </div>

                    <div class="field">

                        <label for="employee_id">
                            Assign employee
                        </label>

                        <select
                            id="employee_id"
                            name="employee_id"
                        >

                            <option value="">
                                Unassigned
                            </option>

                            <?php while (
                                $emp = $employees->fetch_assoc()
                            ): ?>

                                <option
                                    value="<?= (int) $emp['id'] ?>"
                                >
                                    <?= e(
                                        $emp['employee_code']
                                        . ' — '
                                        . $emp['full_name']
                                    ) ?>
                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="field">

                        <label for="priority">
                            Priority
                        </label>

                        <select
                            id="priority"
                            name="priority"
                        >
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Low">Low</option>
                        </select>

                    </div>

                    <div class="field">

                        <label for="due_date">
                            Due date
                        </label>

                        <input
                            id="due_date"
                            type="date"
                            name="due_date"
                        >

                    </div>

                    <button class="btn" type="submit">
                        Create task
                    </button>

                </form>

            </section>

        <?php endif; ?>

        <section class="panel col-8">

            <h3>Task board</h3>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Owner</th>
                            <th>Priority</th>
                            <th>Due</th>
                            <th>Progress</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($tasks->num_rows > 0): ?>

                        <?php while (
                            $task = $tasks->fetch_assoc()
                        ): ?>

                            <?php
                            $canUpdate = $role !== 'Employee'
                                || (
                                    (int) $task['employee_id']
                                    === $userEmployeeId
                                );

                            $isCompleted = $task['status'] === 'Done';
                            ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= e($task['title']) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= e($task['description']) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= e(
                                        $task['full_name'] ?? 'Unassigned'
                                    ) ?>
                                </td>

                                <td>

                                    <span class="pill">
                                        <?= e($task['priority']) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= e($task['due_date'] ?? '—') ?>
                                </td>

                                <td>

                                    <?php if ($isCompleted): ?>

                                        <!-- Completed tasks are read-only. -->

                                        <span
                                            class="pill"
                                            aria-label="Task completed"
                                        >
                                            Done &#10003;
                                        </span>

                                    <?php elseif ($canUpdate): ?>

                                        <form
                                            method="post"
                                            style="display:flex;gap:6px;align-items:center"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="update_status"
                                                value="1"
                                            >

                                            <input
                                                type="hidden"
                                                name="task_id"
                                                value="<?= (int) $task['id'] ?>"
                                            >

                                            <select
                                                name="status"
                                                aria-label="Task status"
                                                required
                                            >

                                                <?php foreach (
                                                    ['To Do', 'In Progress', 'Done']
                                                    as $status
                                                ): ?>

                                                    <option
                                                        value="<?= e($status) ?>"
                                                        <?= $task['status'] === $status
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= e($status) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <button
                                                class="btn"
                                                type="submit"
                                            >
                                                Save
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="pill">
                                            <?= e($task['status']) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5">
                                No tasks created yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </div>

</main>

</body>
</html>