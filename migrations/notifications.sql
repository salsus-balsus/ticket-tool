-- Run this to enable the notification hook in ticket_action.php
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    ticket_id INT NOT NULL,
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_role (role_id),
    INDEX idx_created (created_at)
);
