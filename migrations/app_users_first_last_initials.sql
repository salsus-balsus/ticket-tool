-- app_users: display_name → first_name, add last_name and initials
-- Run only if your app_users still has display_name.

-- If column is still named display_name:
ALTER TABLE app_users
  CHANGE COLUMN display_name first_name VARCHAR(255) DEFAULT NULL,
  ADD COLUMN last_name VARCHAR(255) DEFAULT NULL AFTER first_name,
  ADD COLUMN initials VARCHAR(31) DEFAULT NULL AFTER last_name;
