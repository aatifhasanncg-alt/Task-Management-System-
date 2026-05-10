<?php
/**
 * consulting/admin/log_view.php — Admin: View Visit Log
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    setFlash('danger', 'Invalid log ID.');
    header('Location: log_list.php');
    exit;
}

// Fetch — admin can view any log in scope
$log = $db->prepare("
    SELECT wl.*,
           c.company_name, c.company_code, c.pan_number,
           b.branch_name,
           u.full_name AS staff_name, u.employee_id,
           sv.full_name AS supervisor_name, sv.employee_id AS supervisor_emp_id,
           COALESCE(
               (SELECT d.dept_name FROM departments d
                WHERE d.id = wl.department_id AND d.dept_code = 'CON' LIMIT 1),
               (SELECT d.dept_name FROM user_department_assignments uda
                JOIN departments d ON d.id = uda.department_id
                WHERE uda.user_id = wl.user_id AND d.dept_code = 'CON' LIMIT 1),
               (SELECT d.dept_name FROM departments d WHERE d.id = wl.department_id LIMIT 1)
           ) AS dept_name
    FROM work_logs wl
    LEFT JOIN branches    b  ON b.id  = wl.branch_id
    LEFT JOIN users       u  ON u.id  = wl.user_id
    LEFT JOIN users       sv ON sv.id = wl.supervisor_id
    LEFT JOIN companies   c  ON c.id  = wl.client_id
    WHERE wl.id = ?
");
$log->execute([$id]);
$log = $log->fetch();

if (!$log) {
    setFlash('danger', 'Log not found.');
    header('Location: log_list.php');
    exit;
}

$isOwnLog = ($log['user_id'] == $uid);
$month = $log['month_year'] ?? date('Y-m', strtotime($log['log_date']));

$visitStatusMeta = [
    'visited' => ['label' => 'Visited', 'color' => '#10b981', 'bg' => '#f0fdf4', 'icon' => 'fa-check-circle'],
    'missed' => ['label' => 'Missed', 'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'fa-times-circle'],
    'rescheduled' => ['label' => 'Rescheduled', 'color' => '#f59e0b', 'bg' => '#fffbeb', 'icon' => 'fa-redo'],
];
$sm = $visitStatusMeta[$log['visit_status']] ?? ['label' => ucfirst($log['visit_status'] ?? ''), 'color' => '#9ca3af', 'bg' => '#f9fafb', 'icon' => 'fa-circle'];

$pageTitle = 'View Visit Log';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- ── Hero ── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-car"></i> Visit Log</div>
                        <h4><?= htmlspecialchars($log['company_name'] ?? '—') ?></h4>
                        <p>
                            <?= date('l, d M Y', strtotime($log['log_date'])) ?>
                            &nbsp;·&nbsp;
                            <span style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                         font-size:.75rem;font-weight:700;padding:.15rem .55rem;border-radius:5px;">
                                <i class="fas <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
                            </span>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <?php if ($isOwnLog): ?>
                            <a href="log_edit.php?id=<?= $log['id'] ?>" class="btn-gold btn btn-sm">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                        <?php endif; ?>
                        <a href="log_list.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Logs
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Own log notice / read-only notice -->
            <?php if (!$isOwnLog): ?>
                <div class="cn-alert cn-alert-info mb-3" style="font-size:.82rem;">
                    <i class="fas fa-info-circle me-2"></i>
                    You are viewing <strong><?= htmlspecialchars($log['staff_name']) ?></strong>'s log in read-only mode.
                    Only the log owner can edit it.
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                <!-- ── LEFT ── -->
                <div>

                    <!-- Staff Info (admin only extra) -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-user me-2" style="color:var(--gold)"></i>Staff
                            </span>
                        </div>
                        <div style="padding:14px 20px;display:flex;align-items:center;gap:14px;">
                            <div style="width:44px;height:44px;border-radius:50%;background:#fffbeb;
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:1rem;font-weight:800;color:#c9a84c;flex-shrink:0;">
                                <?= strtoupper(substr($log['staff_name'] ?? 'ST', 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-size:.92rem;font-weight:700;color:#1f2937;">
                                    <?= htmlspecialchars($log['staff_name'] ?? '—') ?>
                                    <?php if ($isOwnLog): ?>
                                        <span
                                            style="font-size:.65rem;background:#fef3c7;color:#d97706;
                                                 border-radius:4px;padding:.1rem .4rem;margin-left:6px;font-weight:700;">
                                            You
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:.75rem;color:#9ca3af;margin-top:2px;">
                                    <?= htmlspecialchars($log['employee_id'] ?? '') ?>
                                    &nbsp;·&nbsp; <?= htmlspecialchars($log['dept_name'] ?? '') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Visit Details -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-clipboard-list me-2" style="color:var(--gold)"></i>Visit Details
                            </span>
                        </div>
                        <div style="padding:18px 20px;">
                            <div class="row g-4">

                                <div class="col-md-6">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-building me-1"></i>Client
                                    </div>
                                    <div style="font-size:.95rem;font-weight:700;color:#1f2937;">
                                        <?= htmlspecialchars($log['company_name'] ?? '—') ?>
                                    </div>
                                    <?php if ($log['company_code']): ?>
                                        <div style="font-size:.75rem;color:#6b7280;margin-top:2px;">
                                            Code: <strong><?= htmlspecialchars($log['company_code']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($log['pan_number']): ?>
                                        <div style="font-size:.72rem;color:#9ca3af;margin-top:1px;">
                                            PAN: <?= htmlspecialchars($log['pan_number']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-calendar me-1"></i>Date
                                    </div>
                                    <div style="font-size:.95rem;font-weight:700;color:#1f2937;">
                                        <?= date('d M Y', strtotime($log['log_date'])) ?>
                                    </div>
                                    <div style="font-size:.75rem;color:#6b7280;margin-top:2px;">
                                        <?= $log['day_of_week'] ?> · Week <?= $log['week_number'] ?> ·
                                        <?= date('F Y', strtotime($log['log_date'])) ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-sign-in-alt me-1"></i>Time In
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#3b82f6;">
                                        <?= $log['time_in'] ? date('h:i A', strtotime($log['time_in'])) : '—' ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-sign-out-alt me-1"></i>Time Out
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#8b5cf6;">
                                        <?= $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '—' ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-clock me-1"></i>Duration
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#c9a84c;">
                                        <?= number_format((float) $log['duration_hours'], 2) ?>h
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-flag me-1"></i>Visit Status
                                    </div>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;
                                                 background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                                 font-size:.82rem;font-weight:700;padding:.3rem .75rem;border-radius:8px;">
                                        <i class="fas <?= $sm['icon'] ?>"></i><?= $sm['label'] ?>
                                    </span>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-layer-group me-1"></i>Department
                                    </div>
                                    <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                        <?= htmlspecialchars($log['dept_name'] ?? '—') ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-map-marker-alt me-1"></i>Branch
                                    </div>
                                    <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                        <?= htmlspecialchars($log['branch_name'] ?? '—') ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-user-tie me-1"></i>Supervisor
                                    </div>
                                    <?php if (!empty($log['supervisor_name'])): ?>
                                        <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                            <?= htmlspecialchars($log['supervisor_name']) ?>
                                        </div>
                                        <?php if (!empty($log['supervisor_emp_id'])): ?>
                                            <div style="font-size:.72rem;color:#9ca3af;margin-top:1px;">
                                                <?= htmlspecialchars($log['supervisor_emp_id']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div style="font-size:.85rem;color:#d1d5db;">— None</div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Work Description -->
                    <?php if (!empty($log['work_description'])): ?>
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-align-left me-2" style="color:var(--gold)"></i>Work Description
                                </span>
                            </div>
                            <div style="padding:18px 20px;">
                                <div style="font-size:.85rem;color:#374151;line-height:1.7;white-space:pre-wrap;">
                                    <?= htmlspecialchars($log['work_description']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── RIGHT ── -->
                <div>

                    <!-- Summary -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Summary
                            </span>
                        </div>
                        <div style="padding:16px;">
                            <div style="text-align:center;background:#fffbeb;border-radius:12px;
                                        padding:18px 12px;margin-bottom:14px;border:1.5px solid #fde68a;">
                                <div style="font-size:2.2rem;font-weight:900;color:#c9a84c;line-height:1;">
                                    <?= number_format((float) $log['duration_hours'], 2) ?>
                                </div>
                                <div style="font-size:.72rem;color:#9ca3af;margin-top:4px;font-weight:600;">HOURS ON
                                    SITE</div>
                                <?php if ($log['time_in'] && $log['time_out']): ?>
                                    <div style="font-size:.75rem;color:#6b7280;margin-top:6px;">
                                        <?= date('h:i A', strtotime($log['time_in'])) ?>
                                        <span style="color:#d1d5db;"> → </span>
                                        <?= date('h:i A', strtotime($log['time_out'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            $metaRows = [
                                ['fa-calendar-day', 'Date', date('d M Y', strtotime($log['log_date']))],
                                ['fa-hashtag', 'Week', 'Week ' . $log['week_number']],
                                ['fa-tag', 'Month', date('F Y', strtotime($log['log_date']))],
                            ];
                            foreach ($metaRows as [$icon, $label, $val]):
                                ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.8rem;">
                                    <span style="color:#9ca3af;"><i class="fas <?= $icon ?> me-2"></i><?= $label ?></span>
                                    <span style="font-weight:600;color:#374151;"><?= $val ?></span>
                                </div>
                            <?php endforeach; ?>

                            <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:7px 0;font-size:.8rem;">
                                <span style="color:#9ca3af;"><i class="fas fa-flag me-2"></i>Status</span>
                                <span style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                             font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:5px;">
                                    <i class="fas <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-bolt me-2" style="color:var(--gold)"></i>Actions
                            </span>
                        </div>
                        <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                            <?php if ($isOwnLog): ?>
                                <a href="log_edit.php?id=<?= $log['id'] ?>" class="cn-btn cn-btn-gold"
                                    style="justify-content:center;">
                                    <i class="fas fa-edit"></i> Edit This Log
                                </a>
                            <?php endif; ?>
                            <a href="log_list.php?month=<?= $month ?>" class="cn-btn cn-btn-out"
                                style="justify-content:center;">
                                <i class="fas fa-list"></i> All Visit Logs
                            </a>
                            <a href="office_log_list.php?month=<?= $month ?>" class="cn-btn cn-btn-out"
                                style="justify-content:center;">
                                <i class="fas fa-building"></i> Office Logs
                            </a>
                        </div>
                    </div>

                    <!-- Record Info -->
                    <div class="cn-panel">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-database me-2" style="color:var(--gold)"></i>Record Info
                            </span>
                        </div>
                        <div style="padding:14px 16px;">
                            <?php
                            $metaInfo = [
                                ['fa-hashtag', 'Log ID', '#' . $log['id']],
                                ['fa-user', 'Staff', $log['staff_name'] ?? '—'],
                                ['fa-user-tie', 'Supervisor', $log['supervisor_name'] ?? '—'],
                                ['fa-layer-group', 'Dept', $log['dept_name'] ?? '—'],
                                ['fa-map-marker-alt', 'Branch', $log['branch_name'] ?? '—'],
                            ];
                            foreach ($metaInfo as [$icon, $label, $val]):
                                ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:.78rem;">
                                    <span style="color:#9ca3af;"><i class="fas <?= $icon ?> me-2"></i><?= $label ?></span>
                                    <span style="font-weight:600;color:#6b7280;"><?= htmlspecialchars($val) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!empty($log['created_at'])): ?>
                                <div style="font-size:.7rem;color:#d1d5db;margin-top:8px;text-align:center;">
                                    Logged <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>