<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$user = currentUser();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can manage departments.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Department Management';
$errors = [];

/* ── Ensure columns exist (safety) ───────────────────────────── */
try {
    $db->query("SELECT color FROM departments LIMIT 1");
} catch (Exception $e) {
    $db->exec("ALTER TABLE departments 
        ADD COLUMN color VARCHAR(20) DEFAULT '#c9a84c' AFTER dept_code,
        ADD COLUMN icon  VARCHAR(50) DEFAULT 'fa-briefcase' AFTER color,
        ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER icon");
}

/* ── ADD ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();

    $name = trim($_POST['dept_name'] ?? '');
    $code = trim($_POST['dept_code'] ?? '');
    $color = trim($_POST['color'] ?? '#c9a84c');
    $icon = trim($_POST['icon'] ?? 'fa-briefcase');
    $status = (int) ($_POST['is_active'] ?? 1);

    if ($name === '')
        $errors[] = 'Department name is required.';

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM departments WHERE dept_name = ?");
        $dup->execute([$name]);

        if ($dup->fetch()) {
            $errors[] = "Department already exists.";
        } else {
            $db->prepare("INSERT INTO departments (dept_name, dept_code, color, icon, is_active) 
                          VALUES (?,?,?,?,?)")
                ->execute([$name, $code, $color, $icon, $status]);

            logActivity("Added department: $name", 'departments');
            setFlash('success', "Department added.");
            header('Location: department.php');
            exit;
        }
    }
}

/* ── EDIT ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();

    $id = (int) ($_POST['department_id'] ?? 0);
    $name = trim($_POST['dept_name'] ?? '');
    $code = trim($_POST['dept_code'] ?? '');
    $color = trim($_POST['color'] ?? '#c9a84c');
    $icon = trim($_POST['icon'] ?? 'fa-briefcase');
    $status = (int) ($_POST['is_active'] ?? 1);

    if (!$id)
        $errors[] = 'Invalid department.';
    if ($name === '')
        $errors[] = 'Department name required.';

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM departments WHERE dept_name = ? AND id != ?");
        $dup->execute([$name, $id]);

        if ($dup->fetch()) {
            $errors[] = "Department already exists.";
        } else {
            $db->prepare("UPDATE departments 
                          SET dept_name=?, dept_code=?, color=?, icon=?, is_active=? 
                          WHERE id=?")
                ->execute([$name, $code, $color, $icon, $status, $id]);

            logActivity("Updated department ID $id → $name", 'departments');
            setFlash('success', 'Department updated.');
            header('Location: department.php');
            exit;
        }
    }
}

/* ── DELETE ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();

    $id = (int) ($_POST['department_id'] ?? 0);

    if ($id) {
        // Optional dependency check (example: tasks table)
        $check = $db->prepare("SELECT COUNT(*) FROM tasks WHERE department_id = ?");
        $check->execute([$id]);

        if ((int) $check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete — department is in use.');
        } else {
            $nameRow = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
            $nameRow->execute([$id]);
            $name = $nameRow->fetchColumn();

            $db->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);

            logActivity("Deleted department: $name", 'departments');
            setFlash('success', "Department deleted.");
        }

        header('Location: department.php');
        exit;
    }
}

/* ── FETCH ───────────────────────────── */
$departments = $db->query("
    SELECT d.*,
           COUNT(t.id) AS task_count
    FROM departments d
    LEFT JOIN tasks t ON t.department_id = d.id
    GROUP BY d.id
    ORDER BY d.id DESC
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
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-sitemap"></i> Settings</div>
                        <h4>Departments</h4>
                        <p>Manage departments, colors, and icons.</p>
                    </div>
                    <button class="btn btn-gold btn-sm" onclick="openAdd()">
                        <i class="fas fa-plus me-1"></i>Add Department
                    </button>
                </div>
            </div>

            <div class="row g-3">
                <?php foreach ($departments as $d): ?>
                    <div class="col-md-6 col-lg-4">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;padding:1rem;
                display:flex;justify-content:space-between;align-items:center;gap:.75rem;">

                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <span style="background:<?= htmlspecialchars($d['color']) ?>20;
                         color:<?= htmlspecialchars($d['color']) ?>;
                         padding:.3rem .7rem;border-radius:99px;
                         display:flex;align-items:center;gap:.3rem;font-size:.8rem;">
                                    <i class="fas <?= htmlspecialchars($d['icon']) ?>"></i>
                                    <?= htmlspecialchars($d['dept_name']) ?>
                                </span>
                            </div>

                            <div style="display:flex;gap:.4rem;flex-shrink:0;">
                                <button onclick="openEdit(
                                    <?= $d['id'] ?>,
                                    '<?= htmlspecialchars(addslashes($d['dept_name'])) ?>',
                                    '<?= htmlspecialchars(addslashes($d['dept_code'])) ?>',
                                    '<?= $d['color'] ?>',
                                    '<?= htmlspecialchars(addslashes($d['icon'])) ?>',
                                    <?= $d['is_active'] ?>
                                )" style="background:#eff6ff;color:#3b82f6;border:none;
                                        border-radius:6px;padding:.3rem .6rem;
                                        font-size:.75rem;cursor:pointer;">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <?php if ($d['task_count'] == 0): ?>
                                    <button
                                        onclick="confirmDelete(<?= $d['id'] ?>,'<?= htmlspecialchars(addslashes($d['dept_name'])) ?>')"
                                        style="background:#fef2f2;color:#ef4444;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:pointer;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <button disabled title="In use by <?= $d['task_count'] ?> task(s)" style="background:#f9fafb;color:#d1d5db;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:not-allowed;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- MODAL -->
<div id="dept-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
     z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:520px;
            box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;">

        <div style="background:#0a0f1e;border-radius:16px 16px 0 0;padding:1.1rem 1.5rem;
            display:flex;justify-content:space-between;align-items:center;">
            <h5 id="modal-title" style="margin:0;color:#fff;font-size:1rem;"></h5>
            <button onclick="closeModal()"
                style="background:none;border:none;color:#9ca3af;font-size:1.1rem;cursor:pointer;">✕</button>
        </div>
        <div style="padding:1.5rem;">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" id="action">
                <input type="hidden" name="department_id" id="department_id">
                <input type="hidden" name="icon" id="icon">

                <div class="mb-2">
                    <label>Name</label>
                    <input type="text" name="dept_name" id="dept_name" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Code</label>
                    <input type="text" name="dept_code" id="dept_code" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label-mis">Color</label>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <input type="color" name="color" id="color" style="width:38px;height:34px;border:1px solid #e5e7eb;
                      border-radius:8px;cursor:pointer;padding:2px;flex-shrink:0;">
                        <input type="text" id="color-hex" maxlength="7" class="form-control form-control-sm"
                            style="font-family:monospace;font-size:.8rem;">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-mis">Icon
                        <span style="font-size:.68rem;color:#9ca3af;margin-left:.3rem;">Font Awesome 6 Free</span>
                    </label>
                    <div style="position:relative;margin-bottom:.5rem;">
                        <i class="fas fa-magnifying-glass" style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);
                  color:#9ca3af;font-size:.8rem;pointer-events:none;"></i>
                        <input type="text" id="dept-icon-search" class="form-control form-control-sm"
                            style="padding-left:2rem;" placeholder="Search icons… e.g. briefcase, building">
                    </div>
                    <div id="dept-icon-selected" style="display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;
                background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;
                margin-bottom:.5rem;font-size:.82rem;">
                        <i id="dept-icon-preview" class="fas fa-briefcase" style="font-size:1rem;color:#6b7280;"></i>
                        <span id="dept-icon-name" style="font-family:monospace;color:#374151;">fa-briefcase</span>
                        <span style="color:#9ca3af;font-size:.72rem;margin-left:auto;">selected</span>
                    </div>
                    <div id="dept-icon-grid" style="display:grid;grid-template-columns:repeat(8,1fr);gap:.3rem;
                max-height:160px;overflow-y:auto;
                border:1px solid #f3f4f6;border-radius:8px;padding:.4rem;">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-mis">Status</label>
                    <select name="is_active" id="status" class="form-control">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-gold">Save</button>
                </div>

            </form>
        </div><!-- padding -->
    </div><!-- modal box -->
</div><!-- overlay -->

<script>
    // ── Same FA icon list as task_status ─────────────────────────────────────────
    const DEPT_ICONS = [
        'fa-briefcase', 'fa-building', 'fa-store', 'fa-landmark', 'fa-university',
        'fa-sitemap', 'fa-layer-group', 'fa-cubes', 'fa-cube', 'fa-diagram-project',
        'fa-chart-bar', 'fa-chart-line', 'fa-chart-pie', 'fa-gauge', 'fa-receipt',
        'fa-money-bill', 'fa-coins', 'fa-calculator', 'fa-file-invoice', 'fa-bank',
        'fa-users', 'fa-user-group', 'fa-user-tie', 'fa-people-group', 'fa-handshake',
        'fa-gear', 'fa-gears', 'fa-wrench', 'fa-tools', 'fa-hammer',
        'fa-laptop', 'fa-server', 'fa-database', 'fa-network-wired', 'fa-code',
        'fa-shield', 'fa-lock', 'fa-key', 'fa-eye', 'fa-search',
        'fa-globe', 'fa-map', 'fa-location-dot', 'fa-flag', 'fa-bookmark',
        'fa-star', 'fa-heart', 'fa-bolt', 'fa-fire', 'fa-leaf',
        'fa-clipboard', 'fa-list-check', 'fa-folder', 'fa-file', 'fa-pen',
        'fa-tag', 'fa-tags', 'fa-bell', 'fa-envelope', 'fa-comment',
        'fa-truck', 'fa-car', 'fa-plane', 'fa-ship', 'fa-industry',
        'fa-recycle', 'fa-seedling', 'fa-tree', 'fa-sun', 'fa-moon',
        'fa-plus', 'fa-minus', 'fa-check', 'fa-xmark', 'fa-info',
        'fa-circle-dot', 'fa-circle-check', 'fa-circle-xmark', 'fa-ban', 'fa-clock',
    ];

    let deptCurrentIcon = 'fa-briefcase';

    function renderDeptGrid(filter) {
        const grid = document.getElementById('dept-icon-grid');
        const filtered = filter
            ? DEPT_ICONS.filter(ic => ic.includes(filter.toLowerCase()))
            : DEPT_ICONS;

        grid.innerHTML = filtered.map(ic => `
        <button type="button" onclick="selectDeptIcon('${ic}')" title="${ic}"
                style="aspect-ratio:1;border-radius:6px;
                       border:2px solid ${deptCurrentIcon === ic ? '#3b82f6' : 'transparent'};
                       background:${deptCurrentIcon === ic ? '#dbeafe' : '#f9fafb'};
                       cursor:pointer;font-size:.85rem;
                       display:flex;align-items:center;justify-content:center;"
                onmouseover="this.style.background='#eff6ff'"
                onmouseout="this.style.background=deptCurrentIcon==='${ic}'?'#dbeafe':'#f9fafb'">
            <i class="fas ${ic}"></i>
        </button>
    `).join('');
    }

    function selectDeptIcon(ic) {
        deptCurrentIcon = ic;
        document.getElementById('icon').value = ic;
        document.getElementById('dept-icon-preview').className = `fas ${ic}`;
        document.getElementById('dept-icon-name').textContent = ic;
        renderDeptGrid(document.getElementById('dept-icon-search').value.trim());
    }

    document.getElementById('dept-icon-search')?.addEventListener('input', function () {
        renderDeptGrid(this.value.trim());
    });

    // Colour sync
    const deptColorPicker = document.getElementById('color');
    const deptColorHex = document.getElementById('color-hex');
    deptColorPicker?.addEventListener('input', () => { deptColorHex.value = deptColorPicker.value; });
    deptColorHex?.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(deptColorHex.value)) deptColorPicker.value = deptColorHex.value;
    });

    function openAdd() {
        document.getElementById('modal-title').innerText = 'Add Department';
        document.getElementById('action').value = 'add';
        document.getElementById('department_id').value = '';
        document.getElementById('dept_name').value = '';
        document.getElementById('dept_code').value = '';
        document.getElementById('color').value = '#c9a84c';
        document.getElementById('color-hex').value = '#c9a84c';
        document.getElementById('dept-icon-search').value = '';
        selectDeptIcon('fa-briefcase');
        renderDeptGrid('');
        document.getElementById('dept-modal').style.display = 'flex';
    }

    function openEdit(id, name, code, color, icon, status) {
        document.getElementById('modal-title').innerText = 'Edit Department';
        document.getElementById('action').value = 'edit';
        document.getElementById('department_id').value = id;
        document.getElementById('dept_name').value = name;
        document.getElementById('dept_code').value = code;
        document.getElementById('color').value = color;
        document.getElementById('color-hex').value = color;
        document.getElementById('status').value = status;
        document.getElementById('dept-icon-search').value = '';
        selectDeptIcon(icon || 'fa-briefcase');
        renderDeptGrid('');
        document.getElementById('dept-modal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('dept-modal').style.display = 'none';
    }

    document.getElementById('dept-modal')?.addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    function confirmDelete(id, name) {
        if (!confirm(`Delete "${name}"?\n\nThis cannot be undone.`)) return;
        let f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="department_id" value="${id}">
    `;
        document.body.appendChild(f);
        f.submit();
    }

    renderDeptGrid('');

    function openEdit(id, name, code, color, icon, status) {
        document.getElementById('modal-title').innerText = 'Edit Department';
        document.getElementById('action').value = 'edit';
        document.getElementById('department_id').value = id;
        document.getElementById('dept_name').value = name;
        document.getElementById('dept_code').value = code;
        document.getElementById('color').value = color;
        document.getElementById('icon').value = icon;
        document.getElementById('status').value = status;
        document.getElementById('dept-modal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('dept-modal').style.display = 'none';
    }

    function confirmDelete(id, name) {
        if (confirm('Delete ' + name + '?')) {
            let f = document.createElement('form');
            f.method = 'POST';
            f.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="department_id" value="${id}">
        `;
            document.body.appendChild(f);
            f.submit();
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>