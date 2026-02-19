<?php
/**
 * One-time migration: Add missing column created_at (and created_by if missing) to ticket_history.
 * Error was: "Unknown column 'created_at' in 'field list'"
 * Code in ticket_action.php and ticket_lock.php expects: ticket_id, change_type, old_value, new_value, created_at, created_by.
 */
require __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ticket_history LIKE 'created_at'");
    if ($stmt->rowCount() > 0) {
        echo "OK: Column created_at already exists. Skip.\n";
    } else {
        $pdo->exec("ALTER TABLE ticket_history ADD COLUMN created_at DATETIME NULL DEFAULT NULL");
        echo "OK: Added column created_at to ticket_history.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ticket_history LIKE 'created_by'");
    if ($stmt->rowCount() > 0) {
        echo "OK: Column created_by already exists. Skip.\n";
    } else {
        $pdo->exec("ALTER TABLE ticket_history ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL");
        echo "OK: Added column created_by to ticket_history.\n";
    }
    exit(0);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
