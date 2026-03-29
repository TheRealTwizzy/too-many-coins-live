-- Compat replacement for migration_20260329_hoarding_sink_active_seasons_hotfix.sql
-- Adds explicit hoarding-sink tuning fields and seeds active seasons safely.
-- This migration is designed for live rollout: active seasons receive conservative
-- tuning defaults immediately, but sink remains disabled until explicitly enabled.
--
-- Replaces: migration_20260329_hoarding_sink_active_seasons_hotfix.sql
-- Reason:   The original uses ADD COLUMN IF NOT EXISTS which is not supported on
--           all MySQL variants in production. This version uses a stored procedure
--           with INFORMATION_SCHEMA guards and is idempotent on all MySQL 5.7+.

DROP PROCEDURE IF EXISTS _tmc_hoarding_sink_compat;

CREATE PROCEDURE _tmc_hoarding_sink_compat()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_sink_enabled'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_sink_enabled TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_safe_hours'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_safe_hours INT NOT NULL DEFAULT 12;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_safe_min_coins'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_safe_min_coins BIGINT NOT NULL DEFAULT 20000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_tier1_excess_cap'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_tier1_excess_cap BIGINT NOT NULL DEFAULT 50000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_tier2_excess_cap'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_tier2_excess_cap BIGINT NOT NULL DEFAULT 200000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_tier1_rate_hourly_fp'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_tier1_rate_hourly_fp INT NOT NULL DEFAULT 200;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_tier2_rate_hourly_fp'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_tier2_rate_hourly_fp INT NOT NULL DEFAULT 500;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_tier3_rate_hourly_fp'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_tier3_rate_hourly_fp INT NOT NULL DEFAULT 1000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_sink_cap_ratio_fp'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_sink_cap_ratio_fp INT NOT NULL DEFAULT 350000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'seasons'
          AND COLUMN_NAME  = 'hoarding_idle_multiplier_fp'
    ) THEN
        ALTER TABLE seasons
            ADD COLUMN hoarding_idle_multiplier_fp INT NOT NULL DEFAULT 1250000;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'season_participation'
          AND COLUMN_NAME  = 'hoarding_sink_total'
    ) THEN
        ALTER TABLE season_participation
            ADD COLUMN hoarding_sink_total BIGINT NOT NULL DEFAULT 0;
    END IF;
END;

CALL _tmc_hoarding_sink_compat();

DROP PROCEDURE IF EXISTS _tmc_hoarding_sink_compat;

-- Backfill currently live seasons with conservative starter tuning.
-- 'Active' = season is open for participation; 'Blackout' = season is in its
-- end-of-season cooldown period but still live in the DB.
-- Both are considered live/in-progress seasons and should receive tuning defaults.
-- Keep sink disabled (hoarding_sink_enabled = 0) for controlled cohort enablement.
UPDATE seasons
SET hoarding_sink_enabled        = 0,
    hoarding_safe_hours           = 12,
    hoarding_safe_min_coins       = 20000,
    hoarding_tier1_excess_cap     = 50000,
    hoarding_tier2_excess_cap     = 200000,
    hoarding_tier1_rate_hourly_fp = 200,
    hoarding_tier2_rate_hourly_fp = 500,
    hoarding_tier3_rate_hourly_fp = 1000,
    hoarding_sink_cap_ratio_fp    = 350000,
    hoarding_idle_multiplier_fp   = 1250000
WHERE status IN ('Active', 'Blackout');
