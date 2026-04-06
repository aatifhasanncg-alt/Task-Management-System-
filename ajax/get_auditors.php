<?php
require_once '../config/db.php';
require_once '../config/session.php';
requireAdmin();

$db     = getDB();
$nature = trim($_GET['nature'] ?? '');

$fyId = $db->query("
    SELECT id FROM fiscal_years WHERE is_current = 1 LIMIT 1
")->fetchColumn();

$stmt = $db->prepare("
    SELECT
        a.id,
        a.auditor_name,
        a.max_limit,
        COALESCE(q.max_countable_override, a.max_limit) AS effective_max,
        COALESCE(q.countable_count,   0)                    AS countable_count,
        COALESCE(q.uncountable_count, 0)                    AS uncountable_count
    FROM auditors a
    LEFT JOIN auditor_yearly_quota q
           ON q.auditor_id     = a.id
          AND q.fiscal_year_id = ?
    WHERE a.is_active = 1
    ORDER BY a.auditor_name
");
$stmt->execute([$fyId ?: 0]);
$auditors = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($auditors as &$a) {
    $a['max_limit']  = (int)$a['effective_max'];
    $a['at_limit']   = (
        in_array(strtolower($nature), ['countable']) &&
        (int)$a['countable_count'] >= (int)$a['effective_max']
    );
}

header('Content-Type: application/json');
echo json_encode(array_values($auditors));