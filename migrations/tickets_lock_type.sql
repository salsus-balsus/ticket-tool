-- Quality lock overlay: OBS=Obsolete, ONH=On Hold, RED=Redirect. NULL = no lock.
ALTER TABLE tickets
  ADD COLUMN lock_type ENUM('OBS','ONH','RED') NULL DEFAULT NULL AFTER current_role_id;
