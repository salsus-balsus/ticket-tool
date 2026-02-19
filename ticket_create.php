<?php
/**
 * ticket_create.php - New Issue / Create Ticket
 * Mirrors VBA "Create New Issue" form: Issue Number, Status New, Priority, Reporter (from app_users),
 * Object (objects table), Fix/Dev, Ext/Int (Ext=Customer Issue, Int=Internal Issue → type_id), System, Client, Short Description.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Ensure tickets.client_id exists (migration)
$hasClientId = false;
try {
    $pdo->exec("ALTER TABLE tickets ADD COLUMN client_id INT NULL");
    $hasClientId = true;
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $hasClientId = true;
    }
}
if (!$hasClientId) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'client_id'");
        $hasClientId = $stmt && $stmt->fetch();
    } catch (PDOException $e) {}
}

$errors = [];
$form = [
    'ticket_no' => '',
    'priority' => '',
    'reporter' => '',
    'object_ids' => [],
    'fix_dev_type' => 'Fix',
    'source' => 'Int',
    'system_id' => '',
    'client_id' => '',
    'title' => '',
    'detailed_desc' => '',
];

// AJAX: return clients for a system (JSON)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'clients' && isset($_GET['system_id'])) {
    header('Content-Type: application/json');
    $systemId = (int) $_GET['system_id'];
    if ($systemId <= 0) {
        echo json_encode([]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, client, description
            FROM clients
            WHERE system_id = ?
            ORDER BY client
        ");
        $stmt->execute([$systemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $label = trim($r['client'] ?? '');
            if (trim($r['description'] ?? '') !== '') {
                $label .= ' - ' . trim($r['description']);
            }
            $out[] = ['id' => (int) $r['id'], 'label' => $label];
        }
        echo json_encode($out);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

// POST: create ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['priority'] = trim($_POST['priority'] ?? '');
    $form['reporter'] = trim($_POST['reporter'] ?? '');
    $rawIds = isset($_POST['object_id']) ? (is_array($_POST['object_id']) ? $_POST['object_id'] : [$_POST['object_id']]) : [];
    $form['object_ids'] = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    $form['fix_dev_type'] = trim($_POST['fix_dev_type'] ?? '');
    $form['source'] = trim($_POST['source'] ?? '');
    $form['system_id'] = (int) ($_POST['system_id'] ?? 0);
    $form['client_id'] = (int) ($_POST['client_id'] ?? 0);
    $form['title'] = trim($_POST['title'] ?? '');
    $form['detailed_desc'] = trim($_POST['detailed_desc'] ?? '');

    if ($form['title'] === '') {
        $errors[] = 'Short Description is required.';
    }
    if ($form['reporter'] === '') {
        $errors[] = 'Reporter is required.';
    }

    if (empty($errors)) {
        try {
            // Resolve status "New"
            $stmt = $pdo->query("SELECT id FROM ticket_statuses WHERE name = 'New' LIMIT 1");
            $statusRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $statusId = $statusRow ? (int) $statusRow['id'] : 10;

            // Ext/Int → type_id (1=Customer/Ext, 2=Internal/Int)
            $typeId = null;
            if ($form['source'] === 'Ext') {
                $typeId = 1;
            } elseif ($form['source'] === 'Int') {
                $typeId = 2;
            }

            // Next ticket_no
            $stmt = $pdo->query("SELECT COALESCE(MAX(ticket_no), 0) + 1 AS next_no FROM tickets");
            $nextNo = (int) $stmt->fetchColumn();

            // Validate client belongs to system if both set
            if ($form['client_id'] > 0 && $form['system_id'] > 0) {
                $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND system_id = ?");
                $stmt->execute([$form['client_id'], $form['system_id']]);
                if (!$stmt->fetch()) {
                    $form['client_id'] = 0;
                }
            }

            $firstObjectId = !empty($form['object_ids']) ? (int) $form['object_ids'][0] : null;
            if ($hasClientId) {
                $stmt = $pdo->prepare("
                    INSERT INTO tickets (
                        title, detailed_desc, priority, status_id, type_id, object_id, created_by,
                        fix_dev_type, source, ticket_no, client_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $form['title'],
                    $form['detailed_desc'] !== '' ? $form['detailed_desc'] : null,
                    $form['priority'] !== '' ? $form['priority'] : null,
                    $statusId,
                    $typeId,
                    $firstObjectId,
                    $form['reporter'],
                    $form['fix_dev_type'] !== '' ? $form['fix_dev_type'] : null,
                    in_array($form['source'], ['Ext', 'Int'], true) ? $form['source'] : null,
                    $nextNo,
                    $form['client_id'] > 0 ? $form['client_id'] : null,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO tickets (
                        title, detailed_desc, priority, status_id, type_id, object_id, created_by,
                        fix_dev_type, source, ticket_no
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $form['title'],
                    $form['detailed_desc'] !== '' ? $form['detailed_desc'] : null,
                    $form['priority'] !== '' ? $form['priority'] : null,
                    $statusId,
                    $typeId,
                    $firstObjectId,
                    $form['reporter'],
                    $form['fix_dev_type'] !== '' ? $form['fix_dev_type'] : null,
                    in_array($form['source'], ['Ext', 'Int'], true) ? $form['source'] : null,
                    $nextNo,
                ]);
            }
            $newId = (int) $pdo->lastInsertId();
            if (!empty($form['object_ids'])) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_objects (ticket_id INT NOT NULL, object_id INT NOT NULL, PRIMARY KEY (ticket_id, object_id), FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE, FOREIGN KEY (object_id) REFERENCES objects(id) ON DELETE CASCADE)");
                    $st = $pdo->prepare("INSERT IGNORE INTO ticket_objects (ticket_id, object_id) VALUES (?, ?)");
                    foreach ($form['object_ids'] as $oid) {
                        if ($oid > 0) {
                            $st->execute([$newId, $oid]);
                        }
                    }
                } catch (PDOException $e) {
                    // schema may differ; tickets.object_id already set to first
                }
            }
            $msg = 'Issue created';

            // Word document from template (VBA-style: folder per issue, placeholder replacement)
            $templatePath = __DIR__ . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'template.docx';
            $outBaseDir = __DIR__ . DIRECTORY_SEPARATOR . 'out';
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';
            }
            require_once __DIR__ . '/includes/docx_export.php';
            if (function_exists('docx_generate_issue') && is_readable($templatePath)) {
                $systemName = '';
                $clientName = '';
                if ($form['system_id'] > 0) {
                    $st = $pdo->prepare("SELECT name FROM systems WHERE id = ?");
                    $st->execute([$form['system_id']]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $systemName = $row ? trim($row['name']) : '';
                }
                if ($form['client_id'] > 0) {
                    $st = $pdo->prepare("SELECT client, description FROM clients WHERE id = ?");
                    $st->execute([$form['client_id']]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $clientName = trim($row['client']);
                        if (trim($row['description'] ?? '') !== '') {
                            $clientName .= ' - ' . trim($row['description']);
                        }
                    }
                }
                $objectTypeName = '';
                if (!empty($form['object_ids'])) {
                    $st = $pdo->prepare("SELECT name FROM objects WHERE id IN (" . implode(',', array_fill(0, count($form['object_ids']), '?')) . ") ORDER BY name");
                    $st->execute($form['object_ids']);
                    $objectTypeName = implode(', ', array_map(function($r) { return trim($r['name'] ?? ''); }, $st->fetchAll(PDO::FETCH_ASSOC)));
                }
                $paths = docx_issue_paths($outBaseDir, $nextNo, $systemName, $clientName, $objectTypeName, $form['title']);
                $saved = docx_generate_issue($templatePath, $paths['path'], [
                    'IssueNum'  => (string) $nextNo,
                    'ShortDesc' => $form['title'],
                    'Priority'  => $form['priority'],
                    'System'    => $systemName,
                    'Client'    => $clientName,
                    'Reporter'  => $form['reporter'],
                    'ObjectType'=> $objectTypeName,
                    'FixDev'    => $form['fix_dev_type'],
                ]);
                if ($saved !== null) {
                    $msg = 'Issue created and document generated';
                }
            }

            header('Location: ticket_detail.php?id=' . $newId . '&msg=' . urlencode($msg));
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// GET: show form and load lookups
$systems = [];
$clientsBySystem = [];
$statusNewId = 10;
$nextTicketNo = 1;
$objects = [];
$priorities = [];
$reporterUsers = [];
$reporterDefault = get_effective_user() ?: '';

try {
    $systems = $pdo->query("SELECT id, name FROM systems ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT id, system_id, client, description FROM clients ORDER BY system_id, client");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid = (int) $row['system_id'];
        if (!isset($clientsBySystem[$sid])) {
            $clientsBySystem[$sid] = [];
        }
        $label = trim($row['client'] ?? '');
        if (trim($row['description'] ?? '') !== '') {
            $label .= ' - ' . trim($row['description']);
        }
        $clientsBySystem[$sid][] = ['id' => (int) $row['id'], 'label' => $label];
    }

    $row = $pdo->query("SELECT id FROM ticket_statuses WHERE name = 'New' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $statusNewId = (int) $row['id'];
    }
    $nextTicketNo = (int) $pdo->query("SELECT COALESCE(MAX(ticket_no), 0) + 1 FROM tickets")->fetchColumn();
    $objects = $pdo->query("SELECT id, name FROM objects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT username,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), username) AS display_name
        FROM app_users
        ORDER BY first_name, last_name, username
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $reporterUsers[] = $row;
        if (($effUser = get_effective_user()) !== '' && strcasecmp($row['username'], $effUser) === 0) {
            $reporterDefault = $row['display_name'];
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $form['reporter'] === '') {
        $form['reporter'] = $reporterDefault;
    }

    $prioRows = $pdo->query("SELECT DISTINCT priority FROM tickets WHERE priority IS NOT NULL AND TRIM(priority) != '' ORDER BY priority")->fetchAll(PDO::FETCH_COLUMN);
    $priorities = array_merge(['High', 'Medium', 'Low'], $prioRows);
    $priorities = array_unique($priorities);
    sort($priorities);
} catch (PDOException $e) {
    $errors[] = 'Failed to load form data: ' . $e->getMessage();
}

// Prevent caching so that "Back" after creating a ticket refetches the form with updated issue number and initial values
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['ajax'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$pageTitle = 'New Issue';
require_once 'includes/header.php';
?>
<link href="assets/css/tom-select.css" rel="stylesheet"/>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container-xl">
            <div class="page-header d-print-none mb-4">
                <div>
                    <h1 class="page-title">New Issue</h1>
                    <div class="text-muted">Create a new ticket (mirrors Excel Create New Issue)</div>
                </div>
                <div class="ms-auto">
                    <a href="tickets.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3">
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="post" action="ticket_create.php" id="form-new-issue">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Issue Number</label>
                                <input type="text" class="form-control" value="<?= e((string) $nextTicketNo) ?>" readonly disabled aria-label="Issue Number"/>
                                <input type="hidden" name="ticket_no_display" value="<?= (int) $nextTicketNo ?>"/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <input type="text" class="form-control" value="New" readonly disabled aria-label="Status"/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Source</label>
                                <div class="d-flex align-items-center gap-2" style="height: 38px;">
                                    <label class="form-check form-check-inline mb-0 small">
                                        <input type="radio" name="source" value="Ext" class="form-check-input" <?= $form['source'] === 'Ext' ? 'checked' : '' ?> required>
                                        <span class="form-check-label">Ext (Customer Issue)</span>
                                    </label>
                                    <label class="form-check form-check-inline mb-0 small">
                                        <input type="radio" name="source" value="Int" class="form-check-input" <?= $form['source'] === 'Int' ? 'checked' : '' ?>>
                                        <span class="form-check-label">Int (Internal Issue)</span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <div class="d-flex align-items-center gap-2" style="height: 38px;">
                                    <label class="form-check form-check-inline mb-0 small">
                                        <input type="radio" name="fix_dev_type" value="Fix" class="form-check-input" <?= $form['fix_dev_type'] === 'Fix' ? 'checked' : '' ?> required>
                                        <span class="form-check-label">Fix</span>
                                    </label>
                                    <label class="form-check form-check-inline mb-0 small">
                                        <input type="radio" name="fix_dev_type" value="Dev" class="form-check-input" <?= $form['fix_dev_type'] === 'Dev' ? 'checked' : '' ?>>
                                        <span class="form-check-label">Dev</span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="">—</option>
                                    <?php foreach ($priorities as $p): ?>
                                        <option value="<?= e($p) ?>" <?= $form['priority'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reporter</label>
                                <select name="reporter" class="form-select" required>
                                    <?php if (empty($reporterUsers)): ?>
                                        <option value="<?= e($reporterDefault) ?>" selected><?= e($reporterDefault) ?></option>
                                    <?php else: ?>
                                        <?php foreach ($reporterUsers as $u): ?>
                                            <option value="<?= e($u['display_name']) ?>" <?= $form['reporter'] === $u['display_name'] ? 'selected' : '' ?>><?= e($u['display_name']) ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($form['reporter'] !== '' && !in_array($form['reporter'], array_column($reporterUsers, 'display_name'))): ?>
                                            <option value="<?= e($form['reporter']) ?>" selected><?= e($form['reporter']) ?></option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </select>
                                <small class="text-muted">Pre-filled with your login; editable via dropdown (maintained users).</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Object(s)</label>
                                <select name="object_id[]" id="objectSelect" class="form-select" multiple aria-label="Objects">
                                    <?php foreach ($objects as $ob): ?>
                                        <option value="<?= (int) $ob['id'] ?>" <?= in_array((int) $ob['id'], $form['object_ids'], true) ? 'selected' : '' ?>><?= e($ob['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">System</label>
                                <select name="system_id" id="system_id" class="form-select">
                                    <option value="">—</option>
                                    <?php foreach ($systems as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= (int) $form['system_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client</label>
                                <select name="client_id" id="client_id" class="form-select">
                                    <option value="">— Select system first —</option>
                                    <?php
                                    if ($form['system_id'] > 0 && isset($clientsBySystem[$form['system_id']])) {
                                        foreach ($clientsBySystem[$form['system_id']] as $c) {
                                            echo '<option value="' . (int) $c['id'] . '"' . ((int) $form['client_id'] === (int) $c['id'] ? ' selected' : '') . '>' . e($c['label']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Short Description</label>
                                <textarea name="title" class="form-control" rows="3" placeholder="Short description" required><?= e($form['title']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Goal / Details <span class="text-muted">(optional)</span></label>
                                <textarea name="detailed_desc" class="form-control" rows="2" placeholder="Optional goal or detailed description"><?= e($form['detailed_desc']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="create_issue" class="btn btn-primary">Create Issue</button>
                                <a href="tickets.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var systemSelect = document.getElementById('system_id');
    var clientSelect = document.getElementById('client_id');
    if (systemSelect && clientSelect) {
        var clientsBySystem = <?= json_encode($clientsBySystem) ?>;
        function updateClientOptions() {
            var systemId = systemSelect.value ? parseInt(systemSelect.value, 10) : 0;
            var options = '<option value="">—</option>';
            if (systemId && clientsBySystem[systemId]) {
                clientsBySystem[systemId].forEach(function(c) {
                    options += '<option value="' + c.id + '">' + (c.label || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '</option>';
                });
            } else {
                options = '<option value="">— Select system first —</option>';
            }
            clientSelect.innerHTML = options;
        }
        systemSelect.addEventListener('change', updateClientOptions);
        updateClientOptions();
    }

})();
</script>
<script src="assets/js/tom-select.complete.min.js"></script>
<script>
(function() {
    function initObjectSelect() {
        var objectSelect = document.getElementById('objectSelect');
        if (objectSelect && typeof TomSelect !== 'undefined') {
            new TomSelect('#objectSelect', {
                plugins: { remove_button: { title: 'Remove' } },
                placeholder: 'Type to search…'
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initObjectSelect);
    } else {
        initObjectSelect();
    }
})();
</script>
<?php require_once 'includes/footer.php'; ?>
