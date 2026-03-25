<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db        = getDB();
$user      = currentUser();
$pageTitle = 'Bank Summary';
$userRole  = $user['role'] ?? 'staff';

// ── Get the logged-in user's branch ──────────────────────────────────────────
$userBranchStmt = $db->prepare("
    SELECT b.id, b.branch_name, b.address
    FROM   branches b
    INNER JOIN users u ON u.branch_id = b.id
    WHERE  u.id = ? AND b.is_active = 1
    LIMIT  1
");
$userBranchStmt->execute([$user['id']]);
$userBranch = $userBranchStmt->fetch();

// Lookups (for admin add-bank form only)
$allBanks    = $db->query("SELECT id, bank_name, address FROM bank_references WHERE is_active=1 ORDER BY bank_name")->fetchAll();
$fiscalYears = $db->query("
    SELECT fy_code
    FROM fiscal_years 
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);

$filterFY = $_GET['fiscal_year'] ?? '';

// ── Handle: Add bank reference (admin only) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank']) && $userRole === 'admin') {
    verifyCsrf();
    $bankName = trim($_POST['bank_name']    ?? '');
    $address  = trim($_POST['bank_address'] ?? '');
    if ($bankName) {
        try {
            $db->prepare("INSERT INTO bank_references (bank_name, address, created_by) VALUES (?, ?, ?)")
               ->execute([$bankName, $address, $user['id']]);
            setFlash('success', "Bank \"{$bankName}\" added.");
        } catch (Exception $e) {
            setFlash('error', 'Bank name already exists.');
        }
    }
    header('Location: summary.php'); exit;
}

// ── Fetch summary — ONLY for the logged-in user's branch ─────────────────────
$where  = ['1=1'];
$params = [];

if ($userBranch) {
    $where[]  = 'bs.branch_id = ?';
    $params[] = $userBranch['id'];
}
if (!empty($filterFY)) {
    $where[]  = 'bs.fiscal_year = ?';
    $params[] = $filterFY;
}

$ws = implode(' AND ', $where);

$summaryStmt = $db->prepare("
    SELECT bs.*, br.bank_name, br.address AS bank_address, b.branch_name, b.address AS branch_address
    FROM   bank_summary   bs
    LEFT JOIN bank_references br ON br.id = bs.bank_reference_id
    LEFT JOIN branches        b  ON b.id  = bs.branch_id
    WHERE  {$ws}
    ORDER BY bs.total_files DESC
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetchAll();

// ── Dynamic %: each bank's files ÷ grand total × 100 ─────────────────────────
$grandTotal = array_sum(array_column($summary, 'total_files'));

foreach ($summary as &$row) {
    $row['pct_of_total_files'] = ($grandTotal > 0)
        ? round(($row['total_files'] / $grandTotal) * 100, 2)
        : 0.00;
}
unset($row);

$totals = [
    'total_files' => $grandTotal,
    'completed'   => array_sum(array_column($summary, 'completed')),
    'hbc'         => array_sum(array_column($summary, 'hbc')),
    'pending'     => array_sum(array_column($summary, 'pending')),
    'support'     => array_sum(array_column($summary, 'support')),
    'cancelled'   => array_sum(array_column($summary, 'cancelled')),
];

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php
if ($userRole === 'executive') include '../../includes/sidebar_executive.php';
elseif ($userRole === 'admin') include '../../includes/sidebar_admin.php';
else                           include '../../includes/sidebar_staff.php';
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
            <p>
                Showing data for:
                <strong style="color:#c9a84c;">
                    <?= $userBranch
                        ? htmlspecialchars($userBranch['branch_name'] . ($userBranch['address'] ? ' – ' . $userBranch['address'] : ''))
                        : 'Unknown Branch' ?>
                </strong>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/exports/export_excel.php?module=bank_summary&branch_id=<?= $userBranch['id'] ?? '' ?>&fiscal_year=<?= urlencode($filterFY) ?>"
               class="btn btn-sm" style="background:#16a34a;color:white;border-radius:8px;padding:.4rem .9rem;">
                <i class="fas fa-file-excel me-1"></i>Export Excel
            </a>
        </div>
    </div>
</div>

<!-- Fiscal Year Filter -->
<div class="filter-bar mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label-mis">Fiscal Year</label>
            <select name="fiscal_year" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($fiscalYears as $fy): ?>
                    <option value="<?= $fy ?>" <?= ($filterFY === $fy) ? 'selected' : '' ?>><?= $fy ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i></button>
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
                    <th style="color:#c9a84c;text-align:center;" title="Bank files ÷ Grand total × 100">% of Total Files</th>
                    <th style="color:#c9a84c;text-align:center;">Total Files</th>
                    <th style="color:#10b981;text-align:center;">Completed</th>
                    <th style="color:#f59e0b;text-align:center;">HBC</th>
                    <th style="color:#ef4444;text-align:center;">Pending</th>
                    <th style="color:#6b7280;text-align:center;">Support</th>
                    <th style="color:#ef4444;text-align:center;">Cancelled</th>
                    <th style="color:#c9a84c;text-align:center;">Check</th>
                </tr>
            </thead>
            <tbody>
                <!-- Grand totals row — always 100% -->
                <tr style="background:#1e2a45;">
                    <td colspan="2" style="color:#c9a84c;font-weight:700;font-size:.82rem;">ALL BANKS
                    </td>
                    <td style="color:#c9a84c;font-weight:700;text-align:center;"><?= $grandTotal > 0 ? '100%' : '0%' ?></td>
                    <td style="color:#fff;font-weight:700;text-align:center;"><?= $totals['total_files'] ?></td>
                    <td style="color:#10b981;font-weight:700;text-align:center;"><?= $totals['completed'] ?></td>
                    <td style="color:#f59e0b;font-weight:700;text-align:center;"><?= $totals['hbc'] ?></td>
                    <td style="color:#ef4444;font-weight:700;text-align:center;"><?= $totals['pending'] ?></td>
                    <td style="color:#9ca3af;font-weight:700;text-align:center;"><?= $totals['support'] ?></td>
                    <td style="color:#ef4444;font-weight:700;text-align:center;"><?= $totals['cancelled'] ?></td>
                    <td></td>
                </tr>

                <?php if (empty($summary)): ?>
                <tr>
                    <td colspan="10" class="empty-state">
                        <i class="fas fa-landmark"></i> No data for your branch yet
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($summary as $i => $row):
                    $pct       = $row['pct_of_total_files'];
                    $bankLabel = htmlspecialchars(
                        $row['bank_name'] . ($row['bank_address'] ? ' - ' . $row['bank_address'] : '')
                    );
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.78rem;"><?= $i + 1 ?></td>
                    <td style="font-size:.87rem;font-weight:500;"><?= $bankLabel ?></td>
                    <td style="min-width:140px;">
                        <div style="display:flex;align-items:center;gap:.4rem;">
                            <div style="flex:1;background:#1e2a45;border-radius:99px;height:5px;overflow:hidden;">
                                <div style="width:<?= min(100, $pct) ?>%;background:#c9a84c;height:100%;border-radius:99px;transition:width .4s ease;"></div>
                            </div>
                            <span style="font-size:.75rem;font-weight:600;color:#c9a84c;flex-shrink:0;min-width:42px;text-align:right;">
                                <?= number_format($pct, 2) ?>%
                            </span>
                        </div>
                    </td>
                    <td style="text-align:center;font-weight:600;"><?= $row['total_files'] ?></td>
                    <td style="text-align:center;">
                        <span style="background:#ecfdf5;color:#10b981;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $row['completed'] ?></span>
                    </td>
                    <td style="text-align:center;">
                        <span style="background:#fffbeb;color:#f59e0b;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $row['hbc'] ?></span>
                    </td>
                    <td style="text-align:center;">
                        <span style="background:#fef2f2;color:#ef4444;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $row['pending'] ?></span>
                    </td>
                    <td style="text-align:center;font-size:.78rem;"><?= $row['support'] ?></td>
                    <td style="text-align:center;">
                        <span style="background:#fef2f2;color:#ef4444;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;"><?= $row['cancelled'] ?></span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($row['is_checked']): ?>
                            <span style="background:#ecfdf5;color:#10b981;padding:.2rem .6rem;border-radius:4px;font-size:.75rem;font-weight:700;">TRUE</span>
                        <?php else: ?>
                            <span style="background:#fef2f2;color:#ef4444;padding:.2rem .6rem;border-radius:4px;font-size:.75rem;font-weight:700;">FALSE</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($userRole === 'admin'): ?>

<!-- ── Bank References Card ───────────────────────────────────────────────── -->
<div class="card-mis mb-4">
    <div class="card-mis-header d-flex justify-content-between align-items-center">
        <h5><i class="fas fa-university text-warning me-2"></i>Bank References</h5>
        <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addBankModal">
            <i class="fas fa-plus me-1"></i>Add Bank
        </button>
    </div>

    <?php if (empty($allBanks)): ?>
        <div style="padding:2rem;text-align:center;color:#6b7280;">
            <i class="fas fa-university" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.4;"></i>
            No banks added yet
        </div>
    <?php else: ?>
    <!-- Grid of bank cards -->
    <div style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
        <?php foreach ($allBanks as $i => $bank): ?>
        <div style="
            background:#0d1526;
            border:1px solid #1e2a45;
            border-left:3px solid #c9a84c;
            border-radius:8px;
            padding:.75rem 1rem;
            display:flex;
            align-items:flex-start;
            gap:.65rem;
            transition:border-color .2s;
        ">
            <!-- Icon -->
            <div style="
                width:34px;height:34px;
                background:#1e2a45;
                border-radius:8px;
                display:flex;align-items:center;justify-content:center;
                flex-shrink:0;
            ">
                <i class="fas fa-landmark" style="color:#c9a84c;font-size:.8rem;"></i>
            </div>
            <!-- Text -->
            <div style="min-width:0;">
                <div style="
                    font-size:.82rem;
                    font-weight:600;
                    color:#e5e7eb;
                    white-space:nowrap;
                    overflow:hidden;
                    text-overflow:ellipsis;
                    line-height:1.3;
                " title="<?= htmlspecialchars($bank['bank_name']) ?>">
                    <?= htmlspecialchars($bank['bank_name']) ?>
                </div>
                <?php if ($bank['address']): ?>
                <div style="
                    font-size:.73rem;
                    color:#6b7280;
                    margin-top:.15rem;
                    white-space:nowrap;
                    overflow:hidden;
                    text-overflow:ellipsis;
                " title="<?= htmlspecialchars($bank['address']) ?>">
                    <i class="fas fa-map-marker-alt" style="color:#c9a84c;margin-right:.25rem;font-size:.65rem;"></i>
                    <?= htmlspecialchars($bank['address']) ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- Index badge -->
            <div style="
                margin-left:auto;
                font-size:.68rem;
                color:#4b5563;
                flex-shrink:0;
                padding-top:.1rem;
            ">#<?= $i + 1 ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Add Bank Modal ────────────────────────────────────────────────────── -->
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
                    <input type="hidden" name="add_bank"   value="1">
                    <div class="mb-3">
                        <label class="form-label-mis">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" class="form-control form-control-sm"
                               placeholder="e.g. Nepal Bank Ltd." required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label-mis">Address</label>
                        <input type="text" name="bank_address" class="form-control form-control-sm"
                               placeholder="e.g. Kathmandu, Nepal">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-gold btn-sm" onclick="document.getElementById('addBankForm').submit();">
                    <i class="fas fa-plus me-1"></i>Add
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

</div>
</div>
</div>
<?php include '../../includes/footer.php'; ?>