<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

// Fetch industries
$industries = $db->query("SELECT * FROM industries ORDER BY id DESC")->fetchAll();

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="page-hero">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="page-hero-badge"><i class="fas fa-industry"></i> Settings</div>
                    <h4>Industries</h4>
                    <p>Manage industry categories used across companies.</p>
                </div>
                <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addIndustryModal">
                    <i class="fas fa-plus me-1"></i>Add Industry
                </button>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($industries as $i): ?>
                <div class="col-md-6 col-lg-4">
                    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;
                justify-content:space-between;gap:.75rem;">
                        <div style="display:flex;align-items:center;gap:.6rem;">
                            <span style="background:<?= $i['is_active'] ? '#f0fdf4' : '#f9fafb' ?>;
                         color:<?= $i['is_active'] ? '#16a34a' : '#9ca3af' ?>;
                         padding:.25rem .8rem;border-radius:99px;
                         font-size:.78rem;font-weight:700;">
                                <i class="fas fa-industry me-1"></i>
                                <?= htmlspecialchars($i['industry_name']) ?>
                            </span>
                            <?php if (!$i['is_active']): ?>
                                <span style="font-size:.68rem;color:#9ca3af;background:#f3f4f6;
                             padding:.15rem .5rem;border-radius:99px;">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <button class="editBtn" data-id="<?= $i['id'] ?>"
                            data-name="<?= htmlspecialchars($i['industry_name']) ?>" data-status="<?= $i['is_active'] ?>"
                            data-bs-toggle="modal" data-bs-target="#editIndustryModal" style="background:#eff6ff;color:#3b82f6;border:none;
                   border-radius:6px;padding:.3rem .6rem;
                   font-size:.75rem;cursor:pointer;">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ADD INDUSTRY MODAL -->
<div class="modal fade" id="addIndustryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="save_industry.php" class="modal-content">
            <div class="modal-header" style="background:#0a0f1e;">
                <h5 class="modal-title text-white">Add Industry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label>Industry Name</label>
                    <input type="text" name="industry_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-gold">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT INDUSTRY MODAL -->
<div class="modal fade" id="editIndustryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="update_industry.php" class="modal-content">
            <div class="modal-header" style="background:#0a0f1e;">
                <h5 class="modal-title text-white">Edit Industry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="edit_id">

                <div class="mb-2">
                    <label>Industry Name</label>
                    <input type="text" name="industry_name" id="edit_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Status</label>
                    <select name="is_active" id="edit_status" class="form-control">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_id').value = this.dataset.id;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_status').value = this.dataset.status;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>