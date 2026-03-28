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
            'duration_seconds' => 1 * 60 * 60,
            'time_extension_seconds' => 15 * 60,
            'modifier_fp' => 100000,
            'max_stack' => 5,
            'icon' => 'trickle',
            'sigil_cost' => 1,
        ],
        2 => [
            'name' => 'Surge',
            'scope' => 'SELF',
            'duration_seconds' => 3 * 60 * 60,
            'time_extension_seconds' => 30 * 60,
            'modifier_fp' => 150000,
            'max_stack' => 5,
            'icon' => 'surge',
            'sigil_cost' => 1,
        ],
        3 => [
            'name' => 'Flow',
            'scope' => 'SELF',
            'duration_seconds' => 6 * 60 * 60,
            'time_extension_seconds' => 45 * 60,
            'modifier_fp' => 250000,
            'max_stack' => 3,
            'icon' => 'flow',
            'sigil_cost' => 1,
        ],
        4 => [
            'name' => 'Tide',
            'scope' => 'SELF',
            'duration_seconds' => 12 * 60 * 60,
            'time_extension_seconds' => 60 * 60,
            'modifier_fp' => 500000,
            'max_stack' => 3,
            'icon' => 'tide',
            'sigil_cost' => 1,
        ],
        5 => [
            'name' => 'Age',
            'scope' => 'SELF',
            'duration_seconds' => 24 * 60 * 60,
            'time_extension_seconds' => 90 * 60,
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
        $boost['time_extension_ticks'] = ticks_from_real_seconds($canonical['time_extension_seconds']);
        $boost['base_modifier_fp'] = $canonical['modifier_fp'];

        // Preserve runtime modifier value from active_boosts rows.
        if (array_key_exists('modifier_fp', $boost)) {
            $boost['modifier_fp'] = (int)$boost['modifier_fp'];
        } else {
            $boost['modifier_fp'] = $canonical['modifier_fp'];
        }

        $boost['max_stack'] = $canonical['max_stack'];
        $boost['icon'] = $canonical['icon'];
        $boost['sigil_cost'] = $canonical['sigil_cost'];
        $boost['current_stack'] = max(0, min(
            (int)$boost['max_stack'],
            (int)ceil(max(0, (int)$boost['modifier_fp']) / max(1, (int)$boost['base_modifier_fp']))
        ));

        return $boost;
    }
}
