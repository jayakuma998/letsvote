-- LetsVote — database schema (MySQL 8.0 / Amazon RDS)
--
-- Run this ONCE against the RDS primary. The read replica copies it
-- automatically; never run DDL against a replica.
--
--   mysql -h database-1.xxxx.us-east-2.rds.amazonaws.com -u admin -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS letsvote
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE letsvote;

-- ---------------------------------------------------------------------------
-- users — one row per Cognito identity, plus our own voter-registration data
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cognito_sub     VARCHAR(64)     NOT NULL COMMENT 'Cognito subject claim: the stable user id',
    email           VARCHAR(255)    NOT NULL,
    email_verified  TINYINT(1)      NOT NULL DEFAULT 0,
    full_name       VARCHAR(150)    NOT NULL DEFAULT '',
    national_id     VARCHAR(40)     NULL     COMMENT 'National ID card number; NULL until registered',
    date_of_birth   DATE            NULL,
    region          VARCHAR(40)     NULL,
    phone           VARCHAR(30)     NULL,
    is_admin        TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_cognito_sub (cognito_sub),
    -- MySQL allows many NULLs in a UNIQUE index, so unregistered users are fine,
    -- but no two registered voters can share a national ID.
    UNIQUE KEY uniq_users_national_id (national_id),
    KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- candidates — who is on the ballot
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidates (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name   VARCHAR(150) NOT NULL,
    party       VARCHAR(150) NOT NULL DEFAULT '',
    slogan      VARCHAR(255) NOT NULL DEFAULT '',
    bio         TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- voter_receipts — WHO voted. Deliberately contains no candidate column.
-- The PRIMARY KEY on user_id is what enforces one person, one vote.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voter_receipts (
    user_id      BIGINT UNSIGNED NOT NULL,
    receipt_code CHAR(12)        NOT NULL,
    cast_at      DATETIME        NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uniq_receipt_code (receipt_code),
    CONSTRAINT fk_receipts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ballots — WHAT was voted for. Deliberately contains no user column.
-- cast_at is rounded to the hour by the application so that timestamps cannot
-- be used to re-link a ballot back to a receipt.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ballots (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id INT UNSIGNED    NOT NULL,
    region       VARCHAR(40)     NOT NULL,
    cast_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ballots_candidate (candidate_id),
    KEY idx_ballots_region (region),
    CONSTRAINT fk_ballots_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sessions — shared session store so any instance can serve any request
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128)    NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    payload       MEDIUMTEXT      NOT NULL,
    last_activity INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_last_activity (last_activity),
    KEY idx_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- election_settings — exactly one row, id = 1
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS election_settings (
    id             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    title          VARCHAR(200)     NOT NULL,
    is_open        TINYINT(1)       NOT NULL DEFAULT 0,
    opens_at       DATETIME         NULL,
    closes_at      DATETIME         NULL,
    results_public TINYINT(1)       NOT NULL DEFAULT 0,
    updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO election_settings (id, title, is_open, results_public)
     VALUES (1, 'Presidential Election — Class Demo', 0, 0)
ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------------
-- audit_log — every administrative action, so the class can trace changes
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NULL,
    action     VARCHAR(80)     NOT NULL,
    detail     VARCHAR(500)    NOT NULL DEFAULT '',
    ip_address VARCHAR(45)     NOT NULL DEFAULT '',
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Application database user.
-- The app never connects as the RDS master user. Change the password to the
-- one you stored in AWS Secrets Manager, then run these two statements.
-- 172.16.%.% limits the account to hosts inside our VPC CIDR.
-- ---------------------------------------------------------------------------
-- CREATE USER 'letsvote_app'@'172.16.%.%' IDENTIFIED BY 'PUT-THE-SECRETS-MANAGER-PASSWORD-HERE';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON letsvote.* TO 'letsvote_app'@'172.16.%.%';
-- FLUSH PRIVILEGES;
