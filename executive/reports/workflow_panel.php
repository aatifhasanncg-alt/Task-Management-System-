<?php
/**
 * workflow_panel.php
 * Loaded via fetch() from company_wise.php to render
 * the task list + workflow chain for a single company.
 * Returns plain HTML fragment — no layout wrappers.
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db        = getDB();
$companyId = (int)($_GET['company_id'] ?? 0);
if (!$companyId) { echo '<p style="color:#ef4444;text-align:center;padding:1rem;">Invalid company.</p>'; exit; }

// Tasks
$taskSt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.color,
           u.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN users u        ON u.id  = t.assigned_to
    WHERE t.company_id = ? AND t.is_active = 1
    ORDER BY t.created_at DESC
");
$taskSt->execute([$companyId]);
$tasks = $taskSt->fetchAll();

if (empty($tasks)) {
    echo '<p style="text-align:center;padding:1.5rem;color:#9ca3af;font-size:.83rem;">No tasks for this company.</p>';
    exit;
}

$actionColors = [
    'created'          => '#3b82f6',
    'assigned'         => '#f59e0b',
    'status_changed'   => '#8b5cf6',
    'transferred_staff'=> '#06b6d4',
    'transferred_dept' => '#ec4899',
    'completed'        => '#10b981',
    'remarked'         => '#9ca3af',
];
$actionLabels = [
    'created'          => 'Created',
    'assigned'         => 'Assigned',
    'status_changed'   => 'Status Updated',
    'transferred_staff'=> 'Transferred',
    'transferred_dept' => 'Dept Transfer',
    'completed'        => 'Completed',
    'remarked'         => 'Remarked',
];
?>
<div style="padding:.25rem 0;">
    <?php foreach ($tasks as $t):
        $sClass  = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));
        $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done';

        // Workflow for this task
        $wfSt = $db->prepare("
            SELECT tw.*,
                   u1.full_name AS from_name,
                   u2.full_name AS to_name,
                   d1.dept_name AS from_dept,
                   d2.dept_name AS to_dept
            FROM task_workflow tw
            LEFT JOIN users u1       ON u1.id = tw.from_user_id
            LEFT JOIN users u2       ON u2.id = tw.to_user_id
            LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
            LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
            WHERE tw.task_id = ?
            ORDER BY tw.created_at ASC
        ");
        try {
            $wfSt->execute([$t['id']]);
            $workflow = $wfSt->fetchAll();
        } catch (Exception $e) {
            $workflow = [];
        }
    ?>
        <div style="border-bottom:1px solid #f3f4f6;padding:.85rem .5rem;<?= $overdue ? 'background:#fef8f8;' : '' ?>">

            <!-- Task header row -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                    <strong style="font-size:.88rem;color:#1f2937;"><?= htmlspecialchars($t['title']) ?></strong>
                    <?php if ($t['dept_name']): ?>
                        <span style="font-size:.72rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;
                                     color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;
                                     padding:.2rem .5rem;border-radius:99px;">
                            <?= htmlspecialchars($t['dept_name']) ?>
                        </span>
                    <?php endif; ?>
                    <span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status'] ?? '—') ?></span>
                    <?php if ($overdue): ?>
                        <span style="font-size:.65rem;color:#ef4444;font-weight:700;">⚠ OVERDUE</span>
                    <?php endif; ?>
                    <?php if ($t['assigned_to_name']): ?>
                        <span style="font-size:.72rem;color:#6b7280;">
                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($t['assigned_to_name']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span style="font-size:.7rem;color:#9ca3af;"><?= date('d M Y', strtotime($t['created_at'])) ?></span>
                    <a href="<?= APP_URL ?>/executive/tasks/view.php?id=<?= $t['id'] ?>"
                       class="btn btn-sm btn-outline-secondary" style="padding:.2rem .5rem;">
                        <i class="fas fa-eye" style="font-size:.7rem;"></i>
                    </a>
                </div>
            </div>

            <!-- Workflow chain -->
            <?php if (!empty($workflow)): ?>
                <div style="display:flex;align-items:flex-start;flex-wrap:wrap;gap:.3rem;margin-top:.4rem;">
                    <?php foreach ($workflow as $i => $w):
                        $isLast = ($i === count($workflow) - 1);
                        $aColor = $actionColors[$w['action']] ?? '#9ca3af';
                        $aLabel = $actionLabels[$w['action']] ?? ucwords(str_replace('_', ' ', $w['action']));
                    ?>
                        <div style="background:<?= $isLast ? $aColor . '14' : '#f9fafb' ?>;
                                    border:1px solid <?= $isLast ? $aColor : '#e5e7eb' ?>;
                                    border-radius:8px;padding:.4rem .65rem;text-align:center;min-width:88px;">
                            <div style="font-size:.68rem;font-weight:700;color:<?= $aColor ?>;"><?= $aLabel ?></div>
                            <div style="font-size:.72rem;font-weight:500;color:#1f2937;margin:.1rem 0;">
                                <?= htmlspecialchars($w['from_name'] ?? 'System') ?>
                            </div>
                            <?php if ($w['from_dept']): ?>
                                <div style="font-size:.62rem;color:#9ca3af;"><?= htmlspecialchars($w['from_dept']) ?></div>
                            <?php endif; ?>
                            <?php if ($w['old_status'] && $w['new_status'] && $w['old_status'] !== $w['new_status']): ?>
                                <div style="font-size:.6rem;color:#6b7280;margin-top:.1rem;">
                                    <?= htmlspecialchars($w['old_status']) ?> → <?= htmlspecialchars($w['new_status']) ?>
                                </div>
                            <?php endif; ?>
                            <div style="font-size:.62rem;color:#9ca3af;margin-top:.1rem;">
                                <?= date('d M, H:i', strtotime($w['created_at'])) ?>
                            </div>
                            <?php if (!empty($w['remarks'])): ?>
                                <div style="font-size:.6rem;color:#6b7280;font-style:italic;margin-top:.15rem;
                                            max-width:90px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                    title="<?= htmlspecialchars($w['remarks']) ?>">
                                    "<?= htmlspecialchars($w['remarks']) ?>"
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isLast): ?>
                            <div style="color:#c9a84c;font-size:.8rem;font-weight:700;align-self:center;">→</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="font-size:.75rem;color:#9ca3af;font-style:italic;">No workflow history yet.</div>
            <?php endif; ?>

        </div>
    <?php endforeach; ?>
</div>