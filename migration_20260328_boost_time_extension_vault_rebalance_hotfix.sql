-- Hotfix: rebalance boost power/stack values and sigil vault supply+pricing.
-- Notes:
-- - Boost purchase-time extension amounts are code-defined in includes/boost_catalog.php.
-- - This migration aligns persisted DB rows that are still read directly.
-- Safe to run multiple times.

START TRANSACTION;

-- Align boost catalog power and stack limits to requested values.
UPDATE boost_catalog
SET name = 'Trickle', description = '', modifier_fp = 50000, max_stack = 20, sigil_cost = 1
WHERE tier_required = 1;

UPDATE boost_catalog
SET name = 'Surge', description = '', modifier_fp = 100000, max_stack = 10, sigil_cost = 1
WHERE tier_required = 2;

UPDATE boost_catalog
SET name = 'Flow', description = '', modifier_fp = 200000, max_stack = 5, sigil_cost = 1
WHERE tier_required = 3;

UPDATE boost_catalog
SET name = 'Tide', description = '', modifier_fp = 500000, max_stack = 2, sigil_cost = 1
WHERE tier_required = 4;

UPDATE boost_catalog
SET name = 'Age', description = '', modifier_fp = 1000000, max_stack = 1, sigil_cost = 1
WHERE tier_required = 5;

-- Align live vault inventory limits and prices (tiers 1-3).
UPDATE season_vault sv
JOIN seasons s ON s.season_id = sv.season_id
SET
    sv.initial_supply = CASE sv.tier
        WHEN 1 THEN 1000
        WHEN 2 THEN 500
        WHEN 3 THEN 250
        ELSE sv.initial_supply
    END,
    sv.remaining_supply = CASE sv.tier
        WHEN 1 THEN LEAST(sv.remaining_supply, 1000)
        WHEN 2 THEN LEAST(sv.remaining_supply, 500)
        WHEN 3 THEN LEAST(sv.remaining_supply, 250)
        ELSE sv.remaining_supply
    END,
    sv.current_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 20
        WHEN 3 THEN 80
        ELSE sv.current_cost_stars
    END,
    sv.last_published_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 20
        WHEN 3 THEN 80
        ELSE sv.last_published_cost_stars
    END
WHERE s.status IN ('Scheduled', 'Active', 'Blackout')
  AND sv.tier BETWEEN 1 AND 3;

-- Keep canonical seasons.vault_config aligned for future reads/new season defaults.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 1000, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 5))),
    JSON_OBJECT('tier', 2, 'supply', 500, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 20))),
    JSON_OBJECT('tier', 3, 'supply', 250, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 80)))
)
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND JSON_VALID(vault_config) = 1;

COMMIT;
