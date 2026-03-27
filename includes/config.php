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

// Activity
define('IDLE_TIMEOUT_TICKS', ticks_from_real_seconds(900));  // 15 real minutes

// Trade
define('TRADE_TIMEOUT_TICKS', ticks_from_real_seconds(3600));  // 1 real hour

// Economy tuning windows
define('HOARDING_WINDOW_TICKS', ticks_from_real_seconds(86400));  // 24 real hours

// Lock-In
define('MIN_PARTICIPATION_TICKS', 1);

// Sigil drops
define('SIGIL_DROP_RATE', max(1, (int)round(50000 / 60)));  // 1 in 833 (1 tick/min default)
define('SIGIL_PITY_TICKS', ticks_from_real_seconds(120000));
define('SIGIL_MAX_DROPS_WINDOW', 3);
define('SIGIL_DROP_WINDOW_TICKS', ticks_from_real_seconds(86400));

// Sigil tier odds (fixed-point, sum = 1,000,000)
define('SIGIL_TIER_ODDS', [
    1 => 700000,  // 70% Tier I
    2 => 200000,  // 20% Tier II
    3 => 80000,   // 8% Tier III
    4 => 15000,   // 1.5% Tier IV
    5 => 5000     // 0.5% Tier V
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
