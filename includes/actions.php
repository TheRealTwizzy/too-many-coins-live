<?php
/**
 * Too Many Coins - Player Actions
 * Handles all player actions: join, purchase stars, vault, boost, trade, lock-in
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/game_time.php';
require_once __DIR__ . '/economy.php';
require_once __DIR__ . '/auth.php';

class Actions {
    
    /**
     * Join a season
     */
    public static function seasonJoin($playerId, $seasonId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        // Staff check
        if ($player['role'] !== 'Player') {
            return ['error' => 'Staff accounts cannot participate in seasons', 'reason_code' => 'staff_participation_forbidden'];
        }
        
        // Already in a season check
        if ($player['joined_season_id'] !== null) {
            return ['error' => 'Already participating in a season. Lock-In or wait for season end first.'];
        }
        
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        if (!$season) return ['error' => 'Season not found'];
        
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Scheduled') return ['error' => 'Season has not started yet'];
        if ($status === 'Expired') return ['error' => 'Season has expired'];
        
        // Check if join would be effective before expiration
        $gameTime = GameTime::now();
        if ($gameTime >= $season['end_time'] - 1) {
            return ['error' => 'Too late to join this season'];
        }
        
        $db->beginTransaction();
        try {
            // Create or reset participation record
            $existing = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$playerId, $seasonId]
            );
            
            if ($existing) {
                // Re-entry: reset season-bound state, keep participation_time_total
                $db->query(
                    "UPDATE season_participation SET 
                     coins = 0, seasonal_stars = 0,
                     sigils_t1 = 0, sigils_t2 = 0, sigils_t3 = 0, sigils_t4 = 0, sigils_t5 = 0,
                     participation_ticks_since_join = 0, spend_window_total = 0,
                     active_boosts = NULL
                     WHERE player_id = ? AND season_id = ?",
                    [$playerId, $seasonId]
                );
            } else {
                $db->query(
                    "INSERT INTO season_participation (player_id, season_id, first_joined_at)
                     VALUES (?, ?, ?)",
                    [$playerId, $seasonId, $gameTime]
                );
            }
            
            // Update player state
            $db->query(
                "UPDATE players SET 
                 joined_season_id = ?, participation_enabled = 1,
                 idle_modal_active = 0, activity_state = 'Active',
                 last_activity_tick = ?
                 WHERE player_id = ?",
                [$seasonId, $gameTime, $playerId]
            );
            
            // Cancel any open trades by this player in any season
            $db->query(
                "UPDATE trades SET status = 'CANCELED' 
                 WHERE (initiator_id = ? OR acceptor_id = ?) AND status = 'OPEN'",
                [$playerId, $playerId]
            );
            
            $db->commit();
            return ['success' => true, 'message' => 'Joined season successfully'];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Failed to join season: ' . $e->getMessage()];
        }
    }
    
    /**
     * Purchase Seasonal Stars by quantity
     */
    public static function purchaseStars($playerId, $starsRequested) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Blackout') {
            return ['error' => 'Star purchases are not available during blackout', 'reason_code' => 'blackout_disallows_action'];
        }

        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        $starsRequested = (int)$starsRequested;
        if ($starsRequested <= 0) return ['error' => 'Must request a positive star quantity'];
        
        // Get locked star price
        $starPrice = (int)$season['current_star_price'];
        if ($starPrice <= 0) return ['error' => 'Invalid star price'];
        
        $coinsNeeded = $starsRequested * $starPrice;
        
        // Affordability check
        if ($participation['coins'] < $coinsNeeded) {
            return ['error' => 'Insufficient coins'];
        }
        
        $db->beginTransaction();
        try {
            // Burn coins, credit stars
            $db->query(
                "UPDATE season_participation SET 
                 coins = coins - ?, seasonal_stars = seasonal_stars + ?,
                 spend_window_total = spend_window_total + ?
                 WHERE player_id = ? AND season_id = ?",
                [$coinsNeeded, $starsRequested, $coinsNeeded, $playerId, $seasonId]
            );
            
            // Update season supply
            $db->query(
                "UPDATE seasons SET total_coins_supply = total_coins_supply - ? WHERE season_id = ?",
                [$coinsNeeded, $seasonId]
            );
            
            // Update activity
            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [GameTime::now(), $playerId]
            );
            
            $db->commit();
            return [
                'success' => true,
                'stars_purchased' => $starsRequested,
                'coins_spent' => $coinsNeeded,
                'star_price' => $starPrice
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Purchase failed'];
        }
    }
    
    /**
     * Purchase a Sigil from the Vault
     */
    public static function purchaseVaultSigil($playerId, $tier) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        // Blackout check
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Blackout') {
            return ['error' => 'Vault purchases are not available during blackout', 'reason_code' => 'blackout_disallows_action'];
        }
        
        $tier = (int)$tier;
        if ($tier < 1 || $tier > 5) return ['error' => 'Invalid tier'];
        
        $vault = $db->fetch(
            "SELECT * FROM season_vault WHERE season_id = ? AND tier = ?",
            [$seasonId, $tier]
        );
        
        if (!$vault || $vault['remaining_supply'] <= 0) {
            return ['error' => 'This tier is sold out'];
        }
        
        $costStars = (int)$vault['current_cost_stars'];
        
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        if ($participation['seasonal_stars'] < $costStars) {
            return ['error' => 'Insufficient Seasonal Stars'];
        }
        
        $db->beginTransaction();
        try {
            // Burn stars, add sigil, decrement vault
            $sigilCol = "sigils_t{$tier}";
            $db->query(
                "UPDATE season_participation SET seasonal_stars = seasonal_stars - ?, {$sigilCol} = {$sigilCol} + 1
                 WHERE player_id = ? AND season_id = ?",
                [$costStars, $playerId, $seasonId]
            );
            
            $db->query(
                "UPDATE season_vault SET remaining_supply = remaining_supply - 1 WHERE season_id = ? AND tier = ?",
                [$seasonId, $tier]
            );
            
            // Update activity
            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [GameTime::now(), $playerId]
            );
            
            $db->commit();
            return ['success' => true, 'tier' => $tier, 'cost_stars' => $costStars];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Vault purchase failed'];
        }
    }
    
    /**
     * Lock-In: voluntarily exit season, convert SeasonalStars to GlobalStars
     */
    public static function lockIn($playerId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        // Blackout check
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Blackout') {
            return ['error' => 'Lock-In is not available during blackout', 'reason_code' => 'blackout_disallows_action'];
        }
        if ($status === 'Expired') {
            return ['error' => 'Season has expired'];
        }
        
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        // Minimum participation check
        if ($participation['participation_ticks_since_join'] < MIN_PARTICIPATION_TICKS) {
            return ['error' => 'Must participate for at least 1 tick before Lock-In'];
        }
        
        $gameTime = GameTime::now();
        $seasonalStars = (int)$participation['seasonal_stars'];
        
        $db->beginTransaction();
        try {
            // 1. Record Lock-In snapshot
            $db->query(
                "UPDATE season_participation SET 
                 lock_in_effect_tick = ?,
                 lock_in_snapshot_seasonal_stars = ?,
                 lock_in_snapshot_participation_time = participation_time_total
                 WHERE player_id = ? AND season_id = ?",
                [$gameTime, $seasonalStars, $playerId, $seasonId]
            );
            
            // 2. Convert SeasonalStars -> GlobalStars 1:1
            $db->query(
                "UPDATE players SET global_stars = global_stars + ? WHERE player_id = ?",
                [$seasonalStars, $playerId]
            );
            
            // 3. Destroy all season-bound resources
            $db->query(
                "UPDATE season_participation SET 
                 coins = 0, seasonal_stars = 0,
                 sigils_t1 = 0, sigils_t2 = 0, sigils_t3 = 0, sigils_t4 = 0, sigils_t5 = 0,
                 active_boosts = NULL
                 WHERE player_id = ? AND season_id = ?",
                [$playerId, $seasonId]
            );
            
            // 4. Exit season
            $db->query(
                "UPDATE players SET 
                 joined_season_id = NULL, participation_enabled = 0,
                 idle_modal_active = 0, activity_state = 'Active'
                 WHERE player_id = ?",
                [$playerId]
            );
            
            // 5. Cancel open trades
            $db->query(
                "UPDATE trades SET status = 'CANCELED' 
                 WHERE (initiator_id = ? OR acceptor_id = ?) AND status = 'OPEN' AND season_id = ?",
                [$playerId, $playerId, $seasonId]
            );
            
            // Update coins supply
            $coinsDestroyed = (int)$participation['coins'];
            if ($coinsDestroyed > 0) {
                $db->query(
                    "UPDATE seasons SET total_coins_supply = GREATEST(0, total_coins_supply - ?) WHERE season_id = ?",
                    [$coinsDestroyed, $seasonId]
                );
            }
            
            $db->commit();
            return [
                'success' => true,
                'seasonal_stars_converted' => $seasonalStars,
                'global_stars_gained' => $seasonalStars,
                'message' => "Locked in! Converted {$seasonalStars} Seasonal Stars to Global Stars."
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Lock-In failed'];
        }
    }
    
    /**
     * Initiate a trade
     */
    public static function tradeInitiate($playerId, $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot trade while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        // Blackout check
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Blackout') {
            return ['error' => 'Trading is not available during blackout', 'reason_code' => 'blackout_disallows_action'];
        }
        
        // Validate counterparty
        $acceptor = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$acceptorId]);
        if (!$acceptor || $acceptor['joined_season_id'] != $seasonId) {
            return ['error' => 'Counterparty is not in the same season'];
        }
        
        // Validate trade composition
        $aCoins = max(0, (int)$sideACoins);
        $bCoins = max(0, (int)$sideBCoins);
        $aSigilCount = array_sum($sideASigils);
        $bSigilCount = array_sum($sideBSigils);
        
        if (($aCoins == 0 && $aSigilCount == 0) || ($bCoins == 0 && $bSigilCount == 0)) {
            return ['error' => 'Both parties must contribute value'];
        }
        if ($aSigilCount == 0 && $bSigilCount == 0) {
            return ['error' => 'Coins-for-Coins trades are not allowed'];
        }
        
        // Check affordability for initiator
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        if ($participation['coins'] < $aCoins) return ['error' => 'Insufficient coins'];
        for ($t = 0; $t < 5; $t++) {
            $sigilCol = 'sigils_t' . ($t + 1);
            if (($sideASigils[$t] ?? 0) > $participation[$sigilCol]) {
                return ['error' => 'Insufficient Tier ' . ($t + 1) . ' Sigils'];
            }
        }
        
        // Calculate trade value and fee
        $declaredValue = Economy::calculateTradeValue($season, $aCoins, $sideASigils, $bCoins, $sideBSigils);
        $fee = Economy::calculateTradeFee($season, $declaredValue);
        
        // Check initiator can afford fee
        if ($participation['coins'] < $aCoins + $fee) {
            return ['error' => 'Insufficient coins to cover trade fee'];
        }
        
        $gameTime = GameTime::now();
        
        $db->beginTransaction();
        try {
            // Escrow initiator assets + fee
            $db->query(
                "UPDATE season_participation SET coins = coins - ? WHERE player_id = ? AND season_id = ?",
                [$aCoins + $fee, $playerId, $seasonId]
            );
            for ($t = 0; $t < 5; $t++) {
                if (($sideASigils[$t] ?? 0) > 0) {
                    $col = 'sigils_t' . ($t + 1);
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} - ? WHERE player_id = ? AND season_id = ?",
                        [$sideASigils[$t], $playerId, $seasonId]
                    );
                }
            }
            
            // Create trade
            $tradeId = $db->insert(
                "INSERT INTO trades (season_id, initiator_id, acceptor_id, status,
                 side_a_coins, side_a_sigils, side_b_coins, side_b_sigils,
                 surface_version_used, declared_value_coins, locked_fee_coins,
                 created_tick, expires_tick)
                 VALUES (?, ?, ?, 'OPEN', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$seasonId, $playerId, $acceptorId,
                 $aCoins, json_encode($sideASigils), $bCoins, json_encode($sideBSigils),
                 $gameTime, $declaredValue, $fee,
                 $gameTime, $gameTime + TRADE_TIMEOUT_TICKS]
            );
            
            // Update activity
            $db->query(
                "UPDATE players SET last_activity_tick = ? WHERE player_id = ?",
                [$gameTime, $playerId]
            );
            
            $db->commit();
            return ['success' => true, 'trade_id' => $tradeId, 'fee' => $fee, 'declared_value' => $declaredValue];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Trade initiation failed'];
        }
    }
    
    /**
     * Accept a trade
     */
    public static function tradeAccept($playerId, $tradeId) {
        $db = Database::getInstance();
        $trade = $db->fetch("SELECT * FROM trades WHERE trade_id = ? AND status = 'OPEN'", [$tradeId]);
        if (!$trade) return ['error' => 'Trade not found or not open'];
        if ($trade['acceptor_id'] != $playerId) return ['error' => 'This trade is not for you'];
        
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot trade while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $trade['season_id'];
        $fee = (int)$trade['locked_fee_coins'];
        $sideBCoins = (int)$trade['side_b_coins'];
        $sideBSigils = json_decode($trade['side_b_sigils'], true);
        $sideACoins = (int)$trade['side_a_coins'];
        $sideASigils = json_decode($trade['side_a_sigils'], true);
        
        // Check acceptor affordability
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        if ($participation['coins'] < $sideBCoins + $fee) {
            return ['error' => 'Insufficient coins to accept trade (including fee)'];
        }
        for ($t = 0; $t < 5; $t++) {
            $col = 'sigils_t' . ($t + 1);
            if (($sideBSigils[$t] ?? 0) > $participation[$col]) {
                return ['error' => 'Insufficient Tier ' . ($t + 1) . ' Sigils'];
            }
        }
        
        $gameTime = GameTime::now();
        $initiatorId = $trade['initiator_id'];
        
        $db->beginTransaction();
        try {
            // Deduct acceptor's assets + fee
            $db->query(
                "UPDATE season_participation SET coins = coins - ? WHERE player_id = ? AND season_id = ?",
                [$sideBCoins + $fee, $playerId, $seasonId]
            );
            for ($t = 0; $t < 5; $t++) {
                if (($sideBSigils[$t] ?? 0) > 0) {
                    $col = 'sigils_t' . ($t + 1);
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} - ? WHERE player_id = ? AND season_id = ?",
                        [$sideBSigils[$t], $playerId, $seasonId]
                    );
                }
            }
            
            // Give initiator's assets to acceptor
            $db->query(
                "UPDATE season_participation SET coins = coins + ? WHERE player_id = ? AND season_id = ?",
                [$sideACoins, $playerId, $seasonId]
            );
            for ($t = 0; $t < 5; $t++) {
                if (($sideASigils[$t] ?? 0) > 0) {
                    $col = 'sigils_t' . ($t + 1);
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} + ? WHERE player_id = ? AND season_id = ?",
                        [$sideASigils[$t], $playerId, $seasonId]
                    );
                }
            }
            
            // Give acceptor's assets to initiator
            $db->query(
                "UPDATE season_participation SET coins = coins + ? WHERE player_id = ? AND season_id = ?",
                [$sideBCoins, $initiatorId, $seasonId]
            );
            for ($t = 0; $t < 5; $t++) {
                if (($sideBSigils[$t] ?? 0) > 0) {
                    $col = 'sigils_t' . ($t + 1);
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} + ? WHERE player_id = ? AND season_id = ?",
                        [$sideBSigils[$t], $initiatorId, $seasonId]
                    );
                }
            }
            
            // Fees are burned (already deducted, not given to anyone)
            // Update coins supply (fees burned)
            $totalFeesBurned = $fee * 2; // Both parties
            $db->query(
                "UPDATE seasons SET total_coins_supply = GREATEST(0, total_coins_supply - ?) WHERE season_id = ?",
                [$totalFeesBurned, $seasonId]
            );
            
            // Mark trade as accepted
            $db->query(
                "UPDATE trades SET status = 'ACCEPTED', resolved_tick = ? WHERE trade_id = ?",
                [$gameTime, $tradeId]
            );
            
            // Update activity for both
            $db->query(
                "UPDATE players SET last_activity_tick = ? WHERE player_id IN (?, ?)",
                [$gameTime, $playerId, $initiatorId]
            );
            
            $db->commit();
            return ['success' => true, 'message' => 'Trade completed successfully'];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Trade acceptance failed'];
        }
    }
    
    /**
     * Decline or cancel a trade
     */
    public static function tradeCancel($playerId, $tradeId, $action = 'CANCELED') {
        $db = Database::getInstance();
        $trade = $db->fetch("SELECT * FROM trades WHERE trade_id = ? AND status = 'OPEN'", [$tradeId]);
        if (!$trade) return ['error' => 'Trade not found or not open'];
        
        if ($action === 'DECLINED' && $trade['acceptor_id'] != $playerId) {
            return ['error' => 'Only the intended acceptor can decline'];
        }
        if ($action === 'CANCELED' && $trade['initiator_id'] != $playerId) {
            return ['error' => 'Only the initiator can cancel'];
        }
        
        $seasonId = $trade['season_id'];
        $initiatorId = $trade['initiator_id'];
        $fee = (int)$trade['locked_fee_coins'];
        $sideACoins = (int)$trade['side_a_coins'];
        $sideASigils = json_decode($trade['side_a_sigils'], true);
        
        $db->beginTransaction();
        try {
            // Return escrowed assets to initiator
            $db->query(
                "UPDATE season_participation SET coins = coins + ? WHERE player_id = ? AND season_id = ?",
                [$sideACoins + $fee, $initiatorId, $seasonId]
            );
            for ($t = 0; $t < 5; $t++) {
                if (($sideASigils[$t] ?? 0) > 0) {
                    $col = 'sigils_t' . ($t + 1);
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} + ? WHERE player_id = ? AND season_id = ?",
                        [$sideASigils[$t], $initiatorId, $seasonId]
                    );
                }
            }
            
            $db->query(
                "UPDATE trades SET status = ?, resolved_tick = ? WHERE trade_id = ?",
                [$action, GameTime::now(), $tradeId]
            );
            
            $db->commit();
            return ['success' => true, 'message' => 'Trade ' . strtolower($action)];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Trade cancellation failed'];
        }
    }
    
    /**
     * Acknowledge idle modal (return to Active)
     */
    public static function idleAck($playerId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['idle_modal_active']) {
            return ['error' => 'Not idle'];
        }
        
        $gameTime = GameTime::now();
        $db->query(
            "UPDATE players SET 
             idle_modal_active = 0, activity_state = 'Active',
             idle_since_tick = NULL, last_activity_tick = ?
             WHERE player_id = ?",
            [$gameTime, $playerId]
        );
        
        return ['success' => true, 'message' => 'Welcome back! You are now Active.'];
    }
    
    /**
     * Purchase a cosmetic with Global Stars
     */
    public static function purchaseCosmetic($playerId, $cosmeticId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        $cosmetic = $db->fetch("SELECT * FROM cosmetic_catalog WHERE cosmetic_id = ?", [$cosmeticId]);
        if (!$cosmetic) return ['error' => 'Cosmetic not found'];
        
        $price = (int)$cosmetic['price_global_stars'];
        if ($player['global_stars'] < $price) {
            return ['error' => 'Insufficient Global Stars'];
        }
        
        // Check if already owned
        $owned = $db->fetch(
            "SELECT * FROM player_cosmetics WHERE player_id = ? AND cosmetic_id = ?",
            [$playerId, $cosmeticId]
        );
        if ($owned) return ['error' => 'Already owned'];
        
        $db->beginTransaction();
        try {
            $db->query(
                "UPDATE players SET global_stars = global_stars - ? WHERE player_id = ?",
                [$price, $playerId]
            );
            $db->query(
                "INSERT INTO player_cosmetics (player_id, cosmetic_id) VALUES (?, ?)",
                [$playerId, $cosmeticId]
            );
            $db->commit();
            return ['success' => true, 'message' => 'Cosmetic purchased!'];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Purchase failed'];
        }
    }
    
    /**
     * Equip/unequip a cosmetic
     */
    public static function equipCosmetic($playerId, $cosmeticId, $equip = true) {
        $db = Database::getInstance();
        
        // Get cosmetic category
        $cosmetic = $db->fetch(
            "SELECT c.category FROM player_cosmetics pc 
             JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
             WHERE pc.player_id = ? AND pc.cosmetic_id = ?",
            [$playerId, $cosmeticId]
        );
        if (!$cosmetic) return ['error' => 'Cosmetic not owned'];
        
        if ($equip) {
            // Unequip others in same category
            $db->query(
                "UPDATE player_cosmetics pc 
                 JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
                 SET pc.equipped = 0
                 WHERE pc.player_id = ? AND c.category = ?",
                [$playerId, $cosmetic['category']]
            );
        }
        
        $db->query(
            "UPDATE player_cosmetics SET equipped = ? WHERE player_id = ? AND cosmetic_id = ?",
            [$equip ? 1 : 0, $playerId, $cosmeticId]
        );
        
        return ['success' => true];
    }
    
    /**
     * Purchase and activate a Boost by consuming a Sigil
     * Per canon: player submits boost_id, server validates sigil tier requirement,
     * destroys the sigil, and activates the boost for its duration.
     */
    public static function purchaseBoost($playerId, $boostId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        // Blackout check - no boost activation during blackout
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Blackout') {
            return ['error' => 'Boost activation is not available during blackout', 'reason_code' => 'blackout_disallows_action'];
        }
        if ($status !== 'Active') {
            return ['error' => 'Season is not active'];
        }
        
        // Get boost from catalog
        $boost = $db->fetch("SELECT * FROM boost_catalog WHERE boost_id = ?", [$boostId]);
        if (!$boost) return ['error' => 'Boost not found'];
        
        $tierRequired = (int)$boost['tier_required'];
        $sigilCost = (int)$boost['sigil_cost'];
        $scope = $boost['scope'];
        $durationTicks = (int)$boost['duration_ticks'];
        // Backward compatibility: legacy self boosts were seeded as 60/120/180 ticks.
        // Canonical minute-based self boosts use 1/2/3 ticks.
        if ($scope === 'SELF' && $durationTicks >= 60 && $durationTicks <= 180 && $durationTicks % 60 === 0) {
            $durationTicks = intdiv($durationTicks, 60);
        }
        $modifierFp = (int)$boost['modifier_fp'];
        $maxStack = (int)$boost['max_stack'];
        
        // Get participation
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        // Check sigil inventory
        $sigilCol = "sigils_t{$tierRequired}";
        if ((int)$participation[$sigilCol] < $sigilCost) {
            return ['error' => "Insufficient Tier {$tierRequired} Sigils. Need {$sigilCost}, have {$participation[$sigilCol]}"];
        }
        
        // Check max stack
        $gameTime = GameTime::now();
        if ($scope === 'SELF') {
            $activeCount = $db->fetch(
                "SELECT COUNT(*) as cnt FROM active_boosts 
                 WHERE player_id = ? AND season_id = ? AND boost_id = ? AND is_active = 1 AND expires_tick >= ?",
                [$playerId, $seasonId, $boostId, $gameTime]
            )['cnt'];
        } else {
            // For GLOBAL boosts, check if this player already has one active
            $activeCount = $db->fetch(
                "SELECT COUNT(*) as cnt FROM active_boosts 
                 WHERE player_id = ? AND season_id = ? AND boost_id = ? AND is_active = 1 AND expires_tick >= ?",
                [$playerId, $seasonId, $boostId, $gameTime]
            )['cnt'];
        }
        
        if ($activeCount >= $maxStack) {
            return ['error' => 'Maximum active instances of this boost reached'];
        }
        
        $expiresTick = $gameTime + $durationTicks;
        
        $db->beginTransaction();
        try {
            // 1. Consume sigil(s)
            $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - ? WHERE player_id = ? AND season_id = ?",
                [$sigilCost, $playerId, $seasonId]
            );
            
            // 2. Create active boost record
            $db->query(
                "INSERT INTO active_boosts (player_id, season_id, boost_id, scope, modifier_fp, activated_tick, expires_tick, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                [$playerId, $seasonId, $boostId, $scope, $modifierFp, $gameTime, $expiresTick]
            );
            
            // 3. Update activity
            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [$gameTime, $playerId]
            );
            
            $db->commit();
            
            $scopeLabel = ($scope === 'GLOBAL') ? 'all players in the season' : 'you';
            return [
                'success' => true,
                'boost_name' => $boost['name'],
                'scope' => $scope,
                'modifier_percent' => round($modifierFp / 10000, 1),
                'duration_ticks' => $durationTicks,
                'expires_tick' => $expiresTick,
                'sigils_consumed' => $sigilCost,
                'tier_consumed' => $tierRequired,
                'message' => "{$boost['name']} activated! UBI +" . round($modifierFp / 10000, 1) . "% for {$scopeLabel}."
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Boost activation failed: ' . $e->getMessage()];
        }
    }
}
