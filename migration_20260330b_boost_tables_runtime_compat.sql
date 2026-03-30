-- Runtime compatibility migration for boost tables required by active-season leaderboard.
--
-- Replaces missing bootstrap state when migration_boosts_drops.sql was not run
-- during older/manual environment initialization.

CREATE TABLE IF NOT EXISTS boost_catalog (
    boost_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    tier_required TINYINT UNSIGNED NOT NULL,
    sigil_cost INT NOT NULL DEFAULT 1,
    scope ENUM('SELF', 'GLOBAL') NOT NULL DEFAULT 'SELF',
    duration_ticks BIGINT NOT NULL DEFAULT 60,
    modifier_id INT UNSIGNED NOT NULL,
    modifier_fp INT NOT NULL DEFAULT 0,
    max_stack INT NOT NULL DEFAULT 1,
    icon VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS active_boosts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    boost_id INT UNSIGNED NOT NULL,
    scope ENUM('SELF', 'GLOBAL') NOT NULL DEFAULT 'SELF',
    modifier_fp INT NOT NULL DEFAULT 0,
    activated_tick BIGINT NOT NULL,
    expires_tick BIGINT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_player_season (player_id, season_id, is_active),
    INDEX idx_season_active (season_id, is_active, expires_tick),
    INDEX idx_scope (season_id, scope, is_active),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id),
    FOREIGN KEY (boost_id) REFERENCES boost_catalog(boost_id)
) ENGINE=InnoDB;

INSERT INTO boost_catalog (name, description, tier_required, sigil_cost, scope, duration_ticks, modifier_id, modifier_fp, max_stack, icon)
SELECT * FROM (
    SELECT 'Trickle', '', 1, 1, 'SELF', 60, 1, 100000, 5, 'trickle'
    UNION ALL
    SELECT 'Surge', '', 2, 1, 'SELF', 180, 2, 150000, 5, 'surge'
    UNION ALL
    SELECT 'Flow', '', 3, 1, 'SELF', 360, 3, 250000, 2, 'flow'
    UNION ALL
    SELECT 'Tide', '', 4, 1, 'SELF', 720, 4, 500000, 1, 'tide'
    UNION ALL
    SELECT 'Age', '', 5, 1, 'SELF', 1440, 5, 1000000, 1, 'age'
) AS defaults
WHERE NOT EXISTS (SELECT 1 FROM boost_catalog LIMIT 1);
