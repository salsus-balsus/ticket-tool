<?php
/**
 * One-time migration: Add "Customer Confirmed" transition for role_id=1 (Quality)
 * so the button appears when viewing ticket #1209 (status 95, type 1) as Quality.
 * Evidence: .cursor/debug.log showed count_any_role=1, count=0 for user_role_id=1
 * => single transition exists for (95,1) with allowed_role_id != 1.
 */
require __DIR__ . '/../includes/config.php';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        SELECT flow_type_id, current_status_id, next_status_id, target_owner_role_id, button_label, edge_type
        FROM workflow_transitions
        WHERE current_status_id = 95 AND flow_type_id = 1
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        die("No transition found for current_status_id=95, flow_type_id=1. Skip migration.");
    }
    $exists = $pdo->prepare("
        SELECT 1 FROM workflow_transitions
        WHERE current_status_id = ? AND flow_type_id = ? AND next_status_id = ? AND allowed_role_id = 1
    ");
    $exists->execute([$row['current_status_id'], $row['flow_type_id'], $row['next_status_id']]);
    if ($exists->fetchColumn()) {
        $pdo->rollBack();
        die("Transition for role 1 already exists. Skip migration.");
    }
    $ins = $pdo->prepare("
        INSERT INTO workflow_transitions (flow_type_id, current_status_id, next_status_id, allowed_role_id, target_owner_role_id, button_label, edge_type)
        VALUES (?, ?, ?, 1, ?, ?, ?)
    ");
    $ins->execute([
        $row['flow_type_id'],
        $row['current_status_id'],
        $row['next_status_id'],
        (int)$row['target_owner_role_id'],
        $row['button_label'],
        $row['edge_type'] ?? 'normal'
    ]);
    $pdo->commit();
    echo "OK: Added workflow transition for role_id=1 (same as existing for status 95, type 1).";
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}
