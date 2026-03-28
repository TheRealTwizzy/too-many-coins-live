<?php
/**
 * Too Many Coins - Database Initialization
 * Run this once after deploying to set up the database schema and seed data.
 * Access via: /api/index.php?action=init_db&secret=YOUR_INIT_SECRET
 * Or run from CLI: php init_db.php
 */

require_once __DIR__ . '/includes/config.php';

// Security: only allow via CLI or with correct secret
$isCli = (php_sapi_name() === 'cli');
$secret = $_GET['secret'] ?? '';
$initSecret = getenv('TMC_INIT_SECRET') ?: 'tmc_init_2024';

if (!$isCli && $secret !== $initSecret) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden. Provide ?secret=YOUR_INIT_SECRET']));
}

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );

    $results = [];

    // Check if tables already exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) > 5) {
        echo json_encode(['status' => 'already_initialized', 'tables' => $tables]);
        exit;
    }

    // Execute schema
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schema);
    $results[] = 'Schema loaded';

    // Execute seed data
    $seed = file_get_contents(__DIR__ . '/seed_data.sql');
    $pdo->exec($seed);
    $results[] = 'Seed data loaded';

    // Execute boost/drop migration
    $migration = file_get_contents(__DIR__ . '/migration_boosts_drops.sql');
    $pdo->exec($migration);
    $results[] = 'Boost/drop migration loaded';

    // Add canonical sigil drop columns to season_participation if they don't exist
    try {
        $pdo->exec("ALTER TABLE season_participation ADD COLUMN sigil_drops_total INT NOT NULL DEFAULT 0");
        $results[] = 'Added sigil_drops_total column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            $results[] = 'sigil_drops_total: ' . $e->getMessage();
        } else {
            $results[] = 'sigil_drops_total column already exists';
        }
    }

    try {
        $pdo->exec("ALTER TABLE season_participation ADD COLUMN eligible_ticks_since_last_drop BIGINT NOT NULL DEFAULT 0");
        $results[] = 'Added eligible_ticks_since_last_drop column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            $results[] = 'eligible_ticks_since_last_drop: ' . $e->getMessage();
        } else {
            $results[] = 'eligible_ticks_since_last_drop column already exists';
        }
    }

    try {
        $pdo->exec("ALTER TABLE season_participation ADD COLUMN coins_fractional_fp BIGINT NOT NULL DEFAULT 0");
        $results[] = 'Added coins_fractional_fp column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            $results[] = 'coins_fractional_fp: ' . $e->getMessage();
        } else {
            $results[] = 'coins_fractional_fp column already exists';
        }
    }

    // Verify
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $boostCount = $pdo->query("SELECT COUNT(*) FROM boost_catalog")->fetchColumn();
    $cosmeticCount = $pdo->query("SELECT COUNT(*) FROM cosmetic_catalog")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'results' => $results,
        'tables' => $tables,
        'boost_catalog_count' => (int)$boostCount,
        'cosmetics_count' => (int)$cosmeticCount
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
