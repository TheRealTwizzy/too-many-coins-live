<?php
/**
 * Too Many Coins - API Router
 * All game API endpoints
 */

// Security headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Rate limiting (simple file-based, per-IP)
$rateLimitDir = sys_get_temp_dir() . '/tmc_ratelimit';
if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0755, true);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = $rateLimitDir . '/' . md5($clientIp) . '.json';
$rateLimit = 120; // requests per minute
$rateWindow = 60;
$now = time();
$rateData = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : null;
if (!$rateData || ($now - $rateData['window_start']) >= $rateWindow) {
    $rateData = ['window_start' => $now, 'count' => 0];
}
$rateData['count']++;
file_put_contents($rateLimitFile, json_encode($rateData));
if ($rateData['count'] > $rateLimit) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please slow down.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/tick_engine.php';

// Database initialization endpoint (must be before server_state check)
$earlyAction = $_GET['action'] ?? '';
if ($earlyAction === 'init_db') {
    require_once __DIR__ . '/../init_db.php';
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Must run before server_state check
if ($path === '/api/init_db') {
 require __DIR__ . '/../init_db.php';
 exit;
}

// Parse request early so tick routing can happen before default tick behavior.
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input = array_merge($_GET, $_POST, $input);

// Initialize server state if needed
$db = Database::getInstance();
$serverState = $db->fetch("SELECT * FROM server_state WHERE id = 1");
if (!$serverState) {
    $yearSeed = random_bytes(32);
    $db->query(
        "INSERT INTO server_state (id, server_mode, lifecycle_phase, current_year_seq, global_tick_index)
         VALUES (1, 'NORMAL', 'Release', 1, ?)",
        [GameTime::now()]
    );
    $db->query(
        "INSERT INTO yearly_state (year_seq, year_seed, started_at) VALUES (1, ?, ?)",
        [$yearSeed, GameTime::now()]
    );
}

// Dedicated scheduler endpoint: invoke with action=tick and a valid tick secret.
if ($action === 'tick') {
    $providedTickSecret = $input['tick_secret'] ?? ($_SERVER['HTTP_X_TICK_SECRET'] ?? '');

    if (TMC_TICK_SECRET === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Tick endpoint is not configured']);
        exit;
    }

    if (!hash_equals(TMC_TICK_SECRET, (string)$providedTickSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    try {
        TickEngine::processTicks();
        echo json_encode([
            'ok' => true,
            'server_now' => GameTime::now()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Tick processing failed']);
        error_log("Tick endpoint error: " . $e->getMessage());
    }
    exit;
}

// Optional fallback: process ticks on normal API requests.
if (TMC_TICK_ON_REQUEST) {
    try {
        TickEngine::processTicks();
    } catch (Exception $e) {
        // Don't fail API calls due to tick processing errors
        error_log("Tick error: " . $e->getMessage());
    }
}

try {
    switch ($action) {
        // ==================== AUTH ====================
        case 'register':
            echo json_encode(Auth::register(
                $input['handle'] ?? '',
                $input['email'] ?? '',
                $input['password'] ?? ''
            ));
            break;
            
        case 'login':
            echo json_encode(Auth::login(
                $input['email'] ?? '',
                $input['password'] ?? ''
            ));
            break;
            
        case 'logout':
            echo json_encode(Auth::logout());
            break;
            
        // ==================== GAME STATE ====================
        case 'game_state':
            $player = Auth::getCurrentPlayer();
            echo json_encode(getGameState($player));
            break;
            
        case 'season_detail':
            $player = Auth::getCurrentPlayer();
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(getSeasonDetail($player, $seasonId));
            break;
            
        case 'leaderboard':
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(getLeaderboard($seasonId));
            break;
            
        case 'global_leaderboard':
            echo json_encode(getGlobalLeaderboard());
            break;
            
        // ==================== SEASON ACTIONS ====================
        case 'season_join':
            $player = Auth::requireAuth();
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(Actions::seasonJoin($player['player_id'], $seasonId));
            break;
            
        case 'purchase_stars':
            $player = Auth::requireAuth();
            $starsRequested = (int)($input['stars_requested'] ?? 0);
            echo json_encode(Actions::purchaseStars($player['player_id'], $starsRequested));
            break;
            
        case 'purchase_vault':
            $player = Auth::requireAuth();
            $tier = (int)($input['tier'] ?? 0);
            echo json_encode(Actions::purchaseVaultSigil($player['player_id'], $tier));
            break;
            
        case 'lock_in':
            $player = Auth::requireAuth();
            echo json_encode(Actions::lockIn($player['player_id']));
            break;
            
        case 'idle_ack':
            $player = Auth::requireAuth();
            echo json_encode(Actions::idleAck($player['player_id']));
            break;
            
        // ==================== BOOSTS ====================
        case 'boost_catalog':
            echo json_encode(getBoostCatalog());
            break;
            
        case 'purchase_boost':
            $player = Auth::requireAuth();
            $boostId = (int)($input['boost_id'] ?? 0);
            echo json_encode(Actions::purchaseBoost($player['player_id'], $boostId));
            break;
            
        case 'active_boosts':
            $player = Auth::requireAuth();
            echo json_encode(getActiveBoosts($player));
            break;
            
        case 'sigil_drops':
            $player = Auth::requireAuth();
            echo json_encode(getRecentSigilDrops($player));
            break;
            
        // ==================== TRADING ====================
        case 'trade_initiate':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeInitiate(
                $player['player_id'],
                (int)($input['acceptor_id'] ?? 0),
                (int)($input['side_a_coins'] ?? 0),
                $input['side_a_sigils'] ?? [0,0,0,0,0],
                (int)($input['side_b_coins'] ?? 0),
                $input['side_b_sigils'] ?? [0,0,0,0,0]
            ));
            break;
            
        case 'trade_accept':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeAccept(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0)
            ));
            break;
            
        case 'trade_decline':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeCancel(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0),
                'DECLINED'
            ));
            break;
            
        case 'trade_cancel':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeCancel(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0),
                'CANCELED'
            ));
            break;
            
        case 'my_trades':
            $player = Auth::requireAuth();
            echo json_encode(getMyTrades($player));
            break;
            
        case 'season_players':
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(getSeasonPlayers($seasonId));
            break;
            
        // ==================== COSMETICS ====================
        case 'cosmetic_catalog':
            echo json_encode(getCosmeticCatalog());
            break;
            
        case 'purchase_cosmetic':
            $player = Auth::requireAuth();
            echo json_encode(Actions::purchaseCosmetic(
                $player['player_id'],
                (int)($input['cosmetic_id'] ?? 0)
            ));
            break;
            
        case 'equip_cosmetic':
            $player = Auth::requireAuth();
            echo json_encode(Actions::equipCosmetic(
                $player['player_id'],
                (int)($input['cosmetic_id'] ?? 0),
                (bool)($input['equip'] ?? true)
            ));
            break;
            
        case 'my_cosmetics':
            $player = Auth::requireAuth();
            echo json_encode(getMyCosmetics($player));
            break;
            
        // ==================== CHAT ====================
        case 'chat_send':
            $player = Auth::requireAuth();
            echo json_encode(sendChat($player, $input));
            break;
            
        case 'chat_messages':
            $player = Auth::getCurrentPlayer();
            echo json_encode(getChatMessages($player, $input));
            break;
            
        // ==================== PROFILE ====================
        case 'profile':
            $targetId = (int)($input['player_id'] ?? 0);
            $player = Auth::getCurrentPlayer();
            echo json_encode(getProfile($player, $targetId));
            break;
            
        case 'my_badges':
            $player = Auth::requireAuth();
            echo json_encode(getMyBadges($player));
            break;
            
        case 'season_history':
            $player = Auth::requireAuth();
            echo json_encode(getSeasonHistory($player));
            break;
            
        default:
            echo json_encode(['error' => 'Unknown action', 'available_actions' => [
                'register', 'login', 'logout', 'game_state', 'season_detail', 'leaderboard',
                'global_leaderboard', 'season_join', 'purchase_stars', 'purchase_vault',
                'lock_in', 'idle_ack', 'boost_catalog', 'purchase_boost', 'active_boosts',
                'sigil_drops', 'trade_initiate', 'trade_accept', 'trade_decline',
                'trade_cancel', 'my_trades', 'season_players', 'cosmetic_catalog',
                'purchase_cosmetic', 'equip_cosmetic', 'my_cosmetics', 'chat_send',
                'chat_messages', 'profile', 'my_badges', 'season_history', 'tick'
            ]]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}

// ==================== HELPER FUNCTIONS ====================

function getGameState($player) {
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    
    $state = [
        'server_now' => $gameTime,
        'global_tick_index' => $gameTime,
        'server_mode' => 'NORMAL',
        'lifecycle_phase' => 'Release',
        'seasons' => [],
        'player' => null
    ];
    
    // Get visible seasons
    $seasons = GameTime::getVisibleSeasons();
    foreach ($seasons as &$s) {
        $s['computed_status'] = GameTime::getSeasonStatus($s);
        applySeasonCountdownFields($s, $gameTime);
        $endTime = (int)$s['end_time'];
        $blackoutTime = (int)$s['blackout_time'];
        $s['blackout_remaining'] = max(0, $blackoutTime - $gameTime);
        $s['is_blackout'] = ($gameTime >= $blackoutTime && $gameTime < $endTime);
        
        // Get vault info
        $s['vault'] = $db->fetchAll(
            "SELECT * FROM season_vault WHERE season_id = ? ORDER BY tier",
            [$s['season_id']]
        );
        
        // Remove binary seed from response
        unset($s['season_seed']);
    }
    $state['seasons'] = $seasons;
    
    if ($player) {
        $participation = null;
        if ($player['joined_season_id']) {
            $participation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$player['player_id'], $player['joined_season_id']]
            );
        }
        
        $state['player'] = [
            'player_id' => $player['player_id'],
            'handle' => $player['handle'],
            'role' => $player['role'],
            'global_stars' => (int)$player['global_stars'],
            'joined_season_id' => $player['joined_season_id'],
            'participation_enabled' => (bool)$player['participation_enabled'],
            'idle_modal_active' => (bool)$player['idle_modal_active'],
            'activity_state' => $player['activity_state'],
            'participation' => $participation ? [
                'coins' => (int)$participation['coins'],
                'seasonal_stars' => (int)$participation['seasonal_stars'],
                'sigils' => [
                    (int)$participation['sigils_t1'],
                    (int)$participation['sigils_t2'],
                    (int)$participation['sigils_t3'],
                    (int)$participation['sigils_t4'],
                    (int)$participation['sigils_t5']
                ],
                'sigils_total' => (int)$participation['sigils_t1'] + (int)$participation['sigils_t2'] + (int)$participation['sigils_t3'] + (int)$participation['sigils_t4'] + (int)$participation['sigils_t5'],
                'participation_time' => (int)$participation['participation_time_total'],
                'active_ticks' => (int)$participation['active_ticks_total'],
                'lock_in_stars' => $participation['lock_in_snapshot_seasonal_stars'],
                'sigil_drops_total' => (int)($participation['sigil_drops_total'] ?? 0),
                'eligible_ticks_since_last_drop' => (int)($participation['eligible_ticks_since_last_drop'] ?? 0),
            ] : null,
            'active_boosts' => getActiveBoosts($player),
            'recent_drops' => ($player['joined_season_id']) ? getRecentSigilDrops($player) : [],
            'can_lock_in' => canLockIn($player, $participation),
            'can_purchase_stars' => canPurchaseStars($player),
            'can_trade' => canTrade($player),
        ];
    }
    
    return $state;
}

function gameTicksToRealSeconds($gameTicks) {
    if ($gameTicks <= 0) return 0;
    $scale = max(1, (int)TIME_SCALE);
    return max(0, intdiv(((int)$gameTicks) * (int)TICK_REAL_SECONDS, $scale));
}

function applySeasonCountdownFields(&$season, $gameTime) {
    $status = $season['computed_status'] ?? GameTime::getSeasonStatus($season);
    $startTime = (int)$season['start_time'];
    $endTime = (int)$season['end_time'];

    if ($status === 'Scheduled') {
        $remaining = max(0, $startTime - $gameTime);
        $mode = 'scheduled';
        $label = 'Begins in';
    } elseif ($status === 'Active' || $status === 'Blackout') {
        $remaining = max(0, $endTime - $gameTime);
        $mode = 'running';
        $label = 'Time Left';
    } else {
        $remaining = 0;
        $mode = 'ended';
        $label = 'Ended';
    }

    $season['time_remaining'] = $remaining;
    $season['time_remaining_real_seconds'] = gameTicksToRealSeconds($remaining);
    $season['time_remaining_formatted'] = GameTime::formatTimeRemaining($remaining);
    $season['countdown_mode'] = $mode;
    $season['countdown_label'] = $label;
}

function canLockIn($player, $participation) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    if (!$participation) return false;
    if ($participation['participation_ticks_since_join'] < MIN_PARTICIPATION_TICKS) return false;
    
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active');
}

function canPurchaseStars($player) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    return true;
}

function canTrade($player) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active');
}

function getSeasonDetail($player, $seasonId) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return ['error' => 'Season not found'];
    
    $gameTime = GameTime::now();
    $season['computed_status'] = GameTime::getSeasonStatus($season);
    applySeasonCountdownFields($season, $gameTime);
    
    // Vault
    $season['vault'] = $db->fetchAll(
        "SELECT * FROM season_vault WHERE season_id = ? ORDER BY tier",
        [$seasonId]
    );
    
    // Top players
    if ($season['computed_status'] === 'Active' || $season['computed_status'] === 'Blackout') {
        $season['leaderboard'] = $db->fetchAll(
            "SELECT p.player_id, p.handle,
                    COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
                    sp.lock_in_effect_tick
             FROM players p
             LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
             WHERE p.joined_season_id = ? AND p.participation_enabled = 1
             ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC",
            [$seasonId, $seasonId]
        );
    } else {
        $season['leaderboard'] = $db->fetchAll(
            "SELECT sp.player_id, p.handle, sp.seasonal_stars, sp.lock_in_effect_tick
             FROM season_participation sp
             JOIN players p ON p.player_id = sp.player_id
             WHERE sp.season_id = ? AND (sp.seasonal_stars > 0 OR sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
             ORDER BY sp.seasonal_stars DESC, sp.player_id ASC
             LIMIT 50",
            [$seasonId]
        );
    }
    
    // Player count
    $season['player_count'] = $db->fetch(
        "SELECT COUNT(*) as cnt FROM players WHERE joined_season_id = ? AND participation_enabled = 1",
        [$seasonId]
    )['cnt'];
    
    unset($season['season_seed']);
    return $season;
}

function getLeaderboard($seasonId) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return [];

    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Active' || $status === 'Blackout') {
        return $db->fetchAll(
            "SELECT p.player_id, p.handle,
                    COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
                    sp.final_rank,
                    sp.lock_in_effect_tick,
                    COALESCE(sp.end_membership, 0) AS end_membership,
                    sp.badge_awarded,
                    COALESCE(sp.global_stars_earned, 0) AS global_stars_earned,
                    COALESCE(sp.participation_bonus, 0) AS participation_bonus,
                    COALESCE(sp.placement_bonus, 0) AS placement_bonus,
                    p.activity_state, p.online_current
             FROM players p
             LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
             WHERE p.joined_season_id = ? AND p.participation_enabled = 1
             ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC",
            [$seasonId, $seasonId]
        );
    }

    return $db->fetchAll(
        "SELECT sp.player_id, p.handle, sp.seasonal_stars, sp.final_rank,
                sp.lock_in_effect_tick, sp.end_membership, sp.badge_awarded,
                sp.global_stars_earned, sp.participation_bonus, sp.placement_bonus,
                p.activity_state, p.online_current
         FROM season_participation sp
         JOIN players p ON p.player_id = sp.player_id
         WHERE sp.season_id = ?
         AND (sp.seasonal_stars > 0 OR sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
         ORDER BY sp.seasonal_stars DESC, sp.player_id ASC
         LIMIT 100",
        [$seasonId]
    );
}

function getGlobalLeaderboard() {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT player_id, handle, global_stars, activity_state, online_current
         FROM players 
         WHERE global_stars > 0 AND profile_deleted_at IS NULL
         ORDER BY global_stars DESC, player_id ASC
         LIMIT 100"
    );
}

function getMyTrades($player) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return [];
    
    return $db->fetchAll(
        "SELECT t.*, 
                pi.handle as initiator_handle,
                pa.handle as acceptor_handle
         FROM trades t
         JOIN players pi ON pi.player_id = t.initiator_id
         JOIN players pa ON pa.player_id = t.acceptor_id
         WHERE t.season_id = ? AND (t.initiator_id = ? OR t.acceptor_id = ?)
         ORDER BY t.created_at DESC LIMIT 20",
        [$player['joined_season_id'], $player['player_id'], $player['player_id']]
    );
}

function getSeasonPlayers($seasonId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT p.player_id, p.handle, p.activity_state, p.online_current
         FROM players p
         WHERE p.joined_season_id = ? AND p.participation_enabled = 1
         ORDER BY p.handle ASC",
        [$seasonId]
    );
}

function getCosmeticCatalog() {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM cosmetic_catalog ORDER BY price_global_stars ASC, name ASC");
}

function getMyCosmetics($player) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT c.*, pc.equipped, pc.purchased_at
         FROM player_cosmetics pc
         JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
         WHERE pc.player_id = ?
         ORDER BY pc.purchased_at DESC",
        [$player['player_id']]
    );
}

function sendChat($player, $input) {
    $db = Database::getInstance();
    $channelKind = strtoupper($input['channel'] ?? 'GLOBAL');
    $content = trim($input['content'] ?? '');
    $seasonId = $input['season_id'] ?? null;
    $recipientId = $input['recipient_id'] ?? null;
    
    if (empty($content)) return ['error' => 'Message cannot be empty'];
    if (strlen($content) > CHAT_MAX_LENGTH) return ['error' => 'Message too long'];
    
    // Channel validation
    if ($channelKind === 'SEASON') {
        if (!$player['joined_season_id']) return ['error' => 'Not in a season'];
        $seasonId = $player['joined_season_id'];
    }
    if ($channelKind === 'DM' && !$recipientId) return ['error' => 'Recipient required for DM'];
    
    $db->query(
        "INSERT INTO chat_messages (channel_kind, season_id, sender_id, recipient_id, handle_snapshot, content)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$channelKind, $seasonId, $player['player_id'], $recipientId, $player['handle'], $content]
    );
    
    return ['success' => true];
}

function getChatMessages($player, $input) {
    $db = Database::getInstance();
    $channelKind = strtoupper($input['channel'] ?? 'GLOBAL');
    $seasonId = $input['season_id'] ?? null;
    
    if ($channelKind === 'GLOBAL') {
        return $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_admin_post, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'GLOBAL' AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [CHAT_MAX_ROWS]
        );
    }
    
    if ($channelKind === 'SEASON' && $seasonId) {
        return $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'SEASON' AND season_id = ? AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [$seasonId, CHAT_MAX_ROWS]
        );
    }
    
    return [];
}

function getProfile($viewer, $targetId) {
    $db = Database::getInstance();
    $target = $db->fetch(
        "SELECT player_id, handle, role, global_stars, profile_visibility, created_at, profile_deleted_at
         FROM players WHERE player_id = ?",
        [$targetId]
    );
    if (!$target) return ['error' => 'Player not found'];
    
    if ($target['profile_deleted_at']) {
        return ['player_id' => $target['player_id'], 'handle' => '[Removed]', 'deleted' => true];
    }
    
    // Get badges
    $badges = $db->fetchAll(
        "SELECT * FROM badges WHERE player_id = ? ORDER BY awarded_at DESC",
        [$targetId]
    );
    
    // Get season history
    $history = $db->fetchAll(
        "SELECT sp.*, s.start_time, s.end_time
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ? AND (sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
         ORDER BY s.start_time DESC LIMIT 20",
        [$targetId]
    );
    
    $target['badges'] = $badges;
    $target['season_history'] = $history;
    
    return $target;
}

function getMyBadges($player) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT b.*, s.start_time, s.end_time
         FROM badges b
         LEFT JOIN seasons s ON s.season_id = b.season_id
         WHERE b.player_id = ?
         ORDER BY b.awarded_at DESC",
        [$player['player_id']]
    );
}

function getSeasonHistory($player) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT sp.*, s.start_time, s.end_time, s.status as season_status
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ?
         ORDER BY s.start_time DESC LIMIT 50",
        [$player['player_id']]
    );
}

function getBoostCatalog() {
    $db = Database::getInstance();
    $catalog = $db->fetchAll("SELECT * FROM boost_catalog ORDER BY tier_required ASC, boost_id ASC");
    foreach ($catalog as &$boost) {
        $durationTicks = (int)$boost['duration_ticks'];
        // Backward compatibility: legacy self boosts were seeded at 60/120/180 ticks.
        // Canonical minute-based self boosts use 1/2/3 ticks.
        if ($boost['scope'] === 'SELF' && $durationTicks >= 60 && $durationTicks <= 180 && $durationTicks % 60 === 0) {
            $boost['duration_ticks'] = intdiv($durationTicks, 60);
        }
    }
    unset($boost);
    return $catalog;
}

function getActiveBoosts($player) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return ['self' => [], 'global' => []];
    
    $gameTime = GameTime::now();
    $serverNowUnix = time();
    $seasonId = $player['joined_season_id'];
    
    $selfBoosts = $db->fetchAll(
        "SELECT ab.*, bc.name, bc.description, bc.tier_required, bc.icon
         FROM active_boosts ab
         JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
         WHERE ab.player_id = ? AND ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'SELF' AND ab.expires_tick >= ?
         ORDER BY ab.expires_tick ASC",
        [$player['player_id'], $seasonId, $gameTime]
    );
    
    $globalBoosts = $db->fetchAll(
        "SELECT ab.*, bc.name, bc.description, bc.tier_required, bc.icon, p.handle as activator_handle
         FROM active_boosts ab
         JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
         JOIN players p ON p.player_id = ab.player_id
         WHERE ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'GLOBAL' AND ab.expires_tick >= ?
         ORDER BY ab.expires_tick ASC",
        [$seasonId, $gameTime]
    );
    
    // Annotate each boost with wall-clock expiry data for client-side real-time countdown.
    // expires_at_real is an absolute Unix timestamp: the moment the boost expires in real time.
    // remaining_real_seconds is the number of real seconds left at the time this response is built.
    // Using absolute timestamps makes the countdown resilient to page refresh, disconnect, and idle.
    foreach ($selfBoosts as &$b) {
        $ticksRemaining = max(0, (int)$b['expires_tick'] - $gameTime);
        $b['remaining_real_seconds'] = gameTicksToRealSeconds($ticksRemaining);
        $b['expires_at_real'] = $serverNowUnix + $b['remaining_real_seconds'];
    }
    unset($b);
    foreach ($globalBoosts as &$b) {
        $ticksRemaining = max(0, (int)$b['expires_tick'] - $gameTime);
        $b['remaining_real_seconds'] = gameTicksToRealSeconds($ticksRemaining);
        $b['expires_at_real'] = $serverNowUnix + $b['remaining_real_seconds'];
    }
    unset($b);
    
    // Calculate total modifier
    $totalModFp = 0;
    foreach ($selfBoosts as $b) $totalModFp += (int)$b['modifier_fp'];
    foreach ($globalBoosts as $b) $totalModFp += (int)$b['modifier_fp'];
    $totalModFp = min($totalModFp, 4000000); // Cap at 400% bonus
    
    return [
        'self' => $selfBoosts,
        'global' => $globalBoosts,
        'total_modifier_fp' => $totalModFp,
        'total_modifier_percent' => round($totalModFp / 10000, 1),
        'server_now' => $gameTime,
        'server_real_now' => $serverNowUnix
    ];
}

function getRecentSigilDrops($player) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return [];
    
    $seasonId = $player['joined_season_id'];
    
    return $db->fetchAll(
        "SELECT tier, source, drop_tick, created_at 
         FROM sigil_drop_log 
         WHERE player_id = ? AND season_id = ?
         ORDER BY drop_tick DESC LIMIT 20",
        [$player['player_id'], $seasonId]
    );
}
