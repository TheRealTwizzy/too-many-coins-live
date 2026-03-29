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
     * Estimate sigil power from tiered sigil inventory.
     */
    public static function calculateSigilPower($participation) {
        if (!$participation || !is_array($participation)) return 0;

        $weights = [1 => 1, 2 => 2, 3 => 3, 4 => 5, 5 => 8, 6 => 13];
        $power = 0;
        foreach ($weights as $tier => $weight) {
            $col = 'sigils_t' . $tier;
            $count = (int)($participation[$col] ?? 0);
            if ($count > 0) {
                $power += $count * $weight;
            }
        }

        return max(0, (int)$power);
    }

    /**
     * Blend baseline and max-power tier odds using a linear ramp.
     */
    public static function adjustedSigilTierOdds($sigilPower) {
        $base = SIGIL_TIER_ODDS;
        $target = SIGIL_TIER_ODDS_MAX_POWER;
        $fullShift = max(1, (int)SIGIL_POWER_FULL_SHIFT);
        $power = max(0, (int)$sigilPower);
        $ratioFp = min(FP_SCALE, intdiv($power * FP_SCALE, $fullShift));

        $tiers = [];
        $sum = 0;
        foreach ($base as $tier => $odds) {
            $from = (int)$odds;
            $to = (int)($target[$tier] ?? $from);
            $blended = intdiv(($from * (FP_SCALE - $ratioFp)) + ($to * $ratioFp), FP_SCALE);
            $tiers[(int)$tier] = max(0, (int)$blended);
            $sum += $tiers[(int)$tier];
        }

        if ($sum <= 0) {
            return $base;
        }

        // Normalize to exactly 1,000,000 without reordering odds.
        $delta = 1000000 - $sum;
        $tiers[1] = max(0, (int)$tiers[1] + $delta);
        return $tiers;
    }

    /**
     * Scale base sigil drop Bernoulli denominator by sigil power.
     * Higher denominator means lower base drop chance.
     */
    public static function sigilDropRateForPower($sigilPower) {
        $baseRate = max(1, (int)SIGIL_DROP_RATE);
        $maxRate = max($baseRate, (int)(defined('SIGIL_DROP_RATE_MAX_POWER') ? SIGIL_DROP_RATE_MAX_POWER : $baseRate));
        $fullShift = max(1, (int)SIGIL_POWER_FULL_SHIFT);
        $power = max(0, (int)$sigilPower);
        $ratioFp = min(FP_SCALE, intdiv($power * FP_SCALE, $fullShift));

        return max(1, intdiv(($baseRate * (FP_SCALE - $ratioFp)) + ($maxRate * $ratioFp), FP_SCALE));
    }
    
    /**
     * Fixed-point multiply with floor: floor(base * mult_fp / 1_000_000)
     */
    public static function fpMultiply($base, $multFp) {
        // PHP handles big integers natively (GMP-like for 64-bit)
        return intdiv($base * $multFp, FP_SCALE);
    }

    /**
     * Convert whole-coin value into fixed-point units.
     */
    public static function toFixedPoint($coins) {
        return max(0, (int)$coins) * FP_SCALE;
    }

    /**
     * Apply a boost modifier to a fixed-point amount.
     */
    public static function applyBoostModifierFp($amountFp, $boostModFp) {
        $baseFp = max(0, (int)$amountFp);
        $modFp = max(0, (int)$boostModFp);
        if ($modFp <= 0) return $baseFp;

        return intdiv($baseFp * (FP_SCALE + $modFp), FP_SCALE);
    }

    /**
     * Guaranteed whole-coin floor from effective boost modifier.
     * Example default policy: +1 coin/tick per 10% boost (100,000 fp).
     */
    public static function guaranteedBoostFloorCoins($boostModFp, $capCoins = null) {
        $modFp = max(0, (int)$boostModFp);
        $stepPercent = max(1, (int)BOOST_GUARANTEED_FLOOR_STEP_PERCENT);
        $stepCoins = max(1, (int)BOOST_GUARANTEED_FLOOR_STEP_COINS);
        $fpPerStep = intdiv($stepPercent * FP_SCALE, 100);
        if ($fpPerStep <= 0) return 0;

        $coins = intdiv($modFp, $fpPerStep) * $stepCoins;
        $effectiveCap = ($capCoins === null) ? (int)BOOST_GUARANTEED_FLOOR_CAP_COINS : (int)$capCoins;
        if ($effectiveCap > 0) {
            $coins = min($coins, $effectiveCap);
        }

        return max(0, $coins);
    }

    /**
     * Fixed-point wrapper for guaranteedBoostFloorCoins().
     */
    public static function guaranteedBoostFloorFp($boostModFp, $capCoins = null) {
        return self::toFixedPoint(self::guaranteedBoostFloorCoins($boostModFp, $capCoins));
    }

    /**
     * Split fixed-point amount into whole coins and residual fractional fp.
     */
    public static function splitFixedPoint($amountFp) {
        $value = max(0, (int)$amountFp);
        return [
            intdiv($value, FP_SCALE),
            $value % FP_SCALE,
        ];
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
     * Compute Early Lock-In payout.
     *
     * Conversion order (per canon):
     *  1. T1–T5 sigils are refunded at their per-tier star values and added to
     *     the player's seasonal star total.
     *  2. The combined seasonal star total is then converted to global stars at
     *     65% (floor).
     *
     * T6 sigils are NOT refunded and must be handled separately by the caller
     * (they are destroyed with no compensation).
     *
     * @param int   $seasonalStars  Player's current seasonal star balance.
     * @param int[] $sigilCounts    Indexed array [0..4] = counts for T1–T5.
     * @param int[] $tierCosts      Indexed array [0..4] = star refund value per sigil for T1–T5.
     * @return array {
     *     sigil_refund_stars: int,
     *     total_seasonal_stars: int,
     *     global_stars_gained: int
     * }
     */
    public static function computeEarlyLockInPayout(int $seasonalStars, array $sigilCounts, array $tierCosts): array {
        $sigilRefundStars = 0;
        for ($i = 0; $i < 5; $i++) {
            $count = (int)($sigilCounts[$i] ?? 0);
            $cost  = (int)($tierCosts[$i] ?? 0);
            $sigilRefundStars += $count * $cost;
        }
        $totalSeasonalStars = $seasonalStars + $sigilRefundStars;
        $globalStarsGained  = (int)floor($totalSeasonalStars * 0.65);
        return [
            'sigil_refund_stars'   => $sigilRefundStars,
            'total_seasonal_stars' => $totalSeasonalStars,
            'global_stars_gained'  => $globalStarsGained,
        ];
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
        for ($t = 1; $t <= SIGIL_MAX_TIER; $t++) {
            if (isset($sideASigils[$t - 1]) && $sideASigils[$t - 1] > 0) {
                $sigilCostStars = $vaultCosts[$t] ?? 0;
                $valueA += $sideASigils[$t - 1] * $sigilCostStars * $starPrice;
            }
        }
        
        // Calculate side B value
        $valueB = $sideBCoins;
        for ($t = 1; $t <= SIGIL_MAX_TIER; $t++) {
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
     * Compute per-player sigil drop configuration.
     *
     * Returns an array with:
     *   'drop_rate' => int   – Bernoulli denominator (1-in-N chance per eligible tick)
     *   'tier_odds' => int[] – Conditional per-tier odds (parts per 1,000,000, sum = 1,000,000)
     *
     * How per-player dynamic rates are computed:
     *  1. Power-adjusted baseline odds are obtained from adjustedSigilTierOdds(), which
     *     already blends SIGIL_TIER_ODDS toward SIGIL_TIER_ODDS_MAX_POWER based on the
     *     player's total sigil power (higher power → lower base drop frequency).
     *  2. Inventory-based dampening: for each tier T, the player's current sigils_tT count
     *     is divided by SIGIL_INVENTORY_ADJ_THRESHOLD. Each whole step reduces that tier's
     *     conditional odds by SIGIL_INVENTORY_ADJ_STEP_FP, capped at
     *     SIGIL_INVENTORY_ADJ_MAX_STEPS steps. This prevents over-dropping a tier the
     *     player already has many of.
     *  3. Per-tier clamping: each tier's odds are clamped within the configured
     *     [SIGIL_TIER_ODDS_MIN[tier], SIGIL_TIER_ODDS_MAX[tier]] bounds.
     *  4. Monotonic ordering enforcement: for every adjacent pair, odds[T] is capped at
     *     odds[T-1] so lower-tier sigils can never be rarer than higher-tier sigils.
     *  5. Renormalization: values are scaled proportionally so their sum equals exactly
     *     1,000,000; a rounding remainder is applied to T1.
     *  6. Boost-based drop frequency: the Bernoulli denominator (from sigilDropRateForPower)
     *     is reduced by 1 for every SIGIL_BOOST_DROP_RATE_STEP_FP of active boost modifier,
     *     capped at SIGIL_BOOST_DROP_RATE_MAX_BONUS, increasing overall drop frequency for
     *     players with active boosts.
     *
     * Balancing assumptions:
     *  - Drops remain the primary sigil acquisition source; the vault provides a secondary
     *    path at significant star cost, and combining provides a tertiary upgrade path.
     *  - The inventory dampening is mild enough (≤10% reduction per tier) that drops always
     *    dominate over vault purchases under normal play.
     *  - Cross-tier scaling ensures T1 >= T2 >= T3 >= T4 >= T5 at every evaluation.
     *
     * @param array $player     Season-participation row (must include sigils_t1…sigils_t5 fields).
     * @param int   $boostModFp Combined boost modifier in fixed-point (FP_SCALE = 1,000,000 → 100%).
     * @return array{drop_rate: int, tier_odds: array<int,int>}
     */
    public static function computePerPlayerSigilDropConfig($player, $boostModFp = 0) {
        $sigilPower = self::calculateSigilPower($player);

        // Step 1: Power-adjusted baseline tier odds (sum = 1,000,000)
        $tierOdds = self::adjustedSigilTierOdds($sigilPower);

        $minOdds = SIGIL_TIER_ODDS_MIN;
        $maxOdds = SIGIL_TIER_ODDS_MAX;

        // Step 2: Per-tier inventory-based dampening
        $adjThreshold = max(1, (int)SIGIL_INVENTORY_ADJ_THRESHOLD);
        $adjStepFp    = max(0, (int)SIGIL_INVENTORY_ADJ_STEP_FP);
        $adjMaxSteps  = max(0, (int)SIGIL_INVENTORY_ADJ_MAX_STEPS);

        foreach ($tierOdds as $tier => $odds) {
            $col    = 'sigils_t' . $tier;
            $count  = (int)($player[$col] ?? 0);
            $steps  = min($adjMaxSteps, intdiv($count, $adjThreshold));
            $tierOdds[$tier] = $odds - ($steps * $adjStepFp);
        }

        // Step 3: Clamp each tier within configured [min, max] bounds
        foreach ($tierOdds as $tier => $odds) {
            $lo = (int)($minOdds[$tier] ?? 0);
            $hi = (int)($maxOdds[$tier] ?? 1000000);
            $tierOdds[$tier] = self::clamp($odds, $lo, $hi);
        }

        // Step 4: Enforce monotonic ordering – T1 >= T2 >= T3 >= T4 >= T5
        // Lower-tier sigils (T1) must never become rarer than higher-tier sigils (T5).
        $tiers = array_keys($tierOdds);
        sort($tiers); // [1, 2, 3, 4, 5]
        for ($i = 1; $i < count($tiers); $i++) {
            $prev = $tiers[$i - 1];
            $curr = $tiers[$i];
            if ($tierOdds[$curr] > $tierOdds[$prev]) {
                $tierOdds[$curr] = $tierOdds[$prev];
            }
        }

        // Step 5: Renormalize to exactly 1,000,000
        $sum = array_sum($tierOdds);
        if ($sum <= 0) {
            // Safety fallback: return unmodified base odds
            $tierOdds = SIGIL_TIER_ODDS;
        } else {
            // Proportional scaling to preserve relative ratios, then fix rounding on T1
            $scaled = [];
            foreach ($tierOdds as $tier => $odds) {
                $scaled[$tier] = intdiv($odds * 1000000, $sum);
            }
            $scaledSum = array_sum($scaled);
            $scaled[$tiers[0]] = max(0, $scaled[$tiers[0]] + (1000000 - $scaledSum));
            $tierOdds = $scaled;

            // Re-enforce monotonic ordering after the rounding delta on T1
            for ($i = 1; $i < count($tiers); $i++) {
                $prev = $tiers[$i - 1];
                $curr = $tiers[$i];
                if ($tierOdds[$curr] > $tierOdds[$prev]) {
                    $tierOdds[$curr] = $tierOdds[$prev];
                }
            }
        }

        // Step 6: Boost-based drop frequency adjustment (lower denominator = more drops)
        $dropRate = self::sigilDropRateForPower($sigilPower);
        $boostMod = max(0, (int)$boostModFp);
        $stepFp   = max(1, (int)SIGIL_BOOST_DROP_RATE_STEP_FP);
        $maxBonus = max(0, (int)SIGIL_BOOST_DROP_RATE_MAX_BONUS);
        $bonus    = min($maxBonus, intdiv($boostMod, $stepFp));
        $dropRate = max(1, $dropRate - $bonus);

        return [
            'drop_rate' => $dropRate,
            'tier_odds' => $tierOdds,
        ];
    }

    /**
     * Process Sigil drop for a player.
     * Returns tier number (1-5) or 0 for no drop.
     * Tier odds are shifted by sigil power, but Tier 6 is never randomly dropped.
     *
     * @param array      $season     Season row (must include season_id and season_seed).
     * @param int        $playerId   Player identifier (used as RNG input).
     * @param int        $seasonTick Absolute game-tick (used as RNG input).
     * @param int        $sigilPower Legacy sigil-power scalar; ignored when $dropConfig provided.
     * @param array|null $dropConfig Pre-computed per-player config from computePerPlayerSigilDropConfig().
     *                               When supplied, $sigilPower is not used.
     */
    public static function processSigilDrop($season, $playerId, $seasonTick, $sigilPower = 0, array $dropConfig = null) {
        // Deterministic RNG using SHA-256.
        // Use 'J' (unsigned 64-bit big-endian) instead of 'P' (machine byte-order) so
        // the hash input is identical on every platform/PHP build.
        $seed = $season['season_seed'];
        $input = pack('J', $season['season_id']) . pack('J', $seasonTick) . $seed . pack('J', $playerId);
        $hash = hash('sha256', $input, true);

        // Use 'N' (unsigned 32-bit big-endian) for both extractions: portable across all
        // PHP platforms unlike 'P' (machine byte-order 64-bit). A 32-bit range
        // (0–4,294,967,295) is far larger than SIGIL_DROP_RATE and 1,000,000,
        // so the modulo distribution is effectively uniform.

        // Resolve drop rate and tier odds from per-player config or legacy sigil power.
        if ($dropConfig !== null) {
            $dropRate = (int)$dropConfig['drop_rate'];
            $tierOdds = $dropConfig['tier_odds'];
        } else {
            $dropRate = self::sigilDropRateForPower($sigilPower);
            $tierOdds = self::adjustedSigilTierOdds($sigilPower);
        }

        // Bernoulli trial: bytes 0-3 mod drop-rate denominator (power/boost-scaled)
        $trial = unpack('N', substr($hash, 0, 4))[1] % max(1, $dropRate);

        if ($trial !== 0) return 0; // No drop

        // Tier selection: bytes 4-7 mod 1,000,000 (matches SIGIL_TIER_ODDS fixed-point scale)
        $tierRoll = unpack('N', substr($hash, 4, 4))[1] % 1000000;

        $cumulative = 0;
        foreach ($tierOdds as $tier => $odds) {
            $cumulative += $odds;
            if ($tierRoll < $cumulative) return $tier;
        }

        return 1; // Fallback
    }
}
