<?php
ob_start();
require_once '../config/db.php';
require_once '../config/session.php';
ob_clean();

header('Content-Type: application/json');

$user = currentUser();
if (!$user) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

$db = getDB();

try {
    $stmt = $db->prepare("
        SELECT id, title, message, type, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $countStmt->execute([$user['id']]);
    $unread = (int)$countStmt->fetchColumn();

    echo json_encode([
        'ok'     => true,
        'data'   => $notifications,
        'unread' => $unread,
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}