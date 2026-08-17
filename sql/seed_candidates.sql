-- Ballot for the class demo: a mock Nigerian presidential election.
--
-- ---------------------------------------------------------------------------
-- READ THIS BEFORE PUTTING THE SITE ON A PUBLIC DOMAIN
-- ---------------------------------------------------------------------------
-- These are REAL, living public figures. That makes a far better classroom
-- demonstration than invented names, and it also makes the site far easier to
-- mistake for a real poll. Two rules follow from that, and both are already
-- implemented — do not undo them:
--
--   1. Every page carries a banner saying this is a classroom exercise and
--      not a real election (templates/layout.php).
--   2. The portraits are ILLUSTRATED INITIALS, not photographs. Using real
--      news photos would be a copyright problem, and a realistic photo makes
--      the "is this real?" question much harder for a visitor to answer.
--
-- The bios below are limited to matters of public record — offices held and
-- party affiliation. Do not add invented policy positions, quotes or slogans:
-- putting words in a real politician's mouth on a live vote counter is how a
-- teaching project turns into misinformation. The `slogan` column is left
-- empty for exactly this reason.
--
-- The 2027 field is NOT settled. These three contested the 2023 election;
-- treating them as the 2027 candidates is an assumption made for the demo.
--
-- Prefer invented candidates? `git log` this file for the fictional set that
-- shipped originally, or edit the rows below.
-- ---------------------------------------------------------------------------
--
--   mysql -h database-1.xxxx.us-east-2.rds.amazonaws.com -u admin -p letsvote < sql/seed_candidates.sql

USE letsvote;

-- Idempotent: re-running refreshes the details without duplicating rows or
-- disturbing any ballots already cast against these candidate ids.
INSERT INTO candidates (id, full_name, party, party_abbr, slogan, bio, photo, accent, is_active, sort_order) VALUES
(1, 'Bola Ahmed Tinubu', 'All Progressives Congress', 'APC', '',
 'President of Nigeria since 2023. Governor of Lagos State from 1999 to 2007.',
 '/assets/candidates/tinubu.svg', '#1d6fb8', 1, 1),

(2, 'Atiku Abubakar', 'Peoples Democratic Party', 'PDP', '',
 'Vice-President of Nigeria from 1999 to 2007. Has contested several presidential elections.',
 '/assets/candidates/atiku.svg', '#0b7a4b', 1, 2),

(3, 'Peter Obi', 'Labour Party', 'LP', '',
 'Governor of Anambra State from 2006 to 2014. Labour Party presidential candidate in 2023.',
 '/assets/candidates/obi.svg', '#b8341d', 1, 3)
AS new
ON DUPLICATE KEY UPDATE
    full_name  = new.full_name,
    party      = new.party,
    party_abbr = new.party_abbr,
    slogan     = new.slogan,
    bio        = new.bio,
    photo      = new.photo,
    accent     = new.accent,
    is_active  = new.is_active,
    sort_order = new.sort_order;

-- Retire any candidates left over from an earlier seed. Deactivating rather
-- than deleting keeps referential integrity with any ballots already cast.
UPDATE candidates SET is_active = 0 WHERE id NOT IN (1, 2, 3);

UPDATE election_settings
   SET title = 'Nigeria Presidential Election 2027 — Classroom Demonstration'
 WHERE id = 1;

-- Promote yourself to administrator AFTER your first Cognito sign-in
-- (the user row only exists once you have logged in at least once):
--
--   UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
