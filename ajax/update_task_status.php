<?php
header('Content-Type: application/json');

require_once '../config/db.php';

$db = getDB();

$task_id = $_POST['task_id'] ?? null;
$action  = $_POST['action'] ?? '';

if (!$task_id) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid task']);
    exit;
}

if ($action === 'status_change') {

    $new_status = $_POST['new_status'] ?? '';

    // get status_id
    $stmt = $db->prepare("SELECT id FROM task_status WHERE status_name=?");
    $stmt->execute([$new_status]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid status']);
        exit;
    }

    $status_id = $row['id'];

    $update = $db->prepare("UPDATE tasks SET status_id=? WHERE id=?");
    $update->execute([$status_id, $task_id]);

    echo json_encode(['ok' => true, 'msg' => 'Status updated']);
    exit;
}

if ($action === 'transfer_staff') {

    $to_user = $_POST['to_user_id'] ?? '';

    $update = $db->prepare("UPDATE tasks SET assigned_to=? WHERE id=?");
    $update->execute([$to_user, $task_id]);

    echo json_encode(['ok' => true, 'msg' => 'Task transferred']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Invalid action']);