<?php
/**
 * tickets.php - Ticket List
 * Tabler layout, filters, data table with joins.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

// --- Filter params (GET) ---
// Object: single (legacy) or multiple (object[]=1&object[]=2)
$filterObjectIds = [];
if (isset($_GET['object_id'])) {
    $id = (int) $_GET['object_id'];
    if ($id > 0) $filterObjectIds[] = $id;
} elseif (isset($_GET['object'])) {
    if (is_array($_GET['object'])) {
        foreach ($_GET['object'] as $v) { $id = (int) $v; if ($id > 0) $filterObjectIds[] = $id; }
    } else {
        $id = (int) $_GET['object'];
        if ($id > 0) $filterObjectIds[] = $id;
    }
}
$filterObject = !empty($filterObjectIds) ? $filterObjectIds[0] : null; // legacy single for display
$filterObjectMode = isset($_GET['object_mode']) && $_GET['object_mode'] === 'and' ? 'and' : 'or';
// Status: support multiple selection (status[]=1&status[]=2 or legacy status=1)
$filterStatusIds = [];
if (isset($_GET['status'])) {
    if (is_array($_GET['status'])) {
        foreach ($_GET['status'] as $v) {
            $id = (int) $v;
            if ($id > 0) $filterStatusIds[] = $id;
        }
    } else {
        $id = (int) $_GET['status'];
        if ($id > 0) $filterStatusIds[] = $id;
    }
}
$filterSource  = isset($_GET['source']) ? trim((string) $_GET['source']) : '';  // Ext | Int
$filterFixDev  = isset($_GET['fix_dev']) ? trim((string) $_GET['fix_dev']) : ''; // Fix | Dev
// Reporter/Responsible: support reporter[]/responsible[] (array) or reporter=Frank,Mike (legacy)
$filterReporters    = [];
$filterResponsibles = [];
if (isset($_GET['reporter'])) {
    if (is_array($_GET['reporter'])) {
        $filterReporters = array_values(array_filter(array_map('trim', $_GET['reporter'])));
    } else {
        $filterReporters = array_values(array_filter(array_map('trim', explode(',', trim((string) $_GET['reporter'])))));
    }
}
if (isset($_GET['responsible'])) {
    if (is_array($_GET['responsible'])) {
        $filterResponsibles = array_values(array_filter(array_map('trim', $_GET['responsible'])));
    } else {
        $filterResponsibles = array_values(array_filter(array_map('trim', explode(',', trim((string) $_GET['responsible'])))));
    }
}
$filterReporterRaw   = implode(', ', $filterReporters);
$filterResponsibleRaw = implode(', ', $filterResponsibles);
$filterReporter     = $filterReporterRaw;
$filterResponsible  = $filterResponsibleRaw;
$filterReporterMode    = isset($_GET['reporter_mode']) && $_GET['reporter_mode'] === 'and' ? 'and' : 'or';
$filterResponsibleMode = isset($_GET['responsible_mode']) && $_GET['responsible_mode'] === 'and' ? 'and' : 'or';
// Priority: single (legacy) or multiple (priority[]=High&priority[]=Low)
$filterPriorities = [];
if (isset($_GET['priority'])) {
    if (is_array($_GET['priority'])) {
        foreach ($_GET['priority'] as $v) { $p = trim((string) $v); if ($p !== '') $filterPriorities[] = $p; }
    } else {
        $p = trim((string) $_GET['priority']);
        if ($p !== '') $filterPriorities[] = $p;
    }
}
$filterPriority = !empty($filterPriorities) ? $filterPriorities[0] : ''; // legacy for display
// Goal: multiple (goal[]=...)
$filterGoals = [];
if (isset($_GET['goal']) && is_array($_GET['goal'])) {
    foreach ($_GET['goal'] as $v) { $g = trim((string) $v); if ($g !== '') $filterGoals[] = $g; }
} elseif (isset($_GET['goal'])) {
    $g = trim((string) $_GET['goal']);
    if ($g !== '') $filterGoals[] = $g;
}
$searchQuery   = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$sortColumn    = isset($_GET['sort']) && in_array($_GET['sort'], ['id', 'source', 'type', 'working_prio', 'goal', 'priority'], true) ? $_GET['sort'] : 'id';
$sortOrder     = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

$statuses = [];
$goals = [];
$reporterNames = [];
$responsibleNames = [];
$types = [];
$tickets = [];
$dbError = null;

try {
    // Load filter options (no sector filter)
    $objects = $pdo->query("SELECT id, name, sector_id FROM objects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $statuses = $pdo->query("SELECT id, name, color_code FROM ticket_statuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $goals = $pdo->query("SELECT DISTINCT TRIM(goal) AS goal FROM tickets WHERE goal IS NOT NULL AND TRIM(goal) != '' ORDER BY goal")->fetchAll(PDO::FETCH_COLUMN);
    $reporterNames = $pdo->query("SELECT DISTINCT person_name FROM ticket_participants WHERE role = 'REP' AND TRIM(person_name) != '' ORDER BY person_name")->fetchAll(PDO::FETCH_COLUMN);
    $responsibleNames = $pdo->query("SELECT DISTINCT person_name FROM ticket_participants WHERE role = 'RES' AND TRIM(person_name) != '' ORDER BY person_name")->fetchAll(PDO::FETCH_COLUMN);

    // Build query: object via ticket_objects (N:M), people via ticket_participants (REP/RES)
    $sql = "
        SELECT
            t.id, t.title, t.priority, t.type_id, t.status_id,
            (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.role = 'REP') AS reporter,
            (SELECT GROUP_CONCAT(person_name ORDER BY person_name SEPARATOR ', ') FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.role = 'RES') AS responsible,
            t.source,
            t.fix_dev_type,
            t.working_prio,
            t.goal,
            t.ticket_no,
            t.affected_object_count,
            t.affected_object_note,
            tt.name AS type_name,
            tt.code AS type_code,
            ts.name AS status_name,
            ts.color_code AS status_color,
            o.name AS object_name,
            s.name AS sector_name,
            r.name AS role_name,
            (SELECT GROUP_CONCAT(o2.name ORDER BY o2.name SEPARATOR ', ') FROM ticket_objects to2 JOIN objects o2 ON to2.object_id = o2.id WHERE to2.ticket_id = t.id) AS object_names,
            (SELECT COUNT(*) FROM ticket_objects WHERE ticket_id = t.id) AS object_count
        FROM tickets t
        LEFT JOIN (SELECT ticket_id, MIN(object_id) AS object_id FROM ticket_objects GROUP BY ticket_id) to1 ON to1.ticket_id = t.id
        LEFT JOIN objects o ON o.id = to1.object_id
        LEFT JOIN sectors s ON o.sector_id = s.id
        LEFT JOIN ticket_statuses ts ON t.status_id = ts.id
        LEFT JOIN ticket_types tt ON t.type_id = tt.id
        LEFT JOIN roles r ON t.current_role_id = r.id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filterObjectIds)) {
        if ($filterObjectMode === 'and') {
            $sql .= " AND (SELECT COUNT(DISTINCT to_f.object_id) FROM ticket_objects to_f WHERE to_f.ticket_id = t.id AND to_f.object_id IN (" . implode(',', array_fill(0, count($filterObjectIds), '?')) . ") ) = " . count($filterObjectIds);
            $params = array_merge($params, $filterObjectIds);
        } else {
            $sql .= " AND EXISTS (SELECT 1 FROM ticket_objects to_f WHERE to_f.ticket_id = t.id AND to_f.object_id IN (" . implode(',', array_fill(0, count($filterObjectIds), '?')) . "))";
            $params = array_merge($params, $filterObjectIds);
        }
    }
    if (!empty($filterStatusIds)) {
        $sql .= " AND t.status_id IN (" . implode(',', array_fill(0, count($filterStatusIds), '?')) . ")";
        $params = array_merge($params, $filterStatusIds);
    }
    if ($filterSource !== '' && in_array($filterSource, ['Ext', 'Int'], true)) {
        $sql .= " AND (COALESCE(TRIM(t.source), (CASE WHEN t.type_id = 2 THEN 'Int' WHEN t.type_id = 1 THEN 'Ext' ELSE '' END)) = ?)";
        $params[] = $filterSource;
    }
    if ($filterFixDev !== '' && in_array($filterFixDev, ['Fix', 'Dev'], true)) {
        $sql .= " AND COALESCE(TRIM(t.fix_dev_type), '') = ?";
        $params[] = $filterFixDev;
    }
    if (!empty($filterReporters)) {
        $op = $filterReporterMode === 'and' ? ' AND ' : ' OR ';
        $placeholders = implode($op, array_fill(0, count($filterReporters), "EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.role = 'REP' AND TRIM(tp.person_name) = ?)"));
        $sql .= " AND ($placeholders)";
        foreach ($filterReporters as $r) { $params[] = $r; }
    }
    if (!empty($filterResponsibles)) {
        $op = $filterResponsibleMode === 'and' ? ' AND ' : ' OR ';
        $placeholders = implode($op, array_fill(0, count($filterResponsibles), "EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.role = 'RES' AND TRIM(tp.person_name) = ?)"));
        $sql .= " AND ($placeholders)";
        foreach ($filterResponsibles as $r) { $params[] = $r; }
    }
    if (!empty($filterPriorities)) {
        $sql .= " AND LOWER(TRIM(COALESCE(t.priority, ''))) IN (" . implode(',', array_fill(0, count($filterPriorities), 'LOWER(?)')) . ")";
        foreach ($filterPriorities as $p) { $params[] = $p; }
    }
    if (!empty($filterGoals)) {
        $sql .= " AND TRIM(COALESCE(t.goal, '')) IN (" . implode(',', array_fill(0, count($filterGoals), '?')) . ")";
        $params = array_merge($params, $filterGoals);
    }
    if ($searchQuery !== '') {
        $searchLike = '%' . $searchQuery . '%';
        $sql .= " AND (
            t.title LIKE ? OR t.goal LIKE ?
            OR EXISTS (SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id = t.id AND tp.person_name LIKE ?)
            OR o.name LIKE ? OR s.name LIKE ?
            OR CAST(t.ticket_no AS CHAR) LIKE ? OR CAST(t.id AS CHAR) = ?
        )";
        $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike;
        $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchQuery;
    }

    // Sortable: id, source, type (fix_dev), working_prio, goal, priority
    $sortMap = [
        'id' => 'COALESCE(t.ticket_no, t.id)',
        'source' => 'COALESCE(t.source, (CASE WHEN t.type_id = 2 THEN \'Int\' WHEN t.type_id = 1 THEN \'Ext\' ELSE \'\' END))',
        'type' => 'COALESCE(t.fix_dev_type, \'\')',
        'working_prio' => 'COALESCE(t.working_prio, -1)',
        'goal' => 'COALESCE(TRIM(t.goal), \'\')',
        'priority' => "FIELD(LOWER(COALESCE(t.priority, '')), 'very high', 'high', 'medium', 'low', '')"
    ];
    $sortExpr = $sortMap[$sortColumn] ?? $sortMap['id'];
    $sql .= " ORDER BY {$sortExpr} {$sortOrder}, t.id DESC LIMIT 100";

    if (empty($params)) {
        $stmt = $pdo->query($sql);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Build active filter chips (label + URL without that filter)
$activeFilters = [];
$buildParams = function($omitKey, $omitVal = null) use ($searchQuery, $filterObjectIds, $filterObjectMode, $filterStatusIds, $filterSource, $filterFixDev, $filterReporters, $filterResponsibles, $filterReporterMode, $filterResponsibleMode, $filterPriorities, $filterGoals) {
    $p = [];
    if ($omitKey !== 'q' && $searchQuery !== '') $p['q'] = $searchQuery;
    if ($omitKey !== 'object') {
        if (!empty($filterObjectIds)) {
            if ($omitVal !== null && $omitKey === 'object') {
                $rest = array_values(array_filter($filterObjectIds, function($id) use ($omitVal) { return (int)$id !== (int)$omitVal; }));
                if (!empty($rest)) { $p['object'] = $rest; if ($filterObjectMode === 'and') $p['object_mode'] = 'and'; }
            } else {
                $p['object'] = $filterObjectIds; if ($filterObjectMode === 'and') $p['object_mode'] = 'and';
            }
        }
    }
    if ($omitKey !== 'status') {
        if (!empty($filterStatusIds)) $p['status'] = $filterStatusIds;
    } elseif ($omitVal !== null) {
        $rest = array_values(array_filter($filterStatusIds, function($id) use ($omitVal) { return (int)$id !== (int)$omitVal; }));
        if (!empty($rest)) $p['status'] = $rest;
    }
    if ($omitKey !== 'source' && $filterSource !== '') $p['source'] = $filterSource;
    if ($omitKey !== 'fix_dev' && $filterFixDev !== '') $p['fix_dev'] = $filterFixDev;
    if ($omitKey !== 'reporter') {
        if (!empty($filterReporters)) {
            $p['reporter'] = $filterReporters;
            if ($filterReporterMode === 'and') $p['reporter_mode'] = 'and';
        }
    } elseif ($omitVal !== null) {
        $rest = array_values(array_filter($filterReporters, function($x) use ($omitVal) { return $x !== $omitVal; }));
        if (!empty($rest)) {
            $p['reporter'] = $rest;
            if ($filterReporterMode === 'and') $p['reporter_mode'] = 'and';
        }
    }
    if ($omitKey !== 'responsible') {
        if (!empty($filterResponsibles)) {
            $p['responsible'] = $filterResponsibles;
            if ($filterResponsibleMode === 'and') $p['responsible_mode'] = 'and';
        }
    } elseif ($omitVal !== null) {
        $rest = array_values(array_filter($filterResponsibles, function($x) use ($omitVal) { return $x !== $omitVal; }));
        if (!empty($rest)) {
            $p['responsible'] = $rest;
            if ($filterResponsibleMode === 'and') $p['responsible_mode'] = 'and';
        }
    }
    if ($omitKey !== 'priority' && !empty($filterPriorities)) $p['priority'] = $filterPriorities;
    if ($omitKey !== 'goal' && !empty($filterGoals)) $p['goal'] = $filterGoals;
    return $p;
};
if ($searchQuery !== '') {
    $q = $buildParams('q');
    $activeFilters[] = ['label' => 'Search: ' . $searchQuery, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : '')];
}
foreach ($filterObjectIds as $oid) {
    $objName = 'Object';
    foreach ($objects as $o) { if ((int)$o['id'] === (int)$oid) { $objName = $o['name']; break; } }
    $q = $buildParams('object', $oid);
    $activeFilters[] = ['label' => $objName, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : '')];
}
foreach ($filterStatusIds as $sid) {
    $stName = 'Status';
    $stColor = null;
    foreach ($statuses as $s) { if ((int)$s['id'] === (int)$sid) { $stName = $s['name']; $stColor = $s['color_code'] ?? null; break; } }
    $q = $buildParams('status', $sid);
    $chip = ['label' => $stName, 'url' => 'tickets.php' . (empty($q) ? '' : '?' . http_build_query($q))];
    if ($stColor !== null && $stColor !== '') {
        if (strpos($stColor, '#') === 0) {
            $chip['badge_style'] = 'background-color:' . $stColor . '20;color:' . $stColor;
            $chip['badge_class'] = 'badge';
        } else {
            $chip['badge_class'] = 'badge bg-' . preg_replace('/[^a-z0-9_-]/i', '', $stColor) . '-lt';
        }
    }
    $activeFilters[] = $chip;
}
foreach ($filterPriorities as $pr) {
    $rest = array_values(array_filter($filterPriorities, function($x) use ($pr) { return $x !== $pr; }));
    $q = $rest ? array_merge($buildParams('priority'), ['priority' => $rest]) : $buildParams('priority');
    $pClass = priority_class($pr);
    $pBadge = ($pClass === 'text-danger' ? 'bg-red-lt' : ($pClass === 'text-orange' ? 'bg-orange-lt' : ($pClass === 'text-warning' ? 'bg-yellow-lt' : 'bg-secondary-lt')));
    $activeFilters[] = ['label' => 'Priority: ' . $pr, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : ''), 'badge_class' => 'badge ' . $pBadge];
}
foreach ($filterGoals as $g) {
    $rest = array_values(array_filter($filterGoals, function($x) use ($g) { return $x !== $g; }));
    $q = $rest ? array_merge($buildParams('goal'), ['goal' => $rest]) : $buildParams('goal');
    $activeFilters[] = ['label' => 'Goal: ' . (mb_strlen($g) > 30 ? mb_substr($g, 0, 27) . '…' : $g), 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : '')];
}
if ($filterSource !== '') {
    $q = $buildParams('source');
    $activeFilters[] = ['label' => 'Source: ' . $filterSource, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : ''), 'badge_class' => 'badge ' . source_badge_class($filterSource)];
}
if ($filterFixDev !== '') {
    $q = $buildParams('fix_dev');
    $activeFilters[] = ['label' => 'Type: ' . $filterFixDev, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : ''), 'badge_class' => 'badge ' . fix_dev_badge_class($filterFixDev)];
}
foreach ($filterReporters as $r) {
    $q = $buildParams('reporter', $r);
    $activeFilters[] = ['label' => 'Reporter: ' . $r, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : '')];
}
foreach ($filterResponsibles as $resp) {
    $q = $buildParams('responsible', $resp);
    $activeFilters[] = ['label' => 'Responsible: ' . $resp, 'url' => 'tickets.php' . ($q ? '?' . http_build_query($q) : '')];
}

/**
 * Map priority to Tabler color class
 */
function priority_class($priority) {
    $p = strtolower(trim($priority ?? ''));
    if (strpos($p, 'high') !== false && strpos($p, 'very') !== false) return 'text-danger';
    if (strpos($p, 'high') !== false) return 'text-orange';
    if (strpos($p, 'medium') !== false) return 'text-warning';
    if (strpos($p, 'low') !== false) return 'text-muted';
    return 'text-secondary';
}

/**
 * Source badge: INT=yellow, EXT=red
 */
function source_badge_class($val) {
    $c = strtoupper(trim($val ?? ''));
    if ($c === 'INT') return 'bg-yellow-lt';
    if ($c === 'EXT') return 'bg-red-lt';
    return 'bg-secondary-lt';
}

/**
 * Type badge (Fix/Dev): DEV=purple, FIX=orange
 */
function fix_dev_badge_class($val) {
    $c = strtoupper(trim($val ?? ''));
    if ($c === 'DEV') return 'bg-purple-lt';
    if ($c === 'FIX') return 'bg-orange-lt';
    return 'bg-secondary-lt';
}
$pageTitle = 'Tickets';
require_once 'includes/header.php';
?>
<link href="assets/css/tom-select.css" rel="stylesheet"/>
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($dbError): ?>
                    <div class="alert alert-danger">Database error: <?= e($dbError) ?></div>
                    <?php endif; ?>
                    <?php if (isset($_GET['randomized']) && (int) $_GET['randomized'] === 1): ?>
                    <div class="alert alert-success alert-dismissible" role="alert">
                        Die Stati aller Tickets wurden zufällig verteilt.<?= isset($_GET['count']) ? ' ' . (int) $_GET['count'] . ' Ticket(s) aktualisiert.' : '' ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <div class="page-header d-print-none mb-4">
                        <div class="d-flex flex-wrap align-items-center gap-3 w-100">
                            <div>
                                <h1 class="page-title">Tickets</h1>
                                <div class="text-muted">Filter and browse all tickets</div>
                            </div>
                            <div class="d-flex flex-grow-1 justify-content-center">
                                <form method="get" action="tickets.php" class="input-group flex-grow-1" style="min-width:320px;max-width:420px;">
                                    <?php
                                    $headerHidden = array_filter([
                                        'object' => !empty($filterObjectIds) ? $filterObjectIds : null,
                                        'status' => !empty($filterStatusIds) ? $filterStatusIds : null,
                                        'source' => $filterSource ?: null, 'fix_dev' => $filterFixDev ?: null,
                                        'reporter' => !empty($filterReporters) ? $filterReporters : null,
                                        'responsible' => !empty($filterResponsibles) ? $filterResponsibles : null,
                                        'reporter_mode' => $filterReporterMode === 'and' ? 'and' : null, 'responsible_mode' => $filterResponsibleMode === 'and' ? 'and' : null,
                                        'object_mode' => $filterObjectMode === 'and' ? 'and' : null,
                                        'priority' => !empty($filterPriorities) ? $filterPriorities : null,
                                        'goal' => !empty($filterGoals) ? $filterGoals : null
                                    ], function($v) { return $v !== null && $v !== '' && $v !== []; });
                                    if (!empty($headerHidden['status'])) {
                                        foreach ($headerHidden['status'] as $sid) { echo '<input type="hidden" name="status[]" value="' . (int)$sid . '">'; }
                                        unset($headerHidden['status']);
                                    }
                                    if (!empty($headerHidden['object'])) {
                                        foreach ($headerHidden['object'] as $oid) { echo '<input type="hidden" name="object[]" value="' . (int)$oid . '">'; }
                                        unset($headerHidden['object']);
                                    }
                                    if (!empty($headerHidden['priority'])) {
                                        foreach ($headerHidden['priority'] as $pv) { echo '<input type="hidden" name="priority[]" value="' . e($pv) . '">'; }
                                        unset($headerHidden['priority']);
                                    }
                                    if (!empty($headerHidden['goal'])) {
                                        foreach ($headerHidden['goal'] as $gv) { echo '<input type="hidden" name="goal[]" value="' . e($gv) . '">'; }
                                        unset($headerHidden['goal']);
                                    }
                                    if (!empty($headerHidden['reporter'])) {
                                        foreach ($headerHidden['reporter'] as $rv) { echo '<input type="hidden" name="reporter[]" value="' . e($rv) . '">'; }
                                        unset($headerHidden['reporter']);
                                    }
                                    if (!empty($headerHidden['responsible'])) {
                                        foreach ($headerHidden['responsible'] as $rv) { echo '<input type="hidden" name="responsible[]" value="' . e($rv) . '">'; }
                                        unset($headerHidden['responsible']);
                                    }
                                    foreach ($headerHidden as $k => $v) {
                                        if ($v === null || $v === '') continue;
                                        if (is_array($v)) { foreach ($v as $vv) echo '<input type="hidden" name="' . e($k) . '[]" value="' . e($vv) . '">'; }
                                        else echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
                                    }
                                    ?>
                                    <input type="search" name="q" class="form-control border-end-0" placeholder="Title, Object, Reporter…" value="<?= e($searchQuery) ?>" aria-label="Search" style="min-width:200px;"/>
                                    <span class="input-group-text bg-transparent border-start-0"><svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></svg></span>
                                </form>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <a href="randomize_ticket_statuses.php" class="btn btn-outline-info">Stati randomisieren</a>
                                <a href="import_tickets.php" class="btn btn-outline-warning">Import Tickets</a>
                                <a href="ticket_create.php" class="btn btn-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                                    New Ticket
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="get" action="tickets.php" id="filterForm">
                                <input type="hidden" name="q" value="<?= e($searchQuery) ?>"/>
                                
                                <div class="d-flex flex-column gap-3">
                                    
                                    <div class="row g-3 align-items-end">
                                        <div class="col-auto" style="width: 240px; flex-shrink: 0;">
                                            <label class="form-label">Source</label>
                                            <div class="d-flex align-items-center gap-2" style="height: 38px;">
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="source" value="" class="form-check-input" <?= $filterSource === '' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">All</span>
                                                </label>
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="source" value="Ext" class="form-check-input" <?= $filterSource === 'Ext' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Ext</span>
                                                </label>
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="source" value="Int" class="form-check-input" <?= $filterSource === 'Int' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Int</span>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <label class="form-label">Status</label>
                                            <select name="status[]" id="filterStatus" multiple style="min-width:160px;">
                                                <?php foreach ($statuses as $st): ?>
                                                <option value="<?= (int)$st['id'] ?>" data-color="<?= e($st['color_code'] ?? '') ?>" <?= in_array((int)$st['id'], $filterStatusIds, true) ? 'selected' : '' ?>><?= e($st['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <label class="form-label">Priority</label>
                                            <select name="priority[]" id="filterPriority" multiple style="min-width:120px;">
                                                <option value="Very High" data-badge="bg-red-lt" <?= in_array('Very High', $filterPriorities, true) ? 'selected' : '' ?>>Very High</option>
                                                <option value="High" data-badge="bg-orange-lt" <?= in_array('High', $filterPriorities, true) ? 'selected' : '' ?>>High</option>
                                                <option value="Medium" data-badge="bg-yellow-lt" <?= in_array('Medium', $filterPriorities, true) ? 'selected' : '' ?>>Medium</option>
                                                <option value="Low" data-badge="bg-secondary-lt" <?= in_array('Low', $filterPriorities, true) ? 'selected' : '' ?>>Low</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <label class="form-label">Goal</label>
                                            <select name="goal[]" id="filterGoal" multiple style="min-width:180px;">
                                                <?php foreach ($goals as $g): ?>
                                                <option value="<?= e($g) ?>" <?= in_array($g, $filterGoals, true) ? 'selected' : '' ?>><?= e(mb_strlen($g) > 50 ? mb_substr($g, 0, 47) . '…' : $g) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-3 align-items-end">
                                        <div class="col-auto" style="width: 240px; flex-shrink: 0;">
                                            <label class="form-label">Type</label>
                                            <div class="d-flex align-items-center gap-2" style="height: 38px;">
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="fix_dev" value="" class="form-check-input" <?= $filterFixDev === '' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">All</span>
                                                </label>
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="fix_dev" value="Fix" class="form-check-input" <?= $filterFixDev === 'Fix' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Fix</span>
                                                </label>
                                                <label class="form-check form-check-inline mb-0 small">
                                                    <input type="radio" name="fix_dev" value="Dev" class="form-check-input" <?= $filterFixDev === 'Dev' ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Dev</span>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <label class="form-label mb-0">Object</label>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Object match mode">
                                                    <input type="radio" class="btn-check" name="object_mode" value="or" id="object_mode_or" <?= $filterObjectMode === 'or' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="object_mode_or" style="font-size: 0.65rem; line-height: 1;">OR</label>
                                                    <input type="radio" class="btn-check" name="object_mode" value="and" id="object_mode_and" <?= $filterObjectMode === 'and' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="object_mode_and" style="font-size: 0.65rem; line-height: 1;">AND</label>
                                                </div>
                                            </div>
                                            <select name="object[]" id="filterObject" multiple style="min-width:200px;">
                                                <?php foreach ($objects as $ob): ?>
                                                <option value="<?= (int)$ob['id'] ?>" <?= in_array((int)$ob['id'], $filterObjectIds, true) ? 'selected' : '' ?>><?= e($ob['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <label class="form-label mb-0">Reporter</label>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Reporter match mode">
                                                    <input type="radio" class="btn-check" name="reporter_mode" value="or" id="reporter_mode_or" <?= $filterReporterMode === 'or' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="reporter_mode_or" style="font-size: 0.65rem; line-height: 1;">OR</label>
                                                    <input type="radio" class="btn-check" name="reporter_mode" value="and" id="reporter_mode_and" <?= $filterReporterMode === 'and' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="reporter_mode_and" style="font-size: 0.65rem; line-height: 1;">AND</label>
                                                </div>
                                            </div>
                                            <select name="reporter[]" id="filterReporter" multiple style="min-width:160px;">
                                                <?php
                                                $reporterOpts = array_unique(array_merge($reporterNames, $filterReporters));
                                                foreach ($reporterOpts as $rn):
                                                    $sel = in_array($rn, $filterReporters, true);
                                                ?><option value="<?= e($rn) ?>" <?= $sel ? 'selected' : '' ?>><?= e($rn) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <label class="form-label mb-0">Responsible</label>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Responsible match mode">
                                                    <input type="radio" class="btn-check" name="responsible_mode" value="or" id="responsible_mode_or" <?= $filterResponsibleMode === 'or' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="responsible_mode_or" style="font-size: 0.65rem; line-height: 1;">OR</label>
                                                    <input type="radio" class="btn-check" name="responsible_mode" value="and" id="responsible_mode_and" <?= $filterResponsibleMode === 'and' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary py-0 px-1 small" for="responsible_mode_and" style="font-size: 0.65rem; line-height: 1;">AND</label>
                                                </div>
                                            </div>
                                            <select name="responsible[]" id="filterResponsible" multiple style="min-width:160px;">
                                                <?php
                                                $responsibleOpts = array_unique(array_merge($responsibleNames, $filterResponsibles));
                                                foreach ($responsibleOpts as $rn):
                                                    $sel = in_array($rn, $filterResponsibles, true);
                                                ?><option value="<?= e($rn) ?>" <?= $sel ? 'selected' : '' ?>><?= e($rn) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-auto">
                                            <div class="d-flex gap-2" style="height: 38px; align-items: center;">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Apply</button>
                                                <a href="tickets.php" class="btn btn-outline-danger btn-sm">Reset Filter</a>
                                            </div>
                                        </div>
                                        
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (!empty($activeFilters)): ?>
                        <div class="card-body border-top pt-2 pb-2">
                            <div class="d-flex flex-wrap align-items-center gap-1">
                                <?php foreach ($activeFilters as $af): ?>
                                <a href="<?= e($af['url']) ?>" class="<?= $af['badge_class'] ?? 'badge bg-secondary-lt text-secondary' ?> text-decoration-none d-inline-flex align-items-center gap-1" <?= !empty($af['badge_style']) ? ' style="' . e($af['badge_style']) . '"' : '' ?> title="Remove this filter">
                                    <?= e($af['label']) ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <?php if (empty($tickets)): ?>
                        <div class="empty">
                            <div class="empty-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="128" height="128" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /></svg>
                            </div>
                            <p class="empty-title">No tickets found</p>
                            <p class="empty-subtitle">Try adjusting your filters or create a new ticket.</p>
                            <div class="empty-action">
                                <a href="tickets.php" class="btn btn-primary">Reset Filters</a>
                                <a href="ticket_create.php" class="btn btn-outline-primary">New Ticket</a>
                            </div>
                        </div>
                        <?php else: ?>
                        <?php
                        $baseParams = array_filter([
                            'q' => $searchQuery ?: null, 'object' => !empty($filterObjectIds) ? $filterObjectIds : null,
                            'status' => !empty($filterStatusIds) ? $filterStatusIds : null, 'source' => $filterSource ?: null, 'fix_dev' => $filterFixDev ?: null,
                            'reporter' => !empty($filterReporters) ? $filterReporters : null, 'responsible' => !empty($filterResponsibles) ? $filterResponsibles : null,
                            'reporter_mode' => $filterReporterMode === 'and' ? 'and' : null, 'responsible_mode' => $filterResponsibleMode === 'and' ? 'and' : null,
                            'object_mode' => $filterObjectMode === 'and' ? 'and' : null,
                            'priority' => !empty($filterPriorities) ? $filterPriorities : null, 'goal' => !empty($filterGoals) ? $filterGoals : null
                        ], function($v) { return $v !== null && $v !== '' && $v !== []; });
                        $baseUrl = 'tickets.php' . (empty($baseParams) ? '' : '?' . http_build_query($baseParams));
                        $sortLink = function($col, $label) use ($baseUrl, $sortColumn, $sortOrder) {
                            $newOrder = ($sortColumn === $col && $sortOrder === 'DESC') ? 'asc' : 'desc';
                            $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
                            $url = $baseUrl . $sep . 'sort=' . $col . '&order=' . $newOrder;
                            $arrow = $sortColumn === $col ? ($sortOrder === 'ASC' ? ' ↑' : ' ↓') : '';
                            return '<a href="' . e($url) . '" class="text-reset text-decoration-none">' . e($label) . $arrow . '</a>';
                        };
                        ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table table-striped">
                                <thead>
                                    <tr>
                                        <th style="width:70px;"><?= $sortLink('id', 'ID') ?></th>
                                        <th style="width:60px;"><?= $sortLink('source', 'Ext/Int') ?></th>
                                        <th style="width:60px;"><?= $sortLink('type', 'Fix/Dev') ?></th>
                                        <th style="width:80px;"><?= $sortLink('working_prio', 'Working Prio') ?></th>
                                        <th style="min-width:120px;"><?= $sortLink('goal', 'Goal') ?></th>
                                        <th>Object</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Reporter</th>
                                        <th>Responsible</th>
                                        <th style="width:100px;"><?= $sortLink('priority', 'Priority') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tickets as $row): ?>
                                    <?php $srcVal = $row['source'] ?? ($row['type_id'] == 2 ? 'Int' : ($row['type_id'] == 1 ? 'Ext' : ($row['type_code'] ?? '-'))); ?>
                                    <?php $typeVal = $row['fix_dev_type'] ?? $row['type_code'] ?? '-'; ?>
                                    <tr>
                                        <td>
                                            <a href="ticket_detail.php?id=<?= (int) $row['id'] ?>" class="fw-bold"><?= (int) ($row['ticket_no'] ?? $row['id']) ?></a>
                                        </td>
                                        <td><span class="badge <?= source_badge_class($srcVal) ?>"><?= e($srcVal) ?></span></td>
                                        <td><span class="badge <?= fix_dev_badge_class($typeVal) ?>"><?= e($typeVal) ?></span></td>
                                        <td><?= $row['working_prio'] !== null && $row['working_prio'] !== '' ? (int)$row['working_prio'] : '—' ?></td>
                                        <td class="text-muted" style="max-width:180px;"><?= (e(mb_substr($row['goal'] ?? '', 0, 60)) ?: '—') . (mb_strlen($row['goal'] ?? '') > 60 ? '…' : '') ?></td>
                                        <td>
                                            <?php
                                            $objCnt = (int)($row['object_count'] ?? 0);
                                            $affCnt = $row['affected_object_count'] ?? null;
                                            $affNote = $row['affected_object_note'] ?? '';
                                            if ($affCnt !== null && $affCnt !== ''): ?>
                                                <?= e($affCnt == 0 ? 'all object types' : (int)$affCnt . ' object types') ?>
                                            <?php elseif ($objCnt > 1): ?>
                                                <?= e($row['object_names'] ?? (int)$objCnt . ' objects') ?>
                                            <?php else: ?>
                                                <?= e($row['object_names'] ?? $row['object_name'] ?? $affNote ?: '-') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted" style="max-width:200px;"><?= e(mb_substr($row['title'] ?? '', 0, 80)) ?><?= mb_strlen($row['title'] ?? '') > 80 ? '…' : '' ?></td>
                                        <td><?= render_status_badge($row['status_name'] ?? '-', $row['status_color'] ?? null) ?></td>
                                        <td><?= e($row['reporter'] ?? '-') ?></td>
                                        <td><?php
                                            $respList = array_filter(array_map('trim', explode(',', $row['responsible'] ?? '')));
                                            if (empty($respList)) { echo '—'; }
                                            elseif (count($respList) <= 2) { echo e(implode(', ', $respList)); }
                                            else { echo e(implode(', ', array_slice($respList, 0, 2))) . ' <span class="text-muted">+' . (count($respList) - 2) . '</span>'; }
                                        ?></td>
                                        <td><span class="<?= priority_class($row['priority']) ?>"><?= e($row['priority'] ?? '-') ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            <p class="m-0 text-muted">
                                Showing <?= count($tickets) ?> tickets (max 100)
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once 'includes/footer.php'; ?>
<script src="assets/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var baseOpts = {
        plugins: { remove_button: { title: 'Remove' } },
        placeholder: '—'
    };
    var selStatus = document.getElementById('filterStatus');
    if (selStatus) {
        var statusColorMap = {};
        [].slice.call(selStatus.querySelectorAll('option')).forEach(function(o) {
            statusColorMap[o.value] = o.getAttribute('data-color') || '';
        });
        new TomSelect('#filterStatus', Object.assign({}, baseOpts, {
            placeholder: 'All statuses',
            render: {
                item: function(item, escape) {
                    var color = statusColorMap[item.value];
                    var style = '', cls = 'badge';
                    if (color && color.indexOf('#') === 0) {
                        style = 'background-color:' + color + '20;color:' + color;
                    } else if (color) {
                        cls = 'badge bg-' + color.replace(/[^a-z0-9_-]/gi, '') + '-lt';
                    } else {
                        cls = 'badge bg-secondary-lt';
                    }
                    return '<span class="' + cls + '"' + (style ? ' style="' + style + '"' : '') + '>' + escape(item.text) + '</span>';
                },
                option: function(item, escape) {
                    var color = statusColorMap[item.value];
                    var style = '', cls = 'badge';
                    if (color && color.indexOf('#') === 0) {
                        style = 'background-color:' + color + '20;color:' + color;
                    } else if (color) {
                        cls = 'badge bg-' + color.replace(/[^a-z0-9_-]/gi, '') + '-lt';
                    } else {
                        cls = 'badge bg-secondary-lt';
                    }
                    // Wrap the option in a div with py-1 to force block layout and add vertical spacing
                    return '<div class="py-1"><span class="' + cls + '"' + (style ? ' style="' + style + '"' : '') + '>' + escape(item.text) + '</span></div>';
                }
            }
        }));
    }
    var selPriority = document.getElementById('filterPriority');
    if (selPriority) {
        var priorityBadgeMap = {};
        [].slice.call(selPriority.querySelectorAll('option')).forEach(function(o) {
            priorityBadgeMap[o.value] = o.getAttribute('data-badge') || 'bg-secondary-lt';
        });
        new TomSelect('#filterPriority', Object.assign({}, baseOpts, {
            placeholder: 'All',
            render: {
                item: function(item, escape) {
                    var cls = 'badge ' + (priorityBadgeMap[item.value] || 'bg-secondary-lt');
                    return '<span class="' + cls + '">' + escape(item.text) + '</span>';
                },
                option: function(item, escape) {
                    var cls = 'badge ' + (priorityBadgeMap[item.value] || 'bg-secondary-lt');
                    // Wrap the option in a div with py-1 to force block layout and add vertical spacing
                    return '<div class="py-1"><span class="' + cls + '">' + escape(item.text) + '</span></div>';
                }
            }
        }));
    }
    if (document.getElementById('filterObject')) {
        new TomSelect('#filterObject', Object.assign({}, baseOpts, { placeholder: 'Type to search…' }));
    }
    if (document.getElementById('filterReporter')) {
        new TomSelect('#filterReporter', Object.assign({}, baseOpts, { placeholder: 'Name…', create: true }));
    }
    if (document.getElementById('filterResponsible')) {
        new TomSelect('#filterResponsible', Object.assign({}, baseOpts, { placeholder: 'Name…', create: true }));
    }
    if (document.getElementById('filterGoal')) {
        new TomSelect('#filterGoal', Object.assign({}, baseOpts, { placeholder: 'All goals' }));
    }
});
</script>
</body>
</html>