<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();

// Gate: only Core Admin department executive can manage statuses
if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can manage task statuses.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Task Status Management';
$errors    = [];

// ── POST HANDLERS ─────────────────────────────────────────────────────────────

// Add new status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $name  = trim($_POST['status_name'] ?? '');
    $color = trim($_POST['color']       ?? '#6b7280');
    $bg    = trim($_POST['bg_color']    ?? '#f3f4f6');

    if ($name === '') {
        $errors[] = 'Status name is required.';
    } elseif (strlen($name) > 50) {
        $errors[] = 'Status name must be 50 characters or less.';
    } else {
        // Check duplicate
        $dup = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $dup->execute([$name]);
        if ($dup->fetch()) {
            $errors[] = "Status \"$name\" already exists.";
        } else {
            // Auto-add color columns if not exist
            try { $db->query("SELECT color FROM task_status LIMIT 1"); }
            catch (Exception $e) {
                $db->exec("ALTER TABLE task_status ADD COLUMN color VARCHAR(20) NOT NULL DEFAULT '#6b7280' AFTER status_name");
                $db->exec("ALTER TABLE task_status ADD COLUMN bg_color VARCHAR(20) NOT NULL DEFAULT '#f3f4f6' AFTER color");
            }
            $db->prepare("INSERT INTO task_status (status_name, color, bg_color) VALUES (?, ?, ?)")
               ->execute([$name, $color, $bg]);
            logActivity("Added task status: $name (color: $color, bg: $bg)", 'task_status');
            setFlash('success', "Status \"$name\" added successfully.");
            header('Location: task_status.php'); exit;
        }
    }
}

// Edit status name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $sid  = (int)($_POST['status_id']   ?? 0);
    $name = trim($_POST['status_name']  ?? '');

    if (!$sid)         $errors[] = 'Invalid status.';
    if ($name === '')  $errors[] = 'Status name is required.';

    if (!$errors) {
        // Check not duplicate (excluding self)
        $dup = $db->prepare("SELECT id FROM task_status WHERE status_name = ? AND id != ?");
        $dup->execute([$name, $sid]);
        if ($dup->fetch()) {
            $errors[] = "Status \"$name\" already exists.";
        } else {
            $db->prepare("UPDATE task_status SET status_name = ? WHERE id = ?")
               ->execute([$name, $sid]);
            logActivity("Updated task status ID $sid to: $name", 'task_status');
            setFlash('success', 'Status updated.');
            header('Location: task_status.php'); exit;
        }
    }
}

// Delete status — only if no tasks use it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $sid = (int)($_POST['status_id'] ?? 0);
    if ($sid) {
        $inUse = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status_id = ?");
        $inUse->execute([$sid]);
        if ((int)$inUse->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete — this status is used by existing tasks. Reassign those tasks first.');
        } else {
            $nameRow = $db->prepare("SELECT status_name FROM task_status WHERE id = ?");
            $nameRow->execute([$sid]);
            $deletedName = $nameRow->fetchColumn();
            $db->prepare("DELETE FROM task_status WHERE id = ?")->execute([$sid]);
            logActivity("Deleted task status: $deletedName", 'task_status');
            setFlash('success', "Status \"$deletedName\" deleted.");
        }
        header('Location: task_status.php'); exit;
    }
}

// ── Fetch all statuses with usage count ──────────────────────────────────────
// Fetch statuses — try with color columns, fall back if not yet added
try {
    $statuses = $db->query("
        SELECT ts.id, ts.status_name,
               ts.color, ts.bg_color,
               COUNT(t.id) AS task_count
        FROM task_status ts
        LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
        GROUP BY ts.id, ts.status_name, ts.color, ts.bg_color
        ORDER BY ts.id ASC
    ")->fetchAll();
} catch (Exception $e) {
    $statuses = $db->query("
        SELECT ts.id, ts.status_name,
               NULL AS color, NULL AS bg_color,
               COUNT(t.id) AS task_count
        FROM task_status ts
        LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
        GROUP BY ts.id, ts.status_name
        ORDER BY ts.id ASC
    ")->fetchAll();
}

// Colour map for display (matches TASK_STATUSES config if defined)
$colorMap = defined('TASK_STATUSES') ? TASK_STATUSES : [];

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
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-sliders"></i> Settings</div>
            <h4>Task Status Management</h4>
            <p>Add, rename, or remove task statuses. Only Core Admin executives can manage this.</p>
        </div>
        <button class="btn btn-gold btn-sm" onclick="openAddModal()">
            <i class="fas fa-plus me-1"></i>Add New Status
        </button>
    </div>
</div>

<!-- Status Table -->
<div class="card-mis">
    <div class="card-mis-header">
        <h5><i class="fas fa-circle-dot text-warning me-2"></i>All Statuses</h5>
        <span style="font-size:.75rem;color:#9ca3af;"><?= count($statuses) ?> statuses</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Status Name</th>
                    <th class="text-center">Tasks Using</th>
                    <th class="text-center">Preview</th>
                    <th class="text-center" style="width:140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($statuses)): ?>
            <tr><td colspan="5" class="empty-state">No statuses found.</td></tr>
            <?php endif; ?>
            <?php foreach ($statuses as $s):
                $sKey = $s['status_name'];
                $sCol = $s['color']  ?? $colorMap[$sKey]['color'] ?? '#6b7280';
                $sBg  = $s['bg_color'] ?? $colorMap[$sKey]['bg']    ?? '#f3f4f6';
            ?>
            <tr>
                <td style="color:#9ca3af;font-size:.8rem;"><?= $s['id'] ?></td>
                <td>
                    <span style="font-weight:600;font-size:.88rem;">
                        <?= htmlspecialchars($s['status_name']) ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ($s['task_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#3b82f6;padding:.2rem .6rem;
                                 border-radius:99px;font-size:.75rem;font-weight:600;">
                        <?= $s['task_count'] ?> tasks
                    </span>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:.78rem;">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span class="status-badge"
                          style="background:<?= $sBg ?>;color:<?= $sCol ?>;
                                 padding:.25rem .75rem;border-radius:99px;
                                 font-size:.75rem;font-weight:600;">
                        <?= htmlspecialchars($s['status_name']) ?>
                    </span>
                </td>
                <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center">
                        <!-- Edit -->
                        <button onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['status_name'])) ?>')"
                                style="background:#eff6ff;color:#3b82f6;border:none;border-radius:6px;
                                       padding:.3rem .65rem;font-size:.75rem;cursor:pointer;"
                                title="Rename">
                            <i class="fas fa-pen"></i>
                        </button>
                        <!-- Delete — disabled if in use -->
                        <?php if ($s['task_count'] > 0): ?>
                        <button disabled
                                title="Cannot delete — used by <?= $s['task_count'] ?> task(s)"
                                style="background:#f3f4f6;color:#d1d5db;border:none;border-radius:6px;
                                       padding:.3rem .65rem;font-size:.75rem;cursor:not-allowed;">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php else: ?>
                        <button onclick="confirmDelete(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['status_name'])) ?>')"
                                style="background:#fef2f2;color:#ef4444;border:none;border-radius:6px;
                                       padding:.3rem .65rem;font-size:.75rem;cursor:pointer;"
                                title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Info note -->
<div style="margin-top:1rem;padding:.75rem 1rem;background:#fefce8;border:1px solid #fde68a;
            border-radius:10px;font-size:.8rem;color:#92400e;display:flex;gap:.6rem;align-items:flex-start;">
    <i class="fas fa-triangle-exclamation" style="margin-top:.1rem;flex-shrink:0;"></i>
    <div>
        <strong>Note:</strong> Statuses in use by tasks cannot be deleted. Rename is always allowed.
        Colors set here are saved to the database and applied immediately across the system.
    </div>
</div>

</div><!-- padding -->
</div><!-- main-content -->
</div><!-- app-wrapper -->

<!-- ── Add Modal ─────────────────────────────────────────────────────────────── -->
<div id="add-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;width:100%;max-width:420px;
                box-shadow:0 20px 60px rgba(0,0,0,.2);margin:1rem;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 style="margin:0;font-size:1rem;font-weight:700;">
                <i class="fas fa-plus text-warning me-2"></i>Add New Status
            </h5>
            <button onclick="closeModals()"
                    style="background:none;border:none;font-size:1.1rem;color:#9ca3af;cursor:pointer;">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"     value="add">
            <div class="mb-3">
                <label class="form-label-mis">Status Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="status_name" id="add-name"
                       class="form-control" placeholder="e.g. On Hold, Holding, Reviewing…"
                       maxlength="50" required autofocus>
                <small style="font-size:.72rem;color:#9ca3af;">Max 50 characters. Must be unique.</small>
            </div>
            <!-- Colour pickers -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label-mis">Text Colour</label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <input type="color" name="color" id="add-color"
                               value="#6b7280"
                               style="width:42px;height:34px;border:1px solid #e5e7eb;
                                      border-radius:8px;cursor:pointer;padding:2px;">
                        <input type="text" id="add-color-hex"
                               value="#6b7280"
                               maxlength="7"
                               class="form-control form-control-sm"
                               style="font-family:monospace;font-size:.8rem;"
                               placeholder="#6b7280">
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label-mis">Background Colour</label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <input type="color" name="bg_color" id="add-bg"
                               value="#f3f4f6"
                               style="width:42px;height:34px;border:1px solid #e5e7eb;
                                      border-radius:8px;cursor:pointer;padding:2px;">
                        <input type="text" id="add-bg-hex"
                               value="#f3f4f6"
                               maxlength="7"
                               class="form-control form-control-sm"
                               style="font-family:monospace;font-size:.8rem;"
                               placeholder="#f3f4f6">
                    </div>
                </div>
            </div>
            <!-- Live preview -->
            <div class="mb-4">
                <label class="form-label-mis">Live Preview</label>
                <div style="padding:.5rem 0;">
                    <span id="add-preview"
                          style="background:#f3f4f6;color:#6b7280;padding:.3rem .9rem;
                                 border-radius:99px;font-size:.8rem;font-weight:600;">
                        New Status
                    </span>
                </div>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" onclick="closeModals()"
                        style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                               padding:.55rem 1.1rem;font-size:.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-gold btn-sm"
                        style="padding:.55rem 1.25rem;">
                    <i class="fas fa-plus me-1"></i>Add Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────────── -->
<div id="edit-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;width:100%;max-width:420px;
                box-shadow:0 20px 60px rgba(0,0,0,.2);margin:1rem;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 style="margin:0;font-size:1rem;font-weight:700;">
                <i class="fas fa-pen text-warning me-2"></i>Rename Status
            </h5>
            <button onclick="closeModals()"
                    style="background:none;border:none;font-size:1.1rem;color:#9ca3af;cursor:pointer;">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
            <input type="hidden" name="action"      value="edit">
            <input type="hidden" name="status_id"   id="edit-id">
            <div class="mb-4">
                <label class="form-label-mis">Status Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="status_name" id="edit-name"
                       class="form-control" maxlength="50" required>
                <small style="font-size:.72rem;color:#9ca3af;">Renaming will update everywhere this status appears.</small>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" onclick="closeModals()"
                        style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                               padding:.55rem 1.1rem;font-size:.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-gold btn-sm" style="padding:.55rem 1.25rem;">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete confirm form (hidden) ──────────────────────────────────────────── -->
<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action"     value="delete">
    <input type="hidden" name="status_id"  id="delete-id">
</form>

<script>
function openAddModal() {
    document.getElementById('add-name').value = '';
    document.getElementById('add-preview').textContent = 'New Status';
    document.getElementById('add-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('add-name').focus(), 50);
}

function openEditModal(id, name) {
    document.getElementById('edit-id').value   = id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('edit-name').focus(), 50);
}

function closeModals() {
    document.getElementById('add-modal').style.display  = 'none';
    document.getElementById('edit-modal').style.display = 'none';
}

// Sync color picker ↔ hex input, update preview
function syncColor(pickerId, hexId) {
    const picker = document.getElementById(pickerId);
    const hex    = document.getElementById(hexId);
    picker.addEventListener('input', () => { hex.value = picker.value; updatePreview(); });
    hex.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) {
            picker.value = hex.value; updatePreview();
        }
    });
}
syncColor('add-color', 'add-color-hex');
syncColor('add-bg',    'add-bg-hex');

function updatePreview() {
    const preview = document.getElementById('add-preview');
    preview.style.color      = document.getElementById('add-color').value;
    preview.style.background = document.getElementById('add-bg').value;
}

document.getElementById('add-name').addEventListener('input', function() {
    const preview = document.getElementById('add-preview');
    preview.textContent = this.value.trim() || 'New Status';
});

function confirmDelete(id, name) {
    if (!confirm(`Delete status "${name}"?\n\nThis cannot be undone.`)) return;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-form').submit();
}

// Close modal on backdrop click
['add-modal','edit-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModals();
    });
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModals();
});
</script>

<?php include '../../includes/footer.php'; ?>