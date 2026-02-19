<?php
/**
 * index.php - Dashboard
 * Tabler.io layout, real DB queries.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$my_role_id = get_user_role(0); // Mock: role 1 (Quality/Thomas)

// --- KPI Queries ---
$openTopics = 0;
$myTodos = 0;
$pipeline = [];
$criticalObjects = [];
$latestActivity = [];

try {
    // Open Topics: tickets where status is NOT terminal (is_terminal = 0)
    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM tickets t
        JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE ts.is_terminal = 0
    ");
    $openTopics = (int) ($stmt->fetch()['cnt'] ?? 0);

    // My ToDos: tickets where current_role_id matches my role AND not terminal
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM tickets t
        JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE ts.is_terminal = 0
          AND t.current_role_id = ?
    ");
    $stmt->execute([$my_role_id]);
    $myTodos = (int) ($stmt->fetch()['cnt'] ?? 0);

    // Pipeline Overview: distribution by stage (Quality, Business, Dev, Delivery)
    // Map status_id to stage based on import status mapping (10,20=Quality; 30=Business; 40,50=Dev; rest=Delivery)
    $stmt = $pdo->query("
        SELECT
            CASE
                WHEN ts.id IN (10, 20) THEN 'Quality'
                WHEN ts.id = 30 THEN 'Business'
                WHEN ts.id IN (40, 50) THEN 'Dev'
                ELSE 'Delivery'
            END AS stage,
            COUNT(*) AS cnt
        FROM tickets t
        JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE ts.is_terminal = 0
        GROUP BY stage
        ORDER BY FIELD(stage, 'Quality', 'Business', 'Dev', 'Delivery')
    ");
    $pipeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Critical Objects: top 5 objects with most open tickets (via ticket_objects N:M)
    $stmt = $pdo->query("
        SELECT o.id, o.name, COUNT(DISTINCT t.id) AS open_count
        FROM objects o
        JOIN ticket_objects to2 ON to2.object_id = o.id
        JOIN tickets t ON t.id = to2.ticket_id
        JOIN ticket_statuses ts ON t.status_id = ts.id
        WHERE ts.is_terminal = 0
        GROUP BY o.id, o.name
        ORDER BY open_count DESC
        LIMIT 5
    ");
    $criticalObjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest Activity: 5 most recent ticket_comments with ticket title and author
    $stmt = $pdo->query("
        SELECT tc.id, tc.ticket_id, tc.author, tc.comment_text, tc.created_at, t.title AS ticket_title
        FROM ticket_comments tc
        JOIN tickets t ON tc.ticket_id = t.id
        ORDER BY tc.created_at DESC
        LIMIT 5
    ");
    $latestActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Dashboard';
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if (!empty($dbError)): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <h1 class="page-title">Dashboard</h1>
                    </div>

                    <!-- KPI Cards -->
                    <div class="row row-deck row-cards mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="subheader">Open Topics</div>
                                    </div>
                                    <div class="h1 mb-0"><?= (int) $openTopics ?></div>
                                    <div class="text-muted">Tickets not yet confirmed</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="subheader">My ToDos</div>
                                    </div>
                                    <div class="h1 mb-0"><?= (int) $myTodos ?></div>
                                    <div class="text-muted">Waiting on my role</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-2">Pipeline Overview</div>
                                    <div class="row g-2">
                                        <?php foreach ($pipeline as $row): ?>
                                        <div class="col-auto">
                                            <span class="badge bg-blue-lt me-1"><?= e($row['stage']) ?></span>
                                            <strong><?= (int) $row['cnt'] ?></strong>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($pipeline)): ?>
                                        <div class="col text-muted">No open tickets</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row row-deck row-cards">
                        <!-- Critical Objects -->
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Critical Objects</h3>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($criticalObjects)): ?>
                                    <p class="text-muted mb-0">No open tickets.</p>
                                    <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($criticalObjects as $obj): ?>
                                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                            <a href="tickets.php?object_id=<?= (int) $obj['id'] ?>"><?= e($obj['name']) ?></a>
                                            <span class="badge bg-red-lt"><?= (int) $obj['open_count'] ?> open</span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Latest Activity -->
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Latest Activity</h3>
                                    <div class="card-actions">
                                        <a href="tickets.php" class="btn btn-primary btn-sm">View all</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($latestActivity)): ?>
                                    <p class="text-muted p-3 mb-0">No comments yet.</p>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-vcenter card-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Ticket</th>
                                                    <th>Author</th>
                                                    <th>Comment</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($latestActivity as $a): ?>
                                                <tr>
                                                    <td>
                                                        <a href="ticket_detail.php?id=<?= (int) $a['ticket_id'] ?>"><?= e($a['ticket_title']) ?></a>
                                                    </td>
                                                    <td><?= e($a['author']) ?></td>
                                                    <td class="text-muted" style="max-width:200px;"><?= e(mb_substr($a['comment_text'], 0, 60)) ?><?= mb_strlen($a['comment_text']) > 60 ? '…' : '' ?></td>
                                                    <td class="text-muted"><?= format_datetime($a['created_at']) ?></td>
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
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
