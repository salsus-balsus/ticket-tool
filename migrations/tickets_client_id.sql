-- Add client_id to tickets for System/Client (RDM) linkage.
-- clients.id references systems; one client belongs to one system.
ALTER TABLE tickets ADD COLUMN client_id INT NULL AFTER customer_id;
ALTER TABLE tickets ADD KEY fk_tickets_client (client_id);
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
