<?php
/**
 * object_view.php - Object 360° View
 * Shows open tickets and latest test results for a specific object.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: tickets.php');
    exit;
}

$object = null;
$openTickets = [];
$latestTestResults = [];
$dbError = null;

try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.name, s.name AS sector_name
        FROM objects o
        LEFT JOIN sectors s ON o.sector_id = s.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $object = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$object) {
        header('Location: tickets.php');
        exit;
    }

    // Open tickets for this object (non-terminal status)
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.status_id, ts.name AS status_name, ts.color_code AS status_color
        FROM tickets t
        LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE t.object_id = ? AND (ts.is_terminal = 0 OR ts.is_terminal IS NULL)
        ORDER BY t.id DESC
        LIMIT 20
    ");
    $stmt->execute([$id]);
    $openTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest test results per test case for this object
    $stmt = $pdo->prepare("
        SELECT tc.id AS test_case_id, tc.title AS test_case_title,
               te.result, te.executed_at
        FROM test_cases tc
        LEFT JOIN (
            SELECT te1.test_case_id, te1.result, te1.executed_at
            FROM test_executions te1
            INNER JOIN (SELECT test_case_id, MAX(executed_at) AS max_at FROM test_executions GROUP BY test_case_id) mx
              ON te1.test_case_id = mx.test_case_id AND te1.executed_at = mx.max_at
        ) te ON te.test_case_id = tc.id
        WHERE tc.object_id = ?
        ORDER BY te.executed_at DESC, tc.title
        LIMIT 20
    ");
    $stmt->execute([$id]);
    $latestTestResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function exec_badge($result) {
    $r = strtolower(trim($result ?? ''));
    if ($r === 'pass' || $r === 'passed') return 'bg-success-lt';
    if ($r === 'fail' || $r === 'failed') return 'bg-danger-lt';
    return 'bg-secondary-lt';
}
$pageTitle = ($object['name'] ?? 'Object') . ' - 360° View';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title"><?= e($object['name']) ?></h1>
                        <div class="text-muted"><?= e($object['sector_name'] ?? '-') ?> · 360° View</div>
                        <div class="ms-auto">
                            <a href="tickets.php?object_id=<?= $id ?>" class="btn btn-primary">View Tickets</a>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Open Tickets -->
                        <div class="col-lg-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h3 class="card-title">Open Tickets</h3>
                                    <a href="tickets.php?object_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">View all</a>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($openTickets)): ?>
                                    <p class="text-muted p-3 mb-0">No open tickets for this object.</p>
                                    <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($openTickets as $t): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <a href="ticket_detail.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a>
                                            <?= render_status_badge($t['status_name'] ?? '-', $t['status_color'] ?? null) ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Latest Test Results -->
                        <div class="col-lg-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h3 class="card-title">Latest Test Results</h3>
                                    <a href="testplan.php?object=<?= $id ?>" class="btn btn-sm btn-outline-primary">View all</a>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($latestTestResults)): ?>
                                    <p class="text-muted p-3 mb-0">No test executions for this object.</p>
                                    <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($latestTestResults as $tr): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <a href="test_case_detail.php?id=<?= (int) $tr['test_case_id'] ?>"><?= e($tr['test_case_title'] ?? 'Test #' . $tr['test_case_id']) ?></a>
                                            <span><span class="badge <?= exec_badge($tr['result']) ?>"><?= e($tr['result'] ?? 'Open') ?></span> <?= format_date($tr['executed_at']) ?></span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
