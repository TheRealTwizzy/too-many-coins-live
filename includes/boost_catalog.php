<?php
/**
 * Canonical boost catalog definitions.
 *
 * Source of truth for boost lifecycle values (scope, duration, modifier, names)
 * used by activation and listing paths.
 */
require_once __DIR__ . '/config.php';

class BoostCatalog
{
    /**
     * Canonical boost definitions keyed by tier.
     * Durations are expressed in real seconds, then converted to ticks via
     * ticks_from_real_seconds() so behavior remains correct if tick cadence changes.
     */
    private const DEFINITIONS = [
        1 => [
            'name' => 'Trickle',
            'scope' => 'SELF',
            'duration_seconds' => 12 * 60 * 60,
            'modifier_fp' => 100000,
            'max_stack' => 5,
            'icon' => 'trickle',
            'sigil_cost' => 1,
        ],
        2 => [
            'name' => 'Surge',
            'scope' => 'SELF',
            'duration_seconds' => 36 * 60 * 60,
            'modifier_fp' => 150000,
            'max_stack' => 5,
            'icon' => 'surge',
            'sigil_cost' => 1,
        ],
        3 => [
            'name' => 'Flow',
            'scope' => 'SELF',
            'duration_seconds' => 72 * 60 * 60,
            'modifier_fp' => 250000,
            'max_stack' => 2,
            'icon' => 'flow',
            'sigil_cost' => 1,
        ],
        4 => [
            'name' => 'Tide',
            'scope' => 'SELF',
            'duration_seconds' => 144 * 60 * 60,
            'modifier_fp' => 500000,
            'max_stack' => 1,
            'icon' => 'tide',
            'sigil_cost' => 1,
        ],
        5 => [
            'name' => 'Age',
            'scope' => 'SELF',
            'duration_seconds' => 288 * 60 * 60,
            'modifier_fp' => 1000000,
            'max_stack' => 1,
            'icon' => 'age',
            'sigil_cost' => 1,
        ],
    ];

    public static function normalize(array $boost): array
    {
        $tier = (int)($boost['tier_required'] ?? 0);
        if (!isset(self::DEFINITIONS[$tier])) {
            return $boost;
        }

        $canonical = self::DEFINITIONS[$tier];
        $boost['name'] = $canonical['name'];
        $boost['description'] = '';
        $boost['scope'] = $canonical['scope'];
        $boost['duration_ticks'] = ticks_from_real_seconds($canonical['duration_seconds']);
        $boost['modifier_fp'] = $canonical['modifier_fp'];
        $boost['max_stack'] = $canonical['max_stack'];
        $boost['icon'] = $canonical['icon'];
        $boost['sigil_cost'] = $canonical['sigil_cost'];

        return $boost;
    }
}

