-- Add explicit hoarding-sink tuning fields and seed active seasons safely.
-- This migration is designed for live rollout: active seasons receive conservative
-- tuning defaults immediately, but sink remains disabled until explicitly enabled.

ALTER TABLE seasons
    ADD COLUMN IF NOT EXISTS hoarding_sink_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS hoarding_safe_hours INT NOT NULL DEFAULT 12,
    ADD COLUMN IF NOT EXISTS hoarding_safe_min_coins BIGINT NOT NULL DEFAULT 20000,
    ADD COLUMN IF NOT EXISTS hoarding_tier1_excess_cap BIGINT NOT NULL DEFAULT 50000,
    ADD COLUMN IF NOT EXISTS hoarding_tier2_excess_cap BIGINT NOT NULL DEFAULT 200000,
    ADD COLUMN IF NOT EXISTS hoarding_tier1_rate_hourly_fp INT NOT NULL DEFAULT 200,
    ADD COLUMN IF NOT EXISTS hoarding_tier2_rate_hourly_fp INT NOT NULL DEFAULT 500,
    ADD COLUMN IF NOT EXISTS hoarding_tier3_rate_hourly_fp INT NOT NULL DEFAULT 1000,
    ADD COLUMN IF NOT EXISTS hoarding_sink_cap_ratio_fp INT NOT NULL DEFAULT 350000,
    ADD COLUMN IF NOT EXISTS hoarding_idle_multiplier_fp INT NOT NULL DEFAULT 1250000;

ALTER TABLE season_participation
    ADD COLUMN IF NOT EXISTS hoarding_sink_total BIGINT NOT NULL DEFAULT 0;

-- Backfill currently live seasons with conservative starter tuning.
-- Keep sink disabled for controlled cohort enablement.
UPDATE seasons
SET hoarding_sink_enabled = 0,
    hoarding_safe_hours = 12,
    hoarding_safe_min_coins = 20000,
    hoarding_tier1_excess_cap = 50000,
    hoarding_tier2_excess_cap = 200000,
    hoarding_tier1_rate_hourly_fp = 200,
    hoarding_tier2_rate_hourly_fp = 500,
    hoarding_tier3_rate_hourly_fp = 1000,
    hoarding_sink_cap_ratio_fp = 350000,
    hoarding_idle_multiplier_fp = 1250000
WHERE status IN ('Active', 'Blackout');
