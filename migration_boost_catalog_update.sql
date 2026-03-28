-- Migration: Update boost catalog to canonical durations and clear descriptions.
-- Boost durations are now 12x longer; descriptions are removed from catalog entries.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 1 minute.

START TRANSACTION;

UPDATE boost_catalog SET name = 'Trickle', description = '', duration_ticks = 720,  modifier_fp = 100000, max_stack = 5, sigil_cost = 1 WHERE tier_required = 1;
UPDATE boost_catalog SET name = 'Surge',   description = '', duration_ticks = 2160, modifier_fp = 150000, max_stack = 5, sigil_cost = 1 WHERE tier_required = 2;
UPDATE boost_catalog SET name = 'Flow',    description = '', duration_ticks = 4320, modifier_fp = 250000, max_stack = 2, sigil_cost = 1 WHERE tier_required = 3;
UPDATE boost_catalog SET name = 'Tide',    description = '', duration_ticks = 8640, modifier_fp = 500000, max_stack = 1, sigil_cost = 1 WHERE tier_required = 4;
UPDATE boost_catalog SET name = 'Age',     description = '', duration_ticks = 17280, modifier_fp = 1000000, max_stack = 1, sigil_cost = 1 WHERE tier_required = 5;

COMMIT;
