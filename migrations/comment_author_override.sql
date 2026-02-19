-- Per-comment author display name (overrides author_map/author_display for this comment only)
CREATE TABLE IF NOT EXISTS comment_author_override (
    comment_id INT UNSIGNED NOT NULL PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
);
