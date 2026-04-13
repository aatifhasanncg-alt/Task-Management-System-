<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$pageTitle = 'Bank Summary';

$userRole = $user['role'] ?? 'staff';

// Lookups
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allBanks = $db->query("SELECT id, bank_name, address FROM bank_references WHERE is_active=1 ORDER BY bank_name")->fetchAll();
$fiscalYears = $db->query("
    SELECT fy_code 
    FROM fiscal_years WHERE is_active!=0
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);
$filterBank = trim($_GET['search_bank'] ?? '');

$filterBranch = (int) ($_GET['branch_id'] ?? 0);
$filterFY = $_GET['fiscal_year'] ?? '';
$errors = [];

// Handle: Add bank reference
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank'])) {
    verifyCsrf();
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAddress = trim($_POST['address'] ?? '');
    if ($bankName) {
        try {
            $db->prepare("INSERT INTO bank_references (bank_name, address, created_by) VALUES (?, ?, ?)")
                ->execute([$bankName, $bankAddress, $user['id']]);
            setFlash('success', "Bank \"{$bankName}\" added.");
        } catch (Exception $e) {
            setFlash('error', 'Bank name already exists.');
        }
    }
    header('Location: summary.php');
    exit;
}

// Handle: Update existing bank reference
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_bank'])) {
    verifyCsrf();
    $editBankId = (int) trim($_POST['edit_bank_id'] ?? 0);
    $editBankName = trim($_POST['edit_bank_name'] ?? '');
    $editBankAddress = trim($_POST['edit_bank_address'] ?? '');
    if ($editBankId && $editBankName) {
        try {
            $db->prepare("UPDATE bank_references SET bank_name=?, address=?, updated_by=? WHERE id=?")
                ->execute([$editBankName, $editBankAddress, $user['id'], $editBankId]);
            setFlash('success', "Bank reference updated.");
        } catch (Exception $e) {
            setFlash('error', 'Could not update — bank name may already exist.');
        }
    } else {
        setFlash('error', 'Bank name is required.');
    }
    header('Location: summary.php');
    exit;
}

// Handle: Update bank summary row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_summary'])) {
    verifyCsrf();

    // bank_ref_id comes ONLY from the hidden input (not the display select)
    $bankRefId = (int) ($_POST['bank_ref_id'] ?? 0);
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $fy = trim($_POST['fiscal_year'] ?? '');
    $totalFiles = (int) ($_POST['total_files'] ?? 0);
    $completed = (int) ($_POST['completed'] ?? 0);
    $hbc = (int) ($_POST['hbc'] ?? 0);
    $pending = (int) ($_POST['pending'] ?? 0);
    $support = (int) ($_POST['support'] ?? 0);
    $cancelled = (int) ($_POST['cancelled'] ?? 0);
    // Checkbox: only present in POST when ticked — default 0
    $isChecked = (($completed + $hbc + $pending + $support + $cancelled) === $totalFiles) ? 1 : 0;

    if (!$bankRefId || !$branchId || !$fy) {
        setFlash('error', 'Invalid submission — bank, branch or fiscal year missing.');
        header('Location: summary.php');
        exit;
    }

    // Use INSERT ... ON DUPLICATE KEY UPDATE (requires unique key on bank_reference_id+branch_id+fiscal_year)
    // Fallback: manual check-then-update/insert
    $existing = $db->prepare(
        "SELECT id FROM bank_summary WHERE bank_reference_id=? AND branch_id=? AND fiscal_year=?"
    );
    $existing->execute([$bankRefId, $branchId, $fy]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        // UPDATE by primary key — safest, can't accidentally hit another row
        $db->prepare("
            UPDATE bank_summary
            SET total_files=?, completed=?, hbc=?, pending=?,
                support=?, cancelled=?, is_checked=?, updated_by=?
            WHERE id=?
        ")->execute([
                    $totalFiles,
                    $completed,
                    $hbc,
                    $pending,
                    $support,
                    $cancelled,
                    $isChecked,
                    $user['id'],
                    $existingRow['id']          // ← update by PK, not composite key
                ]);
    } else {
        $db->prepare("
            INSERT INTO bank_summary
                (bank_reference_id, branch_id, fiscal_year,
                 total_files, completed, hbc, pending, support, cancelled,
                 is_checked, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
                    $bankRefId,
                    $branchId,
                    $fy,
                    $totalFiles,
                    $completed,
                    $hbc,
                    $pending,
                    $support,
                    $cancelled,
                    $isChecked,
                    $user['id']
                ]);
    }

    setFlash('success', 'Bank summary updated.');
    header('Location: summary.php?branch_id=' . $branchId . '&fiscal_year=' . urlencode($fy));
    exit;
}

// ── Fetch summary rows ──────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];
if ($filterBranch) {
    $where[] = 'bs.branch_id = ?';
    $params[] = $filterBranch;
}
if ($filterFY) {
    $where[] = 'bs.fiscal_year = ?';
    $params[] = $filterFY;
}
if ($filterBank) {
    $where[] = 'br.bank_name LIKE ?';
    $params[] = '%' . $filterBank . '%';
}
$ws = implode(' AND ', $where);

$summaryStmt = $db->prepare("
    SELECT bs.*, br.bank_name, br.address AS bank_address, b.branch_name
    FROM   bank_summary   bs
    LEFT JOIN bank_references br ON br.id = bs.bank_reference_id
    LEFT JOIN branches        b  ON b.id  = bs.branch_id
    WHERE  {$ws}
    ORDER BY bs.total_files DESC
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetchAll();

// ── Calculate grand totals first, then per-row percentage ──────────────────
$grandTotal = array_sum(array_column($summary, 'total_files'));   // e.g. 10

foreach ($summary as &$row) {
    $row['pct_share'] = ($grandTotal > 0)
        ? round(($row['total_files'] / $grandTotal) * 100, 2)
        : 0.00;

    $row['pct_done'] = ($row['total_files'] > 0)
        ? round((min($row['completed'], $row['total_files']) / $row['total_files']) * 100, 2)
        : 0.00;

    // Auto-compute is_checked from actual counts
    $statusSum = $row['completed'] + $row['hbc'] + $row['pending'] + $row['support'] + $row['cancelled'];
    $row['is_checked'] = ($row['total_files'] > 0 && $statusSum === (int)$row['total_files']) ? 1 : 0;
}
unset($row);

// Sort descending by percentage (already sorted by total_files DESC, same order)
// Uncomment if you prefer explicit sort:
// usort($summary, fn($a,$b) => $b['pct_of_total_files'] <=> $a['pct_of_total_files']);

$totals = [
    'total_files' => $grandTotal,
    'completed' => array_sum(array_column($summary, 'completed')),
    'hbc' => array_sum(array_column($summary, 'hbc')),
    'pending' => array_sum(array_column($summary, 'pending')),
    'support' => array_sum(array_column($summary, 'support')),
    'cancelled' => array_sum(array_column($summary, 'cancelled')),
];


// ADD THIS LINE — must be after $totals is defined
$totalPct = ($totals['total_files'] > 0)
    ? round(($totals['completed'] / $totals['total_files']) * 100, 2)
    : 0;
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php
    if ($userRole === 'executive')
        include '../../includes/sidebar_executive.php';
    elseif ($userRole === 'admin')
        include '../../includes/sidebar_admin.php';
    else
        include '../../includes/sidebar_staff.php';
    ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-landmark"></i> Banking</div>
                        <h4>Bank Summary Report</h4>
                        <p>Track file completion status per bank &mdash; percentage calculated from grand total.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=bank_summary&branch_id=<?= $filterBranch ?>&fiscal_year=<?= urlencode($filterFY) ?>"
                            class="btn btn-sm"
                            style="background:#16a34a;color:white;border-radius:8px;padding:.4rem .9rem;">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-3">
                    <label class="form-label-mis">Search Bank</label>
                    <input type="text" name="search_bank" class="form-control form-control-sm"
                        placeholder="Type bank name..."
                        value="<?= htmlspecialchars($_GET['search_bank'] ?? '') ?>">
                </div>
                    <div class="col-md-3">
                        <label class="form-label-mis">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            <?php foreach ($allBranches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-mis">Fiscal Year</label>
                        <select name="fiscal_year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php foreach ($fiscalYears as $fy): ?>
                                <option value="<?= $fy ?>" <?= $filterFY === $fy ? 'selected' : '' ?>><?= $fy ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i>
                            Filter</button>
                        <a href="summary.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <!-- Summary Table -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-table text-warning me-2"></i>Bank Summary</h5>
                    <small class="text-muted">
                        <?= count($summary) ?> bank<?= count($summary) !== 1 ? 's' : '' ?>
                        &nbsp;|&nbsp; Grand total: <strong><?= $grandTotal ?></strong> files
                    </small>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100" id="bankSummaryTable">
                        <thead>
                            <tr style="background:#0a0f1e;">
                                <th style="color:#9ca3af;width:40px;">S.No</th>
                                <th style="color:#c9a84c;">Bank Name</th>
                                <th style="color:#c9a84c;text-align:center;"
                                    title="Bank's total files ÷ Grand total × 100">% Share</th>
                                <th style="color:#10b981;text-align:center;" title="Completed ÷ Bank total × 100">% Done
                                </th>
                                <th style="color:#c9a84c;text-align:center;">Total Files</th>
                                <th style="color:#10b981;text-align:center;">Completed</th>
                                <th style="color:#f59e0b;text-align:center;">HBC</th>
                                <th style="color:#ef4444;text-align:center;">Pending</th>
                                <th style="color:#6b7280;text-align:center;">Support</th>
                                <th style="color:#ef4444;text-align:center;">Cancelled</th>
                                <th style="color:#c9a84c;text-align:center;">Check</th>
                                <?php if (in_array($userRole, ['admin', 'executive'])): ?>
                                    <th style="color:#9ca3af;text-align:center;">Edit</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>

                            <!-- Grand-total summary row -->
                            <tr style="background:#1e2a45;">
                                <td colspan="2" style="color:#c9a84c;font-weight:700;font-size:.82rem;">
                                    <?php
                                    if ($filterBranch) {
                                        $idx = array_search($filterBranch, array_column($allBranches, 'id'));
                                        echo htmlspecialchars($idx !== false ? $allBranches[$idx]['branch_name'] : '');
                                    } else {
                                        echo 'ALL BRANCHES';
                                    }
                                    ?>
                                </td>
                                <!-- Overall completion % (completed ÷ grand total) -->
                                <td style="color:#c9a84c;font-weight:700;text-align:center;">100%</td>
                                <?php
                                $totalDoneColor = $totalPct >= 100 ? '#10b981'
                                    : ($totalPct >= 70 ? '#f59e0b' : '#ef4444');
                                ?>
                                <td style="font-weight:700;text-align:center;">
                                    <div style="display:flex;align-items:center;gap:.4rem;justify-content:center;">
                                        <div
                                            style="width:60px;background:#0a0f1e;border-radius:99px;height:5px;overflow:hidden;">
                                            <div
                                                style="width:<?= min(100, $totalPct) ?>%;background:<?= $totalDoneColor ?>;height:100%;border-radius:99px;">
                                            </div>
                                        </div>
                                        <span style="color:<?= $totalDoneColor ?>;font-size:.78rem;font-weight:700;">
                                            <?= $totalPct ?>%
                                        </span>
                                    </div>
                                </td>
                                <td style="color:#fff;font-weight:700;text-align:center;">
                                    <?= $totals['total_files'] ?>
                                </td>
                                <td style="color:#10b981;font-weight:700;text-align:center;"><?= $totals['completed'] ?>
                                </td>
                                <td style="color:#f59e0b;font-weight:700;text-align:center;"><?= $totals['hbc'] ?></td>
                                <td style="color:#ef4444;font-weight:700;text-align:center;"><?= $totals['pending'] ?>
                                </td>
                                <td style="color:#9ca3af;font-weight:700;text-align:center;"><?= $totals['support'] ?>
                                </td>
                                <td style="color:#ef4444;font-weight:700;text-align:center;"><?= $totals['cancelled'] ?>
                                </td>
                                <td></td>
                                <?php if (in_array($userRole, ['admin', 'executive'])): ?>
                                    <td></td>
                                <?php endif; ?>
                            </tr>

                            <?php if (empty($summary)): ?>
                                <tr>
                                    <td colspan="11" class="empty-state">
                                        <i class="fas fa-landmark"></i> No data yet
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($summary as $i => $row): ?>

                                <tr>
                                    <td style="color:#9ca3af;font-size:.78rem;"><?= $i + 1 ?></td>
                                    <td style="font-size:.87rem;font-weight:500;">
                                        <?= htmlspecialchars($row['bank_name'] . (isset($row['bank_address']) && $row['bank_address'] ? ' - ' . $row['bank_address'] : '')) ?>
                                    </td>

                                    <!-- % of total files: progress bar + number -->
                                    <!-- % Share: this bank's files ÷ grand total -->
                                    <td style="min-width:120px;">
                                        <div style="display:flex;align-items:center;gap:.4rem;">
                                            <div
                                                style="flex:1;background:#1e2a45;border-radius:99px;height:5px;overflow:hidden;">
                                                <div
                                                    style="width:<?= min(100, $row['pct_share']) ?>%;background:#c9a84c;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                            <span
                                                style="font-size:.75rem;font-weight:600;color:#c9a84c;flex-shrink:0;min-width:40px;text-align:right;">
                                                <?= number_format($row['pct_share'], 1) ?>%
                                            </span>
                                        </div>
                                    </td>

                                    <!-- % Done: completed ÷ this bank's total -->
                                    <td style="min-width:100px;">
                                        <?php
                                        $doneColor = $row['pct_done'] >= 100 ? '#10b981'
                                            : ($row['pct_done'] >= 70 ? '#f59e0b' : '#ef4444');
                                        ?>
                                        <div style="display:flex;align-items:center;gap:.4rem;">
                                            <div
                                                style="flex:1;background:#1e2a45;border-radius:99px;height:5px;overflow:hidden;">
                                                <div
                                                    style="width:<?= min(100, $row['pct_done']) ?>%;background:<?= $doneColor ?>;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                            <span
                                                style="font-size:.75rem;font-weight:600;color:<?= $doneColor ?>;flex-shrink:0;min-width:40px;text-align:right;">
                                                <?= number_format($row['pct_done'], 1) ?>%
                                            </span>
                                        </div>
                                    </td>

                                    <td style="text-align:center;font-weight:600;"><?= $row['total_files'] ?></td>

                                    <td style="text-align:center;">
                                        <span
                                            style="background:#ecfdf5;color:#10b981;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;">
                                            <?= $row['completed'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#fffbeb;color:#f59e0b;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;">
                                            <?= $row['hbc'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#fef2f2;color:#ef4444;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;">
                                            <?= $row['pending'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;font-size:.78rem;"><?= $row['support'] ?></td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#fef2f2;color:#ef4444;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;">
                                            <?= $row['cancelled'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($row['is_checked']): ?>
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.2rem .6rem;border-radius:4px;font-size:.75rem;font-weight:700;">TRUE</span>
                                        <?php else: ?>
                                            <span
                                                style="background:#fef2f2;color:#ef4444;padding:.2rem .6rem;border-radius:4px;font-size:.75rem;font-weight:700;">FALSE</span>
                                        <?php endif; ?>
                                    </td>

                                    <?php if (in_array($userRole, ['admin', 'executive'])): ?>
                                        <td style="text-align:center;">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                onclick="openEdit(<?= htmlspecialchars(json_encode($row)) ?>)">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (in_array($userRole, ['admin', 'executive'])): ?>
                <!-- Edit Modal -->
                <div class="modal fade" id="editModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header" style="background:#0a0f1e;">
                                <h5 class="modal-title text-white"><i class="fas fa-landmark me-2"></i>Update Bank Summary
                                </h5>
                                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="editForm">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="update_summary" value="1">
                                    <input type="hidden" name="bank_ref_id" id="edit_bank_ref_id">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Bank</label>
                                            <!-- NO name attr — value is synced to hidden input below -->
                                            <select id="edit_bank_select" class="form-select form-select-sm"
                                                onchange="document.getElementById('edit_bank_ref_id').value = this.value;">
                                                <?php foreach ($allBanks as $b): ?>
                                                    <option value="<?= $b['id'] ?>">
                                                        <?= htmlspecialchars($b['bank_name'] . ($b['address'] ? ' - ' . $b['address'] : '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Branch</label>
                                            <select name="branch_id" id="edit_branch" class="form-select form-select-sm"
                                                required>
                                                <?php foreach ($allBranches as $b): ?>
                                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Fiscal Year</label>
                                            <select name="fiscal_year" id="edit_fy" class="form-select form-select-sm">
                                                <?php foreach ($fiscalYears as $fy): ?>
                                                    <option value="<?= $fy ?>"><?= $fy ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php foreach ([
                                            ['total_files', 'Total Files'],
                                            ['completed', 'Completed'],
                                            ['hbc', 'HBC'],
                                            ['pending', 'Pending'],
                                            ['support', 'Support'],
                                            ['cancelled', 'Cancelled'],
                                        ] as [$field, $label]): ?>
                                            <div class="col-md-4">
                                                <label class="form-label-mis"><?= $label ?></label>
                                                <input type="number" name="<?= $field ?>" id="edit_<?= $field ?>"
                                                    class="form-control form-control-sm" min="0" value="0">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-gold btn-sm" onclick="document.getElementById('editForm').submit();">
                                    <i class="fas fa-save me-1"></i>Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manage Bank References -->
                <div class="card-mis mb-4">
                    <div class="card-mis-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-university text-warning me-2"></i>Bank References</h5>
                        <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addBankModal">
                            <i class="fas fa-plus me-1"></i>Add Bank
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100">
                            <thead>
                                <tr style="background:#0a0f1e;">
                                    <th style="color:#9ca3af;width:40px;">#</th>
                                    <th style="color:#c9a84c;" colspan="2">Bank Name — Address</th>
                                    <th style="color:#9ca3af;text-align:center;width:80px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allBanks)): ?>
                                    <tr>
                                        <td colspan="4" class="empty-state"><i class="fas fa-university"></i> No banks added yet
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($allBanks as $i => $bank): ?>
                                    <tr>
                                        <td style="color:#9ca3af;font-size:.78rem;"><?= $i + 1 ?></td>
                                        <td style="font-size:.87rem;font-weight:500;" colspan="2">
                                            <?= htmlspecialchars($bank['bank_name'] . ($bank['address'] ? ' - ' . $bank['address'] : '')) ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                onclick="openBankEdit(<?= $bank['id'] ?>, <?= htmlspecialchars(json_encode($bank['bank_name'])) ?>, <?= htmlspecialchars(json_encode($bank['address'] ?? '')) ?>)">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Add Bank Modal -->
                <div class="modal fade" id="addBankModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header" style="background:#0a0f1e;">
                                <h5 class="modal-title text-white"><i class="fas fa-plus me-2"></i>Add Bank Reference</h5>
                                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="addBankForm">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="add_bank" value="1">
                                    <div class="mb-3">
                                        <label class="form-label-mis">Bank Name <span class="text-danger">*</span></label>
                                        <input type="text" name="bank_name" class="form-control form-control-sm"
                                            placeholder="e.g. Nepal Bank Ltd." required>
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label-mis">Address</label>
                                        <input type="text" name="address" class="form-control form-control-sm"
                                            placeholder="e.g. Kathmandu, Nepal">
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-gold btn-sm"
                                    onclick="document.getElementById('addBankForm').submit();">
                                    <i class="fas fa-plus me-1"></i>Add
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Bank Reference Modal -->
                <div class="modal fade" id="editBankRefModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header" style="background:#0a0f1e;">
                                <h5 class="modal-title text-white"><i class="fas fa-pen me-2"></i>Edit Bank Reference</h5>
                                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="editBankRefForm">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="update_bank" value="1">
                                    <input type="hidden" name="edit_bank_id" id="ref_edit_id">
                                    <div class="mb-3">
                                        <label class="form-label-mis">Bank Name <span class="text-danger">*</span></label>
                                        <input type="text" name="edit_bank_name" id="ref_edit_name"
                                            class="form-control form-control-sm" required>
                                    </div>
                                    <div class="mb-1">
                                        <label class="form-label-mis">Address</label>
                                        <input type="text" name="edit_bank_address" id="ref_edit_address"
                                            class="form-control form-control-sm">
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-gold btn-sm"
                                    onclick="document.getElementById('editBankRefForm').submit();">
                                    <i class="fas fa-save me-1"></i>Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    function openBankEdit(id, name, address) {
                        document.getElementById('ref_edit_id').value = id;
                        document.getElementById('ref_edit_name').value = name;
                        document.getElementById('ref_edit_address').value = address;
                        new bootstrap.Modal(document.getElementById('editBankRefModal')).show();
                    }

                    function openEdit(row) {
                        // 1. Set the hidden input first (this is what gets submitted)
                        document.getElementById('edit_bank_ref_id').value = row.bank_reference_id;
                        // 2. Set the visible select to match (display only, no name attr)
                        document.getElementById('edit_bank_select').value = row.bank_reference_id;

                        document.getElementById('edit_branch').value = row.branch_id;
                        document.getElementById('edit_fy').value = row.fiscal_year;
                        document.getElementById('edit_total_files').value = row.total_files ?? 0;
                        document.getElementById('edit_completed').value = row.completed ?? 0;
                        document.getElementById('edit_hbc').value = row.hbc ?? 0;
                        document.getElementById('edit_pending').value = row.pending ?? 0;
                        document.getElementById('edit_support').value = row.support ?? 0;
                        document.getElementById('edit_cancelled').value = row.cancelled ?? 0;

                        new bootstrap.Modal(document.getElementById('editModal')).show();
                    }
                </script>
            <?php endif; ?>

        </div><!-- /.main-content inner -->
    </div><!-- /.main-content -->
</div><!-- /.app-wrapper -->
<?php include '../../includes/footer.php'; ?>