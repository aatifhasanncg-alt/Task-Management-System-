<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';

requireExecutive();

$db   = getDB();
$user = currentUser();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: ' . APP_URL . '/executive/dashboard/index.php');
    exit;
}

$pageTitle = 'Company Type Management';
$errors = [];

/* ── ADD ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();

    $name = trim($_POST['type_name'] ?? '');

    if ($name === '') {
        $errors[] = 'Type name is required.';
    } elseif (strlen($name) > 50) {
        $errors[] = 'Max 50 characters allowed.';
    } else {
        $dup = $db->prepare("SELECT id FROM company_types WHERE type_name = ?");
        $dup->execute([$name]);

        if ($dup->fetch()) {
            $errors[] = "Type \"$name\" already exists.";
        } else {
            $db->prepare("INSERT INTO company_types (type_name) VALUES (?)")
               ->execute([$name]);

            logActivity("Added company type: $name", 'company_types');
            setFlash('success', "Type \"$name\" added.");
            header('Location: type.php'); exit;
        }
    }
}

/* ── EDIT ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();

    $id   = (int)($_POST['type_id'] ?? 0);
    $name = trim($_POST['type_name'] ?? '');

    if (!$id) {
        $errors[] = 'Invalid type.';
    }

    if ($name === '') {
        $errors[] = 'Type name is required.';
    }

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM company_types WHERE type_name = ? AND id != ?");
        $dup->execute([$name, $id]);

        if ($dup->fetch()) {
            $errors[] = "Type \"$name\" already exists.";
        } else {
            $db->prepare("UPDATE company_types SET type_name=? WHERE id=?")
               ->execute([$name, $id]);

            logActivity("Updated company type ID $id → $name", 'company_types');
            setFlash('success', 'Type updated.');
            header('Location: type.php'); exit;
        }
    }
}

/* ── DELETE ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();

    $id = (int)($_POST['type_id'] ?? 0);

    if ($id) {
        $nameRow = $db->prepare("SELECT type_name FROM company_types WHERE id=?");
        $nameRow->execute([$id]);
        $name = $nameRow->fetchColumn();

        $db->prepare("DELETE FROM company_types WHERE id=?")->execute([$id]);

        logActivity("Deleted company type: $name", 'company_types');
        setFlash('success', "Type \"$name\" deleted.");

        header('Location: type.php'); exit;
    }
}

/* ── FETCH ──────────────────────────── */
$types = $db->query("SELECT * FROM company_types ORDER BY id DESC")->fetchAll();

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
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="page-hero d-flex justify-content-between align-items-center">
    <div>
        <h4><i class="fas fa-tags"></i> Company Types</h4>
        <p>Manage organization/company type categories.</p>
    </div>
    <button class="btn btn-gold btn-sm" onclick="openAdd()">
        <i class="fas fa-plus me-1"></i> Add Type
    </button>
</div>

<div class="row g-3">
<?php foreach ($types as $t): ?>
<div class="col-md-4">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;padding:1rem;display:flex;justify-content:space-between;align-items:center;">
        
        <div>
            <i class="fas fa-building text-primary me-2"></i>
            <strong><?= htmlspecialchars($t['type_name']) ?></strong>
        </div>

        <div class="d-flex gap-2">
            <button onclick="openEdit(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['type_name'])) ?>')"
                class="btn btn-sm btn-outline-primary">
                <i class="fas fa-pen"></i>
            </button>

            <button onclick="confirmDelete(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['type_name'])) ?>')"
                class="btn btn-sm btn-outline-danger">
                <i class="fas fa-trash"></i>
            </button>
        </div>

    </div>
</div>
<?php endforeach; ?>

<?php if (empty($types)): ?>
<div class="col-12 text-center text-muted py-5">
    <i class="fas fa-box-open fa-2x mb-2"></i>
    <p>No types found. Add one.</p>
</div>
<?php endif; ?>
</div>

</div>
</div>
</div>

<!-- MODAL -->
<div id="type-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;padding:1.5rem;border-radius:12px;width:100%;max-width:400px;">

        <h5 id="modal-title" class="mb-3"></h5>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" id="form-action">
            <input type="hidden" name="type_id" id="form-id">

            <div class="mb-3">
                <label class="form-label">Type Name</label>
                <input type="text" name="type_name" id="form-name" class="form-control" required maxlength="50">
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-gold">Save</button>
            </div>
        </form>

    </div>
</div>

<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="type_id" id="delete-id">
</form>

<script>
function openAdd(){
    document.getElementById('modal-title').innerText = 'Add Type';
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-id').value = '';
    document.getElementById('form-name').value = '';
    document.getElementById('type-modal').style.display = 'flex';
}

function openEdit(id, name){
    document.getElementById('modal-title').innerText = 'Edit Type';
    document.getElementById('form-action').value = 'edit';
    document.getElementById('form-id').value = id;
    document.getElementById('form-name').value = name;
    document.getElementById('type-modal').style.display = 'flex';
}

function closeModal(){
    document.getElementById('type-modal').style.display = 'none';
}

function confirmDelete(id, name){
    if(confirm(`Delete "${name}"?`)){
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-form').submit();
    }
}

document.getElementById('type-modal').addEventListener('click', function(e){
    if(e.target === this) closeModal();
});
</script>

<?php include '../../includes/footer.php'; ?>