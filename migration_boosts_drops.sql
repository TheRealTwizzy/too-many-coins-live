-- Migration: Add Boost system and Sigil drop tracking
-- Too Many Coins

-- ============================================================
-- BOOST CATALOG (per-season configurable boost types)
-- ============================================================

CREATE TABLE IF NOT EXISTS boost_catalog (
    boost_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    tier_required TINYINT UNSIGNED NOT NULL,  -- Sigil tier needed (1-5)
    sigil_cost INT NOT NULL DEFAULT 1,        -- Number of sigils consumed
    scope ENUM('SELF', 'GLOBAL') NOT NULL DEFAULT 'SELF',
    duration_ticks BIGINT NOT NULL DEFAULT 60,    -- How long the boost lasts (1 tick/min default)
    modifier_id INT UNSIGNED NOT NULL,         -- Links to UBI modifier system
    modifier_fp INT NOT NULL DEFAULT 0,        -- Fixed-point modifier value (added to UBI multiplier)
    max_stack INT NOT NULL DEFAULT 1,          -- Max concurrent activations of this boost per player
    icon VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- ACTIVE BOOSTS (per player per season)
-- ============================================================

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

-- ============================================================
-- SIGIL DROP TRACKING (pity counter + throttle window)
-- ============================================================

CREATE TABLE IF NOT EXISTS sigil_drop_tracking (
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    eligible_ticks_since_last_drop BIGINT NOT NULL DEFAULT 0,
    total_drops INT NOT NULL DEFAULT 0,
    last_drop_tick BIGINT DEFAULT NULL,
    PRIMARY KEY (player_id, season_id),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- Rolling window drop log for throttle enforcement
CREATE TABLE IF NOT EXISTS sigil_drop_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    drop_tick BIGINT NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    source ENUM('RNG', 'PITY') NOT NULL DEFAULT 'RNG',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player_season_tick (player_id, season_id, drop_tick),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DEFAULT BOOST CATALOG
-- ============================================================

INSERT INTO boost_catalog (name, description, tier_required, sigil_cost, scope, duration_ticks, modifier_id, modifier_fp, max_stack, icon) VALUES
-- Tier I: Self UBI boost
('Trickle', 'Increases your UBI by 25% for 15 minutes.', 1, 1, 'SELF', 15, 1, 250000, 3, 'trickle'),
-- Tier II: Self UBI boost
('Surge', 'Increases your UBI by 50% for 30 minutes.', 2, 1, 'SELF', 30, 2, 500000, 2, 'surge'),
-- Tier III: Self UBI boost
('Flow', 'Increases your UBI by 75% for 1 hour.', 3, 1, 'SELF', 60, 3, 750000, 1, 'flow'),
-- Tier IV: Global UBI boost (affects all participants)
('Tide', 'Increases UBI by 15% for all players for 24 hours.', 4, 1, 'GLOBAL', 1440, 4, 150000, 1, 'tide'),
-- Tier V: Powerful global UBI boost
('Age', 'Increases UBI by 30% for all players for 48 hours.', 5, 1, 'GLOBAL', 2880, 5, 300000, 1, 'age');
