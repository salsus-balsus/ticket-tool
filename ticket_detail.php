<?php
/**
 * ticket_detail.php - Ticket Detail & Workflow Engine (View)
 * Joins: objects, sectors, ticket_statuses, ticket_types, roles, customers.
 * Assignees: ticket_assignees. Objects: ticket_objects. Relations: source_ticket_id/target_ticket_id. Tests: ticket_test_relations.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$msg = $_GET['msg'] ?? '';

if ($id <= 0) {
    header('Location: tickets.php');
    exit;
}

$ticket = null;
$comments = [];
$relationships = [];
$testExecutions = [];
$allowedTransitions = [];
$affectedObjects = [];
$dbError = null;
$current_user_role_id = get_user_role(0);

try {
    // Main ticket: object via ticket_objects (N:M), people via ticket_participants (REP/RES)
    $ticketSql = "
        SELECT
            t.id, t.ticket_no, t.title, t.goal, t.working_prio, t.priority, t.created_at,
            t.status_id, t.type_id, t.current_role_id, t.lock_type, t.redirect_ticket_id, t.customer_id,
            t.affected_object_count, t.affected_object_note,
            (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants WHERE ticket_id = t.id AND role = 'REP') AS reporter_names,
            (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants WHERE ticket_id = t.id AND role = 'RES') AS responsible_names,
            o.name AS object_name,
            s.name AS sector_name,
            ts.name AS status_name, ts.color_code AS status_color,
            tt.name AS type_name, tt.code AS type_code,
            r.name AS role_name,
            c.name AS customer_name
        FROM tickets t
        LEFT JOIN (SELECT ticket_id, MIN(object_id) AS object_id FROM ticket_objects GROUP BY ticket_id) to1 ON to1.ticket_id = t.id
        LEFT JOIN objects o ON o.id = to1.object_id
        LEFT JOIN sectors s ON o.sector_id = s.id
        LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
        LEFT JOIN ticket_types tt ON t.type_id = tt.id
        LEFT JOIN roles r ON t.current_role_id = r.id
        LEFT JOIN customers c ON t.customer_id = c.id
        WHERE t.id = ?
    ";
    try {
        $stmt = $pdo->prepare($ticketSql);
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
        // Fallback when e.g. lock_type column does not exist yet (migration not run)
        try {
            $ticketSqlNoLock = "
                SELECT
                    t.id, t.ticket_no, t.title, t.goal, t.working_prio, t.priority, t.created_at,
                    t.status_id, t.type_id, t.current_role_id, t.customer_id,
                    (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants WHERE ticket_id = t.id AND role = 'REP') AS reporter_names,
                    (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants WHERE ticket_id = t.id AND role = 'RES') AS responsible_names,
                    o.name AS object_name,
                    s.name AS sector_name,
                    ts.name AS status_name, ts.color_code AS status_color,
                    tt.name AS type_name, tt.code AS type_code,
                    r.name AS role_name,
                    c.name AS customer_name
                FROM tickets t
                LEFT JOIN (SELECT ticket_id, MIN(object_id) AS object_id FROM ticket_objects GROUP BY ticket_id) to1 ON to1.ticket_id = t.id
                LEFT JOIN objects o ON o.id = to1.object_id
                LEFT JOIN sectors s ON o.sector_id = s.id
                LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
                LEFT JOIN ticket_types tt ON t.type_id = tt.id
                LEFT JOIN roles r ON t.current_role_id = r.id
                LEFT JOIN customers c ON t.customer_id = c.id
                WHERE t.id = ?
            ";
            $stmt = $pdo->prepare($ticketSqlNoLock);
            $stmt->execute([$id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ticket) {
                $ticket['affected_object_count'] = null;
                $ticket['affected_object_note'] = null;
                if (!isset($ticket['ticket_no'])) $ticket['ticket_no'] = null;
                $ticket['lock_type'] = null;
                $ticket['redirect_ticket_id'] = null;
            }
        } catch (PDOException $e2) {
            throw $e2;
        }
    }

    if (!$ticket) {
        header('Location: tickets.php');
        exit;
    }

    // Customer Scope: fallback from first reporter (ticket_participants) if customer_id not set
    $firstReporter = trim(explode(',', $ticket['reporter_names'] ?? '')[0] ?? '');
    if (empty($ticket['customer_name']) && $firstReporter !== '') {
        $stmt = $pdo->prepare("
            SELECT c.name FROM customers c
            WHERE ? LIKE CONCAT('%', c.name, '%')
            ORDER BY LENGTH(c.name) DESC
            LIMIT 1
        ");
        $stmt->execute([$firstReporter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ticket['customer_name'] = $row['name'];
        }
    }

    // Allowed transitions (for workflow box)
    $allowedTransitions = get_allowed_transitions(
        $id,
        (int) $ticket['status_id'],
        (int) $ticket['type_id'],
        $current_user_role_id
    );

    // Workflow flowchart (horizontal, current status highlighted) – same data as admin_workflow
    $workflowStatuses = $pdo->query("SELECT * FROM ticket_statuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $workflowTransitions = [];
    try {
        $workflowTransitions = $pdo->query("
            SELECT wt.*, s1.name AS from_status, s2.name AS to_status,
                   r.name AS role_name, r2.name AS target_role_name, tt.code AS type_code
            FROM workflow_transitions wt
            JOIN ticket_statuses s1 ON wt.current_status_id = s1.id
            JOIN ticket_statuses s2 ON wt.next_status_id = s2.id
            JOIN roles r ON wt.allowed_role_id = r.id
            LEFT JOIN roles r2 ON wt.target_owner_role_id = r2.id
            LEFT JOIN ticket_types tt ON wt.flow_type_id = tt.id
            ORDER BY s1.id, s2.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    $workflowMermaid = build_mermaid_flowchart(
        $workflowTransitions,
        $workflowStatuses,
        (int) $ticket['type_id'],
        'LR',
        (int) $ticket['status_id'],
        $ticket['lock_type'] ?? null
    );

    // Status explanations for flowchart click (same node ids as in build_mermaid_flowchart)
    $workflowStatusExplanations = [];
    if (!empty($workflowTransitions)) {
        $flowTypeId = (int) $ticket['type_id'];
        $usedStatusIds = [];
        foreach ($workflowTransitions as $t) {
            if ((int) ($t['flow_type_id'] ?? 0) === $flowTypeId) {
                $usedStatusIds[(int) $t['current_status_id']] = true;
                $usedStatusIds[(int) $t['next_status_id']] = true;
            }
        }
        $idPad = strlen((string) max(array_keys($usedStatusIds ?: [1])));
        foreach ($workflowStatuses as $s) {
            $sid = (int) $s['id'];
            if (empty($usedStatusIds[$sid])) continue;
            $nid = 's' . str_pad((string) $sid, max(2, $idPad), '0', STR_PAD_LEFT);
            $workflowStatusExplanations[$nid] = [
                'name' => $s['name'] ?? '',
                'explanation' => trim($s['explanation'] ?? '')
            ];
        }
    }

    // Related: comments (ORDER BY created_at DESC)
    $stmt = $pdo->prepare("
        SELECT id, author, comment_text, created_at
        FROM ticket_comments
        WHERE ticket_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Related: ticket_relationships (source_ticket_id, target_ticket_id)
    try {
        $stmt = $pdo->prepare("
            SELECT tr.relationship_type,
                   CASE WHEN tr.source_ticket_id = ? THEN tr.target_ticket_id ELSE tr.source_ticket_id END AS related_id,
                   rt.title AS related_title
            FROM ticket_relationships tr
            LEFT JOIN tickets rt ON rt.id = (CASE WHEN tr.source_ticket_id = ? THEN tr.target_ticket_id ELSE tr.source_ticket_id END)
            WHERE tr.source_ticket_id = ? OR tr.target_ticket_id = ?
            ORDER BY tr.relationship_type, related_id
        ");
        $stmt->execute([$id, $id, $id, $id]);
        $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $relEx) {
        $relationships = [];
        try {
            $stmt = $pdo->prepare("
                SELECT CASE WHEN tr.source_ticket_id = ? THEN tr.target_ticket_id ELSE tr.source_ticket_id END AS related_id,
                       rt.title AS related_title
                FROM ticket_relationships tr
                LEFT JOIN tickets rt ON rt.id = (CASE WHEN tr.source_ticket_id = ? THEN tr.target_ticket_id ELSE tr.source_ticket_id END)
                WHERE tr.source_ticket_id = ? OR tr.target_ticket_id = ?
                ORDER BY related_id
            ");
            $stmt->execute([$id, $id, $id, $id]);
            $relationships = array_map(function($r) { $r['relationship_type'] = 'related'; return $r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e2) {
            $relationships = [];
        }
    }

    // Related: test_executions via ticket_test_relations (many-to-many)
    $testExecutions = [];
    try {
        $stmt = $pdo->prepare("
            SELECT te.id, tc.title AS name, te.overall_status AS result, NULL AS executed_at
            FROM ticket_test_relations ttr
            JOIN test_executions te ON ttr.test_execution_id = te.id
            LEFT JOIN test_cases tc ON te.test_case_id = tc.id
            WHERE ttr.ticket_id = ?
            ORDER BY te.id DESC
        ");
        $stmt->execute([$id]);
        $testExecutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $eTe) {
        if (strpos($eTe->getMessage(), 'overall_status') !== false) {
            $stmt = $pdo->prepare("
                SELECT te.id, tc.title AS name, NULL AS result, NULL AS executed_at
                FROM ticket_test_relations ttr
                JOIN test_executions te ON ttr.test_execution_id = te.id
                LEFT JOIN test_cases tc ON te.test_case_id = tc.id
                WHERE ttr.ticket_id = ?
                ORDER BY te.id DESC
            ");
            $stmt->execute([$id]);
            $testExecutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            throw $eTe;
        }
    }

    // Affected objects (ticket_objects + affected_object_count/note)
    $affectedObjects = [];
    try {
        $stmt = $pdo->prepare("
            SELECT o.id, o.name FROM ticket_objects to2
            JOIN objects o ON to2.object_id = o.id
            WHERE to2.ticket_id = ?
            ORDER BY o.name
        ");
        $stmt->execute([$id]);
        $affectedObjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Alle Kunden, die dieses Objekt im Scope haben (customer_scopes) – mit id für Links
    $objectScopeCustomers = [];
    $primaryObjectId = isset($affectedObjects[0]['id']) ? (int) $affectedObjects[0]['id'] : 0;
    if ($primaryObjectId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.name FROM customer_scopes cs
                JOIN customers c ON cs.customer_id = c.id
                WHERE cs.object_id = ?
                ORDER BY c.name
            ");
            $stmt->execute([$primaryObjectId]);
            $objectScopeCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // Involved People: Rolle und Vorname aus app_users (username, first_name+last_name → role, first_name only for display)
    $personRoleMap = [];
    $personFirstNameMap = [];
    $customerNamesForMatch = [];
    try {
        $stmt = $pdo->query("
            SELECT u.first_name, u.last_name, u.username, r.name AS role_name
            FROM app_users u
            JOIN roles r ON r.id = u.role_id
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rn = trim($row['role_name'] ?? '');
            $fn = trim($row['first_name'] ?? '');
            $ln = trim($row['last_name'] ?? '');
            $full = trim($fn . ' ' . $ln);
            $displayFirst = $fn !== '' ? $fn : $full;
            if ($rn !== '') {
                $un = trim($row['username'] ?? '');
                if ($un !== '') {
                    $personRoleMap[$un] = $rn;
                    if ($displayFirst !== '') $personFirstNameMap[$un] = $displayFirst;
                }
                if ($full !== '') {
                    $personRoleMap[$full] = $rn;
                    $personFirstNameMap[$full] = $displayFirst;
                    if ($ln !== '') {
                        $alt = trim($ln . ' ' . $fn);
                        $personRoleMap[$alt] = $rn;
                        $personFirstNameMap[$alt] = $displayFirst;
                    }
                }
                if ($fn !== '') {
                    $personRoleMap[$fn] = $rn;
                    $personFirstNameMap[$fn] = $displayFirst;
                }
            }
        }
        $stmt = $pdo->query("SELECT name FROM customers WHERE name IS NOT NULL AND TRIM(name) != ''");
        $customerNamesForMatch = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}

    // User der aktuellen Rolle (Current Owner) für Popup
    $currentRoleUsers = [];
    $currentRoleId = (int) ($ticket['current_role_id'] ?? 0);
    if ($currentRoleId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT u.username, u.first_name, u.last_name, u.initials
                FROM app_users u
                WHERE u.role_id = ?
                ORDER BY u.first_name, u.last_name, u.username
            ");
            $stmt->execute([$currentRoleId]);
            $currentRoleUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

/**
 * Map button_color from workflow to Tabler btn class
 */
function transition_btn_class($color) {
    $c = strtolower(trim($color ?? ''));
    if (in_array($c, ['green', 'success'])) return 'btn-success';
    if (in_array($c, ['red', 'danger'])) return 'btn-danger';
    if (in_array($c, ['yellow', 'warning'])) return 'btn-warning';
    if (in_array($c, ['blue', 'primary'])) return 'btn-primary';
    return 'btn-outline-primary';
}
$pageTitle = ($ticket && !empty($ticket['ticket_no'])) ? '#' . (int) $ticket['ticket_no'] : '#' . (int) $id;
require_once 'includes/header.php';
?>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>

                    <?php if (!$ticket): ?>
                    <div class="alert alert-warning">Ticket not found.</div>
                    <a href="tickets.php" class="btn btn-primary">Back to Tickets</a>
                    <?php else: ?>

                    <?php if ($msg): ?>
                    <div class="alert alert-success alert-dismissible" role="alert">
                        <?= e($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div class="page-header d-print-none mb-4">
                        <div>
                            <div class="row g-2 align-items-center">
                                <div class="col">
                                    <h1 class="page-title"><?= e($ticket['title']) ?></h1>
                                    <div class="display-5 fw-bold text-muted">#<?= !empty($ticket['ticket_no']) ? (int) $ticket['ticket_no'] : (int) $ticket['id'] ?></div>
                                </div>
                                <div class="col-auto d-flex align-items-center gap-2 ticket-status-block">
                                    <?php
                                    $typeCode = strtoupper(trim($ticket['type_code'] ?? ''));
                                    $typeLabel = (in_array($typeCode, ['CUST', 'EXT', 'EXTERNAL'])) ? 'External' : 'Internal';
                                    ?>
                                    <span class="text-muted ticket-type-label"><?= e($typeLabel) ?></span>
                                    <?= render_status_badge($ticket['status_name'], $ticket['status_color']) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $is_quality_owner = (isset($ticket['role_name']) && $ticket['role_name'] === 'Quality');
                    $lock_type = $ticket['lock_type'] ?? null;
                    $lock_labels = ['OBS' => 'Obsolete', 'ONH' => 'On Hold', 'RED' => 'Redirect'];
                    ?>
                    <!-- Workflow Box (Top Prominent) – horizontal flowchart + current status highlight -->
                    <?php // TODO: When role "Standard" is selected: allow clicking a status node to set ticket to that status (testing). Show explanation via small (i) icon next to node instead of modal. ?>
                    <div class="card mb-4">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h3 class="card-title mb-0">Workflow</h3>
                            <?php if ($is_quality_owner && $lock_type === 'RED' && !empty($ticket['redirect_ticket_id'])): ?>
                            <span class="redirected-to-label fw-bold" style="font-size:1rem;"><span class="text-muted">Redirected to</span> <a href="ticket_detail.php?id=<?= (int) $ticket['redirect_ticket_id'] ?>" class="text-decoration-none fw-bold">#<?= (int) $ticket['redirect_ticket_id'] ?></a></span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($workflowTransitions)): ?>
                            <div class="workflow-flowchart-wrap mb-3">
                                <div class="mermaid mermaid-workflow-horizontal"><?= e($workflowMermaid) ?></div>
                            </div>
                            <?php if (!empty($workflowStatusExplanations)): ?>
                            <div class="modal modal-blur fade" id="workflowStatusModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="workflowStatusModalTitle">Status</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body" id="workflowStatusModalBody"></div>
                                    </div>
                                </div>
                            </div>
                            <script>
                            window.WORKFLOW_STATUS_EXPLANATIONS = <?= json_encode($workflowStatusExplanations) ?>;
                            </script>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($currentRoleUsers)): ?>
                            <div class="modal modal-blur fade" id="currentRoleUsersModal" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="currentRoleUsersModalTitle"><?= e($ticket['role_name'] ?? 'Role') ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="text-muted small mb-2">Users with this role:</p>
                                            <ul class="list-unstyled mb-0">
                                                <?php foreach ($currentRoleUsers as $ru): ?>
                                                <li><?= e(trim(($ru['first_name'] ?? '') . ' ' . ($ru['last_name'] ?? '')) ?: $ru['username']) ?><?php if (!empty($ru['username'])): ?> <span class="text-muted">(<?= e($ru['username']) ?>)</span><?php endif; ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="d-flex flex-wrap gap-2">
                                    <?php
                                    $workflow_locked = !empty($lock_type);
                                    if (!empty($allowedTransitions)): ?>
                                        <?php foreach ($allowedTransitions as $tr): ?>
                                        <form method="post" action="ticket_action.php" class="d-inline">
                                            <input type="hidden" name="ticket_id" value="<?= $id ?>">
                                            <input type="hidden" name="next_status_id" value="<?= (int) $tr['next_status_id'] ?>">
                                            <input type="hidden" name="target_role_id" value="<?= (int) ($tr['target_owner_role_id'] ?? 0) ?>">
                                            <button type="submit" class="btn <?= transition_btn_class($tr['target_status_color'] ?? $tr['button_color'] ?? null) ?><?= $workflow_locked ? ' opacity-75' : '' ?>"<?= $workflow_locked ? ' disabled' : '' ?>>
                                                <?= e($tr['button_label']) ?>
                                            </button>
                                        </form>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <div class="alert alert-secondary mb-0 py-2" role="alert">
                                        <strong>Current Status:</strong> <?= e($ticket['status_name']) ?>.
                                        <?php if (!empty($ticket['role_name'])): ?>
                                        Waiting for <strong><?= e($ticket['role_name']) ?></strong>.
                                        <?php else: ?>
                                        No transitions available.
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($is_quality_owner): ?>
                                <div class="d-flex flex-wrap gap-2 ms-auto">
                                    <?php if (empty($lock_type)): ?>
                                    <form method="post" action="ticket_lock.php" class="d-inline">
                                        <input type="hidden" name="ticket_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="set_lock">
                                        <input type="hidden" name="lock_type" value="OBS">
                                        <button type="submit" class="btn btn-outline-danger">Obsolete</button>
                                    </form>
                                    <form method="post" action="ticket_lock.php" class="d-inline">
                                        <input type="hidden" name="ticket_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="set_lock">
                                        <input type="hidden" name="lock_type" value="ONH">
                                        <button type="submit" class="btn btn-outline-warning">On Hold</button>
                                    </form>
                                    <button type="button" class="btn btn-outline-primary" style="border-color:#7c3aed;color:#7c3aed" data-bs-toggle="modal" data-bs-target="#redirectModal">Redirect</button>
                                    <?php else: ?>
                                    <form method="post" action="ticket_lock.php" class="d-inline">
                                        <input type="hidden" name="ticket_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="revoke_lock">
                                        <button type="submit" class="btn revoke-redirect-btn" style="border:1px solid #7c3aed;color:#6c757d;background:transparent;">Revoke <?= e($lock_labels[$lock_type] ?? $lock_type) ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_quality_owner): ?>
                    <div class="modal modal-blur fade" id="redirectModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Redirect – Select follow-up ticket</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="text-muted small mb-2">This ticket is replaced by a follow-up ticket. Search for the ticket and select it.</p>
                                    <div class="input-group mb-3">
                                        <input type="text" id="redirectSearchInput" class="form-control" placeholder="Ticket #, title, reporter…" aria-label="Search">
                                        <button type="button" class="btn btn-primary" id="redirectSearchBtn">Search</button>
                                    </div>
                                    <div id="redirectSearchResults" class="list-group list-group-flush" style="max-height:280px;overflow-y:auto;"></div>
                                    <form id="redirectForm" method="post" action="ticket_lock.php" class="d-none">
                                        <input type="hidden" name="ticket_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="set_lock">
                                        <input type="hidden" name="lock_type" value="RED">
                                        <input type="hidden" name="redirect_ticket_id" id="redirectTicketIdInput" value="">
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                    (function() {
                        var searchUrl = 'tickets_search.php';
                        var excludeId = <?= (int) $id ?>;
                        var resultsEl = document.getElementById('redirectSearchResults');
                        var inputEl = document.getElementById('redirectSearchInput');
                        var formEl = document.getElementById('redirectForm');
                        var redirectInput = document.getElementById('redirectTicketIdInput');
                        function doSearch() {
                            var q = (inputEl && inputEl.value) ? inputEl.value.trim() : '';
                            if (q.length < 1) { resultsEl.innerHTML = '<div class="list-group-item text-muted">Please enter at least one character.</div>'; return; }
                            resultsEl.innerHTML = '<div class="list-group-item text-muted">Searching…</div>';
                            fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&exclude_id=' + excludeId)
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    var tickets = data.tickets || [];
                                    if (tickets.length === 0) {
                                        resultsEl.innerHTML = '<div class="list-group-item text-muted">No tickets found.</div>';
                                        return;
                                    }
                                    var html = '';
                                    tickets.forEach(function(t) {
                                        var no = t.ticket_no != null ? t.ticket_no : t.id;
                                        var title = (t.title || '-');
                                        var shortTitle = title.length > 60 ? title.substring(0, 60) + '…' : title;
                                        html += '<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">';
                                        html += '<span>#' + no + ' – ' + escapeHtml(shortTitle) + '</span>';
                                        html += '<button type="button" class="btn btn-sm btn-primary redirect-select-btn" data-id="' + t.id + '">Select</button>';
                                        html += '</div>';
                                    });
                                    resultsEl.innerHTML = html;
                                    resultsEl.querySelectorAll('.redirect-select-btn').forEach(function(btn) {
                                        btn.addEventListener('click', function() {
                                            var id = parseInt(this.getAttribute('data-id'), 10);
                                            if (id && redirectInput) { redirectInput.value = id; formEl.submit(); }
                                        });
                                    });
                                })
                                .catch(function() { resultsEl.innerHTML = '<div class="list-group-item text-danger">Search failed.</div>'; });
                        }
                        function escapeHtml(s) {
                            var div = document.createElement('div');
                            div.textContent = s;
                            return div.innerHTML;
                        }
                        if (document.getElementById('redirectSearchBtn')) document.getElementById('redirectSearchBtn').addEventListener('click', doSearch);
                        if (inputEl) inputEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
                    })();
                    </script>
                    <?php endif; ?>

                    <!-- Info Grid (3 Columns) -->
                    <div class="row row-deck row-cards mb-4">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Context</h3>
                                </div>
                                <div class="card-body">
                                    <dl class="mb-0">
                                        <dt>Sector</dt>
                                        <dd><?= e($ticket['sector_name'] ?? '-') ?></dd>
                                        <dt>Object<?= (count($affectedObjects ?? []) > 1 || !empty($ticket['affected_object_note'] ?? '') ? 's' : '') ?></dt>
                                        <dd>
                                            <?php if (!empty($affectedObjects)): ?>
                                                <ul class="list-unstyled mb-0">
                                                    <?php foreach ($affectedObjects as $ao): ?>
                                                    <li><a href="tickets.php?object=<?= (int)$ao['id'] ?>"><?= e($ao['name']) ?></a></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php elseif (!empty($ticket['affected_object_count']) || !empty($ticket['affected_object_note'])): ?>
                                                <?php
                                                $cnt = $ticket['affected_object_count'] ?? null;
                                                $note = $ticket['affected_object_note'] ?? '';
                                                if ($cnt !== null && $cnt !== ''): ?>
                                                    <?= e($cnt == 0 ? 'all object types' : (int)$cnt . ' object types') ?>
                                                <?php else: ?>
                                                    <?= e($note) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?= e($ticket['object_name'] ?? '-') ?>
                                            <?php endif; ?>
                                        </dd>
                                        <dt>Customer Scope</dt>
                                        <dd>
                                            <?php if (!empty($objectScopeCustomers)): ?>
                                                <?php
                                                $parts = [];
                                                foreach ($objectScopeCustomers as $oc) {
                                                    $cid = (int) ($oc['id'] ?? 0);
                                                    $cname = $oc['name'] ?? '';
                                                    $parts[] = $cid > 0 ? '<a href="customer_scope.php#customer-' . $cid . '">' . e($cname) . '</a>' : e($cname);
                                                }
                                                echo implode(', ', $parts);
                                                ?>
                                            <?php elseif (!empty($ticket['customer_name'])): ?>
                                                <?php $tid = (int) ($ticket['customer_id'] ?? 0); ?>
                                                <?php if ($tid > 0): ?><a href="customer_scope.php#customer-<?= $tid ?>"><?= e($ticket['customer_name']) ?></a><?php else: ?><?= e($ticket['customer_name']) ?><?php endif; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Process</h3>
                                </div>
                                <div class="card-body">
                                    <dl class="mb-0">
                                        <dt>Type</dt>
                                        <dd><span class="badge bg-blue-lt"><?= e($ticket['type_code'] ?? $ticket['type_name'] ?? '-') ?></span></dd>
                                        <dt>Priority</dt>
                                        <dd><?= e($ticket['priority'] ?? '-') ?></dd>
                                        <dt>Working Prio</dt>
                                        <dd><?= ($ticket['working_prio'] !== null && $ticket['working_prio'] !== '') ? (int) $ticket['working_prio'] : '—' ?></dd>
                                        <dt>Goal</dt>
                                        <dd><?= !empty(trim($ticket['goal'] ?? '')) ? nl2br(e($ticket['goal'])) : '—' ?></dd>
                                        <dt>Created</dt>
                                        <dd><?= format_datetime($ticket['created_at']) ?> by <?= $firstReporter !== '' ? '<strong>' . e($firstReporter) . '</strong>' : '—' ?></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Involved People</h3>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $reporters = array_filter(array_map('trim', explode(',', $ticket['reporter_names'] ?? '')));
                                    $responsibles = array_filter(array_map('trim', explode(',', $ticket['responsible_names'] ?? '')));
                                    $hasAny = !empty($reporters) || !empty($responsibles);
                                    ?>
                                    <?php if (!$hasAny): ?>
                                    <p class="text-muted mb-0">No reporters or responsible people.</p>
                                    <?php else: ?>
                                    <?php
                                    $personRoleMap = $personRoleMap ?? [];
                                    $personFirstNameMap = $personFirstNameMap ?? [];
                                    $customerNamesForMatch = $customerNamesForMatch ?? [];
                                    $personLabel = function($name) use ($personRoleMap, $personFirstNameMap, $customerNamesForMatch) {
                                        $name = trim($name);
                                        if ($name === '') return '';
                                        $displayName = $personFirstNameMap[$name] ?? $name;
                                        $role = $personRoleMap[$name] ?? null;
                                        $isCustomer = false;
                                        foreach ($customerNamesForMatch as $cname) {
                                            $cname = trim($cname);
                                            if ($cname === '') continue;
                                            if (strcasecmp($name, $cname) === 0 || stripos($name, $cname) !== false || stripos($cname, $name) !== false) {
                                                $isCustomer = true;
                                                break;
                                            }
                                        }
                                        $parts = array_filter([$role, $isCustomer ? 'Customer' : null]);
                                        $suffix = empty($parts) ? '' : ' <span class="small text-muted">(' . e(implode(', ', $parts)) . ')</span>';
                                        return e($displayName) . $suffix;
                                    };
                                    ?>
                                    <dl class="mb-0">
                                        <dt>Reporter</dt>
                                        <dd class="mb-2"><?php if (empty($reporters)): ?><span class="text-muted">—</span><?php else: ?><ul class="list-unstyled mb-0"><?php foreach ($reporters as $name): ?><li><?= $personLabel($name) ?></li><?php endforeach; ?></ul><?php endif; ?></dd>
                                        <dt>Responsible</dt>
                                        <dd class="mb-0"><?php if (empty($responsibles)): ?><span class="text-muted">—</span><?php else: ?><ul class="list-unstyled mb-0"><?php foreach ($responsibles as $name): ?><li><?= $personLabel($name) ?></li><?php endforeach; ?></ul><?php endif; ?></dd>
                                    </dl>
                                    <hr>
                                    <dl class="mb-0">
                                        <dt>Current Owner (Role)</dt>
                                        <dd>
                                            <?php if (!empty($ticket['role_name']) && !empty($currentRoleUsers)): ?>
                                                <a href="#" class="text-reset text-decoration-none" data-bs-toggle="modal" data-bs-target="#currentRoleUsersModal"><?= e($ticket['role_name']) ?></a>
                                            <?php else: ?>
                                                <?= e($ticket['role_name'] ?? '-') ?>
                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs / Lower Section -->
                    <div class="card">
                        <div class="card-header">
                            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#tab-audit">Audit Trail</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#tab-relations">Relations</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#tab-tests">Tests</a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content">
                                <!-- Audit Trail -->
                                <div class="tab-pane active" id="tab-audit">
                                    <?php if (empty($comments)): ?>
                                    <p class="text-muted mb-0">No comments yet.</p>
                                    <?php else: ?>
                                    <ul class="timeline">
                                        <?php foreach ($comments as $c): ?>
                                        <li class="timeline-event">
                                            <div class="timeline-event-icon">
                                                <span class="avatar avatar-sm"><?= e(get_author_initials($c['author'], (int)($c['id'] ?? 0))) ?></span>
                                            </div>
                                            <div class="timeline-event-card card">
                                                <div class="card-body">
                                                    <div class="text-muted float-end"><?= format_datetime($c['created_at']) ?></div>
                                                    <div class="fw-bold"><?= e(get_author_display_name($c['author'], (int)($c['id'] ?? 0))) ?></div>
                                                    <div><?= nl2br(e($c['comment_text'])) ?></div>
                                                </div>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </div>
                                <!-- Relations -->
                                <div class="tab-pane" id="tab-relations">
                                    <?php if (empty($relationships)): ?>
                                    <p class="text-muted mb-0">No related tickets.</p>
                                    <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($relationships as $rel): ?>
                                        <?php
                                        $relId = (int) ($rel['related_id'] ?? 0);
                                        $relTitle = $rel['related_title'] ?? "Ticket #$relId";
                                        ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?= e($rel['relationship_type'] ?? 'related') ?>: <a href="ticket_detail.php?id=<?= $relId ?>"><?= e($relTitle) ?></a></span>
                                            <a href="ticket_detail.php?id=<?= $relId ?>" class="badge bg-secondary"><?= $relId ?></a>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </div>
                                <!-- Tests -->
                                <div class="tab-pane" id="tab-tests">
                                    <?php if (empty($testExecutions)): ?>
                                    <p class="text-muted mb-0">No test executions linked.</p>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Test</th>
                                                    <th>Result</th>
                                                    <th>Executed</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($testExecutions as $te): ?>
                                                <tr>
                                                    <td><?= e($te['name'] ?? 'Test #' . $te['id']) ?></td>
                                                    <td>
                                                        <?php
                                                        $res = strtolower($te['result'] ?? '');
                                                        $badge = ($res === 'pass' || $res === 'passed') ? 'bg-success-lt' : (($res === 'fail' || $res === 'failed') ? 'bg-danger-lt' : 'bg-secondary-lt');
                                                        ?>
                                                        <span class="badge <?= $badge ?>"><?= e($te['result'] ?? '-') ?></span>
                                                    </td>
                                                    <td><?= format_datetime($te['executed_at'] ?? null) ?></td>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>

<style>
.ticket-status-block .ticket-type-label { font-size: 1rem; font-weight: 500; }
.ticket-status-block .badge { font-size: 1rem; padding: 0.35rem 0.65rem; }
.redirected-to-label { font-size: 1rem; }
.redirected-to-label a { color: #7c3aed; }
.redirected-to-label a:hover { text-decoration: underline !important; }
.revoke-redirect-btn:hover { background: rgba(124, 58, 237, 0.1) !important; color: #6c757d !important; border-color: #7c3aed !important; }
.workflow-flowchart-wrap {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 0.5rem 0;
    background: #fff;
}
.mermaid-workflow-horizontal {
    display: flex;
    justify-content: flex-start;
    min-width: min-content;
}
.mermaid-workflow-horizontal svg {
    max-height: 280px;
    width: auto;
}
/* White backdrop for edge labels (Zustandswechsel) so edges don’t run through text */
.mermaid .edgeLabel rect { fill: white !important; stroke: white !important; }
.mermaid .edgeLabel .labelBkg { fill: white !important; }
.mermaid foreignObject:has(.edgeLabel) .edgeLabel p,
.mermaid foreignObject:has(.edgeLabel) p { background-color: white !important; padding: 2px 6px !important; border-radius: 4px; max-width: max-content; }
.mermaid .edgeLabel text { paint-order: stroke; stroke: white; stroke-width: 3px; }

/* Current status highlight – animation */
.mermaid .currentStatusHighlight rect,
.mermaid .currentStatusHighlight polygon,
.mermaid .currentStatusHighlight circle {
    animation: workflow-status-pulse 1.5s ease-in-out infinite;
}
@keyframes workflow-status-pulse {
    0%, 100% { opacity: 1; filter: drop-shadow(0 0 2px rgba(25, 118, 210, 0.4)); }
    50% { opacity: 0.92; filter: drop-shadow(0 0 8px rgba(25, 118, 210, 0.7)); }
}
/* Lock overlay shimmer: Obsolete (red), On Hold (orange), Redirect (purple) */
.mermaid .currentStatusLockOBS rect,
.mermaid .currentStatusLockOBS polygon,
.mermaid .currentStatusLockOBS circle {
    animation: workflow-lock-obs 1.5s ease-in-out infinite;
}
.mermaid .currentStatusLockONH rect,
.mermaid .currentStatusLockONH polygon,
.mermaid .currentStatusLockONH circle {
    animation: workflow-lock-onh 1.5s ease-in-out infinite;
}
.mermaid .currentStatusLockRED rect,
.mermaid .currentStatusLockRED polygon,
.mermaid .currentStatusLockRED circle {
    animation: workflow-lock-red 1.5s ease-in-out infinite;
}
@keyframes workflow-lock-obs {
    0%, 100% { opacity: 1; filter: drop-shadow(0 0 2px rgba(220, 38, 38, 0.4)); }
    50% { opacity: 0.92; filter: drop-shadow(0 0 8px rgba(220, 38, 38, 0.7)); }
}
@keyframes workflow-lock-onh {
    0%, 100% { opacity: 1; filter: drop-shadow(0 0 2px rgba(234, 88, 12, 0.4)); }
    50% { opacity: 0.92; filter: drop-shadow(0 0 8px rgba(234, 88, 12, 0.7)); }
}
@keyframes workflow-lock-red {
    0%, 100% { opacity: 1; filter: drop-shadow(0 0 2px rgba(124, 58, 237, 0.4)); }
    50% { opacity: 0.92; filter: drop-shadow(0 0 8px rgba(124, 58, 237, 0.7)); }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    mermaid.initialize({
        startOnLoad: false,
        flowchart: {
            useMaxWidth: false,
            htmlLabels: false,
            direction: 'LR',
            nodeSpacing: 50,
            rankSpacing: 60,
            padding: 12
        }
    });
    var el = document.querySelector('.mermaid-workflow-horizontal');
    if (el) {
        mermaid.run({ querySelector: '.mermaid-workflow-horizontal', suppressErrors: true }).then(function() {
            var wrap = document.querySelector('.workflow-flowchart-wrap');
            if (!wrap || !window.WORKFLOW_STATUS_EXPLANATIONS) return;
            wrap.addEventListener('click', function(e) {
                var g = e.target.closest('g.node') || e.target.closest('g[id*="s"]');
                if (!g) return;
                var id = (g.id || '').toString();
                var m = id.match(/(s\d+)/);
                var nodeId = m ? m[1] : null;
                if (!nodeId || !window.WORKFLOW_STATUS_EXPLANATIONS[nodeId]) return;
                var d = window.WORKFLOW_STATUS_EXPLANATIONS[nodeId];
                var modal = document.getElementById('workflowStatusModal');
                var titleEl = document.getElementById('workflowStatusModalTitle');
                var bodyEl = document.getElementById('workflowStatusModalBody');
                if (modal && titleEl && bodyEl) {
                    titleEl.textContent = d.name;
                    bodyEl.textContent = d.explanation || '(No explanation maintained.)';
                    (typeof bootstrap !== 'undefined' && bootstrap.Modal) ? new bootstrap.Modal(modal).show() : modal.classList.add('show');
                }
            });
        }).catch(function() {});
    }
});
</script>
</body>
</html>
