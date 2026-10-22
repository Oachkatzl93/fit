-- In die ausgewählte MySQL-/MariaDB-Datenbank importieren (z. B. phpMyAdmin).
-- InnoDB ist für atomare Einmalcodes und Refresh-Rotation erforderlich.
CREATE TABLE coach_accounts (
    id VARCHAR(64) PRIMARY KEY,
    api_key TEXT NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    generation INTEGER NOT NULL,
    updated_at BIGINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE coach_grants (
    id VARCHAR(64) PRIMARY KEY,
    athlete_id VARCHAR(64) NOT NULL,
    generation INTEGER NOT NULL,
    client_id VARCHAR(128) NOT NULL,
    redirect_uri TEXT NOT NULL,
    resource TEXT NOT NULL,
    scope VARCHAR(128) NOT NULL,
    challenge VARCHAR(128) NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE coach_tokens (
    id VARCHAR(64) PRIMARY KEY,
    kind VARCHAR(16) NOT NULL,
    family VARCHAR(64) NOT NULL,
    athlete_id VARCHAR(64) NOT NULL,
    generation INTEGER NOT NULL,
    client_id VARCHAR(128) NOT NULL,
    resource TEXT NOT NULL,
    scope VARCHAR(128) NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE INDEX coach_tokens_family ON coach_tokens (family);
CREATE INDEX coach_tokens_athlete ON coach_tokens (athlete_id);
CREATE INDEX coach_tokens_expiry ON coach_tokens (expires_at);
CREATE INDEX coach_grants_expiry ON coach_grants (expires_at);

CREATE TABLE coach_limits (
    id VARCHAR(64) PRIMARY KEY,
    hits INTEGER NOT NULL,
    expires_at BIGINT NOT NULL
) ENGINE=InnoDB;
