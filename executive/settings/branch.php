<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

// Fetch branches
$branches = $db->query("SELECT * FROM branches ORDER BY id DESC")->fetchAll();

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
                        <div class="page-hero-badge"><i class="fas fa-code-branch"></i> Settings</div>
                        <h4>Branches</h4>
                        <p>Manage office branches across locations.</p>
                    </div>
                    <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                        <i class="fas fa-plus me-1"></i>Add Branch
                    </button>
                </div>
            </div>

            <div class="row g-3">
                <?php foreach ($branches as $b): ?>
                    <div class="col-md-6 col-lg-4">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;
                justify-content:space-between;gap:.75rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <span style="background:<?= $b['is_active'] ? '#f0fdf4' : '#f9fafb' ?>;
                         color:<?= $b['is_active'] ? '#16a34a' : '#9ca3af' ?>;
                         padding:.25rem .8rem;border-radius:99px;
                         font-size:.78rem;font-weight:700;">
                                    <i class="fas fa-code-branch me-1"></i>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </span>
                                <?php if (!$b['is_active']): ?>
                                    <span style="font-size:.68rem;color:#9ca3af;background:#f3f4f6;
                             padding:.15rem .5rem;border-radius:99px;">Inactive</span>
                                <?php endif; ?>
                            </div>
                            <button class="editBranchBtn" data-id="<?= $b['id'] ?>"
                                data-name="<?= htmlspecialchars($b['branch_name']) ?>"
                                data-city="<?= htmlspecialchars($b['city']) ?>"
                                data-address="<?= htmlspecialchars($b['address']) ?>"
                                data-phone="<?= htmlspecialchars($b['phone']) ?>"
                                data-email="<?= htmlspecialchars($b['email']) ?>" data-head="<?= $b['is_head_office'] ?>"
                                data-status="<?= $b['is_active'] ?>" data-bs-toggle="modal"
                                data-bs-target="#editBranchModal" style="background:#eff6ff;color:#3b82f6;border:none;
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
</div>

<!-- ADD BRANCH MODAL -->
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="save_branch.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="mb-2">
                    <label>Branch Name *</label>
                    <input type="text" name="branch_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>City</label>
                    <input type="text" name="city" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Address</label>
                    <textarea name="address" class="form-control"></textarea>
                </div>

                <div class="mb-2">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Head Office</label>
                    <select name="is_head_office" class="form-control">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
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

<!-- EDIT BRANCH MODAL -->
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="update_branch.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="edit_branch_id">

                <div class="mb-2">
                    <label>Branch Name *</label>
                    <input type="text" name="branch_name" id="edit_branch_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>City</label>
                    <input type="text" name="city" id="edit_city" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Address</label>
                    <textarea name="address" id="edit_address" class="form-control"></textarea>
                </div>

                <div class="mb-2">
                    <label>Phone</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Head Office</label>
                    <select name="is_head_office" id="edit_head_office" class="form-control">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>

                <div class="mb-2">
                    <label>Status</label>
                    <select name="is_active" id="edit_branch_status" class="form-control">
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
    document.querySelectorAll('.editBranchBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_branch_id').value = this.dataset.id;
            document.getElementById('edit_branch_name').value = this.dataset.name;
            document.getElementById('edit_city').value = this.dataset.city || '';
            document.getElementById('edit_address').value = this.dataset.address || '';
            document.getElementById('edit_phone').value = this.dataset.phone || '';
            document.getElementById('edit_email').value = this.dataset.email || '';
            document.getElementById('edit_head_office').value = this.dataset.head;
            document.getElementById('edit_branch_status').value = this.dataset.status;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>