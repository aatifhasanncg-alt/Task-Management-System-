<?php
require_once '../../config/db.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

$stmt = $db->prepare("
    UPDATE corporate_grades
    SET grade_name = ?, min_profit = ?, max_profit = ?, description = ?, is_active = ?
    WHERE id = ?
");

$stmt->execute([
    $_POST['grade_name'],
    $_POST['min_profit'],
    $_POST['max_profit'],
    $_POST['description'],
    $_POST['is_active'],
    $_POST['id']
]);

header("Location: corporate_grades.php");
