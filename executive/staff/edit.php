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

$db = getDB();
$staffId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff) {
    setFlash('error', 'User not found');
    header('Location:index.php');
    exit;
}
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allRoles = $db->query("SELECT id, role_name FROM roles ORDER BY id")->fetchAll();
$allAdmins = $db->query("
    SELECT u.id, u.full_name, b.branch_name FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE r.role_name='admin' AND u.is_active=1
    ORDER BY u.full_name
")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Sanitize POST, fallback to old values if empty
    $fullName = trim($_POST['full_name'] ?? $staff['full_name']);
    $username = trim($_POST['username'] ?? $staff['username']);
    $email = trim($_POST['email'] ?? $staff['email']);
    $phone = trim($_POST['phone'] ?? $staff['phone']);
    $emergency = trim($_POST['emergency_contact'] ?? $staff['emergency_contact']);
    $roleId = (int) ($_POST['role_id'] ?? $staff['role_id']);
    $branchId = (int) ($_POST['branch_id'] ?? $staff['branch_id']);
    $deptId = (int) ($_POST['department_id'] ?? $staff['department_id']);
    $managedBy = (int) ($_POST['managed_by'] ?? $staff['managed_by']) ?: null;
    $joiningDate = $_POST['joining_date'] ?? $staff['joining_date'];
    $address = trim($_POST['address'] ?? $staff['address']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $gaEnabled = isset($_POST['ga_enabled']) ? 1 : 0;

    // Basic validation
    if (!$fullName)
        $errors[] = 'Full name required';
    if (!$username)
        $errors[] = 'Username required';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Valid email required';
    if (!$roleId)
        $errors[] = 'Role required';
    if (!$branchId)
        $errors[] = 'Branch required';
    if (!$deptId)
        $errors[] = 'Department required';

    // Check username/email duplicates
    $uCheck = $db->prepare("SELECT id FROM users WHERE username=? AND id!=?");
    $uCheck->execute([$username, $staffId]);
    if ($uCheck->fetch())
        $errors[] = 'Username already taken by another user';

    $eCheck = $db->prepare("SELECT id FROM users WHERE email=? AND id!=?");
    $eCheck->execute([$email, $staffId]);
    if ($eCheck->fetch())
        $errors[] = 'Email already used by another user';

    if (!$errors) {
        $update = $db->prepare("
            UPDATE users SET
            full_name=?, username=?, email=?, phone=?, emergency_contact=?,
            role_id=?, branch_id=?, department_id=?, managed_by=?,
            joining_date=?, address=?, is_active=?, ga_enabled=?
            WHERE id=?
        ");

        $update->execute([
            $fullName, $username, $email, $phone ?: null, $emergency ?: null,
            $roleId, $branchId, $deptId, $managedBy,
            $joiningDate ?: null, $address ?: null, $is_active, $gaEnabled,
            $staffId
        ]);

        setFlash('success', 'Staff updated successfully');
        header("Location:view.php?id={$staffId}");
        exit;
    }
}

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <h5>Edit Staff Member</h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <!-- Personal Info -->
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5>Personal Info</h5>
                    </div>
                    <div class="card-mis-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" class="form-control"
                                    value="<?= htmlspecialchars($staff['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Employee ID</label>
                                <input type="text" name="employee_id" class="form-control"
                                    value="<?= htmlspecialchars($staff['employee_id']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label>Email *</label>
                                <input type="email" name="email" class="form-control"
                                    value="<?= htmlspecialchars($staff['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control"
                                    value="<?= htmlspecialchars($staff['phone']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label>Emergency Contact</label>
                                <input type="text" name="emergency_contact" class="form-control"
                                    value="<?= htmlspecialchars($staff['emergency_contact']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label>Joining Date</label>
                                <input type="date" name="joining_date" class="form-control"
                                    value="<?= htmlspecialchars($staff['joining_date']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control"
                                    value="<?= htmlspecialchars($staff['address']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account & Role -->
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5>Account & Role</h5>
                    </div>
                    <div class="card-mis-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Username *</label>
                                <input type="text" name="username" class="form-control"
                                    value="<?= htmlspecialchars($staff['username']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Role *</label>
                                <select name="role_id" class="form-select" required>
                                    <?php foreach ($allRoles as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= $staff['role_id'] == $r['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-check mt-2">
                                <input type="checkbox" name="is_active" class="form-check-input"
                                    <?= $staff['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label">Active User</label>
                            </div>
                            <div class="col-md-6 form-check mt-2">
                                <input type="checkbox" name="ga_enabled" class="form-check-input"
                                    <?= $staff['ga_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label">GA Enabled</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch & Department -->
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5>Branch & Department</h5>
                    </div>
                    <div class="card-mis-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Branch *</label>
                                <select name="branch_id" class="form-select" required>
                                    <?php foreach ($allBranches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $staff['branch_id'] == $b['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Department *</label>
                                <select name="department_id" class="form-select" required>
                                    <?php foreach ($allDepts as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= $staff['department_id'] == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['dept_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Managed By</label>
                                <select name="managed_by" class="form-select">
                                    <option value="">-- Select Manager --</option>
                                    <?php foreach ($allAdmins as $a): ?>
                                        <option value="<?= $a['id'] ?>" <?= $staff['managed_by'] == $a['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($a['full_name']) ?>
                                            (<?= htmlspecialchars($a['branch_name']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <button class="btn btn-success">Update Staff</button>
            </form>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>