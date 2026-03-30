-- Economy Scale Tuning Migration (2026-03-30)
-- Production-safe and idempotent.
-- Applies star-price stability and trade-fee parameters to Active seasons only.

UPDATE seasons
SET
    starprice_idle_weight_fp   = 15000,
    starprice_active_only      = 0,
    starprice_max_upstep_fp    = 1500,
    starprice_max_downstep_fp  = 6000,
    trade_min_fee_coins        = 25,
    trade_fee_tiers            = JSON_ARRAY(
        JSON_OBJECT('threshold', 0,        'rate_fp', 5000),
        JSON_OBJECT('threshold', 100000,   'rate_fp', 8000),
        JSON_OBJECT('threshold', 1000000,  'rate_fp', 12000),
        JSON_OBJECT('threshold', 10000000, 'rate_fp', 18000)
    )
WHERE status = 'Active';
