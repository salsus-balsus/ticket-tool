<?php
/**
 * customer_scope.php - Business Matrix ("Who uses What")
 * Grouped by Customer. Objects with phase (PoC, Implementation, Live) and is_active.
 * Link: View Tickets for this Object -> tickets.php?object_id=X
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$customers = [];
$dbError = null;
$sortTickets = isset($_GET['sort']) && $_GET['sort'] === 'tickets';
$sortOrder = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'asc' : 'desc';

try {
    $stmt = $pdo->query("
        SELECT c.id AS customer_id, c.name AS customer_name,
               cs.object_id, cs.phase, cs.is_active,
               o.name AS object_name,
               s.name AS sector_name,
               (SELECT COUNT(*) FROM tickets t
                WHERE EXISTS (SELECT 1 FROM ticket_objects to2 WHERE to2.ticket_id = t.id AND to2.object_id = cs.object_id)
                AND EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.role = 'REP' AND tp.person_name LIKE CONCAT('%', c.name, '%'))) AS ticket_count
        FROM customers c
        LEFT JOIN customer_scopes cs ON c.id = cs.customer_id
        LEFT JOIN objects o ON cs.object_id = o.id
        LEFT JOIN sectors s ON o.sector_id = s.id
        WHERE cs.object_id IS NOT NULL
        ORDER BY c.name, o.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by customer, then by sector (sector as grouping, no redundant sector per row)
    foreach ($rows as $r) {
        $cid = $r['customer_id'];
        $sectorName = $r['sector_name'] ?? '—';
        if (!isset($customers[$cid])) {
            $customers[$cid] = [
                'name' => $r['customer_name'],
                'sectors' => []
            ];
        }
        if (!isset($customers[$cid]['sectors'][$sectorName])) {
            $customers[$cid]['sectors'][$sectorName] = [];
        }
        $customers[$cid]['sectors'][$sectorName][] = [
            'object_id' => $r['object_id'],
            'object_name' => $r['object_name'],
            'phase' => $r['phase'],
            'is_active' => $r['is_active'],
            'ticket_count' => (int) ($r['ticket_count'] ?? 0)
        ];
    }

    foreach ($customers as &$c) {
        ksort($c['sectors'], SORT_NATURAL);
    }
    unset($c);

    if ($sortTickets) {
        foreach ($customers as &$cust) {
            foreach ($cust['sectors'] as &$objs) {
                usort($objs, function ($a, $b) use ($sortOrder) {
                    $diff = ($a['ticket_count'] ?? 0) - ($b['ticket_count'] ?? 0);
                    return $sortOrder === 'asc' ? $diff : -$diff;
                });
            }
            unset($objs);
        }
        unset($cust);
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Customer Scope';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Customer Scope</h1>
                        <div class="text-muted">Who uses What – Business Matrix</div>
                    </div>

                    <div class="card">
                        <?php if (empty($customers)): ?>
                        <div class="empty">
                            <p class="empty-title">No customer scope data</p>
                            <p class="empty-subtitle">Add customers and objects to customer_scopes.</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php
                            $nextOrder = ($sortTickets && $sortOrder === 'desc') ? 'asc' : 'desc';
                            $sortUrl = 'customer_scope.php?sort=tickets&order=' . $nextOrder;
                            $sortArrow = $sortTickets ? ($sortOrder === 'desc' ? ' ↓' : ' ↑') : '';
                            ?>
                            <?php foreach ($customers as $custId => $cust): ?>
                            <div class="list-group-item" id="customer-<?= (int) $custId ?>">
                                <h3 class="mb-3"><?= e($cust['name']) ?></h3>
                                <?php foreach ($cust['sectors'] as $sectorName => $objects): ?>
                                <div class="mb-4">
                                    <h4 class="h5 text-muted mb-2"><?= e($sectorName) ?></h4>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-vcenter table-customer-scope">
                                            <colgroup>
                                                <col class="col-object">
                                                <col class="col-phase">
                                                <col class="col-active">
                                                <col class="col-tickets">
                                                <col class="col-actions">
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th>Object</th>
                                                    <th>Phase</th>
                                                    <th>Active</th>
                                                    <th><a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">Total tickets<?= $sortArrow ?></a></th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($objects as $obj): ?>
                                                <tr>
                                                    <td><?= e($obj['object_name'] ?? '-') ?></td>
                                                    <td><span class="badge bg-secondary-lt"><?= e($obj['phase'] ?? '-') ?></span></td>
                                                    <td><?= !empty($obj['is_active']) ? '<span class="badge bg-success-lt">Yes</span>' : '<span class="badge bg-secondary-lt">No</span>' ?></td>
                                                    <td><span class="badge bg-blue-lt"><?= (int) ($obj['ticket_count'] ?? 0) ?></span></td>
                                                    <td>
                                                        <a href="tickets.php?object_id=<?= (int) $obj['object_id'] ?>&reporter=<?= urlencode($cust['name']) ?>" class="btn btn-sm btn-outline-primary">View Tickets</a>
                                                        <a href="testplan.php?object=<?= (int) $obj['object_id'] ?>" class="btn btn-sm btn-outline-secondary">View Test Results</a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
<style>
/* Einheitliche Tab-Stops: gleiche Spaltenbreiten in allen Kunden/Sektor-Tabellen */
.table-customer-scope {
    table-layout: fixed;
    width: 100%;
}
.table-customer-scope .col-object { width: 35%; min-width: 140px; }
.table-customer-scope .col-phase  { width: 14%; min-width: 100px; }
.table-customer-scope .col-active { width: 8%;  min-width: 72px;  }
.table-customer-scope .col-tickets{ width: 10%; min-width: 88px;  }
.table-customer-scope .col-actions{ width: 260px; }
.table-customer-scope td:nth-child(1),
.table-customer-scope th:nth-child(1) { overflow: hidden; text-overflow: ellipsis; }
.table-customer-scope td:nth-child(2),
.table-customer-scope td:nth-child(3),
.table-customer-scope td:nth-child(4),
.table-customer-scope td:nth-child(5) { white-space: nowrap; }
</style>
</body>
</html>
