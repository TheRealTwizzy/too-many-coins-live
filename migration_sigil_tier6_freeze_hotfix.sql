-- Hotfix: add Tier 6 sigils, freeze effects, and new vault targets.
-- Safe to run multiple times.

START TRANSACTION;

-- Add Tier 6 inventory support.
SET @sigils_t6_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'sigils_t6'
);
SET @sigils_t6_sql := IF(
    @sigils_t6_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN sigils_t6 INT NOT NULL DEFAULT 0 AFTER sigils_t5',
    'SELECT 1'
);
PREPARE stmt_sigils_t6 FROM @sigils_t6_sql;
EXECUTE stmt_sigils_t6;
DEALLOCATE PREPARE stmt_sigils_t6;

-- Freeze effect table (Tier 6 action).
CREATE TABLE IF NOT EXISTS active_freezes (
    freeze_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_player_id BIGINT UNSIGNED NOT NULL,
    target_player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    activated_tick BIGINT NOT NULL,
    expires_tick BIGINT NOT NULL,
    applied_count INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_freeze_target (target_player_id, season_id, is_active, expires_tick),
    INDEX idx_freeze_season (season_id, is_active, expires_tick),
    CONSTRAINT fk_active_freezes_source FOREIGN KEY (source_player_id) REFERENCES players(player_id),
    CONSTRAINT fk_active_freezes_target FOREIGN KEY (target_player_id) REFERENCES players(player_id),
    CONSTRAINT fk_active_freezes_season FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- Remove vault tiers 4/5 and enforce requested inventory/cost for tiers 1-3.
DELETE FROM season_vault WHERE tier IN (4, 5);

UPDATE season_vault
SET initial_supply = 500,
    remaining_supply = LEAST(remaining_supply, 500),
    current_cost_stars = 50,
    last_published_cost_stars = 50
WHERE tier = 1;

UPDATE season_vault
SET initial_supply = 250,
    remaining_supply = LEAST(remaining_supply, 250),
    current_cost_stars = 125,
    last_published_cost_stars = 125
WHERE tier = 2;

UPDATE season_vault
SET initial_supply = 100,
    remaining_supply = LEAST(remaining_supply, 100),
    current_cost_stars = 275,
    last_published_cost_stars = 275
WHERE tier = 3;

-- Keep canonical season vault config aligned for future seasons.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 500, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 50))),
    JSON_OBJECT('tier', 2, 'supply', 250, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 125))),
    JSON_OBJECT('tier', 3, 'supply', 100, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 275)))
)
WHERE JSON_VALID(vault_config) = 1;

COMMIT;
