<?php
/**
 * Too Many Coins - Database Connection
 */
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private static $hotfixChecked = false;
    private $pdo;

    private function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
            die(json_encode(['error' => 'Database connection failed']));
        }

        $this->applyPendingHotfixMigrations();
    }

    private function applyPendingHotfixMigrations() {
        if (!defined('TMC_AUTO_SQL_HOTFIX') || !TMC_AUTO_SQL_HOTFIX) {
            return;
        }

        if (self::$hotfixChecked) {
            return;
        }

        if (!$this->isSchemaReadyForHotfixes()) {
            self::$hotfixChecked = true;
            return;
        }

        $this->ensureMigrationTable();

        $files = $this->getHotfixFiles();
        foreach ($files as $filePath) {
            $migrationName = basename($filePath);
            $checksum = hash_file('sha256', $filePath);
            if ($checksum === false) {
                throw new RuntimeException('Unable to hash migration file: ' . $migrationName);
            }

            $existing = $this->fetch(
                'SELECT checksum FROM schema_migrations WHERE migration_name = ?',
                [$migrationName]
            );

            if ($existing) {
                if (($existing['checksum'] ?? '') !== $checksum) {
                    error_log('Hotfix checksum changed for ' . $migrationName . '. Create a new hotfix filename for new patches.');
                }
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration file: ' . $migrationName);
            }

            try {
                $this->pdo->exec($sql);
                $this->query(
                    'INSERT INTO schema_migrations (migration_name, checksum) VALUES (?, ?)',
                    [$migrationName, $checksum]
                );
            } catch (Throwable $e) {
                throw new RuntimeException('Failed applying migration ' . $migrationName . ': ' . $e->getMessage(), 0, $e);
            }
        }

        self::$hotfixChecked = true;
    }

    private function isSchemaReadyForHotfixes() {
        try {
            $exists = $this->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = 'server_state'",
                [DB_NAME]
            );
            return ((int)($exists['c'] ?? 0)) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ensureMigrationTable() {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    private function getHotfixFiles() {
        $repoRoot = realpath(__DIR__ . '/..');
        if ($repoRoot === false) {
            return [];
        }

        $files = glob($repoRoot . DIRECTORY_SEPARATOR . 'migration_*_hotfix.sql');
        if (!is_array($files)) {
            return [];
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance() {
        self::$instance = null;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}
