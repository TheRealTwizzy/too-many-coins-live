-- Hotfix: raise Tier 3 sigil vault cost to 120 Stars.
-- Safe to run multiple times.

START TRANSACTION;

-- Update current cost for live vault inventory (active/upcoming seasons only).
UPDATE season_vault sv
JOIN seasons s ON s.season_id = sv.season_id
SET sv.current_cost_stars = 120
WHERE sv.tier = 3
  AND s.status IN ('Scheduled', 'Active', 'Blackout');

-- Keep canonical seasons.vault_config aligned for future reads/new season defaults.
UPDATE seasons
SET vault_config = JSON_SET(
    vault_config,
    '$[2].cost_table[0].cost', 120
)
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND JSON_VALID(vault_config) = 1
  AND JSON_UNQUOTE(JSON_EXTRACT(vault_config, '$[2].tier')) = '3';

COMMIT;
