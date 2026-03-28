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
            'description' => 'Increases your UBI by 25% for 15 minutes.',
            'scope' => 'SELF',
            'duration_seconds' => 15 * 60,
            'modifier_fp' => 250000,
            'max_stack' => 3,
            'icon' => 'trickle',
            'sigil_cost' => 1,
        ],
        2 => [
            'name' => 'Surge',
            'description' => 'Increases your UBI by 50% for 30 minutes.',
            'scope' => 'SELF',
            'duration_seconds' => 30 * 60,
            'modifier_fp' => 500000,
            'max_stack' => 2,
            'icon' => 'surge',
            'sigil_cost' => 1,
        ],
        3 => [
            'name' => 'Flow',
            'description' => 'Increases your UBI by 75% for 1 hour.',
            'scope' => 'SELF',
            'duration_seconds' => 60 * 60,
            'modifier_fp' => 750000,
            'max_stack' => 1,
            'icon' => 'flow',
            'sigil_cost' => 1,
        ],
        4 => [
            'name' => 'Tide',
            'description' => 'Increases UBI by 15% for all players for 24 hours.',
            'scope' => 'GLOBAL',
            'duration_seconds' => 24 * 60 * 60,
            'modifier_fp' => 150000,
            'max_stack' => 1,
            'icon' => 'tide',
            'sigil_cost' => 1,
        ],
        5 => [
            'name' => 'Age',
            'description' => 'Increases UBI by 30% for all players for 48 hours.',
            'scope' => 'GLOBAL',
            'duration_seconds' => 48 * 60 * 60,
            'modifier_fp' => 300000,
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
        $boost['description'] = $canonical['description'];
        $boost['scope'] = $canonical['scope'];
        $boost['duration_ticks'] = ticks_from_real_seconds($canonical['duration_seconds']);
        $boost['modifier_fp'] = $canonical['modifier_fp'];
        $boost['max_stack'] = $canonical['max_stack'];
        $boost['icon'] = $canonical['icon'];
        $boost['sigil_cost'] = $canonical['sigil_cost'];

        return $boost;
    }
}

