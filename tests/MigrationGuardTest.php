<?php
/**
 * Tests for the migration guard / failure-recording system.
 *
 * Root cause: migrations using ADD COLUMN IF NOT EXISTS fail on MySQL variants
 * that do not support that syntax. Before the fix, a failed migration was not
 * recorded in schema_migrations, so every new PHP-FPM worker that spawned
 * would retry the same broken migration, spamming logs and potentially
 * blocking economy/tick paths.
 *
 * Fix: Database::applyPendingMigrations() now records a `status='failed'` row
 * when a migration fails, preventing retries on subsequent requests.
 *
 * These tests validate the guard logic and migration SQL file compatibility
 * without requiring a live database connection.
 */

use PHPUnit\Framework\TestCase;

/**
 * In-memory simulation of the schema_migrations tracking table and the
 * migration-application loop in Database::applyPendingMigrations().
 *
 * Mirrors the key logic:
 *  - If a row for migration_name exists (any status) → skip.
 *  - On success  → insert status='applied'.
 *  - On failure  → insert status='failed' (the guard that prevents retry loops).
 */
class MigrationRunnerSimulation
{
    /** @var array<string,array{checksum:string,status:string}> */
    private array $recorded = [];

    /** @var string[] Names of migrations that should simulate a SQL error. */
    private array $failingMigrations;

    /** @var string[] Log of error_log()-equivalent messages produced. */
    public array $log = [];

    /** @var string[] Names of migrations that were actually executed (exec called). */
    public array $executed = [];

    public function __construct(array $failingMigrations = [])
    {
        $this->failingMigrations = $failingMigrations;
    }

    /**
     * Simulate applying a single migration file.
     */
    public function applyMigration(string $migrationName, string $checksum): void
    {
        // If already recorded (applied or failed) → skip without executing.
        if (isset($this->recorded[$migrationName])) {
            $existing = $this->recorded[$migrationName];
            if ($existing['checksum'] !== $checksum) {
                $this->log[] = 'Migration checksum changed for ' . $migrationName
                    . '. Create a new migration filename for new patches.';
            }
            return;
        }

        // Simulate exec().
        $this->executed[] = $migrationName;

        if (in_array($migrationName, $this->failingMigrations, true)) {
            $this->log[] = 'Failed applying migration ' . $migrationName . ': simulated SQL error';
            // Guard: record as failed so subsequent workers/requests don't retry.
            $this->recorded[$migrationName] = ['checksum' => $checksum, 'status' => 'failed'];
            return;
        }

        $this->recorded[$migrationName] = ['checksum' => $checksum, 'status' => 'applied'];
    }

    public function getStatus(string $migrationName): ?string
    {
        return $this->recorded[$migrationName]['status'] ?? null;
    }

    public function hasRecord(string $migrationName): bool
    {
        return isset($this->recorded[$migrationName]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class MigrationGuardTest extends TestCase
{
    // ── Guard / retry-loop prevention ──────────────────────────────────────

    public function testFailedMigrationIsRecordedAndNotRetriedOnNextRequest(): void
    {
        $runner = new MigrationRunnerSimulation(['migration_bad.sql']);

        // First request: migration fails, guard records it.
        $runner->applyMigration('migration_bad.sql', 'abc123');

        $this->assertSame('failed', $runner->getStatus('migration_bad.sql'));
        $this->assertCount(1, $runner->executed, 'Migration should be executed exactly once.');

        // Second request (new worker): same migration must NOT be retried.
        $runner->applyMigration('migration_bad.sql', 'abc123');

        $this->assertCount(1, $runner->executed, 'Failed migration must not be retried.');
    }

    public function testSuccessfulMigrationIsRecordedAsApplied(): void
    {
        $runner = new MigrationRunnerSimulation([]);

        $runner->applyMigration('migration_good.sql', 'deadbeef');

        $this->assertSame('applied', $runner->getStatus('migration_good.sql'));
        $this->assertContains('migration_good.sql', $runner->executed);
    }

    public function testAppliedMigrationIsSkippedOnSubsequentRun(): void
    {
        $runner = new MigrationRunnerSimulation([]);

        $runner->applyMigration('migration_good.sql', 'deadbeef');
        $runner->applyMigration('migration_good.sql', 'deadbeef');

        $this->assertCount(1, $runner->executed, 'Applied migration must not run twice.');
    }

    public function testChecksumChangeOnRecordedMigrationLogsWarningAndSkips(): void
    {
        $runner = new MigrationRunnerSimulation([]);

        $runner->applyMigration('migration_hotfix.sql', 'hash1');
        // Simulate file modification after initial apply.
        $runner->applyMigration('migration_hotfix.sql', 'hash2_changed');

        $this->assertCount(1, $runner->executed, 'Modified migration must not be re-executed.');
        $this->assertStringContainsString(
            'Create a new migration filename',
            implode(' ', $runner->log),
            'Checksum change must produce a warning.'
        );
    }

    public function testChecksumChangeOnFailedMigrationLogsWarningAndSkips(): void
    {
        $runner = new MigrationRunnerSimulation(['migration_broken.sql']);

        $runner->applyMigration('migration_broken.sql', 'hash1');
        // Operator edits the broken file (wrong fix attempt).
        $runner->applyMigration('migration_broken.sql', 'hash2_changed');

        $this->assertCount(1, $runner->executed, 'Previously-failed migration must not run again after checksum change.');
        $this->assertStringContainsString('Create a new migration filename', implode(' ', $runner->log));
    }

    public function testNewReplacementMigrationRunsAfterOriginalFailure(): void
    {
        $runner = new MigrationRunnerSimulation(['migration_20260329_original.sql']);

        // Original fails.
        $runner->applyMigration('migration_20260329_original.sql', 'h1');
        // New compat file should run successfully.
        $runner->applyMigration('migration_20260329b_compat.sql', 'h2');

        $this->assertSame('failed',  $runner->getStatus('migration_20260329_original.sql'));
        $this->assertSame('applied', $runner->getStatus('migration_20260329b_compat.sql'));
        $this->assertContains('migration_20260329b_compat.sql', $runner->executed);
    }

    // ── Migration SQL file compatibility checks ────────────────────────────

    /**
     * Verify that the two originally-failing migration files still contain the
     * problematic ADD COLUMN IF NOT EXISTS syntax (they must NOT be edited per
     * checksum-immutability rules).
     */
    public function testOriginalFailingMigrationsContainIncompatibleSyntax(): void
    {
        $repoRoot = dirname(__DIR__);

        $incompatibleFiles = [
            'migration_20260329_sigil_drop_pacing_non_batch.sql',
            'migration_20260329_hoarding_sink_active_seasons_hotfix.sql',
        ];

        foreach ($incompatibleFiles as $filename) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . $filename;
            $this->assertFileExists($path, "Expected original migration file to remain: $filename");
            $sql = file_get_contents($path);
            $this->assertStringContainsString(
                'ADD COLUMN IF NOT EXISTS',
                $sql,
                "Original file $filename must remain unedited (checksum-immutability)."
            );
        }
    }

    /**
     * Verify that the compat replacement migration files do NOT use
     * ADD COLUMN IF NOT EXISTS in their executable SQL (comments excluded)
     * and instead use the INFORMATION_SCHEMA pattern.
     */
    public function testCompatMigrationsUseInformationSchemaNotIfNotExistsSyntax(): void
    {
        $repoRoot = dirname(__DIR__);

        $compatFiles = [
            'migration_20260329b_sigil_drop_pacing_compat.sql',
            'migration_20260329b_hoarding_sink_compat.sql',
        ];

        foreach ($compatFiles as $filename) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . $filename;
            $this->assertFileExists($path, "Expected compat migration file to exist: $filename");
            $sql = file_get_contents($path);

            // Strip single-line SQL comments before checking executable syntax.
            $executableSql = preg_replace('/^--.*$/m', '', $sql);

            $this->assertStringNotContainsString(
                'ADD COLUMN IF NOT EXISTS',
                $executableSql,
                "Compat migration $filename must not use ADD COLUMN IF NOT EXISTS in executable SQL."
            );

            $this->assertStringContainsString(
                'information_schema',
                strtolower($sql),
                "Compat migration $filename must use INFORMATION_SCHEMA guards."
            );
        }
    }

    /**
     * Verify the sort relationship between compat and original migration files.
     *
     * The 20260329b_ prefix sorts BEFORE 20260329_ under PHP natural sort
     * because 'b' (ASCII 0x62) precedes '_' (ASCII 0x5F), meaning the compat
     * file is attempted first. This is still safe: if the compat file runs
     * before the original, it adds the columns idempotently; the original then
     * fails (duplicate-column error) and is recorded as 'failed'. End state is
     * identical regardless of which runs first.
     */
    public function testCompatMigrationSortOrderIsDocumented(): void
    {
        $pairs = [
            [
                'original' => 'migration_20260329_sigil_drop_pacing_non_batch.sql',
                'compat'   => 'migration_20260329b_sigil_drop_pacing_compat.sql',
            ],
            [
                'original' => 'migration_20260329_hoarding_sink_active_seasons_hotfix.sql',
                'compat'   => 'migration_20260329b_hoarding_sink_compat.sql',
            ],
        ];

        foreach ($pairs as $pair) {
            $files = [$pair['original'], $pair['compat']];
            sort($files, SORT_NATURAL | SORT_FLAG_CASE);

            // PHP natural sort: '20260329b_' < '20260329_' (letter < underscore in natural collation)
            // so compat files sort first. This is safe: see docblock.
            $this->assertSame(
                $pair['compat'],
                $files[0],
                "Compat migration sorts before its original under PHP natural sort (both orderings are safe)."
            );
        }
    }

    /**
     * Verify that compat migration files contain DROP/CREATE PROCEDURE and CALL
     * (the stored-procedure guard pattern).
     */
    public function testCompatMigrationsUseProcedurePattern(): void
    {
        $repoRoot = dirname(__DIR__);

        $compatFiles = [
            'migration_20260329b_sigil_drop_pacing_compat.sql',
            'migration_20260329b_hoarding_sink_compat.sql',
        ];

        foreach ($compatFiles as $filename) {
            $path = $repoRoot . DIRECTORY_SEPARATOR . $filename;
            $sql  = strtolower(file_get_contents($path));

            $this->assertStringContainsString('create procedure', $sql,  "$filename must use CREATE PROCEDURE.");
            $this->assertStringContainsString('drop procedure',   $sql,  "$filename must clean up the procedure.");
            $this->assertStringContainsString('call ',            $sql,  "$filename must CALL the procedure.");
        }
    }

    // ── schema_migrations status column ───────────────────────────────────

    public function testSchemaForNewDeploymentsIncludesStatusColumn(): void
    {
        // Inspect the CREATE TABLE statement embedded in database.php to confirm
        // the status column is present for fresh installs.
        $dbPhp = file_get_contents(dirname(__DIR__) . '/includes/database.php');

        $this->assertStringContainsString(
            "status VARCHAR(16)",
            $dbPhp,
            "schema_migrations CREATE TABLE must include status column for new installs."
        );
    }

    public function testDatabasePhpBackfillsStatusColumnOnExistingDeployments(): void
    {
        // Confirm that ensureMigrationTable() queries information_schema to add
        // the status column if it does not already exist (backward compat).
        $dbPhp = file_get_contents(dirname(__DIR__) . '/includes/database.php');

        $this->assertStringContainsString(
            "COLUMN_NAME = 'status'",
            $dbPhp,
            "ensureMigrationTable() must check information_schema for the status column."
        );

        $this->assertStringContainsString(
            "ADD COLUMN status",
            $dbPhp,
            "ensureMigrationTable() must ALTER TABLE to add status when missing."
        );
    }

    public function testDatabasePhpInsertsStatusOnSuccess(): void
    {
        $dbPhp = file_get_contents(dirname(__DIR__) . '/includes/database.php');

        $this->assertStringContainsString(
            "'applied'",
            $dbPhp,
            "Database must insert status='applied' on successful migration."
        );
    }

    public function testDatabasePhpInsertsStatusFailedOnError(): void
    {
        $dbPhp = file_get_contents(dirname(__DIR__) . '/includes/database.php');

        $this->assertStringContainsString(
            "'failed'",
            $dbPhp,
            "Database must insert status='failed' on migration error."
        );

        $this->assertStringContainsString(
            'INSERT IGNORE INTO schema_migrations',
            $dbPhp,
            "Failure recording must use INSERT IGNORE to avoid duplicate-key errors."
        );
    }
}
