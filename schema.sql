-- Too Many Coins - Database Schema
-- Based on canonical game design documentation

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- ACCOUNTS & IDENTITY
-- ============================================================

CREATE TABLE IF NOT EXISTS players (
    player_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    handle VARCHAR(16) NOT NULL,
    handle_lower VARCHAR(16) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Player', 'Moderator', 'Admin') NOT NULL DEFAULT 'Player',
    global_stars BIGINT NOT NULL DEFAULT 0,
    profile_visibility ENUM('PUBLIC', 'FRIENDS_ONLY', 'HIDDEN') NOT NULL DEFAULT 'PUBLIC',
    profile_deleted_at DATETIME DEFAULT NULL,
    handle_changed_at DATETIME DEFAULT NULL,
    online_current TINYINT(1) NOT NULL DEFAULT 0,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Session/participation state
    joined_season_id BIGINT UNSIGNED DEFAULT NULL,
    participation_enabled TINYINT(1) NOT NULL DEFAULT 0,
    idle_modal_active TINYINT(1) NOT NULL DEFAULT 0,
    activity_state ENUM('Active', 'Idle') NOT NULL DEFAULT 'Active',
    idle_since_tick BIGINT DEFAULT NULL,
    last_activity_tick BIGINT DEFAULT NULL,
    connection_seq INT UNSIGNED NOT NULL DEFAULT 0,
    session_token VARCHAR(128) DEFAULT NULL,
    INDEX idx_handle_lower (handle_lower),
    INDEX idx_session_token (session_token),
    INDEX idx_global_stars (global_stars DESC)
) ENGINE=InnoDB;

-- Handle history (handles are permanently non-reusable)
CREATE TABLE IF NOT EXISTS handle_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    old_handle VARCHAR(16) NOT NULL,
    new_handle VARCHAR(16) NOT NULL,
    old_handle_lower VARCHAR(16) NOT NULL,
    new_handle_lower VARCHAR(16) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_handle_lower (old_handle_lower),
    INDEX idx_new_handle_lower (new_handle_lower),
    FOREIGN KEY (player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

-- All handles ever used (for non-reuse enforcement)
CREATE TABLE IF NOT EXISTS handle_registry (
    handle_lower VARCHAR(16) PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SEASONS
-- ============================================================

CREATE TABLE IF NOT EXISTS seasons (
    season_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    start_time BIGINT NOT NULL,  -- Unix epoch seconds
    end_time BIGINT NOT NULL,    -- Unix epoch seconds (start_time + 28*86400)
    blackout_time BIGINT NOT NULL, -- end_time - 72*3600
    season_seed BINARY(32) NOT NULL,  -- Random 256-bit seed
    status ENUM('Scheduled', 'Active', 'Blackout', 'Expired') NOT NULL DEFAULT 'Scheduled',
    season_expired TINYINT(1) NOT NULL DEFAULT 0,
    expiration_finalized TINYINT(1) NOT NULL DEFAULT 0,
    -- Immutable per-season economy config
    base_ubi_active_per_tick BIGINT NOT NULL DEFAULT 100,
    base_ubi_idle_factor_fp INT NOT NULL DEFAULT 250000,  -- 0.25
    ubi_min_per_tick BIGINT NOT NULL DEFAULT 1,
    inflation_table JSON NOT NULL,
    hoarding_window_ticks INT NOT NULL DEFAULT 86400,
    target_spend_rate_per_tick BIGINT NOT NULL DEFAULT 50,
    hoarding_min_factor_fp INT NOT NULL DEFAULT 100000,  -- 0.1
    starprice_table JSON NOT NULL,
    star_price_cap BIGINT NOT NULL DEFAULT 10000,
    trade_fee_tiers JSON NOT NULL,
    trade_min_fee_coins BIGINT NOT NULL DEFAULT 10,
    -- Vault config
    vault_config JSON NOT NULL,  -- Per-tier: initial_supply, cost table
    -- Published surfaces
    current_star_price BIGINT NOT NULL DEFAULT 100,
    total_coins_supply BIGINT NOT NULL DEFAULT 0,
    total_coins_supply_end_of_tick BIGINT NOT NULL DEFAULT 0,
    -- Tick tracking
    last_processed_tick BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_start_time (start_time),
    INDEX idx_end_time (end_time)
) ENGINE=InnoDB;

-- Vault inventory per season per tier
CREATE TABLE IF NOT EXISTS season_vault (
    season_id BIGINT UNSIGNED NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,  -- 1-5
    initial_supply INT NOT NULL DEFAULT 0,
    remaining_supply INT NOT NULL DEFAULT 0,
    current_cost_stars BIGINT NOT NULL DEFAULT 0,
    last_published_cost_stars BIGINT DEFAULT NULL,
    PRIMARY KEY (season_id, tier),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- ============================================================
-- SEASON PARTICIPATION
-- ============================================================

CREATE TABLE IF NOT EXISTS season_participation (
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    coins BIGINT NOT NULL DEFAULT 0,
    coins_fractional_fp BIGINT NOT NULL DEFAULT 0,
    seasonal_stars BIGINT NOT NULL DEFAULT 0,
    -- Sigils by tier (1-5)
    sigils_t1 INT NOT NULL DEFAULT 0,
    sigils_t2 INT NOT NULL DEFAULT 0,
    sigils_t3 INT NOT NULL DEFAULT 0,
    sigils_t4 INT NOT NULL DEFAULT 0,
    sigils_t5 INT NOT NULL DEFAULT 0,
    -- Sigil drop tracking mirrors runtime state
    sigil_drops_total INT NOT NULL DEFAULT 0,
    eligible_ticks_since_last_drop BIGINT NOT NULL DEFAULT 0,
    -- Participation tracking
    participation_time_total BIGINT NOT NULL DEFAULT 0,
    participation_ticks_since_join BIGINT NOT NULL DEFAULT 0,
    active_ticks_total BIGINT NOT NULL DEFAULT 0,
    first_joined_at BIGINT DEFAULT NULL,
    last_exit_at BIGINT DEFAULT NULL,
    -- Spend tracking for hoarding suppression
    spend_window_total BIGINT NOT NULL DEFAULT 0,
    -- Lock-In snapshot
    lock_in_effect_tick BIGINT DEFAULT NULL,
    lock_in_snapshot_seasonal_stars BIGINT DEFAULT NULL,
    lock_in_snapshot_participation_time BIGINT DEFAULT NULL,
    -- End-of-season
    end_membership TINYINT(1) NOT NULL DEFAULT 0,
    final_rank INT DEFAULT NULL,
    final_seasonal_stars BIGINT DEFAULT NULL,
    global_stars_earned BIGINT NOT NULL DEFAULT 0,
    participation_bonus BIGINT NOT NULL DEFAULT 0,
    placement_bonus BIGINT NOT NULL DEFAULT 0,
    badge_awarded ENUM('first', 'second', 'third') DEFAULT NULL,
    -- Boost state
    active_boosts JSON DEFAULT NULL,
    PRIMARY KEY (player_id, season_id),
    INDEX idx_season_stars (season_id, seasonal_stars DESC),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- ============================================================
-- TRADES
-- ============================================================

CREATE TABLE IF NOT EXISTS trades (
    trade_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    initiator_id BIGINT UNSIGNED NOT NULL,
    acceptor_id BIGINT UNSIGNED NOT NULL,
    status ENUM('OPEN', 'ACCEPTED', 'DECLINED', 'CANCELED', 'EXPIRED', 'INVALIDATED') NOT NULL DEFAULT 'OPEN',
    -- Side A (initiator offers)
    side_a_coins BIGINT NOT NULL DEFAULT 0,
    side_a_sigils JSON NOT NULL,  -- [t1, t2, t3, t4, t5]
    -- Side B (acceptor offers)
    side_b_coins BIGINT NOT NULL DEFAULT 0,
    side_b_sigils JSON NOT NULL,
    -- Locked values
    surface_version_used BIGINT NOT NULL,
    declared_value_coins BIGINT NOT NULL DEFAULT 0,
    locked_fee_coins BIGINT NOT NULL DEFAULT 0,
    -- Timing
    created_tick BIGINT NOT NULL,
    expires_tick BIGINT NOT NULL,  -- created_tick + 3600
    resolved_tick BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_season_status (season_id, status),
    INDEX idx_initiator (initiator_id, status),
    INDEX idx_acceptor (acceptor_id, status),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id),
    FOREIGN KEY (initiator_id) REFERENCES players(player_id),
    FOREIGN KEY (acceptor_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

-- ============================================================
-- ECONOMY LEDGER (Audit Trail)
-- ============================================================

CREATE TABLE IF NOT EXISTS economy_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    global_tick BIGINT NOT NULL,
    season_id BIGINT UNSIGNED DEFAULT NULL,
    season_tick BIGINT DEFAULT NULL,
    player_id BIGINT UNSIGNED DEFAULT NULL,
    resource_type ENUM('Coins', 'SeasonalStars', 'GlobalStars', 'Sigil_T1', 'Sigil_T2', 'Sigil_T3', 'Sigil_T4', 'Sigil_T5') NOT NULL,
    direction ENUM('MINT', 'BURN', 'TRANSFER', 'RESET_DESTRUCTION') NOT NULL,
    amount BIGINT NOT NULL,
    category VARCHAR(50) NOT NULL,
    surface_version_used BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_season_tick (season_id, season_tick),
    INDEX idx_player (player_id)
) ENGINE=InnoDB;

-- ============================================================
-- BADGES
-- ============================================================

CREATE TABLE IF NOT EXISTS badges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    badge_type ENUM('seasonal_first', 'seasonal_second', 'seasonal_third', 'yearly_top10') NOT NULL,
    season_id BIGINT UNSIGNED DEFAULT NULL,
    year_seq INT DEFAULT NULL,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player (player_id),
    FOREIGN KEY (player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

-- ============================================================
-- COSMETICS
-- ============================================================

CREATE TABLE IF NOT EXISTS cosmetic_catalog (
    cosmetic_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('avatar_frame', 'name_color', 'profile_bg', 'title', 'effect') NOT NULL,
    price_global_stars INT NOT NULL,
    css_class VARCHAR(100) DEFAULT NULL,
    icon_url VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS player_cosmetics (
    player_id BIGINT UNSIGNED NOT NULL,
    cosmetic_id BIGINT UNSIGNED NOT NULL,
    equipped TINYINT(1) NOT NULL DEFAULT 0,
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, cosmetic_id),
    FOREIGN KEY (player_id) REFERENCES players(player_id),
    FOREIGN KEY (cosmetic_id) REFERENCES cosmetic_catalog(cosmetic_id)
) ENGINE=InnoDB;

-- ============================================================
-- CHAT
-- ============================================================

CREATE TABLE IF NOT EXISTS chat_messages (
    message_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_kind ENUM('GLOBAL', 'SEASON', 'DM') NOT NULL,
    season_id BIGINT UNSIGNED DEFAULT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED DEFAULT NULL,
    handle_snapshot VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    is_removed TINYINT(1) NOT NULL DEFAULT 0,
    is_admin_post TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel_season (channel_kind, season_id, created_at DESC),
    INDEX idx_dm (sender_id, recipient_id, created_at DESC),
    FOREIGN KEY (sender_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

-- ============================================================
-- SOCIAL GRAPH
-- ============================================================

CREATE TABLE IF NOT EXISTS friendships (
    player_a BIGINT UNSIGNED NOT NULL,
    player_b BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_a, player_b),
    FOREIGN KEY (player_a) REFERENCES players(player_id),
    FOREIGN KEY (player_b) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS friend_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_player BIGINT UNSIGNED NOT NULL,
    to_player BIGINT UNSIGNED NOT NULL,
    status ENUM('PENDING', 'ACCEPTED', 'DECLINED') NOT NULL DEFAULT 'PENDING',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_request (from_player, to_player),
    FOREIGN KEY (from_player) REFERENCES players(player_id),
    FOREIGN KEY (to_player) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blocks (
    blocker_id BIGINT UNSIGNED NOT NULL,
    blocked_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES players(player_id),
    FOREIGN KEY (blocked_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

-- ============================================================
-- YEARLY TRACKING
-- ============================================================

CREATE TABLE IF NOT EXISTS yearly_state (
    year_seq INT NOT NULL PRIMARY KEY,
    year_seed BINARY(32) NOT NULL,
    started_at BIGINT NOT NULL,
    seasons_expired INT NOT NULL DEFAULT 0,
    reset_executed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SERVER STATE
-- ============================================================

CREATE TABLE IF NOT EXISTS server_state (
    id INT NOT NULL DEFAULT 1 PRIMARY KEY,
    server_mode ENUM('NORMAL', 'MAINTENANCE_LOCKDOWN', 'READ_ONLY_ECONOMY', 'LOCKDOWN_CONNECTIONS', 'RATE_LIMIT_ACTIONS') NOT NULL DEFAULT 'NORMAL',
    lifecycle_phase ENUM('Alpha', 'Beta', 'Release') NOT NULL DEFAULT 'Alpha',
    current_year_seq INT NOT NULL DEFAULT 1,
    global_tick_index BIGINT NOT NULL DEFAULT 0,
    last_tick_processed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- PENDING ACTIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS pending_actions (
    action_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_data JSON DEFAULT NULL,
    accept_seq BIGINT UNSIGNED DEFAULT NULL,
    intake_tick BIGINT NOT NULL,
    resolution_tick BIGINT NOT NULL,
    effect_tick BIGINT NOT NULL,
    surface_version_used BIGINT DEFAULT NULL,
    locked_quote JSON DEFAULT NULL,
    status ENUM('PENDING', 'RESOLVED', 'REJECTED', 'INVALIDATED') NOT NULL DEFAULT 'PENDING',
    reason_code VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player_pending (player_id, status),
    INDEX idx_season_tick (season_id, resolution_tick),
    FOREIGN KEY (player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
