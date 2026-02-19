<?php
/**
 * leaves.php - User-facing leaves management.
 * Manage own absences via get_effective_user_id(). Add, edit, delete. Year calendar.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/leave_colors.php';

$effectiveUserId = get_effective_user_id();
$leaves = [];
$dbError = null;
$msg = '';

if ($effectiveUserId === null) {
    header('Location: index.php?msg=' . urlencode('Please log in as a user to manage leaves.'));
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_leaves (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            comment VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_leave_user (user_id),
            CONSTRAINT fk_leave_app_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE user_leaves DROP COLUMN status");
} catch (PDOException $e) { /* column may already be missing */ }
try {
    $pdo->exec("ALTER TABLE user_leaves ADD COLUMN is_sickness TINYINT(1) NOT NULL DEFAULT 0 AFTER comment");
} catch (PDOException $e) { /* column may already exist */ }

define('LEAVE_COMMENT_SICKNESS', 'Sickness');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_leave'])) {
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $isSickness = !empty($_POST['is_sickness']);
        $comment = trim($_POST['comment'] ?? '') ?: null;
        if ($isSickness && $comment === null) {
            $comment = LEAVE_COMMENT_SICKNESS;
        }
        if ($start && $end && strtotime($start) && strtotime($end) && strtotime($start) <= strtotime($end)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO user_leaves (user_id, start_date, end_date, comment, is_sickness) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$effectiveUserId, $start, $end, $comment, $isSickness ? 1 : 0]);
                $msg = 'Leave added.';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
        } else {
            $msg = 'Invalid dates.';
        }
    }
    if (isset($_POST['update_leave'])) {
        $id = (int) ($_POST['leave_id'] ?? 0);
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $isSickness = !empty($_POST['is_sickness']);
        $comment = trim($_POST['comment'] ?? '') ?: null;
        if ($isSickness && $comment === null) {
            $comment = LEAVE_COMMENT_SICKNESS;
        }
        if ($id > 0 && $start && $end && strtotime($start) <= strtotime($end)) {
            try {
                $cur = $pdo->prepare("SELECT id, start_date, end_date, comment, is_sickness FROM user_leaves WHERE id=? AND user_id=?");
                $cur->execute([$id, $effectiveUserId]);
                $rowBefore = $cur->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("UPDATE user_leaves SET start_date=?, end_date=?, comment=?, is_sickness=? WHERE id=? AND user_id=?");
                $stmt->execute([$start, $end, $comment, $isSickness ? 1 : 0, $id, $effectiveUserId]);
                $rc = $stmt->rowCount();
                $msg = ($rc || $rowBefore) ? 'Leave updated.' : 'Leave not found or access denied.';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
        } else {
            $msg = 'Invalid input.';
        }
    }
    if (isset($_POST['delete_leave'])) {
        $id = (int) ($_POST['leave_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM user_leaves WHERE id=? AND user_id=?");
                $stmt->execute([$id, $effectiveUserId]);
                $msg = $stmt->rowCount() ? 'Leave deleted.' : 'Leave not found or access denied.';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage();
            }
        }
    }
    if ($msg) {
        header('Location: leaves.php?msg=' . urlencode($msg));
        exit;
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT id, start_date, end_date, comment, is_sickness
        FROM user_leaves
        WHERE user_id = ?
        ORDER BY start_date DESC, end_date DESC
    ");
    $stmt->execute([$effectiveUserId]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$displayYear = (int) date('Y');
if (!empty($leaves)) {
    $years = [];
    foreach ($leaves as $l) {
        $years[] = (int) substr($l['start_date'], 0, 4);
        $years[] = (int) substr($l['end_date'], 0, 4);
    }
    if (!empty($years)) {
        $displayYear = max($years);
    }
}

// "Where am I based" – per-user federal state for holiday highlighting (DB)
try {
    $pdo->exec("ALTER TABLE app_users ADD COLUMN based_state_id VARCHAR(2) NULL DEFAULT NULL");
} catch (PDOException $e) { /* column may exist */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_based_state'])) {
    $v = trim($_POST['based_state'] ?? '');
    if ($v === '') $v = null;
    try {
        $stmt = $pdo->prepare("UPDATE app_users SET based_state_id = ? WHERE id = ?");
        $stmt->execute([$v, $effectiveUserId]);
        header('Location: leaves.php' . (isset($_GET['msg']) ? '?msg=' . urlencode($_GET['msg']) : ''));
        exit;
    } catch (PDOException $e) {}
}

$basedStateId = '';
try {
    $stmt = $pdo->prepare("SELECT based_state_id FROM app_users WHERE id = ?");
    $stmt->execute([$effectiveUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['based_state_id'] !== null && $row['based_state_id'] !== '') {
        $basedStateId = $row['based_state_id'];
    }
} catch (PDOException $e) {}

$federalStates = [];
$holidayDates = [];
$holidayNames = [];
try {
    $federalStates = $pdo->query("SELECT id, name FROM federal_states ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    if ($basedStateId !== '') {
        $yearMin = $displayYear - 2;
        $yearMax = $displayYear + 2;
        $stmt = $pdo->prepare("SELECT date, name FROM holidays WHERE state_id = ? AND date >= ? AND date <= ?");
        $stmt->execute([$basedStateId, $yearMin . '-01-01', $yearMax . '-12-31']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $holidayDates[] = $row['date'];
            $holidayNames[$row['date']] = $row['name'] ?? '';
        }
    }
} catch (PDOException $e) {}

// Calculate effective leave days (excluding weekends and holidays)
function calculate_effective_days($start, $end, $holidayDates) {
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $endDate->modify('+1 day'); // Include end date in period
    
    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($startDate, $interval, $endDate);
    
    $days = 0;
    foreach ($period as $dt) {
        $dateStr = $dt->format('Y-m-d');
        $isWeekend = ($dt->format('N') >= 6); // 6 = Saturday, 7 = Sunday
        $isHoliday = in_array($dateStr, $holidayDates);
        
        if (!$isWeekend && !$isHoliday) {
            $days++;
        }
    }
    return $days;
}

$pageTitle = 'Leaves';
$pageHeadExtra = (isset($pageHeadExtra) ? $pageHeadExtra : '') . leave_colors_styles();
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-info alert-dismissible"><?= e($_GET['msg']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Leaves</h1>
                        <div class="text-muted">Manage your absences</div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header d-flex align-items-center p-3">
                            <div style="flex: 1;">
                                <h3 class="card-title mb-0">My leaves</h3>
                            </div>
                            
                            <div style="flex: 2;" class="text-center">
                                <?php
                                $editBased = isset($_GET['edit_based']);
                                $basedStateName = '';
                                foreach ($federalStates as $fs) {
                                    if ($fs['id'] === $basedStateId) { $basedStateName = $fs['name']; break; }
                                }
                                ?>
                                <?php if ($editBased): ?>
                                <form method="post" class="d-inline-block m-0" id="form-based-state">
                                    <input type="hidden" name="save_based_state" value="1">
                                    <label class="form-label mb-0 me-2 d-inline-block small">Where am I based:</label>
                                    <select name="based_state" class="form-select form-select-sm d-inline-block me-1" style="width: auto; min-width: 140px;" id="select-based-state">
                                        <option value="">— Select —</option>
                                        <?php foreach ($federalStates as $fs): ?>
                                        <option value="<?= e($fs['id']) ?>" <?= $basedStateId === $fs['id'] ? 'selected' : '' ?>><?= e($fs['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="save_based_state" class="btn btn-primary btn-sm px-2">Save</button>
                                    <a href="leaves.php" class="btn btn-outline-secondary btn-sm px-2 ms-1">Cancel</a>
                                </form>
                                <?php else: ?>
                                <span class="me-2 text-muted small">Where am I based:</span>
                                <span class="me-2 small fw-bold"><?= $basedStateName !== '' ? e($basedStateName) : '— Select —' ?></span>
                                <a href="leaves.php?edit_based=1" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.7rem;">Edit</a>
                                <?php endif; ?>
                            </div>

                            <div style="flex: 1;" class="text-end">
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-add-leave">
                                    Add leave
                                </button>
                            </div>
                        </div>

                        <?php if (empty($leaves)): ?>
                        <div class="card-body text-center py-5">
                            <p class="text-muted mb-0">No leaves found. Click 'Add leave' to submit one.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table table-striped">
                                <thead>
                                    <tr>
                                        <th class="w-1"></th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th class="text-center">Days</th>
                                        <th>Comment</th>
                                        <th class="w-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leaves as $index => $l): 
                                        $effDays = $l['is_sickness'] ? '—' : calculate_effective_days($l['start_date'], $l['end_date'], $holidayDates);
                                        $rowClass = get_leave_row_class($l['is_sickness'] ?? 0);
                                        $badgeClass = get_leave_badge_class($l['is_sickness'] ?? 0);
                                    ?>
                                    <tr class="<?= $rowClass ? ' ' . $rowClass : '' ?>">
                                        <td>
                                            <input type="radio" name="selected_leave" class="form-check-input m-0 align-middle leave-radio" value="<?= (int)$l['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                        </td>
                                        <td><?= e(date('d.m.Y', strtotime($l['start_date']))) ?></td>
                                        <td><?= e(date('d.m.Y', strtotime($l['end_date']))) ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $badgeClass ?>" title="<?= !empty($l['is_sickness']) ? 'Sickness' : '' ?>"><?= !empty($l['is_sickness']) ? 'S' : $effDays ?></span>
                                        </td>
                                        <td class="text-muted"><?= e($l['comment'] ?? '') ?></td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-edit-leave" data-id="<?= (int)$l['id'] ?>" data-start="<?= e($l['start_date']) ?>" data-end="<?= e($l['end_date']) ?>" data-comment="<?= e($l['comment'] ?? '') ?>" data-is-sickness="<?= !empty($l['is_sickness']) ? '1' : '0' ?>">Edit</button>
                                            <form method="post" class="d-inline-block m-0" onsubmit="return confirm('Delete this leave?');">
                                                <input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                                                <button type="submit" name="delete_leave" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($leaves)): ?>
                    <div class="card" id="calendar-card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <h3 class="card-title mb-0">Year view</h3>
                            <select id="calendar-year" class="form-select form-select-sm" style="width: auto;">
                                <?php for ($y = $displayYear - 2; $y <= $displayYear + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y === $displayYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="card-body">
                            <div id="leave-calendar" class="leaves-year-calendar"></div>
                        </div>
                        <div class="card-footer d-flex flex-wrap gap-4 align-items-center small text-muted">
                            <span class="fw-bold me-1">Legend:</span>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center fw-bold" style="width: 1.2rem; height: 1.2rem; background: var(--tblr-primary); color: #fff; font-size: 0.7rem;">27</span>
                                <span>Selected leave</span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center fw-bold" style="width: 1.2rem; height: 1.2rem; background: rgba(var(--tblr-primary-rgb), 0.12); color: var(--tblr-primary); font-size: 0.7rem;">14</span>
                                <span>Other leave</span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center" style="width: 1.2rem; height: 1.2rem; background: rgba(0, 0, 0, 0.08); color: #495057; font-size: 0.7rem;">Sa</span>
                                <span>Weekend</span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center fw-bold" style="width: 1.2rem; height: 1.2rem; background: rgba(253, 126, 20, 0.35); color: #9c460d; font-size: 0.7rem;">3</span>
                                <span>Holiday</span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center fw-bold" style="width: 1.2rem; height: 1.2rem; background: rgba(214, 51, 108, 0.5); color: #fff; font-size: 0.7rem;">S</span>
                                <span>Sickness</span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded d-inline-flex align-items-center justify-content-center fw-bold" style="width: 1.2rem; height: 1.2rem; border: 1px solid #1e293b; color: #1e293b; font-size: 0.7rem;">17</span>
                                <span>Today</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<div class="modal modal-blur fade" id="modal-add-leave" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">From</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-check">
                            <input type="checkbox" name="is_sickness" value="1" class="form-check-input" id="add-is-sickness">
                            <span class="form-check-label">Sickness</span>
                        </label>
                        <small class="text-muted d-block mt-1">When checked, comment is set to &quot;Sickness&quot; if left empty.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment (optional)</label>
                        <input type="text" name="comment" class="form-control" maxlength="255" placeholder="e.g. Vacation" id="add-comment">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_leave" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="modal-edit-leave" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="leave_id" id="edit-leave-id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">From</label>
                        <input type="date" name="start_date" id="edit-start" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To</label>
                        <input type="date" name="end_date" id="edit-end" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-check">
                            <input type="checkbox" name="is_sickness" value="1" class="form-check-input" id="edit-is-sickness">
                            <span class="form-check-label">Sickness</span>
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment (optional)</label>
                        <input type="text" name="comment" id="edit-comment" class="form-control" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_leave" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var formBasedState = document.getElementById('form-based-state');
    var selectBasedState = document.getElementById('select-based-state');
    if (formBasedState && selectBasedState) {
        selectBasedState.addEventListener('change', function() {
            formBasedState.submit();
        });
    }

    var editModal = document.getElementById('modal-edit-leave');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (btn && btn.dataset.id) {
                document.getElementById('edit-leave-id').value = btn.dataset.id;
                document.getElementById('edit-start').value = btn.dataset.start || '';
                document.getElementById('edit-end').value = btn.dataset.end || '';
                document.getElementById('edit-comment').value = btn.dataset.comment || '';
                document.getElementById('edit-is-sickness').checked = (btn.dataset.isSickness === '1');
            }
        });
    }

    var container = document.getElementById('leave-calendar');
    if (!container) return;

    var leavesData = <?= json_encode(array_map(function($l) {
        return ['id' => (int)$l['id'], 'start_date' => $l['start_date'], 'end_date' => $l['end_date'], 'is_sickness' => !empty($l['is_sickness'])];
    }, $leaves)) ?>;
    var holidayDates = <?= json_encode($holidayDates) ?>;
    var holidayNames = <?= json_encode($holidayNames) ?>;
    var yearSelect = document.getElementById('calendar-year');
    var year = <?= $displayYear ?>;

    function parseDate(str) {
        var p = str.split('-');
        return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    }

    function isInRange(d, start, end) {
        var t = d.getTime();
        return t >= start.getTime() && t <= end.getTime();
    }

    function getSelectedId() {
        var r = document.querySelector('.leave-radio:checked');
        return r ? parseInt(r.value, 10) : null;
    }

    function renderYearCalendar(yr) {
        var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var weekdays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        var selectedId = getSelectedId();
        var html = '<div class="row g-3">';

        for (var m = 0; m < 12; m++) {
            var first = new Date(yr, m, 1);
            var last = new Date(yr, m + 1, 0);
            var startDay = (first.getDay() + 6) % 7;
            var daysInMonth = last.getDate();

            html += '<div class="col-md-4 col-lg-3"><div class="calendar calendar-sm">';
            html += '<div class="calendar-title text-center small fw-bold mb-1">' + monthNames[m] + ' ' + yr + '</div>';
            html += '<div class="calendar-header">';
            for (var w = 0; w < 7; w++) html += '<div class="calendar-date"><span class="date-item" style="font-size:0.65rem;">' + weekdays[w] + '</span></div>';
            html += '</div><div class="calendar-body">';

            for (var i = 0; i < startDay; i++) {
                html += '<div class="calendar-date prev-month"><span class="date-item"></span></div>';
            }
            for (var d = 1; d <= daysInMonth; d++) {
                var date = new Date(yr, m, d);
                var cls = 'calendar-date';
                var dayOfWeek = date.getDay();
                var isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
                if (isWeekend) cls += ' calendar-day-weekend';
                var dateStr = yr + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                if (holidayDates && holidayDates.indexOf(dateStr) !== -1) cls += ' calendar-day-holiday';
                var inSelected = false;
                var inOther = false;
                var inOtherSickness = false;
                var isSelectedStart = false, isSelectedEnd = false;
                var selectedIsSickness = false;
                for (var j = 0; j < leavesData.length; j++) {
                    var L = leavesData[j];
                    var s = parseDate(L.start_date);
                    var e = parseDate(L.end_date);
                    if (isInRange(date, s, e)) {
                        if (L.id === selectedId) {
                            inSelected = true;
                            selectedIsSickness = L.is_sickness || false;
                            isSelectedStart = (date.getTime() === s.getTime());
                            isSelectedEnd = (date.getTime() === e.getTime());
                        } else {
                            inOther = true;
                            if (L.is_sickness) inOtherSickness = true;
                        }
                    }
                }
                if (inSelected) {
                    cls += ' calendar-range';
                    if (selectedIsSickness) cls += ' calendar-range-sickness';
                    if (isSelectedStart) cls += ' range-start';
                    if (isSelectedEnd) cls += ' range-end';
                } else if (inOther) {
                    cls += ' calendar-range-other';
                    if (inOtherSickness) cls += ' calendar-range-other-sickness';
                }
                var today = new Date();
                var isToday = date.getFullYear() === today.getFullYear() && date.getMonth() === today.getMonth() && date.getDate() === today.getDate();
                var isHoliday = holidayDates && holidayDates.indexOf(dateStr) !== -1;
                var holidayTitle = (holidayNames && holidayNames[dateStr]) ? String(holidayNames[dateStr]).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
                html += '<div class="' + cls + '">';
                html += '<span class="date-item' + (isToday ? ' date-today' : '') + '"' + (isHoliday && holidayTitle ? ' title="' + holidayTitle + '"' : '') + '>' + d + '</span></div>';
            }
            var totalCells = startDay + daysInMonth;
            var remainder = totalCells % 7;
            if (remainder > 0) {
                for (var k = 0; k < 7 - remainder; k++) {
                    html += '<div class="calendar-date next-month"><span class="date-item"></span></div>';
                }
            }
            html += '</div></div></div>';
        }
        html += '</div>';
        container.innerHTML = html;
    }

    document.querySelectorAll('.leave-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            renderYearCalendar(year);
            document.getElementById('calendar-card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    if (yearSelect) {
        yearSelect.addEventListener('change', function() {
            year = parseInt(this.value, 10);
            renderYearCalendar(year);
        });
    }

    renderYearCalendar(year);
});
</script>
<style>
/* Basis-Schriftgröße und eine dunklere Grundfarbe für den Kalender */
.leaves-year-calendar .calendar { font-size: 0.7rem; color: #1e293b; }

/* Monats-Überschriften kräftiger */
.leaves-year-calendar .calendar-title { color: #1d273b; font-weight: 700 !important; }

/* Standard-Tage */
.leaves-year-calendar .calendar-date .date-item { 
    width: 1.2rem; 
    height: 1.2rem; 
    line-height: 1.2rem; 
    font-size: 0.7rem; 
    color: #1e293b; /* Dunkles Grau / fast Schwarz */
    font-weight: 500;
}

/* Wochentage (Mo, Tu...) in der Kopfzeile noch etwas kräftiger */
.leaves-year-calendar .calendar-header .date-item { font-weight: 700; color: #000; }

/* Wochenende (Grau) – nicht on top: Urlaub überdeckt Wochenende */
.leaves-year-calendar .calendar-day-weekend .date-item { background: rgba(0, 0, 0, 0.08); color: #495057; }
.leaves-year-calendar .calendar-day-weekend.calendar-range .date-item { background: transparent; color: var(--tblr-primary); }
.leaves-year-calendar .calendar-day-weekend.calendar-range-other .date-item { background: rgba(var(--tblr-primary-rgb), 0.12); color: var(--tblr-primary); }

/* Feiertage on top (unverändert) */
.leaves-year-calendar .calendar-day-holiday .date-item { background: rgba(253, 126, 20, 0.35); color: #9c460d; font-weight: 600; position: relative; z-index: 2; }
.leaves-year-calendar .calendar-day-holiday.calendar-day-weekend .date-item { background: rgba(253, 126, 20, 0.4); color: #9c460d; }
.leaves-year-calendar .calendar-day-holiday.calendar-range .date-item { background: rgba(253, 126, 20, 0.5); color: #7c2d0a; }
.leaves-year-calendar .calendar-day-holiday.calendar-range.range-start .date-item,
.leaves-year-calendar .calendar-day-holiday.calendar-range.range-end .date-item { background: rgba(253, 126, 20, 0.85); color: #fff; }

/* Sickness im Jahr-Kalender: nach Tabler laden, damit sie .calendar-range überschreiben */
.leaves-year-calendar .calendar-day-weekend.calendar-range-sickness .date-item { background: transparent; color: <?= LEAVE_SICKNESS_FG ?>; }
.leaves-year-calendar .calendar-day-weekend.calendar-range-other-sickness .date-item { background: <?= LEAVE_SICKNESS_BG ?>; color: <?= LEAVE_SICKNESS_FG ?>; }
.leaves-year-calendar .calendar-range-sickness .date-item { background: rgba(214, 51, 108, 0.5); color: #fff; font-weight: 600; }
.leaves-year-calendar .calendar-range-sickness.range-start .date-item,
.leaves-year-calendar .calendar-range-sickness.range-end .date-item { background: <?= LEAVE_SICKNESS_SOLID ?>; color: #fff; }
.leaves-year-calendar .calendar-range-other-sickness .date-item { background: <?= LEAVE_SICKNESS_BG ?>; color: <?= LEAVE_SICKNESS_FG ?>; font-weight: 600; }

/* Andere Urlaube (nicht ausgewählt) */
.leaves-year-calendar .calendar-range-other .date-item { background: rgba(var(--tblr-primary-rgb), 0.12); color: var(--tblr-primary); font-weight: 600; }

/* Start/Ende ausgewählter Urlaub (nur für Nicht-Sickness; Sickness siehe oben) */
.leaves-year-calendar .calendar-range:not(.calendar-range-sickness).range-start .date-item,
.leaves-year-calendar .calendar-range:not(.calendar-range-sickness).range-end .date-item {
    color: #ffffff !important;
    font-weight: 700;
}

/* Heutiger Tag: Deutlichere Umrandung */
.leaves-year-calendar .date-today { border: 1px solid #1e293b; font-weight: bold; }
</style>
<?php require_once 'includes/footer.php'; ?>