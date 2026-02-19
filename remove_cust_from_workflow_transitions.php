<?php
/**
 * One-time script: Remove the word "CUST" from workflow_transitions.button_label
 * only when it appears as a separate token (so "Customer" is not changed).
 * Run once via: php remove_cust_from_workflow_transitions.php
 * or open in browser: http://localhost/ticket-tool/remove_cust_from_workflow_transitions.php
 */
require_once __DIR__ . '/includes/config.php';

$updated = 0;
$rows = $pdo->query("SELECT id, button_label FROM workflow_transitions")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $original = $row['button_label'];
    $cleaned = preg_replace('/\bCUST\b/i', '', $original);
    $cleaned = preg_replace('/\s*\(\s*\)\s*/', ' ', $cleaned);
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
    if ($cleaned !== $original) {
        $stmt = $pdo->prepare("UPDATE workflow_transitions SET button_label = ? WHERE id = ?");
        $stmt->execute([$cleaned, (int) $row['id']]);
        $updated++;
        echo "ID {$row['id']}: \"" . htmlspecialchars($original) . "\" -> \"" . htmlspecialchars($cleaned) . "\"\n";
    }
}

echo "\nDone. Updated {$updated} row(s). You can delete this script after verification.\n";
