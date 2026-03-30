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
require_once __DIR__ . '/../includes/boost_catalog.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/tick_engine.php';
require_once __DIR__ . '/../includes/notifications.php';

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

/**
 * Convert a MySQL DATETIME string ("YYYY-MM-DD HH:MM:SS") to an ISO 8601
 * UTC string ("YYYY-MM-DDTHH:MM:SS+00:00") for unambiguous JS Date parsing.
 * Returns null for null/empty input.
 */
function iso_utc_datetime(?string $dt): ?string {
    if ($dt === null || $dt === '') return null;
    return str_replace(' ', 'T', $dt) . '+00:00';
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
            $limit = isset($input['limit']) ? (int)$input['limit'] : 0;
            echo json_encode(getLeaderboard($seasonId, $limit));
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
            
        case 'star_purchase_preview':
            $player = Auth::requireAuth();
            echo json_encode(previewStarPurchase($player, (int)($input['stars_requested'] ?? 0)));
            break;

        case 'purchase_stars':
            $player = Auth::requireAuth();
            $starsRequested = (int)($input['stars_requested'] ?? 0);
            $confirmed = !empty($input['confirm_economic_impact']);
            $result = gatedStarPurchase($player, $starsRequested, $confirmed);
            echo json_encode($result);
            break;
            
        case 'purchase_vault':
            $player = Auth::requireAuth();
            $tier = (int)($input['tier'] ?? 0);
            echo json_encode(Actions::purchaseVaultSigil($player['player_id'], $tier));
            break;

        case 'combine_sigil':
            $player = Auth::requireAuth();
            $fromTier = (int)($input['from_tier'] ?? 0);
            echo json_encode(Actions::combineSigils($player['player_id'], $fromTier));
            break;

        case 'freeze_player_ubi':
            $player = Auth::requireAuth();
            echo json_encode(Actions::freezePlayerUbi(
                $player['player_id'],
                (int)($input['target_player_id'] ?? 0),
                isset($input['target_handle']) ? (string)$input['target_handle'] : null
            ));
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
            
        case 'boost_activate_preview':
            $player = Auth::requireAuth();
            echo json_encode(previewBoostActivate($player, (int)($input['boost_id'] ?? 0), (string)($input['purchase_kind'] ?? 'power')));
            break;

        case 'purchase_boost':
            $player = Auth::requireAuth();
            $boostId = (int)($input['boost_id'] ?? 0);
            $purchaseKind = (string)($input['purchase_kind'] ?? 'power');
            $confirmed = !empty($input['confirm_economic_impact']);
            $result = gatedBoostActivate($player, $boostId, $purchaseKind, $confirmed);
            echo json_encode($result);
            break;
            
        case 'active_boosts':
            $player = Auth::requireAuth();
            echo json_encode(getActiveBoosts($player));
            break;
            
        case 'sigil_drops':
            $player = Auth::requireAuth();
            echo json_encode(getRecentSigilDrops($player));
            break;

        // ==================== NOTIFICATIONS ====================
        case 'notifications_list':
            $player = Auth::requireAuth();
            $limit = (int)($input['limit'] ?? 50);
            echo json_encode([
                'success' => true,
                'notifications' => Notifications::listForPlayer($player['player_id'], $limit),
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        case 'notifications_mark_read':
            $player = Auth::requireAuth();
            $ids = getNotificationIdsFromInput($input);
            $updated = Notifications::markRead($player['player_id'], $ids);
            echo json_encode([
                'success' => true,
                'updated' => $updated,
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        case 'notifications_mark_all_read':
            $player = Auth::requireAuth();
            $updated = Notifications::markAllRead($player['player_id']);
            echo json_encode([
                'success' => true,
                'updated' => $updated,
                'unread_count' => 0
            ]);
            break;

        case 'notifications_remove':
            $player = Auth::requireAuth();
            $ids = getNotificationIdsFromInput($input);
            $removed = Notifications::remove($player['player_id'], $ids);
            echo json_encode([
                'success' => true,
                'removed' => $removed,
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        case 'notifications_create':
            $player = Auth::requireAuth();
            $category = trim((string)($input['category'] ?? 'gameplay'));
            if ($category === '') $category = 'gameplay';
            $title = trim((string)($input['title'] ?? $input['message'] ?? 'Notification'));
            if ($title === '') $title = 'Notification';
            $bodyRaw = $input['body'] ?? null;
            $body = is_string($bodyRaw) ? trim($bodyRaw) : null;
            if ($body === '') $body = null;

            $payload = null;
            if (isset($input['payload']) && is_array($input['payload'])) {
                $payload = $input['payload'];
            }

            $id = Notifications::create(
                $player['player_id'],
                $category,
                $title,
                $body,
                [
                    'is_read' => !empty($input['is_read']),
                    'event_key' => isset($input['event_key']) ? (string)$input['event_key'] : null,
                    'payload' => $payload
                ]
            );

            echo json_encode([
                'success' => true,
                'notification' => Notifications::getByIdForPlayer($player['player_id'], $id),
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;
            
        // ==================== TRADING ====================
        case 'trade_preview':
            $player = Auth::requireAuth();
            $sideASigils = normalizeSigilCounts($input['side_a_sigils'] ?? [0,0,0,0,0,0]);
            $sideBSigils = normalizeSigilCounts($input['side_b_sigils'] ?? [0,0,0,0,0,0]);
            echo json_encode(previewTrade(
                $player,
                (int)($input['acceptor_id'] ?? 0),
                (int)($input['side_a_coins'] ?? 0),
                $sideASigils,
                (int)($input['side_b_coins'] ?? 0),
                $sideBSigils
            ));
            break;

        case 'trade_initiate':
            $player = Auth::requireAuth();
            $confirmed = !empty($input['confirm_economic_impact']);
            $sideASigils = normalizeSigilCounts($input['side_a_sigils'] ?? [0,0,0,0,0,0]);
            $sideBSigils = normalizeSigilCounts($input['side_b_sigils'] ?? [0,0,0,0,0,0]);
            $result = gatedTradeInitiate(
                $player,
                (int)($input['acceptor_id'] ?? 0),
                (int)($input['side_a_coins'] ?? 0),
                $sideASigils,
                (int)($input['side_b_coins'] ?? 0),
                $sideBSigils,
                $confirmed
            );
            echo json_encode($result);
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
                'combine_sigil', 'freeze_player_ubi',
                'lock_in', 'idle_ack', 'boost_catalog', 'purchase_boost', 'active_boosts',
                'sigil_drops', 'trade_initiate', 'trade_accept', 'trade_decline',
                'trade_cancel', 'my_trades', 'season_players', 'cosmetic_catalog',
                'purchase_cosmetic', 'equip_cosmetic', 'my_cosmetics', 'chat_send',
                'chat_messages', 'notifications_list', 'notifications_mark_read',
                'notifications_mark_all_read', 'notifications_remove', 'notifications_create',
                'profile', 'my_badges', 'season_history', 'tick',
                'star_purchase_preview', 'trade_preview', 'boost_activate_preview'
            ]]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}

// ==================== HELPER FUNCTIONS ====================
const LEADERBOARD_MAX_LIMIT = 200;

function getSigilDropRateMetadata($sigilPower = 0) {
    $sigilPower = max(0, (int)$sigilPower);
    $dropRate = Economy::sigilDropRateForPower($sigilPower);
    $basePercent = 100 / max(1, (int)$dropRate);
    $tierOdds = Economy::adjustedSigilTierOdds($sigilPower);
    $tiers = [];
    foreach ($tierOdds as $tier => $oddsFp) {
        $conditionalPercent = ((int)$oddsFp / 1000000) * 100;
        $effectivePercent = $basePercent * ((int)$oddsFp / 1000000);
        $tiers[] = [
            'tier' => (int)$tier,
            'odds_fp' => (int)$oddsFp,
            'chance_percent' => round($effectivePercent, 6),
            'conditional_percent' => round($conditionalPercent, 2)
        ];
    }

    return [
        'sigil_power' => $sigilPower,
        'base_one_in' => (int)$dropRate,
        'base_percent' => round($basePercent, 6),
        'tiers' => $tiers
    ];
}

/**
 * Build the sigil_drop_rates payload from a pre-computed per-player drop config.
 * Uses the full dynamic model (inventory-aware scaling + boost-pressure) rather
 * than the simpler sigil-power scalar used by getSigilDropRateMetadata().
 *
 * @param array $dropConfig Return value of Economy::computePerPlayerSigilDropConfig().
 * @return array
 */
function getSigilDropRateMetadataFromConfig(array $dropConfig) {
    $dropRate = max(1, (int)$dropConfig['drop_rate']);
    $tierOdds = $dropConfig['tier_odds'];
    $basePercent = 100.0 / $dropRate;
    $tiers = [];
    foreach ($tierOdds as $tier => $oddsFp) {
        $oddsFp = (int)$oddsFp;
        $conditionalPercent = ($oddsFp / 1000000) * 100;
        $effectivePercent = $basePercent * ($oddsFp / 1000000);
        $tiers[] = [
            'tier' => (int)$tier,
            'odds_fp' => $oddsFp,
            'chance_percent' => round($effectivePercent, 6),
            'conditional_percent' => round($conditionalPercent, 6),
        ];
    }
    return [
        'base_one_in' => $dropRate,
        'base_percent' => round($basePercent, 6),
        'tiers' => $tiers,
    ];
}

function calculatePlayerRatePerTick($season, $player, $participation, $activeBoosts) {
    if (!$season || !$participation) {
        return [
            'rate_per_tick' => 0,
            'gross_rate_per_tick' => 0,
            'hoarding_sink_per_tick' => 0,
            'net_rate_per_tick' => 0,
            'hoarding_sink_active' => false,
        ];
    }
    if (isPlayerFrozen((int)$player['player_id'], (int)$season['season_id'])) {
        return [
            'rate_per_tick' => 0,
            'gross_rate_per_tick' => 0,
            'hoarding_sink_per_tick' => 0,
            'net_rate_per_tick' => 0,
            'hoarding_sink_active' => false,
        ];
    }

    $totalModFp = (int)($activeBoosts['total_modifier_fp'] ?? 0);
    $rates = Economy::calculateRateBreakdown($season, $player, $participation, $totalModFp, false);

    $grossRate = round(((int)$rates['gross_rate_fp']) / FP_SCALE, 2);
    $sinkPerTick = max(0, (int)$rates['sink_per_tick']);
    $netRate = round(((int)$rates['net_rate_fp']) / FP_SCALE, 2);

    return [
        // Preserve legacy key as player-facing gross rate.
        'rate_per_tick' => max(0, $grossRate),
        'gross_rate_per_tick' => max(0, $grossRate),
        'hoarding_sink_per_tick' => $sinkPerTick,
        'net_rate_per_tick' => max(0, $netRate),
        'hoarding_sink_active' => Economy::hoardingSinkEnabled($season) && $sinkPerTick > 0,
    ];
}

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
        $s['sigil_drop_rates'] = getSigilDropRateMetadata();
        
        // Remove binary seed from response
        unset($s['season_seed']);
    }
    $state['seasons'] = $seasons;
    
    if ($player) {
        $participation = null;
        $joinedSeason = null;
        if ($player['joined_season_id']) {
            $joinedSeason = $db->fetch(
                "SELECT * FROM seasons WHERE season_id = ?",
                [$player['joined_season_id']]
            );
            $participation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$player['player_id'], $player['joined_season_id']]
            );
        }

        $activeBoosts = getActiveBoosts($player);
        $rateMetrics = calculatePlayerRatePerTick($joinedSeason, $player, $participation, $activeBoosts);
        
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
                    (int)$participation['sigils_t5'],
                    (int)($participation['sigils_t6'] ?? 0)
                ],
                'sigils_total' => (int)$participation['sigils_t1'] + (int)$participation['sigils_t2'] + (int)$participation['sigils_t3'] + (int)$participation['sigils_t4'] + (int)$participation['sigils_t5'] + (int)($participation['sigils_t6'] ?? 0),
                'participation_time' => (int)$participation['participation_time_total'],
                'active_ticks' => (int)$participation['active_ticks_total'],
                'lock_in_stars' => $participation['lock_in_snapshot_seasonal_stars'],
                'sigil_drops_total' => (int)($participation['sigil_drops_total'] ?? 0),
                'eligible_ticks_since_last_drop' => (int)($participation['eligible_ticks_since_last_drop'] ?? 0),
                'combine_recipes' => getCombineRecipesForParticipation($participation),
                'tier6_visible' => shouldRevealTier6($participation),
                'can_freeze' => ((int)($participation['sigils_t6'] ?? 0) > 0),
                'freeze' => getFreezeStatusForPlayer((int)$player['player_id'], (int)$player['joined_season_id']),
                'rate_per_tick' => (float)$rateMetrics['rate_per_tick'],
                'gross_rate_per_tick' => (float)$rateMetrics['gross_rate_per_tick'],
                'hoarding_sink_per_tick' => (int)$rateMetrics['hoarding_sink_per_tick'],
                'net_rate_per_tick' => (float)$rateMetrics['net_rate_per_tick'],
                'hoarding_sink_active' => (bool)$rateMetrics['hoarding_sink_active'],
            ] : null,
            'active_boosts' => $activeBoosts,
            'recent_drops' => ($player['joined_season_id']) ? getRecentSigilDrops($player) : [],
            'notifications' => Notifications::listForPlayer($player['player_id'], 50),
            'notifications_unread_count' => Notifications::unreadCount($player['player_id']),
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
    $season['sigil_drop_rates'] = getSigilDropRateMetadata();

    if ($player && (int)$player['joined_season_id'] === (int)$seasonId && (int)$player['participation_enabled'] === 1) {
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [(int)$player['player_id'], (int)$seasonId]
        );
        if ($participation) {
            // Surface effective rates from the same live model used by gameplay:
            // inventory-aware per-tier scaling + boost-activity pressure on denominator.
            $playerBoosts = getActiveBoosts($player);
            $boostModFp = (int)($playerBoosts['total_modifier_fp'] ?? 0);
            $dropConfig = Economy::computePerPlayerSigilDropConfig($participation, $boostModFp);
            $season['sigil_drop_rates'] = getSigilDropRateMetadataFromConfig($dropConfig);
            $season['player_combine_recipes'] = getCombineRecipesForParticipation($participation);
            $season['player_tier6_visible'] = shouldRevealTier6($participation);
            $season['player_can_freeze'] = ((int)($participation['sigils_t6'] ?? 0) > 0);
            $season['player_freeze'] = getFreezeStatusForPlayer((int)$player['player_id'], (int)$seasonId);
        }
    }
    
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

function getLeaderboard($seasonId, int $limit = 0) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return [];
    $limit = max(0, min(LEADERBOARD_MAX_LIMIT, (int)$limit));
    $limitClause = $limit > 0 ? " LIMIT ?" : "";

    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Active' || $status === 'Blackout') {
        $gameTime = GameTime::now();
        $rows = $db->fetchAll(
            "SELECT p.player_id, p.handle,
                    COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
                    COALESCE(sp.coins, 0) AS coins,
                    sp.participation_time_total,
                    sp.final_rank,
                    sp.lock_in_effect_tick,
                    COALESCE(sp.end_membership, 0) AS end_membership,
                    sp.badge_awarded,
                    COALESCE(sp.global_stars_earned, 0) AS global_stars_earned,
                    COALESCE(sp.participation_bonus, 0) AS participation_bonus,
                    COALESCE(sp.placement_bonus, 0) AS placement_bonus,
                    p.activity_state, p.online_current,
                    COALESCE(frz.is_frozen, 0) AS is_frozen,
                    ROUND(
                        LEAST(
                            COALESCE(self_b.self_fp, 0) + glob_b.global_fp,
                            4000000
                        ) / 10000, 1
                    ) AS boost_pct
                    , LEAST(
                        COALESCE(self_b.self_fp, 0) + glob_b.global_fp,
                        4000000
                    ) AS boost_mod_fp
             FROM players p
             LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
             LEFT JOIN (
                 SELECT player_id, SUM(modifier_fp) AS self_fp
                 FROM active_boosts
                 WHERE season_id = ? AND is_active = 1 AND scope = 'SELF' AND expires_tick >= ?
                 GROUP BY player_id
             ) self_b ON self_b.player_id = p.player_id
             LEFT JOIN (
                 SELECT target_player_id AS player_id, 1 AS is_frozen
                 FROM active_freezes
                 WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                 GROUP BY target_player_id
             ) frz ON frz.player_id = p.player_id
             CROSS JOIN (
                 SELECT COALESCE(SUM(modifier_fp), 0) AS global_fp
                 FROM active_boosts
                 WHERE season_id = ? AND is_active = 1 AND scope = 'GLOBAL' AND expires_tick >= ?
              ) glob_b
              WHERE p.joined_season_id = ? AND p.participation_enabled = 1
              ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC{$limitClause}",
            $limit > 0
                ? [$seasonId, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId, $limit]
                : [$seasonId, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId]
        );
        foreach ($rows as &$row) {
            $playerShim = [
                'participation_enabled' => 1,
                'activity_state' => $row['activity_state'] ?? 'Offline',
            ];
            $participationShim = [
                'coins' => (int)($row['coins'] ?? 0),
                'participation_time_total' => (int)($row['participation_time_total'] ?? 0),
            ];
            $breakdown = Economy::calculateRateBreakdown(
                $season,
                $playerShim,
                $participationShim,
                (int)($row['boost_mod_fp'] ?? 0),
                ((int)($row['is_frozen'] ?? 0) > 0)
            );
            $row['rate_per_tick'] = round(((int)$breakdown['gross_rate_fp']) / FP_SCALE, 2);
            unset($row['boost_mod_fp']);
        }
        unset($row);
        return $rows;
    }

    $rows = $db->fetchAll(
        "SELECT sp.player_id, p.handle, sp.seasonal_stars, COALESCE(sp.coins, 0) AS coins, sp.final_rank,
                sp.lock_in_effect_tick, sp.end_membership, sp.badge_awarded,
                sp.global_stars_earned, sp.participation_bonus, sp.placement_bonus,
                p.activity_state, p.online_current,
                0 AS is_frozen,
                0.0 AS boost_pct
         FROM season_participation sp
         JOIN players p ON p.player_id = sp.player_id
         WHERE sp.season_id = ?
         AND (sp.seasonal_stars > 0 OR sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL OR sp.final_rank IS NOT NULL)
         ORDER BY sp.seasonal_stars DESC, sp.player_id ASC{$limitClause}",
        $limit > 0 ? [$seasonId, $limit] : [$seasonId]
    );
    foreach ($rows as &$row) {
        $row['rate_per_tick'] = 0;
    }
    unset($row);
    return $rows;
}

function normalizeSigilCounts($value): array {
    if (!is_array($value)) return [0, 0, 0, 0, 0, 0];
    $normalized = [0, 0, 0, 0, 0, 0];
    for ($i = 0; $i < 6; $i++) {
        $normalized[$i] = max(0, (int)($value[$i] ?? 0));
    }
    return $normalized;
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
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_admin_post, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'GLOBAL' AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [CHAT_MAX_ROWS]
        );
        foreach ($messages as &$m) {
            $m['created_at'] = iso_utc_datetime($m['created_at'] ?? null);
        }
        return $messages;
    }
    
    if ($channelKind === 'SEASON' && $seasonId) {
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'SEASON' AND season_id = ? AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [$seasonId, CHAT_MAX_ROWS]
        );
        foreach ($messages as &$m) {
            $m['created_at'] = iso_utc_datetime($m['created_at'] ?? null);
        }
        return $messages;
    }
    
    return [];
}

function getProfile($viewer, $targetId) {
    $db = Database::getInstance();
    $target = $db->fetch(
        "SELECT player_id, handle, role, global_stars, profile_visibility, created_at, profile_deleted_at,
                joined_season_id, participation_enabled
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
    $target['active_participation'] = null;
    // Normalise DATETIME to ISO 8601 UTC so JS Date() parses it unambiguously.
    $target['created_at'] = iso_utc_datetime($target['created_at'] ?? null);

    if (!empty($target['joined_season_id']) && (int)$target['participation_enabled'] === 1) {
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$target['joined_season_id']]);
        if ($season) {
            $status = GameTime::getSeasonStatus($season);
            if ($status === 'Active') {
                $participation = $db->fetch(
                    "SELECT coins, sigils_t1, sigils_t2, sigils_t3, sigils_t4, sigils_t5, sigils_t6
                     FROM season_participation
                     WHERE player_id = ? AND season_id = ?",
                    [$targetId, $target['joined_season_id']]
                );
                if ($participation) {
                    $target['active_participation'] = [
                        'season_id' => (int)$target['joined_season_id'],
                        'coins' => (int)$participation['coins'],
                        'sigils' => [
                            (int)$participation['sigils_t1'],
                            (int)$participation['sigils_t2'],
                            (int)$participation['sigils_t3'],
                            (int)$participation['sigils_t4'],
                            (int)$participation['sigils_t5'],
                            (int)($participation['sigils_t6'] ?? 0)
                        ]
                    ];
                }
            }
        }
    }
    
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
        $boost = BoostCatalog::normalize($boost);
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
    // expires_at_real is computed as the absolute real Unix timestamp of the start of
    // tick (expires_tick + 1) – the first moment at which gameTime exceeds expires_tick
    // and the boost is no longer active.  Using the tick-boundary formula makes this
    // value stable: it does not change between API calls, even if the game tick advances
    // between the purchase request and the subsequent active_boosts query.
    // remaining_real_seconds is derived from the same stable timestamp so it matches.
    foreach ($selfBoosts as &$b) {
        $b = BoostCatalog::normalize($b);
        $expiresAtReal = GameTime::tickStartRealUnix((int)$b['expires_tick'] + 1);
        $b['expires_at_real'] = $expiresAtReal;
        $b['remaining_real_seconds'] = max(0, $expiresAtReal - $serverNowUnix);
    }
    unset($b);
    foreach ($globalBoosts as &$b) {
        $b = BoostCatalog::normalize($b);
        $expiresAtReal = GameTime::tickStartRealUnix((int)$b['expires_tick'] + 1);
        $b['expires_at_real'] = $expiresAtReal;
        $b['remaining_real_seconds'] = max(0, $expiresAtReal - $serverNowUnix);
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
    
    $drops = $db->fetchAll(
        "SELECT tier, source, drop_tick, created_at 
         FROM sigil_drop_log 
         WHERE player_id = ? AND season_id = ?
         ORDER BY drop_tick DESC LIMIT 20",
        [$player['player_id'], $seasonId]
    );
    foreach ($drops as &$d) {
        $d['created_at'] = iso_utc_datetime($d['created_at'] ?? null);
    }
    return $drops;
}

function getNotificationIdsFromInput($input) {
    if (isset($input['notification_ids']) && is_array($input['notification_ids'])) {
        return $input['notification_ids'];
    }
    if (isset($input['notification_id'])) {
        return [$input['notification_id']];
    }
    return [];
}

function getCombineRecipesForParticipation($participation) {
    $recipes = [];
    foreach (SIGIL_COMBINE_RECIPES as $fromTier => $required) {
        $fromCol = 'sigils_t' . (int)$fromTier;
        $owned = (int)($participation[$fromCol] ?? 0);
        $recipes[] = [
            'from_tier' => (int)$fromTier,
            'to_tier' => (int)$fromTier + 1,
            'required' => (int)$required,
            'owned' => $owned,
            'can_combine' => $owned >= (int)$required,
        ];
    }
    return $recipes;
}

function shouldRevealTier6($participation) {
    $ownedT6 = (int)($participation['sigils_t6'] ?? 0);
    return $ownedT6 > 0;
}

function isPlayerFrozen($playerId, $seasonId) {
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    $row = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM active_freezes WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ?",
        [(int)$playerId, (int)$seasonId, (int)$gameTime]
    );
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function getFreezeStatusForPlayer($playerId, $seasonId) {
    if (!$seasonId) {
        return [
            'is_frozen' => false,
            'remaining_ticks' => 0,
            'expires_tick' => null,
            'expires_at_real' => null,
            'remaining_real_seconds' => 0,
        ];
    }
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    $serverNowUnix = time();
    $row = $db->fetch(
        "SELECT expires_tick FROM active_freezes
         WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ?
         ORDER BY expires_tick DESC LIMIT 1",
        [(int)$playerId, (int)$seasonId, (int)$gameTime]
    );
    if (!$row) {
        return [
            'is_frozen' => false,
            'remaining_ticks' => 0,
            'expires_tick' => null,
            'expires_at_real' => null,
            'remaining_real_seconds' => 0,
        ];
    }
    $expiresTick = (int)$row['expires_tick'];
    $expiresAtReal = GameTime::tickStartRealUnix($expiresTick + 1);
    return [
        'is_frozen' => true,
        'remaining_ticks' => max(0, $expiresTick - (int)$gameTime),
        'expires_tick' => $expiresTick,
        'expires_at_real' => $expiresAtReal,
        'remaining_real_seconds' => max(0, $expiresAtReal - $serverNowUnix),
    ];
}

// ==================== ECONOMIC CONSEQUENCE PREVIEW HELPERS ====================

/**
 * Compute a risk object for an action.
 *
 * @param float $spendFraction  0.0–1.0: fraction of relevant balance consumed.
 * @param array $extraFlags     Additional string flags to include.
 * @return array{severity: string, flags: array, explain: string}
 */
function computeEconomicRisk(float $spendFraction, array $extraFlags = []): array {
    $flags = $extraFlags;
    $severity = 'low';
    $lines = [];

    if ($spendFraction >= 0.80) {
        $severity = 'high';
        $flags[] = 'large_spend';
        $lines[] = sprintf('Action spends %.0f%% of your available balance.', $spendFraction * 100);
    } elseif ($spendFraction >= 0.50) {
        $severity = 'medium';
        $flags[] = 'moderate_spend';
        $lines[] = sprintf('Action spends %.0f%% of your available balance.', $spendFraction * 100);
    }

    if (in_array('last_sigil', $extraFlags, true)) {
        $severity = ($severity === 'low') ? 'medium' : $severity;
        $lines[] = 'This will consume your last sigil of this tier.';
    }
    if (in_array('no_boost_active', $extraFlags, true)) {
        $lines[] = 'No existing boost of this type is active; this will start a new one.';
    }
    if (in_array('time_extend', $extraFlags, true)) {
        $lines[] = 'This extends an existing boost duration.';
    }

    $explain = implode(' ', $lines) ?: 'Action is within normal spend parameters.';
    return [
        'severity' => $severity,
        'flags' => array_values($flags),
        'explain' => $explain,
    ];
}

/**
 * Build a standard preview payload.
 */
function buildPreviewPayload(
    int $estimatedTotalCost,
    int $estimatedFee,
    int $estimatedPriceImpactBp,
    int $postBalanceEstimate,
    array $risk,
    string $type = 'coins'
): array {
    $impactPct = round($estimatedPriceImpactBp / 100, 4);
    return [
        'estimated_total_cost'     => $estimatedTotalCost,
        'estimated_fee'            => $estimatedFee,
        'estimated_price_impact_bp'  => $estimatedPriceImpactBp,
        'estimated_price_impact_pct' => $impactPct,
        'post_balance_estimate'    => max(0, $postBalanceEstimate),
        'balance_type'             => $type,
        'risk'                     => $risk,
        'requires_explicit_confirm' => ($risk['severity'] !== 'low'),
    ];
}

/**
 * Preview a star purchase without executing it.
 */
function previewStarPurchase(array $player, int $starsRequested): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
    }

    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Blackout') {
        return ['error' => 'Star purchases are not available during blackout', 'reason_code' => 'blackout_disallows_action'];
    }

    if ($starsRequested <= 0) {
        return ['error' => 'Must request a positive star quantity'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $starPrice = (int)$season['current_star_price'];
    if ($starPrice <= 0) return ['error' => 'Invalid star price'];

    $estimatedCost = $starsRequested * $starPrice;
    $coins = (int)$participation['coins'];
    $totalSupply = max(1, (int)$season['total_coins_supply']);
    $priceImpactBp = (int)round(($estimatedCost / $totalSupply) * 10000);

    $spendFraction = $coins > 0 ? min(1.0, $estimatedCost / $coins) : 1.0;
    $risk = computeEconomicRisk($spendFraction);

    $payload = buildPreviewPayload(
        $estimatedCost,
        0,
        $priceImpactBp,
        $coins - $estimatedCost,
        $risk
    );
    $payload['stars_requested'] = $starsRequested;
    $payload['star_price'] = $starPrice;
    $payload['coins_available'] = $coins;

    return array_merge(['success' => true, 'preview_type' => 'star_purchase'], $payload);
}

/**
 * Gated star purchase: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedStarPurchase(array $player, int $starsRequested, bool $confirmed): array {
    $preview = previewStarPurchase($player, $starsRequested);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This action has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::purchaseStars($player['player_id'], $starsRequested);
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'executed_total_cost'     => (int)($result['coins_spent'] ?? 0),
            'executed_fee'            => 0,
            'executed_price_impact_bp' => $preview['estimated_price_impact_bp'],
            'executed_price_impact_pct' => $preview['estimated_price_impact_pct'],
            'post_balance_estimate'   => max(0, $preview['coins_available'] - (int)($result['coins_spent'] ?? 0)),
            'stars_purchased'         => (int)($result['stars_purchased'] ?? 0),
        ];
    }
    return $result;
}

/**
 * Preview a trade without executing it.
 */
function previewTrade(
    array $player,
    int $acceptorId,
    int $sideACoins,
    array $sideASigils,
    int $sideBCoins,
    array $sideBSigils
): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot trade while idle', 'reason_code' => 'idle_gated'];
    }

    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Blackout') {
        return ['error' => 'Trading is not available during blackout', 'reason_code' => 'blackout_disallows_action'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $aCoins = max(0, (int)$sideACoins);
    $bCoins = max(0, (int)$sideBCoins);
    $aSigils = normalizeSigilCounts($sideASigils);
    $bSigils = normalizeSigilCounts($sideBSigils);
    $declaredValue = Economy::calculateTradeValue($season, $aCoins, $aSigils, $bCoins, $bSigils);
    $fee = Economy::calculateTradeFee($season, $declaredValue);
    $totalCost = $aCoins + $fee;
    // Both initiator and acceptor pay the same locked fee on accept.
    $estimatedBurn = $fee * 2;

    $coins = (int)$participation['coins'];
    $totalSupply = max(1, (int)$season['total_coins_supply']);
    $priceImpactBp = (int)round(($estimatedBurn / $totalSupply) * 10000);

    $spendFraction = $coins > 0 ? min(1.0, $totalCost / $coins) : 1.0;
    $extraFlags = [];
    if ($fee > 0) $extraFlags[] = 'fee_applies';
    $risk = computeEconomicRisk($spendFraction, $extraFlags);

    $payload = buildPreviewPayload(
        $totalCost,
        $fee,
        $priceImpactBp,
        $coins - $totalCost,
        $risk
    );
    $payload['declared_value'] = $declaredValue;
    $payload['side_a_coins'] = $aCoins;
    $payload['side_b_coins'] = $bCoins;
    $payload['estimated_burn_on_accept'] = $estimatedBurn;
    $payload['coins_escrowed'] = $aCoins;
    $payload['coins_available'] = $coins;

    return array_merge(['success' => true, 'preview_type' => 'trade'], $payload);
}

/**
 * Gated trade initiate: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedTradeInitiate(
    array $player,
    int $acceptorId,
    int $sideACoins,
    array $sideASigils,
    int $sideBCoins,
    array $sideBSigils,
    bool $confirmed
): array {
    $preview = previewTrade($player, $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This trade has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::tradeInitiate($player['player_id'], $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils);
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'executed_total_cost'      => (int)($sideACoins) + (int)($result['fee'] ?? 0),
            'executed_fee'             => (int)($result['fee'] ?? 0),
            'executed_price_impact_bp' => $preview['estimated_price_impact_bp'],
            'executed_price_impact_pct' => $preview['estimated_price_impact_pct'],
            'post_balance_estimate'    => max(0, $preview['coins_available'] - (int)$sideACoins - (int)($result['fee'] ?? 0)),
            'declared_value'           => (int)($result['declared_value'] ?? 0),
        ];
    }
    return $result;
}

/**
 * Preview a boost activation without executing it.
 */
function previewBoostActivate(array $player, int $boostId, string $purchaseKind): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
    }

    $purchaseKind = ($purchaseKind === 'time') ? 'time' : 'power';
    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Blackout' || $status !== 'Active') {
        return ['error' => 'Boost activation is only available during active season', 'reason_code' => 'blackout_disallows_action'];
    }

    $boost = $db->fetch("SELECT * FROM boost_catalog WHERE boost_id = ?", [$boostId]);
    if (!$boost) return ['error' => 'Boost not found'];
    $boost = BoostCatalog::normalize($boost);

    $tierRequired = (int)$boost['tier_required'];
    $sigilCost = (int)$boost['sigil_cost'];

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $sigilCol = "sigils_t{$tierRequired}";
    $sigilsOwned = (int)($participation[$sigilCol] ?? 0);
    if ($sigilsOwned < $sigilCost) {
        return ['error' => "Insufficient Tier {$tierRequired} Sigils"];
    }

    $gameTime = GameTime::now();
    $activeRow = $db->fetch(
        "SELECT * FROM active_boosts WHERE player_id = ? AND season_id = ? AND boost_id = ? AND is_active = 1 AND expires_tick >= ? ORDER BY expires_tick DESC LIMIT 1",
        [$playerId, $seasonId, $boostId, $gameTime]
    );

    $extraFlags = [];
    if (!$activeRow) $extraFlags[] = 'no_boost_active';
    if ($purchaseKind === 'time') $extraFlags[] = 'time_extend';
    if ($sigilsOwned === $sigilCost) $extraFlags[] = 'last_sigil';

    $spendFraction = $sigilsOwned > 0 ? min(1.0, $sigilCost / $sigilsOwned) : 1.0;
    $risk = computeEconomicRisk($spendFraction, $extraFlags);

    $payload = buildPreviewPayload(
        $sigilCost,
        0,
        0,
        max(0, $sigilsOwned - $sigilCost),
        $risk,
        "sigils_t{$tierRequired}"
    );
    $payload['boost_id']       = $boostId;
    $payload['boost_name']     = $boost['name'];
    $payload['tier_required']  = $tierRequired;
    $payload['sigil_cost']     = $sigilCost;
    $payload['sigils_owned']   = $sigilsOwned;
    $payload['purchase_kind']  = $purchaseKind;
    $payload['modifier_fp']    = (int)$boost['modifier_fp'];
    $payload['modifier_percent'] = round((int)$boost['modifier_fp'] / 10000, 1);

    return array_merge(['success' => true, 'preview_type' => 'boost_activate'], $payload);
}

/**
 * Gated boost activate: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedBoostActivate(array $player, int $boostId, string $purchaseKind, bool $confirmed): array {
    $preview = previewBoostActivate($player, $boostId, $purchaseKind);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This boost activation has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::purchaseBoost($player['player_id'], $boostId, $purchaseKind);
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'executed_total_cost'      => (int)($result['sigils_consumed'] ?? 0),
            'executed_fee'             => 0,
            'executed_price_impact_bp' => 0,
            'executed_price_impact_pct' => 0.0,
            'post_balance_estimate'    => (int)$preview['post_balance_estimate'],
            'tier_consumed'            => (int)($result['tier_consumed'] ?? 0),
            'sigils_consumed'          => (int)($result['sigils_consumed'] ?? 0),
        ];
    }
    return $result;
}
