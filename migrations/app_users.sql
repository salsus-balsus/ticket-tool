-- User & Rollen: Zuordnung Login → Rolle für Workflow-Berechtigungen
-- Spalten: first_name, last_name, initials (siehe app_users_first_last_initials.sql für Migration von display_name)
CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) DEFAULT NULL,
    initials VARCHAR(31) DEFAULT NULL,
    role_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username (username),
    KEY idx_role (role_id)
);
