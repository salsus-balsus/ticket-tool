<?php
/**
 * Admin: List tickets with inconsistent status.
 * "Consistent" = status appears in workflow_transitions (current or next).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$tickets = [];
$maintainedStatusIds = [];
$dbError = null;

try {
    $maintainedStatusIds = $pdo->query("
        SELECT DISTINCT id FROM (
            SELECT current_status_id AS id FROM workflow_transitions
            UNION
            SELECT next_status_id AS id FROM workflow_transitions
        ) AS u
    ")->fetchAll(PDO::FETCH_COLUMN);
    $maintainedStatusIds = array_map('intval', $maintainedStatusIds);

    if (!empty($maintainedStatusIds)) {
        $placeholders = implode(',', array_fill(0, count($maintainedStatusIds), '?'));
        $stmt = $pdo->prepare("
            SELECT t.id, t.ticket_no, t.title, t.status_id, t.created_by,
                   ts.name AS status_name, ts.color_code AS status_color,
                   tt.code AS type_code, o.name AS object_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
            LEFT JOIN ticket_types tt ON t.type_id = tt.id
            LEFT JOIN (SELECT ticket_id, MIN(object_id) AS object_id FROM ticket_objects GROUP BY ticket_id) to1 ON to1.ticket_id = t.id
            LEFT JOIN objects o ON o.id = to1.object_id
            WHERE t.status_id NOT IN ($placeholders)
            ORDER BY t.status_id, t.id
        ");
        $stmt->execute(array_values($maintainedStatusIds));
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $tickets = $pdo->query("
            SELECT t.id, t.ticket_no, t.title, t.status_id, t.created_by,
                   ts.name AS status_name, ts.color_code AS status_color,
                   tt.code AS type_code, o.name AS object_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
            LEFT JOIN ticket_types tt ON t.type_id = tt.id
            LEFT JOIN (SELECT ticket_id, MIN(object_id) AS object_id FROM ticket_objects GROUP BY ticket_id) to1 ON to1.ticket_id = t.id
            LEFT JOIN objects o ON o.id = to1.object_id
            ORDER BY t.status_id, t.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Tickets with inconsistent status – Admin';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Tickets with inconsistent status</h1>
                        <div class="text-muted">Status does not appear in workflow (transitions) – not mappable.</div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <?php if (empty($tickets)): ?>
                            <p class="text-success mb-0">All tickets have a status that is maintained in the workflow.</p>
                            <?php else: ?>
                            <p class="text-muted mb-3"><?= count($tickets) ?> ticket(s) with status outside the workflow map.</p>
                            <div class="table-responsive">
                                <table class="table table-vcenter table-sm">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Ticket</th>
                                            <th>Status</th>
                                            <th>Type</th>
                                            <th>Object</th>
                                            <th>Reporter</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tickets as $t): ?>
                                        <tr>
                                            <td><?= (int) $t['id'] ?></td>
                                            <td><a href="ticket_detail.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a> <span class="text-muted">#<?= (int)($t['ticket_no'] ?? $t['id']) ?></span></td>
                                            <td><?= render_status_badge($t['status_name'], $t['status_color']) ?> <span class="text-muted">(ID <?= (int)$t['status_id'] ?>)</span></td>
                                            <td><span class="badge bg-secondary-lt"><?= e($t['type_code'] ?? '-') ?></span></td>
                                            <td><?= e($t['object_name'] ?? '-') ?></td>
                                            <td><?= e($t['created_by'] ?? '-') ?></td>
                                            <td><a href="ticket_detail.php?id=<?= (int) $t['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
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
