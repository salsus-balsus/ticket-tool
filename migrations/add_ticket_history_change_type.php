<?php
/**
 * One-time migration: Add missing column change_type to ticket_history.
 * Error was: "Unknown column 'change_type' in 'field list'"
 * Code in ticket_action.php and ticket_lock.php expects: ticket_id, change_type, old_value, new_value, created_at, created_by.
 */
require __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ticket_history LIKE 'change_type'");
    if ($stmt->rowCount() > 0) {
        echo "OK: Column change_type already exists. Skip.";
        exit(0);
    }
    $pdo->exec("ALTER TABLE ticket_history ADD COLUMN change_type VARCHAR(64) NOT NULL DEFAULT 'status_change'");
    echo "OK: Added column change_type to ticket_history.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
    exit(1);
}
