-- Role-level color for status display. ticket_statuses.color_code is derived from the status's stage role.
ALTER TABLE roles ADD COLUMN color_code VARCHAR(31) NULL DEFAULT NULL;
