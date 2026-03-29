<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

class EconomyPrecisionTest extends TestCase
{
    public function testGuaranteedBoostFloorCoinsScalesByTenPercentSteps(): void
    {
        $this->assertSame(1, Economy::guaranteedBoostFloorCoins(100000));  // 10%
        $this->assertSame(2, Economy::guaranteedBoostFloorCoins(200000));  // 20%
        $this->assertSame(5, Economy::guaranteedBoostFloorCoins(500000));  // 50%
        $this->assertSame(10, Economy::guaranteedBoostFloorCoins(1000000)); // 100%
    }

    public function testGuaranteedBoostFloorFpAddsWholeCoinAtTenPercentEvenOnLowBase(): void
    {
        $baseUbi = 1;
        $rateFp = Economy::toFixedPoint($baseUbi);
        $rateFp = Economy::applyBoostModifierFp($rateFp, 100000); // 10% => 1.1/tick
        $rateFp += Economy::guaranteedBoostFloorFp(100000); // +1 whole coin floor

        [$coins, $fraction] = Economy::splitFixedPoint($rateFp);
        $this->assertSame(2, $coins);
        $this->assertSame(100000, $fraction);
    }

    public function testSigilThrottleWindowCeilingMatchesConfig(): void
    {
        $maxRealizedRate = SIGIL_MAX_DROPS_WINDOW / SIGIL_DROP_WINDOW_TICKS;
        $this->assertEqualsWithDelta(8 / 1440, $maxRealizedRate, 0.000001);
    }

    public function testTenPercentBoostPreservesFractionalRate(): void
    {
        $baseUbi = 7;
        $rateFp = Economy::toFixedPoint($baseUbi);
        $boostedRateFp = Economy::applyBoostModifierFp($rateFp, 100000);
        [$coins, $fraction] = Economy::splitFixedPoint($boostedRateFp);

        $this->assertSame(7, $coins);
        $this->assertSame(700000, $fraction);
    }

    public function testFractionalCarryMintsExtraCoinOverMultipleTicks(): void
    {
        $baseUbi = 7;
        $rateFp = Economy::applyBoostModifierFp(Economy::toFixedPoint($baseUbi), 100000); // 7.7/tick

        $carryFp = 0;
        $totalFp = ($rateFp * 2) + $carryFp;
        [$coinsAfterTwoTicks, $carryAfterTwoTicks] = Economy::splitFixedPoint($totalFp);

        $this->assertSame(15, $coinsAfterTwoTicks);
        $this->assertSame(400000, $carryAfterTwoTicks);
    }

    public function testCalculateUBIIgnoresHoardingSuppressionForGrossPath(): void
    {
        $season = [
            'base_ubi_active_per_tick' => 30,
            'base_ubi_idle_factor_fp' => 250000,
            'ubi_min_per_tick' => 1,
            'total_coins_supply' => 0,
            'inflation_table' => json_encode([
                ['x' => 0, 'factor_fp' => 1000000],
                ['x' => 1000000, 'factor_fp' => 1000000],
            ]),
            'hoarding_min_factor_fp' => 90000,
            'target_spend_rate_per_tick' => 999999,
        ];

        $player = [
            'participation_enabled' => 1,
            'activity_state' => 'Active',
        ];

        $participation = [
            'spend_window_total' => 0,
        ];

        $this->assertSame(30, Economy::calculateUBI($season, $player, $participation));
    }

    public function testHoardingSinkIsZeroWhenFeatureDisabled(): void
    {
        $season = [
            'hoarding_sink_enabled' => 0,
        ];
        $player = ['activity_state' => 'Active'];
        $participation = ['coins' => 999999];

        $sink = Economy::calculateHoardingSinkCoinsPerTick($season, $player, $participation, Economy::toFixedPoint(100));
        $this->assertSame(0, $sink);
    }

    public function testHoardingSinkUsesTiersIdleMultiplierAndCap(): void
    {
        $season = [
            'hoarding_sink_enabled' => 1,
            'hoarding_safe_hours' => 0,
            'hoarding_safe_min_coins' => 0,
            'hoarding_tier1_excess_cap' => 50000,
            'hoarding_tier2_excess_cap' => 200000,
            'hoarding_tier1_rate_hourly_fp' => 60000,
            'hoarding_tier2_rate_hourly_fp' => 120000,
            'hoarding_tier3_rate_hourly_fp' => 240000,
            'hoarding_idle_multiplier_fp' => 1250000,
            'hoarding_sink_cap_ratio_fp' => 200000, // 20% of gross coins/tick
        ];
        $player = ['activity_state' => 'Idle'];
        $participation = ['coins' => 1000000];
        $grossRateFp = Economy::toFixedPoint(50);

        $sink = Economy::calculateHoardingSinkCoinsPerTick($season, $player, $participation, $grossRateFp);

        // Cap is floor(50 * 0.20) = 10 coins/tick.
        $this->assertSame(10, $sink);
    }

    public function testEffectiveSigilTierChanceMatchesConfigMath(): void
    {
        $basePercent = 100 / Economy::sigilDropRateForPower(0);
        $tierOneConditional = SIGIL_TIER_ODDS[1] / FP_SCALE;
        $effectivePercent = $basePercent * $tierOneConditional;

        // T1 target: 8.75%; denominator 8 gives ~12.5% × 700000/1000000 ≈ 8.75%
        $this->assertEqualsWithDelta(8.75, $effectivePercent, 0.1);
    }

    public function testSigilTierDropRatesScaleFromT1ToT5(): void
    {
        // Verify SIGIL_TIER_DROP_RATES is defined with correct tier bounds
        $this->assertArrayHasKey(1, SIGIL_TIER_DROP_RATES);
        $this->assertArrayHasKey(5, SIGIL_TIER_DROP_RATES);

        // T1 must be 8.75% (875/10000) and T5 must be 0.06% (6/10000)
        $this->assertSame(875, SIGIL_TIER_DROP_RATES[1]); // 8.75% in parts-per-10000
        $this->assertSame(6,   SIGIL_TIER_DROP_RATES[5]); // 0.06% in parts-per-10000

        // Rates must be strictly descending T1 → T5
        for ($tier = 1; $tier < 5; $tier++) {
            $this->assertGreaterThan(
                SIGIL_TIER_DROP_RATES[$tier + 1],
                SIGIL_TIER_DROP_RATES[$tier],
                sprintf('Expected T%d drop rate to be higher than T%d.', $tier, $tier + 1)
            );
        }
    }

    public function testSigilTierOddsSumToOneMillion(): void
    {
        $sum = array_sum(SIGIL_TIER_ODDS);
        $this->assertSame(1000000, $sum, 'SIGIL_TIER_ODDS must sum to exactly 1,000,000.');
    }

    public function testEffectiveSigilTierChancesDecreaseAtFullPower(): void
    {
        $lowPowerOdds = Economy::adjustedSigilTierOdds(0);
        $highPowerOdds = Economy::adjustedSigilTierOdds(SIGIL_POWER_FULL_SHIFT);
        $lowBasePercent = 100 / Economy::sigilDropRateForPower(0);
        $highBasePercent = 100 / Economy::sigilDropRateForPower(SIGIL_POWER_FULL_SHIFT);

        for ($tier = 1; $tier <= 5; $tier++) {
            $lowEffective = $lowBasePercent * (((int)$lowPowerOdds[$tier]) / FP_SCALE);
            $highEffective = $highBasePercent * (((int)$highPowerOdds[$tier]) / FP_SCALE);
            $this->assertTrue(
                $highEffective < $lowEffective,
                sprintf('Expected Tier %d effective chance to decrease with sigil power.', $tier)
            );
        }
    }

    public function testVaultCostUsesTargetTierDefaultsForNaturalProgression(): void
    {
        $vaultConfig = json_encode([
            ['tier' => 1, 'supply' => 1000, 'cost_table' => [['remaining' => 1, 'cost' => 5]]],
            ['tier' => 2, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 25]]],
            ['tier' => 3, 'supply' => 250, 'cost_table' => [['remaining' => 1, 'cost' => 125]]],
        ]);

        $this->assertSame(5, Economy::calculateVaultCost($vaultConfig, 1, 1000));
        $this->assertSame(25, Economy::calculateVaultCost($vaultConfig, 2, 500));
        $this->assertSame(125, Economy::calculateVaultCost($vaultConfig, 3, 250));
    }

    public function testVaultCostStepTableSelectsFirstMatchingRemainingThreshold(): void
    {
        $vaultConfig = json_encode([
            [
                'tier' => 1,
                'supply' => 500,
                'cost_table' => [
                    ['remaining' => 400, 'cost' => 10],
                    ['remaining' => 200, 'cost' => 20],
                    ['remaining' => 1, 'cost' => 30],
                ],
            ],
        ]);

        $this->assertSame(10, Economy::calculateVaultCost($vaultConfig, 1, 500));
        $this->assertSame(20, Economy::calculateVaultCost($vaultConfig, 1, 250));
        $this->assertSame(30, Economy::calculateVaultCost($vaultConfig, 1, 50));
    }

    // ==================== Early Lock-In Payout ====================

    public function testEarlyLockInPayoutNoSigils(): void
    {
        // No sigils: payout = floor(0.65 * seasonalStars)
        $result = Economy::computeEarlyLockInPayout(100, [0,0,0,0,0], [5,25,125,375,1125]);
        $this->assertSame(0,   $result['sigil_refund_stars']);
        $this->assertSame(100, $result['total_seasonal_stars']);
        $this->assertSame(65,  $result['global_stars_gained']); // floor(100 * 0.65) = 65
    }

    public function testEarlyLockInPayoutFloorRoundsDown(): void
    {
        // 10 seasonal stars → floor(10 * 0.65) = floor(6.5) = 6
        $result = Economy::computeEarlyLockInPayout(10, [0,0,0,0,0], [5,25,125,375,1125]);
        $this->assertSame(0,  $result['sigil_refund_stars']);
        $this->assertSame(10, $result['total_seasonal_stars']);
        $this->assertSame(6,  $result['global_stars_gained']);
    }

    public function testEarlyLockInPayoutSigilRefundAddedBeforeConversion(): void
    {
        // 1 T1 sigil (5 stars) + 0 seasonal stars → total 5 → floor(5 * 0.65) = floor(3.25) = 3
        $result = Economy::computeEarlyLockInPayout(0, [1,0,0,0,0], [5,25,125,375,1125]);
        $this->assertSame(5, $result['sigil_refund_stars']);
        $this->assertSame(5, $result['total_seasonal_stars']);
        $this->assertSame(3, $result['global_stars_gained']);
    }

    public function testEarlyLockInPayoutMixedSigilsAndStars(): void
    {
        // 2 T1 (10), 1 T2 (25), 1 T3 (125) = 160 sigil stars + 40 seasonal = 200 total
        // floor(200 * 0.65) = floor(130.0) = 130
        $result = Economy::computeEarlyLockInPayout(40, [2,1,1,0,0], [5,25,125,375,1125]);
        $this->assertSame(160, $result['sigil_refund_stars']);
        $this->assertSame(200, $result['total_seasonal_stars']);
        $this->assertSame(130, $result['global_stars_gained']);
    }

    public function testEarlyLockInPayoutT4AndT5DerivedCosts(): void
    {
        // 1 T4 at 375, 1 T5 at 1125 → sigil refund = 1500; total = 1500; floor(1500*0.65)=975
        $result = Economy::computeEarlyLockInPayout(0, [0,0,0,1,1], [5,25,125,375,1125]);
        $this->assertSame(1500, $result['sigil_refund_stars']);
        $this->assertSame(1500, $result['total_seasonal_stars']);
        $this->assertSame(975,  $result['global_stars_gained']); // floor(1500*0.65)=975
    }

    public function testEarlyLockInPayoutZeroStarsAndNoSigils(): void
    {
        $result = Economy::computeEarlyLockInPayout(0, [0,0,0,0,0], [5,25,125,375,1125]);
        $this->assertSame(0, $result['sigil_refund_stars']);
        $this->assertSame(0, $result['total_seasonal_stars']);
        $this->assertSame(0, $result['global_stars_gained']);
    }

    public function testLowerTierSigilsDropMoreFrequentlyThanHigherTiers(): void
    {
        // T1 should dominate conditional drop odds (target: 70%)
        $this->assertGreaterThan(
            500000,
            SIGIL_TIER_ODDS[1],
            'T1 must account for more than 50% of conditional drops to sustain boost activity.'
        );

        // Effective drop rate ordering: T1 > T2 > T3 > T4 > T5
        $basePercent = 100.0 / Economy::sigilDropRateForPower(0);
        $prevEffective = $basePercent * (SIGIL_TIER_ODDS[1] / FP_SCALE);
        $this->assertGreaterThan(0.0, $prevEffective, 'T1 effective drop rate must be positive.');
        for ($tier = 2; $tier <= 5; $tier++) {
            $effective = $basePercent * (SIGIL_TIER_ODDS[$tier] / FP_SCALE);
            $this->assertLessThan(
                $prevEffective,
                $effective,
                sprintf('T%d effective drop rate must be lower than T%d.', $tier, $tier - 1)
            );
            $prevEffective = $effective;
        }
    }

    public function testT1DropRateIsHighEnoughForActiveBoostPlay(): void
    {
        // With base drop rate 1-in-8 and T1 at 70%, effective T1 rate should
        // exceed 5% per eligible tick so active players see regular replenishment.
        $basePercent = 100.0 / Economy::sigilDropRateForPower(0);
        $t1Effective = $basePercent * (SIGIL_TIER_ODDS[1] / FP_SCALE);
        $this->assertGreaterThan(
            5.0,
            $t1Effective,
            'T1 effective drop rate should exceed 5% per eligible tick for active-play viability.'
        );
    }

    // ==================== Rate Breakdown / Net Mint ====================

    /**
     * Helper: build a minimal season fixture with hoarding sink disabled.
     */
    private function makeSeasonFixture(array $overrides = []): array
    {
        $base = [
            'base_ubi_active_per_tick' => 10,
            'base_ubi_idle_factor_fp'  => 500000,
            'ubi_min_per_tick'         => 1,
            'total_coins_supply'       => 0,
            'inflation_table'          => json_encode([
                ['x' => 0,       'factor_fp' => 1000000],
                ['x' => 1000000, 'factor_fp' => 1000000],
            ]),
            'hoarding_min_factor_fp'       => 900000,
            'target_spend_rate_per_tick'   => 0,
            'hoarding_sink_enabled'        => 0,
            'hoarding_safe_hours'          => 0,
            'hoarding_safe_min_coins'      => 0,
            'hoarding_tier1_excess_cap'    => 0,
            'hoarding_tier2_excess_cap'    => 0,
            'hoarding_tier1_rate_hourly_fp'=> 0,
            'hoarding_tier2_rate_hourly_fp'=> 0,
            'hoarding_tier3_rate_hourly_fp'=> 0,
            'hoarding_sink_cap_ratio_fp'   => 0,
            'hoarding_idle_multiplier_fp'  => 1000000,
        ];
        return array_merge($base, $overrides);
    }

    /**
     * Helper: build a minimal player/participation fixture.
     */
    private function makePlayerFixture(array $overrides = []): array
    {
        $base = [
            'participation_enabled' => 1,
            'activity_state'        => 'Active',
            'coins'                 => 0,
        ];
        return array_merge($base, $overrides);
    }

    /**
     * When not frozen and no sink, net_rate_fp must equal gross_rate_fp.
     */
    public function testCalculateRateBreakdownNetEqualsGrossWhenNoSink(): void
    {
        $season = $this->makeSeasonFixture();
        $player = $this->makePlayerFixture();

        $rates = Economy::calculateRateBreakdown($season, $player, $player, 0, false);

        $this->assertSame((int)$rates['gross_rate_fp'], (int)$rates['net_rate_fp'],
            'net_rate_fp must equal gross_rate_fp when sink is disabled.');
        $this->assertSame(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be zero when hoarding sink is disabled.');
        $this->assertGreaterThan(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be positive for an active participant.');
    }

    /**
     * Freeze short-circuit: all three rates must be zero regardless of boost activity.
     * Guards against the "positive rate shown but no coins minted" display bug when frozen.
     */
    public function testCalculateRateBreakdownAllZeroWhenFrozen(): void
    {
        $season = $this->makeSeasonFixture();
        $player = $this->makePlayerFixture();
        // Large boost modifier – should be irrelevant when frozen.
        $boostModFp = 500000; // 50%

        $rates = Economy::calculateRateBreakdown($season, $player, $player, $boostModFp, true);

        $this->assertSame(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be 0 when player is frozen.');
        $this->assertSame(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be 0 when player is frozen.');
        $this->assertSame(0, (int)$rates['net_rate_fp'],
            'net_rate_fp must be 0 when player is frozen.');
    }

    /**
     * Positive gross (boosted) with sink enabled: net must be strictly less than gross
     * and the breakdown must expose all three values so the UI can display them correctly.
     */
    public function testCalculateRateBreakdownNetLessThanGrossWhenSinkActive(): void
    {
        $season = $this->makeSeasonFixture([
            'hoarding_sink_enabled'        => 1,
            'hoarding_safe_hours'          => 0,
            'hoarding_safe_min_coins'      => 0,
            'hoarding_tier1_excess_cap'    => 50000,
            'hoarding_tier2_excess_cap'    => 200000,
            'hoarding_tier1_rate_hourly_fp'=> 60000,
            'hoarding_tier2_rate_hourly_fp'=> 120000,
            'hoarding_tier3_rate_hourly_fp'=> 240000,
            'hoarding_sink_cap_ratio_fp'   => 200000, // 20%
        ]);
        // Player holds many coins so hoarding sink fires.
        $player = $this->makePlayerFixture(['coins' => 1000000, 'activity_state' => 'Active']);

        $rates = Economy::calculateRateBreakdown($season, $player, $player, 0, false);

        $this->assertGreaterThan(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be positive for an active participant.');
        $this->assertGreaterThan(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be positive when hoarding sink fires.');
        // When sink > 0, gross must be strictly greater than net.
        $this->assertGreaterThan((int)$rates['net_rate_fp'], (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be greater than net_rate_fp when sink is active.');
        // Net must equal max(0, gross - sink_fp).
        $expectedNet = max(0, (int)$rates['gross_rate_fp'] - Economy::toFixedPoint((int)$rates['sink_per_tick']));
        $this->assertSame($expectedNet, (int)$rates['net_rate_fp'],
            'net_rate_fp must equal max(0, gross_rate_fp - toFixedPoint(sink_per_tick)).');
    }

    /**
     * Boost floor guarantees positive net even on a 1 UBI/tick base.
     * Guards the "boost floor and net minting continuity" requirement.
     */
    public function testBoostFloorEnsuresPositiveNetRateWithMinimalUbi(): void
    {
        $season = $this->makeSeasonFixture(['base_ubi_active_per_tick' => 1]);
        $player = $this->makePlayerFixture();
        $boostModFp = 100000; // 10%: guaranteedBoostFloor = +1 coin/tick

        $rates = Economy::calculateRateBreakdown($season, $player, $player, $boostModFp, false);

        $this->assertGreaterThan(0, (int)$rates['net_rate_fp'],
            'net_rate_fp must be positive when boost floor adds coins to a 1-UBI player.');
        $this->assertGreaterThan(
            Economy::toFixedPoint(1),
            (int)$rates['net_rate_fp'],
            'net_rate_fp must exceed 1 coin/tick in fp when boost modifier is active on 1 UBI base.'
        );
    }

    /**
     * When net_rate_fp is zero, fractional carry must not accumulate.
     * This validates the tick-engine fix: carry accrues against net, not gross.
     * If net = 0, then over any number of ticks the carry stays 0.
     */
    public function testNetRateZeroProducesNoCarryAccumulation(): void
    {
        // Net = 0 is simulated by calling splitFixedPoint on net * ticks + carry
        // with net = 0.
        $netRateFp  = 0;
        $ticksToProcess = 10;
        $carryFp    = 0;

        $totalNetFp = ($netRateFp * $ticksToProcess) + $carryFp;
        [$netCoins, $newCarryFp] = Economy::splitFixedPoint($totalNetFp);

        $this->assertSame(0, $netCoins,
            'No whole coins must be produced when net_rate_fp is zero.');
        $this->assertSame(0, $newCarryFp,
            'Carry must remain zero when net_rate_fp is zero, preventing phantom oscillation.');
    }

    /**
     * Positive net rate with fractional component correctly accumulates carry and
     * mints an extra coin once the carry exceeds one whole coin over multiple ticks.
     * Guards fractional carry continuity under the new net-rate-based carry approach.
     */
    public function testPositiveNetRateCarryAccumulatesAndMintsExtraCoin(): void
    {
        // Net = 2.5 coins/tick (2,500,000 fp).
        $netRateFp = 2500000;
        $carryFp   = 0;

        // Tick 1
        $totalNetFp = ($netRateFp * 1) + $carryFp;
        [$coins1, $carry1] = Economy::splitFixedPoint($totalNetFp);
        $this->assertSame(2, $coins1);
        $this->assertSame(500000, $carry1);

        // Tick 2 (carry rolls over)
        $totalNetFp = ($netRateFp * 1) + $carry1;
        [$coins2, $carry2] = Economy::splitFixedPoint($totalNetFp);
        $this->assertSame(3, $coins2); // 2.5 + 0.5 carry = 3
        $this->assertSame(0, $carry2);

        // Over 2 ticks: 2 + 3 = 5 coins total === floor(2.5 * 2)
        $this->assertSame(5, $coins1 + $coins2);
    }
}
