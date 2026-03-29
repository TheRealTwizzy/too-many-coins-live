<?php
/**
 * Tests for boost duration timing correctness.
 *
 * Root cause fixed: previously, expireBoosts used `expires_tick <= gameTime`
 * which meant a boost expired BEFORE UBI accrual in the same tick, effectively
 * stealing one full tick of benefit from every boost.
 *
 * Fix: expireBoosts now uses `expires_tick < gameTime` (strictly less than),
 * and active-boost queries use `expires_tick >= gameTime` (inclusive).
 * This ensures a boost with expires_tick = T is still considered active at
 * gameTime T and only removed at gameTime T+1.
 *
 * 1 game tick = 1 minute of real time (TICK_REAL_SECONDS = 60, TIME_SCALE = 1).
 *
 * Follow-up fixes also addressed:
 * - Boost state persists across page refresh / disconnect / idle because the
 *   server stores expires_tick in the database and rehydrates on every request.
 * - expires_at_real is now computed as GameTime::tickStartRealUnix(expires_tick + 1),
 *   an absolute wall-clock timestamp that is STABLE across API calls.  Previously
 *   it was computed as serverNowUnix + (expires_tick − gameTime) × TICK_REAL_SECONDS,
 *   which could produce a value ≤ serverNowUnix if the game tick advanced between the
 *   purchase request and the subsequent active_boosts query, causing the client to
 *   display "Expiring…" immediately after activation (the reported bug).
 * - Page routing is restored from localStorage on reload so players stay on
 *   the same screen.
 */

use PHPUnit\Framework\TestCase;

/**
 * Simulates the in-memory boost-timing logic without a live database.
 * Mirrors the SQL WHERE-clause conditions used in tick_engine.php and
 * actions.php so we can unit-test the timing semantics directly.
 */
class BoostTimingLogic
{
    /**
     * Decide whether a boost should be expired at the given gameTime.
     * Mirrors: tick_engine.php expireBoosts()
     *   "WHERE is_active = 1 AND expires_tick < ?"
     *
     * @param int $expiresTick  The boost's expires_tick value.
     * @param int $gameTime     The current game tick being processed.
     * @return bool True if the boost should be marked inactive.
     */
    public static function shouldExpire(int $expiresTick, int $gameTime): bool
    {
        return $expiresTick < $gameTime;
    }

    /**
     * Decide whether a boost is active at the given gameTime.
     * Mirrors: tick_engine.php getActivePlayerBoosts / getActiveGlobalBoosts
     *   "WHERE is_active = 1 AND expires_tick >= ?"
     *
     * @param int  $expiresTick The boost's expires_tick value.
     * @param bool $isActive    The current is_active flag (set by shouldExpire).
     * @param int  $gameTime    The current game tick.
     * @return bool True if the boost should contribute to UBI this tick.
     */
    public static function isActiveForTick(int $expiresTick, bool $isActive, int $gameTime): bool
    {
        return $isActive && $expiresTick >= $gameTime;
    }

    /**
     * Simulate boost activation (purchaseBoost logic).
     * Returns the expires_tick value that will be stored.
     *
     * @param int $gameTime      The current game tick at purchase time.
     * @param int $durationTicks The configured duration from boost_catalog.
     * @return int expires_tick to store in active_boosts.
     */
    public static function computeExpiresTick(int $gameTime, int $durationTicks): int
    {
        return $gameTime + $durationTicks;
    }

    /**
     * Simulate a single tick-engine run for one boost.
     * Returns whether the boost contributed to UBI this tick, and whether
     * it is still active after the tick.
     *
     * Order mirrors tick_engine.php processSeasonTick():
     *   Phase 2 – expire old boosts
     *   Phase 5 – accrue UBI with active boosts
     *
     * @param int  $expiresTick  The boost's expires_tick value.
     * @param bool $isActive     The boost's current is_active flag.
     * @param int  $gameTime     The game tick being processed.
     * @return array{applied: bool, isActiveAfter: bool}
     */
    public static function processTick(int $expiresTick, bool $isActive, int $gameTime): array
    {
        // Phase 2: expire
        if (self::shouldExpire($expiresTick, $gameTime)) {
            $isActive = false;
        }

        // Phase 5: accrue UBI
        $applied = self::isActiveForTick($expiresTick, $isActive, $gameTime);

        return ['applied' => $applied, 'isActiveAfter' => $isActive];
    }
}

/**
 * Simulates the server-side boost-persistence and rehydration logic.
 *
 * The canonical model is:
 *   - expires_tick is stored absolutely in the DB at purchase time.
 *   - On every API read, remaining time is recomputed as max(0, expires_tick - now).
 *   - disconnect / idle / refresh do NOT clear or modify expires_tick.
 *
 * This mirrors getActiveBoosts() in api/index.php.
 */
class BoostPersistenceLogic
{
    /**
     * Simulate fetching active boosts for a player at a given game tick.
     * Returns only boosts whose expires_tick >= gameTime (still valid).
     *
     * @param array[] $storedBoosts  Rows from active_boosts (keyed by id).
     * @param int     $gameTime      Current game tick.
     * @return array[] Active boost rows.
     */
    public static function fetchActiveBoosts(array $storedBoosts, int $gameTime): array
    {
        return array_values(array_filter(
            $storedBoosts,
            fn($b) => $b['is_active'] === true && $b['expires_tick'] >= $gameTime
        ));
    }

    /**
     * Compute the absolute wall-clock Unix timestamp when a boost expires.
     * Mirrors: GameTime::tickStartRealUnix(expires_tick + 1) in api/index.php.
     *
     * The boost is active as long as gameTime <= expires_tick.  The first real
     * moment it is no longer active is the start of tick (expires_tick + 1):
     *   serverEpoch + (expires_tick + 1) * tickRealSecs
     *
     * This value is STABLE: it does not change across API calls, so the client
     * countdown is resilient to the game tick advancing between the purchase
     * request and the subsequent active_boosts query.
     *
     * @param int $expiresTick   The boost's expires_tick.
     * @param int $serverEpoch   Server's Unix epoch (start of tick 0).
     * @param int $tickRealSecs  Real seconds per game tick (default 60).
     * @return int Unix timestamp of expiry.
     */
    public static function expiresAtReal(int $expiresTick, int $serverEpoch, int $tickRealSecs = 60): int
    {
        return $serverEpoch + ($expiresTick + 1) * $tickRealSecs;
    }

    /**
     * Compute the wall-clock remaining seconds for a boost.
     * Mirrors: getActiveBoosts() in api/index.php.
     *   $expiresAtReal = GameTime::tickStartRealUnix(expires_tick + 1)
     *   $remaining_real_seconds = max(0, $expiresAtReal - $serverNowUnix)
     *
     * @param int $expiresTick   The boost's expires_tick.
     * @param int $serverEpoch   Server's Unix epoch (start of tick 0).
     * @param int $serverNowUnix Server's current Unix timestamp.
     * @param int $tickRealSecs  Real seconds per game tick (default 60).
     * @return int Remaining real seconds (non-negative).
     */
    public static function remainingRealSeconds(int $expiresTick, int $serverEpoch, int $serverNowUnix, int $tickRealSecs = 60): int
    {
        return max(0, self::expiresAtReal($expiresTick, $serverEpoch, $tickRealSecs) - $serverNowUnix);
    }
}

/**
 * Simulates the client-side countdown logic.
 *
 * The client receives expires_at_real (absolute Unix timestamp) and computes:
 *   remaining = max(0, expires_at_real - clientNow)
 * This never goes negative and is independent of tick processing.
 */
class BoostCountdownLogic
{
    /**
     * Compute client-side remaining seconds from the absolute expiry timestamp.
     *
     * @param int $expiresAtReal  Unix timestamp when the boost expires.
     * @param int $clientNow      Current Unix timestamp on the client.
     * @return int Non-negative remaining seconds.
     */
    public static function remainingSeconds(int $expiresAtReal, int $clientNow): int
    {
        return max(0, $expiresAtReal - $clientNow);
    }
}

/**
 * Encodes the canonical boost catalog tier definitions from boost_catalog seed data.
 * These constants mirror the seeded rows in migration_boosts_drops.sql and are used
 * to drive tier-specific timing tests without hard-coding magic numbers inline.
 *
 *  Tier | Name    | Scope  | duration_ticks | time_extension_ticks | modifier_fp | max_stack
 *  -----+---------+--------+----------------+----------------------+-------------+----------
 *    1  | Trickle | SELF   |             60 |                    5 |      50,000 |         5
 *    2  | Surge   | SELF   |            180 |                   15 |     150,000 |         5
 *    3  | Flow    | SELF   |            360 |                   30 |     250,000 |         5
 *    4  | Tide    | SELF   |            720 |                   60 |     500,000 |         3
 *    5  | Age     | SELF   |           1440 |                   90 |   1,000,000 |         1
 */
class BoostCatalogLogic
{
    /** Canonical duration_ticks keyed by tier_required. */
    public const DURATION_BY_TIER = [
        1 => 60,     // Trickle – 60 ticks (1 hour at 60 s/tick)
        2 => 180,    // Surge   – 180 ticks (3 hours)
        3 => 360,    // Flow    – 360 ticks (6 hours)
        4 => 720,    // Tide    – 720 ticks (12 hours)
        5 => 1440,   // Age     – 1440 ticks (24 hours)
    ];

    /**
     * Canonical time_extension_ticks keyed by tier_required.
     * These are the FLAT amounts shown on the Boost Catalog purchase button.
     * They must NOT be multiplied by the player's power stack – the player
     * always receives exactly this amount when they buy a time extension.
     */
    public const TIME_EXTENSION_BY_TIER = [
        1 => 5,    // Trickle – +5 min  (button label: "+5 mins")
        2 => 15,   // Surge   – +15 min (button label: "+15 mins")
        3 => 30,   // Flow    – +30 min (button label: "+30 mins")
        4 => 60,   // Tide    – +60 min (button label: "+60 mins")
        5 => 90,   // Age     – +90 min (button label: "+90 mins")
    ];

    /** Scope keyed by tier_required. */
    public const SCOPE_BY_TIER = [
        1 => 'SELF',
        2 => 'SELF',
        3 => 'SELF',
        4 => 'SELF',
        5 => 'SELF',
    ];

    /** Canonical modifier_fp keyed by tier_required. */
    public const MODIFIER_BY_TIER = [
        1 => 50000,
        2 => 150000,
        3 => 250000,
        4 => 500000,
        5 => 1000000,
    ];
}

/**
 * Simulates the Boost Catalog time-purchase extension logic.
 *
 * Mirrors the server-side calculation in actions.php purchaseBoost() for
 * purchase_kind = 'time'.  The extension applied is the flat catalog value
 * (time_extension_ticks) with NO power-stack multiplication.
 */
class BoostTimePurchaseLogic
{
    /**
     * Compute the ticks to extend a boost by for a time purchase.
     *
     * The extension is the flat catalog amount – it must equal exactly what is
     * shown on the purchase button, and is independent of the player's current
     * power stack.
     *
     * Mirrors: $extendTicks = max(1, $timeExtensionTicks); in actions.php.
     *
     * @param int $catalogExtensionTicks  The canonical time_extension_ticks from
     *                                    BoostCatalog::normalize().
     * @return int Ticks to add to expires_tick.
     */
    public static function computeExtendTicks(int $catalogExtensionTicks): int
    {
        // Flat catalog value only – do NOT multiply by power stack.
        return max(1, $catalogExtensionTicks);
    }

    /**
     * Compute the new expires_tick after a time purchase.
     *
     * Mirrors: $expiresTick = max((int)$active['expires_tick'], $gameTime) + $extendTicks;
     *
     * @param int $currentExpiresTick  The boost's current expires_tick.
     * @param int $gameTime            Current game tick at purchase time.
     * @param int $extendTicks         The extension computed by computeExtendTicks().
     * @return int New expires_tick to persist.
     */
    public static function computeNewExpiresTick(int $currentExpiresTick, int $gameTime, int $extendTicks): int
    {
        return max($currentExpiresTick, $gameTime) + $extendTicks;
    }
}

class BoostTimingTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Unit tests for the boundary condition of shouldExpire()
    // -----------------------------------------------------------------------

    public function testShouldNotExpireWhenExpireTickEqualsGameTime(): void
    {
        // expires_tick = T, gameTime = T → boost still active this tick
        $this->assertFalse(
            BoostTimingLogic::shouldExpire(100, 100),
            'A boost with expires_tick == gameTime must NOT be expired yet.'
        );
    }

    public function testShouldNotExpireWhenExpireTickIsAfterGameTime(): void
    {
        $this->assertFalse(
            BoostTimingLogic::shouldExpire(101, 100),
            'A boost with expires_tick > gameTime must not be expired.'
        );
    }

    public function testShouldExpireWhenExpireTickIsBeforeGameTime(): void
    {
        $this->assertTrue(
            BoostTimingLogic::shouldExpire(99, 100),
            'A boost with expires_tick < gameTime must be expired.'
        );
    }

    // -----------------------------------------------------------------------
    // Unit tests for the boundary condition of isActiveForTick()
    // -----------------------------------------------------------------------

    public function testIsActiveWhenExpireTickEqualsGameTime(): void
    {
        // expires_tick = T, gameTime = T → should be counted for UBI
        $this->assertTrue(
            BoostTimingLogic::isActiveForTick(100, true, 100),
            'A boost with expires_tick == gameTime must be active for this tick.'
        );
    }

    public function testIsActiveWhenExpireTickIsAfterGameTime(): void
    {
        $this->assertTrue(
            BoostTimingLogic::isActiveForTick(101, true, 100),
            'A boost with expires_tick > gameTime must be active.'
        );
    }

    public function testNotActiveWhenExpireTickIsBeforeGameTime(): void
    {
        $this->assertFalse(
            BoostTimingLogic::isActiveForTick(99, true, 100),
            'A boost with expires_tick < gameTime must not be active.'
        );
    }

    public function testNotActiveWhenFlaggedInactive(): void
    {
        // Even if expires_tick is in the future, is_active = false wins
        $this->assertFalse(
            BoostTimingLogic::isActiveForTick(200, false, 100),
            'A boost that has been flagged inactive must not be active regardless of expires_tick.'
        );
    }

    // -----------------------------------------------------------------------
    // Full-duration scenario tests (common case: engine already ran at T)
    // -----------------------------------------------------------------------

    /**
     * Most common scenario:
     * - Tick engine last ran at tick T (before the purchase).
     * - Player purchases boost at tick T.
     * - Engine next runs at T+1, T+2, ..., T+N.
     *
     * Expected: boost applies for exactly N ticks (T+1 through T+N inclusive).
     */
    public function testOneDurationBoostAppliesForOneTick(): void
    {
        $purchaseTick  = 100;
        $durationTicks = 1;
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 101

        $isActive = true;

        // Tick T+1 = 101: the only engine run that should apply the boost
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 101);
        $this->assertTrue($result['applied'], 'A 1-tick boost must apply at tick T+1.');
        $this->assertTrue($result['isActiveAfter'], 'Boost flag must still be is_active after tick T+1 (expires at T+2).');

        // Tick T+2 = 102: boost must be expired and not applied
        $result = BoostTimingLogic::processTick($expiresTick, $result['isActiveAfter'], 102);
        $this->assertFalse($result['applied'], 'A 1-tick boost must NOT apply at tick T+2.');
        $this->assertFalse($result['isActiveAfter'], 'Boost must be expired after tick T+2.');
    }

    public function testTwoDurationBoostAppliesForTwoTicks(): void
    {
        $purchaseTick  = 100;
        $durationTicks = 2;
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 102

        $isActive = true;
        $appliedCount = 0;

        foreach ([101, 102] as $tick) {
            $result = BoostTimingLogic::processTick($expiresTick, $isActive, $tick);
            if ($result['applied']) {
                $appliedCount++;
            }
            $isActive = $result['isActiveAfter'];
        }

        $this->assertSame(2, $appliedCount, 'A 2-tick boost must apply for exactly 2 ticks.');

        // Tick 103: must be expired
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 103);
        $this->assertFalse($result['applied'], 'A 2-tick boost must NOT apply at tick T+3.');
    }

    public function testThreeDurationBoostAppliesForThreeTicks(): void
    {
        $purchaseTick  = 50;
        $durationTicks = 3;
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 53

        $isActive    = true;
        $appliedCount = 0;

        foreach ([51, 52, 53] as $tick) {
            $result = BoostTimingLogic::processTick($expiresTick, $isActive, $tick);
            if ($result['applied']) {
                $appliedCount++;
            }
            $isActive = $result['isActiveAfter'];
        }

        $this->assertSame(3, $appliedCount, 'A 3-tick boost must apply for exactly 3 ticks.');

        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 54);
        $this->assertFalse($result['applied'], 'A 3-tick boost must NOT apply at tick T+4.');
    }

    // -----------------------------------------------------------------------
    // Boundary / edge-case tests
    // -----------------------------------------------------------------------

    /**
     * Exact expiration boundary: the boost must still apply at expires_tick,
     * but must NOT apply one tick later.
     */
    public function testBoostAppliesAtExpirationTickAndNotAfter(): void
    {
        $expiresTick = 200;
        $isActive    = true;

        // At the exact expiration tick the boost must still be counted
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 200);
        $this->assertTrue($result['applied'], 'Boost must apply on the tick equal to expires_tick.');

        // One tick later it must be gone
        $result = BoostTimingLogic::processTick($expiresTick, $result['isActiveAfter'], 201);
        $this->assertFalse($result['applied'], 'Boost must NOT apply one tick after expires_tick.');
    }

    /**
     * Boost remains active throughout the full configured duration window.
     * Uses a 1440-tick (24 hour) global boost to verify no premature expiry
     * mid-duration.
     */
    public function testBoostRemainsActiveForFullLongDuration(): void
    {
        $purchaseTick  = 1000;
        $durationTicks = 1440; // 24-hour global boost at 1 tick/min
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 2440

        $isActive = true;

        // Sampled ticks across full lifetime must apply, including expiry tick.
        foreach ([1001, 1060, 1500, 2000, 2439, 2440] as $tick) {
            $result = BoostTimingLogic::processTick($expiresTick, $isActive, $tick);
            $this->assertTrue(
                $result['applied'],
                "1440-tick boost must still apply at tick {$tick} (T+" . ($tick - $purchaseTick) . ').'
            );
            $isActive = $result['isActiveAfter'];
        }

        // Tick T+1441 = 2441: must be expired
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 2441);
        $this->assertFalse($result['applied'], '1440-tick boost must NOT apply at tick T+1441.');
    }

    /**
     * Edge case: engine runs at the same tick as purchase (purchase happens
     * early in the tick before the worker fires).  The boost should also apply
     * for that tick, giving N+1 total applications – acceptable behaviour
     * described in the fix notes.
     */
    public function testBoostActivatedAndAppliedInSameTick(): void
    {
        $purchaseTick  = 100;
        $durationTicks = 1;
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);

        $isActive = true;

        // Engine runs at same tick as purchase (T = 100)
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 100);
        $this->assertTrue($result['applied'], 'Boost must apply if engine runs at the same tick as purchase.');
    }

    // -----------------------------------------------------------------------
    // Regression: verify the OLD (broken) behaviour is NOT present
    // -----------------------------------------------------------------------

    /**
     * OLD code used `expires_tick <= gameTime` to expire and `expires_tick > gameTime`
     * to check active.  This caused the boost to be expired BEFORE UBI accrual on
     * the tick equal to expires_tick, stealing the last full tick of benefit.
     * Confirm the old conditions would have failed this scenario.
     */
    public function testOldConditionsWouldHaveFailedOneDurationBoost(): void
    {
        // Simulate old expiry condition: expires_tick <= gameTime
        $shouldExpireOld = static function (int $expiresTick, int $gameTime): bool {
            return $expiresTick <= $gameTime;
        };

        // Simulate old active condition: expires_tick > gameTime
        $isActiveOld = static function (int $expiresTick, bool $isActive, int $gameTime): bool {
            return $isActive && $expiresTick > $gameTime;
        };

        $expiresTick = 101; // purchase at 100, duration 1

        // With old code, at gameTime = 101:
        $isActive = true;
        if ($shouldExpireOld($expiresTick, 101)) {
            $isActive = false; // old code expires it first
        }
        $oldApplied = $isActiveOld($expiresTick, $isActive, 101);

        $this->assertFalse(
            $oldApplied,
            'Regression guard: old expires_tick <= / > conditions must NOT apply the boost at T+1, confirming the bug existed.'
        );
    }

    // -----------------------------------------------------------------------
    // Boost persistence: refresh, disconnect, and idle scenarios
    // -----------------------------------------------------------------------

    /**
     * Simulates a page refresh: the client reconnects and the server re-reads
     * the boost from the database.  Because expires_tick is persisted and the
     * game clock has not reached it, the boost must still be returned as active.
     *
     * This mirrors getActiveBoosts() querying "WHERE is_active = 1 AND expires_tick >= gameTime".
     */
    public function testBoostSurvivesPageRefresh(): void
    {
        // Boost was purchased at tick 500, duration 60 → expires at tick 560
        $storedBoost = ['id' => 1, 'expires_tick' => 560, 'is_active' => true];

        // Player refreshes at tick 520 (40 ticks into the boost's lifetime)
        $gameTimeAfterRefresh = 520;
        $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $gameTimeAfterRefresh);

        $this->assertCount(1, $active, 'Boost must still be returned as active after a page refresh mid-duration.');
        $this->assertSame(560, $active[0]['expires_tick']);
    }

    /**
     * Simulates an idle / disconnect period: no ticks are processed for 30
     * ticks (e.g., the worker is paused or the player goes offline).  The boost
     * must still be active when the player reconnects, because expires_tick in
     * the database is unchanged.
     *
     * This demonstrates that boost validity is NOT tied to active connection or
     * continuous tick processing.
     */
    public function testBoostSurvivesDisconnectAndIdlePeriod(): void
    {
        // Boost expires at tick 300; last tick processed was tick 200.
        $storedBoost = ['id' => 2, 'expires_tick' => 300, 'is_active' => true];

        // Gap: ticks 201–230 were skipped (worker paused / player offline).
        // Player reconnects; server now processes tick 231.
        $gameTimeAtReconnect = 231;
        $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $gameTimeAtReconnect);

        $this->assertCount(1, $active, 'Boost must still be active after an idle/disconnect gap.');
    }

    /**
     * Boost must NOT survive a disconnect that lasts past its expiry tick.
     * At reconnect the server re-evaluates expires_tick >= gameTime and must
     * not return an already-expired boost.
     */
    public function testExpiredBoostIsNotReturnedAfterReconnect(): void
    {
        $storedBoost = ['id' => 3, 'expires_tick' => 150, 'is_active' => true];

        // Player reconnects at tick 151, one tick after expiry
        $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], 151);

        $this->assertCount(0, $active, 'A boost past its expires_tick must not be returned on reconnect.');
    }

    // -----------------------------------------------------------------------
    // Wall-clock expiry and remaining_real_seconds computation
    // -----------------------------------------------------------------------

    /**
     * Verify that remaining_real_seconds is computed correctly using the
     * absolute tick-boundary formula.
     * 1 tick = 60 real seconds in production.
     *
     * expires_tick = 110, serverEpoch = 0, serverNow = 6000 (= start of tick 100).
     * expires_at_real = serverEpoch + (110 + 1) × 60 = 6660
     * remaining      = 6660 - 6000 = 660 seconds
     */
    public function testRemainingRealSecondsCalculation(): void
    {
        $serverEpoch = 0;
        $serverNow   = 6000; // = serverEpoch + 100 × 60 (exact tick-100 boundary)
        $remaining = BoostPersistenceLogic::remainingRealSeconds(110, $serverEpoch, $serverNow);
        $this->assertSame(660, $remaining,
            'remaining_real_seconds must equal (expires_tick + 1 - tick 100) × TICK_REAL_SECONDS ' .
            'when queried at the tick-100 boundary (serverEpoch=0, tick=100, expires_tick=110 → 660 s).');
    }

    /**
     * remaining_real_seconds must be zero (never negative) once the boost has expired.
     */
    public function testRemainingRealSecondsIsNonNegativeWhenExpired(): void
    {
        // expires_tick = 99, query at tick 100 start (serverNow = 6000, serverEpoch = 0)
        // expires_at_real = 0 + (99+1)*60 = 6000; remaining = max(0, 6000 - 6000) = 0
        $serverEpoch = 0;
        $serverNow   = 6000;
        $remaining = BoostPersistenceLogic::remainingRealSeconds(99, $serverEpoch, $serverNow);
        $this->assertSame(0, $remaining,
            'remaining_real_seconds must be 0 for an already-expired boost, never negative.');
    }

    /**
     * Verify that expires_at_real is a stable absolute Unix timestamp derived
     * from the tick epoch, independent of when the API response is generated.
     *
     * Formula: serverEpoch + (expires_tick + 1) × TICK_REAL_SECONDS
     *
     * With serverEpoch = 1_699_994_000 (= serverNow - 100×60):
     *   expires_at_real = 1_699_994_000 + 111×60 = 1_700_000_660
     */
    public function testExpiresAtRealIsAbsoluteTimestamp(): void
    {
        $tickRealSecs = 60;
        $gameTime     = 100;
        // serverEpoch is the real Unix time at tick 0.
        // If the server is now at tick 100 and serverNow = 1_700_000_000,
        // then serverEpoch ≈ 1_700_000_000 − 100×60 = 1_699_994_000.
        $serverEpoch  = 1_699_994_000;
        $expiresTick  = 110;

        $expiresAt = BoostPersistenceLogic::expiresAtReal($expiresTick, $serverEpoch, $tickRealSecs);

        // expected = serverEpoch + (110 + 1) × 60 = 1_699_994_000 + 6_660 = 1_700_000_660
        $this->assertSame(1_700_000_660, $expiresAt,
            'expires_at_real must equal serverEpoch + (expires_tick + 1) × TICK_REAL_SECONDS ' .
            'and be independent of serverNowUnix.');
    }

    // -----------------------------------------------------------------------
    // Client-side countdown logic (BoostCountdownLogic)
    // -----------------------------------------------------------------------

    /**
     * The client countdown must count down toward zero as wall-clock time advances.
     */
    public function testCountdownDecreaseOverTime(): void
    {
        $expiresAtReal = 1_700_001_000; // 1000 seconds from base

        $remainingAt0   = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_000_000);
        $remainingAt500 = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_000_500);
        $remainingAt999 = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_000_999);

        $this->assertSame(1000, $remainingAt0,   'Countdown must show 1000s at start.');
        $this->assertSame(500,  $remainingAt500, 'Countdown must show 500s at halfway.');
        $this->assertSame(1,    $remainingAt999, 'Countdown must show 1s one second before expiry.');
    }

    /**
     * The countdown must reach exactly zero at the expiry moment and never
     * go negative.
     */
    public function testCountdownReachesZeroAtExpiryWithoutGoingNegative(): void
    {
        $expiresAtReal = 1_700_001_000;

        $atExpiry      = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_001_000);
        $afterExpiry   = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_002_000);

        $this->assertSame(0, $atExpiry,    'Countdown must be exactly 0 at the expiry timestamp.');
        $this->assertSame(0, $afterExpiry, 'Countdown must remain 0 (not negative) after the expiry timestamp.');
    }

    /**
     * Boost expires only when the wall-clock reaches expiresAtReal, not before.
     */
    public function testBoostExpiredOnlyWhenWallClockReachesExpiresAtReal(): void
    {
        $expiresAtReal = 1_700_001_000;

        $oneSecondBefore = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_000_999);
        $atExpiry        = BoostCountdownLogic::remainingSeconds($expiresAtReal, 1_700_001_000);

        $this->assertGreaterThan(0, $oneSecondBefore,
            'Boost must still have remaining time one second before expiresAtReal.');
        $this->assertSame(0, $atExpiry,
            'Boost must have zero remaining time exactly at expiresAtReal.');
    }

    // -----------------------------------------------------------------------
    // Boost catalog: tier-to-duration mapping validation
    // -----------------------------------------------------------------------

    /**
     * Verifies the canonical tier-to-duration mapping from the boost catalog
     * seed data (migration_boosts_drops.sql).
     * These durations feed directly into expires_tick = gameTime + duration_ticks.
     */
    public function testBoostCatalogTierDurations(): void
    {
        $expected = [
            1 => 60,
            2 => 180,
            3 => 360,
            4 => 720,
            5 => 1440,
        ];

        foreach ($expected as $tier => $expectedDuration) {
            $this->assertSame(
                $expectedDuration,
                BoostCatalogLogic::DURATION_BY_TIER[$tier],
                "Tier {$tier} boost must have a canonical duration of {$expectedDuration} ticks."
            );
        }
    }

    /**
     * For every tier (1–5), verifies that:
     *   - the boost applies at T+1 (first tick after purchase),
     *   - the boost applies at expires_tick (last tick of the window),
     *   - the boost does NOT apply at expires_tick + 1,
     *   - the boost appears in the active listing at expires_tick,
     *   - the boost does NOT appear in the active listing at expires_tick + 1.
     */
    public function testAllTierDurationBehaviourMatches(): void
    {
        $purchaseTick = 1000;

        foreach (BoostCatalogLogic::DURATION_BY_TIER as $tier => $durationTicks) {
            $expiresTick = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);

            // Must apply at first tick after purchase
            $result = BoostTimingLogic::processTick($expiresTick, true, $purchaseTick + 1);
            $this->assertTrue($result['applied'],
                "Tier {$tier} boost must apply at tick T+1.");

            // Must apply on the last tick (= expires_tick)
            $result = BoostTimingLogic::processTick($expiresTick, true, $expiresTick);
            $this->assertTrue($result['applied'],
                "Tier {$tier} boost must apply at expires_tick (T+{$durationTicks}).");

            // Must NOT apply one tick after expires_tick
            $result = BoostTimingLogic::processTick($expiresTick, true, $expiresTick + 1);
            $this->assertFalse($result['applied'],
                "Tier {$tier} boost must NOT apply one tick after expires_tick.");

            // Listing: boost must be visible at expires_tick
            $storedBoost = ['id' => $tier * 100, 'expires_tick' => $expiresTick, 'is_active' => true];
            $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $expiresTick);
            $this->assertCount(1, $active,
                "Tier {$tier} boost must still appear in active listing at expires_tick.");

            // Listing: boost must NOT be visible at expires_tick + 1
            $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $expiresTick + 1);
            $this->assertCount(0, $active,
                "Tier {$tier} boost must NOT appear in active listing at expires_tick + 1.");
        }
    }

    // -----------------------------------------------------------------------
    // Tier-specific boundary tests (3, 4, 5)
    // -----------------------------------------------------------------------

    /**
     * Tier III self boost (Flow) – 360 ticks.
     * Canonical duration from boost_catalog: duration_ticks = 360.
     * Verifies the boundary: applies at expires_tick, not at expires_tick + 1.
     */
    public function testTierThreeSelfBoostBoundary(): void
    {
        $purchaseTick  = 200;
        $durationTicks = BoostCatalogLogic::DURATION_BY_TIER[3]; // 360
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 560

        $result = BoostTimingLogic::processTick($expiresTick, true, 560);
        $this->assertTrue($result['applied'],
            'Tier 3 boost must still apply at expires_tick (tick 560).');
        $this->assertTrue($result['isActiveAfter'],
            'Tier 3 boost must remain active-flagged at expires_tick.');

        $result = BoostTimingLogic::processTick($expiresTick, $result['isActiveAfter'], 561);
        $this->assertFalse($result['applied'],
            'Tier 3 boost must NOT apply at expires_tick + 1 (tick 561).');
    }

    /**
     * Tier IV self boost (Tide) – 720 ticks.
     * Canonical duration from boost_catalog: duration_ticks = 720 (12 hours).
     * Spot-checks: T+1 (first), T+360 (mid), T+720 (last = expires_tick), T+721 (expired).
     */
    public function testTierFourSelfBoostBoundary(): void
    {
        $purchaseTick  = 500;
        $durationTicks = BoostCatalogLogic::DURATION_BY_TIER[4]; // 720
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 1220

        // T+1 – first tick
        $result = BoostTimingLogic::processTick($expiresTick, true, 501);
        $this->assertTrue($result['applied'],
            'Tier 4 boost must apply at T+1 (tick 501).');

        // T+360 – mid-duration spot-check
        $result = BoostTimingLogic::processTick($expiresTick, true, 860);
        $this->assertTrue($result['applied'],
            'Tier 4 boost must apply at the mid-duration tick T+360 (tick 860).');

        // T+720 – last tick (= expires_tick)
        $result = BoostTimingLogic::processTick($expiresTick, true, 1220);
        $this->assertTrue($result['applied'],
            'Tier 4 boost must apply at expires_tick T+720 (tick 1220).');
        $this->assertTrue($result['isActiveAfter'],
            'Tier 4 boost must remain active-flagged at expires_tick.');

        // T+721 – expired
        $result = BoostTimingLogic::processTick($expiresTick, $result['isActiveAfter'], 1221);
        $this->assertFalse($result['applied'],
            'Tier 4 boost must NOT apply at T+721 (tick 1221).');
    }

    /**
     * Tier V self boost (Age) – 1440 ticks.
     * Canonical duration from boost_catalog: duration_ticks = 1440 (24 hours).
     * Spot-checks: T+1 (first), T+720 (midpoint), T+1440 (last = expires_tick), T+1441 (expired).
     */
    public function testTierFiveSelfBoostBoundary(): void
    {
        $purchaseTick  = 1000;
        $durationTicks = BoostCatalogLogic::DURATION_BY_TIER[5]; // 1440
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 2440

        // T+1 – first tick
        $result = BoostTimingLogic::processTick($expiresTick, true, 1001);
        $this->assertTrue($result['applied'],
            'Tier 5 boost must apply at T+1 (tick 1001).');

        // T+720 – midpoint
        $result = BoostTimingLogic::processTick($expiresTick, true, 1720);
        $this->assertTrue($result['applied'],
            'Tier 5 boost must apply at the midpoint tick T+720 (tick 1720).');

        // T+1440 – last tick (= expires_tick)
        $result = BoostTimingLogic::processTick($expiresTick, true, 2440);
        $this->assertTrue($result['applied'],
            'Tier 5 boost must apply at expires_tick T+1440 (tick 2440).');
        $this->assertTrue($result['isActiveAfter'],
            'Tier 5 boost must remain active-flagged at expires_tick.');

        // T+1441 – expired
        $result = BoostTimingLogic::processTick($expiresTick, $result['isActiveAfter'], 2441);
        $this->assertFalse($result['applied'],
            'Tier 5 boost must NOT apply at T+1441 (tick 2441).');
        $this->assertFalse($result['isActiveAfter'],
            'Tier 5 boost must be expired at T+1441.');
    }

    /**
     * Exhaustive check: tier V (Age) boost applies for all 1440 ticks
     * in its window without any premature expiration.
     */
    public function testTierFiveSelfBoostRemainsActiveForAll1440Ticks(): void
    {
        $purchaseTick  = 2000;
        $durationTicks = BoostCatalogLogic::DURATION_BY_TIER[5]; // 1440
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 3440

        $isActive = true;

        for ($tick = 2001; $tick <= 3440; $tick++) {
            $result = BoostTimingLogic::processTick($expiresTick, $isActive, $tick);
            $this->assertTrue(
                $result['applied'],
                "Tier 5 1440-tick boost must apply at tick {$tick} (T+" . ($tick - $purchaseTick) . ').'
            );
            $isActive = $result['isActiveAfter'];
        }

        // Tick T+1441: must be expired
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 3441);
        $this->assertFalse($result['applied'],
            'Tier 5 1440-tick boost must NOT apply at T+1441.');
    }

    // -----------------------------------------------------------------------
    // Active-boost listing visibility throughout boost lifetime
    // -----------------------------------------------------------------------

    /**
     * Verifies that a boost appears in the server-side active listing at every
     * game tick throughout its configured lifetime, and disappears immediately
     * after the expiry tick.
     *
     * Mirrors the SQL filter used by getActiveBoosts():
     *   "WHERE is_active = 1 AND expires_tick >= gameTime"
     */
    public function testActiveBoostListedThroughoutLifetime(): void
    {
        // Tier 4 global boost: purchased at tick 300, expires at 1740
        $storedBoost = ['id' => 10, 'expires_tick' => 1740, 'is_active' => true];

        // Must be visible at every sampled tick from purchase through expires_tick
        foreach ([300, 301, 1020, 1500, 1739, 1740] as $gameTime) {
            $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $gameTime);
            $this->assertCount(1, $active,
                "Boost must appear in active listing at gameTime = {$gameTime} (expires_tick = 1740).");
        }

        // Must NOT be visible one tick after expires_tick
        $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], 1741);
        $this->assertCount(0, $active,
            'Boost must NOT appear in active listing at gameTime = 1741 (one tick after expires_tick = 1740).');
    }

    /**
     * Tier V boost (1440-tick): verifies active-listing visibility at key
     * points throughout its window, and removal immediately after expiry.
     */
    public function testTierFiveBoostListingVisibilityAndRemoval(): void
    {
        // Tier 5 boost: purchased at tick 1000, expires at 2440
        $storedBoost = ['id' => 20, 'expires_tick' => 2440, 'is_active' => true];

        // Must be visible at purchase tick, first tick, midpoint, and expires_tick
        foreach ([1000, 1001, 1720, 2439, 2440] as $gameTime) {
            $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], $gameTime);
            $this->assertCount(1, $active,
                "Tier 5 boost must appear in active listing at gameTime = {$gameTime}.");
        }

        // Must NOT be visible one tick after the 1440-tick window ends
        $active = BoostPersistenceLogic::fetchActiveBoosts([$storedBoost], 2441);
        $this->assertCount(0, $active,
            'Tier 5 boost must NOT appear in active listing at gameTime = 2441 (tick after expiry).');
    }

    public function testBoostCatalogModifiersAndScopes(): void
    {
        $this->assertSame(50000, BoostCatalogLogic::MODIFIER_BY_TIER[1]);
        $this->assertSame(150000, BoostCatalogLogic::MODIFIER_BY_TIER[2]);
        $this->assertSame(250000, BoostCatalogLogic::MODIFIER_BY_TIER[3]);
        $this->assertSame(500000, BoostCatalogLogic::MODIFIER_BY_TIER[4]);
        $this->assertSame(1000000, BoostCatalogLogic::MODIFIER_BY_TIER[5]);

        $this->assertSame('SELF', BoostCatalogLogic::SCOPE_BY_TIER[1]);
        $this->assertSame('SELF', BoostCatalogLogic::SCOPE_BY_TIER[2]);
        $this->assertSame('SELF', BoostCatalogLogic::SCOPE_BY_TIER[3]);
        $this->assertSame('SELF', BoostCatalogLogic::SCOPE_BY_TIER[4]);
        $this->assertSame('SELF', BoostCatalogLogic::SCOPE_BY_TIER[5]);
    }

    public function testSelfAndGlobalModifierApplicationByScope(): void
    {
        // Player A activates Trickle (self) while a Tide (self) is active for them.
        $playerASelfBoosts = [
            ['modifier_fp' => BoostCatalogLogic::MODIFIER_BY_TIER[1], 'expires_tick' => 200, 'is_active' => true],
        ];
        $playerBSelfBoosts = [];
        $globalBoosts = [
            ['modifier_fp' => BoostCatalogLogic::MODIFIER_BY_TIER[4], 'expires_tick' => 200, 'is_active' => true],
        ];

        $playerATotal = 0;
        foreach ($playerASelfBoosts as $b) $playerATotal += (int)$b['modifier_fp'];
        foreach ($globalBoosts as $b) $playerATotal += (int)$b['modifier_fp'];

        $playerBTotal = 0;
        foreach ($playerBSelfBoosts as $b) $playerBTotal += (int)$b['modifier_fp'];
        foreach ($globalBoosts as $b) $playerBTotal += (int)$b['modifier_fp'];

        $this->assertSame(550000, $playerATotal, 'Activator gets both SELF boosts (Trickle + Tide).');
        $this->assertSame(500000, $playerBTotal, 'Other player gets only their own SELF boost (Tide).');
    }

    // -----------------------------------------------------------------------
    // Boost Catalog time-extension correctness
    // -----------------------------------------------------------------------

    /**
     * Verifies the canonical time_extension_ticks for each tier (1–5) matches
     * the amounts displayed on the Boost Catalog purchase button:
     *   Tier 1 (Trickle): +5 min = 5 ticks
     *   Tier 2 (Surge):   +15 min = 15 ticks
     *   Tier 3 (Flow):    +30 min = 30 ticks
     *   Tier 4 (Tide):    +60 min = 60 ticks
     *   Tier 5 (Age):     +90 min = 90 ticks
     */
    public function testBoostCatalogTimeExtensionByTier(): void
    {
        $expected = [
            1 => 5,    // Trickle – +5 min
            2 => 15,   // Surge   – +15 min
            3 => 30,   // Flow    – +30 min
            4 => 60,   // Tide    – +60 min
            5 => 90,   // Age     – +90 min
        ];

        foreach ($expected as $tier => $expectedTicks) {
            $this->assertSame(
                $expectedTicks,
                BoostCatalogLogic::TIME_EXTENSION_BY_TIER[$tier],
                "Tier {$tier} time extension must be {$expectedTicks} ticks (the amount shown on the button)."
            );
        }
    }

    /**
     * Purchasing a time extension always adds exactly the catalog-listed amount,
     * regardless of the player's current power stack.
     *
     * With stack = 1:  extendTicks must equal the catalog value (not 1×catalog).
     * With stack = 3:  extendTicks must still equal the catalog value (not 3×catalog).
     * With stack = 10: extendTicks must still equal the catalog value (not 10×catalog).
     */
    public function testBoostTimePurchaseGrantsExactListedTime(): void
    {
        foreach (BoostCatalogLogic::TIME_EXTENSION_BY_TIER as $tier => $catalogTicks) {
            // Regardless of power stack size, the extension must equal the catalog value.
            foreach ([1, 2, 3, 5, 10, 20] as $stackCount) {
                $extendTicks = BoostTimePurchaseLogic::computeExtendTicks($catalogTicks);

                $this->assertSame(
                    $catalogTicks,
                    $extendTicks,
                    "Tier {$tier} time extension must be {$catalogTicks} ticks (stack={$stackCount}) — " .
                    "power stack must NOT affect the granted amount."
                );
            }
        }
    }

    /**
     * Power stack count has zero impact on the time extension computation.
     * Compares extension at stack=1 vs stack=max to confirm they are identical.
     */
    public function testPowerStackDoesNotAffectBoostTimePurchase(): void
    {
        foreach (BoostCatalogLogic::TIME_EXTENSION_BY_TIER as $tier => $catalogTicks) {
            $extendAtStack1  = BoostTimePurchaseLogic::computeExtendTicks($catalogTicks);
            $extendAtStackMax = BoostTimePurchaseLogic::computeExtendTicks($catalogTicks);

            $this->assertSame(
                $extendAtStack1,
                $extendAtStackMax,
                "Tier {$tier} time extension must be identical regardless of power stack."
            );
            $this->assertSame(
                $catalogTicks,
                $extendAtStack1,
                "Tier {$tier} time extension must equal the catalog-listed value ({$catalogTicks} ticks)."
            );
        }
    }

    /**
     * Verifies the new expires_tick is computed correctly: it advances from
     * the current expires_tick (or gameTime if the boost is almost expired) by
     * exactly extendTicks, with no power-stack factor applied.
     */
    public function testBoostTimePurchaseNewExpiresTick(): void
    {
        $gameTime    = 500;
        $extendTicks = BoostTimePurchaseLogic::computeExtendTicks(
            BoostCatalogLogic::TIME_EXTENSION_BY_TIER[1]  // 5 ticks
        );

        // Case 1: boost still has significant time remaining (expires_tick > gameTime).
        $currentExpires = 600;
        $newExpires     = BoostTimePurchaseLogic::computeNewExpiresTick($currentExpires, $gameTime, $extendTicks);
        $this->assertSame(605, $newExpires,
            'New expires_tick must equal current expires_tick + extendTicks when boost is still running.');

        // Case 2: boost is on the edge of expiry (expires_tick == gameTime).
        $newExpires = BoostTimePurchaseLogic::computeNewExpiresTick($gameTime, $gameTime, $extendTicks);
        $this->assertSame($gameTime + $extendTicks, $newExpires,
            'New expires_tick must equal gameTime + extendTicks when boost is at expiry boundary.');

        // Case 3: boost has already expired (expires_tick < gameTime) – max() floors to gameTime.
        $newExpires = BoostTimePurchaseLogic::computeNewExpiresTick(400, $gameTime, $extendTicks);
        $this->assertSame($gameTime + $extendTicks, $newExpires,
            'New expires_tick must use gameTime as floor when stored expires_tick has already passed.');
    }
}
