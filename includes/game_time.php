<?php
/**
 * Too Many Coins - Game Time & Season Management
 * 
 * Approach: Game time is a quantized virtual clock that starts at 0 when the server boots.
 * Base tick cadence is configurable via TICK_REAL_SECONDS (default: 60 real seconds per tick).
 * TIME_SCALE can accelerate the clock for development/testing.
 * Season 1 starts at game time = 1 so it becomes available immediately after startup.
 * This ensures the first season is immediately active for players.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class GameTime {
    
    private static $serverEpoch = null;
    private static $legacyScaleChecked = false;
    
    /**
     * Get the real-world Unix timestamp when the server was first initialized
     */
    private static function getServerEpoch() {
        if (self::$serverEpoch !== null) return self::$serverEpoch;
        
        $db = Database::getInstance();
        $state = $db->fetch("SELECT created_at FROM server_state WHERE id = 1");
        if ($state) {
            self::$serverEpoch = strtotime($state['created_at'] . ' UTC');
        } else {
            self::$serverEpoch = time();
        }
        return self::$serverEpoch;
    }
    
    /**
     * Get current game time.
     * Game time = floor(real_elapsed_seconds / TICK_REAL_SECONDS) * TIME_SCALE
     */
    public static function now() {
        $realElapsed = time() - self::getServerEpoch();
        $baseTicks = intdiv(max(0, $realElapsed), TICK_REAL_SECONDS);
        return max(0, $baseTicks * TIME_SCALE);
    }
    
    /**
     * Get the global tick index (= game time)
     */
    public static function globalTick() {
        return self::now();
    }

    /**
     * Get the real Unix timestamp for the start of a given game tick.
     * This is the inverse of now(): the wall-clock moment at which gameTime
     * first equals $tick.
     *
     * Used to compute a stable, absolute expires_at_real for active boosts so
     * that the client countdown reflects the true expiry moment regardless of
     * when the API response is generated within a tick.
     *
     * Tick values in normal gameplay are in the thousands-to-millions range,
     * so the product $tick * TICK_REAL_SECONDS stays well within PHP's 64-bit
     * integer range before the division by TIME_SCALE is applied.
     */
    public static function tickStartRealUnix(int $tick): int {
        return (int)(self::getServerEpoch() + intdiv($tick * TICK_REAL_SECONDS, TIME_SCALE));
    }
    
    /**
     * Calculate season start time from season sequence number.
     * Season 1 starts at game time = 1.
     * Subsequent seasons start every SEASON_CADENCE game-seconds apart.
     */
    public static function seasonStartTime($seasonSeq) {
        return 1 + (($seasonSeq - 1) * SEASON_CADENCE);
    }
    
    /**
     * Calculate season end time
     */
    public static function seasonEndTime($startTime) {
        return $startTime + SEASON_DURATION;
    }
    
    /**
     * Calculate blackout start time
     */
    public static function blackoutStartTime($endTime) {
        return $endTime - BLACKOUT_DURATION;
    }
    
    /**
     * Get season tick index for a given global tick and season
     */
    public static function seasonTick($seasonStartTime, $globalTick) {
        return $globalTick - $seasonStartTime;
    }
    
    /**
     * Determine season status at a given time
     */
    public static function getSeasonStatus($season, $gameTime = null) {
        if ($gameTime === null) $gameTime = self::now();
        
        $start = (int)$season['start_time'];
        $end = (int)$season['end_time'];
        $blackout = (int)$season['blackout_time'];
        
        if ($gameTime < $start) return 'Scheduled';
        if ($gameTime >= $end) return 'Expired';
        if ($gameTime >= $blackout) return 'Blackout';
        return 'Active';
    }
    
    /**
     * Ensure seasons exist for the current time window
     */
    public static function ensureSeasons() {
        $db = Database::getInstance();
        self::maybeMigrateLegacyTickScale($db);
        self::rebalanceExistingSeasons($db);
        $gameTime = self::now();
        
        // Find which season sequence we're in
        $elapsed = max(0, $gameTime - 1);
        $currentSeq = max(1, (int)floor($elapsed / SEASON_CADENCE) + 1);
        
        // Create seasons from 1 to (currentSeq + 2)
        $startSeq = max(1, $currentSeq - 1);
        $endSeq = $currentSeq + 2;
        
        for ($seq = $startSeq; $seq <= $endSeq; $seq++) {
            $startTime = self::seasonStartTime($seq);
            $endTime = self::seasonEndTime($startTime);
            $blackoutTime = self::blackoutStartTime($endTime);
            
            // Check if season already exists by start_time
            $existing = $db->fetch(
                "SELECT season_id FROM seasons WHERE start_time = ?",
                [$startTime]
            );
            
            if (!$existing) {
                $seed = random_bytes(32);
                
                $inflationTable = json_encode([
                    ['x' => 0, 'factor_fp' => 1000000],
                    ['x' => 50000, 'factor_fp' => 700000],
                    ['x' => 200000, 'factor_fp' => 350000],
                    ['x' => 800000, 'factor_fp' => 150000],
                    ['x' => 3000000, 'factor_fp' => 70000]
                ]);
                
                $starpriceTable = json_encode([
                    ['m' => 0, 'price' => 100],
                    ['m' => 25000, 'price' => 250],
                    ['m' => 100000, 'price' => 700],
                    ['m' => 500000, 'price' => 2500],
                    ['m' => 2000000, 'price' => 9000]
                ]);
                
                $tradeFeeTiers = json_encode([
                    ['threshold' => 0, 'rate_fp' => 50000],
                    ['threshold' => 10000, 'rate_fp' => 30000],
                    ['threshold' => 100000, 'rate_fp' => 20000]
                ]);
                
                $vaultConfig = json_encode([
                    ['tier' => 1, 'supply' => 2500, 'cost_table' => [['remaining' => 1, 'cost' => 10]]],
                    ['tier' => 2, 'supply' => 1000, 'cost_table' => [['remaining' => 1, 'cost' => 25]]],
                    ['tier' => 3, 'supply' => 500,  'cost_table' => [['remaining' => 1, 'cost' => 50]]],
                    ['tier' => 4, 'supply' => 250,  'cost_table' => [['remaining' => 1, 'cost' => 100]]],
                    ['tier' => 5, 'supply' => 100,  'cost_table' => [['remaining' => 1, 'cost' => 250]]]
                ]);
                
                $seasonId = $db->insert(
                    "INSERT INTO seasons (start_time, end_time, blackout_time, season_seed, 
                     status, base_ubi_active_per_tick, base_ubi_idle_factor_fp, ubi_min_per_tick,
                     inflation_table, hoarding_window_ticks, target_spend_rate_per_tick, hoarding_min_factor_fp,
                     starprice_table, star_price_cap, trade_fee_tiers, trade_min_fee_coins,
                     vault_config, current_star_price, last_processed_tick)
                     VALUES (?, ?, ?, ?, 'Scheduled', 30, 250000, 1, ?, ?, 15, 100000, ?, 10000, ?, 10, ?, 100, ?)",
                    [$startTime, $endTime, $blackoutTime, $seed,
                     $inflationTable, HOARDING_WINDOW_TICKS, $starpriceTable, $tradeFeeTiers, $vaultConfig, $startTime]
                );
                
                // Create vault inventory
                $vaultItems = json_decode($vaultConfig, true);
                foreach ($vaultItems as $item) {
                    $initialCost = $item['cost_table'][0]['cost'];
                    $db->query(
                        "INSERT INTO season_vault (season_id, tier, initial_supply, remaining_supply, current_cost_stars, last_published_cost_stars)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$seasonId, $item['tier'], $item['supply'], $item['supply'], $initialCost, $initialCost]
                    );
                }
            }
        }
        
        // Update season statuses based on current game time
        self::updateSeasonStatuses();
    }
    
    /**
     * Update all season statuses based on current game time
     */
    public static function updateSeasonStatuses() {
        $db = Database::getInstance();
        $gameTime = self::now();
        
        $db->query(
            "UPDATE seasons SET status = 'Active' 
             WHERE start_time <= ? AND ? < blackout_time AND status = 'Scheduled'",
            [$gameTime, $gameTime]
        );
        
        $db->query(
            "UPDATE seasons SET status = 'Blackout' 
             WHERE blackout_time <= ? AND ? < end_time AND status IN ('Scheduled', 'Active')",
            [$gameTime, $gameTime]
        );
        
        $db->query(
            "UPDATE seasons SET status = 'Expired', season_expired = 1
             WHERE end_time <= ? AND status IN ('Scheduled', 'Active', 'Blackout')",
            [$gameTime]
        );
    }
    
    /**
     * Get all active/visible seasons
     */
    public static function getVisibleSeasons() {
        $db = Database::getInstance();
        $gameTime = self::now();
        
        return $db->fetchAll(
            "SELECT s.*, 
                    (SELECT COUNT(*) FROM season_participation sp 
                     JOIN players p ON p.player_id = sp.player_id 
                     WHERE sp.season_id = s.season_id AND p.joined_season_id = s.season_id AND p.participation_enabled = 1) as player_count
             FROM seasons s 
             ORDER BY s.start_time ASC
             LIMIT 10"
        );
    }
    
    /**
     * Format time remaining in game ticks to human-readable
     */
    public static function formatTimeRemaining($gameTicks) {
        if ($gameTicks <= 0) return 'Ended';
        
        // Convert game ticks to real seconds for display
        $realSeconds = max(0, intdiv($gameTicks * TICK_REAL_SECONDS, TIME_SCALE));
        
        $days = floor($realSeconds / 86400);
        $hours = floor(($realSeconds % 86400) / 3600);
        $mins = floor(($realSeconds % 3600) / 60);
        
        // Show game time
        $gameDays = floor($gameTicks / 86400);
        $gameHours = floor(($gameTicks % 86400) / 3600);
        
        if ($days > 0) return "{$gameDays}d {$gameHours}h (real: {$days}d {$hours}h)";
        if ($hours > 0) return "{$gameHours}h (real: {$hours}h {$mins}m)";
        if ($mins > 0) return "real: {$mins}m";
        return "< 1m";
    }

    /**
     * One-time migration of legacy second-based tick storage to minute-based storage.
     */
    private static function maybeMigrateLegacyTickScale($db) {
        if (self::$legacyScaleChecked) {
            return;
        }
        self::$legacyScaleChecked = true;

        if (TICK_REAL_SECONDS !== 60 || TIME_SCALE !== 1) {
            return;
        }

        $dur = $db->fetch("SELECT MAX(end_time - start_time) AS max_duration FROM seasons");
        $maxDuration = (int)($dur['max_duration'] ?? 0);

        // Legacy seasons used 2,419,200 ticks (seconds) for 28 days.
        if ($maxDuration < 200000) {
            return;
        }

        $db->beginTransaction();
        try {
            $db->query(
                "UPDATE seasons SET
                 start_time = CEIL(start_time / 60),
                 end_time = CEIL(end_time / 60),
                 blackout_time = CEIL(blackout_time / 60),
                 last_processed_tick = CEIL(last_processed_tick / 60)"
            );

            $db->query(
                "UPDATE server_state SET
                 global_tick_index = CEIL(global_tick_index / 60)"
            );

            $db->query(
                "UPDATE yearly_state SET
                 started_at = CEIL(started_at / 60)"
            );

            $db->query(
                "UPDATE players SET
                 idle_since_tick = CASE WHEN idle_since_tick IS NULL THEN NULL ELSE CEIL(idle_since_tick / 60) END,
                 last_activity_tick = CASE WHEN last_activity_tick IS NULL THEN NULL ELSE CEIL(last_activity_tick / 60) END"
            );

            $db->query(
                "UPDATE season_participation SET
                 participation_time_total = CEIL(participation_time_total / 60),
                 participation_ticks_since_join = CEIL(participation_ticks_since_join / 60),
                 active_ticks_total = CEIL(active_ticks_total / 60),
                 first_joined_at = CASE WHEN first_joined_at IS NULL THEN NULL ELSE CEIL(first_joined_at / 60) END,
                 last_exit_at = CASE WHEN last_exit_at IS NULL THEN NULL ELSE CEIL(last_exit_at / 60) END,
                 lock_in_effect_tick = CASE WHEN lock_in_effect_tick IS NULL THEN NULL ELSE CEIL(lock_in_effect_tick / 60) END,
                 lock_in_snapshot_participation_time = CASE WHEN lock_in_snapshot_participation_time IS NULL THEN NULL ELSE CEIL(lock_in_snapshot_participation_time / 60) END"
            );

            $hasEligibleTicks = $db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'season_participation'
                   AND COLUMN_NAME = 'eligible_ticks_since_last_drop'"
            );
            if ((int)($hasEligibleTicks['cnt'] ?? 0) > 0) {
                $db->query(
                    "UPDATE season_participation
                     SET eligible_ticks_since_last_drop = CEIL(eligible_ticks_since_last_drop / 60)"
                );
            }

            $db->query(
                "UPDATE trades SET
                 created_tick = CEIL(created_tick / 60),
                 expires_tick = CEIL(expires_tick / 60),
                 resolved_tick = CASE WHEN resolved_tick IS NULL THEN NULL ELSE CEIL(resolved_tick / 60) END"
            );

            try {
                $db->query(
                    "UPDATE active_boosts SET
                     activated_tick = CEIL(activated_tick / 60),
                     expires_tick = CEIL(expires_tick / 60)"
                );
            } catch (Exception $e) {
                // Optional migration table may not exist yet.
            }

            try {
                $db->query(
                    "UPDATE sigil_drop_tracking SET
                     eligible_ticks_since_last_drop = CEIL(eligible_ticks_since_last_drop / 60),
                     last_drop_tick = CASE WHEN last_drop_tick IS NULL THEN NULL ELSE CEIL(last_drop_tick / 60) END"
                );
                $db->query(
                    "UPDATE sigil_drop_log SET
                     drop_tick = CEIL(drop_tick / 60)"
                );
            } catch (Exception $e) {
                // Optional migration tables may not exist yet.
            }

            $db->query(
                "UPDATE pending_actions SET
                 intake_tick = CEIL(intake_tick / 60),
                 resolution_tick = CEIL(resolution_tick / 60),
                 effect_tick = CEIL(effect_tick / 60)"
            );

            $db->query(
                "UPDATE economy_ledger SET
                 global_tick = CEIL(global_tick / 60),
                 season_tick = CASE WHEN season_tick IS NULL THEN NULL ELSE CEIL(season_tick / 60) END"
            );

            // Keep real-world boost durations after cadence conversion.
            try {
                $db->query(
                    "UPDATE boost_catalog SET duration_ticks = GREATEST(1, CEIL(duration_ticks / 60))
                     WHERE duration_ticks > 300"
                );
            } catch (Exception $e) {
                // Optional migration table may not exist yet.
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log('Legacy tick migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Rebalance existing seasons created with legacy defaults.
     */
    private static function rebalanceExistingSeasons($db) {
        $inflationTable = json_encode([
            ['x' => 0, 'factor_fp' => 1000000],
            ['x' => 50000, 'factor_fp' => 700000],
            ['x' => 200000, 'factor_fp' => 350000],
            ['x' => 800000, 'factor_fp' => 150000],
            ['x' => 3000000, 'factor_fp' => 70000]
        ]);

        $starpriceTable = json_encode([
            ['m' => 0, 'price' => 100],
            ['m' => 25000, 'price' => 250],
            ['m' => 100000, 'price' => 700],
            ['m' => 500000, 'price' => 2500],
            ['m' => 2000000, 'price' => 9000]
        ]);

        $db->query(
            "UPDATE seasons
             SET base_ubi_active_per_tick = 30,
                 target_spend_rate_per_tick = 15,
                 hoarding_window_ticks = ?,
                 inflation_table = ?,
                 starprice_table = ?,
                 current_star_price = GREATEST(current_star_price, 100)
             WHERE base_ubi_active_per_tick = 100
               AND target_spend_rate_per_tick = 50",
            [HOARDING_WINDOW_TICKS, $inflationTable, $starpriceTable]
        );
    }
}
