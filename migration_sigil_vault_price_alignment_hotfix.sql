-- Align Sigil Vault tier pricing across existing seasons and future cost recalculations.
-- This keeps charged prices (season_vault) and canonical config (seasons.vault_config) consistent.

UPDATE season_vault
SET
    current_cost_stars = CASE tier
        WHEN 1 THEN 10
        WHEN 2 THEN 25
        WHEN 3 THEN 50
        WHEN 4 THEN 150
        WHEN 5 THEN 300
        ELSE current_cost_stars
    END,
    last_published_cost_stars = CASE tier
        WHEN 1 THEN 10
        WHEN 2 THEN 25
        WHEN 3 THEN 50
        WHEN 4 THEN 150
        WHEN 5 THEN 300
        ELSE last_published_cost_stars
    END
WHERE tier BETWEEN 1 AND 5;

UPDATE seasons
SET vault_config = JSON_SET(
    COALESCE(vault_config, JSON_ARRAY()),
    '$[0].cost_table[0].cost', 10,
    '$[1].cost_table[0].cost', 25,
    '$[2].cost_table[0].cost', 50,
    '$[3].cost_table[0].cost', 150,
    '$[4].cost_table[0].cost', 300
)
WHERE vault_config IS NOT NULL;
