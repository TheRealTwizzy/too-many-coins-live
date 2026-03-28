-- Hotfix: add fixed-point carryover storage for per-tick UBI accrual precision.
-- Ensures small boost modifiers (for example +10%) are not lost to per-tick flooring.

SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'coins_fractional_fp'
);

SET @sql := IF(
    @has_col = 0,
    'ALTER TABLE season_participation ADD COLUMN coins_fractional_fp BIGINT NOT NULL DEFAULT 0 AFTER coins',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
