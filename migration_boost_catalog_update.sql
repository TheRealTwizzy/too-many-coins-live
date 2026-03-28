-- Migration: Update boost catalog to canonical durations and clear descriptions.
-- Canonical durations: 1h, 3h, 6h, 12h, 24h (1 tick = 60 seconds).
-- Safe to run multiple times (idempotent updates).

START TRANSACTION;

UPDATE boost_catalog SET name = 'Trickle', description = '', duration_ticks = 60,   modifier_fp = 50000,  max_stack = 5, sigil_cost = 1 WHERE tier_required = 1;
UPDATE boost_catalog SET name = 'Surge',   description = '', duration_ticks = 180,  modifier_fp = 150000, max_stack = 5, sigil_cost = 1 WHERE tier_required = 2;
UPDATE boost_catalog SET name = 'Flow',    description = '', duration_ticks = 360,  modifier_fp = 250000, max_stack = 5, sigil_cost = 1 WHERE tier_required = 3;
UPDATE boost_catalog SET name = 'Tide',    description = '', duration_ticks = 720,  modifier_fp = 500000, max_stack = 3, sigil_cost = 1 WHERE tier_required = 4;
UPDATE boost_catalog SET name = 'Age',     description = '', duration_ticks = 1440, modifier_fp = 1000000, max_stack = 1, sigil_cost = 1 WHERE tier_required = 5;

COMMIT;
