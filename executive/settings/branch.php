<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

// Fetch branches
$branches = $db->query("SELECT * FROM branches ORDER BY is_head_office DESC, branch_name ASC")->fetchAll();
$pageTitle = 'Branch Management';
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
                <?php foreach ($branches as $b):
                    $isHead    = (bool)$b['is_head_office'];
                    $isActive  = (bool)$b['is_active'];

                    // Icon & colours differ between head office and regular branches
                    if ($isHead) {
                        $icon       = 'fas fa-building-columns'; // crown-like HQ icon
                        $iconBg     = $isActive ? '#fef9ec' : '#f9fafb';
                        $iconColor  = $isActive ? '#c9a84c'  : '#9ca3af';
                        $badgeBg    = $isActive ? '#fef9ec'  : '#f9fafb';
                        $badgeColor = $isActive ? '#92710a'  : '#9ca3af';
                        $cardBorder = $isActive ? '#f0d98833' : '#f3f4f6';
                        $cardBg     = $isActive ? '#fffdf5'  : '#fff';
                    } else {
                        $icon       = 'fas fa-map-marker-alt'; // location pin for branches
                        $iconBg     = $isActive ? '#eff6ff'  : '#f9fafb';
                        $iconColor  = $isActive ? '#3b82f6'  : '#9ca3af';
                        $badgeBg    = $isActive ? '#eff6ff'  : '#f9fafb';
                        $badgeColor = $isActive ? '#1d4ed8'  : '#9ca3af';
                        $cardBorder = '#f3f4f6';
                        $cardBg     = '#fff';
                    }
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div style="background:<?= $cardBg ?>;border-radius:12px;
                                    border:1px solid <?= $cardBorder ?>;
                                    padding:1rem 1.1rem;display:flex;align-items:center;
                                    justify-content:space-between;gap:.75rem;
                                    transition:box-shadow .15s;"
                             onmouseenter="this.style.boxShadow='0 2px 12px rgba(0,0,0,.07)'"
                             onmouseleave="this.style.boxShadow='none'">

                            <div style="display:flex;align-items:center;gap:.75rem;">

                                <!-- Branch type icon -->
                                <div style="width:38px;height:38px;border-radius:10px;
                                            background:<?= $iconBg ?>;
                                            display:flex;align-items:center;justify-content:center;
                                            flex-shrink:0;">
                                    <i class="<?= $icon ?>" style="color:<?= $iconColor ?>;font-size:.95rem;"></i>
                                </div>

                                <div>
                                    <!-- Branch name -->
                                    <div style="font-weight:600;font-size:.88rem;color:#1f2937;line-height:1.2;">
                                        <?= htmlspecialchars($b['branch_name']) ?>
                                    </div>

                                    <!-- Tags row -->
                                    <div style="display:flex;align-items:center;gap:.4rem;margin-top:.3rem;flex-wrap:wrap;">

                                        <!-- Head Office / Branch label -->
                                        <span style="background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;
                                                     padding:.15rem .55rem;border-radius:99px;
                                                     font-size:.68rem;font-weight:700;white-space:nowrap;">
                                            <?php if ($isHead): ?>
                                                <i class="fas fa-star me-1" style="font-size:.6rem;"></i>Head Office
                                            <?php else: ?>
                                                <i class="fas fa-map-marker-alt me-1" style="font-size:.6rem;"></i>Branch
                                            <?php endif; ?>
                                        </span>

                                        <!-- City if available -->
                                        <?php if (!empty($b['city'])): ?>
                                            <span style="font-size:.68rem;color:#9ca3af;">
                                                <i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($b['city']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <!-- Inactive pill -->
                                        <?php if (!$isActive): ?>
                                            <span style="font-size:.65rem;color:#9ca3af;background:#f3f4f6;
                                                         padding:.12rem .45rem;border-radius:99px;">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit button -->
                            <button class="editBranchBtn"
                                data-id="<?= $b['id'] ?>"
                                data-name="<?= htmlspecialchars($b['branch_name']) ?>"
                                data-city="<?= htmlspecialchars($b['city'] ?? '') ?>"
                                data-address="<?= htmlspecialchars($b['address'] ?? '') ?>"
                                data-phone="<?= htmlspecialchars($b['phone'] ?? '') ?>"
                                data-email="<?= htmlspecialchars($b['email'] ?? '') ?>"
                                data-head="<?= (int)$b['is_head_office'] ?>"
                                data-status="<?= (int)$b['is_active'] ?>"
                                data-bs-toggle="modal" data-bs-target="#editBranchModal"
                                style="background:<?= $isHead ? '#fef9ec' : '#eff6ff' ?>;
                                       color:<?= $isHead ? '#c9a84c' : '#3b82f6' ?>;
                                       border:none;border-radius:6px;
                                       padding:.3rem .6rem;font-size:.75rem;
                                       cursor:pointer;flex-shrink:0;"
                                title="Edit <?= htmlspecialchars($b['branch_name']) ?>">
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
                <h5 class="modal-title"><i class="fas fa-plus text-warning me-2"></i>Add Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label-mis">Branch Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="branch_name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label-mis">City</label>
                    <input type="text" name="city" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label-mis">Address</label>
                    <textarea name="address" class="form-control form-control-sm" rows="2"></textarea>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col">
                        <label class="form-label-mis">Phone</label>
                        <input type="text" name="phone" class="form-control form-control-sm">
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col">
                        <label class="form-label-mis">Head Office</label>
                        <select name="is_head_office" class="form-select form-select-sm">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Status</label>
                        <select name="is_active" class="form-select form-select-sm">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT BRANCH MODAL -->
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="update_branch.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen text-warning me-2"></i>Edit Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="edit_branch_id">
                <div class="mb-2">
                    <label class="form-label-mis">Branch Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="branch_name" id="edit_branch_name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label-mis">City</label>
                    <input type="text" name="city" id="edit_city" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label-mis">Address</label>
                    <textarea name="address" id="edit_address" class="form-control form-control-sm" rows="2"></textarea>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col">
                        <label class="form-label-mis">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control form-control-sm">
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col">
                        <label class="form-label-mis">Head Office</label>
                        <select name="is_head_office" id="edit_head_office" class="form-select form-select-sm">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Status</label>
                        <select name="is_active" id="edit_branch_status" class="form-select form-select-sm">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Update</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.editBranchBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_branch_id').value    = this.dataset.id;
            document.getElementById('edit_branch_name').value  = this.dataset.name;
            document.getElementById('edit_city').value         = this.dataset.city    || '';
            document.getElementById('edit_address').value      = this.dataset.address || '';
            document.getElementById('edit_phone').value        = this.dataset.phone   || '';
            document.getElementById('edit_email').value        = this.dataset.email   || '';
            document.getElementById('edit_head_office').value  = this.dataset.head;
            document.getElementById('edit_branch_status').value = this.dataset.status;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>