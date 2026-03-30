<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Fiscal Year Settings';

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-calendar-alt"></i> Settings</div>
                        <h4>Fiscal Years</h4>
                        <p>Manage fiscal year codes, labels, and active periods.</p>
                    </div>
                    <button class="btn btn-gold btn-sm" onclick="openFYModal()">
                        <i class="fas fa-plus me-1"></i>Add FY
                    </button>
                </div>
            </div>

            <div class="row g-3">
                <?php
                $res = $db->query("SELECT * FROM fiscal_years ORDER BY id DESC");
                $fyRows = $res->fetchAll(PDO::FETCH_ASSOC);
                foreach ($fyRows as $row):
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;padding:1rem 1.1rem;">
                            <div
                                style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:.6rem;">
                                <span style="background:<?= $row['is_current'] ? '#f0fdf4' : '#f9fafb' ?>;
                         color:<?= $row['is_current'] ? '#16a34a' : '#6b7280' ?>;
                         padding:.25rem .8rem;border-radius:99px;
                         font-size:.78rem;font-weight:700;">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    <?= htmlspecialchars($row['fy_label'] ?: $row['fy_code']) ?>
                                    <?= $row['is_current'] ? ' ★' : '' ?>
                                </span>
                                <button onclick='editFY(<?= json_encode($row) ?>)' style="background:#eff6ff;color:#3b82f6;border:none;
                           border-radius:6px;padding:.3rem .6rem;
                           font-size:.75rem;cursor:pointer;">
                                    <i class="fas fa-pen"></i>
                                </button>
                            </div>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                <span
                                    style="font-size:.72rem;background:#eff6ff;color:#3b82f6;padding:.2rem .55rem;border-radius:6px;">
                                    <?= htmlspecialchars($row['fy_code']) ?>
                                </span>
                                <span
                                    style="font-size:.72rem;background:#f3f4f6;color:#6b7280;padding:.2rem .55rem;border-radius:6px;">
                                    <?= $row['start_date'] ?> → <?= $row['end_date'] ?>
                                </span>
                                <span style="font-size:.72rem;background:<?= $row['is_active'] ? '#f0fdf4' : '#fef2f2' ?>;
                         color:<?= $row['is_active'] ? '#16a34a' : '#ef4444' ?>;
                         padding:.2rem .55rem;border-radius:6px;">
                                    <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
           

        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="fyModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">

            <div class="modal-header" style="background:#0a0f1e;">
                <h5 class="modal-title text-white">
                    <i class="fas fa-calendar-alt me-2" style="color:#c9a84c;"></i>Fiscal Year
                </h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <form method="POST" action="save_fy.php">
                    <input type="hidden" name="id" id="fy_id">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label>FY Code</label>
                            <input type="text" name="fy_code" id="fy_code" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label>FY Label</label>
                            <input type="text" name="fy_label" id="fy_label" class="form-control" readonly>
                        </div>

                        <div class="col-md-6">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label>End Date</label>
                            <input type="date" name="end_date" id="end_date" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label>Active</label>
                            <select name="is_active" id="is_active" class="form-control">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label>Current FY</label>
                            <select name="is_current" id="is_current" class="form-control">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                    </div>

                    <div class="mt-3 text-end d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-gold btn-sm">Save</button>
                    </div>

                </form>

            </div>

        </div>
    </div>
</div>

<script>
    // open modal
    function openFYModal() {
        let modal = new bootstrap.Modal(document.getElementById('fyModal'));
        modal.show();
    }

    // auto label
    document.getElementById('fy_code').addEventListener('input', function () {
        let code = this.value;
        if (code.length >= 4) {
            let start = code.substring(0, 4);
            document.getElementById('fy_label').value = start + '/' + (parseInt(start) + 1);
        }
    });

    // edit
    function editFY(data) {
        document.getElementById('fy_id').value = data.id;
        document.getElementById('fy_code').value = data.fy_code;
        document.getElementById('fy_label').value = data.fy_label;
        document.getElementById('start_date').value = data.start_date;
        document.getElementById('end_date').value = data.end_date;
        document.getElementById('is_active').value = data.is_active;
        document.getElementById('is_current').value = data.is_current;

        openFYModal();
    }
</script>

<?php include '../../includes/footer.php'; ?>