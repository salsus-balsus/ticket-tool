<?php
/**
 * admin_leaves.php - Admin view of all user leaves.
 * Filter by person, year calendar showing all leaves with selected one highlighted.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$leaves = [];
$users = [];
$filterUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$dbError = null;

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

try {
    $users = $pdo->query("
        SELECT u.id, u.first_name, u.last_name, u.username
        FROM app_users u
        ORDER BY COALESCE(u.first_name, u.username), u.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sql = "
        SELECT ul.id, ul.user_id, ul.start_date, ul.end_date, ul.comment, ul.is_sickness,
               u.first_name, u.last_name, u.username
        FROM user_leaves ul
        JOIN app_users u ON u.id = ul.user_id
    ";
    $params = [];
    if ($filterUserId > 0) {
        $sql .= " WHERE ul.user_id = ?";
        $params[] = $filterUserId;
    }
    $sql .= " ORDER BY ul.start_date DESC, ul.end_date DESC";

    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $leaves = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
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

$pageTitle = 'Leaves (Admin)';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <div class="row g-2 align-items-center">
                            <div class="col">
                                <h1 class="page-title">Leaves (Admin)</h1>
                                <div class="text-muted">View all user absences</div>
                            </div>
                            <div class="col-auto ms-auto d-flex gap-2">
                                <?php if (file_exists(__DIR__ . '/data/vacation.csv')): ?>
                                <a href="import_leaves.php" class="btn btn-outline-primary">Import from vacation.csv</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <h3 class="card-title mb-0">Leaves</h3>
                            <?php if (!empty($users)): ?>
                            <form method="get" class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0 small">Filter by person:</label>
                                <select name="user_id" class="form-select form-select-sm" style="width: auto; min-width: 180px;" onchange="this.form.submit()">
                                    <option value="0" <?= $filterUserId === 0 ? 'selected' : '' ?>>All</option>
                                    <?php foreach ($users as $u):
                                        $label = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? '-');
                                    ?>
                                    <option value="<?= (int) $u['id'] ?>" <?= $filterUserId === (int) $u['id'] ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($leaves)): ?>
                            <div class="empty">
                                <p class="empty-title">No leaves</p>
                                <p class="empty-subtitle"><?= $filterUserId > 0 ? 'No leaves for this person.' : 'Import from <code>data/vacation.csv</code> via <a href="import_leaves.php">import_leaves.php</a>' ?></p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter table-sm table-hover" id="leaves-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 2.5rem;"></th>
                                            <th>Person</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($leaves as $i => $l):
                                            $name = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: ($l['username'] ?? '-');
                                            $rowClass = !empty($l['is_sickness']) ? ' leave-row-sickness' : '';
                                        ?>
                                        <tr class="leave-row<?= $rowClass ?>" data-id="<?= (int) $l['id'] ?>" data-start="<?= e($l['start_date']) ?>" data-end="<?= e($l['end_date']) ?>">
                                            <td>
                                                <input type="radio" name="leave_select" value="<?= (int) $l['id'] ?>" class="form-check-input leave-radio" <?= $i === 0 ? 'checked' : '' ?>>
                                            </td>
                                            <td><?= e($name) ?></td>
                                            <td><?= e(date('d.m.Y', strtotime($l['start_date']))) ?></td>
                                            <td><?= e(date('d.m.Y', strtotime($l['end_date']))) ?></td>
                                            <td class="text-muted"><?= !empty($l['is_sickness']) ? '<span class="badge badge-sickness" title="Sickness">S</span> ' : '' ?><?= e(mb_substr($l['comment'] ?? '', 0, 50)) ?><?= mb_strlen($l['comment'] ?? '') > 50 ? '…' : '' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
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
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('leave-calendar');
    if (!container) return;

    var leavesData = <?= json_encode(array_map(function($l) {
        return ['id' => (int)$l['id'], 'start_date' => $l['start_date'], 'end_date' => $l['end_date']];
    }, $leaves)) ?>;
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
                var inSelected = false;
                var inOther = false;
                var isSelectedStart = false, isSelectedEnd = false;
                for (var j = 0; j < leavesData.length; j++) {
                    var L = leavesData[j];
                    var s = parseDate(L.start_date);
                    var e = parseDate(L.end_date);
                    if (isInRange(date, s, e)) {
                        if (L.id === selectedId) {
                            inSelected = true;
                            isSelectedStart = (date.getTime() === s.getTime());
                            isSelectedEnd = (date.getTime() === e.getTime());
                        } else {
                            inOther = true;
                        }
                    }
                }
                if (inSelected) {
                    cls += ' calendar-range';
                    if (isSelectedStart) cls += ' range-start';
                    if (isSelectedEnd) cls += ' range-end';
                } else if (inOther) {
                    cls += ' calendar-range-other';
                }
                var today = new Date();
                var isToday = date.getFullYear() === today.getFullYear() && date.getMonth() === today.getMonth() && date.getDate() === today.getDate();
                html += '<div class="' + cls + '">';
                html += '<span class="date-item' + (isToday ? ' date-today' : '') + '">' + d + '</span></div>';
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
.leaves-year-calendar .calendar { font-size: 0.7rem; }
.leaves-year-calendar .calendar-date .date-item { width: 1.2rem; height: 1.2rem; line-height: 1.2rem; font-size: 0.7rem; }
.leaves-year-calendar .calendar-range-other .date-item { background: rgba(var(--tblr-primary-rgb), 0.12); color: var(--tblr-primary); }
.badge-sickness { background: rgba(214, 51, 108, 0.2); color: #c92a6a; }
.leave-row-sickness { background: rgba(214, 51, 108, 0.06); }
</style>
<?php require_once 'includes/footer.php'; ?>
