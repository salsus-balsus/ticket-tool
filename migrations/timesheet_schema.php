<?php
/**
 * Timesheet schema migration.
 * Run once to drop and recreate time_entries, add time_entry_tickets and user_employment_data.
 * Ensures ts_projects, ts_topics, ts_task_groups exist with clean structure.
 */
require_once __DIR__ . '/../includes/config.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // 1. Drop dependent table first (FK to time_entries)
    $pdo->exec("DROP TABLE IF EXISTS time_entry_tickets");
    $pdo->exec("DROP TABLE IF EXISTS time_entries");

    // 2. Lookup tables: create if not exist (do not drop – may contain master data)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ts_projects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ts_topics (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uq_ts_topics_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ts_task_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uq_ts_task_groups_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 3. Target hours for Overview "To be"
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_employment_data (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8.00,
            days_per_week DECIMAL(3,2) NOT NULL DEFAULT 5.00,
            hours_per_month DECIMAL(6,2) DEFAULT NULL,
            days_per_month DECIMAL(4,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ued_user (user_id),
            KEY idx_ued_dates (effective_from, effective_to),
            CONSTRAINT fk_ued_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 4. Time entries (no single ticket_id)
    $pdo->exec("
        CREATE TABLE time_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            duration_hours DECIMAL(5,2) NOT NULL,
            project_id INT UNSIGNED NULL,
            topic_id INT UNSIGNED NULL,
            task_group_id INT UNSIGNED NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_te_user (user_id),
            KEY idx_te_date (entry_date),
            KEY idx_te_project (project_id),
            KEY idx_te_topic (topic_id),
            CONSTRAINT fk_te_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
            CONSTRAINT fk_te_project FOREIGN KEY (project_id) REFERENCES ts_projects(id) ON DELETE SET NULL,
            CONSTRAINT fk_te_topic FOREIGN KEY (topic_id) REFERENCES ts_topics(id) ON DELETE SET NULL,
            CONSTRAINT fk_te_task_group FOREIGN KEY (task_group_id) REFERENCES ts_task_groups(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 5. 1:n link time entry -> tickets
    $pdo->exec("
        CREATE TABLE time_entry_tickets (
            time_entry_id INT UNSIGNED NOT NULL,
            ticket_id INT NOT NULL,
            PRIMARY KEY (time_entry_id, ticket_id),
            CONSTRAINT fk_tet_entry FOREIGN KEY (time_entry_id) REFERENCES time_entries(id) ON DELETE CASCADE,
            CONSTRAINT fk_tet_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    echo "Timesheet schema migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
