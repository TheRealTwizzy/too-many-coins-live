-- Compat replacement for migration_20260329_sigil_drop_pacing_non_batch.sql
-- Adds queued drop counters so earned drops can be delivered gradually.
--
-- Replaces: migration_20260329_sigil_drop_pacing_non_batch.sql
-- Reason:   The original uses ADD COLUMN IF NOT EXISTS which is not supported on
--           all MySQL variants in production. This version uses a stored procedure
--           with INFORMATION_SCHEMA guards and is idempotent on all MySQL 5.7+.

DROP PROCEDURE IF EXISTS _tmc_sigil_drop_pacing_compat;

CREATE PROCEDURE _tmc_sigil_drop_pacing_compat()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'season_participation'
          AND COLUMN_NAME  = 'pending_rng_sigil_drops'
    ) THEN
        ALTER TABLE season_participation
            ADD COLUMN pending_rng_sigil_drops BIGINT NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'season_participation'
          AND COLUMN_NAME  = 'pending_pity_sigil_drops'
    ) THEN
        ALTER TABLE season_participation
            ADD COLUMN pending_pity_sigil_drops BIGINT NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'season_participation'
          AND COLUMN_NAME  = 'sigil_next_delivery_tick'
    ) THEN
        ALTER TABLE season_participation
            ADD COLUMN sigil_next_delivery_tick BIGINT NOT NULL DEFAULT 0;
    END IF;
END;

CALL _tmc_sigil_drop_pacing_compat();

DROP PROCEDURE IF EXISTS _tmc_sigil_drop_pacing_compat;
