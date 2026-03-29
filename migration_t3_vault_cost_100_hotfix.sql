-- Hotfix: set T3 sigil vault price to 100 stars.
-- Updates live season_vault rows and the canonical vault_config JSON on active/scheduled
-- seasons so new reads and future season seeds both use the correct price.
-- Safe to run multiple times.

START TRANSACTION;

-- Update live vault rows for active and scheduled seasons.
UPDATE season_vault sv
JOIN seasons s ON s.season_id = sv.season_id
SET sv.current_cost_stars      = 100,
    sv.last_published_cost_stars = 100
WHERE sv.tier = 3
  AND s.status IN ('Scheduled', 'Active', 'Blackout');

-- Keep the canonical vault_config JSON aligned for future reads / new season defaults.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 1000, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 5))),
    JSON_OBJECT('tier', 2, 'supply', 500,  'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 25))),
    JSON_OBJECT('tier', 3, 'supply', 250,  'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 100)))
)
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND JSON_VALID(vault_config) = 1;

COMMIT;
