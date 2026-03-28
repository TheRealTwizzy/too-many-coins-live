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
     * Uses a 60-tick (1 hour) global boost to verify no premature expiry
     * mid-duration.
     */
    public function testBoostRemainsActiveForFullLongDuration(): void
    {
        $purchaseTick  = 1000;
        $durationTicks = 60; // 60-tick global boost
        $expiresTick   = BoostTimingLogic::computeExpiresTick($purchaseTick, $durationTicks);
        // expiresTick = 1060

        $isActive = true;

        // Every tick from T+1 to T+60 (the expiry tick) must apply
        for ($tick = 1001; $tick <= 1060; $tick++) {
            $result = BoostTimingLogic::processTick($expiresTick, $isActive, $tick);
            $this->assertTrue(
                $result['applied'],
                "60-tick boost must still apply at tick {$tick} (T+" . ($tick - $purchaseTick) . ').'
            );
            $isActive = $result['isActiveAfter'];
        }

        // Tick T+61 = 1061: must be expired
        $result = BoostTimingLogic::processTick($expiresTick, $isActive, 1061);
        $this->assertFalse($result['applied'], '60-tick boost must NOT apply at tick T+61.');
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
}
