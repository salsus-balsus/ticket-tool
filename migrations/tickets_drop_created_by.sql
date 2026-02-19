-- Drop tickets.created_by: reporter is now stored in ticket_participants (role = 'REP').
-- Run this when you have migrated to ticket_participants and want to align the schema.
-- The import script works with or without this column (fallback: fills created_by with first reporter).

ALTER TABLE tickets DROP COLUMN created_by;
