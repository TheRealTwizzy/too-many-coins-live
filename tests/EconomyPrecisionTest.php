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
        $basePercent = 100 / SIGIL_DROP_RATE;
        $tierOneConditional = SIGIL_TIER_ODDS[1] / FP_SCALE;
        $effectivePercent = $basePercent * $tierOneConditional;

        $this->assertEqualsWithDelta(0.093333, $effectivePercent, 0.000001);
    }
}
