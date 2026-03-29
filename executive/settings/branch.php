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

        <div class="card-mis">
            <div class="card-mis-header d-flex justify-content-between">
                <h5><i class="fas fa-code-branch"></i> Branches</h5>

                <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                    <i class="fas fa-plus"></i> Add Branch
                </button>
            </div>

            <div class="table-responsive">
                <table class="table-mis w-100">
                    <thead>
                        <tr>
                            <th>Branch Name</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($branches as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['branch_name']) ?></td>
                                <td><?= $b['is_active'] ? 'Active' : 'Inactive' ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary editBranchBtn"
                                        data-id="<?= $b['id'] ?>"
                                        data-name="<?= htmlspecialchars($b['branch_name']) ?>"
                                        data-status="<?= $b['is_active'] ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editBranchModal">
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
                    <label>Branch Name</label>
                    <input type="text" name="branch_name" class="form-control" required>
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
                    <label>Branch Name</label>
                    <input type="text" name="branch_name" id="edit_branch_name" class="form-control" required>
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
        document.getElementById('edit_branch_status').value = this.dataset.status;
    });
});
</script>

<?php include '../../includes/footer.php'; ?>