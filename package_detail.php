<?php
require 'includes/config.php';
require 'includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: work_packages.php'); exit; }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_tickets'])) {
        $ids = array_filter(array_map('intval', explode(',', $_POST['ticket_ids'] ?? '')));
        $stmt = $pdo->prepare("INSERT IGNORE INTO work_package_items_tickets (package_id, ticket_id) VALUES (?, ?)");
        foreach ($ids as $tid) { if ($tid > 0) $stmt->execute([$id, $tid]); }
    }
    if (isset($_POST['add_ticket_select']) && !empty($_POST['ticket_id'])) {
        $tid = (int) $_POST['ticket_id'];
        if ($tid > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO work_package_items_tickets (package_id, ticket_id) VALUES (?, ?)");
            $stmt->execute([$id, $tid]);
        }
    }
    if (isset($_POST['add_tests'])) {
        $ids = array_filter(array_map('intval', explode(',', $_POST['execution_ids'] ?? '')));
        $stmt = $pdo->prepare("INSERT IGNORE INTO work_package_items_tests (package_id, execution_id) VALUES (?, ?)");
        foreach ($ids as $eid) { if ($eid > 0) $stmt->execute([$id, $eid]); }
    }
    if (isset($_POST['add_test_select']) && !empty($_POST['execution_id'])) {
        $eid = (int) $_POST['execution_id'];
        if ($eid > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO work_package_items_tests (package_id, execution_id) VALUES (?, ?)");
            $stmt->execute([$id, $eid]);
        }
    }
    if (isset($_POST['remove_ticket'])) {
        $tid = (int) $_POST['remove_ticket'];
        $pdo->prepare("DELETE FROM work_package_items_tickets WHERE package_id = ? AND ticket_id = ?")->execute([$id, $tid]);
    }
    if (isset($_POST['remove_test'])) {
        $eid = (int) $_POST['remove_test'];
        $pdo->prepare("DELETE FROM work_package_items_tests WHERE package_id = ? AND execution_id = ?")->execute([$id, $eid]);
    }
    if (isset($_POST['toggle_status'])) {
        $newStatus = ($_POST['current_status'] ?? '') === 'Open' ? 'Closed' : 'Open';
        $pdo->prepare("UPDATE work_packages SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
    }
    header("Location: package_detail.php?id=$id");
    exit;
}

// Fetch Package
$stmt = $pdo->prepare("SELECT * FROM work_packages WHERE id = ?");
$stmt->execute([$id]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pkg) { header('Location: work_packages.php'); exit; }

// Linked Tickets
$stmt = $pdo->prepare("SELECT t.*, ts.name as status_name, ts.color_code, ts.is_terminal FROM work_package_items_tickets wit JOIN tickets t ON wit.ticket_id = t.id LEFT JOIN ticket_statuses ts ON t.status_id = ts.id WHERE wit.package_id = ?");
$stmt->execute([$id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Linked Tests
$tests = $pdo->prepare("SELECT te.*, te.test_case_id, tc.title as case_title FROM work_package_items_tests wie JOIN test_executions te ON wie.execution_id = te.id JOIN test_cases tc ON te.test_case_id = tc.id WHERE wie.package_id = ?");
$tests->execute([$id]);
$tests = $tests->fetchAll(PDO::FETCH_ASSOC);

// Summary: total, completed, progress
$totalItems = count($tickets) + count($tests);
$completedTickets = count(array_filter($tickets, fn($t) => (int)($t['is_terminal'] ?? 0) === 1));
$completedTests = count(array_filter($tests, fn($t) => ($t['overall_status'] ?? '') === 'Pass'));
$completedItems = $completedTickets + $completedTests;
$progressPercent = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// Available tickets not yet in package (for Quick Add dropdown)
$availTickets = $pdo->prepare("SELECT t.id, t.title FROM tickets t WHERE t.id NOT IN (SELECT ticket_id FROM work_package_items_tickets WHERE package_id = ?) ORDER BY t.id DESC LIMIT 100");
$availTickets->execute([$id]);
$availTickets = $availTickets->fetchAll(PDO::FETCH_ASSOC);

// Available test executions not yet in package
$availTests = $pdo->prepare("SELECT te.id, te.overall_status, tc.title as case_title FROM test_executions te JOIN test_cases tc ON te.test_case_id = tc.id WHERE te.id NOT IN (SELECT execution_id FROM work_package_items_tests WHERE package_id = ?) ORDER BY te.id DESC LIMIT 100");
$availTests->execute([$id]);
$availTests = $availTests->fetchAll(PDO::FETCH_ASSOC);
?>

<?php $pageTitle = 'Work Package #' . $id; require 'includes/header.php'; ?>
<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col">
                    <div class="page-pretitle">Work Package #<?= $id ?></div>
                    <h2 class="page-title"><?= htmlspecialchars($pkg['title']) ?></h2>
                    <div class="text-muted"><?= htmlspecialchars($pkg['assigned_to'] ?? 'Unassigned') ?></div>
                </div>
                <div class="col-auto ms-auto">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="current_status" value="<?= htmlspecialchars($pkg['status']) ?>">
                        <button type="submit" name="toggle_status" class="btn btn-<?= $pkg['status'] === 'Open' ? 'danger' : 'success' ?>">
                            <?= $pkg['status'] === 'Open' ? 'Close Package' : 'Reopen Package' ?>
                        </button>
                    </form>
                    <a href="work_packages.php" class="btn btn-outline-secondary">Back to List</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div class="page-body">
        <div class="container-xl mb-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">Package Summary</h4>
                    <div class="d-flex align-items-center mb-2">
                        <div>Progress</div>
                        <div class="ms-auto"><strong><?= $completedItems ?></strong> / <?= $totalItems ?> items completed</div>
                    </div>
                    <div class="progress progress-sm">
                        <div class="progress-bar bg-primary" style="width: <?= $progressPercent ?>%" role="progressbar"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Included Tickets</h3>
                        </div>
                        <div class="card-body border-bottom py-3">
                            <form method="POST" class="d-flex gap-2 mb-2">
                                <input type="text" name="ticket_ids" class="form-control form-control-sm" placeholder="Add IDs (e.g. 102, 105)">
                                <button type="submit" name="add_tickets" class="btn btn-sm btn-primary">Add IDs</button>
                            </form>
                            <?php if (!empty($availTickets)): ?>
                            <form method="POST" class="d-flex gap-2">
                                <select name="ticket_id" class="form-select form-select-sm" style="min-width:200px;">
                                    <option value="">Select ticket to add...</option>
                                    <?php foreach ($availTickets as $at): ?>
                                    <option value="<?= (int)$at['id'] ?>">#<?= $at['id'] ?> <?= htmlspecialchars(mb_substr($at['title'], 0, 40)) ?><?= mb_strlen($at['title']) > 40 ? '…' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="add_ticket_select" class="btn btn-sm btn-outline-primary">Add Selected</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table card-table table-vcenter text-nowrap">
                                <thead><tr><th>ID</th><th>Title</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($tickets as $t): ?>
                                    <tr>
                                        <td><a href="ticket_detail.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?></a></td>
                                        <td><?= htmlspecialchars(mb_substr($t['title'], 0, 40)) ?><?= mb_strlen($t['title']) > 40 ? '…' : '' ?></td>
                                        <td><?= render_status_badge($t['status_name'] ?? '-', $t['color_code'] ?? null) ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove from package?');">
                                                <input type="hidden" name="remove_ticket" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-icon btn-ghost-danger">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Included Test Executions</h3>
                        </div>
                        <div class="card-body border-bottom py-3">
                            <form method="POST" class="d-flex gap-2 mb-2">
                                <input type="text" name="execution_ids" class="form-control form-control-sm" placeholder="Add Exec IDs (e.g. 1, 2, 3)">
                                <button type="submit" name="add_tests" class="btn btn-sm btn-primary">Add IDs</button>
                            </form>
                            <?php if (!empty($availTests)): ?>
                            <form method="POST" class="d-flex gap-2">
                                <select name="execution_id" class="form-select form-select-sm" style="min-width:220px;">
                                    <option value="">Select test execution...</option>
                                    <?php foreach ($availTests as $at): ?>
                                    <option value="<?= (int)$at['id'] ?>">Exec #<?= $at['id'] ?> - <?= htmlspecialchars(mb_substr($at['case_title'] ?? '', 0, 35)) ?><?= mb_strlen($at['case_title'] ?? '') > 35 ? '…' : '' ?> (<?= $at['overall_status'] ?? '-' ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="add_test_select" class="btn btn-sm btn-outline-primary">Add Selected</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table card-table table-vcenter text-nowrap">
                                <thead><tr><th>Exec ID</th><th>Case</th><th>Result</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($tests as $te): ?>
                                    <tr>
                                        <td><a href="test_case_detail.php?id=<?= (int)($te['test_case_id'] ?? 0) ?>">#<?= $te['id'] ?></a></td>
                                        <td><?= htmlspecialchars(mb_substr($te['case_title'] ?? '', 0, 40)) ?><?= mb_strlen($te['case_title'] ?? '') > 40 ? '…' : '' ?></td>
                                        <td><?= render_status_badge($te['overall_status'] ?? '-', ($te['overall_status'] ?? '') === 'Pass' ? 'success' : (($te['overall_status'] ?? '') === 'Fail' ? 'danger' : 'warning')) ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove from package?');">
                                                <input type="hidden" name="remove_test" value="<?= $te['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-icon btn-ghost-danger">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>