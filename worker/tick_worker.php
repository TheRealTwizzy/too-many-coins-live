<?php
/**
 * Too Many Coins - Dedicated Tick Worker
 *
 * Runs TickEngine::processTicks() on a fixed interval without HTTP traffic.
 * Intended for a separate Dokploy worker service using the same image as web.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/tick_engine.php';

$intervalSeconds = max(1, (int)(getenv('TMC_WORKER_INTERVAL_SECONDS') ?: getenv('TMC_TICK_REAL_SECONDS') ?: 60));
$startDelaySeconds = max(0, (int)(getenv('TMC_WORKER_START_DELAY_SECONDS') ?: 0));
$runOnce = filter_var(getenv('TMC_WORKER_RUN_ONCE') ?: '0', FILTER_VALIDATE_BOOLEAN);
$errorBackoffSeconds = max(1, (int)(getenv('TMC_WORKER_ERROR_BACKOFF_SECONDS') ?: 2));

if ($startDelaySeconds > 0) {
    error_log("[tick-worker] startup delay: {$startDelaySeconds}s");
    sleep($startDelaySeconds);
}

error_log("[tick-worker] starting (interval={$intervalSeconds}s, run_once=" . ($runOnce ? 'true' : 'false') . ")");

while (true) {
    $cycleStarted = microtime(true);
    $hadError = false;

    try {
        $db = Database::getInstance();

        // Guard against concurrent workers across replicas.
        $lockRow = $db->fetch("SELECT GET_LOCK('tmc_tick_worker', 0) AS got_lock");
        $hasLock = isset($lockRow['got_lock']) && (int)$lockRow['got_lock'] === 1;

        if ($hasLock) {
            try {
                TickEngine::processTicks();
            } finally {
                try {
                    $db->fetch("SELECT RELEASE_LOCK('tmc_tick_worker') AS released_lock");
                } catch (Throwable $releaseError) {
                    // If connection drops, MySQL releases session locks automatically.
                    error_log('[tick-worker] lock release check failed: ' . $releaseError->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        $hadError = true;
        // Reset singleton so a fresh DB connection can be created next loop.
        Database::resetInstance();
        error_log('[tick-worker] error: ' . $e->getMessage());
    }

    if ($runOnce) {
        error_log('[tick-worker] run once complete, exiting');
        exit(0);
    }

    $elapsed = microtime(true) - $cycleStarted;
    $targetSleep = $intervalSeconds - $elapsed;
    $sleepSeconds = $hadError ? max($errorBackoffSeconds, $targetSleep) : max(0.0, $targetSleep);

    if ($sleepSeconds > 0) {
        usleep((int)round($sleepSeconds * 1000000));
    }
}
