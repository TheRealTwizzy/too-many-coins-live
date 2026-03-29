<?php
/**
 * Too Many Coins - Configuration
 * Reads from environment variables with fallback to local defaults
 */

function env_first(array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function ticks_from_real_seconds($seconds) {
    $tickRealSeconds = max(1, (int)(getenv('TMC_TICK_REAL_SECONDS') ?: 60));
    return max(1, (int)ceil($seconds / $tickRealSeconds));
}

// Database variables (prefer DB_*; support common platform aliases)
define('DB_HOST', env_first(['DB_HOST', 'MYSQLHOST', 'MYSQL_HOST', 'HOSTINGER_DB_HOST'], ''));
define('DB_PORT', env_first(['DB_PORT', 'MYSQLPORT', 'MYSQL_PORT', 'HOSTINGER_DB_PORT'], ''));
define('DB_NAME', env_first(['DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE', 'HOSTINGER_DB_NAME'], ''));
define('DB_USER', env_first(['DB_USER', 'MYSQLUSER', 'MYSQL_USER', 'HOSTINGER_DB_USER'], ''));
define('DB_PASS', env_first(['DB_PASS', 'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'HOSTINGER_DB_PASSWORD'], ''));

// Season timing constants
define('SEASON_ANCHOR', 345600);        // 1970-01-05T00:00:00Z in seconds
define('SEASON_DURATION', ticks_from_real_seconds(2419200));   // 28 days
define('SEASON_CADENCE', ticks_from_real_seconds(604800));     // 7 days
define('BLACKOUT_DURATION', ticks_from_real_seconds(259200));  // 72 hours

// Time scale multiplier applied after tick quantization. Keep at 1 in production.
define('TIME_SCALE', max(1, (int)(getenv('TMC_TIME_SCALE') ?: 1)));

// Real seconds represented by one base tick (Dokploy scheduler minimum is 60s).
define('TICK_REAL_SECONDS', max(1, (int)(getenv('TMC_TICK_REAL_SECONDS') ?: 60)));

// Tick processing controls
define('TMC_TICK_ON_REQUEST', filter_var(getenv('TMC_TICK_ON_REQUEST') ?: '1', FILTER_VALIDATE_BOOLEAN));
define('TMC_TICK_SECRET', env_first(['TMC_TICK_SECRET', 'TICK_SECRET'], ''));
define('TMC_AUTO_SQL_MIGRATIONS', filter_var(env_first(['TMC_AUTO_SQL_MIGRATIONS', 'TMC_AUTO_SQL_HOTFIX'], '1'), FILTER_VALIDATE_BOOLEAN));

// Activity
define('IDLE_TIMEOUT_TICKS', ticks_from_real_seconds(900));  // 15 real minutes

// Trade
define('TRADE_TIMEOUT_TICKS', ticks_from_real_seconds(3600));  // 1 real hour

// Economy tuning windows
define('HOARDING_WINDOW_TICKS', ticks_from_real_seconds(86400));  // 24 real hours

// Lock-In
define('MIN_PARTICIPATION_TICKS', 1);

// Sigil drops
// Per-tier effective drop rates at zero sigil power (parts per 10,000; T1=2.50% down to T5=0.50%)
define('SIGIL_TIER_DROP_RATES', [
    1 => 250,  // 2.50%
    2 => 200,  // 2.00%
    3 => 150,  // 1.50%
    4 => 100,  // 1.00%
    5 =>  50,  // 0.50%
]);
define('SIGIL_DROP_RATE', 13);           // 1 in 13 (~7.69% combined base drop rate at 0 sigil power)
define('SIGIL_DROP_RATE_MAX_POWER', 26); // 1 in 26 (~3.85% combined drop rate at full sigil power)
define('SIGIL_PITY_TICKS', ticks_from_real_seconds(120000));
define('SIGIL_MAX_DROPS_WINDOW', 8);
define('SIGIL_DROP_WINDOW_TICKS', ticks_from_real_seconds(86400));

// Per-player dynamic sigil drop rate configuration
//
// Inventory-based dampening:
//   For every SIGIL_INVENTORY_ADJ_THRESHOLD sigils of a given tier already owned, that
//   tier's conditional drop odds are reduced by SIGIL_INVENTORY_ADJ_STEP_FP (parts per
//   1,000,000), up to a maximum of SIGIL_INVENTORY_ADJ_MAX_STEPS reductions.  This
//   prevents a single tier from over-dropping once a player has accumulated many of them
//   while still keeping drops the primary sigil acquisition source.
define('SIGIL_INVENTORY_ADJ_THRESHOLD', 5);    // Trigger every N same-tier sigils owned
define('SIGIL_INVENTORY_ADJ_STEP_FP',   10000); // Reduce odds by 1% (10,000 / 1,000,000) per trigger
define('SIGIL_INVENTORY_ADJ_MAX_STEPS', 10);   // Cap: maximum 10 triggers (≤10% total reduction per tier)

// Boost-based drop frequency adjustment:
//   An active boost modifier (expressed as a fixed-point value where FP_SCALE = 1,000,000
//   represents 100%) reduces the Bernoulli denominator, directly increasing drop frequency.
//   Every SIGIL_BOOST_DROP_RATE_STEP_FP of boost lowers the denominator by 1, capped at
//   SIGIL_BOOST_DROP_RATE_MAX_BONUS total reduction.  Example: with a 40% boost the
//   denominator drops from 13 to 11 (~9.09% vs ~7.69% base rate).
define('SIGIL_BOOST_DROP_RATE_STEP_FP',  200000); // 20% boost per denominator-reduction step
define('SIGIL_BOOST_DROP_RATE_MAX_BONUS', 3);      // Maximum denominator reduction from boosts

// Per-tier conditional-odds bounds (parts per 1,000,000).
// Dynamic adjustments are clamped within [MIN, MAX] for each tier, then monotonic
// ordering (T1 >= T2 >= T3 >= T4 >= T5) is enforced so lower-tier sigils can never
// become rarer than higher-tier sigils.
define('SIGIL_TIER_ODDS_MIN', [
    1 => 200000,  // T1 (Common)    – never below 20% of conditional drops
    2 => 100000,  // T2 (Uncommon)  – never below 10%
    3 =>  75000,  // T3 (Rare)      – never below  7.5%
    4 =>  50000,  // T4 (Epic)      – never below  5%
    5 =>  25000,  // T5 (Legendary) – never below  2.5%
]);
define('SIGIL_TIER_ODDS_MAX', [
    1 => 600000,  // T1 – never above 60% of conditional drops
    2 => 500000,  // T2 – never above 50%
    3 => 400000,  // T3 – never above 40%
    4 => 300000,  // T4 – never above 30%
    5 => 200000,  // T5 – never above 20%
]);

// Sigil progression and crafting
define('SIGIL_MAX_TIER', 6);
define('SIGIL_COMBINE_RECIPES', [
    1 => 5,
    2 => 5,
    3 => 3,
    4 => 3,
    5 => 2,
]);

// Tier-odds scaling by sigil power. Tier 6 is intentionally excluded from RNG drops.
define('SIGIL_POWER_FULL_SHIFT', 40);
define('SIGIL_TIER_ODDS_MAX_POWER', [
    1 => 333333,
    2 => 266667,
    3 => 200000,
    4 => 133333,
    5 =>  66667,
]);

// Freeze mechanics (Tier 6 sigil action)
define('FREEZE_BASE_DURATION_TICKS', ticks_from_real_seconds(1200)); // 20 minutes
define('FREEZE_STACK_MULTIPLIER_FP', 1250000); // 1.25x

// Guaranteed boost floor: +1 whole coin per tick for each 10% effective boost.
// Set cap to 0 for no cap.
define('BOOST_GUARANTEED_FLOOR_STEP_PERCENT', 10);
define('BOOST_GUARANTEED_FLOOR_STEP_COINS', 1);
define('BOOST_GUARANTEED_FLOOR_CAP_COINS', 0);

// Sigil tier odds (fixed-point, sum = 1,000,000)
// Proportional to SIGIL_TIER_DROP_RATES: T1=2.5%, T2=2.0%, T3=1.5%, T4=1.0%, T5=0.5% of total 7.5%
define('SIGIL_TIER_ODDS', [
    1 => 333333,  // ~2.56% effective T1 at 0 sigil power (≈ 2.5% target)
    2 => 266667,  // ~2.05% effective T2
    3 => 200000,  // ~1.54% effective T3
    4 => 133333,  // ~1.03% effective T4
    5 =>  66667,  // ~0.51% effective T5 (≈ 0.5% target)
]);

// Participation bonus
define('PARTICIPATION_BONUS_DIVISOR', ticks_from_real_seconds(3600));
define('PARTICIPATION_BONUS_CAP', 56);

// Placement bonus
define('PLACEMENT_BONUS', [1 => 100, 2 => 60, 3 => 40]);

// Cosmetic price tiers
define('COSMETIC_PRICE_TIERS', [10, 25, 60, 150, 400]);

// Handle rules
define('HANDLE_MIN_LENGTH', 3);
define('HANDLE_MAX_LENGTH', 16);
define('HANDLE_PATTERN', '/^[A-Za-z0-9_]+$/');
define('HANDLE_COOLDOWN_DAYS', 30);
define('RESERVED_HANDLES', ['admin', 'moderator', 'mod', 'support', 'system', 'official']);

// Chat limits
define('CHAT_MAX_ROWS', 200);
define('CHAT_MAX_LENGTH', 500);

// Session
define('SESSION_LIFETIME', 86400);

// Fixed-point scale
define('FP_SCALE', 1000000);
