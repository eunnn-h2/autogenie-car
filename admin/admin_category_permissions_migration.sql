-- Run once if the admin account screen cannot automatically add this column.
ALTER TABLE admin_accounts ADD COLUMN category_permissions TEXT NULL;
-- NULL preserves legacy access. Saving permissions writes an explicit JSON array.
