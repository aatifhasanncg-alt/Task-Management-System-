<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

// Fetch grades
$grades = $db->query("SELECT * FROM corporate_grades ORDER BY min_profit ASC")->fetchAll();

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="card-mis">
            <div class="card-mis-header d-flex justify-content-between">
                <h5><i class="fas fa-chart-line"></i> Corporate Grades</h5>
                <!-- Add Grade Button -->
                <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addGradeModal">
                    <i class="fas fa-plus"></i> Add Grade
                </button>
            </div>

            <div class="table-responsive">
                <table class="table-mis w-100">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Min Profit</th>
                            <th>Max Profit</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['grade_name']) ?></td>
                                <td><?= $g['min_profit'] ?></td>
                                <td><?= $g['max_profit'] ?></td>
                                <td><?= htmlspecialchars($g['description']) ?></td>
                                <td>
                                    <?= $g['is_active'] ? 'Active' : 'Inactive' ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary editBtn" data-id="<?= $g['id'] ?>"
                                        data-name="<?= htmlspecialchars($g['grade_name']) ?>"
                                        data-min="<?= $g['min_profit'] ?>" data-max="<?= $g['max_profit'] ?>"
                                        data-desc="<?= htmlspecialchars($g['description']) ?>"
                                        data-status="<?= $g['is_active'] ?>" data-bs-toggle="modal"
                                        data-bs-target="#editGradeModal">
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
<!-- ADD GRADE MODAL -->
<div class="modal fade" id="addGradeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="save_grade.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Corporate Grade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2">
                    <label>Grade Name</label>
                    <input type="text" name="grade_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Min Profit</label>
                    <input type="number" step="0.01" name="min_profit" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Max Profit</label>
                    <input type="number" step="0.01" name="max_profit" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Description</label>
                    <textarea name="description" class="form-control"></textarea>
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
<!-- EDIT GRADE MODAL -->
<div class="modal fade" id="editGradeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="update_grade.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Corporate Grade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="edit_id">

                <div class="mb-2">
                    <label>Grade Name</label>
                    <input type="text" name="grade_name" id="edit_name" class="form-control" required>
                </div>

                <div class="mb-2">
                    <label>Min Profit</label>
                    <input type="number" step="0.01" name="min_profit" id="edit_min" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Max Profit</label>
                    <input type="number" step="0.01" name="max_profit" id="edit_max" class="form-control">
                </div>

                <div class="mb-2">
                    <label>Description</label>
                    <textarea name="description" id="edit_desc" class="form-control"></textarea>
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
        document.getElementById('edit_min').value = this.dataset.min;
        document.getElementById('edit_max').value = this.dataset.max;
        document.getElementById('edit_desc').value = this.dataset.desc;
        document.getElementById('edit_status').value = this.dataset.status;
    });
});
</script>
<?php include '../../includes/footer.php'; ?>