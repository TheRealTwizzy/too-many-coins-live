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

    public function testEffectiveSigilTierChanceMatchesConfigMath(): void
    {
        $basePercent = 100 / Economy::sigilDropRateForPower(0);
        $tierOneConditional = SIGIL_TIER_ODDS[1] / FP_SCALE;
        $effectivePercent = $basePercent * $tierOneConditional;

        $this->assertEqualsWithDelta(0.25, $effectivePercent, 0.000001);
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
            ['tier' => 1, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 5]]],
            ['tier' => 2, 'supply' => 250, 'cost_table' => [['remaining' => 1, 'cost' => 20]]],
            ['tier' => 3, 'supply' => 100, 'cost_table' => [['remaining' => 1, 'cost' => 50]]],
        ]);

        $this->assertSame(5, Economy::calculateVaultCost($vaultConfig, 1, 500));
        $this->assertSame(20, Economy::calculateVaultCost($vaultConfig, 2, 250));
        $this->assertSame(50, Economy::calculateVaultCost($vaultConfig, 3, 100));
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
}
