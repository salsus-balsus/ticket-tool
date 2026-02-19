-- Redirect lock: which ticket replaces this one (follow-up ticket).
ALTER TABLE tickets
  ADD COLUMN redirect_ticket_id INT UNSIGNED NULL DEFAULT NULL AFTER lock_type,
  ADD KEY idx_redirect_ticket_id (redirect_ticket_id);
