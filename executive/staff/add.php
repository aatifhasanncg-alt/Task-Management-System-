<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../vendor/GoogleAuthenticator.php';
/**
 * Parse .xlsx file without any library.
 * Returns array of rows (each row = 0-indexed array of cell values).
 * Returns false on failure.
 */
function parseXlsxNative(string $filePath): array|false
{
    // xlsx is a zip file
    if (!class_exists('ZipArchive'))
        return false;

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true)
        return false;

    // Read shared strings (all text values live here)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            // Concatenate all <t> fragments inside each <si>
            $val = '';
            foreach ($si->xpath('.//t') as $t) {
                $val .= (string) $t;
            }
            $sharedStrings[] = $val;
        }
    }

    // Read the first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false)
        return false;

    $sheet = new SimpleXMLElement($sheetXml);
    $rows = [];

    // Register the spreadsheetml namespace
    $ns = $sheet->getNamespaces(true);
    $defaultNs = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        $maxCol = 0;

        foreach ($row->c as $cell) {
            // Parse column letter from cell reference (e.g. "B3" → col index 1)
            $ref = (string) ($cell['r'] ?? '');
            preg_match('/^([A-Z]+)/', $ref, $m);
            $colLetters = $m[1] ?? 'A';

            // Convert column letters to 0-based index
            $colIdx = 0;
            foreach (str_split($colLetters) as $ch) {
                $colIdx = $colIdx * 26 + (ord($ch) - ord('A') + 1);
            }
            $colIdx--; // 0-based

            $type = (string) ($cell['t'] ?? '');
            $value = (string) ($cell->v ?? '');

            if ($type === 's') {
                // Shared string index
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            }
            // Numeric/date cells: value is already in $value as a number string

            $rowData[$colIdx] = $value;
            $maxCol = max($maxCol, $colIdx);
        }

        // Fill any gaps so row is a clean 0-indexed array
        $cleanRow = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $cleanRow[] = $rowData[$i] ?? '';
        }

        $rows[] = $cleanRow;
    }

    // Remove header row (first row)
    if (!empty($rows))
        array_shift($rows);

    // Remove fully empty rows
    $rows = array_filter($rows, fn($r) => array_filter($r, fn($v) => trim($v) !== ''));

    return array_values($rows);
}
requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can add staff.');
    header('Location: index.php');
    exit;
}

$db = getDB();
$currentUser = currentUser(); // ← renamed to avoid conflict
$pageTitle = 'Add Staff';
$errors = [];

$ga = new PHPGangsta_GoogleAuthenticator();

// ── Generate secret ONCE and persist via hidden field ──
// On GET: generate fresh secret
// On POST with errors: reuse secret from hidden field
$secret = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = trim($_POST['ga_secret_hidden'] ?? '');
    if (!$secret)
        $secret = $ga->createSecret();
} else {
    $secret = $ga->createSecret();
}

// Build QR URL using posted email (updates live via JS) or placeholder
$qrEmail = trim($_POST['email'] ?? 'staff@askglobal.com.np');
$qrUrl = $ga->getQRCodeGoogleUrl($qrEmail, $secret, 'ASK MIS');

// Lookups
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts = $db->query("SELECT id, dept_name, dept_code FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allRoles = $db->query("SELECT id, role_name FROM roles ORDER BY id")->fetchAll();
$allAdmins = $db->query("
    SELECT u.id, u.full_name, b.branch_name FROM users u
    LEFT JOIN roles r    ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE r.role_name = 'admin' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $emergencyContact = trim($_POST['emergency_contact'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $deptId = (int) ($_POST['department_id'] ?? 0);
    $managedBy = (int) ($_POST['managed_by'] ?? 0) ?: null;
    $joiningDate = $_POST['joining_date'] ?? null;
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!$fullName)
        $errors[] = 'Full name is required.';
    if (!$username)
        $errors[] = 'Username is required.';
    if (!$email)
        $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email format.';
    if (!$roleId)
        $errors[] = 'Role is required.';
    if (!$branchId)
        $errors[] = 'Branch is required.';
    if (!$deptId)
        $errors[] = 'Department is required.';
    if (!$password)
        $errors[] = 'Password is required.';
    if (strlen($password) < 6)
        $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirmPass)
        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $uCheck = $db->prepare("SELECT id FROM users WHERE username = ?");
        $uCheck->execute([$username]);
        if ($uCheck->fetch())
            $errors[] = 'Username already taken.';
    }
    if (!$errors) {
        $eCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $eCheck->execute([$email]);
        if ($eCheck->fetch())
            $errors[] = 'Email already registered.';
    }

    if (!$errors) {
        $hashedPwd = password_hash($password, PASSWORD_DEFAULT);

        $db->prepare("
            INSERT INTO users
            (full_name, username, email, phone,
            password, role_id, branch_id, department_id,
            managed_by, joining_date, address,
            emergency_contact,
            ga_secret, ga_enabled, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,1,NOW())
        ")->execute([
                    $fullName,
                    $username,
                    $email,
                    $phone ?: null,
                    $hashedPwd,
                    $roleId,
                    $branchId,
                    $deptId,
                    $managedBy,
                    $joiningDate ?: null,
                    $address ?: null,
                    $emergencyContact ?: null,
                    $secret,
                ]);

        $newId = $db->lastInsertId();

        // Send welcome email with credentials + secret
        try {
            require_once '../../config/mailer.php';
            $emailHtml = emailWrapper("
                <h2 style='color:#0a0f1e;'>Welcome to MISPro</h2>
                <p>Dear <strong>{$fullName}</strong>,</p>
                <p>Your account has been created. Here are your login credentials:</p>
                <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;width:160px;'>Username</td>
                        <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;'>Password</td>
                        <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$password}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;'>2FA Secret</td>
                        <td style='padding:8px;font-family:monospace;font-size:1rem;font-weight:700;'>{$secret}</td>
                    </tr>
                </table>
                <p style='font-size:.85rem;color:#6b7280;'>
                    Please install <strong>Google Authenticator</strong> on your phone and add this account using the secret key above before your first login.
                </p>
                <a href='" . APP_URL . "/auth/login.php'
                   style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px;'>
                    Login to MISPro
                </a>
            ");
            sendMail($email, $fullName, '[MISPro] Your Account Has Been Created', $emailHtml);
        } catch (Exception $e) {
        }

        logActivity("Staff added: {$fullName}", 'users');
        setFlash('success', "Staff member \"{$fullName}\" added. Credentials sent to {$email}.");
        header("Location: view.php?id={$newId}");
        exit; // ← redirect to view so QR is visible
    }
}

// ── Bulk Excel Import ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import'])) {
    verifyCsrf();

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please upload a valid file.');
        header('Location: add.php');
        exit;
    }

    $tmpFile = $_FILES['excel_file']['tmp_name'];
    $origName = $_FILES['excel_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        setFlash('error', 'Only .xlsx, .xls or .csv files are supported.');
        header('Location: add.php');
        exit;
    }

    // ── Parse rows ────────────────────────────────────────────────────────────
    $rows = [];

    if ($ext === 'csv') {
        // Native PHP — no library needed
        if (($handle = fopen($tmpFile, 'r')) !== false) {
            $isFirst = true;
            while (($row = fgetcsv($handle)) !== false) {
                if ($isFirst) {
                    $isFirst = false;
                    continue;
                } // skip header
                if (array_filter($row))
                    $rows[] = $row;       // skip blank lines
            }
            fclose($handle);
        }

    } elseif (in_array($ext, ['xlsx', 'xls'])) {
        // Pure PHP XLSX reader — no Composer needed
        $rows = parseXlsxNative($tmpFile);
        if ($rows === false) {
            setFlash('error', 'Could not read the Excel file. Please save as .csv and try again.');
            header('Location: add.php');
            exit;
        }
    }

    if (empty($rows)) {
        setFlash('error', 'The file is empty or has no data rows.');
        header('Location: add.php');
        exit;
    }

    // ── Process rows ──────────────────────────────────────────────────────────
    $imported = 0;
    $skipped = 0;
    $skipReasons = [];

    foreach ($rows as $lineIdx => $row) {
        $lineNum = $lineIdx + 2; // +2 because row 1 = header
        $fullName = trim($row[0] ?? '');
        $username = trim($row[1] ?? '');
        $email = trim($row[2] ?? '');
        $phone = trim($row[3] ?? '');
        $password = trim($row[4] ?? '');
        $roleName = strtolower(trim($row[5] ?? 'staff'));
        $branchName = trim($row[6] ?? '');
        $deptName = trim($row[7] ?? '');
        $joinDate = trim($row[8] ?? '');
        $address = trim($row[9] ?? '');
        $emergency = trim($row[10] ?? '');

        if (!$fullName || !$username || !$email) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: missing name, username, or email.";
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: invalid email \"{$email}\".";
            continue;
        }

        // Duplicate check
        $dup = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $dup->execute([$username, $email]);
        if ($dup->fetch()) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: username or email already exists ({$email}).";
            continue;
        }

        // Resolve role
        $roleRow = $db->prepare("SELECT id FROM roles WHERE role_name=?");
        $roleRow->execute([$roleName ?: 'staff']);
        $roleId = $roleRow->fetchColumn() ?: null;
        if (!$roleId) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: invalid role \"{$roleName}\".";
            continue;
        }

        // Resolve branch
        $branchRow = $db->prepare("
            SELECT id FROM branches
            WHERE LOWER(branch_name) LIKE ? AND is_active=1 LIMIT 1
        ");
        $branchRow->execute(['%' . strtolower($branchName) . '%']);
        $branchId = $branchRow->fetchColumn() ?: null;
        if (!$branchId) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: branch \"{$branchName}\" not found.";
            continue;
        }

        // Resolve department
        $deptRow = $db->prepare("
            SELECT id FROM departments
            WHERE LOWER(dept_name) LIKE ? AND is_active=1 LIMIT 1
        ");
        $deptRow->execute(['%' . strtolower($deptName) . '%']);
        $deptId = $deptRow->fetchColumn() ?: null;
        if (!$deptId) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: department \"{$deptName}\" not found.";
            continue;
        }

        $finalPass = $password ?: 'Welcome@123';
        $hashed = password_hash($finalPass, PASSWORD_DEFAULT);
        $newSecret = $ga->createSecret();

        try {
            $db->prepare("
                INSERT INTO users
                (full_name, username, email, phone, password, role_id,
                 branch_id, department_id, joining_date, address,
                 emergency_contact, ga_secret, ga_enabled, is_active, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,NOW())
            ")->execute([
                        $fullName,
                        $username,
                        $email,
                        $phone ?: null,
                        $hashed,
                        $roleId,
                        $branchId,
                        $deptId,
                        $joinDate ?: null,
                        $address ?: null,
                        $emergency ?: null,
                        $newSecret,
                    ]);
        } catch (Exception $e) {
            $skipped++;
            $skipReasons[] = "Row {$lineNum}: DB error — " . $e->getMessage();
            continue;
        }

        // Send welcome email
        try {
            require_once '../../config/mailer.php';
            $emailHtml = emailWrapper("
                <h2>Welcome to MISPro</h2>
                <p>Dear <strong>{$fullName}</strong>,</p>
                <p><strong>Username:</strong> {$username}</p>
                <p><strong>Password:</strong> {$finalPass}</p>
                <p><strong>2FA Secret:</strong>
                   <span style='font-family:monospace;font-size:1rem;'>{$newSecret}</span>
                </p>
                <p style='font-size:.85rem;color:#6b7280;'>
                    Please set up Google Authenticator with the secret above before your first login.
                </p>
                <a href='" . APP_URL . "/auth/login.php'
                   style='display:inline-block;background:#c9a84c;color:#0a0f1e;
                          padding:10px 24px;border-radius:8px;text-decoration:none;
                          font-weight:700;margin-top:12px;'>
                    Login to MISPro
                </a>
            ");
            sendMail($email, $fullName, '[MISPro] Your Account Has Been Created', $emailHtml);
        } catch (Exception $e) {
            // Email failure is non-fatal — user was still created
        }

        $imported++;
    }

    $type = ($imported === 0 && $skipped > 0) ? 'error' : 'success';
    $msg = "Imported: {$imported} staff.";
    if ($skipped)
        $msg .= " Skipped: {$skipped}.";
    setFlash($type, $msg);

    if (!empty($skipReasons)) {
        $_SESSION['bulk_errors'] = $skipReasons;
    }

    header('Location: add.php');
    exit;
}

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="d-flex align-items-center justify-content-between mb-3">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <h5 style="margin:0;">Add Staff Member</h5>
            </div>

            <!-- Tab switcher -->
            <div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:2px solid #f3f4f6;padding-bottom:0;">
                <button id="tab-single-btn" onclick="switchTab('single')" style="background:none;border:none;padding:.6rem 1.2rem;font-size:.88rem;font-weight:600;
               cursor:pointer;border-bottom:2px solid #c9a84c;margin-bottom:-2px;color:#c9a84c;">
                    <i class="fas fa-user me-1"></i>Single Staff
                </button>
                <button id="tab-bulk-btn" onclick="switchTab('bulk')" style="background:none;border:none;padding:.6rem 1.2rem;font-size:.88rem;font-weight:600;
               cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:#9ca3af;">
                    <i class="fas fa-file-excel me-1"></i>Bulk Import via Excel
                </button>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-4">
                    <strong>Please fix:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- TAB 1 — Single Staff -->
            <div id="tab-single" style="display:block;">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <!-- Add inside the form, right after csrf_token hidden input -->
                            <input type="hidden" name="ga_secret_hidden" value="<?= htmlspecialchars($secret) ?>">
                            <!-- Personal Info -->
                            <div class="card-mis mb-4">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-user text-warning me-2"></i>Personal Information</h5>
                                </div>
                                <div class="card-mis-body">
                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Full Name <span
                                                    class="required-star">*</span></label>
                                            <input type="text" name="full_name" class="form-control"
                                                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Email <span
                                                    class="required-star">*</span></label>
                                            <input type="email" name="email" class="form-control"
                                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Phone</label>
                                            <input type="text" name="phone" class="form-control"
                                                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Emergency Contact</label>
                                            <input type="text" name="emergency_contact" class="form-control"
                                                value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Joining Date</label>
                                            <input type="date" name="joining_date" class="form-control"
                                                value="<?= htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Address</label>
                                            <input type="text" name="address" class="form-control"
                                                value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <!-- Account & Role -->
                            <div class="card-mis mb-4">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-user-shield text-warning me-2"></i>Account & Role</h5>
                                </div>
                                <div class="card-mis-body">
                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Username <span
                                                    class="required-star">*</span></label>
                                            <input type="text" name="username" class="form-control"
                                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
                                                autocomplete="off">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Role <span
                                                    class="required-star">*</span></label>
                                            <select name="role_id" class="form-select" required>
                                                <option value="">-- Select Role --</option>
                                                <?php foreach ($allRoles as $r): ?>
                                                    <option value="<?= $r['id'] ?>" <?= ($_POST['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                                                        <?= ucfirst(htmlspecialchars($r['role_name'])) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Password <span
                                                    class="required-star">*</span></label>
                                            <div class="input-group">
                                                <input type="password" name="password" id="password"
                                                    class="form-control" required autocomplete="new-password"
                                                    minlength="6">
                                                <button type="button" class="btn btn-outline-secondary"
                                                    onclick="togglePassword('password', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Confirm Password <span
                                                    class="required-star">*</span></label>
                                            <div class="input-group">
                                                <input type="password" name="confirm_password" id="confirm_password"
                                                    class="form-control" required autocomplete="new-password">
                                                <button type="button" class="btn btn-outline-secondary"
                                                    onclick="togglePassword('confirm_password', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <!-- Replace the existing ga_secret field with this: -->
                                        <div class="col-12">
                                            <div class="card-mis"
                                                style="border-left:3px solid #10b981;margin-top:.5rem;">
                                                <div class="card-mis-header">
                                                    <h5><i class="fas fa-shield-alt" style="color:#10b981;"></i><span
                                                            class="ms-2">Google 2FA Setup</span></h5>
                                                    <div class="form-check form-switch mb-0">
                                                        <input class="form-check-input" type="checkbox" id="enable2fa"
                                                            checked onchange="toggle2FA(this)">
                                                        <label class="form-check-label" for="enable2fa"
                                                            style="font-size:.78rem;color:#6b7280;">Enable 2FA</label>
                                                    </div>
                                                </div>
                                                <div class="card-mis-body" id="twofa-body">
                                                    <div class="row g-3 align-items-center">
                                                        <div class="col-md-4 text-center">
                                                            <div
                                                                style="background:#fff;padding:10px;border-radius:10px;border:2px solid #e5e7eb;display:inline-block;">
                                                                <img id="qrPreview"
                                                                    src="<?= htmlspecialchars($qrUrl) ?>"
                                                                    style="width:150px;height:150px;display:block;">
                                                            </div>
                                                            <div
                                                                style="font-size:.7rem;color:#9ca3af;margin-top:.4rem;">
                                                                QR updates when email changes
                                                            </div>
                                                        </div>
                                                        <div class="col-md-8">
                                                            <label class="form-label-mis">2FA Secret Key</label>
                                                            <div class="input-group mb-2">
                                                                <input type="text" id="gaSecretDisplay"
                                                                    class="form-control"
                                                                    value="<?= htmlspecialchars($secret) ?>" readonly
                                                                    style="font-family:monospace;font-size:.88rem;letter-spacing:.08em;background:#f9fafb;">
                                                                <button type="button" class="btn btn-outline-secondary"
                                                                    onclick="copyGaSecret()">
                                                                    <i class="fas fa-copy" id="copyGaIcon"></i>
                                                                </button>
                                                            </div>
                                                            <div
                                                                style="font-size:.75rem;color:#6b7280;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.6rem .75rem;">
                                                                <i class="fas fa-info-circle me-1"
                                                                    style="color:#10b981;"></i>
                                                                This secret will be emailed to the staff member.
                                                                They must add it to Google Authenticator before their
                                                                first login.
                                                                2FA is <strong>disabled</strong> until they complete
                                                                setup.
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assignment -->
                            <div class="card-mis mb-4">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-sitemap text-warning me-2"></i>Branch & Department</h5>
                                </div>
                                <div class="card-mis-body">
                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Branch <span
                                                    class="required-star">*</span></label>
                                            <select name="branch_id" class="form-select" required>
                                                <option value="">-- Select Branch --</option>
                                                <?php foreach ($allBranches as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($b['branch_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Department <span
                                                    class="required-star">*</span></label>
                                            <select name="department_id" class="form-select" required>
                                                <option value="">-- Select Department --</option>
                                                <?php foreach ($allDepts as $d): ?>
                                                    <option value="<?= $d['id'] ?>" <?= ($_POST['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($d['dept_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Managed By</label>
                                            <select name="managed_by" class="form-select">
                                                <option value="">-- Select Manager --</option>
                                                <?php foreach ($allAdmins as $a): ?>
                                                    <option value="<?= $a['id'] ?>" <?= ($_POST['managed_by'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($a['full_name']) ?>
                                                        (<?= htmlspecialchars($a['branch_name']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-gold">
                                <i class="fas fa-save me-1"></i>Add Staff Member
                            </button>
                        </form>
                    </div>

                    <!-- Right info -->
                    <div class="col-lg-4">
                        <div class="card-mis p-3" style="border-left:3px solid #c9a84c;">
                            <div style="font-size:.82rem;color:#6b7280;line-height:1.7;">
                                <div class="mb-2">
                                    <i class="fas fa-info-circle text-warning me-2"></i>
                                    <strong>Only Core Admin executives can add staff.</strong>
                                </div>
                                <div class="mb-2">Employee ID is auto-generated if left blank.</div>
                                <div class="mb-2">A welcome email with login credentials will be sent automatically.
                                </div>
                                <div>Password must be at least 6 characters.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-single -->

            <!-- TAB 2 — Bulk Import -->
            <div id="tab-bulk" style="display:none;">
                <div class="row g-4">
                    <div class="col-lg-8">

                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-file-excel text-warning me-2"></i>Upload Excel File</h5>
                            </div>
                            <div class="card-mis-body">
                                <form method="POST" enctype="multipart/form-data" id="bulk-form">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="bulk_import" value="1">

                                    <!-- Drop zone -->
                                    <div id="drop-zone" onclick="document.getElementById('bulk_file').click()" style="border:2px dashed #d1d5db;border-radius:12px;padding:2.5rem 1.5rem;
                               text-align:center;cursor:pointer;transition:.2s;background:#fafafa;"
                                        ondragover="event.preventDefault();this.style.borderColor='#c9a84c';this.style.background='#fffbeb';"
                                        ondragleave="this.style.borderColor='#d1d5db';this.style.background='#fafafa';"
                                        ondrop="handleDrop(event)">
                                        <div id="drop-icon" style="margin-bottom:.75rem;">
                                            <i class="fas fa-cloud-upload-alt"
                                                style="font-size:2.2rem;color:#c9a84c;"></i>
                                        </div>
                                        <div id="drop-text" style="font-size:.9rem;font-weight:600;color:#1f2937;">
                                            Click or drag & drop your Excel file here
                                        </div>
                                        <div style="font-size:.78rem;color:#9ca3af;margin-top:.35rem;">
                                            Supports .xlsx, .xls · Max 5 MB · Up to 500 rows
                                        </div>
                                        <input type="file" id="bulk_file" name="excel_file" accept=".xlsx,.xls,.csv"
                                            style="display:none;" onchange="onFileSelect(this)">
                                    </div>

                                    <!-- File preview -->
                                    <div id="file-preview" style="display:none;margin-top:1rem;background:#f0fdf4;
                               border:1px solid #bbf7d0;border-radius:8px;
                               padding:.75rem 1rem;align-items:center;gap:.75rem;">
                                        <i class="fas fa-file-excel" style="color:#16a34a;font-size:1.4rem;"></i>
                                        <div style="flex:1;min-width:0;">
                                            <div id="file-name" style="font-size:.87rem;font-weight:600;color:#1f2937;
                                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                                            <div id="file-size" style="font-size:.75rem;color:#6b7280;"></div>
                                        </div>
                                        <button type="button" onclick="clearFile()"
                                            style="background:none;border:none;color:#9ca3af;font-size:1rem;cursor:pointer;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <div class="d-flex gap-2 mt-3">
                                        <button type="submit" id="upload-btn" class="btn btn-gold" disabled>
                                            <i class="fas fa-upload me-1"></i>Upload & Process
                                        </button>
                                        <span id="upload-spinner"
                                            style="display:none;align-items:center;gap:.5rem;color:#6b7280;font-size:.85rem;">
                                            <i class="fas fa-spinner fa-spin"></i> Processing…
                                        </span>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- How it works -->
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-circle-question text-warning me-2"></i>How It Works</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered" style="font-size:.75rem;">
                                        <thead style="background:#0a0f1e;color:#c9a84c;">
                                            <tr>
                                                <th>A</th>
                                                <th>B</th>
                                                <th>C</th>
                                                <th>D</th>
                                                <th>E</th>
                                                <th>F</th>
                                                <th>G</th>
                                                <th>H</th>
                                                <th>I</th>
                                                <th>J</th>
                                                <th>K</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>full_name*</td>
                                                <td>username*</td>
                                                <td>email*</td>
                                                <td>phone</td>
                                                <td>password</td>
                                                <td>role_name</td>
                                                <td>branch_name*</td>
                                                <td>dept_name*</td>
                                                <td>joining_date</td>
                                                <td>address</td>
                                                <td>emergency_contact</td>
                                            </tr>
                                            <tr style="color:#9ca3af;font-style:italic;">
                                                <td>John Doe</td>
                                                <td>johndoe</td>
                                                <td>john@ask.com</td>
                                                <td>9800000000</td>
                                                <td>Pass@123</td>
                                                <td>staff</td>
                                                <td>Kathmandu</td>
                                                <td>Tax</td>
                                                <td>2024-01-15</td>
                                                <td>Lalitpur</td>
                                                <td>9800000001</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div style="font-size:.72rem;color:#6b7280;margin-top:.5rem;">
                                    * Required. Password defaults to <strong>Welcome@123</strong> if blank.
                                    Role: <code>staff</code>, <code>admin</code>, or <code>executive</code>.
                                    2FA secret is auto-generated per user and emailed automatically.
                                </div>
                            </div>
                        </div>

                    </div><!-- col-lg-8 -->

                    <div class="col-lg-4">
                        <div class="card-mis mb-3" style="border-left:3px solid #c9a84c;">
                            <div class="card-mis-body p-3">
                                <div style="font-size:.85rem;font-weight:700;color:#1f2937;margin-bottom:.5rem;">
                                    <i class="fas fa-download text-warning me-1"></i>Download Template
                                </div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                                    Use our pre-formatted Excel template with sample rows and column hints.
                                </p>
                                <a href="<?= APP_URL ?>/executive/staff/download_template.php"
                                    class="btn btn-gold btn-sm w-100">
                                    <i class="fas fa-file-csv me-1"></i>Download Template (.csv)
                                </a>
                            </div>
                        </div>

                        <div class="card-mis p-3">
                            <div style="font-size:.82rem;font-weight:700;color:#1f2937;margin-bottom:.75rem;">
                                <i class="fas fa-list-check text-warning me-1"></i>Valid Roles
                            </div>
                            <?php foreach (['staff', 'admin', 'executive'] as $rn): ?>
                                <span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;
                             margin:.1rem .1rem 0 0;display:inline-block;font-size:.78rem;">
                                    <?= $rn ?>
                                </span>
                            <?php endforeach; ?>

                            <div style="font-size:.82rem;font-weight:700;color:#1f2937;margin:1rem 0 .5rem;">
                                <i class="fas fa-code-branch text-warning me-1"></i>Available Branches
                            </div>
                            <?php foreach ($allBranches as $b): ?>
                                <span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;
                             margin:.1rem .1rem 0 0;display:inline-block;font-size:.78rem;">
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </span>
                            <?php endforeach; ?>

                            <div style="font-size:.82rem;font-weight:700;color:#1f2937;margin:1rem 0 .5rem;">
                                <i class="fas fa-layer-group text-warning me-1"></i>Available Departments
                            </div>
                            <?php foreach ($allDepts as $d): ?>
                                <span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;
                             margin:.1rem .1rem 0 0;display:inline-block;font-size:.78rem;">
                                    <?= htmlspecialchars($d['dept_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /tab-bulk -->


            <script>
                // ── Tab switching ─────────────────────────────────────────────────────────────
                function switchTab(tab) {
                    document.getElementById('tab-single').style.display = tab === 'single' ? 'block' : 'none';
                    document.getElementById('tab-bulk').style.display = tab === 'bulk' ? 'block' : 'none';
                    const gold = '#c9a84c', gray = '#9ca3af';
                    const active = tab === 'single' ? 'tab-single-btn' : 'tab-bulk-btn';
                    const inactive = tab === 'single' ? 'tab-bulk-btn' : 'tab-single-btn';
                    document.getElementById(active).style.color = gold;
                    document.getElementById(active).style.borderBottomColor = gold;
                    document.getElementById(inactive).style.color = gray;
                    document.getElementById(inactive).style.borderBottomColor = 'transparent';
                }

                // ── 2FA toggle ────────────────────────────────────────────────────────────────
                function toggle2FA(checkbox) {
                    const body = document.getElementById('twofa-body');
                    body.style.display = checkbox.checked ? 'block' : 'none';
                }

                // ── Password toggle ───────────────────────────────────────────────────────────
                function togglePassword(fieldId, btn) {
                    const field = document.getElementById(fieldId);
                    const icon = btn.querySelector('i');
                    if (field.type === 'password') {
                        field.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        field.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                }

                // ── Copy 2FA secret ───────────────────────────────────────────────────────────
                function copyGaSecret() {
                    const text = document.getElementById('gaSecretDisplay').value;
                    navigator.clipboard.writeText(text).then(() => {
                        const icon = document.getElementById('copyGaIcon');
                        icon.className = 'fas fa-check';
                        icon.style.color = '#10b981';
                        setTimeout(() => { icon.className = 'fas fa-copy'; icon.style.color = ''; }, 2000);
                    });
                }

                // ── QR update on email blur ───────────────────────────────────────────────────
                document.querySelector('[name="email"]')?.addEventListener('blur', function () {
                    const email = this.value.trim();
                    const secret = document.getElementById('gaSecretDisplay').value;
                    if (!email) return;
                    const otpUrl = 'otpauth://totp/ASK MIS:' + encodeURIComponent(email) +
                        '?secret=' + secret + '&issuer=ASK MIS';
                    document.getElementById('qrPreview').src =
                        'https://chart.googleapis.com/chart?chs=160x160&chld=M|0&cht=qr&chl=' +
                        encodeURIComponent(otpUrl);
                });

                // ── File drop / select helpers ────────────────────────────────────────────────
                function formatBytes(bytes) {
                    if (bytes < 1024) return bytes + ' B';
                    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                    return (bytes / 1048576).toFixed(2) + ' MB';
                }

                function showFile(file) {
                    document.getElementById('file-name').textContent = file.name;
                    document.getElementById('file-size').textContent = formatBytes(file.size);
                    document.getElementById('file-preview').style.display = 'flex';
                    document.getElementById('drop-text').textContent = 'File selected — ready to upload';
                    document.getElementById('drop-icon').innerHTML =
                        '<i class="fas fa-check-circle" style="font-size:2.2rem;color:#16a34a;"></i>';
                    document.getElementById('upload-btn').disabled = false;
                }

                function onFileSelect(input) {
                    if (input.files[0]) showFile(input.files[0]);
                }

                function handleDrop(e) {
                    e.preventDefault();
                    const dz = document.getElementById('drop-zone');
                    dz.style.borderColor = '#d1d5db';
                    dz.style.background = '#fafafa';
                    const file = e.dataTransfer.files[0];
                    if (!file) return;
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!['xlsx', 'xls'].includes(ext)) { alert('Please upload an .xlsx or .xls file.'); return; }
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    document.getElementById('bulk_file').files = dt.files;
                    showFile(file);
                }

                function clearFile() {
                    document.getElementById('bulk_file').value = '';
                    document.getElementById('file-preview').style.display = 'none';
                    document.getElementById('drop-text').textContent = 'Click or drag & drop your Excel file here';
                    document.getElementById('drop-icon').innerHTML =
                        '<i class="fas fa-cloud-upload-alt" style="font-size:2.2rem;color:#c9a84c;"></i>';
                    document.getElementById('upload-btn').disabled = true;
                }

                document.getElementById('bulk-form')?.addEventListener('submit', function () {
                    document.getElementById('upload-btn').disabled = true;
                    document.getElementById('upload-spinner').style.display = 'flex';
                });
            </script>
            <?php include '../../includes/footer.php'; ?>