-- Personal data scrub for the CI database snapshot.
--
-- ci-snapshot.sh applies this to a throwaway copy of the production database on
-- the production host, so nothing here ever runs against the live database and
-- the copy never leaves the server unscrubbed.
--
-- Only core tables are named here. Module-dependent tables and columns are
-- generated from the copied schema by ci-snapshot.sh, so a project without those
-- modules still applies this file cleanly.

-- Keep the accounts every project has: anonymous (0), the site owner (1) and
-- the default demo users. Every other account belongs to a real person.
CREATE TEMPORARY TABLE keep_uid (uid INT UNSIGNED NOT NULL PRIMARY KEY);
INSERT INTO keep_uid (uid)
SELECT uid FROM users_field_data
WHERE uid IN (0, 1) OR name IN ('admin', 'user', 'editor');

-- The field data table carries name, mail and password hash, so it goes before
-- the base table. user__roles is core; other user fields are module-dependent
-- and handled by the generated statements.
DELETE FROM user__roles WHERE entity_id NOT IN (SELECT uid FROM keep_uid);
DELETE FROM users_data WHERE uid NOT IN (SELECT uid FROM keep_uid);
DELETE FROM users_field_data WHERE uid NOT IN (SELECT uid FROM keep_uid);
DELETE FROM users WHERE uid NOT IN (SELECT uid FROM keep_uid);

-- Kept accounts get placeholder addresses, and no password: CI signs in with
-- `drush uli`, which needs neither a password nor a real mailbox.
UPDATE users_field_data
SET mail = CONCAT(name, '@example.com'),
    init = CONCAT(name, '@example.com'),
    pass = NULL
WHERE uid IN (SELECT uid FROM keep_uid);

-- ci-snapshot.sh appends statements generated from information_schema below.
