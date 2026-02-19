-- Stage = which role "owns" this status. Dropdown in Status Editor uses roles table; color_code is then taken from that role.
ALTER TABLE ticket_statuses ADD COLUMN stage_role_id INT UNSIGNED NULL DEFAULT NULL AFTER stage;
-- Optional FK (if roles.id exists): ADD CONSTRAINT fk_status_stage_role FOREIGN KEY (stage_role_id) REFERENCES roles(id) ON DELETE SET NULL;
