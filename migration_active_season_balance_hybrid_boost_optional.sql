-- Optional migration: apply hybrid-boost economy rebalance to currently live seasons.
--
-- Use only if you intentionally want Active/Blackout/Scheduled seasons to adopt
-- the post-floor balancing knobs immediately.
--
-- Future seasons already pick up defaults from includes/game_time.php.

UPDATE seasons
SET
  inflation_table = '[{"x":0,"factor_fp":1000000},{"x":50000,"factor_fp":620000},{"x":200000,"factor_fp":280000},{"x":800000,"factor_fp":110000},{"x":3000000,"factor_fp":50000}]',
  starprice_table = '[{"m":0,"price":100},{"m":25000,"price":300},{"m":100000,"price":900},{"m":500000,"price":3200},{"m":2000000,"price":11000}]',
  target_spend_rate_per_tick = 18,
  hoarding_min_factor_fp = 90000,
  star_price_cap = 12000,
  current_star_price = GREATEST(current_star_price, 100)
WHERE status IN ('Scheduled', 'Active', 'Blackout');

-- Rollback guidance (manual):
-- Restore prior seasonal values from a DB snapshot, or re-run with previous
-- inflation/starprice JSON and hoarding knobs for affected season_id rows.
