-- Star price stability patch: active-weighted pricing inputs and per-tick velocity clamps.
--
-- Summary of changes:
--   1. starprice_idle_weight_fp   – fixed-point weight applied to idle coins for pricing (default 0.25).
--   2. starprice_active_only      – toggle: when 1, price is based on active-player coins only.
--   3. starprice_max_upstep_fp    – max upward price movement per tick, fp (default ~0.2%/tick).
--   4. starprice_max_downstep_fp  – max downward price movement per tick, fp (default ~1.0%/tick).
--   5. coins_active_total         – telemetry: post-UBI coins held by Active players this tick.
--   6. coins_idle_total           – telemetry: post-UBI coins held by Idle players this tick.
--   7. effective_price_supply     – telemetry: effective supply used for star-price calculation.
--
-- Replaces: (new migration – no prior version)
-- Reason:   ADD COLUMN IF NOT EXISTS is not supported on all MySQL variants. This version uses
--           INFORMATION_SCHEMA guards with PREPARE/EXECUTE and is idempotent on MySQL 5.7+.
--           It also works with manual application via `mysql < file.sql`.
-- Safe to run multiple times.

-- starprice_idle_weight_fp -------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_idle_weight_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_idle_weight_fp INT NOT NULL DEFAULT 250000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- starprice_active_only ----------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_active_only');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_active_only TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- starprice_max_upstep_fp --------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_max_upstep_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_max_upstep_fp INT NOT NULL DEFAULT 2000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- starprice_max_downstep_fp ------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_max_downstep_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_max_downstep_fp INT NOT NULL DEFAULT 10000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- coins_active_total -------------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'coins_active_total');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN coins_active_total BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- coins_idle_total ---------------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'coins_idle_total');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN coins_idle_total BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- effective_price_supply ---------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'effective_price_supply');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN effective_price_supply BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Backfill safe defaults for currently live seasons (Active, Blackout, Scheduled).
-- Telemetry fields (coins_active_total, coins_idle_total, effective_price_supply) are left
-- at 0 since they will be populated on the next tick.
-- Velocity clamp defaults are conservative: ~0.2%/tick up, ~1.0%/tick down.
UPDATE seasons
SET starprice_idle_weight_fp  = 250000,
    starprice_active_only     = 0,
    starprice_max_upstep_fp   = 2000,
    starprice_max_downstep_fp = 10000
WHERE status IN ('Active', 'Blackout', 'Scheduled');
