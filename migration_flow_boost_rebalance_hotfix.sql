-- Hotfix: rebalance Flow boost (tier 3) modifier and stack limit.
-- Changes:
--   modifier_fp: 200000 (20%) -> 250000 (25%)  – Initial UBI and Purchased UBI each +25%
--   max_stack:   5            ->  4             – Power stack limit reduced to 4
-- Safe to run multiple times.

START TRANSACTION;

UPDATE boost_catalog
SET modifier_fp = 250000, max_stack = 4
WHERE tier_required = 3;

COMMIT;
