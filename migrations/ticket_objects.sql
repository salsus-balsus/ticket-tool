-- ticket_objects: many-to-many between tickets and objects
CREATE TABLE IF NOT EXISTS ticket_objects (
    ticket_id INT NOT NULL,
    object_id INT NOT NULL,
    PRIMARY KEY (ticket_id, object_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (object_id) REFERENCES objects(id) ON DELETE CASCADE
);

-- affected_object_count: for "11 object types", "all object types", "several"
-- Run manually if needed: ALTER TABLE tickets ADD COLUMN affected_object_count INT NULL;
-- Run manually if needed: ALTER TABLE tickets ADD COLUMN affected_object_note VARCHAR(255) NULL;
