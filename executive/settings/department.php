<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can manage departments.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Department Management';
$errors    = [];

/* ── Ensure columns exist (safety) ───────────────────────────── */
try { $db->query("SELECT color FROM departments LIMIT 1"); }
catch (Exception $e) {
    $db->exec("ALTER TABLE departments 
        ADD COLUMN color VARCHAR(20) DEFAULT '#c9a84c' AFTER dept_code,
        ADD COLUMN icon  VARCHAR(50) DEFAULT 'fa-briefcase' AFTER color,
        ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER icon");
}

/* ── ADD ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();

    $name  = trim($_POST['dept_name'] ?? '');
    $code  = trim($_POST['dept_code'] ?? '');
    $color = trim($_POST['color'] ?? '#c9a84c');
    $icon  = trim($_POST['icon'] ?? 'fa-briefcase');
    $status = (int)($_POST['is_active'] ?? 1);

    if ($name === '') $errors[] = 'Department name is required.';

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
            header('Location: department.php'); exit;
        }
    }
}

/* ── EDIT ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();

    $id    = (int)($_POST['department_id'] ?? 0);
    $name  = trim($_POST['dept_name'] ?? '');
    $code  = trim($_POST['dept_code'] ?? '');
    $color = trim($_POST['color'] ?? '#c9a84c');
    $icon  = trim($_POST['icon'] ?? 'fa-briefcase');
    $status = (int)($_POST['is_active'] ?? 1);

    if (!$id) $errors[] = 'Invalid department.';
    if ($name === '') $errors[] = 'Department name required.';

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
            header('Location: department.php'); exit;
        }
    }
}

/* ── DELETE ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();

    $id = (int)($_POST['department_id'] ?? 0);

    if ($id) {
        // Optional dependency check (example: tasks table)
        $check = $db->prepare("SELECT COUNT(*) FROM tasks WHERE department_id = ?");
        $check->execute([$id]);

        if ((int)$check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete — department is in use.');
        } else {
            $nameRow = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
            $nameRow->execute([$id]);
            $name = $nameRow->fetchColumn();

            $db->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);

            logActivity("Deleted department: $name", 'departments');
            setFlash('success', "Department deleted.");
        }

        header('Location: department.php'); exit;
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
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
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

        <div>
            <button onclick="openEdit(
                <?= $d['id'] ?>,
                '<?= htmlspecialchars(addslashes($d['dept_name'])) ?>',
                '<?= htmlspecialchars(addslashes($d['dept_code'])) ?>',
                '<?= $d['color'] ?>',
                '<?= htmlspecialchars(addslashes($d['icon'])) ?>',
                <?= $d['is_active'] ?>
            )" class="btn btn-sm btn-primary">
                Edit
            </button>

            <?php if ($d['task_count'] == 0): ?>
            <button onclick="confirmDelete(<?= $d['id'] ?>,'<?= htmlspecialchars(addslashes($d['dept_name'])) ?>')"
                    class="btn btn-sm btn-danger">
                Delete
            </button>
            <?php else: ?>
            <button class="btn btn-sm btn-secondary" disabled title="In use">
                Delete
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
<div id="dept-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
<div style="background:#fff;padding:1.5rem;border-radius:12px;width:100%;max-width:500px;">

<h5 id="modal-title"></h5>

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

<div class="mb-2">
<label>Color</label>
<input type="color" name="color" id="color" class="form-control">
</div>

<div class="mb-2">
<label>Status</label>
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
</div>
</div>

<script>
function openAdd(){
    document.getElementById('modal-title').innerText = 'Add Department';
    document.getElementById('action').value = 'add';
    document.getElementById('department_id').value = '';
    document.getElementById('dept-modal').style.display = 'flex';
}

function openEdit(id,name,code,color,icon,status){
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

function closeModal(){
    document.getElementById('dept-modal').style.display = 'none';
}

function confirmDelete(id,name){
    if(confirm('Delete '+name+'?')){
        let f = document.createElement('form');
        f.method='POST';
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