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

            <div class="card-mis">
                <div class="card-mis-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-calendar-alt text-warning me-2"></i>Fiscal Year</h5>

                    <!-- Add Button -->
                    <button class="btn btn-sm btn-primary" onclick="openFYModal()">
                        <i class="fas fa-plus"></i> Add FY
                    </button>
                </div>

                <div class="card-mis-body">

                    <!-- Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>FY Code</th>
                                    <th>Label</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Active</th>
                                    <th>Current</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $res = $db->query("SELECT * FROM fiscal_years ORDER BY id DESC");
                                while ($row = $res->fetch(PDO::FETCH_ASSOC)):
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['fy_code']) ?></td>
                                    <td><?= htmlspecialchars($row['fy_label']) ?></td>
                                    <td><?= $row['start_date'] ?></td>
                                    <td><?= $row['end_date'] ?></td>
                                    <td><?= $row['is_active'] ? 'Yes' : 'No' ?></td>
                                    <td><?= $row['is_current'] ? 'Yes' : 'No' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning"
                                            onclick='editFY(<?= json_encode($row) ?>)'>
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>

                        </table>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="fyModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Fiscal Year</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
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

                    <div class="mt-3 text-end">
                        <button class="btn btn-primary">Save</button>
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