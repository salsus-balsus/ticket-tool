<?php
/**
 * testplan.php - QA Overview Dashboard
 * Filters: Sector, Object, Test Cycle. Table: Object Context, Test Case Title, Last Execution Status, System Status (ID1, IH1, IH2).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$filterSector = isset($_GET['sector']) ? (int) $_GET['sector'] : null;
$filterObject = isset($_GET['object']) ? (int) $_GET['object'] : null;
$filterCycle  = isset($_GET['cycle']) ? (int) $_GET['cycle'] : null;

$sectors = [];
$objects = [];
$cycles = [];
$testCases = [];
$dbError = null;

try {
    $sectors = $pdo->query("SELECT id, name FROM sectors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $objects = $pdo->query("SELECT id, name, sector_id FROM objects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    try {
        $cycles = $pdo->query("SELECT id, name FROM test_cycles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cycles = [];
    }

    // Main query: test cases with object context and latest execution (via subquery for MySQL 5.7 compat)
    $sql = "
        SELECT
            tc.id, tc.title, tc.test_task, tc.object_id, tc.test_cycle_id,
            o.name AS object_name,
            s.name AS sector_name,
            latest.result AS last_result,
            latest.executed_at AS last_executed_at
        FROM test_cases tc
        LEFT JOIN objects o ON tc.object_id = o.id
        LEFT JOIN sectors s ON o.sector_id = s.id
        LEFT JOIN (
            SELECT te1.test_case_id, te1.result, te1.executed_at
            FROM test_executions te1
            INNER JOIN (SELECT test_case_id, MAX(executed_at) AS max_at FROM test_executions GROUP BY test_case_id) mx
              ON te1.test_case_id = mx.test_case_id AND te1.executed_at = mx.max_at
        ) latest ON latest.test_case_id = tc.id
        WHERE 1=1
    ";
    $params = [];
    if ($filterSector > 0) { $sql .= " AND o.sector_id = ?"; $params[] = $filterSector; }
    if ($filterObject > 0) { $sql .= " AND tc.object_id = ?"; $params[] = $filterObject; }
    if ($filterCycle > 0)  { $sql .= " AND tc.test_cycle_id = ?"; $params[] = $filterCycle; }
    $sql .= " ORDER BY s.name, o.name, tc.title LIMIT 100";

    $stmt = empty($params) ? $pdo->query($sql) : ($pdo->prepare($sql) && $pdo->prepare($sql)->execute($params) ? $pdo->prepare($sql) : $pdo->query($sql));
    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }
    $testCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch system status (ID1, IH1, IH2) for each test case from latest execution
    $systemStatuses = [];
    foreach ($testCases as $tc) {
        $tcId = $tc['id'];
        $stmt = $pdo->prepare("
            SELECT trs.system, trs.status, trs.replication_type
            FROM test_run_systems trs
            JOIN test_executions te ON trs.test_execution_id = te.id
            WHERE te.test_case_id = ?
            ORDER BY te.executed_at DESC, trs.system
            LIMIT 6
        ");
        $stmt->execute([$tcId]);
        $systemStatuses[$tcId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function exec_result_badge($result) {
    $r = strtolower(trim($result ?? ''));
    if ($r === 'pass' || $r === 'passed') return '<span class="badge bg-success-lt">Pass</span>';
    if ($r === 'fail' || $r === 'failed') return '<span class="badge bg-danger-lt">Fail</span>';
    return '<span class="badge bg-secondary-lt">Open</span>';
}

function system_badge($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'pass' || $s === 'passed') return 'bg-success';
    if ($s === 'fail' || $s === 'failed' || $s === 'error') return 'bg-danger';
    return 'bg-secondary';
}
$pageTitle = 'Test Management';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Test Management</h1>
                        <div class="text-muted">QA Overview – filter and view test execution status</div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="get" action="testplan.php" class="row g-3 align-items-end">
                                <div class="col-auto">
                                    <label class="form-label">Sector</label>
                                    <select name="sector" class="form-select form-select-sm" style="min-width:160px;">
                                        <option value="">All sectors</option>
                                        <?php foreach ($sectors as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= $filterSector === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">Object</label>
                                    <select name="object" class="form-select form-select-sm" style="min-width:180px;">
                                        <option value="">All objects</option>
                                        <?php foreach ($objects as $o): ?>
                                        <option value="<?= (int) $o['id'] ?>" <?= $filterObject === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">Test Cycle</label>
                                    <select name="cycle" class="form-select form-select-sm" style="min-width:160px;">
                                        <option value="">All cycles</option>
                                        <?php foreach ($cycles as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= $filterCycle === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Apply</button>
                                    <a href="testplan.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Main Table -->
                    <div class="card">
                        <?php if (empty($testCases)): ?>
                        <div class="empty">
                            <div class="empty-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="128" height="128" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /></svg>
                            </div>
                            <p class="empty-title">No test cases found</p>
                            <p class="empty-subtitle">Try adjusting your filters.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table table-striped">
                                <thead>
                                    <tr>
                                        <th>Object Context</th>
                                        <th>Test Case Title</th>
                                        <th>Last Execution</th>
                                        <th>System Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testCases as $tc): ?>
                                    <?php
                                    $ctx = trim(($tc['sector_name'] ?? '') . ' / ' . ($tc['object_name'] ?? ''), ' /') ?: '-';
                                    $systems = $systemStatuses[$tc['id']] ?? [];
                                    $bySys = [];
                                    foreach ($systems as $ss) {
                                        $bySys[$ss['system'] ?? $ss['system_name'] ?? ''] = $ss;
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-muted"><?= e($ctx) ?></td>
                                        <td>
                                            <a href="test_case_detail.php?id=<?= (int) $tc['id'] ?>" class="text-reset fw-bold"><?= e($tc['title']) ?></a>
                                        </td>
                                        <td><?= exec_result_badge($tc['last_result']) ?> <?= $tc['last_executed_at'] ? format_date($tc['last_executed_at']) : '' ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php foreach (['ID1', 'IH1', 'IH2'] as $sys): ?>
                                                <?php $ent = $bySys[$sys] ?? null; ?>
                                                <span class="badge <?= system_badge($ent['status'] ?? null) ?>" title="<?= e($ent['replication_type'] ?? '') ?>"><?= e($sys) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            <p class="m-0 text-muted">Showing <?= count($testCases) ?> test cases</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
