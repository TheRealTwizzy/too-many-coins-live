<?php
/**
 * Too Many Coins - Game Time & Season Management
 * 
 * Approach: Game time is a virtual clock that starts at 0 when the server first boots.
 * We use accelerated time: 1 real second = TIME_SCALE game seconds.
 * Season 1 starts at game time = 60 (i.e., 1 real second after server boot).
 * This ensures the first season is immediately active for players.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class GameTime {
    
    private static $serverEpoch = null;
    
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
     * Game time = real_elapsed_seconds * TIME_SCALE
     * This means at server boot, game time = 0, and it advances at TIME_SCALE speed.
     */
    public static function now() {
        $realElapsed = time() - self::getServerEpoch();
        return max(0, $realElapsed * TIME_SCALE);
    }
    
    /**
     * Get the global tick index (= game time)
     */
    public static function globalTick() {
        return self::now();
    }
    
    /**
     * Calculate season start time from season sequence number.
     * Season 1 starts at game time = 60 (1 real second after boot).
     * Subsequent seasons start every SEASON_CADENCE game-seconds apart.
     */
    public static function seasonStartTime($seasonSeq) {
        return 60 + (($seasonSeq - 1) * SEASON_CADENCE);
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
        $gameTime = self::now();
        
        // Find which season sequence we're in
        $elapsed = max(0, $gameTime - 60);
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
                    ['x' => 100000, 'factor_fp' => 800000],
                    ['x' => 500000, 'factor_fp' => 500000],
                    ['x' => 2000000, 'factor_fp' => 200000],
                    ['x' => 10000000, 'factor_fp' => 100000]
                ]);
                
                $starpriceTable = json_encode([
                    ['m' => 0, 'price' => 10],
                    ['m' => 50000, 'price' => 50],
                    ['m' => 200000, 'price' => 200],
                    ['m' => 1000000, 'price' => 1000],
                    ['m' => 5000000, 'price' => 5000]
                ]);
                
                $tradeFeeTiers = json_encode([
                    ['threshold' => 0, 'rate_fp' => 50000],
                    ['threshold' => 10000, 'rate_fp' => 30000],
                    ['threshold' => 100000, 'rate_fp' => 20000]
                ]);
                
                $vaultConfig = json_encode([
                    ['tier' => 1, 'supply' => 2000, 'cost_table' => [['remaining' => 1600, 'cost' => 2], ['remaining' => 1200, 'cost' => 3], ['remaining' => 800, 'cost' => 4], ['remaining' => 400, 'cost' => 6], ['remaining' => 200, 'cost' => 9], ['remaining' => 1, 'cost' => 13]]],
                    ['tier' => 2, 'supply' => 800, 'cost_table' => [['remaining' => 640, 'cost' => 6], ['remaining' => 480, 'cost' => 8], ['remaining' => 320, 'cost' => 11], ['remaining' => 160, 'cost' => 15], ['remaining' => 80, 'cost' => 20], ['remaining' => 1, 'cost' => 28]]],
                    ['tier' => 3, 'supply' => 250, 'cost_table' => [['remaining' => 200, 'cost' => 18], ['remaining' => 150, 'cost' => 24], ['remaining' => 100, 'cost' => 32], ['remaining' => 50, 'cost' => 44], ['remaining' => 25, 'cost' => 60], ['remaining' => 1, 'cost' => 85]]],
                    ['tier' => 4, 'supply' => 60, 'cost_table' => [['remaining' => 48, 'cost' => 60], ['remaining' => 36, 'cost' => 80], ['remaining' => 24, 'cost' => 110], ['remaining' => 12, 'cost' => 150], ['remaining' => 6, 'cost' => 210], ['remaining' => 1, 'cost' => 300]]],
                    ['tier' => 5, 'supply' => 15, 'cost_table' => [['remaining' => 12, 'cost' => 220], ['remaining' => 9, 'cost' => 300], ['remaining' => 6, 'cost' => 420], ['remaining' => 3, 'cost' => 600], ['remaining' => 2, 'cost' => 850], ['remaining' => 1, 'cost' => 1200]]]
                ]);
                
                $seasonId = $db->insert(
                    "INSERT INTO seasons (start_time, end_time, blackout_time, season_seed, 
                     status, base_ubi_active_per_tick, base_ubi_idle_factor_fp, ubi_min_per_tick,
                     inflation_table, hoarding_window_ticks, target_spend_rate_per_tick, hoarding_min_factor_fp,
                     starprice_table, star_price_cap, trade_fee_tiers, trade_min_fee_coins,
                     vault_config, current_star_price, last_processed_tick)
                     VALUES (?, ?, ?, ?, 'Scheduled', 100, 250000, 1, ?, 86400, 50, 100000, ?, 10000, ?, 10, ?, 10, ?)",
                    [$startTime, $endTime, $blackoutTime, $seed,
                     $inflationTable, $starpriceTable, $tradeFeeTiers, $vaultConfig, $startTime]
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
        $realSeconds = max(0, intdiv($gameTicks, TIME_SCALE));
        
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
}
