<?php
/**
 * Too Many Coins - Tick Engine
 * Processes the authoritative game tick: system events, sigil drops, actions, UBI, boosts
 * Follows Chapter 03 tick order:
 *   1. Tick classification + snapshot
 *   2. System-event phase (boost expiration)
 *   3. Sigil drop evaluation phase
 *   4. Player action resolution phase
 *   5. System math + accrual phase (UBI with boost modifiers)
 *   6. Market/price-surface publish phase
 *   7. Activity evaluation phase
 *   8. Leaderboard recalculation phase
 *   9. Persistence + audit
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/game_time.php';
require_once __DIR__ . '/economy.php';
require_once __DIR__ . '/boost_catalog.php';
require_once __DIR__ . '/notifications.php';

class TickEngine {
    
    /**
     * Process all pending ticks for all active seasons
     */
    public static function processTicks() {
        $db = Database::getInstance();
        GameTime::ensureSeasons();
        $gameTime = GameTime::now();
        
        // Always compute status from game time; DB status is synchronized metadata.
        $seasons = $db->fetchAll("SELECT * FROM seasons");

        foreach ($seasons as $season) {
            $computedStatus = GameTime::getSeasonStatus($season, $gameTime);

            if (
                in_array($computedStatus, ['Active', 'Blackout'], true)
                || ($computedStatus === 'Expired' && !(int)$season['expiration_finalized'])
            ) {
                self::processSeasonTick($season, $gameTime);
            }

            if (($season['status'] ?? null) !== $computedStatus) {
                $db->query(
                    "UPDATE seasons SET status = ? WHERE season_id = ?",
                    [$computedStatus, $season['season_id']]
                );
            }
        }
        
        // Update server state
        $db->query(
            "UPDATE server_state SET global_tick_index = ?, last_tick_processed_at = NOW() WHERE id = 1",
            [$gameTime]
        );
    }
    
    /**
     * Process a single tick batch for a season
     */
    private static function processSeasonTick($season, $gameTime) {
        $db = Database::getInstance();
        $seasonId = $season['season_id'];
        $startTime = (int)$season['start_time'];
        $endTime = (int)$season['end_time'];
        $blackoutTime = (int)$season['blackout_time'];
        $lastProcessed = (int)$season['last_processed_tick'];
        
        $currentSeasonTick = GameTime::seasonTick($startTime, $gameTime);
        $seasonDurationTicks = $endTime - $startTime;
        
        // Don't process before season starts
        if ($currentSeasonTick < 0) return;
        
        // Cap to season duration
        $currentSeasonTick = min($currentSeasonTick, $seasonDurationTicks);
        
        $lastSeasonTick = max(0, $lastProcessed - $startTime);
        
        // Skip if already processed
        if ($currentSeasonTick <= $lastSeasonTick) return;
        
        // Process in batches
        $ticksToProcess = $currentSeasonTick - $lastSeasonTick;
        
        // Check if this is the expiration tick
        $isExpiration = ($gameTime >= $endTime && !$season['expiration_finalized']);
        
        if ($isExpiration) {
            self::processExpiration($season);
            return;
        }
        
        // Get all participating players for this season
        $participants = $db->fetchAll(
            "SELECT p.*, sp.* FROM players p 
             JOIN season_participation sp ON p.player_id = sp.player_id 
             WHERE p.joined_season_id = ? AND p.participation_enabled = 1 AND sp.season_id = ?",
            [$seasonId, $seasonId]
        );
        
        if (empty($participants)) {
            $db->query(
                "UPDATE seasons SET last_processed_tick = ? WHERE season_id = ?",
                [$gameTime, $seasonId]
            );
            return;
        }
        
        $isBlackout = ($gameTime >= $blackoutTime);
        $isLastValid = ($currentSeasonTick >= $seasonDurationTicks - 1);
        
        $db->beginTransaction();
        try {
            // Phase 2: System-event phase - expire old boosts
            self::expireBoosts($seasonId, $gameTime);
            self::expireFreezes($seasonId, $gameTime);
            
            // Get active global boosts for UBI modifier calculation
            $globalBoosts = self::getActiveGlobalBoosts($seasonId, $gameTime);
            
            $totalNewCoins    = 0;
            $totalBurnedCoins = 0;
            $coinsActiveTotal = 0;
            $coinsIdleTotal   = 0;
            
            foreach ($participants as $p) {
                $playerId = $p['player_id'];
                
                // Compute boost modifier once per player; used for both sigil drops and UBI.
                $selfBoosts = self::getActivePlayerBoosts($playerId, $seasonId, $gameTime);
                $boostModFp = self::calculateBoostModifier($selfBoosts, $globalBoosts);
                $isFrozen = self::isPlayerFrozen($playerId, $seasonId, $gameTime);
                
                // Phase 3: Sigil drop evaluation (not on last-valid or expiration)
                if (!$isLastValid && !$isExpiration) {
                    self::processSigilDrops($season, $p, $seasonId, $gameTime, $currentSeasonTick, $ticksToProcess, $startTime, $lastSeasonTick, $boostModFp);
                }
                
                // Phase 5: UBI accrual (net) + explicit hoarding sink accounting.
                //
                // Bug fix: the old code accumulated fractional carry from the GROSS rate, then
                // subtracted whole-coin sink per tick.  When gross rate has a fractional part and
                // the sink fires every tick in a batch, the player could lose coins even when
                // calculateRateBreakdown reports net_rate_fp = 0 — because carry only ticks over
                // to a whole coin every N ticks but the sink deducts each tick unconditionally.
                // Correct semantics: accumulate carry against the NET rate (max(0, gross - sink_fp))
                // so that fractional precision is preserved at the effective rate and no phantom
                // drain occurs when net is zero.  The sink amount is still tracked separately for
                // hoarding_sink_total and season-supply accounting.
                $rates = Economy::calculateRateBreakdown($season, $p, $p, $boostModFp, $isFrozen);
                $netRateFp   = (int)$rates['net_rate_fp'];
                $sinkPerTick = (int)$rates['sink_per_tick'];

                // Carry accumulates against effective net rate; when net is 0, carry does not increase (existing fractional carry, if any, is preserved).
                $carryFp = max(0, (int)($p['coins_fractional_fp'] ?? 0));
                $totalNetFp = ($netRateFp * $ticksToProcess) + $carryFp;
                [$netCoins, $newCarryFp] = Economy::splitFixedPoint($totalNetFp);

                // Sink here is an accounting-only metric for supply/hoarding reporting; it is not
                // directly debited from the player's balance in this block. Cap it to the amount
                // the player could have actually lost this tick so reported sink never exceeds
                // the maximum burnable balance.
                $totalSink = max(0, $sinkPerTick * $ticksToProcess);
                $maxBurnable = max(0, ((int)($p['coins'] ?? 0)) + $netCoins);
                $totalSink = min($totalSink, $maxBurnable);

                $db->query(
                    "UPDATE season_participation SET coins = GREATEST(0, coins + ?), coins_fractional_fp = ?, hoarding_sink_total = hoarding_sink_total + ? WHERE player_id = ? AND season_id = ?",
                    [$netCoins, $newCarryFp, $totalSink, $playerId, $seasonId]
                );
                $totalNewCoins    += $netCoins;
                $totalBurnedCoins += $totalSink;

                // Accumulate active/idle coin totals (post-UBI balance) for pricing telemetry.
                $postTickCoins = max(0, ((int)($p['coins'] ?? 0)) + $netCoins);
                if (($p['activity_state'] ?? 'Idle') === 'Active') {
                    $coinsActiveTotal += $postTickCoins;
                } else {
                    $coinsIdleTotal += $postTickCoins;
                }
                
                // Update participation tracking
                $activeTicks = ($p['activity_state'] === 'Active') ? $ticksToProcess : 0;
                $db->query(
                    "UPDATE season_participation SET 
                     participation_time_total = participation_time_total + ?,
                     participation_ticks_since_join = participation_ticks_since_join + ?,
                     active_ticks_total = active_ticks_total + ?
                     WHERE player_id = ? AND season_id = ?",
                    [$ticksToProcess, $ticksToProcess, $activeTicks, $playerId, $seasonId]
                );
                
                // Phase 7: Activity evaluation (idle check)
                if ($p['activity_state'] === 'Active') {
                    $lastActivityTick = $p['last_activity_tick'] ?? ($startTime + $lastSeasonTick);
                    $ticksSinceActivity = $gameTime - $lastActivityTick;
                    
                    if ($ticksSinceActivity >= IDLE_TIMEOUT_TICKS) {
                        $db->query(
                            "UPDATE players SET activity_state = 'Idle', idle_modal_active = 1, idle_since_tick = ?
                             WHERE player_id = ?",
                            [$gameTime, $playerId]
                        );
                        Notifications::create(
                            $playerId,
                            'idle',
                            'You are now idle',
                            'Participation is paused until you acknowledge the idle prompt.',
                            [
                                'is_read' => true,
                                'event_key' => 'idle:' . $gameTime,
                                'payload' => ['at_tick' => (int)$gameTime]
                            ]
                        );
                    }
                }
            }
            
            // Phase 6: Update season supply and star price.
            //
            // Supply accounting fix: the previous implementation passed params
            // ($totalNewCoins + $totalBurnedCoins) and $totalBurnedCoins to a single UPDATE
            // SET that updated both total_coins_supply and total_coins_supply_end_of_tick.
            // Due to MySQL's left-to-right SET evaluation, total_coins_supply_end_of_tick
            // referenced the already-updated total_coins_supply, causing the net delta to
            // be applied twice and end_of_tick supply to drift above the true supply each tick.
            //
            // Fix: compute final supply value in PHP so both fields receive the same value.
            // Net delta = $totalNewCoins (gross minted minus the sink already absorbed into
            // the net rate; $totalBurnedCoins is the accounting-only sink metric).
            $grossMinted   = $totalNewCoins + $totalBurnedCoins; // used for traceability logging only
            $netCoinsDelta = $totalNewCoins; // = grossMinted - totalBurnedCoins
            $currentSupply = (int)$season['total_coins_supply'];
            $newSupply     = max(0, $currentSupply + $netCoinsDelta);

            error_log(sprintf(
                '[TMC][tick=%d][season=%d] supply: minted=%d burned=%d net_delta=%d new_supply=%d',
                $gameTime, $seasonId, $grossMinted, $totalBurnedCoins, $netCoinsDelta, $newSupply
            ));

            // Compute effective_price_supply for active-weighted pricing.
            $activeOnly  = (int)($season['starprice_active_only']   ?? 0);
            $idleWeightFp = (int)($season['starprice_idle_weight_fp'] ?? 250000);
            if ($activeOnly) {
                $effectivePriceSupply = max(0, $coinsActiveTotal);
            } else {
                $effectivePriceSupply = max(0, $coinsActiveTotal + Economy::fpMultiply($coinsIdleTotal, $idleWeightFp));
            }

            $db->query(
                "UPDATE seasons SET
                 total_coins_supply = ?,
                 total_coins_supply_end_of_tick = ?,
                 coins_active_total = ?,
                 coins_idle_total = ?,
                 effective_price_supply = ?,
                 last_processed_tick = ?
                 WHERE season_id = ?",
                [$newSupply, $newSupply, $coinsActiveTotal, $coinsIdleTotal, $effectivePriceSupply, $gameTime, $seasonId]
            );
            
            // Recalculate star price using updated season data (includes effective_price_supply
            // and current_star_price as the previous-price reference for velocity clamping).
            $updatedSeason = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
            $newPrice = Economy::calculateStarPrice($updatedSeason);
            $db->query(
                "UPDATE seasons SET current_star_price = ? WHERE season_id = ?",
                [$newPrice, $seasonId]
            );
            
            // Update vault costs
            self::updateVaultCosts($seasonId);
            
            // Expire old trades
            $db->query(
                "UPDATE trades SET status = 'EXPIRED' 
                 WHERE season_id = ? AND status = 'OPEN' AND expires_tick <= ?",
                [$seasonId, $gameTime]
            );
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log("Tick processing error for season {$seasonId}: " . $e->getMessage());
        }
    }
    
    /**
     * Process Sigil drops for a player over a batch of ticks.
     * Uses configured Bernoulli, pity, and rolling-window throttle limits.
     * $boostModFp is the pre-computed boost modifier for this player this tick (FP_SCALE = 1,000,000).
     */
    private static function processSigilDrops($season, $player, $seasonId, $gameTime, $currentSeasonTick, $ticksToProcess, $startTime, $lastSeasonTick, $boostModFp = 0) {
        $db = Database::getInstance();
        $playerId = $player['player_id'];
        
        // Only active, online, participating players get drops
        if (!$player['online_current'] || $player['activity_state'] !== 'Active') {
            // Reset pity counter when not eligible
            $db->query(
                "UPDATE season_participation SET eligible_ticks_since_last_drop = 0 
                 WHERE player_id = ? AND season_id = ?",
                [$playerId, $seasonId]
            );
            return;
        }
        
        $pityCounter = (int)($player['eligible_ticks_since_last_drop'] ?? 0);
        $pendingRngDrops = max(0, (int)($player['pending_rng_sigil_drops'] ?? 0));
        $pendingPityDrops = max(0, (int)($player['pending_pity_sigil_drops'] ?? 0));
        $nextDeliveryTick = max(0, (int)($player['sigil_next_delivery_tick'] ?? 0));
        
        // Check throttle: count drops in the rolling window
        $windowStart = max(0, $currentSeasonTick - SIGIL_DROP_WINDOW_TICKS);
        $windowStartGameTime = $startTime + $windowStart;
        $dropsInWindow = $db->fetch(
            "SELECT COUNT(*) as cnt FROM sigil_drop_log 
             WHERE player_id = ? AND season_id = ? AND drop_tick >= ?",
            [$playerId, $seasonId, $windowStartGameTime]
        )['cnt'] ?? 0;
        
        $throttled = ($dropsInWindow >= SIGIL_MAX_DROPS_WINDOW);
        
        // Pre-compute per-player drop config once for this batch (accounts for inventory
        // and boost activity); reused across every tick in the loop for efficiency.
        $dropConfig = Economy::computePerPlayerSigilDropConfig($player, $boostModFp);

        // Phase A: accrue earned drops over all processed eligible ticks.
        $newPityCounter = $pityCounter;
        for ($t = 0; $t < $ticksToProcess; $t++) {
            $tickIndex = $lastSeasonTick + $t;
            $absoluteTick = $startTime + $tickIndex;

            $newPityCounter++;
            if ($newPityCounter >= SIGIL_PITY_TICKS) {
                $pendingPityDrops++;
                $newPityCounter = 0;
                continue;
            }

            $tier = Economy::processSigilDrop($season, $playerId, $absoluteTick, 0, $dropConfig);
            if ($tier > 0) {
                $pendingRngDrops++;
                $newPityCounter = 0;
            }
        }

        // Phase B: paced delivery – one queued drop per processing cycle.
        $dropsAwarded = 0;
        $maxNewDrops = max(0, SIGIL_MAX_DROPS_WINDOW - (int)$dropsInWindow);
        if (!$throttled && $maxNewDrops > 0 && ($pendingPityDrops + $pendingRngDrops) > 0) {
            $deliveryAllowed = ($nextDeliveryTick <= 0 || $gameTime >= $nextDeliveryTick);
            if ($deliveryAllowed) {
                if ($pendingPityDrops > 0) {
                    self::awardSigilDrop($playerId, $seasonId, 1, $gameTime, 'PITY');
                    $pendingPityDrops--;
                    $dropsAwarded = 1;
                } elseif ($pendingRngDrops > 0) {
                    $tier = Economy::sampleSigilTier($season, $playerId, $gameTime, $dropConfig);
                    self::awardSigilDrop($playerId, $seasonId, $tier, $gameTime, 'RNG');
                    $pendingRngDrops--;
                    $dropsAwarded = 1;
                }
            }
        }

        if ($dropsAwarded > 0) {
            $nextDeliveryTick = self::computeNextSigilDeliveryTick($season, $playerId, $gameTime, (int)$dropConfig['drop_rate']);
        }
        
        // Persist pity and queued pacing state.
        $db->query(
            "UPDATE season_participation
             SET eligible_ticks_since_last_drop = ?,
                 pending_rng_sigil_drops = ?,
                 pending_pity_sigil_drops = ?,
                 sigil_next_delivery_tick = ?
             WHERE player_id = ? AND season_id = ?",
            [$newPityCounter, $pendingRngDrops, $pendingPityDrops, $nextDeliveryTick, $playerId, $seasonId]
        );
    }

    /**
     * Determine the next eligible delivery tick for queued drops.
     * Adds deterministic jitter so drops feel naturally random over time.
     */
    private static function computeNextSigilDeliveryTick($season, $playerId, $fromTick, $baseDropRate) {
        $base = max(1, (int)$baseDropRate);
        $minFp = max(1, (int)SIGIL_PACING_JITTER_MIN_FP);
        $maxFp = max($minFp, (int)SIGIL_PACING_JITTER_MAX_FP);

        $seed = $season['season_seed'];
        $input = pack('J', (int)$season['season_id']) . pack('J', (int)$fromTick) . $seed . pack('J', (int)$playerId) . 'pace';
        $hash = hash('sha256', $input, true);
        $span = $maxFp - $minFp + 1;
        $rollFp = $minFp + (unpack('N', substr($hash, 8, 4))[1] % $span);

        $spacing = max(1, intdiv($base * $rollFp, FP_SCALE));
        return (int)$fromTick + $spacing;
    }
    
    /**
     * Award a Sigil drop to a player
     */
    private static function awardSigilDrop($playerId, $seasonId, $tier, $dropTick, $source) {
        $db = Database::getInstance();
        $sigilCol = "sigils_t{$tier}";
        
        // Add sigil to inventory
        $db->query(
            "UPDATE season_participation SET {$sigilCol} = {$sigilCol} + 1, sigil_drops_total = sigil_drops_total + 1
             WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        // Log the drop
        $db->query(
            "INSERT INTO sigil_drop_log (player_id, season_id, drop_tick, tier, source) VALUES (?, ?, ?, ?, ?)",
            [$playerId, $seasonId, $dropTick, $tier, $source]
        );

        $tierNames = [
            1 => 'Common',
            2 => 'Uncommon',
            3 => 'Rare',
            4 => 'Epic',
            5 => 'Legendary'
        ];
        $sourceNormalized = strtoupper((string)$source) === 'PITY' ? 'pity' : 'rng';
        $tierName = $tierNames[(int)$tier] ?? ('Tier ' . (int)$tier);
        Notifications::create(
            $playerId,
            'sigil_drop',
            'Sigil Drop: Tier ' . (int)$tier,
            sprintf('You found a %s sigil (%s).', $tierName, strtoupper($sourceNormalized)),
            [
                'event_key' => sprintf(
                    'sigil_drop:%d:%d:%d:%s',
                    (int)$seasonId,
                    (int)$dropTick,
                    (int)$tier,
                    $sourceNormalized
                ),
                'payload' => [
                    'season_id' => (int)$seasonId,
                    'drop_tick' => (int)$dropTick,
                    'tier' => (int)$tier,
                    'source' => $sourceNormalized
                ]
            ]
        );
        
        // Update tracking
        $db->query(
            "INSERT INTO sigil_drop_tracking (player_id, season_id, eligible_ticks_since_last_drop, total_drops, last_drop_tick)
             VALUES (?, ?, 0, 1, ?)
             ON DUPLICATE KEY UPDATE eligible_ticks_since_last_drop = 0, total_drops = total_drops + 1, last_drop_tick = ?",
            [$playerId, $seasonId, $dropTick, $dropTick]
        );
    }
    
    /**
     * Expire boosts that have passed their expiration tick
     */
    private static function expireBoosts($seasonId, $gameTime) {
        $db = Database::getInstance();
        $db->query(
            "UPDATE active_boosts SET is_active = 0 
             WHERE season_id = ? AND is_active = 1 AND expires_tick < ?",
            [$seasonId, $gameTime]
        );
    }

    /**
     * Expire freeze effects that have elapsed.
     */
    private static function expireFreezes($seasonId, $gameTime) {
        $db = Database::getInstance();
        $db->query(
            "UPDATE active_freezes SET is_active = 0
             WHERE season_id = ? AND is_active = 1 AND expires_tick < ?",
            [$seasonId, $gameTime]
        );
    }

    /**
     * Check whether a player is currently frozen.
     */
    private static function isPlayerFrozen($playerId, $seasonId, $gameTime) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT COUNT(*) AS cnt FROM active_freezes
             WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ?",
            [$playerId, $seasonId, $gameTime]
        );
        return ((int)($row['cnt'] ?? 0)) > 0;
    }
    
    /**
     * Get active global boosts for a season
     */
    private static function getActiveGlobalBoosts($seasonId, $gameTime) {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT ab.*, bc.name, bc.tier_required, bc.modifier_fp as catalog_modifier_fp
             FROM active_boosts ab
             JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
             WHERE ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'GLOBAL' AND ab.expires_tick >= ?",
            [$seasonId, $gameTime]
        );
    }
    
    /**
     * Get active self boosts for a specific player
     */
    private static function getActivePlayerBoosts($playerId, $seasonId, $gameTime) {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT ab.*, bc.name, bc.tier_required, bc.modifier_fp as catalog_modifier_fp
             FROM active_boosts ab
             JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
             WHERE ab.player_id = ? AND ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'SELF' AND ab.expires_tick >= ?",
            [$playerId, $seasonId, $gameTime]
        );
    }
    
    /**
     * Calculate total boost modifier (fixed-point) from active boosts
     * Returns the sum of all modifier_fp values (to be added to FP_SCALE for multiplier)
     */
    private static function calculateBoostModifier($selfBoosts, $globalBoosts) {
        $totalMod = 0;
        
        foreach ($selfBoosts as $b) {
            $b = BoostCatalog::normalize($b);
            $totalMod += (int)$b['modifier_fp'];
        }
        foreach ($globalBoosts as $b) {
            $b = BoostCatalog::normalize($b);
            $totalMod += (int)$b['modifier_fp'];
        }
        
        // Clamp: max 5x UBI multiplier (mod_cap_multiplier_fp = 5_000_000)
        $maxMod = 5000000 - FP_SCALE; // 4_000_000
        return Economy::clamp($totalMod, 0, $maxMod);
    }
    
    /**
     * Process season expiration and finalization
     */
    private static function processExpiration($season) {
        $db = Database::getInstance();
        $seasonId = $season['season_id'];
        
        $db->beginTransaction();
        try {
            // 1. Mark expired
            $db->query(
                "UPDATE seasons SET season_expired = 1, status = 'Expired' WHERE season_id = ?",
                [$seasonId]
            );
            
            // 2. Remove all boosts and system events
            $db->query(
                "UPDATE active_boosts SET is_active = 0 WHERE season_id = ?",
                [$seasonId]
            );
            
            // 3. Cancel all open trades
            $db->query(
                "UPDATE trades SET status = 'EXPIRED' WHERE season_id = ? AND status = 'OPEN'",
                [$seasonId]
            );
            
            // 4. Get end-finishers
            $endFinishers = $db->fetchAll(
                "SELECT p.player_id, sp.* FROM players p
                 JOIN season_participation sp ON p.player_id = sp.player_id
                 WHERE p.joined_season_id = ? AND p.participation_enabled = 1 AND sp.season_id = ?",
                [$seasonId, $seasonId]
            );
            
            // Mark end membership
            foreach ($endFinishers as $ef) {
                $db->query(
                    "UPDATE season_participation SET end_membership = 1, final_seasonal_stars = seasonal_stars
                     WHERE player_id = ? AND season_id = ?",
                    [$ef['player_id'], $seasonId]
                );
            }
            
            // 5. Compute final rankings
            $rankedEntries = $db->fetchAll(
                "SELECT sp.player_id, sp.seasonal_stars, sp.lock_in_snapshot_seasonal_stars,
                        sp.end_membership, sp.active_ticks_total
                 FROM season_participation sp
                 WHERE sp.season_id = ? 
                 AND (sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
                 ORDER BY sp.seasonal_stars DESC, sp.player_id ASC",
                [$seasonId]
            );
            
            $rank = 0;
            foreach ($rankedEntries as $entry) {
                $rank++;
                $db->query(
                    "UPDATE season_participation SET final_rank = ? WHERE player_id = ? AND season_id = ?",
                    [$rank, $entry['player_id'], $seasonId]
                );
            }
            
            // 6. No-winner guard
            $topValue = !empty($rankedEntries) ? (int)$rankedEntries[0]['seasonal_stars'] : 0;
            $awardBadgesAndPlacement = ($topValue > 0);
            
            // 7. Award bonuses to end-finishers
            $endRank = 0;
            foreach ($endFinishers as $ef) {
                $endRank++;
                $globalStarsEarned = 0;
                
                // Participation bonus
                $activeTicks = (int)$ef['active_ticks_total'];
                $participationBonus = min(intdiv($activeTicks, PARTICIPATION_BONUS_DIVISOR), PARTICIPATION_BONUS_CAP);
                $globalStarsEarned += $participationBonus;
                
                // Placement bonus
                $placementBonus = 0;
                if ($awardBadgesAndPlacement && isset(PLACEMENT_BONUS[$endRank])) {
                    $placementBonus = PLACEMENT_BONUS[$endRank];
                    $globalStarsEarned += $placementBonus;
                }
                
                // Natural-end conversion: SeasonalStars -> GlobalStars 1:1
                $seasonalStars = (int)$ef['seasonal_stars'];
                $globalStarsEarned += $seasonalStars;

                // Apply to player
                $db->query(
                    "UPDATE players SET global_stars = global_stars + ? WHERE player_id = ?",
                    [$globalStarsEarned, $ef['player_id']]
                );
                
                // Record in participation
                $db->query(
                    "UPDATE season_participation SET 
                     global_stars_earned = ?, participation_bonus = ?, placement_bonus = ?,
                     seasonal_stars = 0, coins = 0, 
                     sigils_t1 = 0, sigils_t2 = 0, sigils_t3 = 0, sigils_t4 = 0, sigils_t5 = 0, sigils_t6 = 0,
                     active_boosts = NULL
                     WHERE player_id = ? AND season_id = ?",
                    [$globalStarsEarned, $participationBonus, $placementBonus, $ef['player_id'], $seasonId]
                );
                
                // Award badges
                if ($awardBadgesAndPlacement && $endRank <= 3) {
                    $badgeTypes = [1 => 'seasonal_first', 2 => 'seasonal_second', 3 => 'seasonal_third'];
                    $db->query(
                        "INSERT INTO badges (player_id, badge_type, season_id) VALUES (?, ?, ?)",
                        [$ef['player_id'], $badgeTypes[$endRank], $seasonId]
                    );
                }
                
                // Detach player from season
                $db->query(
                    "UPDATE players SET joined_season_id = NULL, participation_enabled = 0, 
                     idle_modal_active = 0, activity_state = 'Active'
                     WHERE player_id = ?",
                    [$ef['player_id']]
                );
            }
            
            // 8. Mark finalized
            $db->query(
                "UPDATE seasons SET expiration_finalized = 1 WHERE season_id = ?",
                [$seasonId]
            );
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log("Expiration processing error for season {$seasonId}: " . $e->getMessage());
        }
    }
    
    /**
     * Update vault costs based on remaining supply
     */
    private static function updateVaultCosts($seasonId) {
        $db = Database::getInstance();
        $season = $db->fetch("SELECT vault_config FROM seasons WHERE season_id = ?", [$seasonId]);
        
        $vaultItems = $db->fetchAll(
            "SELECT * FROM season_vault WHERE season_id = ?",
            [$seasonId]
        );
        
        foreach ($vaultItems as $item) {
            $newCost = Economy::calculateVaultCost(
                $season['vault_config'],
                $item['tier'],
                $item['remaining_supply']
            );
            
            if ($newCost !== null && $newCost != $item['current_cost_stars']) {
                $db->query(
                    "UPDATE season_vault SET current_cost_stars = ?, last_published_cost_stars = ?
                     WHERE season_id = ? AND tier = ?",
                    [$newCost, $newCost, $seasonId, $item['tier']]
                );
            }
        }
    }
}
