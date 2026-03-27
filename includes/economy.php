<?php
/**
 * Too Many Coins - Economy Engine
 * Handles UBI calculation, star pricing, trade valuation, and all economy math
 * Uses int64 arithmetic with floor-after-each-step as per canon
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class Economy {
    
    /**
     * Fixed-point multiply with floor: floor(base * mult_fp / 1_000_000)
     */
    public static function fpMultiply($base, $multFp) {
        // PHP handles big integers natively (GMP-like for 64-bit)
        return intdiv($base * $multFp, FP_SCALE);
    }
    
    /**
     * Clamp value between min and max
     */
    public static function clamp($value, $min, $max) {
        return max($min, min($max, $value));
    }
    
    /**
     * Piecewise linear interpolation with floor
     */
    public static function piecewiseLinear($x, $table, $xKey, $yKey) {
        if (empty($table)) return 0;
        
        // Below first point
        if ($x <= $table[0][$xKey]) return $table[0][$yKey];
        
        // Above last point
        $last = count($table) - 1;
        if ($x >= $table[$last][$xKey]) return $table[$last][$yKey];
        
        // Find segment
        for ($i = 0; $i < $last; $i++) {
            $x0 = $table[$i][$xKey];
            $x1 = $table[$i + 1][$xKey];
            if ($x >= $x0 && $x < $x1) {
                $y0 = $table[$i][$yKey];
                $y1 = $table[$i + 1][$yKey];
                // Linear interpolation with floor
                if ($x1 == $x0) return $y0;
                return intdiv($y0 * ($x1 - $x) + $y1 * ($x - $x0), $x1 - $x0);
            }
        }
        
        return $table[$last][$yKey];
    }
    
    /**
     * Calculate inflation dampening factor
     */
    public static function inflationFactor($season, $totalCoinsSupply) {
        $table = json_decode($season['inflation_table'], true);
        $factorFp = self::piecewiseLinear($totalCoinsSupply, $table, 'x', 'factor_fp');
        return self::clamp($factorFp, (int)$season['hoarding_min_factor_fp'], FP_SCALE);
    }
    
    /**
     * Calculate hoarding suppression factor for a player
     */
    public static function hoardingFactor($season, $playerSpendWindowAvg) {
        $target = (int)$season['target_spend_rate_per_tick'];
        if ($target <= 0) return FP_SCALE;
        
        $rawFp = intdiv($playerSpendWindowAvg * FP_SCALE, $target);
        return self::clamp($rawFp, (int)$season['hoarding_min_factor_fp'], FP_SCALE);
    }
    
    /**
     * Calculate UBI for a player on a given tick
     */
    public static function calculateUBI($season, $player, $participation, $isLockInTick = false) {
        // Lock-In suppression
        if ($isLockInTick) return 0;
        
        // Not participating
        if (!$player['participation_enabled']) return 0;
        
        $baseActive = (int)$season['base_ubi_active_per_tick'];
        $idleFactorFp = (int)$season['base_ubi_idle_factor_fp'];
        
        // Branch selection based on activity state
        if ($player['activity_state'] === 'Active') {
            $ubi = $baseActive;
        } else {
            $ubi = self::fpMultiply($baseActive, $idleFactorFp);
        }
        
        // Apply inflation dampening
        $totalSupply = (int)$season['total_coins_supply'];
        $inflationFp = self::inflationFactor($season, $totalSupply);
        $ubi = self::fpMultiply($ubi, $inflationFp);
        
        // Apply hoarding suppression
        $spendWindow = isset($participation['spend_window_total']) ? (int)$participation['spend_window_total'] : 0;
        $W = (int)$season['hoarding_window_ticks'];
        $awspd = $W > 0 ? intdiv($spendWindow, $W) : 0;
        $hoardFp = self::hoardingFactor($season, $awspd);
        $ubi = self::fpMultiply($ubi, $hoardFp);
        
        // Apply minimum floor
        $minUbi = (int)$season['ubi_min_per_tick'];
        $ubi = max($ubi, $minUbi);
        
        // Ensure non-negative
        return max(0, $ubi);
    }
    
    /**
     * Calculate star price based on total coin supply
     */
    public static function calculateStarPrice($season, $totalCoinsEndOfTick = null) {
        if ($totalCoinsEndOfTick === null) {
            $totalCoinsEndOfTick = (int)$season['total_coins_supply_end_of_tick'];
        }
        
        $table = json_decode($season['starprice_table'], true);
        $price = self::piecewiseLinear($totalCoinsEndOfTick, $table, 'm', 'price');
        $price = min($price, (int)$season['star_price_cap']);
        return max(1, $price);
    }
    
    /**
     * Calculate vault cost for a tier based on remaining supply
     */
    public static function calculateVaultCost($vaultConfig, $tier, $remaining) {
        $config = json_decode($vaultConfig, true);
        $tierConfig = null;
        foreach ($config as $tc) {
            if ($tc['tier'] == $tier) {
                $tierConfig = $tc;
                break;
            }
        }
        
        if (!$tierConfig || $remaining <= 0) return null;
        
        $costTable = $tierConfig['cost_table'];
        // Step-based pricing: pick the FIRST entry where remaining >= entry's remaining_inclusive_min
        foreach ($costTable as $entry) {
            if ($remaining >= $entry['remaining']) {
                return $entry['cost'];
            }
        }
        
        // Fallback to last entry
        return end($costTable)['cost'];
    }
    
    /**
     * Calculate trade value in coins
     */
    public static function calculateTradeValue($season, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils) {
        $starPrice = (int)$season['current_star_price'];
        $vaultConfig = json_decode($season['vault_config'], true);
        $db = Database::getInstance();
        
        // Get vault costs for sigil valuation
        $vaultCosts = [];
        $vaultRows = $db->fetchAll(
            "SELECT tier, current_cost_stars, last_published_cost_stars FROM season_vault WHERE season_id = ?",
            [$season['season_id']]
        );
        foreach ($vaultRows as $row) {
            $cost = $row['current_cost_stars'] > 0 ? $row['current_cost_stars'] : $row['last_published_cost_stars'];
            $vaultCosts[$row['tier']] = $cost ?: 0;
        }
        
        // Calculate side A value
        $valueA = $sideACoins;
        for ($t = 1; $t <= 5; $t++) {
            if (isset($sideASigils[$t - 1]) && $sideASigils[$t - 1] > 0) {
                $sigilCostStars = $vaultCosts[$t] ?? 0;
                $valueA += $sideASigils[$t - 1] * $sigilCostStars * $starPrice;
            }
        }
        
        // Calculate side B value
        $valueB = $sideBCoins;
        for ($t = 1; $t <= 5; $t++) {
            if (isset($sideBSigils[$t - 1]) && $sideBSigils[$t - 1] > 0) {
                $sigilCostStars = $vaultCosts[$t] ?? 0;
                $valueB += $sideBSigils[$t - 1] * $sigilCostStars * $starPrice;
            }
        }
        
        return $valueA + $valueB;
    }
    
    /**
     * Calculate trade fee
     */
    public static function calculateTradeFee($season, $declaredValue) {
        $tiers = json_decode($season['trade_fee_tiers'], true);
        $minFee = (int)$season['trade_min_fee_coins'];
        
        // Find applicable tier
        $rateFp = $tiers[0]['rate_fp'];
        foreach ($tiers as $tier) {
            if ($declaredValue >= $tier['threshold']) {
                $rateFp = $tier['rate_fp'];
            }
        }
        
        $feeRaw = self::fpMultiply($declaredValue, $rateFp);
        return max($minFee, $feeRaw);
    }
    
    /**
     * Process Sigil drop for a player
     * Returns tier number (1-5) or 0 for no drop
     */
    public static function processSigilDrop($season, $playerId, $seasonTick) {
        // Deterministic RNG using SHA-256.
        // Use 'J' (unsigned 64-bit big-endian) instead of 'P' (machine byte-order) so
        // the hash input is identical on every platform/PHP build.
        $seed = $season['season_seed'];
        $input = pack('J', $season['season_id']) . pack('J', $seasonTick) . $seed . pack('J', $playerId);
        $hash = hash('sha256', $input, true);
        
        // Use 'N' (unsigned 32-bit big-endian) for both extractions: portable across all
        // PHP platforms unlike 'P' (machine byte-order 64-bit). A 32-bit range
        // (0–4,294,967,295) is far larger than SIGIL_DROP_RATE (833) and 1,000,000,
        // so the modulo distribution is effectively uniform.

        // Bernoulli trial: bytes 0-3 mod SIGIL_DROP_RATE
        $trial = unpack('N', substr($hash, 0, 4))[1] % SIGIL_DROP_RATE;
        
        if ($trial !== 0) return 0; // No drop
        
        // Tier selection: bytes 4-7 mod 1,000,000 (matches SIGIL_TIER_ODDS fixed-point scale)
        $tierRoll = unpack('N', substr($hash, 4, 4))[1] % 1000000;
        
        $cumulative = 0;
        foreach (SIGIL_TIER_ODDS as $tier => $odds) {
            $cumulative += $odds;
            if ($tierRoll < $cumulative) return $tier;
        }
        
        return 1; // Fallback
    }
}
