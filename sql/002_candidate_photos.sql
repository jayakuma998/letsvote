-- Migration: add portrait, party abbreviation and accent colour to candidates.
--
-- Run this ONLY if your database was created before these columns existed.
-- A fresh `sql/schema.sql` already includes them.
--
--   mysql -h database-1.xxxx.us-east-2.rds.amazonaws.com -u admin -p letsvote < sql/002_candidate_photos.sql
--
-- Note there is no ADD COLUMN IF NOT EXISTS in MySQL, so running this twice
-- fails with "Duplicate column name". That is harmless — it means the columns
-- are already there.
--
-- Run it against the PRIMARY only. Replication copies the change to the read
-- replica by itself; never run DDL against a replica.

USE letsvote;

ALTER TABLE candidates
    ADD COLUMN party_abbr VARCHAR(12)  NOT NULL DEFAULT ''         AFTER party,
    ADD COLUMN photo      VARCHAR(255) NOT NULL DEFAULT ''         AFTER bio,
    ADD COLUMN accent     CHAR(7)      NOT NULL DEFAULT '#5b6673'  AFTER photo;
