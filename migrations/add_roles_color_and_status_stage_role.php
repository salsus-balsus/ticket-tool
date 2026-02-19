<?php
/**
 * Add roles.color_code and ticket_statuses.stage_role_id.
 * Run once. Safe to re-run (checks for column existence).
 */
require __DIR__ . '/../includes/config.php';

try {
    $r = $pdo->query("SHOW COLUMNS FROM roles LIKE 'color_code'");
    if ($r->rowCount() === 0) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN color_code VARCHAR(31) NULL DEFAULT NULL");
        echo "Added roles.color_code.\n";
    }
    $r = $pdo->query("SHOW COLUMNS FROM ticket_statuses LIKE 'stage_role_id'");
    if ($r->rowCount() === 0) {
        $pdo->exec("ALTER TABLE ticket_statuses ADD COLUMN stage_role_id INT UNSIGNED NULL DEFAULT NULL");
        echo "Added ticket_statuses.stage_role_id.\n";
    }
    echo "OK.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
    exit(1);
}
