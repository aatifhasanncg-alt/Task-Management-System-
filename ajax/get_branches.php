<?php
// ajax/get_branches.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();
header('Content-Type: application/json');
$branches = getDB()->query("SELECT id, branch_name, city FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
echo json_encode($branches);