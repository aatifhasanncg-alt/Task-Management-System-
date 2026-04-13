<?php
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
ob_clean();
header('Content-Type: application/json');

$user = currentUser();
if (!$user) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

$db = getDB();

try {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
        $stmt->execute([$id, $user['id']]);
        echo json_encode(['ok' => true, 'msg' => 'Notification marked as read']);
        exit;
    }

    $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
    $stmt->execute([$user['id']]);
    echo json_encode(['ok' => true, 'msg' => 'All notifications marked as read']);
    exit;

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}