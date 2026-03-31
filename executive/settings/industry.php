<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

$pageTitle = 'Industry Management';

// Fetch industries
$industries = $db->query("SELECT * FROM industries ORDER BY is_active DESC, industry_name ASC")->fetchAll();

$activeCount   = count(array_filter($industries, fn($i) => $i['is_active']));
$inactiveCount = count($industries) - $activeCount;

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-industry"></i> Settings</div>
                        <h4>Industry Management</h4>
                        <p>Manage industry categories used across companies.</p>
                    </div>
                    <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addIndustryModal">
                        <i class="fas fa-plus me-1"></i>Add Industry
                    </button>
                </div>
            </div>

            <!-- Stats strip -->
            <div class="card-mis mb-4 p-3">
                <div class="d-flex gap-4 flex-wrap" style="font-size:.82rem;">
                    <div>
                        <span style="color:#9ca3af;">Total:</span>
                        <strong style="color:#1f2937;margin-left:.3rem;"><?= count($industries) ?></strong>
                    </div>
                    <div>
                        <span style="color:#9ca3af;">Active:</span>
                        <strong style="color:#10b981;margin-left:.3rem;"><?= $activeCount ?></strong>
                    </div>
                    <?php if ($inactiveCount > 0): ?>
                    <div>
                        <span style="color:#9ca3af;">Inactive:</span>
                        <strong style="color:#ef4444;margin-left:.3rem;"><?= $inactiveCount ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Industry cards -->
            <div class="row g-3">
                <?php if (empty($industries)): ?>
                    <div class="col-12">
                        <div class="card-mis text-center py-5" style="color:#9ca3af;">
                            <i class="fas fa-industry fa-2x mb-2 d-block"></i>
                            <p style="font-size:.85rem;">No industries added yet.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($industries as $ind):
                    $isActive  = (bool)$ind['is_active'];
                    $iconColor = $isActive ? '#8b5cf6' : '#9ca3af';
                    $iconBg    = $isActive ? '#f5f3ff' : '#f9fafb';
                    $cardBorder= $isActive ? '#ede9fe44' : '#f3f4f6';
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div style="background:#fff;border-radius:12px;border:1px solid <?= $cardBorder ?>;
                                    padding:1rem 1.1rem;display:flex;align-items:center;
                                    justify-content:space-between;gap:.75rem;
                                    transition:box-shadow .15s;"
                             onmouseenter="this.style.boxShadow='0 2px 12px rgba(0,0,0,.07)'"
                             onmouseleave="this.style.boxShadow='none'">

                            <div style="display:flex;align-items:center;gap:.75rem;">

                                <!-- Icon -->
                                <div style="width:38px;height:38px;border-radius:10px;
                                            background:<?= $iconBg ?>;flex-shrink:0;
                                            display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-industry" style="color:<?= $iconColor ?>;font-size:.95rem;"></i>
                                </div>

                                <div>
                                    <div style="font-weight:600;font-size:.88rem;color:<?= $isActive ? '#1f2937' : '#9ca3af' ?>;line-height:1.2;">
                                        <?= htmlspecialchars($ind['industry_name']) ?>
                                    </div>
                                    <div style="margin-top:.3rem;">
                                        <?php if ($isActive): ?>
                                            <span style="font-size:.68rem;font-weight:700;
                                                         background:#f5f3ff;color:#7c3aed;
                                                         padding:.15rem .55rem;border-radius:99px;">
                                                <i class="fas fa-circle" style="font-size:.45rem;vertical-align:middle;margin-right:.3rem;"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:.68rem;font-weight:600;
                                                         background:#f3f4f6;color:#9ca3af;
                                                         padding:.15rem .55rem;border-radius:99px;">
                                                <i class="fas fa-circle" style="font-size:.45rem;vertical-align:middle;margin-right:.3rem;"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit button -->
                            <button class="editBtn"
                                data-id="<?= $ind['id'] ?>"
                                data-name="<?= htmlspecialchars($ind['industry_name']) ?>"
                                data-status="<?= (int)$ind['is_active'] ?>"
                                data-bs-toggle="modal" data-bs-target="#editIndustryModal"
                                style="background:#f5f3ff;color:#8b5cf6;border:none;
                                       border-radius:6px;padding:.3rem .65rem;
                                       font-size:.75rem;cursor:pointer;flex-shrink:0;"
                                title="Edit <?= htmlspecialchars($ind['industry_name']) ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- ── ADD INDUSTRY MODAL ───────────────────────────────────────────────────── -->
<div class="modal fade" id="addIndustryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="save_industry.php" class="modal-content"
              style="border-radius:16px;overflow:hidden;border:none;
                     box-shadow:0 24px 60px rgba(0,0,0,.15);">

            <!-- Header -->
            <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f3f4f6;
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:.6rem;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#f5f3ff;
                                display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-plus" style="color:#8b5cf6;font-size:.8rem;"></i>
                    </div>
                    <h5 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;">Add Industry</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        style="font-size:.8rem;"></button>
            </div>

            <!-- Body -->
            <div style="padding:1.5rem;">
                <div class="mb-3">
                    <label class="form-label-mis">Industry Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="industry_name" class="form-control form-control-sm"
                           placeholder="e.g. Manufacturing, Retail, IT…" required>
                </div>
                <div>
                    <label class="form-label-mis">Status</label>
                    <select name="is_active" class="form-select form-select-sm">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:1rem 1.5rem;border-top:1px solid #f3f4f6;
                        display:flex;justify-content:flex-end;gap:.6rem;">
                <button type="button" data-bs-dismiss="modal"
                        style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                               padding:.5rem 1rem;font-size:.83rem;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-gold btn-sm" style="padding:.5rem 1.25rem;">
                    <i class="fas fa-save me-1"></i>Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT INDUSTRY MODAL ──────────────────────────────────────────────────── -->
<div class="modal fade" id="editIndustryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="update_industry.php" class="modal-content"
              style="border-radius:16px;overflow:hidden;border:none;
                     box-shadow:0 24px 60px rgba(0,0,0,.15);">

            <!-- Header -->
            <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f3f4f6;
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:.6rem;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#f5f3ff;
                                display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-pen" style="color:#8b5cf6;font-size:.75rem;"></i>
                    </div>
                    <div>
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;">Edit Industry</h5>
                        <div id="edit_modal_subtitle" style="font-size:.7rem;color:#9ca3af;margin-top:.05rem;"></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        style="font-size:.8rem;"></button>
            </div>

            <!-- Body -->
            <div style="padding:1.5rem;">
                <input type="hidden" name="id" id="edit_id">

                <div class="mb-3">
                    <label class="form-label-mis">Industry Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="industry_name" id="edit_name"
                           class="form-control form-control-sm" required>
                </div>
                <div>
                    <label class="form-label-mis">Status</label>
                    <select name="is_active" id="edit_status" class="form-select form-select-sm">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:1rem 1.5rem;border-top:1px solid #f3f4f6;
                        display:flex;justify-content:flex-end;gap:.6rem;">
                <button type="button" data-bs-dismiss="modal"
                        style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                               padding:.5rem 1rem;font-size:.83rem;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" style="padding:.5rem 1.25rem;">
                    <i class="fas fa-save me-1"></i>Update
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_id').value     = this.dataset.id;
            document.getElementById('edit_name').value   = this.dataset.name;
            document.getElementById('edit_status').value = this.dataset.status;
            document.getElementById('edit_modal_subtitle').textContent = 'Editing: ' + this.dataset.name;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>