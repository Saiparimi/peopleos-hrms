<?php
require_once "db.php";
require_once __DIR__.'/auth.php';
require_roles(['Admin', 'HR']);
csrf_verify();


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: employees.php");
    exit;
}

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: employees.php?error=invalid");
    exit;
}

$stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$deleted = $stmt->affected_rows;
$stmt->close();

if ($deleted === 1) {
    header("Location: employees.php?deleted=1");
} else {
    header("Location: employees.php?error=notfound");
}
exit;