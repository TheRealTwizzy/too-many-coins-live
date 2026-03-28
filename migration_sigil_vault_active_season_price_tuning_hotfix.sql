-- Tune active-season Sigil Vault tier pricing to current balance targets.
-- Keeps currently active season_vault prices and canonical seasons.vault_config aligned.

UPDATE season_vault sv
JOIN seasons s ON s.season_id = sv.season_id
SET
    sv.current_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 20
        WHEN 3 THEN 50
        ELSE sv.current_cost_stars
    END,
    sv.last_published_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 20
        WHEN 3 THEN 50
        ELSE sv.last_published_cost_stars
    END
WHERE s.status = 'Active' AND sv.tier BETWEEN 1 AND 3;

UPDATE seasons
SET vault_config = JSON_SET(
    vault_config,
    '$[0].cost_table[0].cost', 5,
    '$[1].cost_table[0].cost', 20,
    '$[2].cost_table[0].cost', 50
)
WHERE status = 'Active' AND vault_config IS NOT NULL;
