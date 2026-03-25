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

        <div class="card-mis">
            <div class="card-mis-header d-flex justify-content-between">
                <h5><i class="fas fa-industry"></i> Industries</h5>

                <!-- Add Industry Button -->
                <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addIndustryModal">
                    <i class="fas fa-plus"></i> Add Industry
                </button>
            </div>

            <div class="table-responsive">
                <table class="table-mis w-100">
                    <thead>
                        <tr>
                            <th>Industry Name</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($industries as $i): ?>
                            <tr>
                                <td><?= htmlspecialchars($i['industry_name']) ?></td>
                                <td><?= $i['is_active'] ? 'Active' : 'Inactive' ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary editBtn"
                                        data-id="<?= $i['id'] ?>"
                                        data-name="<?= htmlspecialchars($i['industry_name']) ?>"
                                        data-status="<?= $i['is_active'] ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editIndustryModal">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- ADD INDUSTRY MODAL -->
<div class="modal fade" id="addIndustryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="save_industry.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Industry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
            <div class="modal-header">
                <h5 class="modal-title">Edit Industry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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