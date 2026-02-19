<?php
require 'includes/config.php';
require 'includes/functions.php';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM workflow_transitions WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
    }
    if (isset($_POST['add_transition'])) {
        $flowType = (int) $_POST['flow_type_id'];
        $btnLabel = trim($_POST['button_label'] ?? '');
        $lb = strtolower($btnLabel);
        $edgeType = 'normal';
        if (preg_match('/failed|loop|return|reject/i', $lb)) $edgeType = 'fallback';
        elseif (preg_match('/confirmed|confirm|validated|ok|created/i', $lb)) $edgeType = 'success';
        $stmt = $pdo->prepare("INSERT INTO workflow_transitions (flow_type_id, current_status_id, next_status_id, allowed_role_id, target_owner_role_id, button_label, edge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$flowType, (int)$_POST['from_status_id'], (int)$_POST['next_status_id'], (int)$_POST['allowed_role_id'], (int)$_POST['target_owner_role_id'], $btnLabel, $edgeType]);
    }
    if (isset($_POST['save_transitions'])) {
        $fromIds = $_POST['current_status_id'] ?? [];
        $toIds = $_POST['next_status_id'] ?? [];
        $allowedRoleIds = $_POST['allowed_role_id'] ?? [];
        $buttonLabels = $_POST['button_label'] ?? [];
        $targetRoleIds = $_POST['target_owner_role_id'] ?? [];
        $stmt = $pdo->prepare("UPDATE workflow_transitions SET current_status_id = ?, next_status_id = ?, allowed_role_id = ?, target_owner_role_id = ?, button_label = ?, edge_type = ? WHERE id = ?");
        foreach ($fromIds as $tid => $fromId) {
            $tid = (int) $tid;
            if ($tid <= 0) continue;
            $fromId = (int) $fromId;
            $toId = (int) ($toIds[$tid] ?? 0);
            $allowedRoleId = (int) ($allowedRoleIds[$tid] ?? 0);
            $targetRoleId = isset($targetRoleIds[$tid]) && (int)$targetRoleIds[$tid] > 0 ? (int)$targetRoleIds[$tid] : null;
            $label = trim($buttonLabels[$tid] ?? '');
            $lb = strtolower($label);
            $edgeType = 'normal';
            if (preg_match('/failed|loop|return|reject/i', $lb)) $edgeType = 'fallback';
            elseif (preg_match('/confirmed|confirm|validated|ok|created/i', $lb)) $edgeType = 'success';
            $stmt->execute([$fromId, $toId, $allowedRoleId, $targetRoleId ?: null, $label, $edgeType, $tid]);
        }
    }
    if (isset($_POST['save_all_statuses'])) {
        $names = $_POST['status_name'] ?? [];
        $explanations = $_POST['status_explanation'] ?? [];
        $stageRoleIds = $_POST['status_stage_role_id'] ?? [];
        $terminals = $_POST['is_terminal'] ?? [];
        $overriddenFlags = $_POST['is_color_overridden'] ?? [];
        $overrideColors = $_POST['status_color_override'] ?? [];
        
        $stmt = $pdo->prepare("UPDATE ticket_statuses SET name = ?, explanation = ?, stage_role_id = ?, is_terminal = ?, color_code = ?, is_color_overridden = ? WHERE id = ?");
        $rc = $pdo->prepare("SELECT color_code FROM roles WHERE id = ? LIMIT 1");
        
        foreach ($names as $sid => $name) {
            $sid = (int) $sid;
            if ($sid <= 0) continue;
            
            $stageRoleId = isset($stageRoleIds[$sid]) && (int)$stageRoleIds[$sid] > 0 ? (int)$stageRoleIds[$sid] : null;
            $isOverridden = !empty($overriddenFlags[$sid]) ? 1 : 0;
            $colorCode = '';
            
            if ($isOverridden) {
                $colorCode = trim($overrideColors[$sid] ?? '');
            } else {
                if ($stageRoleId) {
                    $rc->execute([$stageRoleId]);
                    $colorCode = trim($rc->fetchColumn() ?: '');
                }
            }
            
            $stmt->execute([
                trim($name ?? ''),
                trim($explanations[$sid] ?? ''),
                $stageRoleId,
                !empty($terminals[$sid]) ? 1 : 0,
                $colorCode,
                $isOverridden,
                $sid
            ]);
        }
    }
    header("Location: admin_workflow.php");
    exit;
}

// Fetch Data for Dropdowns
$statuses = $pdo->query("SELECT * FROM ticket_statuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query("SELECT * FROM ticket_types")->fetchAll(PDO::FETCH_ASSOC);

// Helper for JS: Map role IDs to their colors; for read-only display: role id -> name
$roleColorsMap = [];
$roleIdToName = [];
foreach ($roles as $r) {
    $roleColorsMap[$r['id']] = $r['color_code'] ?? 'transparent';
    $roleIdToName[(int)$r['id']] = $r['name'];
}

// Fetch Transitions
$sql = "
    SELECT wt.*, 
           s1.name as from_status, 
           s2.name as to_status, 
           r.name as role_name,
           r2.name as target_role_name,
           tt.code as type_code
    FROM workflow_transitions wt
    JOIN ticket_statuses s1 ON wt.current_status_id = s1.id
    JOIN ticket_statuses s2 ON wt.next_status_id = s2.id
    JOIN roles r ON wt.allowed_role_id = r.id
    LEFT JOIN roles r2 ON wt.target_owner_role_id = r2.id
    LEFT JOIN ticket_types tt ON wt.flow_type_id = tt.id
    ORDER BY s1.id, s2.id
";
$transitions = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$custTypeId = null;
$intTypeId = null;
foreach ($types as $t) {
    $c = strtoupper($t['code'] ?? '');
    if ($c === 'CUST') $custTypeId = (int)$t['id'];
    if ($c === 'INT' || $c === 'DEFECT') $intTypeId = (int)$t['id'];
}
$mermaidCust = build_mermaid_flowchart($transitions, $statuses, $custTypeId, 'TB');
$mermaidInt = build_mermaid_flowchart($transitions, $statuses, $intTypeId, 'TB');

// Status explanations for flowchart node click
$workflowStatusExplanations = [];
if (!empty($transitions)) {
    $usedStatusIds = [];
    foreach ($transitions as $t) {
        $usedStatusIds[(int)$t['current_status_id']] = true;
        $usedStatusIds[(int)$t['next_status_id']] = true;
    }
    $idPad = strlen((string)max(array_keys($usedStatusIds ?: [1])));
    foreach ($statuses as $s) {
        $sid = (int)$s['id'];
        if (empty($usedStatusIds[$sid])) continue;
        $nid = 's' . str_pad((string)$sid, max(2, $idPad), '0', STR_PAD_LEFT);
        $workflowStatusExplanations[$nid] = ['name' => $s['name'] ?? '', 'explanation' => trim($s['explanation'] ?? '')];
    }
}

// Filter transitions by flow type for table
$transitionsExternal = $custTypeId === null ? [] : array_filter($transitions, function($t) use ($custTypeId) {
    return (int)($t['flow_type_id'] ?? 0) === $custTypeId;
});
$transitionsInternal = $intTypeId === null ? [] : array_filter($transitions, function($t) use ($intTypeId) {
    return (int)($t['flow_type_id'] ?? 0) === $intTypeId;
});

$transitionsEdit = isset($_GET['transitions_edit']);
$statusEditorEdit = isset($_GET['status_editor_edit']);

// Color options for status override picker (and shared with admin_users Role Colors)
$roleColorOptions = [
    '#74c0fc' => 'Light Blue',
    '#8ce99a' => 'Light Green',
    '#fcc419' => 'Yellow',
    '#ffa8a8' => 'Light Red',
    ''        => '— none —',
    '#206bc4' => 'Blue',
    '#2fb344' => 'Green',
    '#f59f00' => 'Amber',
    '#e03131' => 'Red',
    '#ae3ec9' => 'Purple',
    '#1864ab' => 'Dark Blue',
    '#087f5b' => 'Dark Green',
    '#e8590c' => 'Dark Orange',
    '#c92a2a' => 'Dark Red',
    '#495057' => 'Dark Gray',
];
?>

<?php $pageTitle = 'Workflow Admin'; require 'includes/header.php'; ?>
<div class="page-wrapper">
    <div class="container-xl compact-layout">
        <div class="page-header"><h2 class="page-title">Workflow Admin</h2></div>
    </div>
    <div class="page-body">
        <div class="container-xl compact-layout">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Add New Transition</h3></div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">From Status</label>
                            <select name="from_status_id" class="form-select">
                                <?php foreach($statuses as $s): ?><option value="<?=(int)$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Status</label>
                            <select name="next_status_id" class="form-select">
                                <?php foreach($statuses as $s): ?><option value="<?=(int)$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Who can click?</label>
                            <select name="allowed_role_id" class="form-select">
                                <?php foreach($roles as $r): ?><option value="<?=$r['id']?>"><?=$r['name']?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Type</label>
                            <select name="flow_type_id" class="form-select" required>
                                <?php foreach($types as $t): ?><option value="<?=$t['id']?>"><?=htmlspecialchars($t['code'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Next Responsible</label>
                            <select name="target_owner_role_id" class="form-select">
                                <?php foreach($roles as $r): ?><option value="<?=$r['id']?>"><?=$r['name']?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Button Label</label>
                            <input type="text" name="button_label" class="form-control" placeholder="e.g. Approve">
                        </div>
                        <div class="col-12">
                            <button type="submit" name="add_transition" class="btn btn-primary">Add Transition</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">Workflow Flowchart</h3>
                    <ul class="nav nav-tabs card-header-tabs" id="workflow-tabs">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#mermaid-cust" data-flow="external">External</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#mermaid-int" data-flow="internal">Internal</a></li>
                    </ul>
                </div>
                <div class="card-body">
                    <?php if (empty($transitions)): ?>
                    <p class="text-muted mb-0">No transitions defined. Add one above.</p>
                    <?php else: ?>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="mermaid-cust">
                            <div class="d-flex justify-content-center mermaid-card-content">
                                <div class="mermaid-workflow-wrap">
                                    <div class="mermaid p-2"><?= htmlspecialchars($mermaidCust) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="mermaid-int">
                            <div class="d-flex justify-content-center mermaid-card-content">
                                <div class="mermaid-workflow-wrap">
                                    <div class="mermaid p-2"><?= htmlspecialchars($mermaidInt) ?></div>
                                </div>
                            </div>
                        </div>
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
                    <script>window.WORKFLOW_STATUS_EXPLANATIONS = <?= json_encode($workflowStatusExplanations) ?>;</script>
                    <?php endif; ?>
                    <p class="text-muted small mt-2 mb-0">Returns as backward arrows. <span style="color:#dc3545">●</span> Red = negative return/rejection, <span style="color:#22c55e">●</span> Green = positive completion.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-4">
                <form method="POST">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="card-title mb-0">Status Editor</h3>
                        <div class="d-flex gap-2">
                            <?php if ($statusEditorEdit): ?>
                                <a href="admin_workflow.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" name="save_all_statuses" class="btn btn-primary">Save</button>
                            <?php else: ?>
                                <a href="admin_workflow.php?status_editor_edit=1" class="btn btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter" id="status-editor-table">
                           <thead>
								<tr>
									<th style="width: 60px;">ID</th>
									<th style="width: 140px;">Name</th>
									<th style="min-width: 200px;">Explanation</th>
									<th style="width: 180px;">Stage (Role)</th>
									<th style="width: 180px;">Color Override</th>
									<th style="width: 90px; text-align: center;">Terminal</th>
								</tr>
							</thead>
                            <tbody>
                                <?php foreach ($statuses as $s):
                                    $isOverridden = !empty($s['is_color_overridden']);
                                    $overrideColor = $isOverridden ? ($s['color_code'] ?? '') : '';
                                    $stageRoleId = (int)($s['stage_role_id'] ?? 0);
                                    $initialRoleColor = $stageRoleId > 0 && isset($roleColorsMap[$stageRoleId]) ? $roleColorsMap[$stageRoleId] : 'transparent';
                                    $inOptions = isset($roleColorOptions[$overrideColor]);
                                    $stageRoleName = $stageRoleId > 0 && isset($roleIdToName[$stageRoleId]) ? $roleIdToName[$stageRoleId] : '— none —';
                                    $overrideLabel = $isOverridden ? ($inOptions ? $roleColorOptions[$overrideColor] : ($overrideColor ?: '— none —')) : 'No';
                                ?>
                                <tr>
                                    <td><?= (int)$s['id'] ?></td>
                                    <?php if ($statusEditorEdit): ?>
                                    <td><input type="text" name="status_name[<?= (int)$s['id'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($s['name'] ?? '') ?>" style="width:110px;"></td>
                                    <td><textarea name="status_explanation[<?= (int)$s['id'] ?>]" class="form-control form-control-sm" rows="3" placeholder="Short description" style="min-width:180px; resize:vertical;"><?= htmlspecialchars($s['explanation'] ?? '') ?></textarea></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary dynamic-role-dot" id="role-dot-<?= (int)$s['id'] ?>" style="background-color: <?= htmlspecialchars($initialRoleColor) ?>;"></span>
                                            <select name="status_stage_role_id[<?= (int)$s['id'] ?>]" class="form-select form-select-sm role-select-dropdown" data-sid="<?= (int)$s['id'] ?>" style="min-width:130px;">
                                                <option value="">— none —</option>
                                                <?php foreach ($roles as $r): ?>
                                                <option value="<?= (int)$r['id'] ?>" <?= $stageRoleId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary override-swatch align-middle" id="override-swatch-<?= (int)$s['id'] ?>" style="background-color: <?= $overrideColor ? htmlspecialchars($overrideColor) : 'transparent' ?>;"></span>
                                            <label class="form-check form-switch mb-0 align-middle" title="Toggle Color Override">
                                                <input type="checkbox" name="is_color_overridden[<?= (int)$s['id'] ?>]" class="form-check-input override-toggle" value="1" data-sid="<?= (int)$s['id'] ?>" <?= $isOverridden ? 'checked' : '' ?>>
                                            </label>
                                            <div class="dropdown custom-color-picker override-picker align-middle" id="override-picker-<?= (int)$s['id'] ?>" style="<?= $isOverridden ? '' : 'display: none;' ?>">
                                                <input type="hidden" name="status_color_override[<?= (int)$s['id'] ?>]" value="<?= htmlspecialchars($overrideColor) ?>">
                                                <button type="button" class="btn btn-sm form-select text-start d-flex align-items-center justify-content-between bg-white px-2" data-bs-toggle="dropdown" aria-expanded="false" style="width: 110px; font-weight: normal;">
                                                    <span class="d-flex align-items-center gap-2 overflow-hidden">
                                                        <span class="color-swatch-sm border border-secondary flex-shrink-0 override-picker-swatch" style="background-color: <?= $overrideColor ?: 'transparent' ?>;"></span>
                                                        <span class="color-name text-truncate" style="font-size: 0.8rem;"><?= htmlspecialchars($inOptions ? $roleColorOptions[$overrideColor] : ($overrideColor ?: '— none —')) ?></span>
                                                    </span>
                                                </button>
                                                <div class="dropdown-menu p-2 shadow-sm" style="min-width: 220px;">
                                                    <div class="color-swatch-grid">
                                                        <?php foreach ($roleColorOptions as $optVal => $optLabel): ?>
                                                        <div class="color-swatch border <?= $overrideColor === $optVal ? 'border-dark shadow-sm' : 'border-secondary-subtle' ?>" style="background-color: <?= $optVal ?: '#f8f9fa' ?>;" data-value="<?= htmlspecialchars($optVal) ?>" data-name="<?= htmlspecialchars($optLabel) ?>" title="<?= htmlspecialchars($optLabel) ?>">
                                                            <?php if ($optVal === ''): ?><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted"><path d="M18 6l-12 12"></path><path d="M6 6l12 12"></path></svg><?php endif; ?>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <label class="form-check form-switch form-switch-danger mt-1">
                                            <input type="checkbox" name="is_terminal[<?= (int)$s['id'] ?>]" class="form-check-input" value="1" <?= !empty($s['is_terminal']) ? 'checked' : '' ?>>
                                        </label>
                                    </td>
                                    <?php else: ?>
                                    <td><?= htmlspecialchars($s['name'] ?? '') ?></td>
                                    <td class="text-muted small"><?= nl2br(htmlspecialchars($s['explanation'] ?? '')) ?></td>
                                    <td>
                                        <span class="color-swatch-sm border border-secondary d-inline-block align-middle" style="background-color: <?= htmlspecialchars($initialRoleColor) ?>;"></span>
                                        <span class="align-middle"><?= htmlspecialchars($stageRoleName) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($isOverridden): ?>
                                        <span class="color-swatch-sm border border-secondary d-inline-block align-middle" style="background-color: <?= htmlspecialchars($overrideColor) ?>;"></span>
                                        <span class="align-middle"><?= htmlspecialchars($overrideLabel) ?></span>
                                        <?php else: ?>
                                        <span class="align-middle">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= !empty($s['is_terminal']) ? 'Yes' : 'No' ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <div class="card mt-3">
                <form method="POST" id="transitions-form">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="card-title mb-0">Transitions</h3>
                        <div class="d-flex gap-2">
                            <?php if ($transitionsEdit): ?>
                                <a href="admin_workflow.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" name="save_transitions" class="btn btn-primary">Save</button>
                            <?php else: ?>
                                <a href="admin_workflow.php?transitions_edit=1" class="btn btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter text-nowrap table-sm">
                            <thead><tr><th>From</th><th>To</th><th>Who can click?</th><th>Button Label</th><th>Next responsible</th><?php if ($transitionsEdit): ?><th></th><?php endif; ?></tr></thead>
                            <tbody id="trans-external">
                                <?php foreach ($transitionsExternal as $row):
                                    $roleColor = trim($roleColorsMap[$row['allowed_role_id']] ?? '');
                                    $roleColorStyle = ($roleColor !== '' && strpos($roleColor, '#') === 0) ? 'background-color:' . htmlspecialchars($roleColor) . '20;color:' . htmlspecialchars($roleColor) : 'background-color:#f1f3f5;color:#495057';
                                    $targetRoleId = (int)($row['target_owner_role_id'] ?? 0);
                                    $targetRoleColor = trim($roleColorsMap[$targetRoleId] ?? '');
                                    $targetRoleColorStyle = ($targetRoleColor !== '' && strpos($targetRoleColor, '#') === 0) ? 'background-color:' . htmlspecialchars($targetRoleColor) . '20;color:' . htmlspecialchars($targetRoleColor) : 'background-color:#f1f3f5;color:#495057';
                                ?>
                                <tr>
                                    <?php if ($transitionsEdit): ?>
                                    <td>
                                        <select name="current_status_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm" style="min-width:140px;">
                                            <?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$row['current_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="next_status_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm" style="min-width:140px;">
                                            <?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$row['next_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary trans-role-dot" id="trans-role-dot-<?= (int)$row['id'] ?>" style="background-color: <?= $roleColor ? htmlspecialchars($roleColor) : 'transparent' ?>;"></span>
                                            <select name="allowed_role_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm trans-allowed-role" data-tid="<?= (int)$row['id'] ?>" style="min-width:120px;">
                                                <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$row['allowed_role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td><input type="text" name="button_label[<?= (int)$row['id'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($row['button_label'] ?? '') ?>" style="min-width:100px;"></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary trans-target-dot" id="trans-target-dot-<?= (int)$row['id'] ?>" style="background-color: <?= $targetRoleColor ? htmlspecialchars($targetRoleColor) : 'transparent' ?>;"></span>
                                            <select name="target_owner_role_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm trans-target-role" data-tid="<?= (int)$row['id'] ?>" style="min-width:120px;">
                                                <option value="">— none —</option>
                                                <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $targetRoleId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <?php else: ?>
                                    <td><?= htmlspecialchars($row['from_status']) ?></td>
                                    <td><?= htmlspecialchars($row['to_status']) ?></td>
                                    <td><span class="badge" style="<?= $roleColorStyle ?>"><?= htmlspecialchars($row['role_name']) ?></span></td>
                                    <td><?= htmlspecialchars($row['button_label'] ?: '-') ?></td>
                                    <td><span class="badge" style="<?= $targetRoleColorStyle ?>"><?= htmlspecialchars($row['target_role_name'] ?? '— none —') ?></span></td>
                                    <?php endif; ?>
                                    <?php if ($transitionsEdit): ?>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-icon btn-ghost-danger btn-delete-transition" title="Delete" data-id="<?= (int)$row['id'] ?>"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2L19 7"/><path d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg></button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tbody id="trans-internal" class="d-none">
                                <?php foreach ($transitionsInternal as $row):
                                    $roleColor = trim($roleColorsMap[$row['allowed_role_id']] ?? '');
                                    $roleColorStyle = ($roleColor !== '' && strpos($roleColor, '#') === 0) ? 'background-color:' . htmlspecialchars($roleColor) . '20;color:' . htmlspecialchars($roleColor) : 'background-color:#f1f3f5;color:#495057';
                                    $targetRoleId = (int)($row['target_owner_role_id'] ?? 0);
                                    $targetRoleColor = trim($roleColorsMap[$targetRoleId] ?? '');
                                    $targetRoleColorStyle = ($targetRoleColor !== '' && strpos($targetRoleColor, '#') === 0) ? 'background-color:' . htmlspecialchars($targetRoleColor) . '20;color:' . htmlspecialchars($targetRoleColor) : 'background-color:#f1f3f5;color:#495057';
                                ?>
                                <tr>
                                    <?php if ($transitionsEdit): ?>
                                    <td>
                                        <select name="current_status_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm" style="min-width:140px;">
                                            <?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$row['current_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="next_status_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm" style="min-width:140px;">
                                            <?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$row['next_status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary trans-role-dot" id="trans-role-dot-<?= (int)$row['id'] ?>" style="background-color: <?= $roleColor ? htmlspecialchars($roleColor) : 'transparent' ?>;"></span>
                                            <select name="allowed_role_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm trans-allowed-role" data-tid="<?= (int)$row['id'] ?>" style="min-width:120px;">
                                                <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$row['allowed_role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td><input type="text" name="button_label[<?= (int)$row['id'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($row['button_label'] ?? '') ?>" style="min-width:100px;"></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-swatch-sm border border-secondary trans-target-dot" id="trans-target-dot-<?= (int)$row['id'] ?>" style="background-color: <?= $targetRoleColor ? htmlspecialchars($targetRoleColor) : 'transparent' ?>;"></span>
                                            <select name="target_owner_role_id[<?= (int)$row['id'] ?>]" class="form-select form-select-sm trans-target-role" data-tid="<?= (int)$row['id'] ?>" style="min-width:120px;">
                                                <option value="">— none —</option>
                                                <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $targetRoleId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <?php else: ?>
                                    <td><?= htmlspecialchars($row['from_status']) ?></td>
                                    <td><?= htmlspecialchars($row['to_status']) ?></td>
                                    <td><span class="badge" style="<?= $roleColorStyle ?>"><?= htmlspecialchars($row['role_name']) ?></span></td>
                                    <td><?= htmlspecialchars($row['button_label'] ?: '-') ?></td>
                                    <td><span class="badge" style="<?= $targetRoleColorStyle ?>"><?= htmlspecialchars($row['target_role_name'] ?? '— none —') ?></span></td>
                                    <?php endif; ?>
                                    <?php if ($transitionsEdit): ?>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-icon btn-ghost-danger btn-delete-transition" title="Delete" data-id="<?= (int)$row['id'] ?>"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2L19 7"/><path d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg></button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.compact-layout {
    max-width: 1100px !important;
    margin: 0 auto;
}

/* --- COLOR PICKER CSS --- */
.color-swatch-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}
.color-swatch {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.color-swatch:hover {
    transform: scale(1.15);
    box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
}
.color-swatch-sm {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
	
.form-switch-danger .form-check-input:checked {
    background-color: var(--tblr-danger, #d63939);
    border-color: var(--tblr-danger, #d63939);
}

/* Flowchart: zoomed in + scrollable (vertical TB layout) */
.mermaid-card-content { 
    width: 100%;
    max-height: 90vh;
    overflow: auto;
    padding: 20px;
    background: #fff;
}

.mermaid-workflow-wrap { 
    display: block;
    /* Hier ist der Zaubertrick: Zwingt den Chart in die Breite (Zoom-Effekt) */
    min-width: 800px; 
    margin: 0 auto;
    padding-bottom: 2rem;
}

.mermaid {
    display: flex;
    justify-content: center;
}

.mermaid svg {
    width: 100% !important;
    height: auto !important;
    /* Deckelt die Maximalgröße auf Riesen-Monitoren, damit es nicht unscharf wird */
    max-width: 1200px !important; 
}

.mermaid .edgeLabel rect { fill: white !important; stroke: white !important; }
.mermaid .edgeLabel .labelBkg { fill: white !important; }
.mermaid foreignObject:has(.edgeLabel) .edgeLabel p,
.mermaid foreignObject:has(.edgeLabel) p { background-color: white !important; padding: 2px 6px !important; border-radius: 4px; max-width: max-content; }
.mermaid .edgeLabel text { paint-order: stroke; stroke: white; stroke-width: 3px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const roleColorsMap = <?= json_encode($roleColorsMap) ?>;

    // Update role color dot when Stage (Role) selection changes
    document.querySelectorAll('.role-select-dropdown').forEach(function(select) {
        select.addEventListener('change', function() {
            var sid = this.getAttribute('data-sid');
            var roleId = this.value;
            var dot = document.getElementById('role-dot-' + sid);
            if (dot) {
                dot.style.backgroundColor = (roleId && roleColorsMap[roleId]) ? roleColorsMap[roleId] : 'transparent';
            }
        });
    });

    // Transitions: delete via POST without nesting forms
    document.querySelectorAll('.btn-delete-transition').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Delete this transition?')) return;
            var id = this.getAttribute('data-id');
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = 'admin_workflow.php';
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'delete_id';
            i.value = id;
            f.appendChild(i);
            document.body.appendChild(f);
            f.submit();
        });
    });

    // Transitions: update role color dot when "Who can click?" selection changes
    document.querySelectorAll('.trans-allowed-role').forEach(function(select) {
        select.addEventListener('change', function() {
            var tid = this.getAttribute('data-tid');
            var roleId = this.value;
            var dot = document.getElementById('trans-role-dot-' + tid);
            if (dot) {
                dot.style.backgroundColor = (roleId && roleColorsMap[roleId]) ? roleColorsMap[roleId] : 'transparent';
            }
        });
    });
    // Transitions: update dot when "Next responsible" selection changes
    document.querySelectorAll('.trans-target-role').forEach(function(select) {
        select.addEventListener('change', function() {
            var tid = this.getAttribute('data-tid');
            var roleId = this.value;
            var dot = document.getElementById('trans-target-dot-' + tid);
            if (dot) {
                dot.style.backgroundColor = (roleId && roleColorsMap[roleId]) ? roleColorsMap[roleId] : 'transparent';
            }
        });
    });

    // Show/hide color override picker when toggle changes
    document.querySelectorAll('.override-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var sid = this.getAttribute('data-sid');
            var picker = document.getElementById('override-picker-' + sid);
            if (picker) {
                picker.style.display = this.checked ? 'block' : 'none';
            }
        });
    });

    // Color picker: set hidden input and button label on swatch click
    document.querySelectorAll('.custom-color-picker .color-swatch').forEach(function(swatch) {
        swatch.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var picker = this.closest('.custom-color-picker');
            var val = this.getAttribute('data-value');
            var name = this.getAttribute('data-name');

            // Set the hidden input value
            picker.querySelector('input[type="hidden"]').value = val;

            // Update the button UI and the standalone Color Override circle (if present)
            var btnSwatch = picker.querySelector('.color-swatch-sm');
            if (btnSwatch) btnSwatch.style.backgroundColor = val || 'transparent';
            var nameEl = picker.querySelector('.color-name');
            if (nameEl) nameEl.textContent = name;
            var pickerId = picker.id;
            if (pickerId && pickerId.indexOf('override-picker-') === 0) {
                var sid = pickerId.replace('override-picker-', '');
                var standAlone = document.getElementById('override-swatch-' + sid);
                if (standAlone) standAlone.style.backgroundColor = val || 'transparent';
            }

            // Update borders in the grid to show which is selected
            picker.querySelectorAll('.color-swatch').forEach(function(s) {
                s.classList.remove('border-dark', 'shadow-sm');
                s.classList.add('border-secondary-subtle');
            });
            this.classList.remove('border-secondary-subtle');
            this.classList.add('border-dark', 'shadow-sm');

            // Close the bootstrap dropdown
            var btn = picker.querySelector('[data-bs-toggle="dropdown"]');
            var dropdown = bootstrap.Dropdown.getInstance(btn);
            if (dropdown) {
                dropdown.hide();
            }
        });
    });
    
    /* --- MERMAID LOGIC --- */
    mermaid.initialize({
        startOnLoad: false,
        flowchart: {
            useMaxWidth: false, /* WICHTIG: Lässt unser CSS die volle Kontrolle übernehmen */
            htmlLabels: false,
            nodeSpacing: 50,
            rankSpacing: 60,
            padding: 15
        }
    });

    mermaid.run({ querySelector: '.tab-pane.active .mermaid', suppressErrors: true }).then(function() {
        var card = document.querySelector('#workflowStatusModal') && document.querySelector('#workflowStatusModal').closest('.card');
        if (!card || !window.WORKFLOW_STATUS_EXPLANATIONS) return;
        card.addEventListener('click', function(e) {
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

    function showTransitionsForTab(flow) {
        document.getElementById('trans-external').classList.toggle('d-none', flow !== 'external');
        document.getElementById('trans-internal').classList.toggle('d-none', flow !== 'internal');
    }

    document.querySelectorAll('#workflow-tabs [data-bs-toggle="tab"]').forEach(function(el) {
        el.addEventListener('shown.bs.tab', function(event) {
            showTransitionsForTab(this.getAttribute('data-flow'));
            const targetPaneId = event.target.getAttribute('href');
            const mermaidEl = document.querySelector(targetPaneId + ' .mermaid');
            if (mermaidEl && !mermaidEl.getAttribute('data-processed')) {
                mermaid.run({ querySelector: targetPaneId + ' .mermaid', suppressErrors: true });
            }
        });
    });
    showTransitionsForTab('external');
});
</script>
<?php require 'includes/footer.php'; ?>