<?php
/**
 * admin_employment.php - Manage user_employment_data (target hours for timesheet Overview "To be").
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$msg = '';
$msgType = '';
$dbError = null;

// Ensure table exists (matches migrations/timesheet_schema.php)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_employment_data (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8.00,
            days_per_week DECIMAL(3,2) NOT NULL DEFAULT 5.00,
            hours_per_month DECIMAL(6,2) DEFAULT NULL,
            days_per_month DECIMAL(4,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ued_user (user_id),
            CONSTRAINT fk_ued_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Ensure new columns exist (table may have been created with older schema: weekly_hours, vacation_days_per_year only)
$requiredColumns = [
    'effective_from' => "DATE NOT NULL DEFAULT '2000-01-01'",
    'effective_to'   => "DATE NULL",
    'hours_per_day'  => "DECIMAL(5,2) NOT NULL DEFAULT 8.00",
    'days_per_week'  => "DECIMAL(3,2) NOT NULL DEFAULT 5.00",
];
try {
    $existingCols = $pdo->query("SHOW COLUMNS FROM user_employment_data")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredColumns as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE user_employment_data ADD COLUMN `" . $col . "` " . $def);
        }
    }
} catch (PDOException $e) {
    if ($dbError === null) $dbError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $action = trim($_POST['action'] ?? '');
    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $effectiveFrom = trim($_POST['effective_from'] ?? '');
        $effectiveTo = trim($_POST['effective_to'] ?? '') ?: null;
        $hoursPerDay = (float) ($_POST['hours_per_day'] ?? 8);
        $daysPerWeek = (float) ($_POST['days_per_week'] ?? 5);
        if (!$userId || !$effectiveFrom || $hoursPerDay <= 0 || $daysPerWeek <= 0) {
            $msg = 'User, effective from, hours per day and days per week are required.';
            $msgType = 'danger';
        } else {
            try {
                if ($action === 'edit' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE user_employment_data SET user_id=?, effective_from=?, effective_to=?, hours_per_day=?, days_per_week=? WHERE id=?");
                    $stmt->execute([$userId, $effectiveFrom, $effectiveTo, $hoursPerDay, $daysPerWeek, $id]);
                    $msg = 'Employment data updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO user_employment_data (user_id, effective_from, effective_to, hours_per_day, days_per_week) VALUES (?,?,?,?,?)");
                    $stmt->execute([$userId, $effectiveFrom, $effectiveTo, $hoursPerDay, $daysPerWeek]);
                    $msg = 'Employment data added.';
                }
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare("DELETE FROM user_employment_data WHERE id = ?")->execute([$id]);
                $msg = 'Employment data deleted.';
                $msgType = 'success';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$users = [];
$rows = [];
try {
    $users = $pdo->query("SELECT id, username, first_name, last_name FROM app_users ORDER BY COALESCE(first_name, username), last_name")->fetchAll(PDO::FETCH_ASSOC);
    $rows = $pdo->query("
        SELECT ued.*, u.username, u.first_name, u.last_name
        FROM user_employment_data ued
        JOIN app_users u ON u.id = ued.user_id
        ORDER BY ued.effective_from DESC, ued.user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$pageTitle = 'Employment data (Timesheet target hours)';
require_once 'includes/header.php';
?>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container-xl">
            <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible">
                <?= e($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($dbError): ?>
            <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
            <?php endif; ?>

            <div class="page-header d-print-none mb-4">
                <h1 class="page-title">Employment data</h1>
                <div class="text-muted">Target hours for Timesheet Overview "To be" (hours/day, days/week; hours/week = h/day × d/week)</div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Add period</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="add"/>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">User</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">—</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Effective from</label>
                                <input type="date" name="effective_from" class="form-control" required/>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" style="white-space: nowrap;">Effective to (optional)</label>
                                <input type="date" name="effective_to" class="form-control"/>
                            </div>
                            <div class="col-md">
                                <label class="form-label" style="white-space: nowrap;">Hours/day</label>
                                <input type="number" name="hours_per_day" class="form-control" step="0.25" value="8" required/>
                            </div>
                            <div class="col-md">
                                <label class="form-label" style="white-space: nowrap;">Days/week</label>
                                <input type="number" name="days_per_week" class="form-control" step="0.5" value="5" required/>
                            </div>
                            <div class="col-md">
                                <label class="form-label" style="white-space: nowrap;">Hours/week</label>
                                <input type="text" class="form-control" id="hours_per_week_display" readonly placeholder="40" title="Calculated: hours/day × days/week" style="background-color: #e9ecef; cursor: not-allowed;" />
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-primary">Add</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Current periods</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-end">h/day</th>
                                <th class="text-end">d/week</th>
                                <th class="text-end">h/week</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $hoursPerWeek = (float)($r['hours_per_day'] ?? 0) * (float)($r['days_per_week'] ?? 0);
                            ?>
                            <tr>
                                <td><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: $r['username']) ?></td>
                                <td><?= e($r['effective_from']) ?></td>
                                <td><?= $r['effective_to'] ? e($r['effective_to']) : '—' ?></td>
                                <td class="text-end"><?= number_format((float)$r['hours_per_day'], 2) ?></td>
                                <td class="text-end"><?= number_format((float)$r['days_per_week'], 2) ?></td>
                                <td class="text-end"><?= number_format($hoursPerWeek, 2) ?></td>
                                <td>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this period?');">
                                        <input type="hidden" name="action" value="delete"/>
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>
                                        <button type="submit" class="btn btn-sm btn-ghost-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($rows)): ?>
                <div class="card-body">
                    <p class="text-muted mb-0">No employment data. Add a period so the Timesheet Overview shows "To be" (target) hours.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    function updateHoursPerWeek() {
        var h = parseFloat(document.querySelector('input[name="hours_per_day"]').value) || 0;
        var d = parseFloat(document.querySelector('input[name="days_per_week"]').value) || 0;
        var el = document.getElementById('hours_per_week_display');
        if (el) el.value = (h * d) ? (Math.round(h * d * 100) / 100) : '';
    }
    document.querySelector('input[name="hours_per_day"]').addEventListener('input', updateHoursPerWeek);
    document.querySelector('input[name="days_per_week"]').addEventListener('input', updateHoursPerWeek);
    updateHoursPerWeek();
})();
</script>
<?php require_once 'includes/footer.php'; ?>
