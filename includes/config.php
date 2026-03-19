<?php
/**
 * Too Many Coins - Configuration
 */

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'too_many_coins');
define('DB_USER', 'tmc_user');
define('DB_PASS', 'tmc_pass_2024');

// Season timing constants
define('SEASON_ANCHOR', 345600);        // 1970-01-05T00:00:00Z in seconds
define('SEASON_DURATION', 2419200);     // 28 days in seconds
define('SEASON_CADENCE', 604800);       // 7 days in seconds
define('BLACKOUT_DURATION', 259200);    // 72 hours in seconds

// For demo: accelerated time (1 real second = 60 game seconds)
define('TIME_SCALE', 60);
// Season duration in real time: ~11.2 hours for a full 28-day season
// Blackout starts ~9.9 hours in

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
