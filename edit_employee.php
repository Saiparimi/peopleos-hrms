<?php
require_once "db.php";
require_once __DIR__.'/auth.php';
require_roles(['Admin', 'HR']);
csrf_verify();


function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    exit("Invalid employee ID.");
}

$stmt = $conn->prepare(
    "SELECT * FROM employees WHERE id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    exit("Employee not found.");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $employee_code = trim($_POST["employee_code"] ?? "");
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $salary_input = trim($_POST["salary"] ?? "");
    $joining_date = trim($_POST["joining_date"] ?? "");
    $status = $_POST["status"] ?? "Active";

    $employee = array_merge($employee, [
        "employee_code" => $employee_code,
        "full_name" => $full_name,
        "email" => $email,
        "phone" => $phone,
        "department" => $department,
        "designation" => $designation,
        "salary" => $salary_input,
        "joining_date" => $joining_date,
        "status" => $status
    ]);

    if (
        $employee_code === "" ||
        $full_name === "" ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_numeric($salary_input) ||
        (float)$salary_input < 0 ||
        !in_array($status, ["Active", "Inactive"], true)
    ) {
        $error = "Enter valid details in all required fields.";
    } else {
        $salary = (float)$salary_input;

        if ($joining_date !== "") {
            $date = DateTime::createFromFormat("!Y-m-d", $joining_date);
            if (!$date || $date->format("Y-m-d") !== $joining_date) {
                $error = "Enter a valid joining date.";
            }
        }

        if ($error === "") {
            try {
                $stmt = $conn->prepare(
                    "UPDATE employees SET
                        employee_code = ?,
                        full_name = ?,
                        email = ?,
                        phone = ?,
                        department = ?,
                        designation = ?,
                        salary = ?,
                        joining_date = NULLIF(?, ''),
                        status = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "ssssssdssi",
                    $employee_code,
                    $full_name,
                    $email,
                    $phone,
                    $department,
                    $designation,
                    $salary,
                    $joining_date,
                    $status,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                header("Location: employees.php?updated=1");
                exit;
            } catch (mysqli_sql_exception $ex) {
                $error = "Update failed. Check for duplicate employee codes or emails.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee | HRMS</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 25px 15px;
        }
        .container {
            max-width: 650px;
            margin: 20px auto;
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 4px 15px #00000010;
        }
        h1 { color: #172554; }
        label {
            display: block;
            margin: 15px 0 6px;
            font-weight: bold;
        }
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }
        button, .back {
            display: inline-block;
            padding: 12px 18px;
            border: none;
            border-radius: 6px;
            background: #1d4ed8;
            color: white;
            text-decoration: none;
            cursor: pointer;
            margin-top: 20px;
        }
        .back { background: #475569; }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
        }
    </style>
    <link rel="stylesheet" href="assets.css">
</head>
<body>
<?php include 'nav.php'; ?>
<div class="container">
    <h1>Edit Employee</h1>

    <?php if ($error !== ""): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST"><?=csrf_field()?>
        <label>Employee Code</label>
        <input name="employee_code" required maxlength="20"
               value="<?= e($employee['employee_code']) ?>">

        <label>Full Name</label>
        <input name="full_name" required maxlength="100"
               value="<?= e($employee['full_name']) ?>">

        <label>Email</label>
        <input type="email" name="email" required maxlength="100"
               value="<?= e($employee['email']) ?>">

        <label>Phone</label>
        <input name="phone" maxlength="20"
               value="<?= e($employee['phone']) ?>">

        <label>Department</label>
        <input name="department" maxlength="100"
               value="<?= e($employee['department']) ?>">

        <label>Designation</label>
        <input name="designation" maxlength="100"
               value="<?= e($employee['designation']) ?>">

        <label>Monthly Salary (₹)</label>
        <input type="number" name="salary" min="0" step="0.01"
               required value="<?= e($employee['salary']) ?>">

        <label>Joining Date</label>
        <input type="date" name="joining_date"
               value="<?= e($employee['joining_date']) ?>">

        <label>Status</label>
        <select name="status">
            <option value="Active"
                <?= $employee['status'] === 'Active' ? 'selected' : '' ?>>
                Active
            </option>
            <option value="Inactive"
                <?= $employee['status'] === 'Inactive' ? 'selected' : '' ?>>
                Inactive
            </option>
        </select>

        <button type="submit">Save Changes</button>
        <a class="back" href="employees.php">Cancel</a>
    </form>
</div>
</body>
</html>