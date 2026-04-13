<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';

requireExecutive();
if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can edit staff.');
    header('Location: index.php');
    exit;
}

$db      = getDB();
$staffId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff) {
    setFlash('error', 'User not found');
    header('Location: index.php');
    exit;
}

$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts    = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allRoles    = $db->query("SELECT id, role_name FROM roles ORDER BY id")->fetchAll();
$allAdmins = $db->query("
    SELECT u.id, u.full_name, u.employee_id, b.branch_name FROM users u
    LEFT JOIN roles r    ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE r.role_name='admin' AND u.is_active=1
    ORDER BY u.full_name
")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName  = trim($_POST['full_name']         ?? $staff['full_name']);
    $username  = trim($_POST['username']           ?? $staff['username']);
    $email     = trim($_POST['email']              ?? $staff['email']);
    $phone     = trim($_POST['phone']              ?? $staff['phone']);
    $emergency = trim($_POST['emergency_contact']  ?? $staff['emergency_contact']);
    $roleId    = (int)($_POST['role_id']           ?? $staff['role_id']);
    $branchId  = (int)($_POST['branch_id']         ?? $staff['branch_id']);
    $deptId    = (int)($_POST['department_id']     ?? $staff['department_id']);
    $managedBy = (int)($_POST['managed_by']        ?? $staff['managed_by']) ?: null;
    $joiningDate = $_POST['joining_date']          ?? $staff['joining_date'];
    $address   = trim($_POST['address']            ?? $staff['address']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $gaEnabled = isset($_POST['ga_enabled']) ? 1 : 0;

    if (!$fullName) $errors[] = 'Full name is required.';
    if (!$username) $errors[] = 'Username is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (!$roleId)   $errors[] = 'Role is required.';
    if (!$branchId) $errors[] = 'Branch is required.';
    if (!$deptId)   $errors[] = 'Department is required.';

    $uCheck = $db->prepare("SELECT id FROM users WHERE username=? AND id!=?");
    $uCheck->execute([$username, $staffId]);
    if ($uCheck->fetch()) $errors[] = 'Username is already taken by another user.';

    $eCheck = $db->prepare("SELECT id FROM users WHERE email=? AND id!=?");
    $eCheck->execute([$email, $staffId]);
    if ($eCheck->fetch()) $errors[] = 'Email is already used by another user.';

    if (!$errors) {
        $oldRoleId = $staff['role_id'];

        $db->prepare("
            UPDATE users SET
                full_name=?, username=?, email=?, phone=?, emergency_contact=?,
                branch_id=?, department_id=?, managed_by=?,
                joining_date=?, address=?, is_active=?, ga_enabled=?
            WHERE id=?
        ")->execute([
            $fullName, $username, $email, $phone ?: null, $emergency ?: null,
            $branchId, $deptId, $managedBy,
            $joiningDate ?: null, $address ?: null, $is_active, $gaEnabled,
            $staffId
        ]);

        if ($oldRoleId != $roleId) {
            require_once '../../config/role_manager.php';
            changeUserRole(
                userId:      $staffId,
                newRoleId:   $roleId,
                newBranchId: $branchId,
                reason:      'Updated via staff edit form'
            );
        }

        setFlash('success', 'Staff member updated successfully.');
        header("Location: view.php?id={$staffId}");
        exit;
    }
}

// Current role name for display
$currentRoleName = '';
foreach ($allRoles as $r) {
    if ($r['id'] == $staff['role_id']) { $currentRoleName = $r['role_name']; break; }
}

$pageTitle = 'Edit Staff: ' . $staff['full_name'];
include '../../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<!-- ── Page Hero ── -->
<div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width:52px;height:52px;border-radius:50%;
                        background:linear-gradient(135deg,#c9a84c,#f5d78e);
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.2rem;font-weight:800;color:#0a0f1e;flex-shrink:0;">
                <?= strtoupper(substr($staff['full_name'], 0, 1) .
                    (strpos($staff['full_name'],' ') ? substr($staff['full_name'], strpos($staff['full_name'],' ')+1, 1) : '')) ?>
            </div>
            <div>
                <div class="page-hero-badge"><i class="fas fa-user-pen"></i> Edit Staff</div>
                <h4 style="margin:0;"><?= htmlspecialchars($staff['full_name']) ?></h4>
                <div style="font-size:.78rem;color:#9ca3af;margin-top:.15rem;">
                    <?= htmlspecialchars($staff['employee_id'] ?? '') ?>
                    <?php if ($staff['employee_id']): ?>&nbsp;·&nbsp;<?php endif; ?>
                    <span style="text-transform:capitalize;"><?= htmlspecialchars($currentRoleName) ?></span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="view.php?id=<?= $staffId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back to Profile
            </a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-users me-1"></i>All Staff
            </a>
        </div>
    </div>
</div>

<!-- ── Errors ── -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-4" style="border-left:4px solid #ef4444;">
    <div class="d-flex align-items-start gap-2">
        <i class="fas fa-circle-exclamation mt-1" style="color:#ef4444;flex-shrink:0;"></i>
        <div>
            <strong style="font-size:.88rem;">Please fix the following:</strong>
            <ul class="mb-0 mt-1" style="font-size:.83rem;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" id="editStaffForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <div class="row g-4">

        <!-- ── LEFT COLUMN ── -->
        <div class="col-lg-8">

            <!-- Personal Info -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-user text-warning me-2"></i>Personal Information</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Full Name <span class="required-star">*</span>
                            </label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? $staff['full_name']) ?>"
                                   required placeholder="e.g. Ram Prasad Sharma">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Employee ID</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-id-badge" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="text" name="employee_id" class="form-control"
                                       value="<?= htmlspecialchars($staff['employee_id'] ?? '') ?>"
                                       readonly
                                       style="background:#f9fafb;cursor:not-allowed;color:#9ca3af;">
                            </div>
                            <small style="font-size:.65rem;color:#9ca3af;">Auto-assigned, cannot be changed</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Email Address <span class="required-star">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-envelope" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($_POST['email'] ?? $staff['email']) ?>"
                                       required placeholder="staff@askglobal.com.np">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-phone" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="text" name="phone" class="form-control"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? $staff['phone'] ?? '') ?>"
                                       placeholder="98XXXXXXXX">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Emergency Contact</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-phone-volume" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="text" name="emergency_contact" class="form-control"
                                       value="<?= htmlspecialchars($_POST['emergency_contact'] ?? $staff['emergency_contact'] ?? '') ?>"
                                       placeholder="Name / Number">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['joining_date'] ?? $staff['joining_date'] ?? '') ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label-mis">Address</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-location-dot" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="text" name="address" class="form-control"
                                       value="<?= htmlspecialchars($_POST['address'] ?? $staff['address'] ?? '') ?>"
                                       placeholder="e.g. Kathmandu, Nepal">
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Account & Role -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-shield-halved text-warning me-2"></i>Account & Role</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Username <span class="required-star">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f9fafb;border-color:#e5e7eb;">
                                    <i class="fas fa-at" style="color:#9ca3af;font-size:.8rem;"></i>
                                </span>
                                <input type="text" name="username" class="form-control"
                                       value="<?= htmlspecialchars($_POST['username'] ?? $staff['username']) ?>"
                                       required placeholder="login username">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Role <span class="required-star">*</span>
                            </label>
                            <select name="role_id" class="form-select" required id="roleSelect">
                                <?php foreach ($allRoles as $r): ?>
                                <option value="<?= $r['id'] ?>"
                                    <?= ($staff['role_id'] == $r['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($r['role_name'])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($staff['role_id'] != ($allRoles[0]['id'] ?? 0)): ?>
                            <small style="font-size:.65rem;color:#f59e0b;">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Changing role will trigger permission reset
                            </small>
                            <?php endif; ?>
                        </div>

                        <!-- Role change warning -->
                        <div class="col-12" id="roleChangeWarning" style="display:none;">
                            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                                        padding:.6rem .9rem;font-size:.78rem;color:#92400e;
                                        display:flex;align-items:center;gap:.5rem;">
                                <i class="fas fa-triangle-exclamation" style="flex-shrink:0;"></i>
                                Role changed from <strong id="oldRoleLabel"></strong> to
                                <strong id="newRoleLabel"></strong> — permissions will be reset on save.
                            </div>
                        </div>

                        <!-- Account toggles -->
                        <div class="col-12">
                            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:.25rem;">

                                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;
                                              background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;
                                              padding:.55rem .9rem;min-width:160px;transition:.15s;"
                                       id="activeToggle">
                                    <div style="position:relative;width:36px;height:20px;flex-shrink:0;">
                                        <input type="checkbox" name="is_active" id="isActiveCheck"
                                               style="opacity:0;position:absolute;width:0;height:0;"
                                               <?= $staff['is_active'] ? 'checked' : '' ?>>
                                        <div class="toggle-track" id="activeTrack"
                                             style="width:36px;height:20px;border-radius:99px;
                                                    background:<?= $staff['is_active'] ? '#10b981' : '#d1d5db' ?>;
                                                    transition:.2s;position:relative;">
                                            <div style="position:absolute;top:2px;
                                                        left:<?= $staff['is_active'] ? '18px' : '2px' ?>;
                                                        width:16px;height:16px;border-radius:50%;
                                                        background:#fff;transition:.2s;"
                                                 id="activeThumb"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:.82rem;font-weight:600;color:#374151;">Active User</div>
                                        <div style="font-size:.68rem;color:#9ca3af;">Can log in to the system</div>
                                    </div>
                                </label>

                                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;
                                              background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;
                                              padding:.55rem .9rem;min-width:160px;transition:.15s;"
                                       id="gaToggle">
                                    <div style="position:relative;width:36px;height:20px;flex-shrink:0;">
                                        <input type="checkbox" name="ga_enabled" id="gaCheck"
                                               style="opacity:0;position:absolute;width:0;height:0;"
                                               <?= $staff['ga_enabled'] ? 'checked' : '' ?>>
                                        <div class="toggle-track" id="gaTrack"
                                             style="width:36px;height:20px;border-radius:99px;
                                                    background:<?= $staff['ga_enabled'] ? '#3b82f6' : '#d1d5db' ?>;
                                                    transition:.2s;position:relative;">
                                            <div style="position:absolute;top:2px;
                                                        left:<?= $staff['ga_enabled'] ? '18px' : '2px' ?>;
                                                        width:16px;height:16px;border-radius:50%;
                                                        background:#fff;transition:.2s;"
                                                 id="gaThumb"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:.82rem;font-weight:600;color:#374151;">
                                            2FA Enabled
                                            <i class="fas fa-shield-halved ms-1"
                                               style="font-size:.7rem;color:#3b82f6;"></i>
                                        </div>
                                        <div style="font-size:.68rem;color:#9ca3af;">Google Authenticator</div>
                                    </div>
                                </label>

                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Branch & Department -->
            <div class="card-mis mb-4" style="overflow:visible;">
                <div class="card-mis-header">
                    <h5><i class="fas fa-building text-warning me-2"></i>Branch & Department</h5>
                </div>
                <div class="card-mis-body" style="overflow:visible;">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Branch <span class="required-star">*</span>
                            </label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($allBranches as $b): ?>
                                <option value="<?= $b['id'] ?>"
                                    <?= ($staff['branch_id'] == $b['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Department <span class="required-star">*</span>
                            </label>
                            <select name="department_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($allDepts as $d): ?>
                                <option value="<?= $d['id'] ?>"
                                    <?= ($staff['department_id'] == $d['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['dept_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label-mis">Managed By</label>
                            <select name="managed_by" class="form-select tomselect-input" id="managedBySelect">
                                <option value="">-- No Manager Assigned --</option>
                                <?php foreach ($allAdmins as $a): ?>
                                <?php
                                $adminMeta = [];
                                if (!empty($a['employee_id'])) $adminMeta[] = $a['employee_id'];
                                if (!empty($a['branch_name'])) $adminMeta[] = $a['branch_name'];
                                $adminMetaStr = $adminMeta ? ' — ' . implode(' | ', $adminMeta) : '';
                                ?>
                                <option value="<?= $a['id'] ?>"
                                    <?= ($staff['managed_by'] == $a['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['full_name'] . $adminMetaStr) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- end col-lg-8 -->

        <!-- ── RIGHT SIDEBAR ── -->
        <div class="col-lg-4">

            <!-- Save Actions -->
            <div class="card-mis mb-3" style="border-left:4px solid #c9a84c;">
                <div class="card-mis-header">
                    <h5><i class="fas fa-floppy-disk text-warning me-2"></i>Save Changes</h5>
                </div>
                <div class="card-mis-body">
                    <button type="submit" class="btn btn-gold w-100 mb-2">
                        <i class="fas fa-save me-2"></i>Update Staff Member
                    </button>
                    <a href="view.php?id=<?= $staffId ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>

            <!-- Current Info snapshot -->
            <div class="card-mis mb-3">
                <div class="card-mis-header">
                    <h5><i class="fas fa-circle-info text-warning me-2"></i>Current Info</h5>
                </div>
                <div class="card-mis-body" style="font-size:.8rem;color:#6b7280;">
                    <?php
                    $currentBranch = '';
                    foreach ($allBranches as $b) {
                        if ($b['id'] == $staff['branch_id']) { $currentBranch = $b['branch_name']; break; }
                    }
                    $currentDept = '';
                    foreach ($allDepts as $d) {
                        if ($d['id'] == $staff['department_id']) { $currentDept = $d['dept_name']; break; }
                    }
                    $infoRows = [
                        ['fas fa-hashtag',        'Employee ID',  $staff['employee_id'] ?? '—'],
                        ['fas fa-user-tag',        'Role',         ucfirst($currentRoleName)],
                        ['fas fa-code-branch',     'Branch',       $currentBranch ?: '—'],
                        ['fas fa-layer-group',     'Department',   $currentDept ?: '—'],
                        ['fas fa-calendar-plus',   'Joined',       $staff['joining_date'] ? date('d M Y', strtotime($staff['joining_date'])) : '—'],
                        ['fas fa-clock',           'Last Updated', date('d M Y', strtotime($staff['updated_at'] ?? $staff['created_at'] ?? 'now'))],
                    ];
                    foreach ($infoRows as [$icon, $label, $val]):
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:.35rem 0;border-bottom:1px solid #f3f4f6;">
                        <div style="display:flex;align-items:center;gap:.5rem;color:#9ca3af;">
                            <i class="fas <?= $icon ?>" style="font-size:.7rem;width:12px;text-align:center;"></i>
                            <span><?= $label ?></span>
                        </div>
                        <span style="font-weight:500;color:#374151;font-size:.78rem;">
                            <?= htmlspecialchars($val) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>

                    <!-- Status pills -->
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
                        <span style="background:<?= $staff['is_active'] ? '#ecfdf5' : '#f9fafb' ?>;
                                     color:<?= $staff['is_active'] ? '#10b981' : '#9ca3af' ?>;
                                     border:1px solid <?= $staff['is_active'] ? '#a7f3d0' : '#e5e7eb' ?>;
                                     padding:.2rem .6rem;border-radius:99px;font-size:.7rem;font-weight:600;">
                            <i class="fas <?= $staff['is_active'] ? 'fa-circle-check' : 'fa-circle-xmark' ?> me-1"></i>
                            <?= $staff['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                        <span style="background:<?= $staff['ga_enabled'] ? '#eff6ff' : '#f9fafb' ?>;
                                     color:<?= $staff['ga_enabled'] ? '#3b82f6' : '#9ca3af' ?>;
                                     border:1px solid <?= $staff['ga_enabled'] ? '#bfdbfe' : '#e5e7eb' ?>;
                                     padding:.2rem .6rem;border-radius:99px;font-size:.7rem;font-weight:600;">
                            <i class="fas fa-shield-halved me-1"></i>
                            2FA <?= $staff['ga_enabled'] ? 'ON' : 'OFF' ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Help note -->
            <div class="card-mis p-3" style="border-left:3px solid #3b82f6;">
                <div style="font-size:.78rem;color:#374151;">
                    <div style="font-weight:700;color:#3b82f6;margin-bottom:.5rem;">
                        <i class="fas fa-circle-info me-1"></i>What you can change
                    </div>
                    <div style="color:#6b7280;line-height:1.7;">
                        All fields are editable except <strong>Employee ID</strong> which is system-assigned.
                        Changing the <strong>Role</strong> will trigger a permission reset via the role manager.
                        Password changes are handled separately from the staff profile view.
                    </div>
                </div>
            </div>

        </div><!-- end col-lg-4 -->

    </div><!-- end row -->
</form>

</div>

<style>
#managedBySelect + .ts-wrapper { display:block !important; visibility:visible !important; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Managed By Tom Select ─────────────────────────────────────────────────
    const managedByTs = new TomSelect('#managedBySelect', {
        placeholder: 'Search by name, ID or branch…',
         dropdownParent: 'body',
        allowEmptyOption: true,
        maxOptions: 300,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts = data.text.split(' — ');
                const name  = parts[0] || '';
                const meta  = parts[1] || '';
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(name)}</div>
                    ${meta ? `<div style="font-size:.75rem;color:#6b7280;">${escape(meta)}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text.split(' — ')[0])}</div>`;
            }
        }
    });

    const preManagedBy = '<?= (int)($staff['managed_by'] ?? 0) ?>';
    if (preManagedBy && preManagedBy !== '0') managedByTs.setValue(preManagedBy, true);


    // ── Toggle switches ───────────────────────────────────────────────────────
    function initToggle(checkId, trackId, thumbId, onColor) {
        const check = document.getElementById(checkId);
        const track = document.getElementById(trackId);
        const thumb = document.getElementById(thumbId);
        if (!check || !track || !thumb) return;

        function update() {
            track.style.background = check.checked ? onColor : '#d1d5db';
            thumb.style.left       = check.checked ? '18px' : '2px';
        }
        check.addEventListener('change', update);
        update();
    }

    initToggle('isActiveCheck', 'activeTrack', 'activeThumb', '#10b981');
    initToggle('gaCheck',       'gaTrack',     'gaThumb',     '#3b82f6');

    // Clicking the toggle label card also fires the checkbox
    ['activeToggle','gaToggle'].forEach(id => {
        const label = document.getElementById(id);
        if (label) {
            label.style.cursor = 'pointer';
            label.addEventListener('mouseenter', () => label.style.borderColor = '#c9a84c');
            label.addEventListener('mouseleave', () => label.style.borderColor = '#e5e7eb');
        }
    });

    // ── Role change warning ───────────────────────────────────────────────────
    const roleSelect   = document.getElementById('roleSelect');
    const warning      = document.getElementById('roleChangeWarning');
    const oldRoleLabel = document.getElementById('oldRoleLabel');
    const newRoleLabel = document.getElementById('newRoleLabel');
    const originalRole = '<?= (int)$staff['role_id'] ?>';
    const originalRoleName = '<?= htmlspecialchars(ucfirst($currentRoleName), ENT_QUOTES) ?>';

    if (roleSelect) {
        roleSelect.addEventListener('change', function () {
            if (this.value !== originalRole) {
                const newName = this.options[this.selectedIndex].text;
                oldRoleLabel.textContent = originalRoleName;
                newRoleLabel.textContent = newName;
                warning.style.display   = 'block';
            } else {
                warning.style.display = 'none';
            }
        });
    }

    // ── Form submit confirmation if role changed ──────────────────────────────
    document.getElementById('editStaffForm')?.addEventListener('submit', function (e) {
        if (roleSelect && roleSelect.value !== originalRole) {
            if (!confirm('Role is changing. This will reset permissions. Continue?')) {
                e.preventDefault();
            }
        }
    });

});
</script>
<?php include '../../includes/footer.php'; ?>
</div>
</div>