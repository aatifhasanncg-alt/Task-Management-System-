<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can manage task statuses.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Task Status Management';
$errors    = [];

// ── Ensure columns exist ──────────────────────────────────────────────────────
try { $db->query("SELECT color FROM task_status LIMIT 1"); }
catch (Exception $e) {
    $db->exec("ALTER TABLE task_status
        ADD COLUMN color    VARCHAR(20) DEFAULT '#9ca3af' AFTER status_name,
        ADD COLUMN bg_color VARCHAR(20) DEFAULT '#f3f4f6' AFTER color,
        ADD COLUMN icon     VARCHAR(80) DEFAULT 'fa-circle-dot' AFTER bg_color");
}
try { $db->query("SELECT icon FROM task_status LIMIT 1"); }
catch (Exception $e) {
    $db->exec("ALTER TABLE task_status
        ADD COLUMN icon VARCHAR(80) DEFAULT 'fa-circle-dot' AFTER bg_color");
}

// ── POST: Add ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $name  = trim($_POST['status_name'] ?? '');
    $color = trim($_POST['color']       ?? '#9ca3af');
    $bg    = trim($_POST['bg_color']    ?? '#f3f4f6');
    $icon  = trim($_POST['icon']        ?? 'fa-circle-dot');

    if ($name === '')         $errors[] = 'Status name is required.';
    elseif (strlen($name) > 50) $errors[] = 'Max 50 characters.';
    else {
        $dup = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $dup->execute([$name]);
        if ($dup->fetch()) {
            $errors[] = "Status \"$name\" already exists.";
        } else {
            $db->prepare("INSERT INTO task_status (status_name, color, bg_color, icon) VALUES (?,?,?,?)")
               ->execute([$name, $color, $bg, $icon]);
            logActivity("Added task status: $name", 'task_status');
            setFlash('success', "Status \"$name\" added.");
            header('Location: task_status.php'); exit;
        }
    }
}

// ── POST: Edit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $sid   = (int)($_POST['status_id']   ?? 0);
    $name  = trim($_POST['status_name']  ?? '');
    $color = trim($_POST['color']        ?? '#9ca3af');
    $bg    = trim($_POST['bg_color']     ?? '#f3f4f6');
    $icon  = trim($_POST['icon']         ?? 'fa-circle-dot');

    if (!$sid)        $errors[] = 'Invalid status.';
    if ($name === '') $errors[] = 'Status name is required.';

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM task_status WHERE status_name = ? AND id != ?");
        $dup->execute([$name, $sid]);
        if ($dup->fetch()) {
            $errors[] = "Status \"$name\" already exists.";
        } else {
            $db->prepare("UPDATE task_status SET status_name=?, color=?, bg_color=?, icon=? WHERE id=?")
               ->execute([$name, $color, $bg, $icon, $sid]);
            logActivity("Updated task status ID $sid → $name", 'task_status');
            setFlash('success', 'Status updated.');
            header('Location: task_status.php'); exit;
        }
    }
}

// ── POST: Delete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $sid = (int)($_POST['status_id'] ?? 0);
    if ($sid) {
        $inUse = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status_id = ?");
        $inUse->execute([$sid]);
        if ((int)$inUse->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete — status is in use. Reassign those tasks first.');
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

// ── Fetch ─────────────────────────────────────────────────────────────────────
$statuses = $db->query("
    SELECT ts.id, ts.status_name,
           COALESCE(ts.color,    '#9ca3af')      AS color,
           COALESCE(ts.bg_color, '#f3f4f6')      AS bg_color,
           COALESCE(ts.icon,     'fa-circle-dot') AS icon,
           COUNT(t.id) AS task_count
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
    GROUP BY ts.id, ts.status_name, ts.color, ts.bg_color, ts.icon
    ORDER BY ts.id ASC
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
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-sliders"></i> Settings</div>
            <h4>Task Statuses</h4>
            <p>Manage status options, colors, and icons used across all tasks.</p>
        </div>
        <button class="btn btn-gold btn-sm" onclick="openAdd()">
            <i class="fas fa-plus me-1"></i>Add Status
        </button>
    </div>
</div>

<!-- Status Cards -->
<div class="row g-3">
<?php foreach ($statuses as $s): ?>
<div class="col-md-6 col-lg-4">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;
                justify-content:space-between;gap:.75rem;">
        <div style="display:flex;align-items:center;gap:.65rem;min-width:0;">
            <span style="background:<?= htmlspecialchars($s['bg_color']) ?>;
                         color:<?= htmlspecialchars($s['color']) ?>;
                         padding:.25rem .8rem;border-radius:99px;
                         font-size:.78rem;font-weight:600;
                         display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;">
                <i class="fas <?= htmlspecialchars($s['icon']) ?>"></i>
                <?= htmlspecialchars($s['status_name']) ?>
            </span>
            <?php if ($s['task_count'] > 0): ?>
            <span style="font-size:.7rem;color:#9ca3af;white-space:nowrap;">
                <?= $s['task_count'] ?> task<?= $s['task_count'] > 1 ? 's' : '' ?>
            </span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:.4rem;flex-shrink:0;">
            <button onclick="openEdit(
                        <?= $s['id'] ?>,
                        '<?= htmlspecialchars(addslashes($s['status_name'])) ?>',
                        '<?= $s['color'] ?>',
                        '<?= $s['bg_color'] ?>',
                        '<?= htmlspecialchars(addslashes($s['icon'])) ?>'
                    )"
                    style="background:#eff6ff;color:#3b82f6;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:pointer;" title="Edit">
                <i class="fas fa-pen"></i>
            </button>
            <?php if ($s['task_count'] > 0): ?>
            <button disabled title="In use by <?= $s['task_count'] ?> task(s)"
                    style="background:#f9fafb;color:#d1d5db;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:not-allowed;">
                <i class="fas fa-trash"></i>
            </button>
            <?php else: ?>
            <button onclick="confirmDelete(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['status_name'])) ?>')"
                    style="background:#fef2f2;color:#ef4444;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:pointer;" title="Delete">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($statuses)): ?>
<div class="col-12">
    <div style="text-align:center;padding:3rem;color:#9ca3af;">
        <i class="fas fa-circle-dot fa-2x mb-2 d-block opacity-40"></i>
        No statuses yet. Add one above.
    </div>
</div>
<?php endif; ?>
</div>

<div style="margin-top:1rem;padding:.75rem 1rem;background:#fefce8;
            border:1px solid #fde68a;border-radius:10px;
            font-size:.8rem;color:#92400e;
            display:flex;gap:.6rem;align-items:flex-start;">
    <i class="fas fa-triangle-exclamation" style="margin-top:.1rem;flex-shrink:0;"></i>
    <span>Statuses used by tasks cannot be deleted — only renamed, recoloured, or re-iconned.</span>
</div>

</div>
</div>
</div>

<!-- ── Modal ──────────────────────────────────────────────────────────────────── -->
<div id="status-modal"
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

        <form method="POST" id="status-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"     id="form-action">
            <input type="hidden" name="status_id"  id="form-status-id">
            <input type="hidden" name="icon"       id="form-icon-value" value="fa-circle-dot">

            <!-- Name -->
            <div class="mb-3">
                <label class="form-label-mis">Status Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="status_name" id="form-name"
                       class="form-control" maxlength="50" required
                       placeholder="e.g. On Hold, In Review…">
            </div>

            <!-- Colours -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label-mis">Text colour</label>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <input type="color" name="color" id="form-color"
                               style="width:38px;height:34px;border:1px solid #e5e7eb;
                                      border-radius:8px;cursor:pointer;
                                      padding:2px;flex-shrink:0;">
                        <input type="text" id="form-color-hex" maxlength="7"
                               class="form-control form-control-sm"
                               style="font-family:monospace;font-size:.8rem;">
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label-mis">Background</label>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <input type="color" name="bg_color" id="form-bg"
                               style="width:38px;height:34px;border:1px solid #e5e7eb;
                                      border-radius:8px;cursor:pointer;
                                      padding:2px;flex-shrink:0;">
                        <input type="text" id="form-bg-hex" maxlength="7"
                               class="form-control form-control-sm"
                               style="font-family:monospace;font-size:.8rem;">
                    </div>
                </div>
            </div>

            <!-- Icon picker -->
            <div class="mb-3">
                <label class="form-label-mis">Icon
                    <span style="font-size:.68rem;color:#9ca3af;margin-left:.3rem;">
                        Font Awesome 6 Free
                    </span>
                </label>

                <!-- Search -->
                <div style="position:relative;margin-bottom:.5rem;">
                    <i class="fas fa-magnifying-glass"
                       style="position:absolute;left:.7rem;top:50%;
                              transform:translateY(-50%);color:#9ca3af;
                              font-size:.8rem;pointer-events:none;"></i>
                    <input type="text" id="icon-search"
                           class="form-control form-control-sm"
                           style="padding-left:2rem;"
                           placeholder="Search icons… e.g. check, clock, star">
                </div>

                <!-- Selected display -->
                <div id="icon-selected-display"
                     style="display:flex;align-items:center;gap:.6rem;
                            padding:.5rem .75rem;background:#f9fafb;
                            border:1px solid #e5e7eb;border-radius:8px;
                            margin-bottom:.5rem;font-size:.82rem;">
                    <i id="icon-selected-preview" class="fas fa-circle-dot"
                       style="font-size:1rem;color:#6b7280;"></i>
                    <span id="icon-selected-name"
                          style="font-family:monospace;color:#374151;">fa-circle-dot</span>
                    <span style="color:#9ca3af;font-size:.72rem;margin-left:auto;">selected</span>
                </div>

                <!-- Grid -->
                <div id="icon-grid"
                     style="display:grid;grid-template-columns:repeat(8,1fr);
                            gap:.3rem;max-height:180px;overflow-y:auto;
                            border:1px solid #f3f4f6;border-radius:8px;padding:.4rem;">
                </div>
            </div>

            <!-- Live preview -->
            <div class="mb-4">
                <label class="form-label-mis">Preview</label>
                <div style="padding:.4rem 0;">
                    <span id="form-preview"
                          style="padding:.3rem .9rem;border-radius:99px;
                                 font-size:.8rem;font-weight:600;
                                 display:inline-flex;align-items:center;gap:.35rem;">
                        <i id="preview-icon" class="fas fa-circle-dot"></i>
                        <span id="preview-text">Preview</span>
                    </span>
                </div>
            </div>

            <div class="d-flex gap-2 justify-content-end">
                <button type="button" onclick="closeModal()"
                        style="background:#f3f4f6;color:#6b7280;border:none;
                               border-radius:8px;padding:.55rem 1.1rem;
                               font-size:.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="form-submit"
                        class="btn btn-gold btn-sm"
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
    <input type="hidden" name="action"    value="delete">
    <input type="hidden" name="status_id" id="delete-id">
</form>

<script>
// ── Icon list (FA6 Free solid — common subset) ────────────────────────────────
const FA_ICONS = [
    'fa-circle-dot','fa-circle-check','fa-circle-xmark','fa-circle-pause',
    'fa-circle-play','fa-circle-stop','fa-circle-half-stroke',
    'fa-check','fa-xmark','fa-ban','fa-pause','fa-play','fa-stop',
    'fa-clock','fa-hourglass-half','fa-hourglass-start','fa-hourglass-end',
    'fa-calendar','fa-calendar-check','fa-calendar-xmark','fa-calendar-days',
    'fa-flag','fa-flag-checkered','fa-bookmark','fa-star','fa-heart',
    'fa-bolt','fa-fire','fa-snowflake','fa-sun','fa-moon',
    'fa-spinner','fa-rotate','fa-arrow-rotate-right','fa-arrows-rotate',
    'fa-triangle-exclamation','fa-exclamation','fa-info','fa-question',
    'fa-thumbs-up','fa-thumbs-down','fa-hand','fa-hand-point-right',
    'fa-lock','fa-lock-open','fa-key','fa-shield','fa-shield-halved',
    'fa-eye','fa-eye-slash','fa-pen','fa-pen-to-square','fa-pencil',
    'fa-trash','fa-archive','fa-box-archive','fa-folder','fa-folder-open',
    'fa-file','fa-file-check','fa-file-pen','fa-file-circle-check',
    'fa-list-check','fa-clipboard-check','fa-clipboard-list','fa-clipboard',
    'fa-chart-bar','fa-chart-line','fa-chart-pie','fa-gauge','fa-gauge-high',
    'fa-user','fa-user-check','fa-user-clock','fa-users','fa-user-group',
    'fa-building','fa-store','fa-briefcase','fa-receipt','fa-money-bill',
    'fa-tag','fa-tags','fa-layer-group','fa-cubes','fa-cube',
    'fa-gear','fa-gears','fa-wrench','fa-screwdriver-wrench','fa-hammer',
    'fa-bell','fa-bell-slash','fa-envelope','fa-envelope-open','fa-comment',
    'fa-comments','fa-message','fa-phone','fa-mobile','fa-laptop',
    'fa-globe','fa-wifi','fa-link','fa-paperclip','fa-share-nodes',
    'fa-arrow-up','fa-arrow-down','fa-arrow-right','fa-arrow-left',
    'fa-angles-up','fa-angles-down','fa-angles-right','fa-chevron-up',
    'fa-chevron-down','fa-chevron-right','fa-chevron-left',
    'fa-up-right-and-down-left-from-center','fa-maximize','fa-minimize',
    'fa-plus','fa-minus','fa-equals','fa-divide','fa-percent',
    'fa-hashtag','fa-at','fa-code','fa-terminal','fa-database',
    'fa-server','fa-network-wired','fa-sitemap','fa-diagram-project',
    'fa-timeline','fa-road','fa-map','fa-map-pin','fa-location-dot',
    'fa-house','fa-landmark','fa-school','fa-hospital','fa-truck',
    'fa-car','fa-plane','fa-train','fa-ship','fa-bicycle',
    'fa-coffee','fa-mug-hot','fa-utensils','fa-apple-whole','fa-leaf',
    'fa-recycle','fa-seedling','fa-tree','fa-mountain','fa-water',
];

let currentIcon = 'fa-circle-dot';

function renderGrid(filter) {
    const grid    = document.getElementById('icon-grid');
    const filtered = filter
        ? FA_ICONS.filter(ic => ic.includes(filter.toLowerCase()))
        : FA_ICONS;

    grid.innerHTML = filtered.map(ic => `
        <button type="button"
                onclick="selectIcon('${ic}')"
                id="ic-${ic.replace(/[^a-z0-9]/g,'-')}"
                title="${ic}"
                style="aspect-ratio:1;border-radius:6px;border:2px solid transparent;
                       background:#f9fafb;cursor:pointer;font-size:.85rem;
                       display:flex;align-items:center;justify-content:center;
                       transition:all .15s;"
                onmouseover="this.style.background='#eff6ff'"
                onmouseout="this.style.background= currentIcon==='${ic}' ? '#dbeafe' : '#f9fafb'">
            <i class="fas ${ic}"></i>
        </button>
    `).join('');

    highlightSelected();
}

function highlightSelected() {
    document.querySelectorAll('#icon-grid button').forEach(btn => {
        const ic = btn.title;
        btn.style.borderColor = ic === currentIcon ? '#3b82f6' : 'transparent';
        btn.style.background  = ic === currentIcon ? '#dbeafe' : '#f9fafb';
    });
}

function selectIcon(ic) {
    currentIcon = ic;
    document.getElementById('form-icon-value').value       = ic;
    document.getElementById('icon-selected-preview').className = `fas ${ic}`;
    document.getElementById('icon-selected-name').textContent  = ic;
    document.getElementById('preview-icon').className          = `fas ${ic}`;
    highlightSelected();
}

document.getElementById('icon-search').addEventListener('input', function() {
    renderGrid(this.value.trim());
});

// ── Colour sync ───────────────────────────────────────────────────────────────
function setColor(pickerId, hexId, value) {
    document.getElementById(pickerId).value = value;
    document.getElementById(hexId).value    = value;
}

function updatePreview() {
    const name    = document.getElementById('form-name').value.trim() || 'Preview';
    const color   = document.getElementById('form-color').value;
    const bg      = document.getElementById('form-bg').value;
    const preview = document.getElementById('form-preview');

    preview.style.color      = color;
    preview.style.background = bg;
    document.getElementById('preview-text').textContent = name;
    document.getElementById('preview-icon').style.color = color;
}

['color','bg'].forEach(key => {
    const picker = document.getElementById(`form-${key}`);
    const hex    = document.getElementById(`form-${key}-hex`);
    picker.addEventListener('input', () => { hex.value = picker.value; updatePreview(); });
    hex.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) {
            picker.value = hex.value; updatePreview();
        }
    });
});

document.getElementById('form-name').addEventListener('input', updatePreview);

// ── Open / Close ──────────────────────────────────────────────────────────────
function openAdd() {
    document.getElementById('modal-title').textContent  = 'Add New Status';
    document.getElementById('form-submit').textContent  = 'Add Status';
    document.getElementById('form-action').value        = 'add';
    document.getElementById('form-status-id').value     = '';
    document.getElementById('form-name').value          = '';
    document.getElementById('icon-search').value        = '';
    setColor('form-color', 'form-color-hex', '#9ca3af');
    setColor('form-bg',    'form-bg-hex',    '#f3f4f6');
    selectIcon('fa-circle-dot');
    renderGrid('');
    updatePreview();
    document.getElementById('status-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function openEdit(id, name, color, bg, icon) {
    document.getElementById('modal-title').textContent  = 'Edit Status';
    document.getElementById('form-submit').textContent  = 'Save Changes';
    document.getElementById('form-action').value        = 'edit';
    document.getElementById('form-status-id').value     = id;
    document.getElementById('form-name').value          = name;
    document.getElementById('icon-search').value        = '';
    setColor('form-color', 'form-color-hex', color || '#9ca3af');
    setColor('form-bg',    'form-bg-hex',    bg    || '#f3f4f6');
    selectIcon(icon || 'fa-circle-dot');
    renderGrid('');
    updatePreview();
    document.getElementById('status-modal').style.display = 'flex';
    setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function closeModal() {
    document.getElementById('status-modal').style.display = 'none';
}

document.getElementById('status-modal').addEventListener('click', function(e) {
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

// Init grid on load so it's ready
renderGrid('');
</script>

<?php include '../../includes/footer.php'; ?>