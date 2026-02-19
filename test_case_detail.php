<?php
/**
 * test_case_detail.php - Test Case Detail (Execution & Replication Details)
 * Header: Object Name, Test Task. Steps. Execution History. System-Specific Results (replication_type, error_details).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: testplan.php');
    exit;
}

$testCase = null;
$steps = [];
$executions = [];
$latestSystemResults = [];
$dbError = null;

try {
    $stmt = $pdo->prepare("
        SELECT tc.id, tc.title, tc.test_task, tc.object_id,
               o.name AS object_name,
               s.name AS sector_name
        FROM test_cases tc
        LEFT JOIN objects o ON tc.object_id = o.id
        LEFT JOIN sectors s ON o.sector_id = s.id
        WHERE tc.id = ?
    ");
    $stmt->execute([$id]);
    $testCase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$testCase) {
        header('Location: testplan.php');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT step_description, expected_result, step_order
        FROM test_case_steps
        WHERE test_case_id = ?
        ORDER BY step_order ASC, id ASC
    ");
    $stmt->execute([$id]);
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, result, executed_at
        FROM test_executions
        WHERE test_case_id = ?
        ORDER BY executed_at DESC
    ");
    $stmt->execute([$id]);
    $executions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest execution: system-specific results from test_run_systems
    $latestExecId = $executions[0]['id'] ?? null;
    if ($latestExecId) {
        $stmt = $pdo->prepare("
            SELECT system, status, replication_type, error_details
            FROM test_run_systems
            WHERE test_execution_id = ?
            ORDER BY system
        ");
        $stmt->execute([$latestExecId]);
        $latestSystemResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function exec_badge($result) {
    $r = strtolower(trim($result ?? ''));
    if ($r === 'pass' || $r === 'passed') return 'bg-success-lt';
    if ($r === 'fail' || $r === 'failed') return 'bg-danger-lt';
    return 'bg-secondary-lt';
}
$pageTitle = 'Test Case #' . $id;
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title"><?= e($testCase['title']) ?></h1>
                        <div class="text-muted"><?= e($testCase['object_name'] ?? '-') ?> · <?= e($testCase['test_task'] ?? '-') ?></div>
                    </div>

                    <!-- Test Steps -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Test Steps</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($steps)): ?>
                            <p class="text-muted mb-0">No steps defined.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter">
                                    <thead>
                                        <tr><th>Step</th><th>Expected Result</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($steps as $i => $st): ?>
                                        <tr>
                                            <td><?= e($st['step_description'] ?? '-') ?></td>
                                            <td><?= e($st['expected_result'] ?? '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Execution History -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Execution History</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($executions)): ?>
                            <p class="text-muted p-3 mb-0">No executions yet.</p>
                            <?php else: ?>
                            <table class="table table-vcenter table-striped">
                                <thead>
                                    <tr><th>Date</th><th>Result</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($executions as $ex): ?>
                                    <tr>
                                        <td><?= format_datetime($ex['executed_at']) ?></td>
                                        <td><span class="badge <?= exec_badge($ex['result']) ?>"><?= e($ex['result'] ?? '-') ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- System-Specific Results (Latest Execution) -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">System-Specific Results (Latest Execution)</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($latestSystemResults)): ?>
                            <p class="text-muted mb-0">No system details for the latest execution.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter">
                                    <thead>
                                        <tr>
                                            <th>System</th>
                                            <th>Replication Type</th>
                                            <th>Status</th>
                                            <th>Error Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($latestSystemResults as $sr): ?>
                                        <tr>
                                            <td><strong><?= e($sr['system'] ?? $sr['system_name'] ?? '-') ?></strong></td>
                                            <td><span class="badge bg-blue-lt"><?= e($sr['replication_type'] ?? '-') ?></span></td>
                                            <td><span class="badge <?= ($sr['status'] ?? '') === 'Error' ? 'bg-danger-lt' : exec_badge($sr['status']) ?>"><?= e($sr['status'] ?? '-') ?></span></td>
                                            <td><?= ($sr['status'] ?? '') === 'Error' && !empty($sr['error_details']) ? nl2br(e($sr['error_details'])) : '-' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
