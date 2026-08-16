-- Sample ballot for the class demo.
--
-- These are INVENTED candidates and INVENTED parties. Keep them fictional:
-- a public site that lists real politicians alongside a live vote counter can
-- easily be mistaken for a real poll. Edit the names below to whatever your
-- class prefers.
--
--   mysql -h primary-db.xxxx.us-east-1.rds.amazonaws.com -u admin -p letsvote < sql/seed_candidates.sql

USE letsvote;

INSERT INTO candidates (full_name, party, slogan, bio, is_active, sort_order) VALUES
('Amina Njoya',      'Unity Alliance',            'One nation, one future',
 'A former regional health administrator running on universal primary care and rural clinics.', 1, 1),
('Bertrand Eyong',   'Progressive Democrats',     'Work, water, roads',
 'An infrastructure engineer focused on road networks, electrification and clean water access.', 1, 2),
('Clarisse Mbarga',  'Green Renewal Movement',    'Farms first',
 'An agricultural economist campaigning on food security, cocoa pricing and reforestation.', 1, 3),
('Daniel Fotso',     'National Youth Congress',   'Jobs for a young country',
 'A software entrepreneur proposing vocational training and small-business credit.', 1, 4),
('Esther Ngu',       'Independent',               'Accountable government',
 'A civil-society lawyer standing on anti-corruption, judicial reform and open budgets.', 1, 5);

-- Promote yourself to administrator AFTER your first Cognito sign-in
-- (the user row only exists once you have logged in at least once):
--
--   UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
