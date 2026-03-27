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

// Database variables (prefer DB_*; support common platform aliases)
define('DB_HOST', env_first(['DB_HOST', 'MYSQLHOST', 'MYSQL_HOST', 'HOSTINGER_DB_HOST'], ''));
define('DB_PORT', env_first(['DB_PORT', 'MYSQLPORT', 'MYSQL_PORT', 'HOSTINGER_DB_PORT'], ''));
define('DB_NAME', env_first(['DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE', 'HOSTINGER_DB_NAME'], ''));
define('DB_USER', env_first(['DB_USER', 'MYSQLUSER', 'MYSQL_USER', 'HOSTINGER_DB_USER'], ''));
define('DB_PASS', env_first(['DB_PASS', 'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'HOSTINGER_DB_PASSWORD'], ''));

// Season timing constants
define('SEASON_ANCHOR', 345600);        // 1970-01-05T00:00:00Z in seconds
define('SEASON_DURATION', 2419200);     // 28 days in seconds
define('SEASON_CADENCE', 604800);       // 7 days in seconds
define('BLACKOUT_DURATION', 259200);    // 72 hours in seconds

// Time scale: 1 = real-time (28-day seasons), 60 = accelerated (11-hour seasons)
define('TIME_SCALE', (int)(getenv('TMC_TIME_SCALE') ?: 60));

// Tick processing controls
define('TMC_TICK_ON_REQUEST', filter_var(getenv('TMC_TICK_ON_REQUEST') ?: '1', FILTER_VALIDATE_BOOLEAN));
define('TMC_TICK_SECRET', env_first(['TMC_TICK_SECRET', 'TICK_SECRET'], ''));

// Activity
define('IDLE_TIMEOUT_TICKS', 54000);    // 15 real minutes at 60x scale

// Trade
define('TRADE_TIMEOUT_TICKS', 3600);    // 1 hour of game ticks

// Lock-In
define('MIN_PARTICIPATION_TICKS', 1);

// Sigil drops
define('SIGIL_DROP_RATE', 50000);       // 1 in 50,000
define('SIGIL_PITY_TICKS', 120000);
define('SIGIL_MAX_DROPS_WINDOW', 3);
define('SIGIL_DROP_WINDOW_TICKS', 86400);

// Sigil tier odds (fixed-point, sum = 1,000,000)
define('SIGIL_TIER_ODDS', [
    1 => 700000,  // 70% Tier I
    2 => 200000,  // 20% Tier II
    3 => 80000,   // 8% Tier III
    4 => 15000,   // 1.5% Tier IV
    5 => 5000     // 0.5% Tier V
]);

// Participation bonus
define('PARTICIPATION_BONUS_DIVISOR', 3600);
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
