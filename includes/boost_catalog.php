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
     *
     * time_extension_seconds is the flat amount added when a player purchases a time
     * extension from the Boost Catalog. It is the EXACT amount shown on the purchase
     * button and must NOT be multiplied by the player's current power stack.
     *   Tier 1 (Trickle): +5 min
     *   Tier 2 (Surge):   +15 min
     *   Tier 3 (Flow):    +30 min
     *   Tier 4 (Tide):    +60 min
     *   Tier 5 (Age):     +90 min
     */
    private const DEFINITIONS = [
        1 => [
            'name' => 'Trickle',
            'scope' => 'SELF',
            'duration_seconds' => 1 * 60 * 60,
            'time_extension_seconds' => 5 * 60,
            'modifier_fp' => 50000,
            'max_stack' => 20,
            'icon' => 'trickle',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 0,
            'vault_stock_leverage_fp' => 1000000,
        ],
        2 => [
            'name' => 'Surge',
            'scope' => 'SELF',
            'duration_seconds' => 3 * 60 * 60,
            'time_extension_seconds' => 15 * 60,
            'modifier_fp' => 100000,
            'max_stack' => 10,
            'icon' => 'surge',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 50000,
            'vault_stock_leverage_fp' => 1100000,
        ],
        3 => [
            'name' => 'Flow',
            'scope' => 'SELF',
            'duration_seconds' => 6 * 60 * 60,
            'time_extension_seconds' => 30 * 60,
            'modifier_fp' => 200000,
            'max_stack' => 5,
            'icon' => 'flow',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 100000,
            'vault_stock_leverage_fp' => 1250000,
        ],
        4 => [
            'name' => 'Tide',
            'scope' => 'SELF',
            'duration_seconds' => 12 * 60 * 60,
            'time_extension_seconds' => 60 * 60,
            'modifier_fp' => 500000,
            'max_stack' => 2,
            'icon' => 'tide',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 150000,
            'vault_stock_leverage_fp' => 1400000,
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
            'vault_price_discount_fp' => 200000,
            'vault_stock_leverage_fp' => 1600000,
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
        $boost['vault_price_discount_fp'] = (int)$canonical['vault_price_discount_fp'];
        $boost['vault_stock_leverage_fp'] = (int)$canonical['vault_stock_leverage_fp'];
        $boost['current_stack'] = max(0, min(
            (int)$boost['max_stack'],
            (int)ceil(max(0, (int)$boost['modifier_fp']) / max(1, (int)$boost['base_modifier_fp']))
        ));

        return $boost;
    }
}
