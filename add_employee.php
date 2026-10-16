<?php
require_once "db.php";
require_once __DIR__.'/auth.php';
require_roles(['Admin', 'HR']);
csrf_verify();


$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = trim($_POST["employee_code"]);
    $name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $department = trim($_POST["department"]);
    $designation = trim($_POST["designation"]);
    $salary = (float) $_POST["salary"];

    $stmt = $conn->prepare(
        "INSERT INTO employees
        (employee_code, full_name, email, phone,
         department, designation, salary)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        "ssssssd",
        $code, $name, $email, $phone,
        $department, $designation, $salary
    );

if ($stmt->execute()) {
    header("Location: employees.php?success=added");
    exit;
} else {
    if ($stmt->errno === 1062) {
        $message = "Employee code or email already exists. Please use a unique value.";
    } else {
        $message = "Unable to register employee: " . $stmt->error;
    }
}

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Registration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            padding: 30px;
        }
        form {
            max-width: 500px;
            margin: auto;
            padding: 25px;
            background: white;
            border-radius: 12px;
        }
        input, button {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            margin: 8px 0;
        }
        button {
            background: #1d4ed8;
            color: white;
            border: 0;
            cursor: pointer;
        }
        h2 { text-align: center; }
    </style>
    <link rel="stylesheet" href="assets.css">
</head>
<body>
<?php include 'nav.php'; ?>
    <form method="POST"><?=csrf_field()?>
        <h2>Register Employee</h2>

        <p><?= htmlspecialchars($message) ?></p>

        <input name="employee_code" placeholder="Employee Code" required>
        <input name="full_name" placeholder="Full Name" required>
        <input name="email" type="email" placeholder="Email" required>
        <input name="phone" placeholder="Phone Number">
        <input name="department" placeholder="Department">
        <input name="designation" placeholder="Designation">
        <input name="salary" type="number" min="0" step="0.01"
               placeholder="Monthly Salary" required>

        <button type="submit">Register Employee</button>
    </form>
</body>
</html>