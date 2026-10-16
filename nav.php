<?php
require_once __DIR__ . '/auth.php';

$navUser = current_user();

if (!function_exists('navEscape')) {
    function navEscape($value): string {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
?>

<nav class="navrow" aria-label="Main navigation">
<?php if ($navUser): ?>

    <?php if ($navUser['role'] === 'Employee'): ?>

        <a href="employee_portal.php">My workspace</a>
        <a href="leave.php">My leave</a>
        <a href="tasks.php">My tasks</a>

    <?php else: ?>

        <a href="index.php">Dashboard</a>
        <a href="employees.php">Employees</a>

        <?php if (in_array($navUser['role'], ['Admin', 'HR'], true)): ?>
            <a href="add_employee.php">Add employee</a>
        <?php endif; ?>

        <a href="attendance.php">Attendance</a>
        <a href="attendance_overview.php">Attendance Overview</a>
        <a href="leave.php">Leave</a>

        <?php if (in_array($navUser['role'], ['Admin', 'HR'], true)): ?>
            <a href="payroll.php">Payroll</a>
        <?php endif; ?>

        <a href="tasks.php">Tasks</a>

        <?php if (in_array($navUser['role'], ['Admin', 'HR', 'Manager'], true)): ?>
            <a href="alerts.php">Notifications</a>
            <a href="performance.php">Performance Setup</a>
            <a href="performance_report.php">Performance Report</a>
        <?php endif; ?>

        <?php if ($navUser['role'] === 'Admin'): ?>
            <a href="users.php">Users</a>
        <?php endif; ?>

    <?php endif; ?>

    <a href="change_password.php">Change password</a>

    <span class="nav-user">
        <?= navEscape($navUser['username'] ?? '') ?>
        ·
        <?= navEscape($navUser['role'] ?? '') ?>
    </span>

    <form method="post" action="logout.php" class="nav-logout">
        <?= csrf_field() ?>
        <button type="submit">Sign out</button>
    </form>

<?php endif; ?>
</nav>

<style>
.navrow {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    padding: 12px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
}

.navrow > a {
    display: inline-block;
    padding: 9px 11px;
    color: #334155;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    transition: background .15s ease, color .15s ease;
}

.navrow > a:hover,
.navrow > a:focus-visible {
    background: #eef2ff;
    color: #4338ca;
    outline: none;
}

.nav-user {
    margin-left: auto;
    color: #64748b;
    font-size: 13px;
    padding: 8px;
}

.nav-logout {
    display: inline-flex;
    margin: 0;
}

.nav-logout button {
    border: 1px solid #e2e8f0;
    background: #fff;
    border-radius: 8px;
    padding: 9px 12px;
    cursor: pointer;
    color: #334155;
    font-weight: 600;
}

.nav-logout button:hover {
    background: #f8fafc;
}

@media (max-width: 700px) {
    .navrow {
        gap: 5px;
        padding: 10px;
    }

    .navrow > a {
        padding: 8px;
        font-size: 12px;
    }

    .nav-user {
        margin-left: 0;
        width: 100%;
    }
}
</style>