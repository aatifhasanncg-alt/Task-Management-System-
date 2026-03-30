<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can manage grades.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Corporate Grade Management';
$errors    = [];

// ── POST: Add ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $name   = trim($_POST['grade_name']   ?? '');
    $min    = $_POST['min_profit']        ?? '';
    $max    = $_POST['max_profit']        ?? '';
    $desc   = trim($_POST['description']  ?? '');
    $active = (int)($_POST['is_active']   ?? 1);

    if ($name === '') $errors[] = 'Grade name is required.';
    else {
        $dup = $db->prepare("SELECT id FROM corporate_grades WHERE grade_name = ?");
        $dup->execute([$name]);
        if ($dup->fetch()) {
            $errors[] = "Grade \"$name\" already exists.";
        } else {
            $db->prepare("INSERT INTO corporate_grades
                (grade_name, min_profit, max_profit, description, is_active)
                VALUES (?,?,?,?,?)")
               ->execute([$name, $min !== '' ? (float)$min : null,
                                 $max !== '' ? (float)$max : null,
                                 $desc ?: null, $active]);
            logActivity("Added corporate grade: $name", 'corporate_grades');
            setFlash('success', "Grade \"$name\" added.");
            header('Location: corporate_grades.php'); exit;
        }
    }
}

// ── POST: Edit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $gid    = (int)($_POST['grade_id']    ?? 0);
    $name   = trim($_POST['grade_name']   ?? '');
    $min    = $_POST['min_profit']        ?? '';
    $max    = $_POST['max_profit']        ?? '';
    $desc   = trim($_POST['description']  ?? '');
    $active = (int)($_POST['is_active']   ?? 1);

    if (!$gid)        $errors[] = 'Invalid grade.';
    if ($name === '') $errors[] = 'Grade name is required.';

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM corporate_grades WHERE grade_name = ? AND id != ?");
        $dup->execute([$name, $gid]);
        if ($dup->fetch()) {
            $errors[] = "Grade \"$name\" already exists.";
        } else {
            $db->prepare("UPDATE corporate_grades SET
                grade_name=?, min_profit=?, max_profit=?, description=?, is_active=?
                WHERE id=?")
               ->execute([$name, $min !== '' ? (float)$min : null,
                                 $max !== '' ? (float)$max : null,
                                 $desc ?: null, $active, $gid]);
            logActivity("Updated corporate grade ID $gid → $name", 'corporate_grades');
            setFlash('success', 'Grade updated.');
            header('Location: corporate_grades.php'); exit;
        }
    }
}

// ── POST: Delete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $gid = (int)($_POST['grade_id'] ?? 0);
    if ($gid) {
        $inUse = $db->prepare("SELECT COUNT(*) FROM task_corporate WHERE grade_id = ?");
        $inUse->execute([$gid]);
        if ((int)$inUse->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete — grade is in use by tasks.');
        } else {
            $nameRow = $db->prepare("SELECT grade_name FROM corporate_grades WHERE id = ?");
            $nameRow->execute([$gid]);
            $deletedName = $nameRow->fetchColumn();
            $db->prepare("DELETE FROM corporate_grades WHERE id = ?")->execute([$gid]);
            logActivity("Deleted corporate grade: $deletedName", 'corporate_grades');
            setFlash('success', "Grade \"$deletedName\" deleted.");
        }
        header('Location: corporate_grades.php'); exit;
    }
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
$grades = $db->query("
    SELECT cg.id, cg.grade_name, cg.min_profit, cg.max_profit,
           cg.description, cg.is_active,
           COUNT(tc.id) AS task_count
    FROM corporate_grades cg
    LEFT JOIN task_corporate tc ON tc.grade_id = cg.id
    GROUP BY cg.id
    ORDER BY cg.min_profit ASC
")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-3">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-chart-line"></i> Corporate</div>
            <h4>Corporate Grades</h4>
            <p>Manage grade levels used in corporate task assignments.</p>
        </div>
        <button class="btn btn-gold btn-sm" onclick="openAdd()">
            <i class="fas fa-plus me-1"></i>Add Grade
        </button>
    </div>
</div>

<!-- Grade Cards -->
<div class="row g-3">
<?php foreach ($grades as $g): ?>
<div class="col-md-6 col-lg-4">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;">

        <!-- Header row -->
        <div style="display:flex;align-items:center;
                    justify-content:space-between;gap:.75rem;margin-bottom:.6rem;">
            <div style="display:flex;align-items:center;gap:.6rem;min-width:0;">
                <span style="background:<?= $g['is_active'] ? '#f0fdf4' : '#f9fafb' ?>;
                             color:<?= $g['is_active'] ? '#16a34a' : '#9ca3af' ?>;
                             padding:.25rem .8rem;border-radius:99px;
                             font-size:.78rem;font-weight:700;white-space:nowrap;">
                    <i class="fas <?= $g['is_active'] ? 'fa-chart-line' : 'fa-ban' ?> me-1"></i>
                    <?= htmlspecialchars($g['grade_name']) ?>
                </span>
                <?php if (!$g['is_active']): ?>
                    <span style="font-size:.68rem;color:#9ca3af;background:#f3f4f6;
                                 padding:.15rem .5rem;border-radius:99px;">Inactive</span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:.4rem;flex-shrink:0;">
                <button onclick="openEdit(
                            <?= $g['id'] ?>,
                            '<?= htmlspecialchars(addslashes($g['grade_name'])) ?>',
                            '<?= $g['min_profit'] ?>',
                            '<?= $g['max_profit'] ?>',
                            '<?= htmlspecialchars(addslashes($g['description'] ?? '')) ?>',
                            <?= $g['is_active'] ?>
                        )"
                        style="background:#eff6ff;color:#3b82f6;border:none;
                               border-radius:6px;padding:.3rem .6rem;
                               font-size:.75rem;cursor:pointer;" title="Edit">
                    <i class="fas fa-pen"></i>
                </button>
                <?php if ($g['task_count'] > 0): ?>
                <button disabled title="In use by <?= $g['task_count'] ?> task(s)"
                        style="background:#f9fafb;color:#d1d5db;border:none;
                               border-radius:6px;padding:.3rem .6rem;
                               font-size:.75rem;cursor:not-allowed;">
                    <i class="fas fa-trash"></i>
                </button>
                <?php else: ?>
                <button onclick="confirmDelete(<?= $g['id'] ?>, '<?= htmlspecialchars(addslashes($g['grade_name'])) ?>')"
                        style="background:#fef2f2;color:#ef4444;border:none;
                               border-radius:6px;padding:.3rem .6rem;
                               font-size:.75rem;cursor:pointer;" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profit range -->
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
            <?php if ($g['min_profit'] !== null): ?>
            <span style="font-size:.72rem;background:#eff6ff;color:#3b82f6;
                         padding:.2rem .55rem;border-radius:6px;">
                Min: <?= number_format((float)$g['min_profit'], 2) ?>
            </span>
            <?php endif; ?>
            <?php if ($g['max_profit'] !== null): ?>
            <span style="font-size:.72rem;background:#fefce8;color:#b45309;
                         padding:.2rem .55rem;border-radius:6px;">
                Max: <?= number_format((float)$g['max_profit'], 2) ?>
            </span>
            <?php endif; ?>
            <?php if ($g['task_count'] > 0): ?>
            <span style="font-size:.72rem;background:#f3f4f6;color:#6b7280;
                         padding:.2rem .55rem;border-radius:6px;">
                <?= $g['task_count'] ?> task<?= $g['task_count'] > 1 ? 's' : '' ?>
            </span>
            <?php endif; ?>
        </div>

        <!-- Description -->
        <?php if ($g['description']): ?>
        <div style="font-size:.75rem;color:#9ca3af;
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($g['description']) ?>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endforeach; ?>

<?php if (empty($grades)): ?>
<div class="col-12">
    <div style="text-align:center;padding:3rem;color:#9ca3af;">
        <i class="fas fa-chart-line fa-2x mb-2 d-block opacity-40"></i>
        No grades yet. Add one above.
    </div>
</div>
<?php endif; ?>
</div>

<div style="margin-top:1rem;padding:.75rem 1rem;background:#fefce8;
            border:1px solid #fde68a;border-radius:10px;
            font-size:.8rem;color:#92400e;
            display:flex;gap:.6rem;align-items:flex-start;">
    <i class="fas fa-triangle-exclamation" style="margin-top:.1rem;flex-shrink:0;"></i>
    <span>Grades used by tasks cannot be deleted — only renamed or edited.</span>
</div>

</div>
</div>
</div>

<!-- ── Modal ─────────────────────────────────────────────────────────────────── -->
<div id="grade-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;
                width:100%;max-width:440px;
                box-shadow:0 20px 60px rgba(0,0,0,.2);
                max-height:90vh;overflow-y:auto;">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 id="modal-title" style="margin:0;font-size:1rem;font-weight:700;"></h5>
            <button onclick="closeModal()"
                    style="background:none;border:none;font-size:1.1rem;
                           color:#9ca3af;cursor:pointer;">✕</button>
        </div>

        <form method="POST" id="grade-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"   id="form-action">
            <input type="hidden" name="grade_id" id="form-grade-id">

            <div class="mb-3">
                <label class="form-label-mis">Grade Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="grade_name" id="form-name"
                       class="form-control" required
                       placeholder="e.g. Grade A, Premium, Standard…">
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label-mis">Min Profit</label>
                    <input type="number" step="0.01" name="min_profit" id="form-min"
                           class="form-control" placeholder="0.00">
                </div>
                <div class="col-6">
                    <label class="form-label-mis">Max Profit</label>
                    <input type="number" step="0.01" name="max_profit" id="form-max"
                           class="form-control" placeholder="0.00">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label-mis">Description</label>
                <textarea name="description" id="form-desc"
                          class="form-control form-control-sm" rows="2"
                          placeholder="Optional description…"></textarea>
            </div>

            <div class="mb-4">
                <label class="form-label-mis">Status</label>
                <select name="is_active" id="form-active" class="form-select">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div class="d-flex gap-2 justify-content-end">
                <button type="button" onclick="closeModal()"
                        style="background:#f3f4f6;color:#6b7280;border:none;
                               border-radius:8px;padding:.55rem 1.1rem;
                               font-size:.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="form-submit" class="btn btn-gold btn-sm"
                        style="padding:.55rem 1.25rem;">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete form -->
<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action"   value="delete">
    <input type="hidden" name="grade_id" id="delete-id">
</form>

<script>
function openAdd() {
    document.getElementById('modal-title').textContent = 'Add New Grade';
    document.getElementById('form-submit').textContent = 'Add Grade';
    document.getElementById('form-action').value       = 'add';
    document.getElementById('form-grade-id').value     = '';
    document.getElementById('form-name').value         = '';
    document.getElementById('form-min').value          = '';
    document.getElementById('form-max').value          = '';
    document.getElementById('form-desc').value         = '';
    document.getElementById('form-active').value       = '1';
    document.getElementById('grade-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function openEdit(id, name, min, max, desc, active) {
    document.getElementById('modal-title').textContent = 'Edit Grade';
    document.getElementById('form-submit').textContent = 'Save Changes';
    document.getElementById('form-action').value       = 'edit';
    document.getElementById('form-grade-id').value     = id;
    document.getElementById('form-name').value         = name;
    document.getElementById('form-min').value          = min ?? '';
    document.getElementById('form-max').value          = max ?? '';
    document.getElementById('form-desc').value         = desc;
    document.getElementById('form-active').value       = active;
    document.getElementById('grade-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function closeModal() {
    document.getElementById('grade-modal').style.display = 'none';
}

document.getElementById('grade-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});

function confirmDelete(id, name) {
    if (!confirm(`Delete "${name}"?\n\nThis cannot be undone.`)) return;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-form').submit();
}
</script>

<?php include '../../includes/footer.php'; ?>