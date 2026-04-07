<?php
require_once '../../config/db.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

$stmt = $db->prepare("
    INSERT INTO corporate_grades 
    (grade_name, min_profit, max_profit, description, is_active)
    VALUES (?, ?, ?, ?, ?)
");

$stmt->execute([
    $_POST['grade_name'],
    $_POST['min_profit'],
    $_POST['max_profit'],
    $_POST['description'],
    $_POST['is_active']
]);

header("Location: corporate_grades.php");