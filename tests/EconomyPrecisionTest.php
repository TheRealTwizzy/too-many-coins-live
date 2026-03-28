<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

class EconomyPrecisionTest extends TestCase
{
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
