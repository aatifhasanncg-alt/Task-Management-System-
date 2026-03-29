<?php
require_once '../config/db.php';

$db = getDB();

$nature = $_GET['nature'] ?? '';

if ($nature) {
    $sql = "SELECT id, auditor_name, countable_count, uncountable_count 
            FROM auditors WHERE is_active=1";
} else {
    $sql = "SELECT id, auditor_name, countable_count, uncountable_count 
            FROM auditors WHERE is_active=1";
}

$data = $db->query($sql)->fetchAll();

echo json_encode(array_map(function($a) {
    return [
        'id' => $a['id'],
        'name' => $a['auditor_name'],
        'countable_count' => $a['countable_count'],
        'uncountable_count' => $a['uncountable_count']
    ];
}, $data));