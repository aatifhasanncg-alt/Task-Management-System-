<?php
/**
 * consulting/manager/office_log_view.php — Manager: View Office Work Log
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireManager();

$db   = getDB();
$user = currentUser();
$uid  = (int) $user['id'];

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    setFlash('danger', 'Invalid log ID.');
    header('Location: log_list.php');
    exit;
}

// Fetch log — ensure it belongs to this user
$log = $db->prepare("
    SELECT owl.*,
           ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours,
           c.company_name, c.company_code, c.pan_number,
           d.dept_name, b.branch_name
    FROM office_work_logs owl
    LEFT JOIN companies   c ON c.id = owl.client_id
    LEFT JOIN departments d ON d.id = owl.department_id
    LEFT JOIN branches    b ON b.id = owl.branch_id
    WHERE owl.id = ? AND owl.user_id = ?
");
$log->execute([$id, $uid]);
$log = $log->fetch();

if (!$log) {
    setFlash('danger', 'Log not found or access denied.');
    header('Location: log_list.php');
    exit;
}

$month = date('Y-m', strtotime($log['log_date']));

$statusMeta = [
    'not_started' => ['label' => 'Not Started', 'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => 'fa-clock'],
    'wip'         => ['label' => 'WIP',         'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => 'fa-spinner'],
    'holding'     => ['label' => 'Holding',     'color' => '#6d28d9', 'bg' => '#ede9fe', 'icon' => 'fa-pause-circle'],
    'completed'   => ['label' => 'Completed',   'color' => '#10b981', 'bg' => '#f0fdf4', 'icon' => 'fa-check-circle'],
];
$sm = $statusMeta[$log['status']] ?? ['label' => ucfirst($log['status'] ?? '—'), 'color' => '#9ca3af', 'bg' => '#f9fafb', 'icon' => 'fa-circle'];

$pageTitle = 'View Office Log';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- ── PAGE HERO ── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-building"></i> Office Log
                        </div>
                        <h4><?= htmlspecialchars($log['company_name'] ?? '—') ?></h4>
                        <p>
                            <?= date('l, d M Y', strtotime($log['log_date'])) ?>
                            &nbsp;·&nbsp;
                            <span style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                         font-size:.75rem;font-weight:700;padding:.15rem .55rem;
                                         border-radius:5px;">
                                <i class="fas <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
                            </span>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="office_log_edit.php?id=<?= $log['id'] ?>" class="btn-gold btn btn-sm">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <a href="log_list.php?month=<?= $month ?>&log_type=office"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Logs
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                <!-- ── LEFT ── -->
                <div>

                    <!-- Work Details -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-desktop me-2" style="color:var(--gold)"></i>Work Details
                            </span>
                        </div>
                        <div style="padding:18px 20px;">
                            <div class="row g-4">

                                <!-- Client -->
                                <div class="col-md-6">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
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

                                <!-- Date -->
                                <div class="col-md-6">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-calendar me-1"></i>Date
                                    </div>
                                    <div style="font-size:.95rem;font-weight:700;color:#1f2937;">
                                        <?= date('d M Y', strtotime($log['log_date'])) ?>
                                    </div>
                                    <div style="font-size:.75rem;color:#6b7280;margin-top:2px;">
                                        <?= date('l', strtotime($log['log_date'])) ?>
                                        &nbsp;·&nbsp; <?= date('F Y', strtotime($log['log_date'])) ?>
                                    </div>
                                </div>

                                <!-- Time In -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-sign-in-alt me-1"></i>Start Time
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#3b82f6;">
                                        <?= $log['time_in'] ? date('h:i A', strtotime($log['time_in'])) : '—' ?>
                                    </div>
                                </div>

                                <!-- Time Out -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-sign-out-alt me-1"></i>End Time
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#8b5cf6;">
                                        <?= $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '—' ?>
                                    </div>
                                </div>

                                <!-- Duration -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-clock me-1"></i>Duration
                                    </div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#c9a84c;">
                                        <?= number_format((float) $log['duration_hours'], 2) ?>h
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-flag me-1"></i>Work Status
                                    </div>
                                    <span style="display:inline-flex;align-items:center;gap:5px;
                                                 background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                                 font-size:.82rem;font-weight:700;
                                                 padding:.3rem .75rem;border-radius:8px;">
                                        <i class="fas <?= $sm['icon'] ?>"></i><?= $sm['label'] ?>
                                    </span>
                                </div>

                                <!-- Department -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-layer-group me-1"></i>Department
                                    </div>
                                    <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                        <?= htmlspecialchars($log['dept_name'] ?? '—') ?>
                                    </div>
                                </div>

                                <!-- Branch -->
                                <div class="col-md-4">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                                                letter-spacing:.05em;margin-bottom:5px;">
                                        <i class="fas fa-map-marker-alt me-1"></i>Branch
                                    </div>
                                    <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                        <?= htmlspecialchars($log['branch_name'] ?? '—') ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <?php if (!empty($log['description'])): ?>
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-align-left me-2" style="color:var(--gold)"></i>Description
                            </span>
                        </div>
                        <div style="padding:18px 20px;">
                            <div style="font-size:.85rem;color:#374151;line-height:1.7;white-space:pre-wrap;">
                                <?= htmlspecialchars($log['description']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Notes -->
                    <?php if (!empty($log['notes'])): ?>
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-sticky-note me-2" style="color:#f59e0b"></i>Notes
                            </span>
                        </div>
                        <div style="padding:16px 20px;">
                            <div style="background:#fffbeb;border-left:3px solid #f59e0b;
                                        border-radius:0 8px 8px 0;padding:12px 14px;
                                        font-size:.84rem;color:#92400e;line-height:1.6;white-space:pre-wrap;">
                                <i class="fas fa-sticky-note me-2" style="color:#f59e0b;"></i>
                                <?= htmlspecialchars($log['notes']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- ── RIGHT ── -->
                <div>

                    <!-- Summary card -->
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Summary
                            </span>
                        </div>
                        <div style="padding:16px;">

                            <!-- Duration big display -->
                            <div style="text-align:center;background:#fffbeb;border-radius:12px;
                                        padding:18px 12px;margin-bottom:14px;
                                        border:1.5px solid #fde68a;">
                                <div style="font-size:2.2rem;font-weight:900;color:#c9a84c;line-height:1;">
                                    <?= number_format((float) $log['duration_hours'], 2) ?>
                                </div>
                                <div style="font-size:.72rem;color:#9ca3af;margin-top:4px;font-weight:600;">
                                    HOURS IN OFFICE
                                </div>
                                <?php if ($log['time_in'] && $log['time_out']): ?>
                                <div style="font-size:.75rem;color:#6b7280;margin-top:6px;">
                                    <?= date('h:i A', strtotime($log['time_in'])) ?>
                                    <span style="color:#d1d5db;"> → </span>
                                    <?= date('h:i A', strtotime($log['time_out'])) ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Meta rows -->
                            <?php
                            $metaRows = [
                                ['fa-calendar-day', 'Date',  date('d M Y', strtotime($log['log_date']))],
                                ['fa-tag',          'Month', date('F Y',   strtotime($log['log_date']))],
                            ];
                            foreach ($metaRows as [$icon, $label, $val]):
                            ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.8rem;">
                                <span style="color:#9ca3af;">
                                    <i class="fas <?= $icon ?> me-2"></i><?= $label ?>
                                </span>
                                <span style="font-weight:600;color:#374151;"><?= $val ?></span>
                            </div>
                            <?php endforeach; ?>

                            <!-- Status -->
                            <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:7px 0;font-size:.8rem;">
                                <span style="color:#9ca3af;">
                                    <i class="fas fa-flag me-2"></i>Status
                                </span>
                                <span style="background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;
                                             font-size:.72rem;font-weight:700;
                                             padding:.2rem .55rem;border-radius:5px;">
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
                            <a href="office_log_edit.php?id=<?= $log['id'] ?>"
                               class="cn-btn cn-btn-gold" style="justify-content:center;">
                                <i class="fas fa-edit"></i> Edit This Log
                            </a>
                            <a href="office_log_create.php"
                               class="cn-btn cn-btn-out" style="justify-content:center;">
                                <i class="fas fa-plus"></i> New Office Log
                            </a>
                            <a href="log_list.php?month=<?= $month ?>&log_type=office"
                               class="cn-btn cn-btn-out" style="justify-content:center;">
                                <i class="fas fa-list"></i> All Logs
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
                                ['fa-hashtag',       'Log ID', '#' . $log['id']],
                                ['fa-layer-group',   'Dept',   $log['dept_name']   ?? '—'],
                                ['fa-map-marker-alt','Branch', $log['branch_name'] ?? '—'],
                            ];
                            foreach ($metaInfo as [$icon, $label, $val]):
                            ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:.78rem;">
                                <span style="color:#9ca3af;">
                                    <i class="fas <?= $icon ?> me-2"></i><?= $label ?>
                                </span>
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