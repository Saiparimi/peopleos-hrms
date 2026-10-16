<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_roles(['Admin', 'HR']);
csrf_verify();

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$departmentFilter = (string)($_GET['department'] ?? '');

if (!in_array($statusFilter, ['', 'Active', 'Inactive'], true)) {
    $statusFilter = '';
}

$employees = [];
$departments = [];
$totalEmployees = 0;

try {
    $departmentResult = $conn->query(
        "SELECT DISTINCT department FROM employees
         WHERE department IS NOT NULL AND TRIM(department) <> ''
         ORDER BY department"
    );

    while ($row = $departmentResult->fetch_assoc()) {
        $departments[] = $row['department'];
    }
    $departmentResult->free();

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(full_name LIKE ? OR employee_code LIKE ?
                     OR email LIKE ? OR phone LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
        $types .= 'ssss';
    }

    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($departmentFilter !== '') {
        $where[] = 'department = ?';
        $params[] = $departmentFilter;
        $types .= 's';
    }

    $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT id, employee_code, full_name, email, phone,
                   department, designation, salary, joining_date, status
            FROM employees $whereSQL ORDER BY id DESC";

    $stmt = $conn->prepare($sql);

    if ($params) {
        $bind = [$types];
        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $countResult = $conn->query("SELECT COUNT(*) AS total FROM employees");
    $totalEmployees = (int)$countResult->fetch_assoc()['total'];
    $countResult->free();

} catch (Throwable $ex) {
    error_log('Employee directory: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to load employees. Check your database configuration.');
}

$activeEmployees = 0;
foreach ($employees as $emp) {
    if (strcasecmp((string)$emp['status'], 'Active') === 0) {
        $activeEmployees++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Employee Directory | HRMS</title>

<style>
:root {
    --bg: #f4f6fb;
    --surface: #fff;
    --ink: #172033;
    --muted: #68758a;
    --line: #e6eaf2;
    --brand: #4f46e5;
    --brand-dark: #3730a3;
    --green: #047857;
    --red: #dc2626;
    --shadow: 0 10px 35px rgba(30, 41, 80, .065);
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: Inter, "Segoe UI", Arial, sans-serif;
    font-size: 14px;
}
a { color: inherit; }
.hero {
    background: linear-gradient(120deg, #111827, #312e81 70%, #4f46e5);
    color: white;
    padding: 28px max(20px, calc((100% - 1440px)/2));
}
.hero-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.brand {
    display: flex;
    align-items: center;
    gap: 13px;
}
.brand-mark {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #ffffff20;
    border: 1px solid #ffffff30;
    font-weight: 900;
    font-size: 19px;
}
.brand h1 { margin: 0 0 5px; font-size: 23px; letter-spacing: -.5px; }
.brand p { margin: 0; color: #c7d2fe; font-size: 13px; }
.account {
    color: #e0e7ff;
    font-size: 12px;
}
.navrow {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    padding: 13px max(20px, calc((100% - 1440px)/2));
    background: white;
    border-bottom: 1px solid var(--line);
    box-shadow: 0 3px 12px #11182706;
}
.navrow a {
    text-decoration: none;
    color: #475569;
    padding: 10px 13px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 650;
}
.navrow a:hover, .navrow a.active {
    color: var(--brand-dark);
    background: #eef2ff;
}
.navrow a[href*="logout"],
.navrow a[href*="signout"] {
    margin-left: auto;
}
main {
    width: 100%;
    max-width: 1440px;
    margin: 0 auto;
    padding: 32px 24px 50px;
}
.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    margin-bottom: 25px;
}
.eyebrow {
    color: var(--brand);
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-bottom: 9px;
}
.page-heading h2 {
    font-size: clamp(27px, 3vw, 36px);
    letter-spacing: -1.3px;
    margin: 0 0 8px;
}
.page-heading p { color: var(--muted); margin: 0; line-height: 1.6; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 0;
    border-radius: 10px;
    padding: 11px 15px;
    font: inherit;
    font-size: 12px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
    transition: .18s ease;
}
.btn:hover { transform: translateY(-1px); }
.btn-primary {
    background: var(--brand);
    color: white;
    box-shadow: 0 5px 12px #4f46e525;
}
.btn-primary:hover { background: var(--brand-dark); }
.btn-light { background: white; border: 1px solid var(--line); color: #334155; }
.btn-light:hover { background: #f8fafc; }
.btn-edit { background: #e0f2fe; color: #075985; }
.btn-edit:hover { background: #bae6fd; }
.btn-delete { background: #fee2e2; color: #b91c1c; }
.btn-delete:hover { background: #fecaca; }

.metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 17px;
    margin-bottom: 24px;
}
.metric {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 17px;
    padding: 21px;
    box-shadow: var(--shadow);
}
.metric-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
}
.metric-icon {
    width: 39px;
    height: 39px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #eef2ff;
    color: var(--brand);
    font-size: 18px;
}
.metric-value {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -1px;
    margin: 13px 0 4px;
}
.metric-note { color: var(--muted); font-size: 12px; }

.panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 17px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.filter-panel { padding: 22px; margin-bottom: 23px; }
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 19px;
}
.panel-heading h3 { margin: 0 0 5px; font-size: 16px; }
.panel-heading p { margin: 0; color: var(--muted); font-size: 12px; }
.filter-grid {
    display: grid;
    grid-template-columns: minmax(200px, 2fr) minmax(140px, 1fr)
                           minmax(155px, 1fr) auto;
    gap: 14px;
    align-items: end;
}
.field label {
    display: block;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 750;
}
.field input, .field select {
    display: block;
    width: 100%;
    height: 45px;
    min-width: 0;
    border: 1px solid #dce2ed;
    border-radius: 10px;
    background: white;
    color: var(--ink);
    padding: 0 12px;
    font: inherit;
    font-size: 13px;
}
.field input:focus, .field select:focus {
    outline: 3px solid #c7d2fe;
    border-color: var(--brand);
}
.filter-actions { display: flex; gap: 8px; }
.filter-actions .btn { height: 45px; white-space: nowrap; }

.table-head {
    padding: 21px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--line);
}
.table-head h3 { margin: 0 0 5px; font-size: 16px; }
.table-head p { margin: 0; color: var(--muted); font-size: 12px; }
.record-count {
    background: #eef2ff;
    color: #4338ca;
    padding: 7px 10px;
    border-radius: 9px;
    font-weight: 750;
    font-size: 11px;
}
.table-scroll { width: 100%; overflow-x: auto; }
table {
    width: 100%;
    min-width: 1120px;
    border-collapse: collapse;
    text-align: left;
}
th {
    background: #f8faff;
    color: #68758a;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .65px;
    font-weight: 800;
}
th, td {
    padding: 16px 14px;
    border-bottom: 1px solid #edf0f6;
    white-space: nowrap;
    vertical-align: middle;
}
tbody tr:last-child td { border-bottom: 0; }
tbody tr:hover { background: #fafbff; }
.id-cell { color: #64748b; font-weight: 700; }
.person { display: flex; align-items: center; gap: 11px; }
.avatar {
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 850;
}
.person-name { font-weight: 750; color: #172033; margin-bottom: 5px; }
.person-code { font-size: 11px; color: #7b8799; }
.email-link { color: #4f46e5; text-decoration: none; }
.email-link:hover { text-decoration: underline; }
.muted { color: #94a3b8; }
.salary { font-weight: 800; font-variant-numeric: tabular-nums; }
.status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 99px;
    background: #dcfce7;
    color: #166534;
    font-size: 11px;
    font-weight: 800;
}
.status::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.status.inactive { background: #fee2e2; color: #991b1b; }
.row-actions { display: flex; gap: 7px; align-items: center; }
.row-actions .btn { padding: 8px 10px; font-size: 11px; }
.row-actions form { display: inline-flex; margin: 0; }
.empty {
    text-align: center;
    padding: 55px 20px;
}
.empty-icon {
    width: 55px;
    height: 55px;
    margin: 0 auto 15px;
    display: grid;
    place-items: center;
    border-radius: 17px;
    background: #eef2ff;
    color: var(--brand);
    font-size: 24px;
}
.empty h3 { margin: 0 0 8px; }
.empty p { color: var(--muted); margin: 0 0 18px; }
.table-footer {
    padding: 13px 20px;
    color: var(--muted);
    font-size: 11px;
    background: #fafbff;
    border-top: 1px solid var(--line);
}
@media(max-width: 1000px) {
    .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .filter-actions { align-self: end; }
}
@media(max-width: 650px) {
    .hero { padding: 23px 15px; }
    .brand h1 { font-size: 19px; }
    .navrow { padding: 10px; gap: 3px; }
    .navrow a { padding: 9px; font-size: 12px; }
    main { padding: 24px 12px 35px; }
    .page-heading { align-items: flex-start; }
    .actions { width: 100%; }
    .actions .btn { flex: 1; }
    .metrics { grid-template-columns: 1fr; gap: 11px; }
    .metric { padding: 17px; }
    .metric-value { font-size: 27px; }
    .filter-panel { padding: 16px; }
    .filter-grid { grid-template-columns: 1fr; }
    .filter-actions { display: grid; grid-template-columns: 1fr 1fr; }
    .table-head { padding: 17px; }
}
</style>
</head>

<body>

<header class="hero">
    <div class="hero-inner">
        <div class="brand">
            <div class="brand-mark">H</div>
            <div>
                <h1>HRMS <span style="font-weight:400">/ People</span></h1>
                <p>Employee directory and workforce management</p>
            </div>
        </div>
        <div class="account">
            Secure workspace · Employee management
        </div>
    </div>
</header>

<?php include __DIR__ . '/nav.php'; ?>

<main>
    <section class="page-heading">
        <div>
            <div class="eyebrow">People operations</div>
            <h2>Employee directory</h2>
            <p>Manage employee records, contact details and employment status.</p>
        </div>
        <div class="actions">
            <a class="btn btn-light" href="index.php">← Dashboard</a>
            <a class="btn btn-primary" href="add_employee.php">＋ Register employee</a>
        </div>
    </section>

    <section class="metrics">
        <div class="metric">
            <div class="metric-top">
                Total employees
                <span class="metric-icon">♙</span>
            </div>
            <div class="metric-value"><?= number_format($totalEmployees) ?></div>
            <div class="metric-note">All employee records</div>
        </div>
        <div class="metric">
            <div class="metric-top">
                Active in current results
                <span class="metric-icon">✓</span>
            </div>
            <div class="metric-value"><?= number_format($activeEmployees) ?></div>
            <div class="metric-note">Matching active employee records</div>
        </div>
        <div class="metric">
            <div class="metric-top">
                Matching records
                <span class="metric-icon">⌕</span>
            </div>
            <div class="metric-value"><?= number_format(count($employees)) ?></div>
            <div class="metric-note">Based on your selected filters</div>
        </div>
    </section>

    <section class="panel filter-panel">
        <div class="panel-heading">
            <div>
                <h3>Find your people</h3>
                <p>Search by name, employee code, email or phone.</p>
            </div>
        </div>

        <form method="GET" action="employees.php">
            <div class="filter-grid">
                <div class="field">
                    <label for="search">Search employees</label>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        maxlength="150"
                        placeholder="e.g. EMP001 or employee name"
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="field">
                    <label for="status">Employment status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="field">
                    <label for="department">Department</label>
                    <select id="department" name="department">
                        <option value="">All departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option
                                value="<?= e($department) ?>"
                                <?= $departmentFilter === $department ? 'selected' : '' ?>
                            ><?= e($department) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">Apply filters</button>
                    <a class="btn btn-light" href="employees.php">Reset</a>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="table-head">
            <div>
                <h3>All employees</h3>
                <p>Employee details and available actions</p>
            </div>
            <span class="record-count">
                <?= number_format(count($employees)) ?> records
            </span>
        </div>

        <?php if ($employees): ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Salary</th>
                        <th>Joining date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                    <?php
                    $name = trim((string)$emp['full_name']);
                    $initials = strtoupper(
                        mb_substr($name !== '' ? $name : 'E', 0, 1)
                    );
                    $inactive = strcasecmp(
                        (string)$emp['status'],
                        'Inactive'
                    ) === 0;

                    $joining = $emp['joining_date'] ?? '';
                    $date = null;

                    if ($joining && $joining !== '0000-00-00') {
                        $date = DateTime::createFromFormat('!Y-m-d', $joining);
                    }
                    ?>
                    <tr>
                        <td class="id-cell"><?= (int)$emp['id'] ?></td>
                        <td>
                            <div class="person">
                                <div class="avatar"><?= e($initials) ?></div>
                                <div>
                                    <div class="person-name"><?= e($name) ?></div>
                                    <div class="person-code"><?= e($emp['employee_code']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($emp['email'])): ?>
                                <a class="email-link" href="mailto:<?= e($emp['email']) ?>">
                                    <?= e($emp['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="muted">Not provided</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($emp['phone'] ?: '—') ?></td>
                        <td><?= e($emp['department'] ?: '—') ?></td>
                        <td><?= e($emp['designation'] ?: '—') ?></td>
                        <td class="salary">₹<?= number_format((float)$emp['salary'], 2) ?></td>
                        <td><?= $date ? e($date->format('d M Y')) : '—' ?></td>
                        <td>
                            <span class="status <?= $inactive ? 'inactive' : '' ?>">
                                <?= e($emp['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a
                                    class="btn btn-edit"
                                    href="edit_employee.php?id=<?= (int)$emp['id'] ?>"
                                >Edit</a>

                                <form
                                    action="delete_employee.php"
                                    method="POST"
                                    onsubmit="return confirm('Are you sure you want to delete this employee?');"
                                >
                                    <?= csrf_field() ?>
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$emp['id'] ?>"
                                    >
                                    <button class="btn btn-delete" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            Tip: On smaller screens, scroll horizontally inside the employee table to access every column.
        </div>

        <?php else: ?>
        <div class="empty">
            <div class="empty-icon">⌕</div>
            <h3>No employees found</h3>
            <p>
                <?php if ($search !== '' || $statusFilter !== '' || $departmentFilter !== ''): ?>
                    Try changing your filters or reset them to see all employees.
                <?php else: ?>
                    Register your first employee to populate the directory.
                <?php endif; ?>
            </p>
            <?php if ($search !== '' || $statusFilter !== '' || $departmentFilter !== ''): ?>
                <a class="btn btn-primary" href="employees.php">Clear filters</a>
            <?php else: ?>
                <a class="btn btn-primary" href="add_employee.php">Register employee</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
<?php $conn->close(); ?>